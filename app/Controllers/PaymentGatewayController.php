<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Flash;
use App\Core\Router;
use App\Core\View;
use App\Services\PaymentGatewayService;
use PDO;
use Throwable;

final class PaymentGatewayController
{
    public function index(): void
    {
        $pdo = Database::connection();
        $service = new PaymentGatewayService();
        $gateways = $service->gateways();

        $invoices = $pdo->query(
            'SELECT i.*, t.name AS tenant_name, t.email AS tenant_email, sp.name AS plan_name, pg.label AS gateway_label
             FROM tenant_invoices i
             INNER JOIN tenants t ON t.id = i.tenant_id
             LEFT JOIN tenant_subscriptions ts ON ts.id = i.subscription_id
             LEFT JOIN saas_plans sp ON sp.id = ts.plan_id
             LEFT JOIN payment_gateways pg ON pg.id = i.payment_gateway_id
             ORDER BY i.created_at DESC
             LIMIT 150'
        )->fetchAll(PDO::FETCH_ASSOC);

        $events = $pdo->query(
            'SELECT e.*, t.name AS tenant_name, pg.label AS gateway_label, pg.provider
             FROM payment_gateway_events e
             LEFT JOIN tenants t ON t.id = e.tenant_id
             LEFT JOIN payment_gateways pg ON pg.id = e.gateway_id
             ORDER BY e.id DESC
             LIMIT 80'
        )->fetchAll(PDO::FETCH_ASSOC);

        View::render('payment_gateways.index', [
            'title' => 'Meios de pagamento',
            'gateways' => $gateways,
            'invoices' => $invoices,
            'events' => $events,
            'providerLabels' => PaymentGatewayService::PROVIDER_LABELS,
            'methodLabels' => PaymentGatewayService::METHOD_LABELS,
        ]);
    }

