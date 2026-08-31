<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Env;
use App\Core\Router;
use App\Core\RequestSecurity;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Throwable;

final class PublicSignupService
{
    // Compatibilidade histórica: public const VERSION = '36.24.5';
    // Compatibilidade histórica: public const VERSION = '36.24.6';
    // Compatibilidade histórica: public const VERSION = '36.25.1';
    public const VERSION = '36.26.0';

    /** @return array<string,mixed> */
    public function offer(): array
    {
        try {
            $pdo = Database::connection();
            $settings = $pdo->query('SELECT * FROM public_signup_settings WHERE id = 1 LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: [];
            $planKey = (string) ($settings['plan_key'] ?? 'starter');
            if ($planKey !== 'starter') {
                $planKey = 'starter';
            }

            $planStatement = $pdo->prepare(
                'SELECT * FROM saas_plans WHERE plan_key = :plan_key AND status = "active" LIMIT 1'
            );
            $planStatement->execute(['plan_key' => $planKey]);
            $plan = $planStatement->fetch(PDO::FETCH_ASSOC) ?: [];

            $gateway = $this->configuredGateway($settings);
            $price = (float) ($plan['rs_ai_monthly_price'] ?? $plan['monthly_price'] ?? 0);
            $features = json_decode((string) ($plan['features_json'] ?? '[]'), true);
            if (!is_array($features)) {
                $features = [];
            }
            $limits = json_decode((string) ($plan['limits_json'] ?? '{}'), true);
            if (!is_array($limits)) {
                $limits = [];
            }

            $commercialPhone = $this->normalizePhone((string) ($settings['commercial_whatsapp'] ?? Env::get('COMMERCIAL_WHATSAPP', '')));
            $commercialMessage = trim((string) ($settings['commercial_message'] ?? 'Olá! Quero conhecer os planos Profissional e Empresarial da RS Connect.'));
            $commercialUrl = $commercialPhone !== ''
                ? 'https://wa.me/' . rawurlencode($commercialPhone) . '?text=' . rawurlencode($commercialMessage)
                : '';
            $couponMetrics = ['active' => 0];
            try {
                $couponMetrics = (new PublicSignupCouponService())->metrics();
            } catch (Throwable) {
                // Durante a janela entre deploy e migration, o cadastro principal continua disponível sem cupons.
            }

            return [
                'enabled' => !empty($settings['enabled']) && $gateway !== null && $plan !== [] && $price > 0,
                'configured' => $gateway !== null && $plan !== [] && $price > 0,
                'settings' => $settings,
                'plan' => $plan,
                'gateway' => $gateway,
                'price' => $price,
                'trial_days' => max(1, (int) ($settings['trial_days'] ?? 7)),
                'pix_enabled' => !empty($settings['pix_enabled']),
                'coupons_enabled' => (int) ($couponMetrics['active'] ?? 0) > 0,
                'grace_days' => max(0, (int) ($settings['grace_days'] ?? 3)),
                'features' => $features,
                'limits' => $limits,
                'commercial_url' => $commercialUrl,
                'terms_url' => $this->safePublicUrl((string) ($settings['terms_url'] ?? '/termos-de-uso'), '/termos-de-uso'),
                'privacy_url' => $this->safePublicUrl((string) ($settings['privacy_url'] ?? '/politica-de-privacidade'), '/politica-de-privacidade'),
            ];
        } catch (Throwable) {
            return [
                'enabled' => false,
                'configured' => false,
                'settings' => [],
                'plan' => [],
                'gateway' => null,
                'price' => 0.0,
                'trial_days' => 7,
                'pix_enabled' => false,
                'coupons_enabled' => false,
                'grace_days' => 3,
                'features' => [],
                'limits' => [],
                'commercial_url' => '',
                'terms_url' => Router::url('/termos-de-uso'),
                'privacy_url' => Router::url('/politica-de-privacidade'),
            ];
        }
    }

    /** @return array<string,mixed> */
    public function adminData(): array
    {
        $offer = $this->offer();
        $gateways = Database::connection()->query(
            'SELECT id, label, environment, status, is_default,
                    CASE WHEN api_key_encrypted IS NOT NULL AND api_key_encrypted <> "" THEN 1 ELSE 0 END AS has_api_key,
                    CASE WHEN webhook_secret_encrypted IS NOT NULL AND webhook_secret_encrypted <> "" THEN 1 ELSE 0 END AS has_webhook_secret
             FROM payment_gateways
             WHERE provider = "asaas"
             ORDER BY is_default DESC, status, id'
        )->fetchAll(PDO::FETCH_ASSOC);

        $recent = Database::connection()->query(
            'SELECT s.*, t.name AS tenant_name
             FROM public_signup_sessions s
             LEFT JOIN tenants t ON t.id = s.tenant_id
             ORDER BY s.id DESC
             LIMIT 80'
        )->fetchAll(PDO::FETCH_ASSOC);

        $couponService = new PublicSignupCouponService();

        return $offer + [
            'gateways' => $gateways,
            'recent_signups' => $recent,
            'coupons' => $couponService->adminList(),
            'coupon_metrics' => $couponService->metrics(),
        ];
    }

    /** @return array<string,mixed> */
    public function testGatewayConnection(int $gatewayId): array
    {
        if ($gatewayId < 1) {
            throw new RuntimeException('Selecione um gateway Asaas para testar.');
        }

        $statement = Database::connection()->prepare(
            'SELECT * FROM payment_gateways WHERE id = :id AND provider = "asaas" LIMIT 1'
        );
        $statement->execute(['id' => $gatewayId]);
        $gateway = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$gateway) {
            throw new RuntimeException('Gateway Asaas não encontrado.');
        }
        if ((string) ($gateway['status'] ?? '') !== 'active') {
            throw new RuntimeException('Ative o gateway Asaas antes de testar a conexão.');
        }

        $response = $this->requestJson(
            'GET',
            $this->asaasBaseUrl($gateway) . '/myAccount/commercialInfo/',
            ['Accept: application/json', 'access_token: ' . $this->gatewayApiKey($gateway)],
            []
        );

        $accountName = '';
        foreach (['commercialName', 'companyName', 'legalName', 'name'] as $field) {
            $candidate = trim((string) ($response[$field] ?? ''));
            if ($candidate !== '') {
                $accountName = $candidate;
                break;
            }
        }

        return [
            'ok' => true,
            'gateway_id' => (int) $gateway['id'],
            'gateway_label' => (string) ($gateway['label'] ?? 'Asaas'),
            'environment' => (string) ($gateway['environment'] ?? 'production'),
            'account_name' => $accountName,
        ];
    }

    /** @param array<string,mixed> $input */
    public function saveSettings(array $input, ?int $userId): void
    {
        $gatewayId = (int) ($input['gateway_id'] ?? 0);
        $enabled = !empty($input['enabled']) ? 1 : 0;
        $pixEnabled = !empty($input['pix_enabled']) ? 1 : 0;
        $trialDays = max(1, min(30, (int) ($input['trial_days'] ?? 7)));
        $graceDays = max(0, min(30, (int) ($input['grace_days'] ?? 3)));
        $checkoutMinutes = max(10, min(1440, (int) ($input['checkout_minutes'] ?? 60)));
        $commercialPhone = $this->normalizePhone((string) ($input['commercial_whatsapp'] ?? ''));
        $commercialMessage = mb_substr(trim((string) ($input['commercial_message'] ?? '')), 0, 500);
        $termsUrl = $this->safePublicUrl((string) ($input['terms_url'] ?? '/termos-de-uso'), '/termos-de-uso');
        $privacyUrl = $this->safePublicUrl((string) ($input['privacy_url'] ?? '/politica-de-privacidade'), '/politica-de-privacidade');

        if ($gatewayId > 0) {
            $gateway = Database::connection()->prepare(
                'SELECT id, status, api_key_encrypted, webhook_secret_encrypted
                 FROM payment_gateways
                 WHERE id = :id AND provider = "asaas" LIMIT 1'
            );
            $gateway->execute(['id' => $gatewayId]);
            $row = $gateway->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new RuntimeException('Selecione um gateway Asaas válido.');
            }
            if ($enabled === 1 && ((string) $row['status'] !== 'active' || trim((string) $row['api_key_encrypted']) === '' || trim((string) $row['webhook_secret_encrypted']) === '')) {
                throw new RuntimeException('Para ativar a inscrição pública, o Asaas precisa estar ativo, com API Key e token do webhook configurados.');
            }
        } elseif ($enabled === 1) {
            throw new RuntimeException('Selecione o gateway Asaas usado no cadastro público.');
        }

