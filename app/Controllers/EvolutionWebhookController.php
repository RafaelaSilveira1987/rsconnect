<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Env;
use App\Services\AccessControlService;
use App\Services\AiAutomationService;
use App\Services\AutomationWebhookService;
use App\Services\CrmAutoService;
use App\Services\CalendarConversationService;
use App\Services\ConversationFlowService;
use App\Services\NotificationService;
use App\Services\PreSchedulingService;
use PDO;
use Throwable;

final class EvolutionWebhookController
{
    public function handle(): void
    {
        header('Content-Type: application/json; charset=UTF-8');

        $pdo = null;
        $payload = [];
        $event = '';
        $instance = [];
        $storedMessageId = 0;
        $conversationId = 0;
        $externalId = null;

        try {
            $this->validateToken();
            $raw = file_get_contents('php://input') ?: '';
            $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($payload)) {
                throw new \RuntimeException('Payload inválido.');
            }

            $event = $this->normalizeEvent((string) ($payload['event'] ?? ''));

            // SEND_MESSAGE é um eco gerado pela própria Evolution após um envio pela API.
            // Ele não representa uma nova mensagem recebida do contato e nunca deve acionar IA,
            // agenda ou CRM. A mensagem de saída já foi persistida pelo serviço que a enviou.
            if (str_contains($event, 'send.message')) {
                $this->respond(202, [
                    'ok' => true,
                    'ignored' => 'outgoing_send_message_event',
                ]);
            }

            $instance = $this->resolveInstance($payload);

            if (str_contains($event, 'messages.update')) {
                try {
                    $updated = $this->applyStatusUpdate($instance, $payload);
                    $this->respond(200, ['ok' => true, 'updated' => $updated]);
                } catch (Throwable $exception) {
                    $this->logWebhookFailure($exception, [
                        'phase' => 'messages.update',
                        'event' => $event,
                        'instance_id' => (int) ($instance['id'] ?? 0),
                    ]);
                    // Atualizações de entrega não podem fazer a Evolution repetir o evento indefinidamente.
                    $this->respond(200, [
                        'ok' => true,
                        'updated' => false,
                        'accepted_with_warning' => true,
                    ]);
                }
            }

            if ($event !== '' && !str_contains($event, 'messages.upsert')) {
                $this->respond(202, ['ok' => true, 'ignored' => $event]);
            }

            $data = $payload['data'] ?? $payload;
            if (isset($data[0]) && is_array($data[0])) {
                $data = $data[0];
            }
            if (!is_array($data)) {
                throw new \RuntimeException('Dados da mensagem não encontrados.');
            }

            $key = is_array($data['key'] ?? null) ? $data['key'] : [];
            $remoteJid = trim((string) ($key['remoteJid'] ?? $data['remoteJid'] ?? ''));
            if ($remoteJid === '') {
                throw new \RuntimeException('remoteJid não informado.');
            }

            if ($this->isIgnoredRemoteJid($remoteJid)) {
                $this->respond(202, ['ok' => true, 'ignored' => 'non_contact_jid']);
            }

            $fromMe = filter_var($key['fromMe'] ?? $data['fromMe'] ?? false, FILTER_VALIDATE_BOOL);
            $reaction = $this->reactionDetails($data);
            if ($reaction !== null) {
                if ($fromMe) {
                    $this->respond(202, ['ok' => true, 'ignored' => 'outgoing_reaction']);
                }
                if (($reaction['removed'] ?? false) === true) {
                    $this->respond(202, ['ok' => true, 'ignored' => 'reaction_removed']);
                }

                $reactionPdo = Database::connection();
                if (!$this->replyToReactionsEnabled($reactionPdo, $instance)) {
                    $this->respond(202, [
                        'ok' => true,
                        'ignored' => 'reaction',
                        'reason' => 'reply_to_reactions_disabled',
                    ]);
                }
            }

            $externalId = trim((string) ($key['id'] ?? $data['id'] ?? '')) ?: null;
            $pushName = trim((string) ($data['pushName'] ?? $data['senderName'] ?? ''));
            $phone = preg_replace('/\D+/', '', strstr($remoteJid, '@', true) ?: $remoteJid) ?: '';
            if ($phone === '') {
                // Eventos de status, canais e broadcasts não devem derrubar o webhook.
                $this->respond(202, ['ok' => true, 'ignored' => 'jid_without_phone']);
            }

            [$messageType, $content] = $this->extractContent($data);
            $isReaction = $messageType === 'reaction';
            $sentAt = $this->extractDate($data);
            $direction = $fromMe ? 'outgoing' : 'incoming';
            $senderType = $fromMe ? 'system' : 'contact';
            $status = $fromMe ? 'sent' : 'received';

            $pdo = Database::connection();
            if ($externalId !== null) {
                $duplicate = $pdo->prepare(
                    'SELECT conversation_id FROM conversation_messages
                     WHERE tenant_id = :tenant_id AND evolution_message_id = :external_id
                     LIMIT 1'
                );
                $duplicate->execute([
                    'tenant_id' => $instance['tenant_id'],
                    'external_id' => $externalId,
                ]);
                $existingConversationId = $duplicate->fetchColumn();
                if ($existingConversationId !== false) {
                    $this->respond(200, [
                        'ok' => true,
                        'duplicate' => true,
                        'conversation_id' => (int) $existingConversationId,
                    ]);
                }
            }

            // A mensagem é persistida antes de CRM, agenda, n8n ou IA.
            // Assim, qualquer falha posterior continua recuperável pela fila.
            $pdo->beginTransaction();
            $contactId = $this->upsertContact($pdo, $instance, $remoteJid, $phone, $pushName);
            $conversationId = $this->upsertConversation(
                $pdo,
                $instance,
                $contactId,
                $remoteJid,
                $content,
                $sentAt,
                !$fromMe
            );
            $storedMessageId = $this->insertMessage(
                $pdo,
                (int) $instance['tenant_id'],
                $conversationId,
                $externalId,
                $direction,
                $senderType,
                $messageType,
                $content,
                $status,
                $payload,
                $sentAt
            );
            $pdo->commit();
            $inserted = $storedMessageId > 0;

            $tenantAccess = ['allowed' => true, 'code' => null];
            $automationAllowed = true;
            try {
                $accessService = new AccessControlService();
                $tenantAccess = $accessService->statusForTenant((int) $instance['tenant_id']);
                $automationAllowed = !empty($tenantAccess['allowed']);
                if (!$automationAllowed) {
                    $accessService->recordBlockedAccess($tenantAccess, 'evolution_webhook');
                }
            } catch (Throwable $exception) {
                $automationAllowed = false;
                $tenantAccess = ['allowed' => false, 'code' => 'access_check_failed'];
                $this->logWebhookFailure($exception, [
                    'phase' => 'access_check',
                    'event' => $event,
                    'instance_id' => (int) ($instance['id'] ?? 0),
                    'conversation_id' => $conversationId,
                    'stored_message_id' => $storedMessageId,
                ]);
            }

            $leadId = null;
            $flowContext = [];
            $preScheduleResult = ['skip_ai' => false, 'handled' => false];
            $processingWarnings = [];

            if (!$fromMe && $inserted && $automationAllowed && !$isReaction) {
                try {
                    $flowContext = (new ConversationFlowService())->ingestIncoming(
                        $pdo,
                        $instance,
                        $contactId,
                        $conversationId,
                        $content
                    );
                } catch (Throwable $exception) {
                    $processingWarnings[] = 'conversation_flow';
                    $this->logWebhookFailure($exception, [
                        'phase' => 'conversation_flow',
                        'event' => $event,
                        'instance_id' => (int) ($instance['id'] ?? 0),
                        'conversation_id' => $conversationId,
                        'stored_message_id' => $storedMessageId,
                    ]);
                }

                try {
                    $leadId = (new CrmAutoService())->createFromConversation(
                        $pdo,
                        $instance,
                        $contactId,
                        $conversationId,
                        $content
                    );
                } catch (Throwable $exception) {
                    $processingWarnings[] = 'crm';
                    $this->logWebhookFailure($exception, [
                        'phase' => 'crm',
                        'event' => $event,
                        'instance_id' => (int) ($instance['id'] ?? 0),
                        'conversation_id' => $conversationId,
                        'stored_message_id' => $storedMessageId,
                    ]);
                }

                try {
                    $calendarSelection = (new CalendarConversationService())->handleIncomingSelection(
                        $pdo,
                        $instance,
                        $contactId,
                        $conversationId,
                        $content,
                        $storedMessageId
                    );
                    $preScheduleResult = !empty($calendarSelection['handled'])
                        ? $calendarSelection
                        : (new PreSchedulingService())->handleIncoming(
                            $pdo,
                            $instance,
                            $contactId,
                            $conversationId,
                            $content,
                            $flowContext
                        );
                } catch (Throwable $exception) {
                    $processingWarnings[] = 'pre_schedule';
                    $this->logWebhookFailure($exception, [
                        'phase' => 'pre_schedule',
                        'event' => $event,
                        'instance_id' => (int) ($instance['id'] ?? 0),
                        'conversation_id' => $conversationId,
                        'stored_message_id' => $storedMessageId,
                    ]);
                }
            } elseif (!$fromMe && $inserted && !$automationAllowed) {
                $preScheduleResult = [
                    'skip_ai' => true,
                    'access_blocked' => true,
                    'reason' => $tenantAccess['code'] ?? 'blocked',
                ];
            }

            $aiHandled = false;
            if (!$fromMe && $inserted) {
                $senderName = $pushName !== '' ? $pushName : $phone;
                $preview = trim($content);
                if ($preview === '') {
                    $preview = match ($messageType) {
                        'image' => '[Imagem recebida]',
                        'audio' => '[Áudio recebido]',
                        'video' => '[Vídeo recebido]',
                        'document' => '[Documento recebido]',
                        default => '[Nova mensagem]',
                    };
                }

                try {
                    (new NotificationService())->createIfEnabled(
                        (int) $instance['tenant_id'],
                        'messages',
                        'Nova mensagem recebida',
                        mb_substr($senderName . ': ' . $preview, 0, 500),
                        'info',
                        '/conversations?conversation_id=' . $conversationId,
                        'message',
                        'message.received',
                        'conversation',
                        $conversationId,
                        [
                            'instance_id' => (int) $instance['id'],
                            'phone' => $phone,
                            'message_type' => $messageType,
                            'external_id' => $externalId,
                        ]
                    );
                } catch (Throwable $exception) {
                    $processingWarnings[] = 'notification';
                    $this->logWebhookFailure($exception, [
                        'phase' => 'notification',
                        'conversation_id' => $conversationId,
                        'stored_message_id' => $storedMessageId,
                    ]);
                }

                // A resposta da conversa tem prioridade sobre integrações externas.
                // n8n e Google Agenda podem levar vários segundos; antes do HOTFIX 36.1.3,
                // essa espera podia encerrar o request antes de a IA ser chamada.
                if ($automationAllowed && !((bool) ($preScheduleResult['skip_ai'] ?? false))) {
                    $aiPayload = $payload;
                    $aiPayload['stored_message_id'] = $storedMessageId;
                    (new AiAutomationService())->handleIncoming($instance, $conversationId, $content, $aiPayload);
                    $aiHandled = true;
                }

                if ($automationAllowed && !$isReaction) {
                    $appointmentEventPayload = $preScheduleResult['appointment_event_payload'] ?? null;
                    if (is_array($appointmentEventPayload) && $appointmentEventPayload !== []) {
                        try {
                            (new AutomationWebhookService())->dispatch(
                                'appointment.pre_scheduled',
                                $appointmentEventPayload,
                                null,
                                (int) $instance['tenant_id']
                            );
                        } catch (Throwable $exception) {
                            $processingWarnings[] = 'appointment_n8n';
                            $this->logWebhookFailure($exception, [
                                'phase' => 'appointment_n8n_after_reply',
                                'conversation_id' => $conversationId,
                                'stored_message_id' => $storedMessageId,
                            ]);
                        }
                    }

                    if (!empty($preScheduleResult['availability_request_needed'])
                        && (int) ($preScheduleResult['appointment_id'] ?? 0) > 0) {
                        try {
                            $availabilityRequest = (new PreSchedulingService())->requestAvailabilityIfNeeded(
                                (int) $instance['tenant_id'],
                                (int) $preScheduleResult['appointment_id']
                            );
                            if (empty($availabilityRequest['ok']) && empty($availabilityRequest['skipped'])) {
                                $processingWarnings[] = 'calendar_availability_request_failed';
                                $this->logWebhookFailure(new \RuntimeException((string) ($availabilityRequest['message'] ?? 'Falha ao enviar a consulta de disponibilidade ao n8n.')), [
                                    'phase' => 'calendar_availability_result_after_reply',
                                    'conversation_id' => $conversationId,
                                    'stored_message_id' => $storedMessageId,
                                    'appointment_id' => (int) ($preScheduleResult['appointment_id'] ?? 0),
                                ]);
                            }
                        } catch (Throwable $exception) {
                            $processingWarnings[] = 'calendar_availability';
                            $this->logWebhookFailure($exception, [
                                'phase' => 'calendar_availability_after_reply',
                                'conversation_id' => $conversationId,
                                'stored_message_id' => $storedMessageId,
                                'appointment_id' => (int) ($preScheduleResult['appointment_id'] ?? 0),
                            ]);
                        }
                    }

                    // Uma resposta consumida pela agenda (ex.: "1", "o primeiro", "14h")
                    // termina aqui. Não encaminha o mesmo comando para fluxos genéricos do n8n,
                    // evitando que ele volte como uma nova tentativa de IA.
                    if (empty($preScheduleResult['terminal_handled'])) {
                        try {
                            (new AutomationWebhookService())->dispatch('message.received', [
                                'tenant_id' => (int) $instance['tenant_id'],
                                'instance_id' => (int) $instance['id'],
                                'conversation_id' => $conversationId,
                                'incoming_message_id' => $storedMessageId,
                                'phone' => $phone,
                                'message_type' => $messageType,
                                'content' => $content,
                            ], null, (int) $instance['tenant_id']);
                        } catch (Throwable $exception) {
                            $processingWarnings[] = 'n8n';
                            $this->logWebhookFailure($exception, [
                                'phase' => 'n8n_after_reply',
                                'conversation_id' => $conversationId,
                                'stored_message_id' => $storedMessageId,
                            ]);
                        }
                    }
                }
            }

            $this->respond(200, [
                'ok' => true,
                'conversation_id' => $conversationId,
                'message_inserted' => $inserted,
                'stored_message_id' => $storedMessageId,
                'crm_lead_id' => $leadId,
                'ai_checked' => $aiHandled,
                'pre_schedule' => $preScheduleResult,
                'conversation_flow' => $flowContext,
                'processing_warnings' => array_values(array_unique($processingWarnings)),
                'access_allowed' => $automationAllowed,
                'access_reason' => $automationAllowed ? null : ($tenantAccess['code'] ?? 'blocked'),
            ]);
        } catch (Throwable $exception) {
            if ($pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $this->logWebhookFailure($exception, [
                'phase' => $storedMessageId > 0 ? 'after_message_saved' : 'before_message_saved',
                'event' => $event,
                'instance_id' => (int) ($instance['id'] ?? 0),
                'tenant_id' => (int) ($instance['tenant_id'] ?? 0),
                'conversation_id' => $conversationId,
                'stored_message_id' => $storedMessageId,
                'external_id' => $externalId,
            ]);

            if ($storedMessageId > 0 && $conversationId > 0 && $instance !== []) {
                $this->recordStoredMessageFailure($instance, $conversationId, $storedMessageId, $exception, $payload);
                // A mensagem já está salva. Retornar 200 evita duplicação da entrada pela Evolution.
                $this->respond(200, [
                    'ok' => true,
                    'accepted_with_error' => true,
                    'conversation_id' => $conversationId,
                    'stored_message_id' => $storedMessageId,
                ]);
            }

            $status = $exception->getCode() >= 400 && $exception->getCode() <= 499
                ? (int) $exception->getCode()
                : 500;
            $this->respond($status, [
                'ok' => false,
                'error' => $exception->getMessage(),
                'retryable' => $status >= 500,
            ]);
        }
    }