    public function save(): void
    {
        $id = (int) ($_POST['id'] ?? 0);
        $label = trim((string) ($_POST['label'] ?? ''));
        $provider = (string) ($_POST['provider'] ?? 'manual');
        $environment = (string) ($_POST['environment'] ?? 'production');
        $apiBaseUrl = trim((string) ($_POST['api_base_url'] ?? ''));
        $apiKey = trim((string) ($_POST['api_key'] ?? ''));
        $publicKey = trim((string) ($_POST['public_key'] ?? ''));
        $webhookSecret = trim((string) ($_POST['webhook_secret'] ?? ''));
        $defaultPaymentMethod = strtoupper(trim((string) ($_POST['default_payment_method'] ?? 'UNDEFINED')));
        $status = (string) ($_POST['status'] ?? 'active');
        $isDefault = isset($_POST['is_default']) ? 1 : 0;
        $notes = trim((string) ($_POST['notes'] ?? ''));

        if ($label === '' || !array_key_exists($provider, PaymentGatewayService::PROVIDER_LABELS)) {
            Flash::set('error', 'Informe nome e provedor válido.');
            $this->redirect('/payment-gateways');
        }
        if (!in_array($environment, ['sandbox', 'production'], true)) {
            $environment = 'production';
        }
        if (!in_array($status, ['active', 'inactive'], true)) {
            $status = 'active';
        }
        if (!array_key_exists($defaultPaymentMethod, PaymentGatewayService::METHOD_LABELS)) {
            $defaultPaymentMethod = 'UNDEFINED';
        }

        if ($provider === 'asaas') {
            // O Asaas possui endpoints oficiais fixos por ambiente. Não aceitamos
            // APP_URL, domínio interno ou URL personalizada neste campo, pois isso
            // faria o servidor tentar enviar o checkout para o próprio RS Connect.
            $apiBaseUrl = '';
        }

        if ($provider === 'pagbank') {
            $apiKey = $this->normalizePagBankApiKey($apiKey);
            try {
                $apiBaseUrl = $this->normalizePagBankBaseUrl($apiBaseUrl, $environment);
            } catch (\RuntimeException $exception) {
                Flash::set('error', $exception->getMessage());
                $this->redirect('/payment-gateways');
            }
        }

        $pdo = Database::connection();
        $existing = [];
        if ($id > 0) {
            $current = $pdo->prepare(
                'SELECT provider, api_key_encrypted, webhook_secret_encrypted FROM payment_gateways WHERE id = :id LIMIT 1'
            );
            $current->execute(['id' => $id]);
            $existing = $current->fetch(PDO::FETCH_ASSOC) ?: [];
        }

        $apiRequiredProviders = ['asaas', 'mercadopago', 'stripe', 'pagbank'];
        $webhookSecretProviders = ['asaas', 'mercadopago', 'stripe', 'infinitepay', 'external'];
        $sameProvider = $id > 0 && (string) ($existing['provider'] ?? '') === $provider;
        $hasStoredApiKey = $sameProvider && trim((string) ($existing['api_key_encrypted'] ?? '')) !== '';
        $hasStoredWebhookSecret = $sameProvider && trim((string) ($existing['webhook_secret_encrypted'] ?? '')) !== '';

        // Não bloqueia o salvamento de uma configuração incompleta. Quando uma
        // credencial obrigatória não chega no POST, o gateway é preservado como
        // inativo para que o administrador possa concluir a configuração sem
        // perder os demais dados preenchidos.
        $credentialWarnings = [];
        if ($status === 'active' && in_array($provider, $apiRequiredProviders, true) && $apiKey === '' && !$hasStoredApiKey) {
            $credentialWarnings[] = 'chave de acesso/API Key';
        }
        if ($status === 'active' && in_array($provider, $webhookSecretProviders, true) && $webhookSecret === '' && !$hasStoredWebhookSecret) {
            $credentialWarnings[] = 'token de autenticação do webhook';
        }
        if ($credentialWarnings !== []) {
            $status = 'inactive';
        }
        if ($provider === 'pagbank') {
            // O PagBank valida x-authenticity-token usando o próprio Token da API.
            $webhookSecret = '';
        }

        try {
            $pdo->beginTransaction();
            if ($isDefault === 1) {
                $pdo->exec('UPDATE payment_gateways SET is_default = 0');
            }

            if ($id > 0) {
                $statement = $pdo->prepare(
                    'UPDATE payment_gateways
                     SET label = :label, provider = :provider, environment = :environment, api_base_url = :api_base_url,
                         api_key_encrypted = :api_key_encrypted, public_key = :public_key,
                         webhook_secret_encrypted = :webhook_secret_encrypted,
                         default_payment_method = :default_payment_method, status = :status,
                         is_default = :is_default, notes = :notes
                     WHERE id = :id'
                );
                $params = ['id' => $id];
                $apiKeyEncrypted = $apiKey !== ''
                    ? Crypto::encrypt($apiKey)
                    : ($sameProvider ? (string) ($existing['api_key_encrypted'] ?? '') : '');
                $webhookSecretEncrypted = $provider === 'pagbank'
                    ? ''
                    : ($webhookSecret !== ''
                        ? Crypto::encrypt($webhookSecret)
                        : ($sameProvider ? (string) ($existing['webhook_secret_encrypted'] ?? '') : ''));
                $action = 'payment.gateway_updated';
            } else {
                $statement = $pdo->prepare(
                    'INSERT INTO payment_gateways
                        (label, provider, environment, api_base_url, api_key_encrypted, public_key,
                         webhook_secret_encrypted, default_payment_method, status, is_default, notes)
                     VALUES
                        (:label, :provider, :environment, :api_base_url, :api_key_encrypted, :public_key,
                         :webhook_secret_encrypted, :default_payment_method, :status, :is_default, :notes)'
                );
                $params = [];
                $apiKeyEncrypted = $apiKey !== '' ? Crypto::encrypt($apiKey) : '';
                $webhookSecretEncrypted = $provider === 'pagbank'
                    ? ''
                    : ($webhookSecret !== '' ? Crypto::encrypt($webhookSecret) : '');
                $action = 'payment.gateway_created';
            }

            $statement->execute($params + [
                'label' => $label,
                'provider' => $provider,
                'environment' => $environment,
                'api_base_url' => $apiBaseUrl !== '' ? $apiBaseUrl : null,
                'api_key_encrypted' => $apiKeyEncrypted,
                'public_key' => $publicKey !== '' ? $publicKey : null,
                'webhook_secret_encrypted' => $webhookSecretEncrypted,
                'default_payment_method' => $defaultPaymentMethod,
                'status' => $status,
                'is_default' => $isDefault,
                'notes' => $notes !== '' ? $notes : null,
            ]);
            $gatewayId = $id > 0 ? $id : (int) $pdo->lastInsertId();
            $pdo->commit();

            Audit::log($action, [
                'gateway_id' => $gatewayId,
                'provider' => $provider,
                'status' => $status,
                'credential_warning' => $credentialWarnings !== [],
            ]);
            if ($credentialWarnings !== []) {
                Flash::set(
                    'warning',
                    'Meio de pagamento salvo como inativo. Para ativar, informe: ' . implode(' e ', $credentialWarnings) . '.'
                );
            } else {
                Flash::set('success', 'Meio de pagamento salvo.');
            }
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Flash::set('error', 'Não foi possível salvar gateway: ' . $exception->getMessage());
        }
        $this->redirect('/payment-gateways');
    }

