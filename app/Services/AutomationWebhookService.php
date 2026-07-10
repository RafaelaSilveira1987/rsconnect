<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Crypto;
use App\Core\Database;
use App\Core\Env;
use App\Core\Router;
use PDO;
use Throwable;

final class AutomationWebhookService
{
    /**
     * Dispara um evento para n8n.
     *
     * Regras:
     * 1. Se $url for informado, envia diretamente para ele (compatibilidade com campos antigos).
     * 2. Se $tenantId for informado, usa os fluxos ativos cadastrados para aquela empresa.
     * 3. Se nenhum fluxo existir, cai no N8N_WEBHOOK_URL global do .env, apenas como fallback legado.
     *
     * @return array<int,array<string,mixed>>
     */
    public function dispatch(string $event, array $payload, ?string $url = null, ?int $tenantId = null): array
    {
        $event = trim($event);
        $tenantId = $tenantId ?: $this->tenantIdFromPayload($payload);
        $results = [];

        $explicitUrl = trim((string) ($url ?? ''));
        if ($explicitUrl !== '') {
            $results[] = $this->sendToUrl($explicitUrl, $event, $payload, $tenantId, null, null);
            return $results;
        }

        if ($tenantId > 0) {
            $flows = $this->flowsForEvent($tenantId, $event);
            foreach ($flows as $flow) {
                $target = Crypto::decrypt((string) $flow['webhook_url_encrypted']);
                $secret = !empty($flow['secret_token_encrypted']) ? Crypto::decrypt((string) $flow['secret_token_encrypted']) : null;
                $results[] = $this->sendToUrl($target, $event, $payload, $tenantId, (int) $flow['id'], $secret, (string) $flow['name']);
            }

            if ($results !== []) {
                return $results;
            }

            $this->log($tenantId, null, $event, 'skipped', null, null, 'Nenhum fluxo n8n ativo para este evento/empresa.', $payload);
        }

        $fallback = trim((string) Env::get('N8N_WEBHOOK_URL', ''));
        if ($fallback !== '') {
            $results[] = $this->sendToUrl($fallback, $event, $payload, $tenantId > 0 ? $tenantId : null, null, null, 'Fallback .env');
        }

        return $results;
    }