    private function isIgnoredRemoteJid(string $remoteJid): bool
    {
        $jid = mb_strtolower(trim($remoteJid));
        if ($jid === '') {
            return true;
        }

        foreach (['@g.us', 'status@broadcast', '@broadcast', '@newsletter', 'newsletter'] as $pattern) {
            if (str_contains($jid, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function recordStoredMessageFailure(
        array $instance,
        int $conversationId,
        int $storedMessageId,
        Throwable $exception,
        array $payload
    ): void {
        try {
            $pdo = Database::connection();
            $agentStatement = $pdo->prepare(
                'SELECT id
                 FROM ai_agents
                 WHERE tenant_id = :tenant_id
                   AND status = "active"
                   AND auto_reply_enabled = 1
                   AND (
                        instance_id = :instance_id_filter
                        OR instance_id IS NULL
                        OR is_default = 1
                   )
                 ORDER BY (instance_id = :instance_id_order) DESC, is_default DESC, id DESC
                 LIMIT 1'
            );
            $agentStatement->execute([
                'tenant_id' => (int) ($instance['tenant_id'] ?? 0),
                'instance_id_filter' => (int) ($instance['id'] ?? 0),
                'instance_id_order' => (int) ($instance['id'] ?? 0),
            ]);
            $agentId = (int) ($agentStatement->fetchColumn() ?: 0);
            $error = mb_substr('Falha após salvar a mensagem recebida: ' . $exception->getMessage(), 0, 500);
            $rawJson = json_encode([
                'payload_event' => $payload['event'] ?? null,
                'exception' => get_class($exception),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            try {
                $already = $pdo->prepare(
                    'SELECT 1 FROM ai_automation_logs
                     WHERE incoming_message_id = :message_id AND event = "ai.failed"
                     LIMIT 1'
                );
                $already->execute(['message_id' => $storedMessageId]);
                if ($already->fetchColumn()) {
                    return;
                }

                $pdo->prepare(
                    'INSERT INTO ai_automation_logs
                        (tenant_id, conversation_id, agent_id, incoming_message_id, event, status,
                         response_preview, error_message, raw_json)
                     VALUES
                        (:tenant_id, :conversation_id, :agent_id, :incoming_message_id,
                         "ai.failed", "error", NULL, :error_message, :raw_json)'
                )->execute([
                    'tenant_id' => (int) ($instance['tenant_id'] ?? 0),
                    'conversation_id' => $conversationId,
                    'agent_id' => $agentId > 0 ? $agentId : null,
                    'incoming_message_id' => $storedMessageId,
                    'error_message' => $error,
                    'raw_json' => $rawJson,
                ]);
                return;
            } catch (Throwable) {
                // Compatibilidade quando a migration 044 ainda não foi aplicada.
            }

            $pdo->prepare(
                'INSERT INTO ai_automation_logs
                    (tenant_id, conversation_id, agent_id, event, status,
                     response_preview, error_message, raw_json)
                 VALUES
                    (:tenant_id, :conversation_id, :agent_id,
                     "ai.failed", "error", NULL, :error_message, :raw_json)'
            )->execute([
                'tenant_id' => (int) ($instance['tenant_id'] ?? 0),
                'conversation_id' => $conversationId,
                'agent_id' => $agentId > 0 ? $agentId : null,
                'error_message' => $error,
                'raw_json' => $rawJson,
            ]);
        } catch (Throwable $logException) {
            $this->logWebhookFailure($logException, [
                'phase' => 'record_saved_message_failure',
                'conversation_id' => $conversationId,
                'stored_message_id' => $storedMessageId,
            ]);
        }
    }

    /** @param array<string,mixed> $context */
    private function logWebhookFailure(Throwable $exception, array $context = []): void
    {
        try {
            $logDir = dirname(__DIR__, 2) . '/storage/logs';
            if (!is_dir($logDir)) {
                mkdir($logDir, 0775, true);
            }

            $record = [
                'at' => date(DATE_ATOM),
                'message' => $exception->getMessage(),
                'exception' => get_class($exception),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'context' => $context,
            ];
            error_log(
                json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
                3,
                $logDir . '/evolution-webhook.log'
            );
        } catch (Throwable) {
            // O diagnóstico nunca pode interromper o webhook.
        }
    }

    private function normalizeEvent(string $event): string
    {
        $event = mb_strtolower(trim($event));
        $event = str_replace(['_', '-'], '.', $event);
        while (str_contains($event, '..')) {
            $event = str_replace('..', '.', $event);
        }
        return $event;
    }

    private function validateToken(): void
    {
        $expected = trim((string) Env::get('EVOLUTION_WEBHOOK_TOKEN', ''));
        if ($expected === '') {
            return;
        }

        $authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        $bearer = str_starts_with($authorization, 'Bearer ') ? substr($authorization, 7) : '';
        $received = (string) (
            $_GET['token']
            ?? $_SERVER['HTTP_X_WEBHOOK_TOKEN']
            ?? $bearer
        );

        if ($received === '' || !hash_equals($expected, $received)) {
            throw new \RuntimeException('Webhook não autorizado.', 401);
        }
    }

    private function resolveInstance(array $payload): array
    {
        $pdo = Database::connection();
        $instanceId = (int) ($_GET['instance_id'] ?? 0);

        if ($instanceId > 0) {
            $statement = $pdo->prepare('SELECT * FROM evolution_instances WHERE id = :id LIMIT 1');
            $statement->execute(['id' => $instanceId]);
            $instance = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$instance) {
                throw new \RuntimeException('Instância não encontrada.', 404);
            }
            return $instance;
        }

        $instanceName = trim((string) (
            $payload['instance']
            ?? $payload['data']['instance']
            ?? $payload['instanceName']
            ?? ''
        ));
        if ($instanceName === '') {
            throw new \RuntimeException('Informe instance_id na URL do webhook ou envie o nome da instância no payload.');
        }

        $statement = $pdo->prepare('SELECT * FROM evolution_instances WHERE instance_name = :name LIMIT 2');
        $statement->execute(['name' => $instanceName]);
        $matches = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (count($matches) !== 1) {
            throw new \RuntimeException('A instância não foi encontrada de forma única. Use instance_id na URL do webhook.');
        }
        return $matches[0];
    }

    private function upsertContact(PDO $pdo, array $instance, string $remoteJid, string $phone, string $pushName): int
    {
        $statement = $pdo->prepare(
            'INSERT INTO contacts
                (tenant_id, evolution_instance_id, remote_jid, phone, name)
             VALUES
                (:tenant_id, :instance_id, :remote_jid, :phone, :name)
             ON DUPLICATE KEY UPDATE
                id = LAST_INSERT_ID(id),
                evolution_instance_id = VALUES(evolution_instance_id),
                remote_jid = VALUES(remote_jid),
                name = IF(VALUES(name) IS NULL OR VALUES(name) = "", name, VALUES(name))'
        );
        $statement->execute([
            'tenant_id' => $instance['tenant_id'],
            'instance_id' => $instance['id'],
            'remote_jid' => $remoteJid,
            'phone' => $phone,
            'name' => $pushName !== '' ? $pushName : null,
        ]);
        return (int) $pdo->lastInsertId();
    }

    private function upsertConversation(
        PDO $pdo,
        array $instance,
        int $contactId,
        string $remoteJid,
        string $content,
        string $sentAt,
        bool $incrementUnread
    ): int {
        $statement = $pdo->prepare(
            'INSERT INTO conversations
                (tenant_id, evolution_instance_id, contact_id, remote_jid, status,
                 attendance_mode, unread_count, last_message_at, last_message_preview)
             VALUES
                (:tenant_id, :instance_id, :contact_id, :remote_jid, "open",
                 "ai", :unread_count, :last_message_at, :preview)
             ON DUPLICATE KEY UPDATE
                id = LAST_INSERT_ID(id),
                contact_id = VALUES(contact_id),
                last_message_at = VALUES(last_message_at),
                last_message_preview = VALUES(last_message_preview),
                unread_count = unread_count + VALUES(unread_count),
                status = IF(status = "closed", "open", status)'
        );
        $statement->execute([
            'tenant_id' => $instance['tenant_id'],
            'instance_id' => $instance['id'],
            'contact_id' => $contactId,
            'remote_jid' => $remoteJid,
            'unread_count' => $incrementUnread ? 1 : 0,
            'last_message_at' => $sentAt,
            'preview' => mb_substr($content, 0, 255),
        ]);
        return (int) $pdo->lastInsertId();
    }

    private function insertMessage(
        PDO $pdo,
        int $tenantId,
        int $conversationId,
        ?string $externalId,
        string $direction,
        string $senderType,
        string $messageType,
        string $content,
        string $status,
        array $payload,
        string $sentAt
    ): int {
        if ($externalId !== null) {
            $exists = $pdo->prepare(
                'SELECT id FROM conversation_messages
                 WHERE tenant_id = :tenant_id AND evolution_message_id = :external_id
                 LIMIT 1'
            );
            $exists->execute(['tenant_id' => $tenantId, 'external_id' => $externalId]);
            if ($exists->fetchColumn()) {
                return 0;
            }
        }

        $statement = $pdo->prepare(
            'INSERT INTO conversation_messages
                (tenant_id, conversation_id, evolution_message_id, direction, sender_type,
                 message_type, content, status, raw_payload_json, sent_at)
             VALUES
                (:tenant_id, :conversation_id, :external_id, :direction, :sender_type,
                 :message_type, :content, :status, :raw_payload, :sent_at)'
        );
        $statement->execute([
            'tenant_id' => $tenantId,
            'conversation_id' => $conversationId,
            'external_id' => $externalId,
            'direction' => $direction,
            'sender_type' => $senderType,
            'message_type' => $messageType,
            'content' => $content,
            'status' => $status,
            'raw_payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'sent_at' => $sentAt,
        ]);
        return (int) $pdo->lastInsertId();
    }

    private function applyStatusUpdate(array $instance, array $payload): bool
    {
        $data = $payload['data'] ?? [];
        if (isset($data[0]) && is_array($data[0])) {
            $data = $data[0];
        }
        if (!is_array($data)) {
            return false;
        }

        $externalId = trim((string) ($data['key']['id'] ?? $data['id'] ?? ''));
        if ($externalId === '') {
            return false;
        }

        $rawStatus = mb_strtolower((string) ($data['status'] ?? $data['update']['status'] ?? ''));
        $status = match (true) {
            str_contains($rawStatus, 'read'), str_contains($rawStatus, 'played') => 'read',
            str_contains($rawStatus, 'delivery'), str_contains($rawStatus, 'delivered') => 'delivered',
            str_contains($rawStatus, 'error'), str_contains($rawStatus, 'failed') => 'failed',
            default => 'sent',
        };

        $pdo = Database::connection();
        $updated = false;
        $usedPendingFallback = false;

        try {
            $statement = $pdo->prepare(
                'UPDATE conversation_messages
                 SET status = :status
                 WHERE tenant_id = :tenant_id AND evolution_message_id = :external_id'
            );
            $statement->execute([
                'status' => $status,
                'tenant_id' => $instance['tenant_id'],
                'external_id' => $externalId,
            ]);
            $updated = $statement->rowCount() > 0;
        } catch (Throwable $exception) {
            if ($status !== 'failed') {
                throw $exception;
            }

            // Bancos antigos podem não possuir "failed" no ENUM. "pending" mantém a saída
            // fora do conjunto de mensagens entregues e permite que a fila tente novamente.
            $fallback = $pdo->prepare(
                'UPDATE conversation_messages
                 SET status = "pending",
                     error_message = :error_message
                 WHERE tenant_id = :tenant_id AND evolution_message_id = :external_id'
            );
            $fallback->execute([
                'error_message' => mb_substr('Falha de entrega informada pela Evolution: ' . $rawStatus, 0, 500),
                'tenant_id' => $instance['tenant_id'],
                'external_id' => $externalId,
            ]);
            $updated = $fallback->rowCount() > 0;
            $usedPendingFallback = true;
            $this->logWebhookFailure($exception, [
                'phase' => 'delivery_status_failed_enum_fallback',
                'instance_id' => (int) ($instance['id'] ?? 0),
                'external_id' => $externalId,
            ]);
        }

        if ($status === 'failed') {
            $this->recordAiDeliveryFailure($instance, $externalId, $payload, $rawStatus);
        }

        return $updated || $usedPendingFallback;
    }

    private function recordAiDeliveryFailure(array $instance, string $externalId, array $payload, string $rawStatus): void
    {
        try {
            $pdo = Database::connection();
            $outgoingStatement = $pdo->prepare(
                'SELECT cm.id, cm.conversation_id, cm.sent_at, c.evolution_instance_id
                 FROM conversation_messages cm
                 INNER JOIN conversations c
                    ON c.id = cm.conversation_id
                   AND c.tenant_id = cm.tenant_id
                 WHERE cm.tenant_id = :tenant_id
                   AND cm.evolution_message_id = :external_id
                   AND cm.direction = "outgoing"
                   AND cm.sender_type = "ai"
                 LIMIT 1'
            );
            $outgoingStatement->execute([
                'tenant_id' => (int) $instance['tenant_id'],
                'external_id' => $externalId,
            ]);
            $outgoing = $outgoingStatement->fetch(PDO::FETCH_ASSOC);
            if (!$outgoing) {
                return;
            }

            $incomingStatement = $pdo->prepare(
                'SELECT id
                 FROM conversation_messages
                 WHERE conversation_id = :conversation_id
                   AND direction = "incoming"
                   AND (
                        sent_at < :sent_at_before
                        OR (sent_at = :sent_at_equal AND id < :outgoing_id)
                   )
                 ORDER BY sent_at DESC, id DESC
                 LIMIT 1'
            );
            $incomingStatement->execute([
                'conversation_id' => (int) $outgoing['conversation_id'],
                'sent_at_before' => (string) $outgoing['sent_at'],
                'sent_at_equal' => (string) $outgoing['sent_at'],
                'outgoing_id' => (int) $outgoing['id'],
            ]);
            $incomingMessageId = (int) ($incomingStatement->fetchColumn() ?: 0);
            if ($incomingMessageId < 1) {
                return;
            }

            $agentStatement = $pdo->prepare(
                'SELECT a.id
                 FROM ai_agents a
                 WHERE a.tenant_id = :tenant_id
                   AND a.status = "active"
                   AND a.auto_reply_enabled = 1
                   AND (
                        a.instance_id = :instance_id_filter
                        OR a.instance_id IS NULL
                        OR a.is_default = 1
                   )
                 ORDER BY (a.instance_id = :instance_id_order) DESC,
                          a.is_default DESC,
                          a.id DESC
                 LIMIT 1'
            );
            $agentStatement->execute([
                'tenant_id' => (int) $instance['tenant_id'],
                'instance_id_filter' => (int) $outgoing['evolution_instance_id'],
                'instance_id_order' => (int) $outgoing['evolution_instance_id'],
            ]);
            $agentId = (int) ($agentStatement->fetchColumn() ?: 0);

            $alreadyStatement = $pdo->prepare(
                'SELECT 1
                 FROM ai_automation_logs
                 WHERE incoming_message_id = :incoming_message_id
                   AND event = "ai.failed"
                   AND error_message LIKE "Falha de entrega pela Evolution%"
                 LIMIT 1'
            );
            $alreadyStatement->execute(['incoming_message_id' => $incomingMessageId]);
            if ($alreadyStatement->fetchColumn()) {
                return;
            }

            $failureData = is_array($payload['data'] ?? null) ? $payload['data'] : [];
            if (isset($failureData[0]) && is_array($failureData[0])) {
                $failureData = $failureData[0];
            }
            $detailValue = $failureData['message']
                ?? $failureData['error']
                ?? $payload['message']
                ?? $payload['error']
                ?? $rawStatus;
            if (is_array($detailValue)) {
                $detailValue = json_encode($detailValue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $detail = is_scalar($detailValue) ? trim((string) $detailValue) : '';
            $error = 'Falha de entrega pela Evolution.';
            if ($detail !== '') {
                $error .= ' Retorno: ' . mb_substr($detail, 0, 350);
            }

            $pdo->prepare(
                'INSERT INTO ai_automation_logs
                    (tenant_id, conversation_id, agent_id, incoming_message_id, event, status,
                     response_preview, error_message, raw_json)
                 VALUES
                    (:tenant_id, :conversation_id, :agent_id, :incoming_message_id,
                     "ai.failed", "error", NULL, :error_message, :raw_json)'
            )->execute([
                'tenant_id' => (int) $instance['tenant_id'],
                'conversation_id' => (int) $outgoing['conversation_id'],
                'agent_id' => $agentId > 0 ? $agentId : null,
                'incoming_message_id' => $incomingMessageId,
                'error_message' => mb_substr($error, 0, 500),
                'raw_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        } catch (Throwable) {
            // A atualização de status não pode falhar por causa do registro auxiliar da fila.
        }
    }

    /**
     * Identifica reações do WhatsApp. Uma reação vazia representa remoção da reação.
     */
    private function reactionDetails(array $data): ?array
    {
        $message = is_array($data['message'] ?? null) ? $data['message'] : [];
        $reaction = is_array($message['reactionMessage'] ?? null) ? $message['reactionMessage'] : null;
        $type = mb_strtolower(trim((string) ($data['messageType'] ?? '')));

        if ($reaction === null && !str_contains($type, 'reaction')) {
            return null;
        }

        $reaction ??= [];
        $text = trim((string) ($reaction['text'] ?? $data['reaction'] ?? ''));
        $targetKey = is_array($reaction['key'] ?? null) ? $reaction['key'] : [];
        $targetId = trim((string) ($targetKey['id'] ?? $reaction['messageId'] ?? ''));

        return [
            'text' => $text,
            'target_id' => $targetId,
            'removed' => $text === '',
        ];
    }

    private function replyToReactionsEnabled(PDO $pdo, array $instance): bool
    {
        try {
            $statement = $pdo->prepare(
                'SELECT reply_to_reactions
                 FROM ai_agents
                 WHERE tenant_id = :tenant_id
                   AND status = "active"
                   AND auto_reply_enabled = 1
                   AND (instance_id = :instance_id_filter OR instance_id IS NULL OR is_default = 1)
                 ORDER BY (instance_id = :instance_id_order) DESC, is_default DESC, id DESC
                 LIMIT 1'
            );
            $statement->execute([
                'tenant_id' => $instance['tenant_id'],
                'instance_id_filter' => $instance['id'],
                'instance_id_order' => $instance['id'],
            ]);
            return (int) ($statement->fetchColumn() ?: 0) === 1;
        } catch (Throwable) {
            // Antes da migration 038, reações permanecem ignoradas por segurança.
            return false;
        }
    }

    private function extractContent(array $data): array
    {
        $message = is_array($data['message'] ?? null) ? $data['message'] : [];
        $type = (string) ($data['messageType'] ?? '');

        $reaction = $this->reactionDetails($data);
        if ($reaction !== null) {
            $emoji = trim((string) ($reaction['text'] ?? ''));
            $targetId = trim((string) ($reaction['target_id'] ?? ''));
            $content = 'O contato reagiu com ' . ($emoji !== '' ? '“' . $emoji . '”' : 'uma reação') . ' a uma mensagem.';
            if ($targetId !== '') {
                $content .= ' Mensagem relacionada: ' . $targetId . '.';
            }
            return ['reaction', $content];
        }

        $candidates = [
            'conversation' => $message['conversation'] ?? null,
            'extendedText' => $message['extendedTextMessage']['text'] ?? null,
            'image' => $message['imageMessage']['caption'] ?? null,
            'video' => $message['videoMessage']['caption'] ?? null,
            'document' => $message['documentMessage']['fileName'] ?? null,
            'buttons' => $message['buttonsResponseMessage']['selectedDisplayText'] ?? null,
            'list' => $message['listResponseMessage']['title'] ?? null,
        ];

        foreach ($candidates as $detectedType => $value) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                return [$detectedType === 'conversation' || $detectedType === 'extendedText' ? 'text' : $detectedType, trim((string) $value)];
            }
        }

        $fallback = match (true) {
            str_contains(mb_strtolower($type), 'image') => ['image', '[Imagem]'],
            str_contains(mb_strtolower($type), 'audio') => ['audio', '[Áudio]'],
            str_contains(mb_strtolower($type), 'video') => ['video', '[Vídeo]'],
            str_contains(mb_strtolower($type), 'document') => ['document', '[Documento]'],
            str_contains(mb_strtolower($type), 'sticker') => ['sticker', '[Figurinha]'],
            default => ['unknown', '[Mensagem não textual]'],
        };
        return $fallback;
    }

    private function extractDate(array $data): string
    {
        $timestamp = $data['messageTimestamp'] ?? $data['timestamp'] ?? null;
        if (is_array($timestamp)) {
            $timestamp = $timestamp['low'] ?? null;
        }
        if (is_numeric($timestamp)) {
            $value = (int) $timestamp;
            if ($value > 20000000000) {
                $value = (int) floor($value / 1000);
            }
            return date('Y-m-d H:i:s', $value);
        }
        return date('Y-m-d H:i:s');
    }

    private function respond(int $status, array $body): never
    {
        http_response_code($status);
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
