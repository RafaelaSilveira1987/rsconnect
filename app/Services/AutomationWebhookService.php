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
    public function dispatch(string $event, array $payload, ?string $url = null, ?int $tenantId = null, ?string $secretToken = null): array
    {
        $event = trim($event);
        $tenantId = $tenantId ?: $this->tenantIdFromPayload($payload);
        $results = [];

        $explicitUrl = trim((string) ($url ?? ''));
        if ($explicitUrl !== '') {
            // URLs legadas configuradas diretamente no agente não podem burlar o contrato
            // dos fluxos cadastrados. Se essa URL pertencer ao writer do Google Calendar,
            // somente calendar.appointment.created pode chegar até ela.
            $guard = $this->explicitTargetGuard($tenantId, $explicitUrl, $event);
            if (!empty($guard['blocked'])) {
                $this->log($tenantId > 0 ? $tenantId : null, $guard['flow_id'] ?? null, $event, 'skipped', null, $this->maskUrl($explicitUrl), (string) ($guard['reason'] ?? 'Evento bloqueado pelo contrato do fluxo.'), $payload);
                return [[
                    'ok' => true,
                    'skipped' => true,
                    'reason' => $guard['reason'] ?? 'protected_flow_contract',
                    'flow_id' => $guard['flow_id'] ?? null,
                ]];
            }
            $results[] = $this->sendToUrl($explicitUrl, $event, $payload, $tenantId, $guard['flow_id'] ?? null, $secretToken, $guard['flow_name'] ?? null);
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
            $guard = $this->explicitTargetGuard($tenantId, $fallback, $event);
            if (!empty($guard['blocked'])) {
                $this->log($tenantId > 0 ? $tenantId : null, $guard['flow_id'] ?? null, $event, 'skipped', null, $this->maskUrl($fallback), (string) ($guard['reason'] ?? 'Fallback bloqueado pelo contrato do fluxo.'), $payload);
                return [[
                    'ok' => true,
                    'skipped' => true,
                    'reason' => $guard['reason'] ?? 'protected_flow_contract',
                    'flow_id' => $guard['flow_id'] ?? null,
                ]];
            }
            $results[] = $this->sendToUrl($fallback, $event, $payload, $tenantId > 0 ? $tenantId : null, $guard['flow_id'] ?? null, null, $guard['flow_name'] ?? 'Fallback .env');
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
                if ($this->flowAllowsEvent($flow, $event)) {
                    $flows[] = $flow;
                }
            }
            return $flows;
        } catch (Throwable) {
            // Permite deploy antes da migration 010 sem quebrar webhooks existentes.
            return [];
        }
    }

    /**
     * Defesa de contrato para fluxos que possuem efeito colateral forte.
     *
     * Um cadastro legado com events_json="*" nunca pode transformar message.received
     * em compromisso no Google. O fluxo "Agenda Google Calendar por Empresa" cria
     * evento externo e, por isso, só recebe criação real de compromisso.
     */
    private function flowAllowsEvent(array $flow, string $event): bool
    {
        if (!$this->matchesEvent((string) ($flow['events_json'] ?? ''), $event)) {
            return false;
        }

        $identity = mb_strtolower(trim(
            (string) ($flow['flow_key'] ?? '') . ' ' .
            (string) ($flow['template_key'] ?? '') . ' ' .
            (string) ($flow['name'] ?? '')
        ));
        $normalized = strtr($identity, [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a',
            'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c',
        ]);

        $isGoogleAppointmentWriter = str_contains($normalized, 'agenda-google-calendar')
            || str_contains($normalized, 'agenda google calendar por empresa');

        if ($isGoogleAppointmentWriter) {
            return $event === 'calendar.appointment.created';
        }

        return true;
    }

    /**
     * Descobre se uma URL explícita aponta para um fluxo cadastrado de efeito colateral
     * forte. Isso fecha o caminho legado agent.n8n_webhook_url, que antes ignorava
     * events_json/flowAllowsEvent e podia enviar ai.replied/message.received direto
     * para o workflow de criação do Google Calendar.
     *
     * @return array{blocked:bool,flow_id:?int,flow_name:?string,reason:?string}
     */
    private function explicitTargetGuard(int $tenantId, string $target, string $event): array
    {
        $result = ['blocked' => false, 'flow_id' => null, 'flow_name' => null, 'reason' => null];
        if (trim($target) === '') {
            return $result;
        }

        // Proteção independente do cadastro em n8n_tenant_flows. O writer oficial
        // usa /webhook/rsconnect-agenda-cliente e pode existir apenas no campo legado
        // do assistente. Mesmo sem registro no banco, ai.replied/message.received nunca
        // podem chegar a esse endpoint de efeito colateral forte.
        $path = mb_strtolower((string) (parse_url($target, PHP_URL_PATH) ?? ''));
        if (str_contains($path, 'rsconnect-agenda-cliente')) {
            $result['flow_name'] = 'Agenda Google Calendar por Empresa';
            if ($event !== 'calendar.appointment.created') {
                $result['blocked'] = true;
                $result['reason'] = 'Evento ' . $event . ' bloqueado: o endpoint rsconnect-agenda-cliente aceita somente calendar.appointment.created.';
            }
            return $result;
        }

        if ($tenantId < 1) {
            return $result;
        }

        try {
            $statement = Database::connection()->prepare(
                'SELECT id, flow_key, template_key, name, events_json, webhook_url_encrypted
                 FROM n8n_tenant_flows
                 WHERE tenant_id = :tenant_id AND status = "active"'
            );
            $statement->execute(['tenant_id' => $tenantId]);
            $normalizedTarget = $this->normalizeComparableUrl($target);
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $flow) {
                $registered = Crypto::decrypt((string) ($flow['webhook_url_encrypted'] ?? ''));
                if ($registered === '' || $this->normalizeComparableUrl($registered) !== $normalizedTarget) {
                    continue;
                }
                $result['flow_id'] = (int) ($flow['id'] ?? 0) ?: null;
                $result['flow_name'] = (string) ($flow['name'] ?? '');
                if (!$this->flowAllowsEvent($flow, $event)) {
                    $result['blocked'] = true;
                    $result['reason'] = 'Evento ' . $event . ' bloqueado: a URL pertence a um fluxo com contrato restrito.';
                }
                return $result;
            }
        } catch (Throwable) {
            // Em deploys antigos, mantém compatibilidade; o gate do workflow continua sendo a segunda defesa.
        }

        return $result;
    }

    private function normalizeComparableUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return rtrim(mb_strtolower($url), '/');
        }
        $scheme = mb_strtolower((string) ($parts['scheme'] ?? 'https'));
        $host = mb_strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $path = rtrim((string) ($parts['path'] ?? ''), '/');
        return $scheme . '://' . $host . $port . $path;
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
            $this->notifyFailure($tenantId, $flowId, $flowName, $event, 'A integração está com um endereço inválido. Revise a configuração antes de tentar novamente.');
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

            $timeoutSeconds = str_starts_with($event, 'calendar.')
                ? max(20, min(90, (int) Env::get('N8N_CALENDAR_HTTP_TIMEOUT', 45)))
                : max(8, min(60, (int) Env::get('N8N_HTTP_TIMEOUT', 18)));

            curl_setopt_array($curl, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 6,
                CURLOPT_TIMEOUT => $timeoutSeconds,
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
            $this->notifyFailure($tenantId, $flowId, $flowName, $event, $this->friendlyAutomationFailure($message));
            return ['ok' => false, 'error' => $message, 'flow_id' => $flowId];
        }
    }


    private function notifyFailure(?int $tenantId, ?int $flowId, ?string $flowName, string $event, string $message): void
    {
        if ($tenantId === null || $tenantId < 1) {
            return;
        }

        $label = trim((string) $flowName);
        $title = $label !== ''
            ? 'A automação “' . mb_substr($label, 0, 80) . '” precisa de atenção'
            : 'Uma integração precisa de atenção';

        (new NotificationService())->createIfEnabled(
            $tenantId,
            'automation_errors',
            $title,
            $message,
            'warning',
            '/automations',
            'automation_error',
            'automation.failed.' . mb_substr($event, 0, 80),
            $flowId !== null ? 'n8n_flow' : 'automation_event',
            $flowId,
            [
                'flow_name' => $flowName,
                'event' => $event,
            ],
            600
        );
    }

    private function friendlyAutomationFailure(string $error): string
    {
        $normalized = mb_strtolower($error);
        if (str_contains($normalized, '404') || str_contains($normalized, 'not registered')) {
            return 'O fluxo não está disponível. Confirme se ele está ativo e se a URL cadastrada é a de produção.';
        }
        if (str_contains($normalized, '401') || str_contains($normalized, '403') || str_contains($normalized, 'unauthorized')) {
            return 'A integração recusou o acesso. Revise o token ou a credencial configurada.';
        }
        if (str_contains($normalized, 'timeout') || str_contains($normalized, 'timed out')) {
            return 'A integração demorou mais que o esperado para responder. Verifique se o serviço externo está online.';
        }
        if (str_contains($normalized, 'connection refused') || str_contains($normalized, 'could not resolve') || str_contains($normalized, 'failed to connect')) {
            return 'Não foi possível conectar ao serviço externo. Revise a URL e a disponibilidade da integração.';
        }
        return 'Uma automação não conseguiu concluir a tarefa. Abra a área de automações para revisar a configuração.';
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
