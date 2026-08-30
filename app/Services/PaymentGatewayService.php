<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Crypto;
use App\Core\Database;
use App\Core\Env;
use App\Core\Router;
use PDO;
use RuntimeException;
use Throwable;

final class PaymentGatewayService
{
    /** @var array<string, bool> */
    private array $columnAvailability = [];

    public const PROVIDER_LABELS = [
        'asaas' => 'Asaas',
        'mercadopago' => 'Mercado Pago',
        'stripe' => 'Stripe',
        'pagbank' => 'PagBank / PagSeguro',
        'infinitepay' => 'InfinitePay — cobrança existente',
        'external' => 'Outro provedor externo',
        'manual' => 'Manual / externo',
    ];

    public const METHOD_LABELS = [
        'UNDEFINED' => 'Cliente escolhe',
        'PIX' => 'Pix',
        'BOLETO' => 'Boleto',
        'CREDIT_CARD' => 'Cartão de crédito',
        'LINK' => 'Link já criado',
    ];

    public function gateways(): array
    {
        return Database::connection()
            ->query('SELECT * FROM payment_gateways ORDER BY is_default DESC, status, label')
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    public function defaultGateway(): ?array
    {
        $statement = Database::connection()->query(
            'SELECT * FROM payment_gateways
             WHERE status = "active"
             ORDER BY is_default DESC, id ASC
             LIMIT 1'
        );
        $gateway = $statement->fetch(PDO::FETCH_ASSOC);
        return $gateway ?: null;
    }

    public function createPaymentForInvoice(int $invoiceId, ?int $gatewayId = null, ?string $paymentMethod = null): array
    {
        $invoice = $this->loadInvoice($invoiceId);
        if (!$invoice) {
            throw new RuntimeException('Cobrança não encontrada.');
        }
        if (($invoice['status'] ?? '') === 'paid') {
            throw new RuntimeException('Essa cobrança já está paga.');
        }

        $existingUrl = trim((string) ($invoice['external_checkout_url'] ?? $invoice['external_invoice_url'] ?? ''));
        $sameGateway = !$gatewayId || (int) ($invoice['payment_gateway_id'] ?? 0) === $gatewayId;

        // O PagBank devolve também a URL de retorno do lojista. Versões anteriores
        // podiam salvar essa URL interna como se fosse o link de pagamento.
        // Quando isso ocorrer, tenta recuperar o link PAY do Checkout já criado,
        // evitando gerar uma cobrança duplicada.
        if ($existingUrl !== '' && !$this->isUsableCheckoutUrl($existingUrl)) {
            if (
                strtolower((string) ($invoice['gateway_provider'] ?? '')) === 'pagbank'
                && trim((string) ($invoice['external_payment_id'] ?? '')) !== ''
            ) {
                try {
                    $this->refreshInvoiceStatus($invoiceId);
                    $invoice = $this->loadInvoice($invoiceId) ?: $invoice;
                    $existingUrl = trim((string) ($invoice['external_checkout_url'] ?? $invoice['external_invoice_url'] ?? ''));
                } catch (Throwable) {
                    // Se a consulta não conseguir reparar, o link inválido é removido
                    // e a criação segue normalmente.
                }
            }

            if (!$this->isUsableCheckoutUrl($existingUrl)) {
                $this->clearInvalidPaymentUrl($invoiceId);
                $existingUrl = '';
            }
        }

        if ($existingUrl !== '' && $sameGateway && in_array((string) ($invoice['status'] ?? ''), ['open', 'overdue'], true)) {
            return [
                'external_id' => (string) ($invoice['external_payment_id'] ?? ''),
                'checkout_url' => $existingUrl,
                'invoice_url' => (string) ($invoice['external_invoice_url'] ?? $existingUrl),
                'external_status' => (string) ($invoice['external_status'] ?? 'existing'),
                'payload' => ['reused' => true, 'message' => 'Link já existente reutilizado para evitar duplicidade.'],
                'provider' => (string) ($invoice['gateway_provider'] ?? 'external'),
                'gateway_label' => '',
                'reused' => true,
            ];
        }

        $gateway = $this->loadGateway($gatewayId);
        if (!$gateway) {
            throw new RuntimeException('Nenhum gateway ativo configurado.');
        }

        $provider = (string) $gateway['provider'];
        $method = $paymentMethod ?: (string) ($gateway['default_payment_method'] ?? 'UNDEFINED');
        $method = $method !== '' ? mb_strtoupper($method) : 'UNDEFINED';

        $result = match ($provider) {
            'asaas' => $this->createAsaasPayment($gateway, $invoice, $method),
            'mercadopago' => $this->createMercadoPagoPreference($gateway, $invoice),
            'stripe' => $this->createStripeCheckoutSession($gateway, $invoice),
            'pagbank' => $this->createPagBankCheckout($gateway, $invoice, $method),
            'infinitepay', 'external', 'manual' => throw new RuntimeException(
                'Esse provedor utiliza uma cobrança já criada. Use “Importar cobrança externa” e informe o link existente.'
            ),
            default => throw new RuntimeException('Gateway não suportado: ' . $provider),
        };

        $this->saveInvoiceGatewayResult($invoiceId, (int) $gateway['id'], $provider, $result);
        $this->logEvent((int) $invoice['tenant_id'], (int) $gateway['id'], 'payment.link_created', 'success', [
            'invoice_id' => $invoiceId,
            'invoice_number' => $invoice['invoice_number'],
            'provider' => $provider,
            'external_id' => $result['external_id'] ?? null,
            'checkout_url' => $result['checkout_url'] ?? null,
        ]);

        return $result + ['provider' => $provider, 'gateway_label' => $gateway['label'] ?? ''];
    }

    public function handleWebhook(string $provider, array $payload, array $headers = [], string $rawBody = ''): array
    {
        $provider = strtolower(trim($provider));
        $security = new WebhookSecurityService();
        $gateway = $this->gatewayForWebhook($provider, $headers, $security);
        if (!$gateway) {
            throw new RuntimeException('Gateway ativo não configurado para o webhook ' . $provider . '.', 503);
        }

        $claimId = 0;
        $tenantId = null;
        $status = 'ignored';
        $message = 'Evento recebido, mas nenhuma cobrança foi alterada.';
        $invoiceNumber = null;
        $externalId = null;
        $mappedStatus = null;

        try {
            $this->authenticatePaymentWebhook($provider, $gateway, $payload, $headers, $rawBody, $security);
            $eventKey = $this->paymentWebhookEventKey($provider, $payload, $headers, $rawBody, $security);
            $claim = $security->claim('payment.' . $provider, $eventKey, $rawBody, [
                'provider' => $provider,
                'gateway_id' => (int) $gateway['id'],
                'event_id' => $this->paymentWebhookExternalEventId($provider, $payload, $headers),
            ]);
            if (!empty($claim['duplicate'])) {
                return [
                    'status' => 'duplicate',
                    'message' => 'Evento já processado anteriormente.',
                    'duplicate' => true,
                ];
            }
            $claimId = (int) ($claim['id'] ?? 0);

            if ($provider === 'asaas') {
                $signupResult = (new PublicSignupService())->handleAsaasWebhook($payload, $gateway);
                if (!empty($signupResult['handled'])) {
                    $tenantId = isset($signupResult['tenant_id']) ? (int) $signupResult['tenant_id'] : null;
                    $status = (string) ($signupResult['status'] ?? 'success');
                    $message = (string) ($signupResult['message'] ?? 'Evento de inscrição pública processado.');
                    $externalId = (string) ($signupResult['external_id'] ?? '');
                    $invoiceNumber = (string) ($signupResult['reference'] ?? '');
                    $safeLog = [
                        'provider' => 'asaas',
                        'flow' => 'public_signup',
                        'reference' => $invoiceNumber,
                        'external_id' => $externalId,
                        'event' => (string) ($signupResult['event'] ?? ($payload['event'] ?? '')),
                        'event_id' => $this->paymentWebhookExternalEventId($provider, $payload, $headers),
                    ];
                    $this->logEvent($tenantId, (int) $gateway['id'], 'payment.webhook.asaas.public_signup', $status === 'success' ? 'success' : 'ignored', $safeLog);
                    $security->markProcessed($claimId, 200, $safeLog + ['status' => $status]);
                    return ['status' => $status, 'message' => $message, 'duplicate' => false];
                }
            }

            if ($provider === 'asaas') {
                $event = (string) ($payload['event'] ?? '');
                $payment = is_array($payload['payment'] ?? null) ? $payload['payment'] : $payload;
                $invoiceNumber = (string) ($payment['externalReference'] ?? $payload['externalReference'] ?? '');
                $externalId = (string) ($payment['id'] ?? $payload['id'] ?? '');
                $mappedStatus = $this->mapAsaasStatus($event, (string) ($payment['status'] ?? ''));
            } elseif ($provider === 'mercadopago') {
                $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
                $externalId = (string) ($data['id'] ?? $payload['id'] ?? '');
                $invoiceNumber = (string) ($payload['external_reference'] ?? $payload['externalReference'] ?? '');
                $mappedStatus = $this->mapMercadoPagoStatus((string) ($payload['status'] ?? $payload['action'] ?? ''));
                if ($externalId !== '' && $invoiceNumber === '') {
                    try {
                        $details = $this->requestJson('GET', rtrim($this->baseUrl($gateway), '/') . '/v1/payments/' . rawurlencode($externalId), [
                            'Authorization: Bearer ' . $this->apiKey($gateway),
                        ]);
                        $invoiceNumber = (string) ($details['external_reference'] ?? '');
                        $mappedStatus = $this->mapMercadoPagoStatus((string) ($details['status'] ?? ''));
                    } catch (Throwable) {
                        // O evento autenticado permanece registrado como ignorado para nova conciliação.
                    }
                }
            } elseif ($provider === 'stripe') {
                $type = (string) ($payload['type'] ?? '');
                $object = is_array($payload['data']['object'] ?? null) ? $payload['data']['object'] : $payload;
                $invoiceNumber = (string) ($object['client_reference_id'] ?? ($object['metadata']['invoice_number'] ?? ''));
                $externalId = (string) ($object['id'] ?? '');
                $mappedStatus = $type === 'checkout.session.completed' || (($object['payment_status'] ?? '') === 'paid') ? 'paid' : null;
            } elseif ($provider === 'pagbank') {
                $checkout = is_array($payload['checkout'] ?? null) ? $payload['checkout'] : [];
                $order = is_array($payload['order'] ?? null) ? $payload['order'] : [];
                $charge = is_array($payload['charges'][0] ?? null) ? $payload['charges'][0] : [];
                $payment = is_array($payload['payment'] ?? null) ? $payload['payment'] : [];

                $invoiceNumber = (string) (
                    $payload['reference_id']
                    ?? $checkout['reference_id']
                    ?? $order['reference_id']
                    ?? $charge['reference_id']
                    ?? $payment['reference_id']
                    ?? ''
                );
                $externalId = (string) (
                    $payload['id']
                    ?? $payload['checkout_id']
                    ?? $checkout['id']
                    ?? $order['id']
                    ?? $charge['id']
                    ?? $payment['id']
                    ?? ''
                );
                $mappedStatus = $this->mapPagBankStatus((string) (
                    $payload['status']
                    ?? $checkout['status']
                    ?? $order['status']
                    ?? $charge['status']
                    ?? $payment['status']
                    ?? $payload['event']
                    ?? ''
                ));
            } elseif (in_array($provider, ['infinitepay', 'external'], true)) {
                $invoiceNumber = (string) ($payload['invoice_number'] ?? $payload['reference_id'] ?? $payload['external_reference'] ?? '');
                $externalId = (string) ($payload['external_id'] ?? $payload['charge_id'] ?? $payload['id'] ?? '');
                $mappedStatus = $this->mapExternalStatus((string) ($payload['status'] ?? $payload['event'] ?? ''));
            }

            if ($invoiceNumber !== '' || $externalId !== '') {
                $invoice = $this->findInvoice($invoiceNumber, $externalId);
                if ($invoice && $mappedStatus) {
                    $tenantId = (int) $invoice['tenant_id'];
                    $this->updateInvoiceStatus((int) $invoice['id'], $mappedStatus, $externalId, $payload);
                    $status = 'success';
                    $message = 'Cobrança atualizada para ' . $mappedStatus . '.';
                }
            }

            $safeLog = [
                'provider' => $provider,
                'invoice_number' => $invoiceNumber,
                'external_id' => $externalId,
                'mapped_status' => $mappedStatus,
                'event_id' => $this->paymentWebhookExternalEventId($provider, $payload, $headers),
            ];
            $this->logEvent($tenantId, (int) $gateway['id'], 'payment.webhook.' . $provider, $status, $safeLog);
            $security->markProcessed($claimId, 200, [
                'provider' => $provider,
                'status' => $status,
                'invoice_number' => $invoiceNumber,
                'external_id' => $externalId,
            ]);

            return ['status' => $status, 'message' => $message, 'duplicate' => false];
        } catch (Throwable $exception) {
            $httpStatus = $exception->getCode() >= 400 && $exception->getCode() <= 599
                ? (int) $exception->getCode()
                : 500;
            if ($claimId > 0) {
                $security->markFailed($claimId, $httpStatus, $exception);
            }
            $this->logEvent(null, (int) $gateway['id'], 'payment.webhook_denied.' . $provider, 'error', [
                'provider' => $provider,
                'http_status' => $httpStatus,
                'reason' => $security->redactText($exception->getMessage()),
            ]);
            throw $exception;
        }
    }

    public function importExternalCharge(array $data): array
    {
        $invoiceId = (int) ($data['invoice_id'] ?? 0);
        $provider = strtolower(trim((string) ($data['provider'] ?? 'infinitepay')));
        $externalId = trim((string) ($data['external_id'] ?? ''));
        $checkoutUrl = trim((string) ($data['checkout_url'] ?? ''));
        $externalStatus = trim((string) ($data['status'] ?? 'open'));
        $gatewayId = (int) ($data['gateway_id'] ?? 0);

        if ($invoiceId < 1 || !in_array($provider, ['infinitepay', 'external'], true)) {
            throw new RuntimeException('Selecione uma cobrança e um provedor externo válido.');
        }
        if ($externalId === '' || $checkoutUrl === '') {
            throw new RuntimeException('Informe o identificador e o link da cobrança externa.');
        }
        if (!filter_var($checkoutUrl, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('Informe um link de pagamento válido.');
        }

        $invoice = $this->loadInvoice($invoiceId);
        if (!$invoice) {
            throw new RuntimeException('Cobrança não encontrada.');
        }

        $duplicate = Database::connection()->prepare(
            'SELECT id, invoice_number FROM tenant_invoices
             WHERE gateway_provider = :provider AND external_payment_id = :external_id AND id <> :invoice_id
             LIMIT 1'
        );
        $duplicate->execute(['provider' => $provider, 'external_id' => $externalId, 'invoice_id' => $invoiceId]);
        if ($duplicateInvoice = $duplicate->fetch(PDO::FETCH_ASSOC)) {
            throw new RuntimeException('Esse identificador externo já está vinculado à cobrança ' . $duplicateInvoice['invoice_number'] . '.');
        }

        $gateway = $gatewayId > 0 ? $this->loadGateway($gatewayId) : $this->gatewayByProvider($provider);
        $gatewayDbId = $gateway ? (int) $gateway['id'] : null;
        $mappedStatus = $this->mapExternalStatus($externalStatus) ?? 'open';
        $payload = [
            'source' => 'admin_import',
            'provider' => $provider,
            'external_id' => $externalId,
            'checkout_url' => $checkoutUrl,
            'status' => $externalStatus,
            'imported_at' => date(DATE_ATOM),
        ];

        $statement = Database::connection()->prepare(
            'UPDATE tenant_invoices
             SET payment_gateway_id = :gateway_id,
                 gateway_provider = :provider,
                 external_payment_id = :external_id,
                 external_checkout_url = :checkout_url_a,
                 external_invoice_url = :checkout_url_b,
                 external_status = :external_status,
                 payment_payload_json = :payload,
                 payment_link_created_at = COALESCE(payment_link_created_at, NOW())'
            . $this->optionalInvoiceTimestampSql('external_imported_at')
            . $this->optionalInvoiceTimestampSql('payment_status_checked_at')
            . '
             WHERE id = :id'
        );
        $statement->execute([
            'gateway_id' => $gatewayDbId,
            'provider' => $provider,
            'external_id' => $externalId,
            'checkout_url_a' => $checkoutUrl,
            'checkout_url_b' => $checkoutUrl,
            'external_status' => $externalStatus,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'id' => $invoiceId,
        ]);

        $this->updateInvoiceStatus($invoiceId, $mappedStatus, $externalId, $payload + ['checkout_url' => $checkoutUrl]);
        $this->logEvent((int) $invoice['tenant_id'], $gatewayDbId, 'payment.external_imported', 'success', $payload + [
            'invoice_id' => $invoiceId,
            'invoice_number' => $invoice['invoice_number'],
        ]);

        return [
            'invoice_id' => $invoiceId,
            'invoice_number' => $invoice['invoice_number'],
            'provider' => $provider,
            'external_id' => $externalId,
            'checkout_url' => $checkoutUrl,
            'status' => $mappedStatus,
        ];
    }

    public function refreshInvoiceStatus(int $invoiceId): array
    {
        $invoice = $this->loadInvoice($invoiceId);
        if (!$invoice) {
            throw new RuntimeException('Cobrança não encontrada.');
        }
        $provider = strtolower((string) ($invoice['gateway_provider'] ?? ''));
        $externalId = trim((string) ($invoice['external_payment_id'] ?? ''));
        if ($provider !== 'pagbank') {
            throw new RuntimeException('A consulta direta de status está disponível para cobranças PagBank. Provedores externos são atualizados pelo webhook normalizado ou manualmente.');
        }
        if ($externalId === '') {
            throw new RuntimeException('A cobrança não possui identificador PagBank.');
        }

        $gateway = !empty($invoice['payment_gateway_id'])
            ? $this->loadGateway((int) $invoice['payment_gateway_id'])
            : $this->gatewayByProvider('pagbank');
        if (!$gateway) {
            throw new RuntimeException('Gateway PagBank ativo não encontrado.');
        }

        $response = $this->requestJson(
            'GET',
            rtrim($this->baseUrl($gateway), '/') . '/checkouts/' . rawurlencode($externalId),
            ['Authorization: Bearer ' . $this->apiKey($gateway)]
        );
        $statusSource = $this->extractPagBankStatus($response);
        $mappedStatus = $this->mapPagBankStatus($statusSource) ?? 'open';
        $checkoutUrl = $this->extractPagBankCheckoutUrl($response);
        $payloadForUpdate = $response;
        if ($checkoutUrl !== '') {
            $payloadForUpdate['checkout_url'] = $checkoutUrl;
        }
        $this->updateInvoiceStatus($invoiceId, $mappedStatus, $externalId, $payloadForUpdate);
        $this->logEvent((int) $invoice['tenant_id'], (int) $gateway['id'], 'payment.status_refreshed', 'success', [
            'invoice_id' => $invoiceId,
            'external_id' => $externalId,
            'provider' => 'pagbank',
            'source_status' => $statusSource,
            'mapped_status' => $mappedStatus,
            'checkout_url_repaired' => $checkoutUrl !== '',
        ]);

        return [
            'status' => $mappedStatus,
            'source_status' => $statusSource,
            'checkout_url' => $checkoutUrl,
            'payload' => $response,
        ];
    }

    public function setInvoiceStatus(int $invoiceId, string $status, array $payload = []): array
    {
        if (!in_array($status, ['open', 'paid', 'overdue', 'cancelled'], true)) {
            throw new RuntimeException('Situação da cobrança inválida.');
        }
        $invoice = $this->loadInvoice($invoiceId);
        if (!$invoice) {
            throw new RuntimeException('Cobrança não encontrada.');
        }
        $this->updateInvoiceStatus($invoiceId, $status, (string) ($invoice['external_payment_id'] ?? ''), $payload + ['source' => 'admin']);
        return ['tenant_id' => (int) $invoice['tenant_id'], 'status' => $status];
    }

    private function createAsaasPayment(array $gateway, array $invoice, string $method): array
    {
        $customerId = $this->getOrCreateAsaasCustomer($gateway, $invoice);
        $baseUrl = rtrim($this->baseUrl($gateway), '/');
        $payload = [
            'customer' => $customerId,
            'billingType' => in_array($method, ['BOLETO', 'PIX', 'CREDIT_CARD', 'UNDEFINED'], true) ? $method : 'UNDEFINED',
            'value' => (float) $invoice['amount'],
            'dueDate' => (string) $invoice['due_date'],
            'description' => $this->invoiceDescription($invoice),
            'externalReference' => (string) $invoice['invoice_number'],
        ];

        $response = $this->requestJson('POST', $baseUrl . '/payments', [
            'Content-Type: application/json',
            'access_token: ' . $this->apiKey($gateway),
        ], $payload);

        return [
            'external_customer_id' => $customerId,
            'external_id' => (string) ($response['id'] ?? ''),
            'checkout_url' => (string) ($response['invoiceUrl'] ?? $response['bankSlipUrl'] ?? ''),
            'invoice_url' => (string) ($response['invoiceUrl'] ?? ''),
            'external_status' => (string) ($response['status'] ?? 'created'),
            'payload' => $response,
        ];
    }

    private function createMercadoPagoPreference(array $gateway, array $invoice): array
    {
        $payload = [
            'external_reference' => (string) $invoice['invoice_number'],
            'notification_url' => Router::url('/webhooks/payments/mercadopago'),
            'items' => [[
                'title' => $this->invoiceDescription($invoice),
                'quantity' => 1,
                'currency_id' => 'BRL',
                'unit_price' => (float) $invoice['amount'],
            ]],
            'payer' => [
                'name' => (string) ($invoice['tenant_name'] ?? ''),
                'email' => (string) ($invoice['tenant_email'] ?? ''),
            ],
            'back_urls' => [
                'success' => Router::url('/subscription?payment=success'),
                'failure' => Router::url('/subscription?payment=failure'),
                'pending' => Router::url('/subscription?payment=pending'),
            ],
            'auto_return' => 'approved',
        ];

        $response = $this->requestJson('POST', rtrim($this->baseUrl($gateway), '/') . '/checkout/preferences', [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey($gateway),
        ], $payload);

        $checkoutUrl = (string) ($response['init_point'] ?? '');
        if (($gateway['environment'] ?? '') === 'sandbox' && !empty($response['sandbox_init_point'])) {
            $checkoutUrl = (string) $response['sandbox_init_point'];
        }

        return [
            'external_id' => (string) ($response['id'] ?? ''),
            'checkout_url' => $checkoutUrl,
            'invoice_url' => $checkoutUrl,
            'external_status' => 'created',
            'payload' => $response,
        ];
    }


    private function createPagBankCheckout(array $gateway, array $invoice, string $method): array
    {
        $amountInCents = max(1, (int) round(((float) $invoice['amount']) * 100));
        $methodMap = [
            'PIX' => 'PIX',
            'BOLETO' => 'BOLETO',
            'CREDIT_CARD' => 'CREDIT_CARD',
        ];

        $paymentMethods = [];
        if (isset($methodMap[$method])) {
            $paymentMethods[] = ['type' => $methodMap[$method]];
        }

        $payload = [
            'reference_id' => (string) $invoice['invoice_number'],
            'customer_modifiable' => true,
            'items' => [[
                'reference_id' => (string) $invoice['invoice_number'],
                'name' => $this->invoiceDescription($invoice),
                'quantity' => 1,
                'unit_amount' => $amountInCents,
            ]],
            'redirect_url' => Router::url('/subscription?payment=pagbank'),
            'return_url' => Router::url('/subscription?payment=pagbank'),
            'notification_urls' => [Router::url('/webhooks/payments/pagbank')],
            'payment_notification_urls' => [Router::url('/webhooks/payments/pagbank')],
        ];

        if ($paymentMethods !== []) {
            $payload['payment_methods'] = $paymentMethods;
        }

        $email = trim((string) ($invoice['tenant_email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email = '';
        }

        $phone = preg_replace('/\D+/', '', (string) ($invoice['tenant_phone'] ?? ''));
        if (str_starts_with($phone, '55') && in_array(strlen($phone), [12, 13], true)) {
            $phone = substr($phone, 2);
        }
        $phonePayload = null;
        if (in_array(strlen($phone), [10, 11], true)) {
            $phonePayload = [[
                'country' => '+55',
                'area' => substr($phone, 0, 2),
                'number' => substr($phone, 2),
                'type' => 'MOBILE',
            ]];
        }

        // O PagBank rejeita CPF/CNPJ com dígitos verificadores inválidos. Como o
        // checkout permanece editável, um documento inválido ou ausente é omitido
        // para que o próprio comprador informe o dado correto na página do PagBank.
        $document = $this->normalizeBrazilianTaxId((string) ($invoice['tenant_document'] ?? ''));

        $customer = array_filter([
            'name' => trim((string) ($invoice['tenant_legal_name'] ?: $invoice['tenant_name'])),
            'email' => $email,
            'tax_id' => $document,
            'phones' => $phonePayload,
        ], static fn ($value): bool => $value !== '' && $value !== null && $value !== []);
        if ($customer !== []) {
            $payload['customer'] = $customer;
        }

        $endpoint = rtrim($this->baseUrl($gateway), '/') . '/checkouts';
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey($gateway),
        ];

        try {
            $response = $this->requestJson('POST', $endpoint, $headers, $payload);
        } catch (RuntimeException $exception) {
            // Proteção adicional para cadastros legados: se o provedor ainda
            // recusar especificamente customer.tax_id, repete uma única vez sem
            // o documento. O cliente poderá preenchê-lo no checkout editável.
            if (
                isset($payload['customer']['tax_id'])
                && str_contains(mb_strtolower($exception->getMessage()), 'customer.tax_id')
            ) {
                unset($payload['customer']['tax_id']);
                if (($payload['customer'] ?? []) === []) {
                    unset($payload['customer']);
                }
                $response = $this->requestJson('POST', $endpoint, $headers, $payload);
            } else {
                throw $exception;
            }
        }

        $checkoutUrl = $this->extractPagBankCheckoutUrl($response);

        return [
            'external_id' => (string) ($response['id'] ?? $response['checkout_id'] ?? ''),
            'checkout_url' => $checkoutUrl,
            'invoice_url' => $checkoutUrl,
            'external_status' => (string) ($response['status'] ?? 'created'),
            'payload' => $response,
        ];
    }

    private function normalizeBrazilianTaxId(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        $length = strlen($digits);
        if (!in_array($length, [11, 14], true) || preg_match('/^(\d)\1+$/', $digits) === 1) {
            return '';
        }

        if ($length === 11) {
            return $this->isValidCpf($digits) ? $digits : '';
        }

        return $this->isValidCnpj($digits) ? $digits : '';
    }

    private function isValidCpf(string $cpf): bool
    {
        for ($position = 9; $position <= 10; $position++) {
            $sum = 0;
            for ($index = 0; $index < $position; $index++) {
                $sum += ((int) $cpf[$index]) * (($position + 1) - $index);
            }
            $digit = (10 * $sum) % 11;
            if ($digit === 10) {
                $digit = 0;
            }
            if ($digit !== (int) $cpf[$position]) {
                return false;
            }
        }

        return true;
    }

    private function isValidCnpj(string $cnpj): bool
    {
        $weights = [
            [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2],
            [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2],
        ];

        foreach ($weights as $round => $roundWeights) {
            $sum = 0;
            foreach ($roundWeights as $index => $weight) {
                $sum += ((int) $cnpj[$index]) * $weight;
            }
            $remainder = $sum % 11;
            $digit = $remainder < 2 ? 0 : 11 - $remainder;
            if ($digit !== (int) $cnpj[12 + $round]) {
                return false;
            }
        }

        return true;
    }

    private function createStripeCheckoutSession(array $gateway, array $invoice): array
    {
        $amountInCents = max(1, (int) round(((float) $invoice['amount']) * 100));
        $body = [
            'mode' => 'payment',
            'success_url' => Router::url('/subscription?payment=success'),
            'cancel_url' => Router::url('/subscription?payment=cancel'),
            'client_reference_id' => (string) $invoice['invoice_number'],
            'customer_email' => (string) ($invoice['tenant_email'] ?? ''),
            'line_items[0][price_data][currency]' => 'brl',
            'line_items[0][price_data][product_data][name]' => $this->invoiceDescription($invoice),
            'line_items[0][price_data][unit_amount]' => $amountInCents,
            'line_items[0][quantity]' => 1,
            'metadata[invoice_number]' => (string) $invoice['invoice_number'],
            'payment_intent_data[metadata][invoice_number]' => (string) $invoice['invoice_number'],
        ];

        $response = $this->requestForm('POST', rtrim($this->baseUrl($gateway), '/') . '/v1/checkout/sessions', [
            'Authorization: Bearer ' . $this->apiKey($gateway),
        ], $body);

        return [
            'external_id' => (string) ($response['id'] ?? ''),
            'checkout_url' => (string) ($response['url'] ?? ''),
            'invoice_url' => (string) ($response['url'] ?? ''),
            'external_status' => (string) ($response['payment_status'] ?? 'created'),
            'payload' => $response,
        ];
    }


    private function extractPagBankCheckoutUrl(array $response): string
    {
        // No Checkout PagBank, o link correto para o comprador é o link com rel=PAY.
        // A resposta também pode conter redirect_url/return_url do lojista, que nunca
        // devem ser persistidas como link de pagamento.
        $links = $response['links'] ?? [];
        if (is_array($links)) {
            foreach ($links as $link) {
                if (!is_array($link)) {
                    continue;
                }
                $rel = strtoupper(trim((string) ($link['rel'] ?? $link['type'] ?? '')));
                $href = trim((string) ($link['href'] ?? $link['url'] ?? ''));
                if ($this->isUsableCheckoutUrl($href) && in_array($rel, ['PAY', 'PAYMENT', 'CHECKOUT'], true)) {
                    return $href;
                }
            }
        }

        foreach (['payment_url', 'checkout_url', 'url'] as $key) {
            $candidate = trim((string) ($response[$key] ?? ''));
            if ($this->isUsableCheckoutUrl($candidate)) {
                return $candidate;
            }
        }

        // Fallback conservador: usa outro link externo somente quando não for SELF,
        // redirect, return, notification ou recurso da própria API.
        if (is_array($links)) {
            foreach ($links as $link) {
                if (!is_array($link)) {
                    continue;
                }
                $rel = strtoupper(trim((string) ($link['rel'] ?? $link['type'] ?? '')));
                $href = trim((string) ($link['href'] ?? $link['url'] ?? ''));
                if (
                    $this->isUsableCheckoutUrl($href)
                    && !in_array($rel, ['SELF', 'REDIRECT', 'RETURN', 'NOTIFICATION', 'PAYMENT_NOTIFICATION'], true)
                ) {
                    return $href;
                }
            }
        }

        return '';
    }

    private function isUsableCheckoutUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $appHost = strtolower((string) parse_url(Router::url('/'), PHP_URL_HOST));
        if ($host !== '' && $appHost !== '' && $host === $appHost) {
            return false;
        }

        return true;
    }

    private function clearInvalidPaymentUrl(int $invoiceId): void
    {
        $statement = Database::connection()->prepare(
            'UPDATE tenant_invoices
             SET external_checkout_url = NULL,
                 external_invoice_url = NULL
             WHERE id = :id'
        );
        $statement->execute(['id' => $invoiceId]);
    }

    private function getOrCreateAsaasCustomer(array $gateway, array $invoice): string
    {
        $pdo = Database::connection();
        $statement = $pdo->prepare(
            'SELECT external_customer_id FROM payment_gateway_customers
             WHERE tenant_id = :tenant_id AND gateway_id = :gateway_id AND provider = "asaas"
             LIMIT 1'
        );
        $statement->execute(['tenant_id' => $invoice['tenant_id'], 'gateway_id' => $gateway['id']]);
        $existing = (string) ($statement->fetchColumn() ?: '');
        if ($existing !== '') {
            return $existing;
        }

        $payload = [
            'name' => (string) ($invoice['tenant_legal_name'] ?: $invoice['tenant_name']),
            'email' => (string) ($invoice['tenant_email'] ?? ''),
            'mobilePhone' => preg_replace('/\D+/', '', (string) ($invoice['tenant_phone'] ?? '')),
        ];
        $document = preg_replace('/\D+/', '', (string) ($invoice['tenant_document'] ?? ''));
        if ($document !== '') {
            $payload['cpfCnpj'] = $document;
        }
        $payload = array_filter($payload, static fn ($value): bool => $value !== '' && $value !== null);

        $response = $this->requestJson('POST', rtrim($this->baseUrl($gateway), '/') . '/customers', [
            'Content-Type: application/json',
            'access_token: ' . $this->apiKey($gateway),
        ], $payload);

        $customerId = (string) ($response['id'] ?? '');
        if ($customerId === '') {
            throw new RuntimeException('Asaas não retornou o ID do cliente.');
        }

        $insert = $pdo->prepare(
            'INSERT INTO payment_gateway_customers
                (tenant_id, gateway_id, provider, external_customer_id, customer_payload_json)
             VALUES
                (:tenant_id, :gateway_id, "asaas", :external_customer_id, :payload)'
        );
        $insert->execute([
            'tenant_id' => $invoice['tenant_id'],
            'gateway_id' => $gateway['id'],
            'external_customer_id' => $customerId,
            'payload' => json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        return $customerId;
    }

    private function loadInvoice(int $invoiceId): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT i.*, t.name AS tenant_name, t.legal_name AS tenant_legal_name, t.document AS tenant_document,
                    t.email AS tenant_email, t.phone AS tenant_phone, sp.name AS plan_name
             FROM tenant_invoices i
             INNER JOIN tenants t ON t.id = i.tenant_id
             LEFT JOIN tenant_subscriptions ts ON ts.id = i.subscription_id
             LEFT JOIN saas_plans sp ON sp.id = ts.plan_id
             WHERE i.id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $invoiceId]);
        $invoice = $statement->fetch(PDO::FETCH_ASSOC);
        return $invoice ?: null;
    }

    private function optionalInvoiceTimestampSql(string $column): string
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $column) !== 1) {
            return '';
        }

        return $this->columnExists('tenant_invoices', $column)
            ? ",\n                 {$column} = NOW()"
            : '';
    }

    private function columnExists(string $table, string $column): bool
    {
        if (
            preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1
            || preg_match('/^[A-Za-z0-9_]+$/', $column) !== 1
        ) {
            return false;
        }

        $cacheKey = $table . '.' . $column;
        if (array_key_exists($cacheKey, $this->columnAvailability)) {
            return $this->columnAvailability[$cacheKey];
        }

        try {
            $statement = Database::connection()->prepare(
                'SELECT COUNT(*)
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = :table_name
                   AND COLUMN_NAME = :column_name'
            );
            $statement->execute([
                'table_name' => $table,
                'column_name' => $column,
            ]);
            return $this->columnAvailability[$cacheKey] = (int) $statement->fetchColumn() > 0;
        } catch (Throwable) {
            // Compatibilidade transitória: a criação da cobrança não deve ser perdida
            // apenas porque o banco ainda não recebeu a migration de auditoria temporal.
            return $this->columnAvailability[$cacheKey] = false;
        }
    }

    private function loadGateway(?int $gatewayId): ?array
    {
        if ($gatewayId && $gatewayId > 0) {
            $statement = Database::connection()->prepare('SELECT * FROM payment_gateways WHERE id = :id AND status = "active" LIMIT 1');
            $statement->execute(['id' => $gatewayId]);
            $gateway = $statement->fetch(PDO::FETCH_ASSOC);
            return $gateway ?: null;
        }
        return $this->defaultGateway();
    }

    private function gatewayByProvider(string $provider): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT * FROM payment_gateways
             WHERE provider = :provider AND status = "active"
             ORDER BY is_default DESC, id ASC
             LIMIT 1'
        );
        $statement->execute(['provider' => $provider]);
        $gateway = $statement->fetch(PDO::FETCH_ASSOC);
        return $gateway ?: null;
    }

    /** @param array<string,mixed> $headers */
    private function gatewayForWebhook(
        string $provider,
        array $headers,
        WebhookSecurityService $security
    ): ?array {
        if ($provider !== 'asaas') {
            return $this->gatewayByProvider($provider);
        }

        $providedToken = $security->header($headers, ['asaas-access-token']);
        if ($providedToken === '') {
            return $this->gatewayByProvider($provider);
        }

        $statement = Database::connection()->prepare(
            'SELECT * FROM payment_gateways
             WHERE provider = :provider AND status = "active"
             ORDER BY is_default DESC, id ASC'
        );
        $statement->execute(['provider' => $provider]);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $gateway) {
            try {
                $expectedToken = $this->webhookSecret($gateway);
            } catch (Throwable) {
                continue;
            }
            if ($expectedToken !== '' && hash_equals($expectedToken, $providedToken)) {
                return $gateway;
            }
        }

        // Retorna o gateway padrão para que a validação central produza a resposta
        // de autenticação uniforme, sem revelar quantos gateways existem.
        return $this->gatewayByProvider($provider);
    }

    private function findInvoice(string $invoiceNumber, string $externalId): ?array
    {
        $sql = 'SELECT * FROM tenant_invoices WHERE 1=1';
        $params = [];
        if ($invoiceNumber !== '') {
            $sql .= ' AND invoice_number = :invoice_number';
            $params['invoice_number'] = $invoiceNumber;
        } elseif ($externalId !== '') {
            $sql .= ' AND external_payment_id = :external_payment_id';
            $params['external_payment_id'] = $externalId;
        } else {
            return null;
        }
        $sql .= ' LIMIT 1';
        $statement = Database::connection()->prepare($sql);
        $statement->execute($params);
        $invoice = $statement->fetch(PDO::FETCH_ASSOC);
        return $invoice ?: null;
    }

    private function saveInvoiceGatewayResult(int $invoiceId, int $gatewayId, string $provider, array $result): void
    {
        $statement = Database::connection()->prepare(
            'UPDATE tenant_invoices
             SET payment_gateway_id = :gateway_id,
                 gateway_provider = :provider,
                 external_customer_id = :external_customer_id,
                 external_payment_id = :external_payment_id,
                 external_checkout_url = :checkout_url,
                 external_invoice_url = :invoice_url,
                 external_status = :external_status,
                 payment_payload_json = :payload,
                 payment_link_created_at = NOW()'
            . $this->optionalInvoiceTimestampSql('payment_status_checked_at')
            . '
             WHERE id = :id'
        );
        $statement->execute([
            'gateway_id' => $gatewayId,
            'provider' => $provider,
            'external_customer_id' => $result['external_customer_id'] ?? null,
            'external_payment_id' => $result['external_id'] ?? null,
            'checkout_url' => $result['checkout_url'] ?? null,
            'invoice_url' => $result['invoice_url'] ?? null,
            'external_status' => $result['external_status'] ?? null,
            'payload' => json_encode($result['payload'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'id' => $invoiceId,
        ]);
    }

    private function updateInvoiceStatus(int $invoiceId, string $status, string $externalId, array $payload): void
    {
        $checkoutUrl = trim((string) ($payload['checkout_url'] ?? $payload['payment_url'] ?? $payload['link'] ?? ''));
        if (!$this->isUsableCheckoutUrl($checkoutUrl)) {
            $checkoutUrl = $this->extractPagBankCheckoutUrl($payload);
        }
        $statement = Database::connection()->prepare(
            'UPDATE tenant_invoices
             SET status = :status,
                 paid_at = CASE WHEN :paid_status = "paid" THEN COALESCE(paid_at, NOW()) ELSE paid_at END,
                 external_payment_id = COALESCE(NULLIF(:external_id, ""), external_payment_id),
                 external_checkout_url = COALESCE(NULLIF(:checkout_url_a, ""), external_checkout_url),
                 external_invoice_url = COALESCE(NULLIF(:checkout_url_b, ""), external_invoice_url),
                 external_status = :external_status,
                 payment_payload_json = :payload'
            . $this->optionalInvoiceTimestampSql('payment_status_checked_at')
            . '
             WHERE id = :id'
        );
        $statement->execute([
            'status' => $status,
            'paid_status' => $status,
            'external_id' => $externalId,
            'checkout_url_a' => $checkoutUrl,
            'checkout_url_b' => $checkoutUrl,
            'external_status' => $status,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'id' => $invoiceId,
        ]);

        $invoiceStatement = Database::connection()->prepare(
            'SELECT tenant_id, subscription_id, period_start, period_end
             FROM tenant_invoices WHERE id = :id LIMIT 1'
        );
        $invoiceStatement->execute(['id' => $invoiceId]);
        $invoice = $invoiceStatement->fetch(PDO::FETCH_ASSOC) ?: [];
        $tenantId = (int) ($invoice['tenant_id'] ?? 0);

        if ($status === 'paid' && $tenantId > 0) {
            $graceDays = max(0, (int) Env::get('BILLING_ACCESS_GRACE_DAYS', 5));
            $pending = Database::connection()->prepare(
                'SELECT COUNT(*) FROM tenant_invoices
                 WHERE tenant_id = :tenant_id
                   AND status IN ("open", "overdue")
                   AND DATEDIFF(CURDATE(), due_date) > :grace_days'
            );
            $pending->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
            $pending->bindValue(':grace_days', $graceDays, PDO::PARAM_INT);
            $pending->execute();
            if ((int) $pending->fetchColumn() === 0) {
                $subscriptionId = (int) ($invoice['subscription_id'] ?? 0);
                if ($subscriptionId > 0) {
                    Database::connection()->prepare(
                        'UPDATE tenant_subscriptions
                         SET billing_status = "active",
                             current_period_starts_at = COALESCE(NULLIF(:period_start, ""), current_period_starts_at),
                             current_period_ends_at = CASE
                                 WHEN :period_end_check <> "" AND :period_end_compare > current_period_ends_at THEN :period_end_value
                                 ELSE current_period_ends_at
                             END,
                             next_billing_at = CASE
                                 WHEN :period_end_next_check <> "" THEN DATE_ADD(:period_end_next_value, INTERVAL 1 DAY)
                                 ELSE next_billing_at
                             END,
                             cancel_at = NULL
                         WHERE id = :subscription_id'
                    )->execute([
                        'period_start' => (string) ($invoice['period_start'] ?? ''),
                        'period_end_check' => (string) ($invoice['period_end'] ?? ''),
                        'period_end_compare' => (string) ($invoice['period_end'] ?? ''),
                        'period_end_value' => (string) ($invoice['period_end'] ?? ''),
                        'period_end_next_check' => (string) ($invoice['period_end'] ?? ''),
                        'period_end_next_value' => (string) ($invoice['period_end'] ?? ''),
                        'subscription_id' => $subscriptionId,
                    ]);
                }
                Database::connection()->prepare('UPDATE tenants SET status = "active" WHERE id = :tenant_id')
                    ->execute(['tenant_id' => $tenantId]);
                if ($this->columnExists('tenant_invoices', 'access_released_at')) {
                    Database::connection()->prepare('UPDATE tenant_invoices SET access_released_at = NOW() WHERE id = :id')
                        ->execute(['id' => $invoiceId]);
                }
            }
        } elseif ($status === 'overdue' && $tenantId > 0) {
            Database::connection()->prepare(
                'UPDATE tenant_subscriptions SET billing_status = "overdue"
                 WHERE tenant_id = :tenant_id ORDER BY id DESC LIMIT 1'
            )->execute(['tenant_id' => $tenantId]);
        }
    }

    private function baseUrl(array $gateway): string
    {
        $configured = trim((string) ($gateway['api_base_url'] ?? ''));
        if ($configured !== '') {
            if ((string) ($gateway['provider'] ?? '') === 'pagbank') {
                return $this->normalizePagBankBaseUrl($configured, (string) ($gateway['environment'] ?? 'production'));
            }
            return rtrim($configured, '/');
        }
        return match ((string) $gateway['provider']) {
            'asaas' => ($gateway['environment'] ?? '') === 'sandbox'
                ? 'https://api-sandbox.asaas.com/v3'
                : 'https://api.asaas.com/v3',
            'mercadopago' => 'https://api.mercadopago.com',
            'stripe' => 'https://api.stripe.com',
            'pagbank' => ($gateway['environment'] ?? '') === 'sandbox'
                ? 'https://sandbox.api.pagseguro.com'
                : 'https://api.pagseguro.com',
            default => '',
        };
    }

    private function apiKey(array $gateway): string
    {
        $encrypted = (string) ($gateway['api_key_encrypted'] ?? '');
        if ($encrypted === '') {
            throw new RuntimeException('API Key não configurada para o gateway ' . ($gateway['label'] ?? ''));
        }
        $apiKey = trim(Crypto::decrypt($encrypted));
        if ((string) ($gateway['provider'] ?? '') === 'pagbank') {
            $apiKey = (string) preg_replace('/^Authorization\s*:\s*/i', '', $apiKey);
            $apiKey = (string) preg_replace('/^Bearer\s+/i', '', $apiKey);
            $apiKey = trim($apiKey, " \t\n\r\0\x0B\"'");
        }
        if ($apiKey === '' || preg_match('/[\r\n]/', $apiKey)) {
            throw new RuntimeException('A chave de acesso do gateway está vazia ou possui formato inválido.');
        }
        return $apiKey;
    }

    private function normalizePagBankBaseUrl(string $configured, string $environment): string
    {
        if (!preg_match('#^https?://#i', $configured)) {
            $configured = 'https://' . $configured;
        }
        $parts = parse_url($configured);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $expectedHost = $environment === 'sandbox'
            ? 'sandbox.api.pagseguro.com'
            : 'api.pagseguro.com';
        if ($host !== $expectedHost) {
            throw new RuntimeException(
                'URL base PagBank incompatível com o ambiente selecionado. Deixe o campo vazio ou use https://' . $expectedHost . '.'
            );
        }
        return 'https://' . $expectedHost;
    }

    private function webhookSecret(array $gateway): string
    {
        $encrypted = (string) ($gateway['webhook_secret_encrypted'] ?? '');
        return $encrypted !== '' ? Crypto::decrypt($encrypted) : '';
    }

    /** @param array<string,mixed> $gateway @param array<string,mixed> $payload @param array<string,mixed> $headers */
    private function authenticatePaymentWebhook(
        string $provider,
        array $gateway,
        array $payload,
        array $headers,
        string $rawBody,
        WebhookSecurityService $security
    ): void {
        $maxAge = max(30, (int) Env::get('SECURITY_WEBHOOK_MAX_AGE_SECONDS', 300));
        if ($provider === 'pagbank') {
            // PagBank assina com SHA-256(Token da API + "-" + payload bruto).
            $security->verifyPagBank($rawBody, $this->apiKey($gateway), $headers);
            return;
        }
        if ($provider === 'stripe') {
            $security->verifyStripe($rawBody, $this->webhookSecret($gateway), $headers, $maxAge);
            return;
        }
        if ($provider === 'mercadopago') {
            $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
            $dataId = trim((string) (
                $_GET['data_id']
                ?? $_GET['data.id']
                ?? $data['id']
                ?? $payload['id']
                ?? ''
            ));
            $security->verifyMercadoPago($this->webhookSecret($gateway), $headers, $dataId, $maxAge);
            return;
        }
        if ($provider === 'asaas') {
            $security->verifyStaticToken(
                'payment.asaas',
                $this->webhookSecret($gateway),
                $headers,
                ['asaas-access-token'],
                32
            );
            return;
        }
        if (in_array($provider, ['infinitepay', 'external'], true)) {
            $provided = $security->header($headers, ['x-rs-payment-token', 'x-webhook-token', 'x-rs-connect-token']);
            if ($provided === '') {
                $authorization = $security->header($headers, ['authorization']);
                if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches) === 1) {
                    $provided = trim((string) ($matches[1] ?? ''));
                }
            }
            $security->verifyStaticToken(
                'payment.' . $provider,
                $this->webhookSecret($gateway),
                ['x-rs-payment-token' => $provided],
                ['x-rs-payment-token'],
                32
            );
            return;
        }

        throw new RuntimeException('Provedor de webhook não suportado.', 400);
    }

    /** @param array<string,mixed> $payload @param array<string,mixed> $headers */
    private function paymentWebhookEventKey(
        string $provider,
        array $payload,
        array $headers,
        string $rawBody,
        WebhookSecurityService $security
    ): string {
        $eventId = $this->paymentWebhookExternalEventId($provider, $payload, $headers);
        $status = trim((string) (
            $payload['status']
            ?? $payload['event']
            ?? $payload['action']
            ?? ($payload['charges'][0]['status'] ?? '')
            ?? ''
        ));
        $occurredAt = trim((string) (
            $payload['created_at']
            ?? $payload['updated_at']
            ?? $payload['dateCreated']
            ?? $payload['date_created']
            ?? ''
        ));

        // Em PagBank, o campo id normalmente identifica a cobrança e pode reaparecer
        // quando o status muda. Status/data/hash impedem que uma atualização legítima
        // seja confundida com replay de um evento anterior.
        $stableCandidate = $provider === 'pagbank'
            ? implode('|', array_filter([$eventId, $status, $occurredAt, hash('sha256', $rawBody)]))
            : $eventId;

        return $security->eventKey('payment.' . $provider, [
            $stableCandidate,
            trim((string) ($payload['idempotency_key'] ?? '')),
        ], $rawBody);
    }

    /** @param array<string,mixed> $payload @param array<string,mixed> $headers */
    private function paymentWebhookExternalEventId(string $provider, array $payload, array $headers): string
    {
        $security = new WebhookSecurityService();
        return trim((string) match ($provider) {
            'asaas' => $payload['id'] ?? '',
            'stripe' => $payload['id'] ?? '',
            'mercadopago' => $security->header($headers, ['x-request-id'])
                ?: ($payload['id'] ?? ($payload['data']['id'] ?? '')),
            'pagbank' => $security->header($headers, ['x-request-id'])
                ?: ($payload['event_id'] ?? $payload['id'] ?? $payload['checkout_id'] ?? ''),
            default => $payload['event_id'] ?? $payload['idempotency_key'] ?? $payload['id'] ?? '',
        });
    }

    private function invoiceDescription(array $invoice): string
    {
        return sprintf(
            'RS Connect — %s — %s a %s',
            $invoice['plan_name'] ?: 'Assinatura SaaS',
            date('d/m/Y', strtotime((string) $invoice['period_start'])),
            date('d/m/Y', strtotime((string) $invoice['period_end']))
        );
    }

    private function mapAsaasStatus(string $event, string $status): ?string
    {
        $source = strtoupper($event . ' ' . $status);
        if (str_contains($source, 'PAYMENT_RECEIVED') || str_contains($source, 'CONFIRMED') || str_contains($source, 'RECEIVED')) {
            return 'paid';
        }
        if (str_contains($source, 'OVERDUE')) {
            return 'overdue';
        }
        if (str_contains($source, 'DELETED') || str_contains($source, 'CANCEL')) {
            return 'cancelled';
        }
        return null;
    }

    private function mapMercadoPagoStatus(string $status): ?string
    {
        $status = strtolower($status);
        if (str_contains($status, 'approved') || str_contains($status, 'payment.updated')) {
            return 'paid';
        }
        if (str_contains($status, 'rejected') || str_contains($status, 'cancel')) {
            return 'cancelled';
        }
        if (str_contains($status, 'pending') || str_contains($status, 'in_process')) {
            return 'open';
        }
        return null;
    }


    private function mapPagBankStatus(string $status): ?string
    {
        $source = strtoupper($status);
        if ($source === '') {
            return null;
        }
        if (str_contains($source, 'PAID') || str_contains($source, 'AUTHORIZED') || str_contains($source, 'APPROVED') || str_contains($source, 'COMPLETED')) {
            return 'paid';
        }
        if (str_contains($source, 'CANCEL') || str_contains($source, 'DECLINED') || str_contains($source, 'DENIED') || str_contains($source, 'EXPIRED')) {
            return 'cancelled';
        }
        if (str_contains($source, 'OVERDUE')) {
            return 'overdue';
        }
        if (str_contains($source, 'WAITING') || str_contains($source, 'PENDING') || str_contains($source, 'IN_ANALYSIS')) {
            return 'open';
        }
        return null;
    }

    private function mapExternalStatus(string $status): ?string
    {
        $source = mb_strtolower(trim($status));
        if ($source === '') {
            return null;
        }
        if (in_array($source, ['paid', 'paga', 'pago', 'approved', 'completed', 'confirmed', 'recebida', 'recebido'], true)) {
            return 'paid';
        }
        if (in_array($source, ['overdue', 'vencida', 'vencido', 'late'], true)) {
            return 'overdue';
        }
        if (in_array($source, ['cancelled', 'canceled', 'cancelada', 'cancelado', 'expired', 'expirada', 'expirado', 'declined'], true)) {
            return 'cancelled';
        }
        if (in_array($source, ['open', 'aberta', 'aberto', 'pending', 'pendente', 'waiting', 'created', 'active'], true)) {
            return 'open';
        }
        return null;
    }

    private function extractPagBankStatus(array $payload): string
    {
        foreach (['payments', 'charges'] as $collectionKey) {
            $collection = $payload[$collectionKey] ?? [];
            if (!is_array($collection)) {
                continue;
            }
            foreach ($collection as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $status = strtoupper((string) ($item['status'] ?? ''));
                if ($status === 'PAID') {
                    return $status;
                }
            }
            foreach ($collection as $item) {
                if (is_array($item) && !empty($item['status'])) {
                    return (string) $item['status'];
                }
            }
        }
        return (string) ($payload['status'] ?? '');
    }

    private function requestJson(string $method, string $url, array $headers = [], array $payload = []): array
    {
        return $this->request($method, $url, $headers, $payload, 'json');
    }

    private function requestForm(string $method, string $url, array $headers = [], array $payload = []): array
    {
        return $this->request($method, $url, $headers, $payload, 'form');
    }

    private function request(string $method, string $url, array $headers, array $payload, string $bodyType): array
    {
        if ($url === '') {
            throw new RuntimeException('URL do gateway não configurada.');
        }
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Não foi possível iniciar cURL.');
        }

        $body = $bodyType === 'form'
            ? http_build_query($payload)
            : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($bodyType === 'form') {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => (int) Env::get('PAYMENT_HTTP_TIMEOUT', 30),
        ]);
        if (in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'], true)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body ?: '');
        }

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException('Erro HTTP no gateway: ' . $error);
        }
        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded)) {
            $decoded = ['raw' => (string) $response];
        }
        if ($status < 200 || $status >= 300) {
            $pagBankError = is_array($decoded['error_messages'][0] ?? null)
                ? $decoded['error_messages'][0]
                : [];
            $description = (string) (
                $pagBankError['description']
                ?? $decoded['errors'][0]['description']
                ?? $decoded['message']
                ?? $decoded['error']['message']
                ?? $decoded['raw']
                ?? ('HTTP ' . $status)
            );
            $parameter = trim((string) ($pagBankError['parameter_name'] ?? ''));
            if (str_contains($description, 'Invalid key=value pair') && str_contains($description, 'Authorization header')) {
                throw new RuntimeException(
                    'PagBank recusou a autenticação. Em Meios de pagamento, deixe a URL base vazia e informe somente o Token da API, sem os textos Authorization: ou Bearer. Confirme também se o token pertence ao ambiente Sandbox ou Produção selecionado.'
                );
            }
            $message = $parameter !== ''
                ? $description . ' Campo recusado: ' . $parameter . '.'
                : $description;
            throw new RuntimeException('Gateway retornou erro: ' . $message);
        }

        return $decoded;
    }

    private function logEvent(?int $tenantId, ?int $gatewayId, string $event, string $status, array $payload): void
    {
        try {
            $statement = Database::connection()->prepare(
                'INSERT INTO payment_gateway_events
                    (tenant_id, gateway_id, event, status, external_id, payload_json)
                 VALUES
                    (:tenant_id, :gateway_id, :event, :status, :external_id, :payload)'
            );
            $security = new WebhookSecurityService();
            $safePayload = $security->sanitize($payload);
            if (isset($safePayload['reason']) && is_string($safePayload['reason'])) {
                $safePayload['reason'] = $security->redactText($safePayload['reason']);
            }
            $statement->execute([
                'tenant_id' => $tenantId,
                'gateway_id' => $gatewayId,
                'event' => $event,
                'status' => $status,
                'external_id' => mb_substr((string) ($payload['external_id'] ?? $payload['event_id'] ?? ''), 0, 190),
                'payload' => json_encode($safePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        } catch (Throwable) {
            // Log financeiro não deve derrubar webhook.
        }
    }
}