        $statement = Database::connection()->prepare(
            'INSERT INTO public_signup_settings
                (id, enabled, pix_enabled, gateway_id, plan_key, trial_days, grace_days, checkout_minutes,
                 commercial_whatsapp, commercial_message, terms_url, privacy_url, updated_by_user_id)
             VALUES
                (1, :enabled, :pix_enabled, :gateway_id, "starter", :trial_days, :grace_days, :checkout_minutes,
                 :commercial_whatsapp, :commercial_message, :terms_url, :privacy_url, :updated_by_user_id)
             ON DUPLICATE KEY UPDATE
                enabled = VALUES(enabled), pix_enabled = VALUES(pix_enabled), gateway_id = VALUES(gateway_id), plan_key = "starter",
                trial_days = VALUES(trial_days), grace_days = VALUES(grace_days), checkout_minutes = VALUES(checkout_minutes),
                commercial_whatsapp = VALUES(commercial_whatsapp), commercial_message = VALUES(commercial_message),
                terms_url = VALUES(terms_url), privacy_url = VALUES(privacy_url), updated_by_user_id = VALUES(updated_by_user_id)'
        );
        $statement->execute([
            'enabled' => $enabled,
            'pix_enabled' => $pixEnabled,
            'gateway_id' => $gatewayId > 0 ? $gatewayId : null,
            'trial_days' => $trialDays,
            'grace_days' => $graceDays,
            'checkout_minutes' => $checkoutMinutes,
            'commercial_whatsapp' => $commercialPhone !== '' ? $commercialPhone : null,
            'commercial_message' => $commercialMessage !== '' ? $commercialMessage : null,
            'terms_url' => $termsUrl,
            'privacy_url' => $privacyUrl,
            'updated_by_user_id' => $userId,
        ]);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function previewCoupon(array $input): array
    {
        $offer = $this->offer();
        if (empty($offer['enabled'])) {
            throw new RuntimeException('As inscrições estão temporariamente indisponíveis.');
        }
        $email = mb_strtolower(trim((string) ($input['email'] ?? '')));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Informe um e-mail válido antes de aplicar o cupom.');
        }
        $paymentMethod = strtolower(trim((string) ($input['payment_method'] ?? 'credit_card')));
        if (!in_array($paymentMethod, ['credit_card', 'pix'], true)) {
            $paymentMethod = 'credit_card';
        }
        if ($paymentMethod === 'pix' && empty($offer['pix_enabled'])) {
            throw new RuntimeException('O Pix ainda não está disponível neste cadastro.');
        }