    /** @return array<int,array<string,mixed>> */
    private function flowsForEvent(int $tenantId, string $event): array
    {
        try {
            $statement = Database::connection()->prepare(
                'SELECT *
                 FROM n8n_tenant_flows
                 WHERE tenant_id = :tenant_id
                   AND status = "active"
                 ORDER BY flow_key ASC, id ASC'
            );
            $statement->execute(['tenant_id' => $tenantId]);
            $flows = [];
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $flow) {
                if ($this->matchesEvent((string) ($flow['events_json'] ?? ''), $event)) {
                    $flows[] = $flow;
                }
            }
            return $flows;
        } catch (Throwable) {
            // Permite deploy antes da migration 010 sem quebrar webhooks existentes.
            return [];
        }
    }

    private function matchesEvent(?string $eventsJson, string $event): bool
    {
        if ($eventsJson === null || trim($eventsJson) === '') {
            return true;
        }
        $events = json_decode($eventsJson, true);
        if (!is_array($events) || $events === []) {
            return true;
        }

        foreach ($events as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate === '' || $candidate === '*' || $candidate === 'all') {
                return true;
            }
            if ($candidate === $event) {
                return true;
            }
            if (str_ends_with($candidate, '.*')) {
                $prefix = substr($candidate, 0, -1);
                if (str_starts_with($event, $prefix)) {
                    return true;
                }
            }
        }
        return false;
    }

    private function sendToUrl(string $target, string $event, array $payload, ?int $tenantId, ?int $flowId, ?string $secretToken = null, ?string $flowName = null): array
    {
        $target = trim($target);
        if ($target === '' || !filter_var($target, FILTER_VALIDATE_URL)) {
            $this->log($tenantId, $flowId, $event, 'error', null, $this->maskUrl($target), 'URL do webhook n8n inválida.', $payload);
            return ['ok' => false, 'error' => 'URL inválida', 'flow_id' => $flowId];
        }

        $callbackToken = trim((string) Env::get('N8N_CALLBACK_TOKEN', ''));
        if ($callbackToken === '' && $secretToken !== null) {
            $callbackToken = trim($secretToken);
        }

        $body = [
            'event' => $event,
            'source' => 'rs-connect',
            'tenant_id' => $tenantId,
            'flow_id' => $flowId,
            'flow_name' => $flowName,
            'payload' => $payload,
            'callback' => [
                'url' => Router::url('/webhooks/n8n/callback'),
                'token' => $callbackToken !== '' ? $callbackToken : null,
            ],
            'sent_at' => date('c'),
        ];

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-RS-Connect-Event: ' . $event,
        ];
        if ($tenantId !== null) {
            $headers[] = 'X-RS-Connect-Tenant-Id: ' . $tenantId;
        }
        if ($secretToken !== null && trim($secretToken) !== '') {
            $headers[] = 'Authorization: Bearer ' . $secretToken;
            $headers[] = 'X-RS-Connect-Token: ' . $secretToken;
        }

        try {
            $curl = curl_init($target);
            if ($curl === false) {
                throw new \RuntimeException('Não foi possível iniciar o cURL.');
            }

            curl_setopt_array($curl, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 6,
                CURLOPT_TIMEOUT => 18,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ]);

            $response = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $error = curl_error($curl);
            curl_close($curl);

            if ($response === false || $status < 200 || $status >= 300) {
                throw new \RuntimeException($error !== '' ? $error : 'HTTP ' . $status . ': ' . mb_substr((string) $response, 0, 500));
            }

            $this->markFlowSuccess($flowId);
            $this->log($tenantId, $flowId, $event, 'success', $status, $this->maskUrl($target), mb_substr((string) $response, 0, 1000), $payload);
            return ['ok' => true, 'http_status' => $status, 'flow_id' => $flowId, 'response' => $response];
        } catch (Throwable $exception) {
            $message = mb_substr($exception->getMessage(), 0, 700);
            $this->markFlowError($flowId, $message);
            $this->log($tenantId, $flowId, $event, 'error', null, $this->maskUrl($target), $message, $payload);
            return ['ok' => false, 'error' => $message, 'flow_id' => $flowId];
        }
    }

    private function tenantIdFromPayload(array $payload): int
    {
        foreach (['tenant_id', 'tenantId'] as $key) {
            if (isset($payload[$key]) && (int) $payload[$key] > 0) {
                return (int) $payload[$key];
            }
        }
        if (isset($payload['payload']) && is_array($payload['payload'])) {
            return $this->tenantIdFromPayload($payload['payload']);
        }
        return 0;
    }

    private function markFlowSuccess(?int $flowId): void
    {
        if ($flowId === null) {
            return;
        }
        try {
            Database::connection()->prepare(
                'UPDATE n8n_tenant_flows
                 SET last_success_at = NOW(), last_error_at = NULL, last_error = NULL
                 WHERE id = :id'
            )->execute(['id' => $flowId]);
        } catch (Throwable) {
        }
    }

    private function markFlowError(?int $flowId, string $message): void
    {
        if ($flowId === null) {
            return;
        }
        try {
            Database::connection()->prepare(
                'UPDATE n8n_tenant_flows
                 SET last_error_at = NOW(), last_error = :error
                 WHERE id = :id'
            )->execute(['id' => $flowId, 'error' => mb_substr($message, 0, 500)]);
        } catch (Throwable) {
        }
    }

    private function log(?int $tenantId, ?int $flowId, string $event, string $status, ?int $httpStatus, ?string $maskedUrl, ?string $message, array $payload): void
    {
        if ($tenantId === null || $tenantId < 1) {
            return;
        }
        try {
            Database::connection()->prepare(
                'INSERT INTO n8n_flow_logs
                    (tenant_id, flow_id, event, status, http_status, request_url_masked, response_preview, error_message, payload_json)
                 VALUES
                    (:tenant_id, :flow_id, :event, :status, :http_status, :request_url_masked, :response_preview, :error_message, :payload_json)'
            )->execute([
                'tenant_id' => $tenantId,
                'flow_id' => $flowId,
                'event' => $event,
                'status' => $status,
                'http_status' => $httpStatus,
                'request_url_masked' => $maskedUrl,
                'response_preview' => $status === 'success' ? $message : null,
                'error_message' => $status !== 'success' ? $message : null,
                'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        } catch (Throwable) {
        }
    }

    private function maskUrl(string $url): string
    {
        if ($url === '') {
            return '';
        }
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return mb_substr($url, 0, 500);
        }
        $path = $parts['path'] ?? '';
        return mb_substr($parts['scheme'] . '://' . $parts['host'] . $path, 0, 500);
    }
}
