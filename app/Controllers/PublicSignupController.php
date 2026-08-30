<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Flash;
use App\Core\Router;
use App\Core\View;
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
            header('Location: ' . $result['checkout_url']);
            exit;
        } catch (Throwable $exception) {
            $_SESSION['public_signup_old'] = [
                'company_name' => trim((string) ($_POST['company_name'] ?? '')),
                'legal_name' => trim((string) ($_POST['legal_name'] ?? '')),
                'responsible_name' => trim((string) ($_POST['responsible_name'] ?? '')),
                'email' => trim((string) ($_POST['email'] ?? '')),
                'phone' => trim((string) ($_POST['phone'] ?? '')),
                'document' => trim((string) ($_POST['document'] ?? '')),
            ];
            Flash::set('error', $exception->getMessage());
            $this->redirect('/signup');
        }
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
            'trial_ends_at' => (string) $signup['trial_ends_at'],
            'first_charge_at' => (string) $signup['first_charge_at'],
            'last_error' => in_array((string) $signup['status'], ['failed'], true) ? (string) ($signup['last_error'] ?? '') : '',
            'login_url' => Router::url('/login'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
                'gateway_id' => (int) ($_POST['gateway_id'] ?? 0),
                'trial_days' => (int) ($_POST['trial_days'] ?? 7),
            ]);
            Flash::set('success', 'Configurações da inscrição pública salvas.');
        } catch (Throwable $exception) {
            Flash::set('error', $exception->getMessage());
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