        return (new PublicSignupCouponService())->apply(
            (string) ($input['coupon_code'] ?? ''),
            (float) ($offer['price'] ?? 0),
            $email,
            $paymentMethod
        );
    }

    /** @param array<string,mixed> $input @return array{token:string,checkout_url:string,reference:string} */
    public function start(array $input): array
    {
        $offer = $this->offer();
        if (empty($offer['enabled'])) {
            throw new RuntimeException('As inscrições estão temporariamente indisponíveis. Fale com o comercial.');
        }

        $companyName = trim((string) ($input['company_name'] ?? ''));
        $legalName = trim((string) ($input['legal_name'] ?? ''));
        $responsibleName = trim((string) ($input['responsible_name'] ?? ''));
        $email = mb_strtolower(trim((string) ($input['email'] ?? '')));
        $phone = $this->normalizePhone((string) ($input['phone'] ?? ''));
        $document = preg_replace('/\D+/', '', (string) ($input['document'] ?? '')) ?: '';
        $password = (string) ($input['password'] ?? '');
        $confirmation = (string) ($input['password_confirmation'] ?? '');
        $couponCode = PublicSignupCouponService::normalizeCode((string) ($input['coupon_code'] ?? ''));
        $paymentMethod = strtolower(trim((string) ($input['payment_method'] ?? 'credit_card')));
        if (!in_array($paymentMethod, ['credit_card', 'pix'], true)) {
            $paymentMethod = 'credit_card';
        }
        if ($paymentMethod === 'pix' && empty($offer['pix_enabled'])) {
            throw new RuntimeException('O pagamento por Pix ainda não está habilitado para novas inscrições.');
        }

        if (mb_strlen($companyName) < 2 || mb_strlen($responsibleName) < 3) {
            throw new RuntimeException('Informe o nome da empresa e o nome do responsável.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Informe um e-mail válido.');
        }
        if (strlen($phone) < 12 || strlen($phone) > 13 || !str_starts_with($phone, '55')) {
            throw new RuntimeException('Informe o WhatsApp com DDD.');
        }
        if (!in_array(strlen($document), [11, 14], true)) {
            throw new RuntimeException('Informe um CPF ou CNPJ válido.');
        }
        if (strlen($password) < 8 || $password !== $confirmation) {
            throw new RuntimeException('A senha precisa ter pelo menos 8 caracteres e a confirmação deve ser igual.');
        }
        if (empty($input['accept_terms']) || empty($input['accept_privacy'])) {
            throw new RuntimeException('Aceite os Termos de Uso e a Política de Privacidade para continuar.');
        }

        $pdo = Database::connection();
        $duplicateUser = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $duplicateUser->execute(['email' => $email]);
        if ($duplicateUser->fetchColumn()) {
            throw new RuntimeException('Este e-mail já possui uma conta. Use a opção Entrar.');
        }
        $duplicateTenant = $pdo->prepare(
            'SELECT id FROM tenants
             WHERE REPLACE(REPLACE(REPLACE(COALESCE(document, ""), ".", ""), "/", ""), "-", "") = :document
             LIMIT 1'
        );
        $duplicateTenant->execute(['document' => $document]);
        if ($duplicateTenant->fetchColumn()) {
            throw new RuntimeException('Já existe uma empresa cadastrada com este CPF ou CNPJ.');
        }

        $ipHash = hash('sha256', RequestSecurity::clientIp());
        $this->assertRateLimit($email, $ipHash);

        $gateway = is_array($offer['gateway'] ?? null) ? $offer['gateway'] : [];
        $plan = is_array($offer['plan'] ?? null) ? $offer['plan'] : [];
        $configuredTrialDays = (int) $offer['trial_days'];
        $originalAmount = round((float) $offer['price'], 2);
        $coupon = null;
        if ($couponCode !== '') {
            $coupon = (new PublicSignupCouponService())->apply($couponCode, $originalAmount, $email, $paymentMethod);
        }
        $finalAmount = $coupon ? (float) $coupon['final_amount'] : $originalAmount;
        $timezone = new DateTimeZone(Clock::appTimezone());
        $today = new DateTimeImmutable('today', $timezone);
        $isPix = $paymentMethod === 'pix';
        $trialDays = $isPix ? 0 : $configuredTrialDays;
        $bonusDays = $isPix ? $configuredTrialDays : 0;
        $firstCharge = $isPix ? $today : $today->modify('+' . $trialDays . ' days');
        $trialEnds = $isPix ? null : $firstCharge->modify('-1 day');
        $expiresAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('+' . max(10, (int) ($offer['settings']['checkout_minutes'] ?? 60)) . ' minutes')
            ->format('Y-m-d H:i:s');

        $token = bin2hex(random_bytes(32));
        $reference = 'rs-signup-' . bin2hex(random_bytes(12));
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        if (!is_string($passwordHash) || $passwordHash === '') {
            throw new RuntimeException('Não foi possível proteger a senha informada.');
        }

        $pdo->prepare(
            'UPDATE public_signup_sessions SET status = "expired", external_status = "superseded"
             WHERE email = :email AND status IN ("started","checkout_created") AND expires_at < UTC_TIMESTAMP()'
        )->execute(['email' => $email]);

        $statement = $pdo->prepare(
            'INSERT INTO public_signup_sessions
                (token_hash, public_reference, gateway_id, plan_id, company_name, legal_name,
                 responsible_name, email, phone, document, password_hash, amount, original_amount,
                 coupon_id, coupon_code, discount_amount, discount_scope, status,
                 payment_method, bonus_days, trial_days, trial_starts_at, trial_ends_at, first_charge_at,
                 accepted_terms_at, accepted_privacy_at, expires_at, ip_hash, user_agent_hash)
             VALUES
                (:token_hash, :public_reference, :gateway_id, :plan_id, :company_name, :legal_name,
                 :responsible_name, :email, :phone, :document, :password_hash, :amount, :original_amount,
                 :coupon_id, :coupon_code, :discount_amount, :discount_scope, "started",
                 :payment_method, :bonus_days, :trial_days, :trial_starts_at, :trial_ends_at, :first_charge_at,
                 UTC_TIMESTAMP(), UTC_TIMESTAMP(), :expires_at, :ip_hash, :user_agent_hash)'
        );
        $statement->execute([
            'token_hash' => hash('sha256', $token),
            'public_reference' => $reference,
            'gateway_id' => (int) $gateway['id'],
            'plan_id' => (int) $plan['id'],
            'company_name' => mb_substr($companyName, 0, 150),
            'legal_name' => $legalName !== '' ? mb_substr($legalName, 0, 190) : null,
            'responsible_name' => mb_substr($responsibleName, 0, 150),
            'email' => $email,
            'phone' => $phone,
            'document' => $document,
            'password_hash' => $passwordHash,
            'amount' => $finalAmount,
            'original_amount' => $originalAmount,
            'coupon_id' => $coupon['id'] ?? null,
            'coupon_code' => $coupon['code'] ?? null,
            'discount_amount' => $coupon['discount_amount'] ?? 0,
            'discount_scope' => $coupon['duration'] ?? null,
            'payment_method' => $paymentMethod,
            'bonus_days' => $bonusDays,
            'trial_days' => $trialDays,
            'trial_starts_at' => $isPix ? null : $today->format('Y-m-d'),
            'trial_ends_at' => $trialEnds?->format('Y-m-d'),
            'first_charge_at' => $firstCharge->format('Y-m-d'),
            'expires_at' => $expiresAt,
            'ip_hash' => $ipHash,
            'user_agent_hash' => hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown')),
        ]);
        $sessionId = (int) $pdo->lastInsertId();

        try {
            $checkout = $this->createAsaasCheckout($gateway, [
                'reference' => $reference,
                'token' => $token,
                'company_name' => $companyName,
                'responsible_name' => $responsibleName,
                'email' => $email,
                'phone' => $phone,
                'document' => $document,
                'amount' => $finalAmount,
                'original_amount' => $originalAmount,
                'coupon_code' => $coupon['code'] ?? '',
                'discount_scope' => $coupon['duration'] ?? '',
                'payment_method' => $paymentMethod,
                'bonus_days' => $bonusDays,
                'first_charge_at' => $firstCharge->format('Y-m-d'),
                'minutes_to_expire' => max(10, min(1440, (int) ($offer['settings']['checkout_minutes'] ?? 60))),
            ]);

            $pdo->prepare(
                'UPDATE public_signup_sessions
                 SET status = "checkout_created", external_checkout_id = :external_checkout_id,
                     external_checkout_url = :external_checkout_url, external_status = :external_status,
                     checkout_created_at = UTC_TIMESTAMP(), payload_json = :payload
                 WHERE id = :id'
            )->execute([
                'external_checkout_id' => $checkout['id'],
                'external_checkout_url' => $checkout['link'],
                'external_status' => (string) ($checkout['status'] ?? 'ACTIVE'),
                'payload' => json_encode($checkout['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'id' => $sessionId,
            ]);

            return ['token' => $token, 'checkout_url' => $checkout['link'], 'reference' => $reference];
        } catch (Throwable $exception) {
            $pdo->prepare('UPDATE public_signup_sessions SET status = "failed", last_error = :error WHERE id = :id')
                ->execute(['error' => mb_substr($exception->getMessage(), 0, 2000), 'id' => $sessionId]);
            throw $exception;
        }
    }

    /** @return array<string,mixed>|null */
    public function checkoutBridge(string $token): ?array
    {
        $signup = $this->status($token);
        if (!$signup) {
            return null;
        }

        $url = trim((string) ($signup['external_checkout_url'] ?? ''));
        $status = (string) ($signup['status'] ?? '');
        if ($status === 'checkout_created' && !$this->isSafeAsaasCheckoutUrl($url)) {
            return [
                'status' => 'failed',
                'last_error' => 'O link retornado pelo Asaas é inválido ou não pertence ao domínio oficial.',
            ];
        }

        return [
            'status' => $status,
            'checkout_url' => $url,
            'company_name' => (string) ($signup['company_name'] ?? ''),
            'email' => (string) ($signup['email'] ?? ''),
            'payment_method' => (string) ($signup['payment_method'] ?? 'credit_card'),
            'expires_at' => (string) ($signup['expires_at'] ?? ''),
            'last_error' => (string) ($signup['last_error'] ?? ''),
        ];
    }

    /** @return array<string,mixed>|null */
    public function status(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $statement = Database::connection()->prepare(
            'SELECT s.*, sp.name AS plan_name, sp.plan_key
             FROM public_signup_sessions s
             INNER JOIN saas_plans sp ON sp.id = s.plan_id
             WHERE s.token_hash = :token_hash LIMIT 1'
        );
        $statement->execute(['token_hash' => hash('sha256', $token)]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @param array<string,mixed> $payload @param array<string,mixed> $gateway @return array<string,mixed> */
    public function handleAsaasWebhook(array $payload, array $gateway): array
    {
        $event = strtoupper(trim((string) ($payload['event'] ?? '')));
        if ($event === '') {
            return ['handled' => false];
        }

        $session = $this->findSessionFromAsaasPayload($payload);
        $subscriptionId = $this->extractSubscriptionId($payload);
        $customerId = $this->extractScalar($payload, ['customer']);
        $checkoutId = $this->extractCheckoutId($payload);
        $externalStatus = $this->extractScalar($payload, ['status']);

        if (!$session && $subscriptionId !== '') {
            $mapping = Database::connection()->prepare(
                'SELECT s.* FROM public_signup_sessions s
                 INNER JOIN tenant_subscription_gateways g ON g.subscription_id = s.subscription_id
                 WHERE g.provider = "asaas" AND g.external_subscription_id = :external_subscription_id
                 LIMIT 1'
            );
            $mapping->execute(['external_subscription_id' => $subscriptionId]);
            $session = $mapping->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        if (!$session) {
            return ['handled' => false];
        }

        $sessionId = (int) $session['id'];
        Database::connection()->prepare(
            'UPDATE public_signup_sessions
             SET last_webhook_at = UTC_TIMESTAMP(),
                 external_checkout_id = COALESCE(NULLIF(:checkout_id, ""), external_checkout_id),
                 external_customer_id = COALESCE(NULLIF(:customer_id, ""), external_customer_id),
                 external_subscription_id = COALESCE(NULLIF(:subscription_id, ""), external_subscription_id),
                 external_status = COALESCE(NULLIF(:external_status, ""), :event),
                 payload_json = :payload
             WHERE id = :id'
        )->execute([
            'checkout_id' => $checkoutId,
            'customer_id' => $customerId,
            'subscription_id' => $subscriptionId,
            'external_status' => $externalStatus,
            'event' => $event,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'id' => $sessionId,
        ]);

        if ($event === 'CHECKOUT_CREATED') {
            return $this->webhookResult($session, 'checkout_created', 'Checkout criado e aguardando conclusão.', $event);
        }
        if (in_array($event, ['CHECKOUT_CANCELED', 'CHECKOUT_EXPIRED'], true)) {
            $status = $event === 'CHECKOUT_CANCELED' ? 'cancelled' : 'expired';
            Database::connection()->prepare('UPDATE public_signup_sessions SET status = :status WHERE id = :id AND status <> "provisioned"')
                ->execute(['status' => $status, 'id' => $sessionId]);
            return $this->webhookResult($session, $status, 'Checkout encerrado.', $event);
        }

        // Compatibilidade histórica do fluxo original:
        // in_array($event, ['CHECKOUT_PAID', 'SUBSCRIPTION_CREATED'], true)
        $paymentMethod = (string) ($session['payment_method'] ?? 'credit_card');
        $isPixSignup = $paymentMethod === 'pix';
        $isPaidPaymentEvent = in_array($event, ['PAYMENT_CONFIRMED', 'PAYMENT_RECEIVED'], true);

        if ($event === 'SUBSCRIPTION_CREATED' && (int) ($session['tenant_id'] ?? 0) > 0) {
            $this->syncSubscriptionEvent($session, $event, $payload, $subscriptionId, $customerId);
            return $this->webhookResult($session, 'success', 'Renovação da assinatura sincronizada.', $event);
        }

        if ($event === 'CHECKOUT_PAID'
            || ($event === 'SUBSCRIPTION_CREATED' && !$isPixSignup)
            || ($isPixSignup && $isPaidPaymentEvent && (int) ($session['tenant_id'] ?? 0) < 1)) {
            Database::connection()->prepare(
                'UPDATE public_signup_sessions
                 SET status = CASE WHEN status = "provisioned" THEN status ELSE "checkout_completed" END,
                     checkout_completed_at = COALESCE(checkout_completed_at, UTC_TIMESTAMP())
                 WHERE id = :id'
            )->execute(['id' => $sessionId]);
            $provisioned = $this->provision($sessionId, (int) $gateway['id'], $payload);
            if ($isPaidPaymentEvent) {
                $fresh = $this->sessionById($sessionId);
                if ($fresh) {
                    $this->syncPaymentEvent($fresh, $event, $payload);
                    $this->restoreFirstChargeCouponAfterPayment($fresh, $gateway);
                }
            }
            $renewalWarning = '';
            if ($isPixSignup) {
                try {
                    $this->ensurePixRenewalSubscription($sessionId, $gateway, $payload);
                } catch (Throwable $exception) {
                    $renewalWarning = ' A renovação mensal por Pix ficou pendente: ' . $exception->getMessage();
                    Database::connection()->prepare(
                        'UPDATE public_signup_sessions SET last_error = :error WHERE id = :id'
                    )->execute([
                        'error' => mb_substr('Conta ativa; renovação Pix pendente. ' . $exception->getMessage(), 0, 2000),
                        'id' => $sessionId,
                    ]);
                }
            }
            return [
                'handled' => true,
                'status' => 'success',
                'message' => $isPixSignup
                    ? 'Pagamento Pix confirmado e conta criada.' . $renewalWarning
                    : 'Conta criada e trial iniciado.',
                'tenant_id' => (int) $provisioned['tenant_id'],
                'external_id' => $checkoutId ?: $subscriptionId,
                'reference' => (string) $session['public_reference'],
                'event' => $event,
            ];
        }

        if (str_starts_with($event, 'SUBSCRIPTION_')) {
            $this->syncSubscriptionEvent($session, $event, $payload, $subscriptionId, $customerId);
            return $this->webhookResult($session, 'success', 'Assinatura sincronizada.', $event);
        }

        if (str_starts_with($event, 'PAYMENT_')) {
            $this->syncPaymentEvent($session, $event, $payload);
            if ($isPaidPaymentEvent) {
                $fresh = $this->sessionById($sessionId) ?: $session;
                $this->restoreFirstChargeCouponAfterPayment($fresh, $gateway);
            }
            if ($isPixSignup && $isPaidPaymentEvent) {
                try {
                    $this->ensurePixRenewalSubscription($sessionId, $gateway, $payload);
                } catch (Throwable $exception) {
                    Database::connection()->prepare(
                        'UPDATE public_signup_sessions SET last_error = :error WHERE id = :id'
                    )->execute([
                        'error' => mb_substr('Renovação Pix pendente. ' . $exception->getMessage(), 0, 2000),
                        'id' => $sessionId,
                    ]);
                }
            }
            return $this->webhookResult($session, 'success', 'Cobrança da assinatura sincronizada.', $event);
        }

        return $this->webhookResult($session, 'ignored', 'Evento Asaas associado ao cadastro, sem alteração necessária.', $event);
    }

    /** @return array<string,mixed> */
    private function provision(int $sessionId, int $gatewayId, array $payload): array
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $statement = $pdo->prepare('SELECT * FROM public_signup_sessions WHERE id = :id FOR UPDATE');
            $statement->execute(['id' => $sessionId]);
            $session = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$session) {
                throw new RuntimeException('Inscrição não encontrada para provisionamento.');
            }
            if ((int) ($session['tenant_id'] ?? 0) > 0) {
                $pdo->commit();
                return $session;
            }
            if (trim((string) ($session['password_hash'] ?? '')) === '') {
                throw new RuntimeException('A inscrição não possui credencial válida para criar o administrador.');
            }

            $slug = $this->uniqueSlug((string) $session['company_name'], $pdo);
            $tenant = $pdo->prepare(
                'INSERT INTO tenants
                    (name, legal_name, slug, document, email, phone, commercial_whatsapp,
                     plan, status, onboarding_step)
                 VALUES
                    (:name, :legal_name, :slug, :document, :email, :phone, :commercial_whatsapp,
                     "starter", "active", 1)'
            );
            $tenant->execute([
                'name' => $session['company_name'],
                'legal_name' => $session['legal_name'] ?: null,
                'slug' => $slug,
                'document' => $session['document'],
                'email' => $session['email'],
                'phone' => $session['phone'],
                'commercial_whatsapp' => $session['phone'],
            ]);
            $tenantId = (int) $pdo->lastInsertId();

            $user = $pdo->prepare(
                'INSERT INTO users (tenant_id, name, email, password_hash, role, status)
                 VALUES (:tenant_id, :name, :email, :password_hash, "client_admin", "active")'
            );
            $user->execute([
                'tenant_id' => $tenantId,
                'name' => $session['responsible_name'],
                'email' => $session['email'],
                'password_hash' => $session['password_hash'],
            ]);
            $userId = (int) $pdo->lastInsertId();

            $paymentMethod = (string) ($session['payment_method'] ?? 'credit_card');
            $isPix = $paymentMethod === 'pix';
            $firstChargeAt = (string) $session['first_charge_at'];
            $startsAt = $isPix ? $firstChargeAt : (string) $session['trial_starts_at'];
            $trialEndsAt = $isPix ? null : (string) $session['trial_ends_at'];
            $bonusDays = max(0, (int) ($session['bonus_days'] ?? 0));
            $nextBillingAt = $isPix
                ? (new DateTimeImmutable($firstChargeAt))->modify('+1 month +' . $bonusDays . ' days')->format('Y-m-d')
                : $firstChargeAt;
            $periodEnd = (new DateTimeImmutable($nextBillingAt))->modify('-1 day')->format('Y-m-d');
            $commitmentEnd = (new DateTimeImmutable($startsAt))->modify('+3 months -1 day')->format('Y-m-d');
            $billingStatus = $isPix ? 'active' : 'trialing';
            $notes = $isPix
                ? 'Plano Inicial ativado por Pix. Primeiro ciclo com 30 dias mais ' . $bonusDays . ' dias adicionais; renovações mensais por cobrança com QR Code Pix.'
                : 'Plano Inicial criado automaticamente pelo cadastro público com 7 dias grátis.';
            if (!empty($session['coupon_code'])) {
                $couponDuration = (string) ($session['discount_scope'] ?? '') === 'recurring'
                    ? 'em todas as mensalidades'
                    : 'na primeira cobrança';
                $notes .= ' Cupom ' . (string) $session['coupon_code'] . ' aplicado ' . $couponDuration
                    . ', com desconto de R$ ' . number_format((float) ($session['discount_amount'] ?? 0), 2, ',', '.') . '.';
            }
            $subscription = $pdo->prepare(
                'INSERT INTO tenant_subscriptions
                    (tenant_id, plan_id, billing_cycle, ai_billing_mode, commitment_months, commitment_ends_at,
                     billing_status, starts_at, trial_ends_at, trial_days, trial_end_behavior, trial_grace_days,
                     current_period_starts_at, current_period_ends_at, next_billing_at,
                     amount, notes, created_by_user_id)
                 VALUES
                    (:tenant_id, :plan_id, "monthly", "rs_connect", 3, :commitment_ends_at,
                     :billing_status, :starts_at, :trial_ends_at, :trial_days, "await_payment", :trial_grace_days,
                     :period_starts_at, :period_ends_at, :next_billing_at,
                     :amount, :notes, NULL)'
            );
            $subscription->execute([
                'tenant_id' => $tenantId,
                'plan_id' => $session['plan_id'],
                'commitment_ends_at' => $commitmentEnd,
                'billing_status' => $billingStatus,
                'starts_at' => $startsAt,
                'trial_ends_at' => $trialEndsAt,
                'trial_days' => $isPix ? 0 : $session['trial_days'],
                'trial_grace_days' => $this->settingsGraceDays($pdo),
                'period_starts_at' => $startsAt,
                'period_ends_at' => $periodEnd,
                'next_billing_at' => $nextBillingAt,
                'amount' => $session['amount'],
                'notes' => $notes,
            ]);
            $subscriptionLocalId = (int) $pdo->lastInsertId();

            $this->createDefaultPipeline($pdo, $tenantId);

            $subscriptionExternalId = $this->extractSubscriptionId($payload);
            $customerExternalId = $this->extractScalar($payload, ['customer']);
            $checkoutExternalId = $this->extractCheckoutId($payload) ?: (string) ($session['external_checkout_id'] ?? '');

            $mapping = $pdo->prepare(
                'INSERT INTO tenant_subscription_gateways
                    (tenant_id, subscription_id, gateway_id, provider, external_checkout_id,
                     external_customer_id, external_subscription_id, status, first_charge_at,
                     last_event_at, payload_json)
                 VALUES
                    (:tenant_id, :subscription_id, :gateway_id, "asaas", :external_checkout_id,
                     :external_customer_id, :external_subscription_id, :status, :first_charge_at,
                     UTC_TIMESTAMP(), :payload)'
            );
            $mapping->execute([
                'tenant_id' => $tenantId,
                'subscription_id' => $subscriptionLocalId,
                'gateway_id' => $gatewayId,
                'external_checkout_id' => $checkoutExternalId !== '' ? $checkoutExternalId : null,
                'external_customer_id' => $customerExternalId !== '' ? $customerExternalId : null,
                'external_subscription_id' => $subscriptionExternalId !== '' ? $subscriptionExternalId : null,
                'status' => $billingStatus,
                'first_charge_at' => $nextBillingAt,
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);

            $pdo->prepare(
                'UPDATE public_signup_sessions
                 SET status = "provisioned", tenant_id = :tenant_id, user_id = :user_id,
                     subscription_id = :subscription_id, password_hash = NULL,
                     external_customer_id = COALESCE(NULLIF(:external_customer_id, ""), external_customer_id),
                     external_subscription_id = COALESCE(NULLIF(:external_subscription_id, ""), external_subscription_id),
                     coupon_redeemed_at = CASE WHEN coupon_id IS NOT NULL THEN COALESCE(coupon_redeemed_at, UTC_TIMESTAMP()) ELSE coupon_redeemed_at END,
                     provisioned_at = UTC_TIMESTAMP(), last_error = NULL
                 WHERE id = :id'
            )->execute([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'subscription_id' => $subscriptionLocalId,
                'external_customer_id' => $customerExternalId,
                'external_subscription_id' => $subscriptionExternalId,
                'id' => $sessionId,
            ]);

            $pdo->commit();
            return [
                'id' => $sessionId,
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'subscription_id' => $subscriptionLocalId,
            ];
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Database::connection()->prepare('UPDATE public_signup_sessions SET status = "failed", last_error = :error WHERE id = :id')
                ->execute(['error' => mb_substr($exception->getMessage(), 0, 2000), 'id' => $sessionId]);
            throw $exception;
        }
    }

    /** @param array<string,mixed> $session @param array<string,mixed> $payload */
    private function syncSubscriptionEvent(array $session, string $event, array $payload, string $externalSubscriptionId, string $externalCustomerId): void
    {
        $subscriptionId = (int) ($session['subscription_id'] ?? 0);
        if ($subscriptionId < 1) {
            return;
        }
        $localStatus = in_array($event, ['SUBSCRIPTION_INACTIVATED', 'SUBSCRIPTION_DELETED'], true) ? 'canceled' : null;
        Database::connection()->prepare(
            'UPDATE tenant_subscription_gateways
             SET external_subscription_id = COALESCE(NULLIF(:external_subscription_id, ""), external_subscription_id),
                 external_customer_id = COALESCE(NULLIF(:external_customer_id, ""), external_customer_id),
                 status = :status, last_event_at = UTC_TIMESTAMP(), payload_json = :payload
             WHERE subscription_id = :subscription_id'
        )->execute([
            'external_subscription_id' => $externalSubscriptionId,
            'external_customer_id' => $externalCustomerId,
            'status' => $localStatus ?: 'active',
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'subscription_id' => $subscriptionId,
        ]);
        if ($localStatus === 'canceled') {
            Database::connection()->prepare('UPDATE tenant_subscriptions SET billing_status = "canceled" WHERE id = :id')
                ->execute(['id' => $subscriptionId]);
        }
    }

    /** @param array<string,mixed> $session @param array<string,mixed> $payload */
    private function syncPaymentEvent(array $session, string $event, array $payload): void
    {
        $subscriptionId = (int) ($session['subscription_id'] ?? 0);
        $tenantId = (int) ($session['tenant_id'] ?? 0);
        if ($subscriptionId < 1 || $tenantId < 1) {
            return;
        }
        $payment = is_array($payload['payment'] ?? null) ? $payload['payment'] : $payload;
        $externalPaymentId = trim((string) ($payment['id'] ?? ''));
        $paymentStatus = strtoupper((string) ($payment['status'] ?? ''));
        $paid = str_contains($event . ' ' . $paymentStatus, 'PAYMENT_RECEIVED')
            || str_contains($event . ' ' . $paymentStatus, 'PAYMENT_CONFIRMED')
            || in_array($paymentStatus, ['RECEIVED', 'CONFIRMED'], true);
        $overdue = str_contains($event . ' ' . $paymentStatus, 'OVERDUE');
        $open = $event === 'PAYMENT_CREATED' || in_array($paymentStatus, ['PENDING', 'AWAITING_RISK_ANALYSIS'], true);

        if ($paid) {
            $start = new DateTimeImmutable((string) ($payment['paymentDate'] ?? $payment['confirmedDate'] ?? 'today'));
            $end = $start->modify('+1 month -1 day');
            Database::connection()->prepare(
                'UPDATE tenant_subscriptions
                 SET billing_status = "active", trial_converted_at = COALESCE(trial_converted_at, UTC_TIMESTAMP()),
                     current_period_starts_at = :starts_at, current_period_ends_at = :ends_at,
                     next_billing_at = :next_billing_at
                 WHERE id = :id'
            )->execute([
                'starts_at' => $start->format('Y-m-d'),
                'ends_at' => $end->format('Y-m-d'),
                'next_billing_at' => $start->modify('+1 month')->format('Y-m-d'),
                'id' => $subscriptionId,
            ]);
            Database::connection()->prepare('UPDATE tenants SET status = "active" WHERE id = :id')
                ->execute(['id' => $tenantId]);
            $this->upsertAsaasInvoice($session, $payment, 'paid');
        } elseif ($overdue) {
            Database::connection()->prepare('UPDATE tenant_subscriptions SET billing_status = "overdue" WHERE id = :id')
                ->execute(['id' => $subscriptionId]);
            $this->upsertAsaasInvoice($session, $payment, 'overdue');
        } elseif ($open && $externalPaymentId !== '') {
            $this->upsertAsaasInvoice($session, $payment, 'open');
        }

        Database::connection()->prepare(
            'UPDATE tenant_subscription_gateways
             SET status = :status, last_event_at = UTC_TIMESTAMP(), payload_json = :payload
             WHERE subscription_id = :subscription_id'
        )->execute([
            'status' => $paid ? 'active' : ($overdue ? 'overdue' : strtolower($paymentStatus ?: $event)),
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'subscription_id' => $subscriptionId,
        ]);
    }

    /** @param array<string,mixed> $session @param array<string,mixed> $payment */
    private function upsertAsaasInvoice(array $session, array $payment, string $status): void
    {
        $externalId = trim((string) ($payment['id'] ?? ''));
        if ($externalId === '') {
            return;
        }
        $dueDate = (string) ($payment['dueDate'] ?? $session['first_charge_at'] ?? date('Y-m-d'));
        $value = (float) ($payment['value'] ?? $session['amount'] ?? 0);
        $periodEnd = (new DateTimeImmutable($dueDate))->modify('+1 month -1 day')->format('Y-m-d');
        $invoiceNumber = 'ASAAS-' . mb_substr(preg_replace('/[^A-Za-z0-9_-]/', '', $externalId) ?: hash('sha256', $externalId), 0, 48);
        $gatewayId = (int) ($session['gateway_id'] ?? 0);

        Database::connection()->prepare(
            'INSERT INTO tenant_invoices
                (tenant_id, subscription_id, invoice_number, period_start, period_end, amount, due_date,
                 paid_at, status, payment_method, payment_gateway_id, gateway_provider,
                 external_reference, external_customer_id, external_payment_id,
                 external_checkout_url, external_invoice_url, external_status, payment_payload_json)
             VALUES
                (:tenant_id, :subscription_id, :invoice_number, :period_start, :period_end, :amount, :due_date,
                 CASE WHEN :paid_flag = 1 THEN UTC_TIMESTAMP() ELSE NULL END, :status, :payment_method, :gateway_id, "asaas",
                 :external_reference, :external_customer_id, :external_payment_id,
                 :external_checkout_url, :external_invoice_url, :external_status, :payload)
             ON DUPLICATE KEY UPDATE
                 status = VALUES(status), paid_at = COALESCE(VALUES(paid_at), paid_at),
                 external_status = VALUES(external_status), payment_payload_json = VALUES(payment_payload_json)'
        )->execute([
            'tenant_id' => (int) $session['tenant_id'],
            'subscription_id' => (int) $session['subscription_id'],
            'invoice_number' => $invoiceNumber,
            'period_start' => $dueDate,
            'period_end' => $periodEnd,
            'amount' => $value,
            'due_date' => $dueDate,
            'paid_flag' => $status === 'paid' ? 1 : 0,
            'status' => $status,
            'payment_method' => (string) ($session['payment_method'] ?? 'credit_card') === 'pix' ? 'PIX' : 'CREDIT_CARD',
            'gateway_id' => $gatewayId,
            'external_reference' => (string) ($payment['externalReference'] ?? $session['public_reference']),
            'external_customer_id' => (string) ($payment['customer'] ?? $session['external_customer_id'] ?? ''),
            'external_payment_id' => $externalId,
            'external_checkout_url' => (string) ($payment['invoiceUrl'] ?? ''),
            'external_invoice_url' => (string) ($payment['invoiceUrl'] ?? ''),
            'external_status' => (string) ($payment['status'] ?? ''),
            'payload' => json_encode($payment, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    /** @return array<string,mixed>|null */
    private function sessionById(int $sessionId): ?array
    {
        $statement = Database::connection()->prepare('SELECT * FROM public_signup_sessions WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $sessionId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @param array<string,mixed> $session @param array<string,mixed> $gateway */
    private function restoreFirstChargeCouponAfterPayment(array $session, array $gateway): void
    {
        if ((string) ($session['payment_method'] ?? '') !== 'credit_card'
            || (string) ($session['discount_scope'] ?? '') !== 'first_charge'
            || empty($session['coupon_id'])
            || !empty($session['discount_restored_at'])) {
            return;
        }

        $originalAmount = (float) ($session['original_amount'] ?? 0);
        $discountedAmount = (float) ($session['amount'] ?? 0);
        $externalSubscriptionId = trim((string) ($session['external_subscription_id'] ?? ''));
        if ($originalAmount <= $discountedAmount || $externalSubscriptionId === '') {
            return;
        }

        try {
            $response = $this->requestJson(
                'PUT',
                $this->asaasBaseUrl($gateway) . '/subscriptions/' . rawurlencode($externalSubscriptionId),
                ['Content-Type: application/json', 'access_token: ' . $this->gatewayApiKey($gateway)],
                ['value' => $originalAmount]
            );

            $pdo = Database::connection();
            $pdo->prepare(
                'UPDATE public_signup_sessions
                 SET discount_restored_at = UTC_TIMESTAMP(), last_error = NULL
                 WHERE id = :id AND discount_restored_at IS NULL'
            )->execute(['id' => (int) $session['id']]);
            if ((int) ($session['subscription_id'] ?? 0) > 0) {
                $pdo->prepare('UPDATE tenant_subscriptions SET amount = :amount WHERE id = :id')
                    ->execute(['amount' => $originalAmount, 'id' => (int) $session['subscription_id']]);
                $pdo->prepare(
                    'UPDATE tenant_subscription_gateways
                     SET payload_json = :payload, last_event_at = UTC_TIMESTAMP()
                     WHERE subscription_id = :subscription_id AND provider = "asaas"'
                )->execute([
                    'payload' => json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'subscription_id' => (int) $session['subscription_id'],
                ]);
            }
        } catch (Throwable $exception) {
            Database::connection()->prepare(
                'UPDATE public_signup_sessions
                 SET last_error = :error
                 WHERE id = :id'
            )->execute([
                'error' => mb_substr('Pagamento confirmado, mas não foi possível restaurar o valor integral da próxima mensalidade: ' . $exception->getMessage(), 0, 2000),
                'id' => (int) $session['id'],
            ]);
        }
    }

    /** @param array<string,mixed> $gateway @param array<string,mixed> $payload */
    private function ensurePixRenewalSubscription(int $sessionId, array $gateway, array $payload): ?string
    {
        $statement = Database::connection()->prepare('SELECT * FROM public_signup_sessions WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $sessionId]);
        $session = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$session || (string) ($session['payment_method'] ?? '') !== 'pix') {
            return null;
        }

        $existing = trim((string) ($session['external_subscription_id'] ?? ''));
        if ($existing !== '') {
            return $existing;
        }

        $customerId = $this->extractScalar($payload, ['customer']);
        if ($customerId === '') {
            $customerId = trim((string) ($session['external_customer_id'] ?? ''));
        }
        if ($customerId === '') {
            throw new RuntimeException('O Asaas ainda não informou o cliente do pagamento Pix.');
        }

        $firstChargeAt = (string) $session['first_charge_at'];
        $bonusDays = max(0, (int) ($session['bonus_days'] ?? 0));
        $nextDueDate = (new DateTimeImmutable($firstChargeAt))
            ->modify('+1 month +' . $bonusDays . ' days')
            ->format('Y-m-d');

        $response = $this->requestJson('POST', $this->asaasBaseUrl($gateway) . '/subscriptions', [
            'Content-Type: application/json',
            'access_token: ' . $this->gatewayApiKey($gateway),
        ], [
            'customer' => $customerId,
            'billingType' => 'BOLETO',
            'value' => ((string) ($session['discount_scope'] ?? '') === 'first_charge' && (float) ($session['original_amount'] ?? 0) > 0)
                ? (float) $session['original_amount']
                : (float) $session['amount'],
            'nextDueDate' => $nextDueDate,
            'cycle' => 'MONTHLY',
            'description' => 'RS Connect Plano Inicial - renovacao mensal com QR Code Pix',
            'externalReference' => 'signup-renewal:' . (string) $session['public_reference'],
        ]);

        $externalSubscriptionId = trim((string) ($response['id'] ?? ''));
        if ($externalSubscriptionId === '') {
            throw new RuntimeException('O Asaas não retornou o identificador da renovação Pix.');
        }

        $pdo = Database::connection();
        $pdo->prepare(
            'UPDATE public_signup_sessions
             SET external_customer_id = :customer_id, external_subscription_id = :subscription_id,
                 external_status = COALESCE(NULLIF(:status, ""), external_status), last_error = NULL
             WHERE id = :id'
        )->execute([
            'customer_id' => $customerId,
            'subscription_id' => $externalSubscriptionId,
            'status' => (string) ($response['status'] ?? 'ACTIVE'),
            'id' => $sessionId,
        ]);

        if ((int) ($session['subscription_id'] ?? 0) > 0) {
            $pdo->prepare(
                'UPDATE tenant_subscription_gateways
                 SET external_customer_id = :customer_id, external_subscription_id = :subscription_id,
                     status = :status, first_charge_at = :next_due_date,
                     last_event_at = UTC_TIMESTAMP(), payload_json = :payload
                 WHERE subscription_id = :local_subscription_id AND provider = "asaas"'
            )->execute([
                'customer_id' => $customerId,
                'subscription_id' => $externalSubscriptionId,
                'status' => (string) ($response['status'] ?? 'ACTIVE'),
                'next_due_date' => $nextDueDate,
                'payload' => json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'local_subscription_id' => (int) $session['subscription_id'],
            ]);
        }

        return $externalSubscriptionId;
    }

    private function normalizeSignupReference(string $reference): string
    {
        foreach (['signup-renewal:', 'signup:'] as $prefix) {
            if (str_starts_with($reference, $prefix)) {
                return substr($reference, strlen($prefix));
            }
        }
        return $reference;
    }

    /** @param array<string,mixed> $gateway @param array<string,mixed> $data @return array{id:string,link:string,status:string,payload:array<string,mixed>} */
    private function createAsaasCheckout(array $gateway, array $data): array
    {
        $baseUrl = $this->asaasBaseUrl($gateway);
        // Compatibilidade histórica do checkout original:
        // 'billingTypes' => ['CREDIT_CARD']
        // 'chargeTypes' => ['RECURRENT']
        $paymentMethod = (string) ($data['payment_method'] ?? 'credit_card');
        $isPix = $paymentMethod === 'pix';
        $payload = [
            'billingTypes' => [$isPix ? 'PIX' : 'CREDIT_CARD'],
            'chargeTypes' => [$isPix ? 'DETACHED' : 'RECURRENT'],
            'minutesToExpire' => (int) $data['minutes_to_expire'],
            'externalReference' => 'signup:' . (string) $data['reference'],
            'callback' => [
                'successUrl' => Router::url('/signup/success?token=' . rawurlencode((string) $data['token'])),
                'cancelUrl' => Router::url('/signup/cancelled?token=' . rawurlencode((string) $data['token'])),
                'expiredUrl' => Router::url('/signup/expired?token=' . rawurlencode((string) $data['token'])),
            ],
            'items' => [[
                'name' => 'RS Connect Plano Inicial',
                'description' => ($isPix
                    ? 'Primeiro ciclo mensal com dias adicionais, pago via Pix QR Code.'
                    : 'Assinatura mensal com IA RS Connect e sete dias gratis.')
                    . (!empty($data['coupon_code']) ? ' Cupom ' . (string) $data['coupon_code'] . ' aplicado.' : ''),
                'quantity' => 1,
                'value' => (float) $data['amount'],
            ]],
        ];
        if (!$isPix) {
            $payload['subscription'] = [
                'cycle' => 'MONTHLY',
                'nextDueDate' => (string) $data['first_charge_at'] . ' 00:00:00',
            ];
        }

        // Não enviamos customerData neste primeiro passo. No Checkout de
        // pagamento, o Asaas exige o endereço completo quando customerData é
        // informado. Como o cadastro público coleta somente os dados essenciais
        // da conta, o próprio pagador informa e confirma endereço e dados do
        // cartão diretamente no ambiente seguro do Asaas.
        $headers = [
            'Content-Type: application/json',
            'access_token: ' . $this->gatewayApiKey($gateway),
        ];
        $response = $this->requestJson('POST', $baseUrl . '/checkouts', $headers, $payload);
        $id = trim((string) ($response['id'] ?? ''));
        if ($id === '') {
            throw new RuntimeException('O Asaas não retornou o identificador do checkout.');
        }
        $link = trim((string) ($response['link'] ?? ''));
        if ($link === '') {
            $link = $this->asaasCheckoutUrl($gateway, $id);
        }
        if (!$this->isSafeAsaasCheckoutUrl($link)) {
            throw new RuntimeException('O Asaas retornou um link de checkout inválido.');
        }

        return [
            'id' => $id,
            'link' => $link,
            'status' => (string) ($response['status'] ?? 'ACTIVE'),
            'payload' => $response,
        ];
    }

    /** @param array<string,mixed> $gateway */
    private function asaasCheckoutUrl(array $gateway, string $checkoutId): string
    {
        $host = (string) ($gateway['environment'] ?? 'production') === 'sandbox'
            ? 'sandbox.asaas.com'
            : 'asaas.com';

        return 'https://' . $host . '/checkoutSession/show?id=' . rawurlencode($checkoutId);
    }

    private function isSafeAsaasCheckoutUrl(string $url): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return false;
        }

        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        if ($host !== 'asaas.com' && !str_ends_with($host, '.asaas.com')) {
            return false;
        }

        $path = (string) ($parts['path'] ?? '');
        return str_starts_with($path, '/checkoutSession/show');
    }

    /** @return array<string,mixed>|null */
    private function configuredGateway(array $settings): ?array
    {
        $gatewayId = (int) ($settings['gateway_id'] ?? 0);
        if ($gatewayId > 0) {
            $statement = Database::connection()->prepare(
                'SELECT * FROM payment_gateways WHERE id = :id AND provider = "asaas" AND status = "active" LIMIT 1'
            );
            $statement->execute(['id' => $gatewayId]);
        } else {
            $statement = Database::connection()->query(
                'SELECT * FROM payment_gateways WHERE provider = "asaas" AND status = "active" ORDER BY is_default DESC, id LIMIT 1'
            );
        }
        $gateway = $statement->fetch(PDO::FETCH_ASSOC);
        return $gateway ?: null;
    }

    private function assertRateLimit(string $email, string $ipHash): void
    {
        // Falhas técnicas do gateway não podem bloquear o cliente. Somente sessões
        // que realmente abriram ou concluíram um checkout entram no limite.
        $emailLimit = max(1, min(20, (int) Env::get('PUBLIC_SIGNUP_EMAIL_LIMIT_PER_HOUR', 5)));
        $ipLimit = max($emailLimit, min(100, (int) Env::get('PUBLIC_SIGNUP_IP_LIMIT_PER_HOUR', 20)));

        $statement = Database::connection()->prepare(
            'SELECT
                COALESCE(SUM(CASE WHEN email = :email THEN 1 ELSE 0 END), 0) AS email_attempts,
                COALESCE(SUM(CASE WHEN ip_hash = :ip_hash THEN 1 ELSE 0 END), 0) AS ip_attempts
             FROM public_signup_sessions
             WHERE created_at >= (UTC_TIMESTAMP() - INTERVAL 1 HOUR)
               AND status IN ("started", "checkout_created", "checkout_completed")'
        );
        $statement->execute(['email' => $email, 'ip_hash' => $ipHash]);
        $counts = $statement->fetch(PDO::FETCH_ASSOC) ?: [];

        if ((int) ($counts['email_attempts'] ?? 0) >= $emailLimit) {
            throw new RuntimeException('Muitas inscrições iniciadas para este e-mail. Aguarde uma hora ou retome o checkout já criado.');
        }
        if ((int) ($counts['ip_attempts'] ?? 0) >= $ipLimit) {
            throw new RuntimeException('Muitas inscrições iniciadas nesta rede. Aguarde uma hora e tente novamente.');
        }
    }

    /** @param array<string,mixed> $payload @return array<string,mixed>|null */
    private function findSessionFromAsaasPayload(array $payload): ?array
    {
        $reference = $this->extractReference($payload);
        $checkoutId = $this->extractCheckoutId($payload);
        $subscriptionId = $this->extractSubscriptionId($payload);
        $customerId = $this->extractScalar($payload, ['customer']);

        $conditions = [];
        $params = [];
        if ($reference !== '') {
            $conditions[] = 'public_reference = :reference';
            $params['reference'] = $this->normalizeSignupReference($reference);
        }
        if ($checkoutId !== '') {
            $conditions[] = 'external_checkout_id = :checkout_id';
            $params['checkout_id'] = $checkoutId;
        }
        if ($subscriptionId !== '') {
            $conditions[] = 'external_subscription_id = :subscription_id';
            $params['subscription_id'] = $subscriptionId;
        }
        if ($customerId !== '') {
            $conditions[] = 'external_customer_id = :customer_id';
            $params['customer_id'] = $customerId;
        }
        if ($conditions === []) {
            return null;
        }
        $statement = Database::connection()->prepare(
            'SELECT * FROM public_signup_sessions WHERE ' . implode(' OR ', $conditions) . ' ORDER BY id DESC LIMIT 1'
        );
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @param array<string,mixed> $payload */
    private function extractReference(array $payload): string
    {
        foreach ([$payload, $payload['checkout'] ?? null, $payload['subscription'] ?? null, $payload['payment'] ?? null] as $source) {
            if (is_array($source)) {
                $value = trim((string) ($source['externalReference'] ?? ''));
                if ($value !== '') {
                    return $value;
                }
            }
        }
        return '';
    }

    /** @param array<string,mixed> $payload */
    private function extractCheckoutId(array $payload): string
    {
        if (is_array($payload['checkout'] ?? null)) {
            return trim((string) ($payload['checkout']['id'] ?? ''));
        }
        $event = strtoupper((string) ($payload['event'] ?? ''));
        return str_starts_with($event, 'CHECKOUT_') ? trim((string) ($payload['id'] ?? '')) : '';
    }

    /** @param array<string,mixed> $payload */
    private function extractSubscriptionId(array $payload): string
    {
        if (is_array($payload['subscription'] ?? null)) {
            return trim((string) ($payload['subscription']['id'] ?? ''));
        }
        if (is_string($payload['subscription'] ?? null)) {
            return trim((string) $payload['subscription']);
        }
        if (is_array($payload['payment'] ?? null)) {
            $value = $payload['payment']['subscription'] ?? '';
            if (is_array($value)) {
                return trim((string) ($value['id'] ?? ''));
            }
            return trim((string) $value);
        }
        $event = strtoupper((string) ($payload['event'] ?? ''));
        return str_starts_with($event, 'SUBSCRIPTION_') ? trim((string) ($payload['id'] ?? '')) : '';
    }

    /** @param array<string,mixed> $payload @param list<string> $keys */
    private function extractScalar(array $payload, array $keys): string
    {
        foreach ([$payload, $payload['checkout'] ?? null, $payload['subscription'] ?? null, $payload['payment'] ?? null] as $source) {
            if (!is_array($source)) {
                continue;
            }
            foreach ($keys as $key) {
                $value = $source[$key] ?? null;
                if (is_scalar($value) && trim((string) $value) !== '') {
                    return trim((string) $value);
                }
            }
        }
        return '';
    }

    /** @param array<string,mixed> $session @return array<string,mixed> */
    private function webhookResult(array $session, string $status, string $message, string $event): array
    {
        return [
            'handled' => true,
            'status' => $status,
            'message' => $message,
            'tenant_id' => (int) ($session['tenant_id'] ?? 0) ?: null,
            'external_id' => (string) ($session['external_checkout_id'] ?? $session['external_subscription_id'] ?? ''),
            'reference' => (string) ($session['public_reference'] ?? ''),
            'event' => $event,
        ];
    }

    private function settingsGraceDays(PDO $pdo): int
    {
        $value = $pdo->query('SELECT grace_days FROM public_signup_settings WHERE id = 1')->fetchColumn();
        return max(0, min(30, (int) ($value === false ? 3 : $value)));
    }

    private function createDefaultPipeline(PDO $pdo, int $tenantId): void
    {
        $pipeline = $pdo->prepare('INSERT INTO crm_pipelines (tenant_id, name, is_default) VALUES (:tenant_id, "Funil comercial", 1)');
        $pipeline->execute(['tenant_id' => $tenantId]);
        $pipelineId = (int) $pdo->lastInsertId();
        $stages = [
            ['Novo', 'open', 'blue', 1, 10],
            ['Qualificação', 'open', 'cyan', 2, 25],
            ['Proposta', 'open', 'violet', 3, 50],
            ['Negociação', 'open', 'amber', 4, 75],
            ['Ganho', 'won', 'green', 5, 100],
            ['Perdido', 'lost', 'slate', 6, 0],
        ];
        $statement = $pdo->prepare(
            'INSERT INTO crm_stages
                (tenant_id, pipeline_id, name, stage_type, color_key, position, probability)
             VALUES (:tenant_id, :pipeline_id, :name, :stage_type, :color_key, :position, :probability)'
        );
        foreach ($stages as [$name, $type, $color, $position, $probability]) {
            $statement->execute([
                'tenant_id' => $tenantId,
                'pipeline_id' => $pipelineId,
                'name' => $name,
                'stage_type' => $type,
                'color_key' => $color,
                'position' => $position,
                'probability' => $probability,
            ]);
        }
    }

    private function uniqueSlug(string $name, PDO $pdo): string
    {
        $base = mb_strtolower($name);
        $base = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $base) ?: $base;
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', $base), '-') ?: 'empresa';
        $slug = $base;
        $counter = 2;
        $statement = $pdo->prepare('SELECT COUNT(*) FROM tenants WHERE slug = :slug');
        while (true) {
            $statement->execute(['slug' => $slug]);
            if ((int) $statement->fetchColumn() === 0) {
                return $slug;
            }
            $slug = $base . '-' . $counter++;
        }
    }

    /** @param array<string,mixed> $gateway */
    private function asaasBaseUrl(array $gateway): string
    {
        // O endpoint do Asaas é definido exclusivamente pelo ambiente. Valores
        // antigos em api_base_url (por exemplo rsconnect.local ou APP_URL) são
        // ignorados para evitar chamadas ao host errado e reduzir risco de SSRF.
        return (string) ($gateway['environment'] ?? 'production') === 'sandbox'
            ? 'https://api-sandbox.asaas.com/v3'
            : 'https://api.asaas.com/v3';
    }

    /** @param array<string,mixed> $gateway */
    private function gatewayApiKey(array $gateway): string
    {
        $encrypted = trim((string) ($gateway['api_key_encrypted'] ?? ''));
        if ($encrypted === '') {
            throw new RuntimeException('A API Key do Asaas não está configurada.');
        }
        $key = trim(Crypto::decrypt($encrypted));
        if ($key === '' || preg_match('/[\r\n]/', $key)) {
            throw new RuntimeException('A API Key do Asaas possui formato inválido.');
        }
        return $key;
    }

    /** @param list<string> $headers @param array<string,mixed> $payload @return array<string,mixed> */
    private function requestJson(string $method, string $url, array $headers, array $payload): array
    {
        $headers = $this->withAsaasUserAgent($headers);
        $method = strtoupper(trim($method));
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Não foi possível iniciar a comunicação com o Asaas.');
        }
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => max(15, (int) Env::get('PAYMENT_HTTP_TIMEOUT', 30)),
        ];
        // O Asaas exige corpo vazio em chamadas GET. Enviar JSON vazio pode gerar 403.
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($response === false) {
            throw new RuntimeException('Falha de conexão com o Asaas: ' . $error);
        }
        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded)) {
            $decoded = ['raw' => (string) $response];
        }
        if ($status < 200 || $status >= 300) {
            $description = (string) ($decoded['errors'][0]['description'] ?? $decoded['message'] ?? 'Resposta HTTP ' . $status);
            throw new RuntimeException('Asaas: ' . mb_substr($description, 0, 500));
        }
        return $decoded;
    }

    /** @param list<string> $headers @return list<string> */
    private function withAsaasUserAgent(array $headers): array
    {
        // Compatibilidade histórica: Env::get('ASAAS_USER_AGENT', 'RS-Connect/36.24.5')
        foreach ($headers as $header) {
            if (stripos($header, 'User-Agent:') === 0) {
                return $headers;
            }
        }

        $userAgent = trim((string) Env::get('ASAAS_USER_AGENT', 'RS-Connect/36.26.0'));
        if ($userAgent === '' || preg_match('/[\r\n]/', $userAgent)) {
            $userAgent = 'RS-Connect/36.26.0';
        }
        $headers[] = 'User-Agent: ' . mb_substr($userAgent, 0, 255);
        return $headers;
    }

    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?: '';
        if ($digits !== '' && !str_starts_with($digits, '55') && in_array(strlen($digits), [10, 11], true)) {
            $digits = '55' . $digits;
        }
        return $digits;
    }

    private function localPhone(string $phone): string
    {
        $digits = $this->normalizePhone($phone);
        return str_starts_with($digits, '55') ? substr($digits, 2) : $digits;
    }

    private function safePublicUrl(string $url, string $fallback): string
    {
        $url = trim($url);
        if ($url === '') {
            $url = $fallback;
        }
        if (str_starts_with($url, '/')) {
            return Router::url($url);
        }
        if (preg_match('~^https://~i', $url) === 1) {
            return $url;
        }
        return Router::url($fallback);
    }
}
