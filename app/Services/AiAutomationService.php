<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Crypto;
use App\Core\Database;
use App\Core\Env;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

final class AiAutomationService
{
    public function __construct(
        private readonly AiModelService $ai = new AiModelService(),
        private readonly AutomationWebhookService $automationWebhook = new AutomationWebhookService(),
    ) {
    }

    public function handleIncoming(array $instance, int $conversationId, string $incomingContent, array $payload): void
    {
        $globalEnabled = filter_var(Env::get('AI_AUTOREPLY_ENABLED', true), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($globalEnabled === false) {
            $this->log((int) $instance['tenant_id'], $conversationId, null, 'ai.skipped', 'skipped', 'AI_AUTOREPLY_ENABLED=false', null, null);
            return;
        }

        try {
            $pdo = Database::connection();
            $conversation = $this->conversation($pdo, $conversationId);
            if (!$conversation || $conversation['attendance_mode'] !== 'ai' || $conversation['status'] === 'closed') {
                $this->log((int) $instance['tenant_id'], $conversationId, null, 'ai.skipped', 'skipped', 'Conversa não está em modo IA.', null, null);
                return;
            }

            $agent = $this->agentFor($pdo, $instance);
            if (!$agent) {
                $tenantId = (int) $instance['tenant_id'];
                $this->log($tenantId, $conversationId, null, 'ai.skipped', 'skipped', 'Nenhum agente ativo com resposta automática.', null, null);
                (new NotificationService())->createIfEnabled(
                    $tenantId,
                    'ai_errors',
                    'Nenhum assistente disponível para responder',
                    'Uma nova mensagem chegou, mas não existe um assistente ativo com respostas automáticas para esta conexão WhatsApp.',
                    'warning',
                    '/agents',
                    'ai_error',
                    'ai.agent_missing',
                    'conversation',
                    $conversationId,
                    ['instance_id' => (int) ($instance['id'] ?? 0)],
                    600
                );
                return;
            }

            if ($this->isInCooldown($pdo, $conversationId, (int) ($agent['cooldown_seconds'] ?? 15))) {
                $this->log((int) $instance['tenant_id'], $conversationId, (int) $agent['id'], 'ai.cooldown', 'skipped', 'Mensagem ignorada por anti-loop/cooldown.', null, null);
                return;
            }

            if ($this->shouldHandoff($incomingContent, (string) ($agent['handoff_keywords'] ?? ''))) {
                $this->handoff($pdo, $instance, $conversation, $agent, $conversationId);
                return;
            }

            if (!$this->isInsideBusinessHours($agent)) {
                $afterHoursMessage = trim((string) ($agent['after_hours_message'] ?? ''));
                if ($afterHoursMessage !== '') {
                    $this->sendAutomatedMessage($pdo, $instance, $conversation, $conversationId, $afterHoursMessage, 'ai.after_hours', 'Mensagem fora do horário enviada pela IA.');
                    $this->log((int) $instance['tenant_id'], $conversationId, (int) $agent['id'], 'ai.after_hours', 'success', null, $afterHoursMessage, null);
                    return;
                }

                $this->log((int) $instance['tenant_id'], $conversationId, (int) $agent['id'], 'ai.after_hours', 'skipped', 'Fora do horário de atendimento e sem mensagem configurada.', null, null);
                return;
            }

            $messages = $this->recentMessages($pdo, $conversationId, (int) ($agent['max_context_messages'] ?? 12));
            $reply = $this->ai->generateReply($agent, $messages, $conversation, $conversation);

            $result = $this->sendAutomatedMessage($pdo, $instance, $conversation, $conversationId, $reply, 'ai.replied', 'Resposta automática enviada pela IA.');

            $this->log((int) $instance['tenant_id'], $conversationId, (int) $agent['id'], 'ai.replied', 'success', null, $reply, [
                'http_status' => $result['status'] ?? null,
                'external_id' => $this->extractMessageId($result['body'] ?? []),
                'provider' => $agent['credential_provider'] ?? $agent['model_provider'] ?? null,
                'credential_id' => $agent['credential_id'] ?? null,
            ]);

            if ((int) ($agent['n8n_enabled'] ?? 0) === 1) {
                $legacyUrl = trim((string) ($agent['n8n_webhook_url'] ?? ''));
                $this->automationWebhook->dispatch('ai.replied', [
                    'tenant_id' => (int) $instance['tenant_id'],
                    'conversation_id' => $conversationId,
                    'agent_id' => (int) $agent['id'],
                    'reply' => $reply,
                    'incoming' => $incomingContent,
                ], $legacyUrl !== '' ? $legacyUrl : null, (int) $instance['tenant_id']);
            }
        } catch (Throwable $exception) {
            if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $tenantId = (int) $instance['tenant_id'];
            $agentId = isset($agent['id']) ? (int) $agent['id'] : null;
            $this->log($tenantId, $conversationId, $agentId, 'ai.failed', 'error', $exception->getMessage(), null, [
                'payload_event' => $payload['event'] ?? null,
            ]);

            (new NotificationService())->createIfEnabled(
                $tenantId,
                'ai_errors',
                'O assistente virtual precisa de atenção',
                $this->friendlyAiFailure($exception->getMessage()),
                'danger',
                '/conversations?conversation_id=' . $conversationId,
                'ai_error',
                'ai.failed',
                'conversation',
                $conversationId,
                [
                    'agent_id' => $agentId,
                    'technical_error' => mb_substr($exception->getMessage(), 0, 700),
                ],
                600
            );
        }
    }

    private function conversation(PDO $pdo, int $conversationId): ?array
    {
        $statement = $pdo->prepare(
            'SELECT c.*, ct.name, ct.phone, ct.email, ct.company, ct.notes, ct.tags_json
             FROM conversations c
             INNER JOIN contacts ct ON ct.id = c.contact_id
             WHERE c.id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $conversationId]);
        $conversation = $statement->fetch(PDO::FETCH_ASSOC);
        return $conversation ?: null;
    }

