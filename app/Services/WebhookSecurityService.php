<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Env;
use PDO;
use Throwable;

/**
 * Segurança comum dos webhooks críticos da aplicação.
 *
 * Responsabilidades:
 * - operar em fail-closed em produção;
 * - validar tokens, assinaturas e timestamps;
 * - impedir replay e reprocessamento por idempotência persistente;
 * - evitar que segredos e payloads completos sejam gravados em logs.
 */
final class WebhookSecurityService
{
    private const DEFAULT_MAX_AGE_SECONDS = 300;
    private const DEFAULT_PROCESSING_STALE_SECONDS = 900;

    public function isProduction(): bool
    {
        return in_array(strtolower(trim((string) Env::get('APP_ENV', 'production'))), ['prod', 'production'], true);
    }

    public function strictMode(): bool
    {
        return $this->isProduction()
            || filter_var(Env::get('SECURITY_WEBHOOK_STRICT', true), FILTER_VALIDATE_BOOL);
    }

    public function allowInsecureLocal(): bool
    {
        return !$this->isProduction()
            && filter_var(Env::get('SECURITY_WEBHOOK_ALLOW_INSECURE_LOCAL', false), FILTER_VALIDATE_BOOL);
    }

    public function assertSecretConfigured(string $source, string $secret, int $minimumLength = 24): void
    {
        $this->requireSecret($source, $secret, $minimumLength);
    }

    /** @param array<string,mixed> $headers */
    public function verifyStaticToken(
        string $source,
        string $expected,
        array $headers,
        array $acceptedHeaders,
        int $minimumLength = 24
    ): void {
        $expected = $this->requireSecret($source, $expected, $minimumLength);
        if ($expected === '' && $this->allowInsecureLocal()) {
            return;
        }

        $provided = $this->header($headers, $acceptedHeaders);
        if ($provided === '' || !hash_equals($expected, $provided)) {
            throw new \RuntimeException('Webhook não autorizado.', 401);
        }
    }

    /** @param array<string,mixed> $headers */
    public function verifyInternalHmac(
        string $source,
        string $rawBody,
        string $secret,
        array $headers,
        ?int $maxAgeSeconds = null
    ): int {
        $secret = $this->requireSecret($source, $secret, 24);
        if ($secret === '' && $this->allowInsecureLocal()) {
            return time();
        }

        $timestampRaw = $this->header($headers, ['x-rs-timestamp']);
        $signature = strtolower($this->header($headers, ['x-rs-signature']));
        if ($timestampRaw === '' || $signature === '') {
            throw new \RuntimeException('Assinatura ou timestamp do webhook não informado.', 401);
        }

        $timestamp = $this->normalizeUnixTimestamp($timestampRaw);
        $this->assertFreshTimestamp($timestamp, $maxAgeSeconds);
        $expected = hash_hmac('sha256', $timestampRaw . '.' . $rawBody, $secret);
        if (!hash_equals($expected, $signature)) {
            throw new \RuntimeException('Assinatura do webhook inválida.', 401);
        }

        return $timestamp;
    }

    /** @param array<string,mixed> $headers */
    public function verifyPagBank(string $rawBody, string $apiToken, array $headers): void
    {
        $apiToken = $this->requireSecret('payment.pagbank', $apiToken, 16);
        if ($apiToken === '' && $this->allowInsecureLocal()) {
            return;
        }

        $received = strtolower($this->header($headers, ['x-authenticity-token']));
        if ($received === '') {
            throw new \RuntimeException('Assinatura PagBank não informada.', 401);
        }

        $expected = hash('sha256', $apiToken . '-' . $rawBody);
        if (!hash_equals($expected, $received)) {
            throw new \RuntimeException('Assinatura PagBank inválida.', 401);
        }
    }

