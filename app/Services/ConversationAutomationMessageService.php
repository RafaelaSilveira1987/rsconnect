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

            // Deduplica apenas uma segunda tentativa do MESMO processamento. Se houve
            // nova mensagem do cliente depois da última saída igual, precisamos responder
            // novamente; caso contrário a entrada fica sem saída e aparece como pendente
            // na fila da IA mesmo quando a regra foi processada corretamente.
            $recent = $pdo->prepare(
                'SELECT m.id
                 FROM conversation_messages m
                 WHERE m.conversation_id = :conversation_id
                   AND m.direction = "outgoing"
                   AND (m.content = :content OR RIGHT(m.content, CHAR_LENGTH(:content_length)) = :content_suffix)
                   AND m.sent_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 90 SECOND)
                   AND m.id > COALESCE((
                       SELECT MAX(i.id)
                       FROM conversation_messages i
                       WHERE i.conversation_id = m.conversation_id
                         AND i.direction = "incoming"
                   ), 0)
                 ORDER BY m.id DESC
                 LIMIT 1'
            );
            $recent->execute([
                'conversation_id' => $conversationId,
                'content' => $message,
                'content_length' => $message,
                'content_suffix' => $message,
            ]);
            if ($recent->fetchColumn()) {
                return ['ok' => true, 'error' => null, 'external_id' => null];
            }

            $agent = (new AgentRoutingService())->resolveForAutomation($pdo, $instance, $conversationId, '', false);
            if (is_array($agent) && !(new AgentOperatingPolicyService())->allowsConversationalAutomation($agent)) {
                return ['ok' => false, 'error' => 'Mensagem aguardando horário de atendimento.', 'external_id' => null];
            }

            // O aviso de ausência fora do horário é operacional e não deve contar como
            // "primeira resposta conversacional". Caso contrário, quando a conversa é
            // retomada após a abertura, a triagem determinística perde a apresentação
            // configurada do assistente.
            $afterHoursMessage = is_array($agent) ? trim((string) ($agent['after_hours_message'] ?? '')) : '';
            $previousSql = 'SELECT 1 FROM conversation_messages WHERE conversation_id = :conversation_id
                   AND direction = "outgoing" AND status NOT IN ("failed", "cancelled")';
            $previousParams = ['conversation_id' => $conversationId];
            if ($afterHoursMessage !== '') {
                $previousSql .= ' AND TRIM(content) <> :after_hours_message';
                $previousParams['after_hours_message'] = $afterHoursMessage;
            }
            $previous = $pdo->prepare($previousSql . ' LIMIT 1');
            $previous->execute($previousParams);
            $message = FirstAutomatedReplyService::compose($message, is_array($agent) ? $agent : [], (bool) $previous->fetchColumn());

            $blocks = (new AgentConversationBehaviorService())->splitReply($tenantId, $message, $pdo);
            if ($blocks === []) {
                $blocks = [$message];
            }

            $service = new EvolutionService(
                (string) $instance['base_url'],
                Crypto::decrypt((string) $instance['api_key_encrypted']),
                (string) $instance['instance_name'],
                24,
                filter_var(Env::get('EVOLUTION_SSL_VERIFY', true), FILTER_VALIDATE_BOOL) !== false,
                trim((string) Env::get('EVOLUTION_CA_BUNDLE', '')) !== '' ? trim((string) Env::get('EVOLUTION_CA_BUNDLE', '')) : null
            );

            $senderDisplayName = trim((string) ($agent['name'] ?? ''));
            $hasSenderDisplay = $this->hasColumn($pdo, 'conversation_messages', 'sender_display_name');
            $externalId = null;
            $lastSentAt = Clock::nowUtc();
            $lastBlock = '';

            foreach ($blocks as $index => $block) {
                $block = trim((string) $block);
                if ($block === '') {
                    continue;
                }

                $response = $service->sendText($phone, $block);
                $body = is_array($response['body'] ?? null) ? $response['body'] : [];
                $externalId = $this->extractMessageId($body);
                $lastSentAt = Clock::nowUtc();
                $lastBlock = $block;

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
                        'content' => $block,
                        'raw_payload' => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'sent_at' => $lastSentAt,
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
                        'content' => $block,
                        'raw_payload' => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'sent_at' => $lastSentAt,
                    ]);
                }

                $eventMetadata = $metadata;
                if (count($blocks) > 1) {
                    $eventMetadata['delivery_block'] = $index + 1;
                    $eventMetadata['delivery_blocks_total'] = count($blocks);
                }
                $pdo->prepare(
                    'INSERT INTO conversation_events (tenant_id, conversation_id, event_type, description, metadata_json)
                     VALUES (:tenant_id, :conversation_id, :event_type, :description, :metadata_json)'
                )->execute([
                    'tenant_id' => $tenantId,
                    'conversation_id' => $conversationId,
                    'event_type' => mb_substr($eventType, 0, 120),
                    'description' => mb_substr($block, 0, 500),
                    'metadata_json' => $eventMetadata !== [] ? json_encode($eventMetadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                ]);
            }

            if ($lastBlock !== '') {
                $pdo->prepare(
                    'UPDATE conversations SET last_message_at = :sent_at, last_message_preview = :preview,
                         status = IF(status = "closed", "open", status)
                     WHERE id = :id AND tenant_id = :tenant_id'
                )->execute([
                    'sent_at' => $lastSentAt,
                    'preview' => mb_substr($lastBlock, 0, 255),
                    'id' => $conversationId,
                    'tenant_id' => $tenantId,
                ]);
            }

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
