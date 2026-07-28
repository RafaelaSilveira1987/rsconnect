<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Crypto;
use App\Core\Database;
use App\Core\Env;
use App\Services\AccessControlService;
use App\Services\AiAutomationService;
use App\Services\AiReplyTimingService;
use App\Services\AgentRoutingService;
use App\Services\AgentOperatingPolicyService;
use App\Services\AutomationWebhookService;
use App\Services\CrmAutoService;
use App\Services\CalendarConversationService;
use App\Services\ConversationFlowService;
use App\Services\EvolutionService;
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
            try {
                Database::connection()->prepare('UPDATE evolution_instances SET last_webhook_at = NOW() WHERE id = :id')
                    ->execute(['id' => (int) $instance['id']]);
            } catch (Throwable) {
                // Compatibilidade antes da migration 063.
            }

            if (str_contains($event, 'qrcode.updated') || str_contains($event, 'qrcode.update')) {
                $result = $this->applyQrCodeUpdate($instance, $payload, $event);
                $this->respond(200, ['ok' => true, 'event' => $event, 'connection' => $result]);
            }

            if (str_contains($event, 'connection.update')) {
                $result = $this->applyConnectionUpdate($instance, $payload, $event);
                $this->respond(200, ['ok' => true, 'event' => $event, 'connection' => $result]);
            }

            if (str_contains($event, 'contacts.upsert')) {
                $updated = $this->applyContactsUpsert($instance, $payload);
                $this->respond(200, ['ok' => true, 'updated_contacts' => $updated]);
            }

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
            $remoteJid = $this->preferredRemoteJid(
                trim((string) ($key['remoteJid'] ?? $data['remoteJid'] ?? '')),
                trim((string) ($key['remoteJidAlt'] ?? $data['remoteJidAlt'] ?? ''))
            );
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
            $contactId = $this->upsertContact(
                $pdo,
                $instance,
                $remoteJid,
                $phone,
                $fromMe ? '' : $pushName
            );
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

            if (!$fromMe && $contactId > 0) {
                $this->refreshContactAvatarIfMissing($pdo, $instance, $contactId, $phone);
            }

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
            $resolvedAgent = null;
            $operatingPolicy = ['enforced' => false, 'inside' => true, 'reason' => 'agent_not_resolved'];
            $outsideBusinessHours = false;
            $replyWaitRemaining = 0;
            $waitingReplyWindow = false;

            if (!$fromMe && $inserted && $automationAllowed && !$isReaction) {
                try {
                    // 36.6.15: o agente é resolvido antes de qualquer automação conversacional.
                    // A política de horário passa a ser fonte única e prevalece sobre prompt, agenda e n8n.
                    $resolvedAgent = (new AgentRoutingService())->resolveForAutomation($pdo, $instance, $conversationId, $content, true);
                    if (is_array($resolvedAgent)) {
                        $operatingPolicy = (new AgentOperatingPolicyService())->status($resolvedAgent);
                        $outsideBusinessHours = !empty($operatingPolicy['enforced']) && empty($operatingPolicy['inside']);
                        if (!$outsideBusinessHours) {
                            $replyWaitRemaining = (new AiReplyTimingService())->remainingForConversation(
                                $pdo,
                                $conversationId,
                                (int) ($resolvedAgent['cooldown_seconds'] ?? 15)
                            );
                            $waitingReplyWindow = $replyWaitRemaining > 0;
                        }
                    }
                } catch (Throwable $exception) {
                    $processingWarnings[] = 'agent_routing';
                    $this->logWebhookFailure($exception, [
                        'phase' => 'agent_routing',
                        'event' => $event,
                        'instance_id' => (int) ($instance['id'] ?? 0),
                        'conversation_id' => $conversationId,
                        'stored_message_id' => $storedMessageId,
                    ]);
                }

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
                    if ($outsideBusinessHours) {
                        // A IA principal ainda será chamada abaixo para registrar a pendência e,
                        // quando configurado, enviar a mensagem diária de ausência. Agenda/seleção não atua.
                        $preScheduleResult = [
                            'skip_ai' => false,
                            'handled' => false,
                            'outside_business_hours' => true,
                            'operating_policy' => $operatingPolicy,
                        ];
                    } elseif ($waitingReplyWindow) {
                        // A mensagem já está persistida. Agenda e respostas fixas esperam o mesmo
                        // tempo configurado da IA; a Fila rápida reexecuta a camada de agenda depois.
                        $preScheduleResult = [
                            'skip_ai' => false,
                            'handled' => false,
                            'reply_wait_deferred' => true,
                            'reply_wait_remaining' => $replyWaitRemaining,
                        ];
                    } else {
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
                                $flowContext,
                                $storedMessageId
                            );
                    }
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
                    if (empty($preScheduleResult['terminal_handled']) && !$outsideBusinessHours) {
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
                'operating_policy' => $operatingPolicy,
                'outside_business_hours' => $outsideBusinessHours,
                'reply_wait_remaining' => $replyWaitRemaining,
                'waiting_reply_window' => $waitingReplyWindow,
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
            $resolvedAgent = (new AgentRoutingService())->resolve(
                $pdo,
                $instance,
                $conversationId,
                '',
                true
            );
            $agentId = (int) ($resolvedAgent['id'] ?? 0);
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

    /** @return array<string,mixed> */
    private function applyQrCodeUpdate(array $instance, array $payload, string $event): array
    {
        $data = $payload['data'] ?? $payload;
        if (isset($data[0]) && is_array($data[0])) {
            $data = $data[0];
        }
        $data = is_array($data) ? $data : [];
        $qr = trim((string) ($data['qrcode']['base64'] ?? $data['base64'] ?? $data['qrcode'] ?? $data['qrCode'] ?? ''));
        if ($qr !== '' && !str_starts_with($qr, 'data:image/')) {
            $qr = '';
        }

        $pdo = Database::connection();
        $pdo->prepare(
            'UPDATE evolution_instances
             SET status = IF(status = "connected", status, "pending"),
                 connection_state = IF(status = "connected", connection_state, "qrcode"),
                 connection_reason = NULL,
                 qrcode_base64 = :qrcode,
                 qrcode_updated_at = NOW(),
                 qrcode_expires_at = DATE_ADD(NOW(), INTERVAL 2 MINUTE),
                 connection_updated_at = NOW(),
                 last_webhook_at = NOW()
             WHERE id = :id'
        )->execute(['qrcode' => $qr !== '' ? $qr : null, 'id' => (int) $instance['id']]);

        $this->recordConnectionEvent($pdo, $instance, $event, 'qrcode', null, null, null, $payload);
        return ['state' => 'qrcode', 'status' => 'pending', 'qr_ready' => $qr !== ''];
    }

    /** @return array<string,mixed> */
    private function applyConnectionUpdate(array $instance, array $payload, string $event): array
    {
        $data = $payload['data'] ?? $payload;
        if (isset($data[0]) && is_array($data[0])) {
            $data = $data[0];
        }
        $data = is_array($data) ? $data : [];
        $instanceData = is_array($data['instance'] ?? null) ? $data['instance'] : [];
        $state = mb_strtolower(trim((string) (
            $data['state'] ?? $data['status'] ?? $data['connection'] ??
            $instanceData['state'] ?? $instanceData['status'] ?? 'unknown'
        )));
        $state = str_replace([' ', '-'], '_', $state);
        $reasonValue = $data['reason'] ?? $data['statusReason'] ?? $data['message'] ?? $data['error'] ?? '';
        if (is_array($reasonValue)) {
            $reasonValue = json_encode($reasonValue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $reason = trim((string) $reasonValue);
        $profileName = trim((string) ($data['profileName'] ?? $data['name'] ?? $instanceData['profileName'] ?? $instanceData['name'] ?? ''));
        $profilePhone = preg_replace('/\D+/', '', (string) ($data['ownerJid'] ?? $data['number'] ?? $instanceData['ownerJid'] ?? $instanceData['number'] ?? '')) ?: '';
        $profilePicture = trim((string) ($data['profilePictureUrl'] ?? $data['profilePicUrl'] ?? $instanceData['profilePictureUrl'] ?? ''));
        if ($profilePicture !== '' && !preg_match('#^https?://#i', $profilePicture)) {
            $profilePicture = '';
        }

        $connectedStates = ['open', 'connected', 'online', 'active'];
        $pendingStates = ['connecting', 'qrcode', 'qr', 'pending', 'created'];
        $status = in_array($state, $connectedStates, true)
            ? 'connected'
            : (in_array($state, $pendingStates, true) ? 'pending' : 'disconnected');

        $pdo = Database::connection();
        $pdo->prepare(
            'UPDATE evolution_instances
             SET status = :status,
                 connection_state = :state,
                 connection_reason = :reason,
                 connection_updated_at = NOW(),
                 last_status_check_at = NOW(),
                 last_webhook_at = NOW(),
                 profile_name = COALESCE(NULLIF(:profile_name, ""), profile_name),
                 profile_phone = COALESCE(NULLIF(:profile_phone, ""), profile_phone),
                 profile_picture_url = COALESCE(NULLIF(:profile_picture, ""), profile_picture_url),
                 qrcode_base64 = IF(:status_connected = 1, NULL, qrcode_base64),
                 qrcode_expires_at = IF(:status_connected = 1, NULL, qrcode_expires_at)
             WHERE id = :id'
        )->execute([
            'status' => $status,
            'state' => $state !== '' ? $state : 'unknown',
            'reason' => $reason !== '' ? mb_substr($reason, 0, 255) : null,
            'profile_name' => mb_substr($profileName, 0, 150),
            'profile_phone' => mb_substr($profilePhone, 0, 40),
            'profile_picture' => mb_substr($profilePicture, 0, 500),
            'status_connected' => $status === 'connected' ? 1 : 0,
            'id' => (int) $instance['id'],
        ]);

        $this->recordConnectionEvent($pdo, $instance, $event, $state, $reason, $profileName, $profilePhone, $payload);
        return ['state' => $state, 'status' => $status, 'reason' => $reason, 'profile_name' => $profileName, 'profile_phone' => $profilePhone];
    }

    /** @param array<string,mixed> $payload */
    private function recordConnectionEvent(PDO $pdo, array $instance, string $event, string $state, ?string $reason, ?string $profileName, ?string $profilePhone, array $payload): void
    {
        try {
            $pdo->prepare(
                'INSERT INTO evolution_connection_events
                    (tenant_id, evolution_instance_id, event_name, connection_state, connection_reason,
                     profile_name, profile_phone, metadata_json, occurred_at)
                 VALUES
                    (:tenant_id, :instance_id, :event_name, :connection_state, :connection_reason,
                     :profile_name, :profile_phone, :metadata_json, NOW())'
            )->execute([
                'tenant_id' => (int) $instance['tenant_id'],
                'instance_id' => (int) $instance['id'],
                'event_name' => mb_substr($event, 0, 80),
                'connection_state' => mb_substr($state, 0, 60),
                'connection_reason' => $reason !== null && $reason !== '' ? mb_substr($reason, 0, 255) : null,
                'profile_name' => $profileName !== null && $profileName !== '' ? mb_substr($profileName, 0, 150) : null,
                'profile_phone' => $profilePhone !== null && $profilePhone !== '' ? mb_substr($profilePhone, 0, 40) : null,
                'metadata_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        } catch (Throwable) {
            // O status principal já foi atualizado; o histórico é auxiliar.
        }
    }

    private function applyContactsUpsert(array $instance, array $payload): int
    {
        $rows = $payload['data'] ?? [];
        if (!is_array($rows)) {
            return 0;
        }
        if (!isset($rows[0]) || !is_array($rows[0])) {
            $rows = [$rows];
        }

        $pdo = Database::connection();
        $updated = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $remoteJid = trim((string) ($row['remoteJid'] ?? $row['id'] ?? ''));
            if ($remoteJid === '' || $this->isIgnoredRemoteJid($remoteJid) || str_contains($remoteJid, '@g.us')) {
                continue;
            }
            $phone = preg_replace('/\D+/', '', strstr($remoteJid, '@', true) ?: $remoteJid) ?: '';
            if ($phone === '') {
                continue;
            }
            $pushName = trim((string) ($row['pushName'] ?? $row['name'] ?? ''));
            $contactId = $this->upsertContact($pdo, $instance, $remoteJid, $phone, $pushName);
            if ($contactId < 1) {
                continue;
            }

            $hasAvatarField = array_key_exists('profilePicUrl', $row) || array_key_exists('profilePictureUrl', $row);
            if ($hasAvatarField) {
                $avatar = trim((string) ($row['profilePicUrl'] ?? $row['profilePictureUrl'] ?? ''));
                if ($avatar !== '' && !preg_match('#^https?://#i', $avatar)) {
                    $avatar = '';
                }
                $pdo->prepare('UPDATE contacts SET avatar_url = :avatar_url WHERE id = :id AND tenant_id = :tenant_id')
                    ->execute([
                        'avatar_url' => mb_substr($avatar, 0, 500),
                        'id' => $contactId,
                        'tenant_id' => (int) $instance['tenant_id'],
                    ]);
            }
            $updated++;
        }

        return $updated;
    }

    private function refreshContactAvatarIfMissing(PDO $pdo, array $instance, int $contactId, string $phone): void
    {
        try {
            $statement = $pdo->prepare('SELECT avatar_url FROM contacts WHERE id = :id AND tenant_id = :tenant_id LIMIT 1');
            $statement->execute(['id' => $contactId, 'tenant_id' => (int) $instance['tenant_id']]);
            $current = $statement->fetchColumn();
            // NULL = nunca consultado. String vazia = consultado e sem foto disponível.
            if ($current !== null) {
                return;
            }

            $url = $this->evolutionServiceForInstance($instance)->fetchProfilePictureUrl($phone);
            $pdo->prepare('UPDATE contacts SET avatar_url = :avatar_url WHERE id = :id AND tenant_id = :tenant_id')
                ->execute([
                    'avatar_url' => $url ?? '',
                    'id' => $contactId,
                    'tenant_id' => (int) $instance['tenant_id'],
                ]);
        } catch (Throwable) {
            // Avatar é enriquecimento visual e nunca deve interromper o webhook principal.
        }
    }

    private function evolutionServiceForInstance(array $instance): EvolutionService
    {
        $verifySsl = filter_var(Env::get('EVOLUTION_SSL_VERIFY', true), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        $caBundle = trim((string) Env::get('EVOLUTION_CA_BUNDLE', ''));

        return new EvolutionService(
            (string) ($instance['base_url'] ?? ''),
            Crypto::decrypt((string) ($instance['api_key_encrypted'] ?? '')),
            (string) ($instance['instance_name'] ?? ''),
            12,
            $verifySsl ?? true,
            $caBundle !== '' ? $caBundle : null
        );
    }

    private function upsertContact(PDO $pdo, array $instance, string $remoteJid, string $phone, string $pushName): int
    {
        $tenantId = (int) ($instance['tenant_id'] ?? 0);
        $identityReady = $this->contactIdentityColumnsAvailable($pdo);

        if ($identityReady) {
            $statement = $pdo->prepare(
                'INSERT INTO contacts
                    (tenant_id, evolution_instance_id, remote_jid, phone, name, name_source,
                     whatsapp_name_candidate, whatsapp_name_seen_count)
                 VALUES
                    (:tenant_id, :instance_id, :remote_jid, :phone, NULL, "unknown", NULL, 0)
                 ON DUPLICATE KEY UPDATE
                    id = LAST_INSERT_ID(id),
                    evolution_instance_id = VALUES(evolution_instance_id),
                    remote_jid = VALUES(remote_jid)'
            );
        } else {
            // Compatibilidade segura antes da migration 059: nunca confia em um único pushName.
            // A interface já usa o telefone como fallback quando contacts.name é nulo.
            $statement = $pdo->prepare(
                'INSERT INTO contacts
                    (tenant_id, evolution_instance_id, remote_jid, phone, name)
                 VALUES
                    (:tenant_id, :instance_id, :remote_jid, :phone, NULL)
                 ON DUPLICATE KEY UPDATE
                    id = LAST_INSERT_ID(id),
                    evolution_instance_id = VALUES(evolution_instance_id),
                    remote_jid = VALUES(remote_jid)'
            );
        }
        $statement->execute([
            'tenant_id' => $tenantId,
            'instance_id' => $instance['id'],
            'remote_jid' => $remoteJid,
            'phone' => $phone,
        ]);
        $contactId = (int) $pdo->lastInsertId();

        if ($identityReady && $contactId > 0) {
            $this->observeWhatsappContactName($pdo, $instance, $contactId, $phone, $pushName);
        }
        return $contactId;
    }

    private function contactIdentityColumnsAvailable(PDO $pdo): bool
    {
        static $ready = null;
        if (is_bool($ready)) {
            return $ready;
        }
        try {
            $statement = $pdo->query(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'contacts'
                   AND COLUMN_NAME IN ('name_source','whatsapp_name_candidate','whatsapp_name_seen_count')"
            );
            $ready = (int) $statement->fetchColumn() === 3;
        } catch (Throwable) {
            $ready = false;
        }
        return $ready;
    }

    private function observeWhatsappContactName(PDO $pdo, array $instance, int $contactId, string $phone, string $pushName): void
    {
        $tenantId = (int) ($instance['tenant_id'] ?? 0);
        $candidate = trim(preg_replace('/\\s+/u', ' ', $pushName) ?? $pushName);
        if ($tenantId < 1 || $contactId < 1 || $candidate === '' || $this->automaticContactNameIsSuspicious($pdo, $instance, $phone, $candidate)) {
            return;
        }
        $candidate = mb_substr($candidate, 0, 150);

        $currentStmt = $pdo->prepare(
            'SELECT name, name_source, whatsapp_name_candidate, whatsapp_name_seen_count
             FROM contacts WHERE id = :id AND tenant_id = :tenant_id LIMIT 1'
        );
        $currentStmt->execute(['id' => $contactId, 'tenant_id' => $tenantId]);
        $current = $currentStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $source = trim((string) ($current['name_source'] ?? 'legacy'));
        $currentName = trim((string) ($current['name'] ?? ''));

        // Nome digitado pela equipe/cliente nunca é substituído pelo WhatsApp.
        if (in_array($source, ['manual', 'legacy'], true) && $currentName !== '') {
            return;
        }

        // Um mesmo pushName observado para números diferentes é tratado como dado contaminado
        // (caso clássico: nome do proprietário da conta conectado vindo no lugar do remetente).
        $collisionStmt = $pdo->prepare(
            'SELECT id FROM contacts
             WHERE tenant_id = :tenant_id
               AND id <> :contact_id
               AND phone <> :phone
               AND (whatsapp_name_candidate = :candidate OR (name_source = "whatsapp" AND name = :candidate))
             LIMIT 1'
        );
        $collisionStmt->execute([
            'tenant_id' => $tenantId,
            'contact_id' => $contactId,
            'phone' => $phone,
            'candidate' => $candidate,
        ]);
        if ($collisionStmt->fetchColumn()) {
            $pdo->prepare(
                'UPDATE contacts
                 SET name = NULL, name_source = "unknown", whatsapp_name_seen_count = 0
                 WHERE tenant_id = :tenant_id AND name_source = "whatsapp" AND name = :candidate'
            )->execute(['tenant_id' => $tenantId, 'candidate' => $candidate]);
            $pdo->prepare(
                'UPDATE contacts
                 SET whatsapp_name_candidate = :candidate, whatsapp_name_seen_count = 0
                 WHERE id = :id AND tenant_id = :tenant_id'
            )->execute(['candidate' => $candidate, 'id' => $contactId, 'tenant_id' => $tenantId]);
            return;
        }

        $previousCandidate = trim((string) ($current['whatsapp_name_candidate'] ?? ''));
        $seen = (int) ($current['whatsapp_name_seen_count'] ?? 0);
        $seen = $previousCandidate === $candidate ? $seen + 1 : 1;

        if ($source === 'whatsapp' && $currentName !== '' && $currentName !== $candidate) {
            $currentName = '';
            $source = 'unknown';
        }

        // Só promove depois de duas observações consistentes do mesmo número.
        // Até lá, a lista de conversas exibe o telefone.
        $promote = $seen >= 2;
        $pdo->prepare(
            'UPDATE contacts
             SET whatsapp_name_candidate = :candidate,
                 whatsapp_name_seen_count = :seen,
                 name = CASE WHEN :promote = 1 THEN :candidate_name WHEN name_source = "whatsapp" THEN NULL ELSE name END,
                 name_source = CASE WHEN :promote_source = 1 THEN "whatsapp" WHEN name_source = "whatsapp" THEN "unknown" ELSE name_source END
             WHERE id = :id AND tenant_id = :tenant_id'
        )->execute([
            'candidate' => $candidate,
            'seen' => $seen,
            'promote' => $promote ? 1 : 0,
            'candidate_name' => $candidate,
            'promote_source' => $promote ? 1 : 0,
            'id' => $contactId,
            'tenant_id' => $tenantId,
        ]);
    }

    private function automaticContactNameIsSuspicious(PDO $pdo, array $instance, string $phone, string $name): bool
    {
        $tenantId = (int) ($instance['tenant_id'] ?? 0);
        $normalized = mb_strtolower(trim($name));
        $digits = preg_replace('/\\D+/', '', $name) ?: '';
        if ($normalized === '' || ($digits !== '' && $digits === (preg_replace('/\\D+/', '', $phone) ?: ''))
            || in_array($normalized, ['unknown', 'desconhecido', 'sem nome', 'whatsapp'], true)) {
            return true;
        }
        try {
            $internal = $pdo->prepare(
                'SELECT 1 FROM users WHERE tenant_id = :tenant_id AND status = "active" AND LOWER(TRIM(name)) = :name
                 UNION ALL
                 SELECT 1 FROM tenants WHERE id = :tenant_id_2 AND (LOWER(TRIM(name)) = :name_2 OR LOWER(TRIM(COALESCE(legal_name, ""))) = :name_3)
                 LIMIT 1'
            );
            $internal->execute([
                'tenant_id' => $tenantId,
                'name' => $normalized,
                'tenant_id_2' => $tenantId,
                'name_2' => $normalized,
                'name_3' => $normalized,
            ]);
            if ($internal->fetchColumn()) {
                return true;
            }
            foreach ([(string) ($instance['name'] ?? ''), (string) ($instance['instance_name'] ?? '')] as $instanceName) {
                if ($instanceName !== '' && mb_strtolower(trim($instanceName)) === $normalized) {
                    return true;
                }
            }
        } catch (Throwable) {
            return true;
        }
        return false;
    }

    private function preferredRemoteJid(string $remoteJid, string $remoteJidAlt): string
    {
        $primary = trim($remoteJid);
        $alternate = trim($remoteJidAlt);
        if ($primary !== '' && str_ends_with(mb_strtolower($primary), '@lid') && $alternate !== '') {
            return $alternate;
        }
        return $primary !== '' ? $primary : $alternate;
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

            $resolvedAgent = (new AgentRoutingService())->resolve(
                $pdo,
                [
                    'id' => (int) $outgoing['evolution_instance_id'],
                    'tenant_id' => (int) $instance['tenant_id'],
                ],
                (int) $outgoing['conversation_id'],
                '',
                true
            );
            $agentId = (int) ($resolvedAgent['id'] ?? 0);

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