    private function agentFor(PDO $pdo, array $instance): ?array
    {
        $statement = $pdo->prepare(
            'SELECT a.*,
                    COALESCE(ac_agent.id, ac_tenant.id) AS credential_id,
                    COALESCE(ac_agent.label, ac_tenant.label) AS credential_label,
                    COALESCE(ac_agent.provider, ac_tenant.provider) AS credential_provider,
                    COALESCE(ac_agent.api_key_encrypted, ac_tenant.api_key_encrypted) AS credential_api_key_encrypted,
                    COALESCE(ac_agent.base_url, ac_tenant.base_url) AS credential_base_url,
                    COALESCE(ac_agent.default_model, ac_tenant.default_model) AS credential_default_model
             FROM ai_agents a
             LEFT JOIN ai_provider_credentials ac_agent ON ac_agent.id = (
                SELECT x.id
                FROM ai_provider_credentials x
                WHERE x.agent_id = a.id AND x.status = "active"
                ORDER BY x.is_default DESC, x.id DESC
                LIMIT 1
             )
             LEFT JOIN ai_provider_credentials ac_tenant ON ac_tenant.id = (
                SELECT y.id
                FROM ai_provider_credentials y
                WHERE y.tenant_id = a.tenant_id AND y.agent_id IS NULL AND y.status = "active"
                ORDER BY y.is_default DESC, y.id DESC
                LIMIT 1
             )
             WHERE a.tenant_id = :tenant_id
               AND a.status = "active"
               AND a.auto_reply_enabled = 1
               AND (a.instance_id = :instance_id_filter OR a.instance_id IS NULL OR a.is_default = 1)
             ORDER BY (a.instance_id = :instance_id_order) DESC, a.is_default DESC, a.id DESC
             LIMIT 1'
        );
        $statement->execute([
            'tenant_id' => $instance['tenant_id'],
            'instance_id_filter' => $instance['id'],
            'instance_id_order' => $instance['id'],
        ]);
        $agent = $statement->fetch(PDO::FETCH_ASSOC);
        return $agent ?: null;
    }

