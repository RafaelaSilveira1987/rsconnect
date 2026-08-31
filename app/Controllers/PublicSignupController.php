<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Flash;
use App\Core\Router;
use App\Core\View;
use App\Services\PublicSignupCouponService;
use App\Services\PublicSignupService;
use Throwable;

final class PublicSignupController
{
    public function show(): void
    {
        $offer = (new PublicSignupService())->offer();
        if (empty($offer['enabled'])) {
            Flash::set('warning', 'As inscrições estão temporariamente indisponíveis. Entre em contato com o comercial.');
            $this->redirect('/login');
        }
        View::render('signup.index', [
            'title' => 'Começar 7 dias grátis',
            'offer' => $offer,
        ], 'guest');
    }

    public function create(): void
    {
        try {
            $result = (new PublicSignupService())->start($_POST);
            $_SESSION['public_signup_token'] = $result['token'];

            // O POST permanece no próprio domínio. A navegação externa para o
            // Asaas acontece em uma página intermediária GET, evitando que a
            // política CSP form-action 'self' bloqueie o redirecionamento após
            // o envio do formulário.
            header(
                'Location: ' . Router::url('/signup/checkout?token=' . rawurlencode((string) $result['token'])),
                true,
                303
            );
            exit;
        } catch (Throwable $exception) {
            $_SESSION['public_signup_old'] = [
                'company_name' => trim((string) ($_POST['company_name'] ?? '')),
                'legal_name' => trim((string) ($_POST['legal_name'] ?? '')),
                'responsible_name' => trim((string) ($_POST['responsible_name'] ?? '')),
                'email' => trim((string) ($_POST['email'] ?? '')),
                'phone' => trim((string) ($_POST['phone'] ?? '')),
                'document' => trim((string) ($_POST['document'] ?? '')),
                'payment_method' => trim((string) ($_POST['payment_method'] ?? 'credit_card')),
                'coupon_code' => trim((string) ($_POST['coupon_code'] ?? '')),
            ];
            Flash::set('error', $exception->getMessage());
            $this->redirect('/signup');
        }
    }


    public function checkout(): void
    {
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');

        $token = $this->requestToken();
        $checkout = (new PublicSignupService())->checkoutBridge($token);
        if (!$checkout) {
            Flash::set('error', 'Não foi possível localizar um checkout válido. Preencha o cadastro novamente.');
            $this->redirect('/signup');
        }

        $status = (string) ($checkout['status'] ?? '');
        if (in_array($status, ['checkout_completed', 'provisioned'], true)) {
            $this->redirect('/signup/success?token=' . rawurlencode($token));
        }
        if (in_array($status, ['cancelled', 'expired'], true)) {
            $this->redirect('/signup/' . $status . '?token=' . rawurlencode($token));
        }
        if ($status === 'failed') {
            Flash::set('error', (string) ($checkout['last_error'] ?? 'O checkout não pôde ser iniciado. Tente novamente.'));
            $this->redirect('/signup');
        }

        View::render('signup.checkout', [
            'title' => 'Abrindo checkout seguro',
            'checkout' => $checkout,
            'token' => $token,
        ], 'guest');
    }

    public function success(): void
    {
        $this->statusPage('success');
    }

    public function cancelled(): void
    {
        $this->statusPage('cancelled');
    }

    public function expired(): void
    {
        $this->statusPage('expired');
    }

