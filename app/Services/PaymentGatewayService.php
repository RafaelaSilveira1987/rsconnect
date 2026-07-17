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
    public const PROVIDER_LABELS = [
        'asaas' => 'Asaas',
        'mercadopago' => 'Mercado Pago',
        'stripe' => 'Stripe',
        'pagbank' => 'PagBank',
        'manual' => 'Manual / externo',
    ];

    public const METHOD_LABELS = [
        'UNDEFINED' => 'Cliente escolhe',
        'PIX' => 'Pix',
        'BOLETO' => 'Boleto',
        'CREDIT_CARD' => 'Cartão de crédito',
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
            'manual' => [
                'external_id' => $invoice['invoice_number'],
                'checkout_url' => '',
                'invoice_url' => '',
                'external_status' => 'manual',
                'payload' => ['message' => 'Gateway manual. Preencha o link externo na observação, se necessário.'],
            ],
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
        $provider = strtolower($provider);
        $gateway = $this->gatewayByProvider($provider);
        $tenantId = null;
        $status = 'ignored';
        $message = 'Evento recebido, mas nenhuma cobrança foi alterada.';
        $invoiceNumber = null;
        $externalId = null;
        $mappedStatus = null;

        if ($gateway && !$this->passesInternalWebhookToken($gateway, $headers, $payload)) {
            $this->logEvent(null, (int) $gateway['id'], 'payment.webhook_denied', 'error', ['provider' => $provider, 'payload' => $payload]);
            throw new RuntimeException('Token de webhook inválido.');
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
            if ($gateway && $externalId !== '' && $invoiceNumber === '') {
                try {
                    $details = $this->requestJson('GET', rtrim($this->baseUrl($gateway), '/') . '/v1/payments/' . rawurlencode($externalId), [
                        'Authorization: Bearer ' . $this->apiKey($gateway),
                    ]);
                    $invoiceNumber = (string) ($details['external_reference'] ?? '');
                    $mappedStatus = $this->mapMercadoPagoStatus((string) ($details['status'] ?? ''));
                } catch (Throwable) {
                    // Se a consulta falhar, registra o payload bruto e deixa como ignored.
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

        $this->logEvent($tenantId, $gateway ? (int) $gateway['id'] : null, 'payment.webhook.' . $provider, $status, [
            'provider' => $provider,
            'invoice_number' => $invoiceNumber,
            'external_id' => $externalId,
            'mapped_status' => $mappedStatus,
            'payload' => $payload,
            'raw' => $rawBody,
        ]);

        return ['status' => $status, 'message' => $message];
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
        $phone = preg_replace('/\D+/', '', (string) ($invoice['tenant_phone'] ?? ''));
        $document = preg_replace('/\D+/', '', (string) ($invoice['tenant_document'] ?? ''));
        $customer = array_filter([
            'name' => (string) ($invoice['tenant_legal_name'] ?: $invoice['tenant_name']),
            'email' => $email,
            'tax_id' => $document,
            'phones' => $phone !== '' ? [[
                'country' => '55',
                'area' => strlen($phone) >= 10 ? substr($phone, -11, 2) : '',
                'number' => strlen($phone) >= 8 ? substr($phone, -9) : $phone,
                'type' => 'MOBILE',
            ]] : null,
        ], static fn ($value): bool => $value !== '' && $value !== null && $value !== []);
        if ($customer !== []) {
            $payload['customer'] = $customer;
        }

        $response = $this->requestJson('POST', rtrim($this->baseUrl($gateway), '/') . '/checkouts', [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey($gateway),
        ], $payload);

        $checkoutUrl = $this->extractPagBankCheckoutUrl($response);

        return [
            'external_id' => (string) ($response['id'] ?? $response['checkout_id'] ?? ''),
            'checkout_url' => $checkoutUrl,
            'invoice_url' => $checkoutUrl,
            'external_status' => (string) ($response['status'] ?? 'created'),
            'payload' => $response,
        ];
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
        foreach (['payment_url', 'checkout_url', 'redirect_url', 'url'] as $key) {
            if (!empty($response[$key]) && is_string($response[$key])) {
                return (string) $response[$key];
            }
        }

        $links = $response['links'] ?? [];
        if (is_array($links)) {
            foreach ($links as $link) {
                if (!is_array($link)) {
                    continue;
                }
                $rel = strtoupper((string) ($link['rel'] ?? $link['type'] ?? ''));
                $href = (string) ($link['href'] ?? $link['url'] ?? '');
                if ($href !== '' && in_array($rel, ['PAY', 'PAYMENT', 'CHECKOUT', 'REDIRECT'], true)) {
                    return $href;
                }
            }
            foreach ($links as $link) {
                if (is_array($link) && !empty($link['href'])) {
                    return (string) $link['href'];
                }
            }
        }

        return '';
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
                 payment_link_created_at = NOW()
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
        $statement = Database::connection()->prepare(
            'UPDATE tenant_invoices
             SET status = :status,
                 paid_at = CASE WHEN :paid_status = "paid" THEN COALESCE(paid_at, NOW()) ELSE paid_at END,
                 external_payment_id = COALESCE(NULLIF(:external_id, ""), external_payment_id),
                 external_status = :external_status,
                 payment_payload_json = :payload
             WHERE id = :id'
        );
        $statement->execute([
            'status' => $status,
            'paid_status' => $status,
            'external_id' => $externalId,
            'external_status' => $status,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'id' => $invoiceId,
        ]);

        if ($status === 'paid') {
            $tenantStatement = Database::connection()->prepare('SELECT tenant_id FROM tenant_invoices WHERE id = :id LIMIT 1');
            $tenantStatement->execute(['id' => $invoiceId]);
            $tenantId = (int) ($tenantStatement->fetchColumn() ?: 0);
            if ($tenantId > 0) {
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
                    Database::connection()->prepare(
                        'UPDATE tenant_subscriptions
                         SET billing_status = CASE
                            WHEN current_period_ends_at >= CURDATE() THEN "active"
                            ELSE billing_status
                         END
                         WHERE tenant_id = :tenant_id
                         ORDER BY id DESC
                         LIMIT 1'
                    )->execute(['tenant_id' => $tenantId]);
                }
            }
        }
    }

    private function baseUrl(array $gateway): string
    {
        $configured = trim((string) ($gateway['api_base_url'] ?? ''));
        if ($configured !== '') {
            return $configured;
        }
        return match ((string) $gateway['provider']) {
            'asaas' => ($gateway['environment'] ?? '') === 'sandbox'
                ? 'https://sandbox.asaas.com/api/v3'
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
        return Crypto::decrypt($encrypted);
    }

    private function webhookSecret(array $gateway): string
    {
        $encrypted = (string) ($gateway['webhook_secret_encrypted'] ?? '');
        return $encrypted !== '' ? Crypto::decrypt($encrypted) : '';
    }

    private function passesInternalWebhookToken(array $gateway, array $headers, array $payload): bool
    {
        $secret = $this->webhookSecret($gateway);
        if ($secret === '') {
            return true;
        }
        $token = (string) ($payload['token'] ?? $_GET['token'] ?? '');
        foreach ($headers as $key => $value) {
            $normalized = strtolower((string) $key);
            if (in_array($normalized, ['x-rs-payment-token', 'x-webhook-token'], true)) {
                $token = is_array($value) ? (string) reset($value) : (string) $value;
                break;
            }
        }
        return hash_equals($secret, $token);
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
            $message = $decoded['errors'][0]['description']
                ?? $decoded['message']
                ?? $decoded['error']['message']
                ?? $decoded['raw']
                ?? ('HTTP ' . $status);
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
            $statement->execute([
                'tenant_id' => $tenantId,
                'gateway_id' => $gatewayId,
                'event' => $event,
                'status' => $status,
                'external_id' => (string) ($payload['external_id'] ?? ''),
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        } catch (Throwable) {
            // Log financeiro não deve derrubar webhook.
        }
    }
}