    /** @param array<string,mixed> $headers */
    public function verifyStripe(
        string $rawBody,
        string $signingSecret,
        array $headers,
        ?int $maxAgeSeconds = null
    ): int {
        $signingSecret = $this->requireSecret('payment.stripe', $signingSecret, 16);
        if ($signingSecret === '' && $this->allowInsecureLocal()) {
            return time();
        }

        $header = $this->header($headers, ['stripe-signature']);
        [$timestampRaw, $signatures] = $this->parseVersionedSignature($header);
        if ($timestampRaw === '' || $signatures === []) {
            throw new \RuntimeException('Assinatura Stripe não informada ou inválida.', 401);
        }

        $timestamp = $this->normalizeUnixTimestamp($timestampRaw);
        $this->assertFreshTimestamp($timestamp, $maxAgeSeconds);
        $expected = hash_hmac('sha256', $timestampRaw . '.' . $rawBody, $signingSecret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, strtolower($signature))) {
                return $timestamp;
            }
        }

        throw new \RuntimeException('Assinatura Stripe inválida.', 401);
    }

    /** @param array<string,mixed> $headers */
    public function verifyMercadoPago(
        string $secret,
        array $headers,
        string $dataId,
        ?int $maxAgeSeconds = null
    ): int {
        $secret = $this->requireSecret('payment.mercadopago', $secret, 16);
        if ($secret === '' && $this->allowInsecureLocal()) {
            return time();
        }

        $signatureHeader = $this->header($headers, ['x-signature']);
        $requestId = trim($this->header($headers, ['x-request-id']));
        [$timestampRaw, $signatures] = $this->parseVersionedSignature($signatureHeader);
        if ($timestampRaw === '' || $signatures === [] || $requestId === '') {
            throw new \RuntimeException('Assinatura Mercado Pago incompleta.', 401);
        }

        $timestamp = $this->normalizeUnixTimestamp($timestampRaw);
        $this->assertFreshTimestamp($timestamp, $maxAgeSeconds);

        $manifest = '';
        $dataId = strtolower(trim($dataId));
        if ($dataId !== '') {
            $manifest .= 'id:' . $dataId . ';';
        }
        $manifest .= 'request-id:' . $requestId . ';ts:' . $timestampRaw . ';';
        $expected = hash_hmac('sha256', $manifest, $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, strtolower($signature))) {
                return $timestamp;
            }
        }

        throw new \RuntimeException('Assinatura Mercado Pago inválida.', 401);
    }

    public function validateOptionalPayloadTimestamp(mixed $value, ?int $maxAgeSeconds = null): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $timestamp = null;
        if (is_numeric($value)) {
            $timestamp = $this->normalizeUnixTimestamp((string) $value);
        } elseif (is_string($value)) {
            $parsed = strtotime($value);
            if ($parsed !== false) {
                $timestamp = $parsed;
            }
        }

        if ($timestamp === null || $timestamp <= 0) {
            throw new \RuntimeException('Timestamp do webhook inválido.', 403);
        }
        $this->assertFreshTimestamp($timestamp, $maxAgeSeconds);
        return $timestamp;
    }

    /**
     * Reserva o evento antes da regra de negócio.
     *
     * @param array<string,mixed> $metadata
     * @return array{id:int,duplicate:bool,status:string,event_key:string,payload_hash:string}
     */
    public function claim(string $source, string $eventKey, string $rawBody, array $metadata = []): array
    {
        $source = substr(strtolower(trim($source)), 0, 80);
        $eventKey = trim($eventKey);
        if ($source === '' || $eventKey === '') {
            throw new \RuntimeException('Não foi possível determinar a chave idempotente do webhook.', 400);
        }
        if (strlen($eventKey) > 190) {
            $eventKey = 'sha256:' . hash('sha256', $eventKey);
        }

        $payloadHash = hash('sha256', $rawBody);
        $metadataJson = json_encode($this->sanitize($metadata), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $pdo = null;

        try {
            $pdo = Database::connection();
            $pdo->beginTransaction();
            try {
                $insert = $pdo->prepare(
                    'INSERT INTO webhook_security_events
                        (source, event_key, payload_hash, status, metadata_json, first_received_at, last_received_at, last_attempt_at, attempts, duplicate_count)
                     VALUES
                        (:source, :event_key, :payload_hash, "processing", :metadata_json, NOW(), NOW(), NOW(), 1, 0)'
                );
                $insert->execute([
                    'source' => $source,
                    'event_key' => $eventKey,
                    'payload_hash' => $payloadHash,
                    'metadata_json' => $metadataJson !== false ? $metadataJson : null,
                ]);
                $id = (int) $pdo->lastInsertId();
                $pdo->commit();
                return [
                    'id' => $id,
                    'duplicate' => false,
                    'status' => 'processing',
                    'event_key' => $eventKey,
                    'payload_hash' => $payloadHash,
                ];
            } catch (Throwable $insertException) {
                if (!$this->isDuplicateKeyException($insertException)) {
                    throw $insertException;
                }
            }

            $select = $pdo->prepare(
                'SELECT id, payload_hash, status, last_attempt_at, response_code
                 FROM webhook_security_events
                 WHERE source = :source AND event_key = :event_key
                 FOR UPDATE'
            );
            $select->execute(['source' => $source, 'event_key' => $eventKey]);
            $existing = $select->fetch(PDO::FETCH_ASSOC);
            if (!$existing) {
                throw new \RuntimeException('Falha ao recuperar evento idempotente.', 503);
            }

            if (!hash_equals((string) $existing['payload_hash'], $payloadHash)) {
                throw new \RuntimeException('Conflito de idempotência: a mesma chave foi usada com outro payload.', 409);
            }

            $existingStatus = (string) ($existing['status'] ?? 'processing');
            $staleSeconds = max(60, (int) Env::get('SECURITY_WEBHOOK_PROCESSING_STALE_SECONDS', self::DEFAULT_PROCESSING_STALE_SECONDS));
            $lastAttempt = !empty($existing['last_attempt_at']) ? strtotime((string) $existing['last_attempt_at']) : 0;
            $canRetry = $existingStatus === 'failed'
                || ($existingStatus === 'processing' && $lastAttempt > 0 && (time() - $lastAttempt) > $staleSeconds);

            if ($canRetry) {
                $retry = $pdo->prepare(
                    'UPDATE webhook_security_events
                     SET status = "processing", last_received_at = NOW(), last_attempt_at = NOW(),
                         attempts = attempts + 1, duplicate_count = duplicate_count + 1,
                         response_code = NULL, last_error = NULL
                     WHERE id = :id'
                );
                $retry->execute(['id' => (int) $existing['id']]);
                $pdo->commit();
                return [
                    'id' => (int) $existing['id'],
                    'duplicate' => false,
                    'status' => 'processing',
                    'event_key' => $eventKey,
                    'payload_hash' => $payloadHash,
                ];
            }

            $duplicate = $pdo->prepare(
                'UPDATE webhook_security_events
                 SET last_received_at = NOW(), attempts = attempts + 1, duplicate_count = duplicate_count + 1
                 WHERE id = :id'
            );
            $duplicate->execute(['id' => (int) $existing['id']]);
            $pdo->commit();

            return [
                'id' => (int) $existing['id'],
                'duplicate' => true,
                'status' => $existingStatus,
                'event_key' => $eventKey,
                'payload_hash' => $payloadHash,
            ];
        } catch (Throwable $exception) {
            if ($pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($exception instanceof \RuntimeException && $exception->getCode() >= 400) {
                throw $exception;
            }
            throw new \RuntimeException(
                'Proteção de idempotência indisponível. Execute a migration 087_webhook_security_events.sql.',
                503,
                $exception
            );
        }
    }

    /** @param array<string,mixed> $response */
    public function markProcessed(int $id, int $responseCode = 200, array $response = []): void
    {
        if ($id < 1) {
            return;
        }
        try {
            Database::connection()->prepare(
                'UPDATE webhook_security_events
                 SET status = "processed", response_code = :response_code, processed_at = NOW(),
                     response_digest = :response_digest, last_error = NULL
                 WHERE id = :id'
            )->execute([
                'id' => $id,
                'response_code' => $responseCode,
                'response_digest' => $response === []
                    ? null
                    : hash('sha256', (string) json_encode($this->sanitize($response), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            ]);
        } catch (Throwable) {
            // O processamento principal já terminou; a falha será observada nos health checks.
        }
    }

    public function markFailed(int $id, int $responseCode, Throwable|string $error): void
    {
        if ($id < 1) {
            return;
        }
        $message = $error instanceof Throwable ? $error->getMessage() : (string) $error;
        $message = $this->redactText($message);
        try {
            Database::connection()->prepare(
                'UPDATE webhook_security_events
                 SET status = "failed", response_code = :response_code, last_error = :last_error, last_attempt_at = NOW()
                 WHERE id = :id'
            )->execute([
                'id' => $id,
                'response_code' => $responseCode,
                'last_error' => substr($message, 0, 700),
            ]);
        } catch (Throwable) {
            // Não mascara a exceção original.
        }
    }

    /** @param list<string> $candidates */
    public function eventKey(string $source, array $candidates, string $rawBody): string
    {
        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '') {
                return strtolower(trim($source)) . ':' . hash('sha256', $candidate);
            }
        }
        return strtolower(trim($source)) . ':payload:' . hash('sha256', $rawBody);
    }

    /** @param array<string,mixed> $headers @param list<string> $names */
    public function header(array $headers, array $names): string
    {
        $normalized = [];
        foreach ($headers as $key => $value) {
            $name = strtolower(str_replace('_', '-', trim((string) $key)));
            if (str_starts_with($name, 'http-')) {
                $name = substr($name, 5);
            }
            $normalized[$name] = trim(is_array($value) ? (string) reset($value) : (string) $value);
        }

        foreach ($names as $name) {
            $key = strtolower(str_replace('_', '-', trim($name)));
            if (isset($normalized[$key]) && $normalized[$key] !== '') {
                return $normalized[$key];
            }
        }
        return '';
    }

    /** @return array<string,mixed>|list<mixed> */
    public function sanitize(array $value): array
    {
        $sensitive = [
            'api_key', 'apikey', 'access_token', 'token', 'password', 'secret', 'authorization',
            'signature', 'authenticity', 'cookie', 'document', 'tax_id', 'cpf', 'cnpj', 'email', 'phone',
            'card', 'barcode', 'qr_code', 'pix', 'payload', 'raw', 'url',
        ];

        foreach ($value as $key => $item) {
            $keyText = strtolower((string) $key);
            foreach ($sensitive as $needle) {
                if (str_contains($keyText, $needle)) {
                    $value[$key] = '[mascarado]';
                    continue 2;
                }
            }
            if (is_array($item)) {
                $value[$key] = $this->sanitize($item);
            } elseif (is_string($item) && strlen($item) > 1000) {
                $value[$key] = substr($item, 0, 1000) . '…';
            }
        }

        return $value;
    }

    public function redactText(string $value): string
    {
        $value = preg_replace('/(bearer\s+)[a-z0-9._~+\/-]+/i', '$1[mascarado]', $value) ?? $value;
        $value = preg_replace('/((?:token|secret|password|api[_-]?key)\s*[=:]\s*)[^\s,;]+/i', '$1[mascarado]', $value) ?? $value;
        return $value;
    }

    private function requireSecret(string $source, string $secret, int $minimumLength): string
    {
        $secret = trim($secret);
        $invalid = $secret === '' || strlen($secret) < $minimumLength || $this->looksLikePlaceholder($secret);
        if ($invalid) {
            if ($this->allowInsecureLocal()) {
                return '';
            }
            throw new \RuntimeException(
                'Configuração de segurança ausente ou inválida para o webhook ' . $source . '.',
                503
            );
        }
        return $secret;
    }

    private function looksLikePlaceholder(string $value): bool
    {
        $normalized = strtolower($value);
        foreach (['troque', 'change_me', 'seu_token', 'seu_secret', 'sua_chave', 'cole_aqui', 'example'] as $placeholder) {
            if (str_contains($normalized, $placeholder)) {
                return true;
            }
        }
        return false;
    }

    private function normalizeUnixTimestamp(string $value): int
    {
        if (!preg_match('/^\d{9,16}$/', trim($value))) {
            throw new \RuntimeException('Timestamp do webhook inválido.', 403);
        }
        $timestamp = (int) $value;
        while ($timestamp > 9999999999) {
            $timestamp = (int) floor($timestamp / 1000);
        }
        return $timestamp;
    }

    private function assertFreshTimestamp(int $timestamp, ?int $maxAgeSeconds = null): void
    {
        $maxAge = max(30, $maxAgeSeconds ?? (int) Env::get('SECURITY_WEBHOOK_MAX_AGE_SECONDS', self::DEFAULT_MAX_AGE_SECONDS));
        $skew = abs(time() - $timestamp);
        if ($timestamp <= 0 || $skew > $maxAge) {
            throw new \RuntimeException('Evento expirado ou com timestamp fora da tolerância.', 403);
        }
    }

    /** @return array{0:string,1:list<string>} */
    private function parseVersionedSignature(string $header): array
    {
        $timestamp = '';
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($key === 't' || $key === 'ts') {
                $timestamp = trim($value);
            } elseif ($key === 'v1' && trim($value) !== '') {
                $signatures[] = trim($value);
            }
        }
        return [$timestamp, $signatures];
    }

    private function isDuplicateKeyException(Throwable $exception): bool
    {
        $code = (string) $exception->getCode();
        $message = strtolower($exception->getMessage());
        return in_array($code, ['23000', '1062'], true)
            || str_contains($message, 'duplicate entry')
            || str_contains($message, 'unique constraint');
    }
}