    public function status(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        $token = $this->requestToken();
        $signup = (new PublicSignupService())->status($token);
        if (!$signup) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'status' => 'not_found'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return;
        }
        echo json_encode([
            'ok' => true,
            'status' => (string) $signup['status'],
            'ready' => (string) $signup['status'] === 'provisioned',
            'email' => (string) $signup['email'],
            'payment_method' => (string) ($signup['payment_method'] ?? 'credit_card'),
            'trial_ends_at' => (string) $signup['trial_ends_at'],
            'first_charge_at' => (string) $signup['first_charge_at'],
            'last_error' => in_array((string) $signup['status'], ['failed'], true) ? (string) ($signup['last_error'] ?? '') : '',
            'login_url' => Router::url('/login'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function validateCoupon(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        try {
            $coupon = (new PublicSignupService())->previewCoupon($_POST);
            echo json_encode(['ok' => true, 'coupon' => $coupon], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (Throwable $exception) {
            http_response_code(422);
            echo json_encode([
                'ok' => false,
                'message' => $exception->getMessage(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }

    public function saveCoupon(): void
    {
        try {
            $id = (new PublicSignupCouponService())->save($_POST, Auth::id());
            Audit::log('public_signup.coupon_saved', [
                'coupon_id' => $id,
                'code' => PublicSignupCouponService::normalizeCode((string) ($_POST['code'] ?? '')),
                'active' => !empty($_POST['active']),
            ]);
            Flash::set('success', 'Cupom salvo com sucesso.');
        } catch (Throwable $exception) {
            Flash::set('error', $exception->getMessage());
        }
        $this->redirect('/settings/public-signup#coupons');
    }

    public function toggleCoupon(): void
    {
        $id = (int) ($_POST['id'] ?? 0);
        $active = !empty($_POST['active']);
        try {
            (new PublicSignupCouponService())->toggle($id, $active, Auth::id());
            Audit::log('public_signup.coupon_toggled', [
                'coupon_id' => $id,
                'active' => $active,
            ]);
            Flash::set('success', $active ? 'Cupom ativado.' : 'Cupom pausado.');
        } catch (Throwable $exception) {
            Flash::set('error', $exception->getMessage());
        }
        $this->redirect('/settings/public-signup#coupons');
    }

    public function settings(): void
    {
        View::render('signup.admin', [
            'title' => 'Inscrição pública e trial Asaas',
            'data' => (new PublicSignupService())->adminData(),
        ]);
    }

    public function saveSettings(): void
    {
        try {
            (new PublicSignupService())->saveSettings($_POST, Auth::id());
            Audit::log('public_signup.settings_updated', [
                'enabled' => !empty($_POST['enabled']),
                'pix_enabled' => !empty($_POST['pix_enabled']),
                'gateway_id' => (int) ($_POST['gateway_id'] ?? 0),
                'trial_days' => (int) ($_POST['trial_days'] ?? 7),
            ]);
            Flash::set('success', 'Configurações da inscrição pública salvas.');
        } catch (Throwable $exception) {
            Flash::set('error', $exception->getMessage());
        }
        $this->redirect('/settings/public-signup');
    }

    public function testGateway(): void
    {
        $gatewayId = (int) ($_POST['gateway_id'] ?? 0);
        try {
            $result = (new PublicSignupService())->testGatewayConnection($gatewayId);
            $environment = (string) ($result['environment'] ?? 'production') === 'production' ? 'Produção' : 'Sandbox';
            $accountName = trim((string) ($result['account_name'] ?? ''));
            $detail = $accountName !== '' ? ' Conta identificada: ' . $accountName . '.' : '';
            Flash::set('success', 'Conexão com o Asaas ' . $environment . ' validada com sucesso.' . $detail);
            Audit::log('public_signup.asaas_connection_tested', [
                'gateway_id' => $gatewayId,
                'environment' => (string) ($result['environment'] ?? ''),
                'success' => true,
            ]);
        } catch (Throwable $exception) {
            Flash::set('error', $exception->getMessage());
            Audit::log('public_signup.asaas_connection_tested', [
                'gateway_id' => $gatewayId,
                'success' => false,
                'error' => mb_substr($exception->getMessage(), 0, 500),
            ]);
        }
        $this->redirect('/settings/public-signup');
    }

    public function terms(): void
    {
        View::render('signup.terms', ['title' => 'Termos de Uso'], 'guest');
    }

    public function privacy(): void
    {
        View::render('signup.privacy', ['title' => 'Política de Privacidade'], 'guest');
    }

    private function statusPage(string $callbackState): void
    {
        $token = $this->requestToken();
        $signup = (new PublicSignupService())->status($token);
        View::render('signup.status', [
            'title' => 'Confirmação da inscrição',
            'signup' => $signup,
            'token' => $token,
            'callbackState' => $callbackState,
        ], 'guest');
    }

    private function requestToken(): string
    {
        $token = trim((string) ($_GET['token'] ?? $_SESSION['public_signup_token'] ?? ''));
        return preg_match('/^[a-f0-9]{64}$/', $token) ? $token : '';
    }

    private function redirect(string $path): never
    {
        header('Location: ' . Router::url($path));
        exit;
    }
}