    private function recentMessages(PDO $pdo, int $conversationId, int $limit): array
    {
        $limit = max(4, min(30, $limit));
        $statement = $pdo->prepare(
            'SELECT * FROM (
                SELECT direction, sender_type, content, sent_at
                FROM conversation_messages
                WHERE conversation_id = :conversation_id
                ORDER BY sent_at DESC, id DESC
                LIMIT ' . $limit . '
             ) recent
             ORDER BY sent_at ASC'
        );
        $statement->execute(['conversation_id' => $conversationId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function shouldHandoff(string $incomingContent, string $keywords): bool
    {
        $incoming = mb_strtolower($incomingContent);
        foreach (array_filter(array_map('trim', explode(',', $keywords))) as $keyword) {
            if ($keyword !== '' && str_contains($incoming, mb_strtolower($keyword))) {
                return true;
            }
        }
        return false;
    }

    private function handoff(PDO $pdo, array $instance, array $conversation, array $agent, int $conversationId): void
    {
        $mode = (string) ($agent['handoff_action'] ?? 'paused');
        $mode = in_array($mode, ['human', 'paused'], true) ? $mode : 'paused';

        $pdo->prepare('UPDATE conversations SET attendance_mode = :mode, status = "pending" WHERE id = :id')
            ->execute(['mode' => $mode, 'id' => $conversationId]);

        $message = trim((string) ($agent['human_handoff_message'] ?? ''));
        if ($message !== '') {
            $this->sendAutomatedMessage($pdo, $instance, $conversation, $conversationId, $message, 'ai.handoff.message', 'Mensagem de transferência enviada pela IA.');
        }

        $this->insertEvent($pdo, (int) $instance['tenant_id'], $conversationId, 'ai.handoff', 'IA pausada por palavra-chave de transferência.');
        $this->log((int) $instance['tenant_id'], $conversationId, (int) $agent['id'], 'ai.handoff', 'skipped', 'Palavra-chave de transferência detectada.', $message !== '' ? $message : null, null);
    }

    private function isInsideBusinessHours(array $agent): bool
    {
        if ((int) ($agent['business_hours_enabled'] ?? 0) !== 1) {
            return true;
        }

        $timezone = trim((string) ($agent['business_timezone'] ?? Env::get('APP_TIMEZONE', 'America/Sao_Paulo'))) ?: 'America/Sao_Paulo';
        try {
            $now = new DateTimeImmutable('now', new DateTimeZone($timezone));
        } catch (Throwable) {
            $now = new DateTimeImmutable('now');
        }

        $days = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];
        $dayKey = $days[(int) $now->format('w')] ?? 'mon';
        $rules = json_decode((string) ($agent['business_hours_json'] ?? ''), true);
        if (!is_array($rules) || !isset($rules[$dayKey]) || !is_array($rules[$dayKey])) {
            return false;
        }

        $current = $now->format('H:i');
        foreach ($rules[$dayKey] as $range) {
            if (!is_array($range) || count($range) < 2) {
                continue;
            }
            $start = (string) $range[0];
            $end = (string) $range[1];
            if ($start <= $current && $current <= $end) {
                return true;
            }
        }

        return false;
    }

    private function isInCooldown(PDO $pdo, int $conversationId, int $seconds): bool
    {
        $seconds = max(0, min(3600, $seconds));
        if ($seconds === 0) {
            return false;
        }

        $statement = $pdo->prepare(
            'SELECT sent_at
             FROM conversation_messages
             WHERE conversation_id = :conversation_id
               AND direction = "outgoing"
               AND sender_type = "ai"
             ORDER BY sent_at DESC, id DESC
             LIMIT 1'
        );
        $statement->execute(['conversation_id' => $conversationId]);
        $last = $statement->fetchColumn();
        if (!$last) {
            return false;
        }

        return (time() - strtotime((string) $last)) < $seconds;
    }

    private function sendAutomatedMessage(PDO $pdo, array $instance, array $conversation, int $conversationId, string $reply, string $eventType, string $eventDescription): array
    {
        $service = $this->evolutionService($instance);
        $result = $service->sendText((string) $conversation['phone'], $reply);
        $externalId = $this->extractMessageId($result['body'] ?? []);
        $sentAt = date('Y-m-d H:i:s');

        $pdo->beginTransaction();
        $insert = $pdo->prepare(
            'INSERT INTO conversation_messages
                (tenant_id, conversation_id, evolution_message_id, direction, sender_type,
                 message_type, content, status, raw_payload_json, sent_at)
             VALUES
                (:tenant_id, :conversation_id, :external_id, "outgoing", "ai",
                 "text", :content, "sent", :raw_payload, :sent_at)'
        );
        $insert->execute([
            'tenant_id' => $instance['tenant_id'],
            'conversation_id' => $conversationId,
            'external_id' => $externalId,
            'content' => $reply,
            'raw_payload' => json_encode($result['body'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'sent_at' => $sentAt,
        ]);

        $pdo->prepare(
            'UPDATE conversations
             SET last_message_at = :sent_at,
                 last_message_preview = :preview,
                 status = IF(status = "closed", "open", status)
             WHERE id = :id'
        )->execute([
            'sent_at' => $sentAt,
            'preview' => mb_substr($reply, 0, 255),
            'id' => $conversationId,
        ]);

        $this->insertEvent($pdo, (int) $instance['tenant_id'], $conversationId, $eventType, $eventDescription);
        $pdo->commit();

        return $result;
    }

    private function evolutionService(array $instance): EvolutionService
    {
        $verifySsl = filter_var(Env::get('EVOLUTION_SSL_VERIFY', true), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        $caBundle = trim((string) Env::get('EVOLUTION_CA_BUNDLE', ''));

        return new EvolutionService(
            (string) $instance['base_url'],
            Crypto::decrypt((string) $instance['api_key_encrypted']),
            (string) $instance['instance_name'],
            24,
            $verifySsl ?? true,
            $caBundle !== '' ? $caBundle : null
        );
    }

    private function extractMessageId(array $body): ?string
    {
        $id = $body['key']['id'] ?? $body['messageId'] ?? $body['id'] ?? $body['data']['key']['id'] ?? null;
        return is_scalar($id) && trim((string) $id) !== '' ? trim((string) $id) : null;
    }

    private function insertEvent(PDO $pdo, int $tenantId, int $conversationId, string $type, string $description): void
    {
        $pdo->prepare(
            'INSERT INTO conversation_events (tenant_id, conversation_id, user_id, event_type, description)
             VALUES (:tenant_id, :conversation_id, NULL, :event_type, :description)'
        )->execute([
            'tenant_id' => $tenantId,
            'conversation_id' => $conversationId,
            'event_type' => $type,
            'description' => $description,
        ]);
    }


    private function friendlyAiFailure(string $error): string
    {
        $normalized = mb_strtolower($error);

        if (str_contains($normalized, '401') || str_contains($normalized, 'invalid api key') || str_contains($normalized, 'chave')) {
            return 'A chave de acesso da IA parece inválida ou expirou. Revise a credencial do assistente para voltar a responder.';
        }
        if (str_contains($normalized, '403') || str_contains($normalized, 'forbidden') || str_contains($normalized, 'acesso recusado')) {
            return 'O serviço de IA recusou o acesso. Revise a credencial e a URL configuradas para este assistente.';
        }
        if (str_contains($normalized, '429') || str_contains($normalized, 'quota') || str_contains($normalized, 'saldo') || str_contains($normalized, 'limit')) {
            return 'O limite de uso ou o saldo da IA pode ter sido atingido. Verifique a conta do provedor antes de tentar novamente.';
        }
        if (str_contains($normalized, 'timeout') || str_contains($normalized, 'timed out') || str_contains($normalized, 'tempo limite')) {
            return 'O serviço de IA demorou mais que o esperado para responder. Tente novamente e confira a conexão do provedor.';
        }
        if (str_contains($normalized, 'modelo') || str_contains($normalized, 'model')) {
            return 'O modelo escolhido pode não estar disponível para esta credencial. Revise o modelo configurado no assistente.';
        }

        return 'O assistente não conseguiu responder uma conversa. Abra os detalhes da conversa e revise a configuração da IA.';
    }

    private function log(int $tenantId, int $conversationId, ?int $agentId, string $event, string $status, ?string $error, ?string $responsePreview, ?array $raw): void
    {
        try {
            Database::connection()->prepare(
                'INSERT INTO ai_automation_logs
                    (tenant_id, conversation_id, agent_id, event, status, response_preview, error_message, raw_json)
                 VALUES
                    (:tenant_id, :conversation_id, :agent_id, :event, :status, :response_preview, :error_message, :raw_json)'
            )->execute([
                'tenant_id' => $tenantId,
                'conversation_id' => $conversationId,
                'agent_id' => $agentId,
                'event' => $event,
                'status' => $status,
                'response_preview' => $responsePreview !== null ? mb_substr($responsePreview, 0, 500) : null,
                'error_message' => $error !== null ? mb_substr($error, 0, 500) : null,
                'raw_json' => $raw !== null ? json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            ]);
        } catch (Throwable) {
            // Não interrompe webhook por falha de log.
        }
    }
}
