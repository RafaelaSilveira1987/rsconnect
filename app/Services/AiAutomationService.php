<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Crypto;
use App\Core\Database;
use App\Core\Env;
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
                $this->log((int) $instance['tenant_id'], $conversationId, null, 'ai.skipped', 'skipped', 'Nenhum agente ativo com resposta automática.', null, null);
                return;
            }

            if ($this->shouldHandoff($incomingContent, (string) ($agent['handoff_keywords'] ?? ''))) {
                $pdo->prepare('UPDATE conversations SET attendance_mode = "paused", status = "pending" WHERE id = :id')
                    ->execute(['id' => $conversationId]);
                $this->insertEvent($pdo, (int) $instance['tenant_id'], $conversationId, 'ai.handoff', 'IA pausada por palavra-chave de transferência.');
                $this->log((int) $instance['tenant_id'], $conversationId, (int) $agent['id'], 'ai.handoff', 'skipped', 'Palavra-chave de transferência detectada.', null, null);
                return;
            }

            $messages = $this->recentMessages($pdo, $conversationId, (int) ($agent['max_context_messages'] ?? 12));
            $reply = $this->ai->generateReply($agent, $messages, $conversation, $conversation);

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

            $this->insertEvent($pdo, (int) $instance['tenant_id'], $conversationId, 'ai.replied', 'Resposta automática enviada pela IA.');
            $pdo->commit();

            $this->log((int) $instance['tenant_id'], $conversationId, (int) $agent['id'], 'ai.replied', 'success', null, $reply, [
                'http_status' => $result['status'] ?? null,
                'external_id' => $externalId,
            ]);

            if ((int) ($agent['n8n_enabled'] ?? 0) === 1) {
                $this->automationWebhook->dispatch('ai.replied', [
                    'tenant_id' => (int) $instance['tenant_id'],
                    'conversation_id' => $conversationId,
                    'agent_id' => (int) $agent['id'],
                    'reply' => $reply,
                    'incoming' => $incomingContent,
                ], (string) ($agent['n8n_webhook_url'] ?? ''));
            }
        } catch (Throwable $exception) {
            if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->log((int) $instance['tenant_id'], $conversationId, isset($agent['id']) ? (int) $agent['id'] : null, 'ai.failed', 'error', $exception->getMessage(), null, [
                'payload_event' => $payload['event'] ?? null,
            ]);
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
            'SELECT *
             FROM ai_agents
             WHERE tenant_id = :tenant_id
               AND status = "active"
               AND auto_reply_enabled = 1
               AND (instance_id = :instance_id OR instance_id IS NULL OR is_default = 1)
             ORDER BY (instance_id = :instance_id) DESC, is_default DESC, id DESC
             LIMIT 1'
        );
        $statement->execute([
            'tenant_id' => $instance['tenant_id'],
            'instance_id' => $instance['id'],
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
