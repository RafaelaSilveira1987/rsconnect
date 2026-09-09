<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Crypto;
use App\Core\Env;
use PDO;
use Throwable;

final class ConversationAutomationMessageService
{
    /** @return array{ok:bool,error:?string,external_id:?string} */
    public function send(PDO $pdo, array $instance, int $conversationId, int $contactId, string $message, string $eventType = 'agent.policy.message', array $metadata = []): array
    {
        $tenantId = (int) ($instance['tenant_id'] ?? 0);
        $message = trim($message);
        if ($tenantId < 1 || $conversationId < 1 || $contactId < 1 || $message === '') {
            return ['ok' => false, 'error' => 'Dados insuficientes para enviar a mensagem automática.', 'external_id' => null];
        }
        try {
            $conversation = $pdo->prepare('SELECT attendance_mode, status FROM conversations WHERE id = :id AND tenant_id = :tenant_id LIMIT 1');
            $conversation->execute(['id' => $conversationId, 'tenant_id' => $tenantId]);
            $conversationRow = $conversation->fetch(PDO::FETCH_ASSOC) ?: [];
            if (($conversationRow['attendance_mode'] ?? 'ai') !== 'ai' || in_array((string) ($conversationRow['status'] ?? ''), ['closed', 'archived'], true)) {
                return ['ok' => false, 'error' => 'Conversa sob controle humano ou encerrada.', 'external_id' => null];
            }

            $contact = $pdo->prepare('SELECT phone FROM contacts WHERE id = :id AND tenant_id = :tenant_id LIMIT 1');
            $contact->execute(['id' => $contactId, 'tenant_id' => $tenantId]);
            $phone = preg_replace('/\D+/', '', (string) ($contact->fetchColumn() ?: '')) ?: '';
            if ($phone === '') {
                return ['ok' => false, 'error' => 'Contato sem telefone válido.', 'external_id' => null];
            }

            $recent = $pdo->prepare(
                'SELECT 1 FROM conversation_messages
                 WHERE conversation_id = :conversation_id AND direction = "outgoing" AND content = :content
                   AND sent_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 90 SECOND)
                 LIMIT 1'
            );
            $recent->execute(['conversation_id' => $conversationId, 'content' => $message]);
            if ($recent->fetchColumn()) {
                return ['ok' => true, 'error' => null, 'external_id' => null];
            }

            $agent = (new AgentRoutingService())->resolveForAutomation($pdo, $instance, $conversationId, '', false);
            if (is_array($agent) && !(new AgentOperatingPolicyService())->allowsConversationalAutomation($agent)) {
                return ['ok' => false, 'error' => 'Mensagem aguardando horário de atendimento.', 'external_id' => null];
            }

            $service = new EvolutionService(
                (string) $instance['base_url'],
                Crypto::decrypt((string) $instance['api_key_encrypted']),
                (string) $instance['instance_name'],
                24,
                filter_var(Env::get('EVOLUTION_SSL_VERIFY', true), FILTER_VALIDATE_BOOL) !== false,
                trim((string) Env::get('EVOLUTION_CA_BUNDLE', '')) !== '' ? trim((string) Env::get('EVOLUTION_CA_BUNDLE', '')) : null
            );
            $response = $service->sendText($phone, $message);
            $body = is_array($response['body'] ?? null) ? $response['body'] : [];
            $externalId = $this->extractMessageId($body);
            $sentAt = Clock::nowUtc();

            $senderDisplayName = trim((string) ($agent['name'] ?? ''));
            $hasSenderDisplay = $this->hasColumn($pdo, 'conversation_messages', 'sender_display_name');
            if ($hasSenderDisplay) {
                $pdo->prepare(
                    'INSERT INTO conversation_messages
                        (tenant_id, conversation_id, evolution_message_id, direction, sender_type, sender_display_name, message_type, content, status, raw_payload_json, sent_at)
                     VALUES
                        (:tenant_id, :conversation_id, :external_id, "outgoing", "ai", :sender_display_name, "text", :content, "sent", :raw_payload, :sent_at)'
                )->execute([
                    'tenant_id' => $tenantId,
                    'conversation_id' => $conversationId,
                    'external_id' => $externalId,
                    'sender_display_name' => $senderDisplayName !== '' ? $senderDisplayName : null,
                    'content' => $message,
                    'raw_payload' => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'sent_at' => $sentAt,
                ]);
            } else {
                $pdo->prepare(
                    'INSERT INTO conversation_messages
                        (tenant_id, conversation_id, evolution_message_id, direction, sender_type, message_type, content, status, raw_payload_json, sent_at)
                     VALUES
                        (:tenant_id, :conversation_id, :external_id, "outgoing", "ai", "text", :content, "sent", :raw_payload, :sent_at)'
                )->execute([
                    'tenant_id' => $tenantId,
                    'conversation_id' => $conversationId,
                    'external_id' => $externalId,
                    'content' => $message,
                    'raw_payload' => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'sent_at' => $sentAt,
                ]);
            }

            $pdo->prepare(
                'UPDATE conversations SET last_message_at = :sent_at, last_message_preview = :preview,
                     status = IF(status = "closed", "open", status)
                 WHERE id = :id AND tenant_id = :tenant_id'
            )->execute([
                'sent_at' => $sentAt,
                'preview' => mb_substr($message, 0, 255),
                'id' => $conversationId,
                'tenant_id' => $tenantId,
            ]);

            $pdo->prepare(
                'INSERT INTO conversation_events (tenant_id, conversation_id, event_type, description, metadata_json)
                 VALUES (:tenant_id, :conversation_id, :event_type, :description, :metadata_json)'
            )->execute([
                'tenant_id' => $tenantId,
                'conversation_id' => $conversationId,
                'event_type' => mb_substr($eventType, 0, 120),
                'description' => mb_substr($message, 0, 500),
                'metadata_json' => $metadata !== [] ? json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            ]);

            return ['ok' => true, 'error' => null, 'external_id' => $externalId];
        } catch (Throwable $exception) {
            return ['ok' => false, 'error' => $exception->getMessage(), 'external_id' => null];
        }
    }

    private function extractMessageId(array $payload): ?string
    {
        foreach ([
            $payload['key']['id'] ?? null,
            $payload['id'] ?? null,
            $payload['messageId'] ?? null,
            $payload['data']['key']['id'] ?? null,
        ] as $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                return mb_substr($value, 0, 190);
            }
        }
        return null;
    }

    private function hasColumn(PDO $pdo, string $table, string $column): bool
    {
        try {
            $stmt = $pdo->prepare(
                'SELECT 1 FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column LIMIT 1'
            );
            $stmt->execute(['table' => $table, 'column' => $column]);
            return (bool) $stmt->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }
}