    public function createInvoiceLink(): void
    {
        $invoiceId = (int) ($_POST['invoice_id'] ?? 0);
        $gatewayId = (int) ($_POST['gateway_id'] ?? 0);
        $paymentMethod = (string) ($_POST['payment_method'] ?? '');
        $returnTo = (string) ($_POST['return_to'] ?? '/payment-gateways');
        if (!in_array($returnTo, ['/billing', '/payment-gateways'], true)) {
            $returnTo = '/payment-gateways';
        }
        if ($invoiceId < 1) {
            Flash::set('error', 'Cobrança inválida.');
            $this->redirect($returnTo);
        }

        try {
            $result = (new PaymentGatewayService())->createPaymentForInvoice($invoiceId, $gatewayId > 0 ? $gatewayId : null, $paymentMethod ?: null);
            $url = (string) ($result['checkout_url'] ?? $result['invoice_url'] ?? '');
            Flash::set('success', $url !== '' ? 'Link de pagamento gerado.' : 'Cobrança processada no gateway.');
            Audit::log('payment.invoice_link_created', ['invoice_id' => $invoiceId, 'provider' => $result['provider'] ?? null]);
        } catch (Throwable $exception) {
            Flash::set('error', 'Não foi possível gerar link de pagamento: ' . $exception->getMessage());
        }
        $this->redirect($returnTo);
    }

    public function importExternalCharge(): void
    {
        $returnTo = (string) ($_POST['return_to'] ?? '/billing');
        if (!in_array($returnTo, ['/billing', '/payment-gateways'], true)) {
            $returnTo = '/billing';
        }
        try {
            $result = (new PaymentGatewayService())->importExternalCharge($_POST);
            Audit::log('payment.external_charge_imported', $result);
            Flash::set('success', 'Cobrança externa vinculada. O link já pode ser enviado ao cliente.');
        } catch (Throwable $exception) {
            Flash::set('error', 'Não foi possível importar a cobrança externa: ' . $exception->getMessage());
        }
        $this->redirect($returnTo);
    }

    public function refreshInvoiceStatus(): void
    {
        $invoiceId = (int) ($_POST['invoice_id'] ?? 0);
        $returnTo = (string) ($_POST['return_to'] ?? '/billing');
        if (!in_array($returnTo, ['/billing', '/payment-gateways'], true)) {
            $returnTo = '/billing';
        }
        try {
            $result = (new PaymentGatewayService())->refreshInvoiceStatus($invoiceId);
            Audit::log('payment.invoice_status_refreshed', ['invoice_id' => $invoiceId] + $result);
            $message = 'Situação consultada no PagBank: ' . (string) ($result['status'] ?? 'atualizada') . '.';
            if (!empty($result['checkout_url'])) {
                $message .= ' Link de pagamento atualizado.';
            }
            Flash::set('success', $message);
        } catch (Throwable $exception) {
            Flash::set('error', 'Não foi possível consultar a cobrança: ' . $exception->getMessage());
        }
        $this->redirect($returnTo);
    }

    public function webhookInfinitePay(): void
    {
        $this->handleWebhook('infinitepay');
    }

    public function webhookExternal(): void
    {
        $this->handleWebhook('external');
    }

    public function webhookAsaas(): void
    {
        $this->handleWebhook('asaas');
    }

    public function webhookMercadoPago(): void
    {
        $this->handleWebhook('mercadopago');
    }

    public function webhookStripe(): void
    {
        $this->handleWebhook('stripe');
    }

    public function webhookPagBank(): void
    {
        $this->handleWebhook('pagbank');
    }

    private function handleWebhook(string $provider): void
    {
        $rawBody = (string) file_get_contents('php://input');
        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            $payload = $_POST ?: $_GET ?: [];
        }
        $headers = function_exists('getallheaders') ? (getallheaders() ?: []) : [];

        try {
            $result = (new PaymentGatewayService())->handleWebhook($provider, $payload, $headers, $rawBody);
            http_response_code(200);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true] + $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (Throwable $exception) {
            $status = $exception->getCode() >= 400 && $exception->getCode() <= 599
                ? (int) $exception->getCode()
                : 500;
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            $message = $status === 500
                ? 'Não foi possível processar o webhook de pagamento.'
                : $exception->getMessage();
            echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        exit;
    }

    private function normalizePagBankApiKey(string $apiKey): string
    {
        $apiKey = trim($apiKey);
        $apiKey = (string) preg_replace('/^Authorization\s*:\s*/i', '', $apiKey);
        $apiKey = (string) preg_replace('/^Bearer\s+/i', '', $apiKey);
        return trim($apiKey, " \t\n\r\0\x0B\"'");
    }

    private function normalizePagBankBaseUrl(string $apiBaseUrl, string $environment): string
    {
        $apiBaseUrl = trim($apiBaseUrl);
        if ($apiBaseUrl === '') {
            return '';
        }
        if (!preg_match('#^https?://#i', $apiBaseUrl)) {
            $apiBaseUrl = 'https://' . $apiBaseUrl;
        }
        $parts = parse_url($apiBaseUrl);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $expectedHost = $environment === 'sandbox'
            ? 'sandbox.api.pagseguro.com'
            : 'api.pagseguro.com';
        if ($host !== $expectedHost) {
            throw new \RuntimeException(
                'URL base PagBank incompatível com o ambiente selecionado. Deixe o campo vazio ou use https://' . $expectedHost . '.'
            );
        }
        return 'https://' . $expectedHost;
    }

    private function redirect(string $path): never
    {
        header('Location: ' . Router::url($path));
        exit;
    }
}
