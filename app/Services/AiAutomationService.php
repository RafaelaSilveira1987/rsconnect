<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Crypto;
use App\Core\Database;
use App\Core\Env;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Throwable;

final class AiAutomationService
{
    private ?int $currentIncomingMessageId = null;

    public function __construct(
        private readonly AiModelService $ai = new AiModelService(),
        private readonly AutomationWebhookService $automationWebhook = new AutomationWebhookService(),
    ) {
    }

    /**
     * Envia a mensagem fixa de ausência sem depender do modo IA da conversa.
     *
     * A fila fora do horário é operacional: uma conversa em modo humano também
     * pode avisar o contato que a empresa está fechada. O lock e a releitura do
     * ack_sent_at impedem respostas duplicadas quando chegam várias mensagens
     * quase ao mesmo tempo.
     *
     * @return array{sent:bool,reason:string}
     */
    public function sendAfterHoursAcknowledgement(
        PDO $pdo,
        array $instance,
        int $conversationId,
        array $agent,
        int $pendingId = 0,
        ?int $incomingMessageId = null
    ): array {
        $message = trim((string) ($agent['after_hours_message'] ?? ''));
        if ($message === '') {
            try {
                $fallbackStatement = $pdo->prepare(
                    'SELECT after_hours_message
                     FROM tenant_onboarding_settings
                     WHERE tenant_id = :tenant_id
                     LIMIT 1'
                );
                $fallbackStatement->execute(['tenant_id' => (int) ($instance['tenant_id'] ?? 0)]);
                $message = trim((string) $fallbackStatement->fetchColumn());
            } catch (Throwable) {
                // Instalações antigas podem não possuir as configurações do onboarding.
            }
        }
        if ($message === '') {
            $message = 'No momento estamos fora do horário de atendimento. Assim que retornarmos, daremos continuidade por aqui.';
        }

        $conversation = $this->conversation($pdo, $conversationId);
        if (!$conversation || (string) ($conversation['status'] ?? '') === 'closed') {
            return ['sent' => false, 'reason' => 'conversation_unavailable'];
        }

        $recipientBlock = $this->nonReplyableRecipientReason($conversation);
        if ($recipientBlock !== null) {
            $this->log(
                (int) ($instance['tenant_id'] ?? 0),
                $conversationId,
                (int) ($agent['id'] ?? 0) ?: null,
                'ai.after_hours',
                'skipped',
                $recipientBlock,
                null,
                ['acknowledgement' => true, 'non_retryable' => true]
            );
            return ['sent' => false, 'reason' => 'recipient_unavailable'];
        }

        $previousIncomingMessageId = $this->currentIncomingMessageId;
        $this->currentIncomingMessageId = $incomingMessageId !== null && $incomingMessageId > 0
            ? $incomingMessageId
            : $previousIncomingMessageId;
        $lockName = mb_substr('rs_after_hours_ack_' . $conversationId, 0, 64);
        $lockAcquired = false;

        try {
            $lockStatement = $pdo->prepare('SELECT GET_LOCK(:lock_name, 5)');
            $lockStatement->execute(['lock_name' => $lockName]);
            $lockAcquired = (int) $lockStatement->fetchColumn() === 1;
            if (!$lockAcquired) {
                return ['sent' => false, 'reason' => 'ack_lock_busy'];
            }

            if ($pendingId > 0) {
                $pendingStatement = $pdo->prepare(
                    'SELECT status, ack_sent_at
                     FROM ai_after_hours_pending
                     WHERE id = :id AND conversation_id = :conversation_id
                     LIMIT 1'
                );
                $pendingStatement->execute([
                    'id' => $pendingId,
                    'conversation_id' => $conversationId,
                ]);
                $pending = $pendingStatement->fetch(PDO::FETCH_ASSOC) ?: null;
                if (is_array($pending)) {
                    if (!empty($pending['ack_sent_at'])) {
                        return ['sent' => false, 'reason' => 'already_acknowledged'];
                    }
                    if (in_array((string) ($pending['status'] ?? ''), ['recovered', 'cancelled'], true)) {
                        return ['sent' => false, 'reason' => 'pending_inactive'];
                    }
                }
            }

            $this->sendAutomatedMessage(
                $pdo,
                $instance,
                $conversation,
                $conversationId,
                $message,
                'ai.after_hours',
                'Mensagem de ausência fora do horário enviada pela automação.',
                $agent
            );

            if ($pendingId > 0) {
                $pdo->prepare(
                    'UPDATE ai_after_hours_pending
                     SET ack_sent_at = COALESCE(ack_sent_at, :ack_sent_at)
                     WHERE id = :id'
                )->execute([
                    'ack_sent_at' => \App\Core\Clock::nowUtc(),
                    'id' => $pendingId,
                ]);
            }

            $this->log(
                (int) ($instance['tenant_id'] ?? 0),
                $conversationId,
                (int) ($agent['id'] ?? 0) ?: null,
                'ai.after_hours',
                'success',
                null,
                $message,
                [
                    'pending_recovery' => true,
                    'acknowledgement' => true,
                    'attendance_mode' => (string) ($conversation['attendance_mode'] ?? ''),
                    'agent_name' => (string) ($agent['name'] ?? ''),
                ]
            );

            return ['sent' => true, 'reason' => 'sent'];
        } catch (Throwable $exception) {
            $this->log(
                (int) ($instance['tenant_id'] ?? 0),
                $conversationId,
                (int) ($agent['id'] ?? 0) ?: null,
                'ai.after_hours',
                'error',
                $exception->getMessage(),
                null,
                ['pending_recovery' => true, 'acknowledgement' => true]
            );
            throw $exception;
        } finally {
            if ($lockAcquired) {
                $this->releaseConversationLock($pdo, $lockName);
            }
            $this->currentIncomingMessageId = $previousIncomingMessageId;
        }
    }

    public function handleIncoming(array $instance, int $conversationId, string $incomingContent, array $payload): void
    {
        $candidateMessageId = $payload['stored_message_id'] ?? $payload['message_id'] ?? null;
        $this->currentIncomingMessageId = is_numeric($candidateMessageId) && (int) $candidateMessageId > 0
            ? (int) $candidateMessageId
            : null;

        $pdo = null;
        $agent = null;
        $failurePhase = 'bootstrap';
        $usageReservationId = 0;
        $usageService = new AiUsageService();
        $generationAgent = null;
        $efficiencyTelemetry = [];
        $aiRoute = null;
        $reply = null;
        $routingTransition = null;
        $previousPinnedAgentId = 0;

        // Defesa adicional contra eco de mensagens enviadas pela própria Evolution.
        // Mesmo que outro chamador encaminhe SEND_MESSAGE ou fromMe=true por engano,
        // esse payload nunca deve chegar ao provedor de IA.
        $payloadEvent = mb_strtolower(trim((string) ($payload['event'] ?? '')));
        $payloadEvent = str_replace(['_', '-'], '.', $payloadEvent);
        $payloadFromMe = filter_var(
            $payload['data']['key']['fromMe']
                ?? $payload['data']['fromMe']
                ?? $payload['key']['fromMe']
                ?? $payload['fromMe']
                ?? false,
            FILTER_VALIDATE_BOOL
        );
        if (str_contains($payloadEvent, 'send.message') || $payloadFromMe) {
            $this->log(
                (int) ($instance['tenant_id'] ?? 0),
                $conversationId,
                null,
                'ai.skipped',
                'skipped',
                'Evento de saída ignorado; a própria mensagem enviada não pode acionar o assistente.',
                null,
                ['outgoing_event' => true, 'payload_event' => $payloadEvent]
            );
            $this->currentIncomingMessageId = null;
            return;
        }

        $conversationLockName = mb_substr('rs_ai_conversation_' . $conversationId, 0, 64);
        $conversationLockAcquired = false;
        $globalEnabled = filter_var(Env::get('AI_AUTOREPLY_ENABLED', true), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($globalEnabled === false) {
            $this->log((int) $instance['tenant_id'], $conversationId, null, 'ai.skipped', 'skipped', 'AI_AUTOREPLY_ENABLED=false', null, null);
            $this->currentIncomingMessageId = null;
            return;
        }

        try {
            $pdo = Database::connection();
            $conversation = $this->conversation($pdo, $conversationId);
            if (!$conversation || $conversation['attendance_mode'] !== 'ai' || $conversation['status'] === 'closed') {
                $this->log((int) $instance['tenant_id'], $conversationId, null, 'ai.skipped', 'skipped', 'Conversa não está mais em modo IA.', null, null);
                return;
            }

            $previousPinnedAgentId = (int) ($conversation['ai_agent_id'] ?? 0);
            $agent = $this->agentFor($pdo, $instance, $conversationId, $incomingContent);
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

            $currentAgentId = (int) ($agent['id'] ?? 0);
            if ($previousPinnedAgentId > 0 && $currentAgentId > 0 && $previousPinnedAgentId !== $currentAgentId) {
                $routedConversation = $this->conversation($pdo, $conversationId);
                if ((int) ($routedConversation['ai_agent_id'] ?? 0) === $currentAgentId) {
                    $previousAgentName = $this->aiAgentName($pdo, (int) $instance['tenant_id'], $previousPinnedAgentId);
                    $routingTransition = [
                        'from_agent_id' => $previousPinnedAgentId,
                        'from_agent_name' => $previousAgentName !== '' ? $previousAgentName : ('Assistente #' . $previousPinnedAgentId),
                        'to_agent_id' => $currentAgentId,
                        'to_agent_name' => trim((string) ($agent['name'] ?? '')) !== '' ? trim((string) $agent['name']) : ('Assistente #' . $currentAgentId),
                    ];

                    $this->insertEvent(
                        $pdo,
                        (int) $instance['tenant_id'],
                        $conversationId,
                        'ai.routing.handoff',
                        'Transferência interna de IA - ' . $routingTransition['from_agent_name'] . ' para IA - ' . $routingTransition['to_agent_name'] . '.'
                    );
                    $this->log(
                        (int) $instance['tenant_id'],
                        $conversationId,
                        $currentAgentId,
                        'ai.routing.handoff',
                        'success',
                        null,
                        null,
                        $routingTransition
                    );
                }
            }

            $recipientBlock = $this->nonReplyableRecipientReason($conversation);
            if ($recipientBlock !== null) {
                $this->log(
                    (int) $instance['tenant_id'],
                    $conversationId,
                    (int) $agent['id'],
                    'ai.recipient.unavailable',
                    'skipped',
                    $recipientBlock,
                    null,
                    [
                        'non_retryable' => true,
                        'failure_phase' => 'recipient.guard',
                        'remote_jid' => (string) ($conversation['remote_jid'] ?? ''),
                        'phone' => (string) ($conversation['phone'] ?? ''),
                    ]
                );
                return;
            }

            $lockStatement = $pdo->prepare('SELECT GET_LOCK(:lock_name, 35)');
            $lockStatement->execute(['lock_name' => $conversationLockName]);
            $conversationLockAcquired = (int) $lockStatement->fetchColumn() === 1;
            if (!$conversationLockAcquired) {
                $this->log(
                    (int) $instance['tenant_id'],
                    $conversationId,
                    (int) $agent['id'],
                    'ai.cooldown',
                    'skipped',
                    'Mensagem aguardando outra execução da IA terminar.',
                    null,
                    [
                        'pending_reprocess' => true,
                        'lock_busy' => true,
                        'incoming_message_id' => $this->payloadMessageId($payload),
                    ]
                );
                return;
            }

            // A conversa pode ter mudado enquanto aguardava outra execução.
            $conversation = $this->conversation($pdo, $conversationId);
            if (!$conversation || $conversation['attendance_mode'] !== 'ai' || $conversation['status'] === 'closed') {
                $this->log((int) $instance['tenant_id'], $conversationId, (int) $agent['id'], 'ai.skipped', 'skipped', 'Conversa não está mais em modo IA.', null, null);
                return;
            }

            $bypassCooldown = filter_var(
                $payload['bypass_cooldown'] ?? false,
                FILTER_VALIDATE_BOOL,
                FILTER_NULL_ON_FAILURE
            ) === true;
            $afterHoursRecovery = filter_var(
                $payload['after_hours_recovery'] ?? false,
                FILTER_VALIDATE_BOOL,
                FILTER_NULL_ON_FAILURE
            ) === true;

            $storedMessageId = (int) ($this->currentIncomingMessageId ?? 0);

            // A proteção contra duplicidade vale para toda execução, não apenas para o reprocessamento.
            // Assim, um webhook repetido nunca gera uma segunda saída para a mesma mensagem recebida.
            if ($storedMessageId > 0 && !$afterHoursRecovery && $this->hasOutgoingAfterStoredMessage($pdo, $conversationId, $storedMessageId)) {
                $this->log(
                    (int) $instance['tenant_id'],
                    $conversationId,
                    (int) $agent['id'],
                    $bypassCooldown ? 'ai.reprocess.skipped' : 'ai.duplicate.skipped',
                    'skipped',
                    'A mensagem já recebeu uma resposta posterior e não será reenviada.',
                    null,
                    ['message_id' => $storedMessageId, 'duplicate_prevented' => true]
                );
                return;
            }

            // A política de horário vem antes da espera da IA. A mensagem fixa de
            // ausência é operacional (não é resposta gerada pela IA) e deve poder
            // ser enviada imediatamente, no máximo uma vez por dia local.
            $operatingPolicy = (new AgentOperatingPolicyService())->status($agent);
            if (!empty($operatingPolicy['enforced']) && empty($operatingPolicy['inside'])) {
                $afterHoursRecoveryService = new AiAfterHoursRecoveryService();
                $pending = $afterHoursRecoveryService->markPending(
                    $pdo,
                    (int) $instance['tenant_id'],
                    $conversationId,
                    (int) $agent['id'],
                    $storedMessageId > 0 ? $storedMessageId : null
                );

                $afterHoursMessage = trim((string) ($agent['after_hours_message'] ?? ''));
                if ($afterHoursMessage !== '' && !empty($pending['should_ack'])) {
                    $conversation = $this->conversation($pdo, $conversationId);
                    if (!$this->conversationAllowsAutomaticReply($conversation)) {
                        $this->log((int) $instance['tenant_id'], $conversationId, (int) $agent['id'], 'ai.skipped', 'skipped', 'Atendimento assumido ou IA pausada antes do envio automático.', null, ['takeover_guard' => true, 'after_hours_pending' => true]);
                        return;
                    }
                    $this->sendAutomatedMessage($pdo, $instance, $conversation, $conversationId, $afterHoursMessage, 'ai.after_hours', 'Mensagem de ausência fora do horário enviada pela automação.', $agent);
                    $afterHoursRecoveryService->markAcknowledged((int) ($pending['pending_id'] ?? 0));
                    $this->log((int) $instance['tenant_id'], $conversationId, (int) $agent['id'], 'ai.after_hours', 'success', null, $afterHoursMessage, ['pending_recovery' => true, 'acknowledgement' => true, 'operating_policy' => $operatingPolicy, 'agent_name' => (string) ($agent['name'] ?? '')]);
                    return;
                }

                $this->log(
                    (int) $instance['tenant_id'],
                    $conversationId,
                    (int) $agent['id'],
                    'ai.after_hours',
                    'skipped',
                    $afterHoursMessage === '' ? 'Fora do horário; demanda preservada para recuperação automática.' : 'Demanda adicionada à recuperação pós-horário; mensagem de ausência já enviada hoje.',
                    null,
                    ['pending_recovery' => true, 'acknowledgement_already_sent' => !empty($pending['pending_id']) && empty($pending['should_ack']), 'operating_policy' => $operatingPolicy, 'agent_name' => (string) ($agent['name'] ?? '')]
                );
                return;
            }

            $cooldownSeconds = max(0, min(3600, (int) ($agent['cooldown_seconds'] ?? 15)));
            $remainingSeconds = $this->cooldownRemaining($pdo, $conversationId, $cooldownSeconds);

            // 36.6.16: cooldown_seconds passa a representar o tempo mínimo de espera
            // após a ÚLTIMA mensagem recebida. Se o cliente enviar outra mensagem
            // dentro desse período, a contagem reinicia. Isso funciona também na
            // primeira interação e agrupa mensagens antes de chamar a IA.
            $cooldownApplies = !$bypassCooldown;
            if ($cooldownApplies && $remainingSeconds > 0) {
                $this->log(
                    (int) $instance['tenant_id'],
                    $conversationId,
                    (int) $agent['id'],
                    'ai.cooldown',
                    'skipped',
                    'Mensagem aguardando o tempo configurado após a última interação antes da resposta da IA.',
                    null,
                    [
                        'pending_reprocess' => true,
                        'cooldown_seconds' => $cooldownSeconds,
                        'reply_wait_seconds' => $cooldownSeconds,
                        'remaining_seconds' => $remainingSeconds,
                        'incoming_message_id' => $this->payloadMessageId($payload),
                    ]
                );
                return;
            }

            // 36.27.18: toda mensagem que chega à IA depois da janela de espera passa
            // novamente pela máquina determinística da Agenda ANTES de qualquer regra
            // local, cache ou provedor de IA. Isso fecha um atalho em que a Fila rápida
            // podia acabar chamando handleIncoming() e produzir uma resposta textual de
            // agendamento sem calendar_appointments persistido.
            if (!$afterHoursRecovery && $storedMessageId > 0) {
                $calendarGuard = $this->processSchedulingDuringReprocess($pdo, $instance, [
                    'contact_id' => (int) ($conversation['contact_id'] ?? 0),
                    'conversation_id' => $conversationId,
                    'message_id' => $storedMessageId,
                    'content' => $incomingContent,
                ]);

                $calendarError = trim((string) ($calendarGuard['calendar_error'] ?? ''));
                $calendarHandled = !empty($calendarGuard['handled']);
                $calendarSkipAi = !empty($calendarGuard['skip_ai']);
                $schedulingIntent = !empty($calendarGuard['scheduling_intent']);
                $preSchedulingEnabled = (new PreSchedulingService())->isEnabled((int) $instance['tenant_id']);

                if ($calendarError !== '' && ($schedulingIntent || $calendarHandled)) {
                    $this->log(
                        (int) $instance['tenant_id'],
                        $conversationId,
                        (int) $agent['id'],
                        'ai.failed',
                        'error',
                        'Falha na camada determinística de agenda: ' . $calendarError,
                        null,
                        [
                            'failure_phase' => 'calendar.pre_schedule',
                            'incoming_message_id' => $storedMessageId,
                            'calendar_fail_closed' => true,
                            'calendar_guard' => 'before_ai_provider',
                        ]
                    );
                    return;
                }

                if ($calendarHandled && $calendarSkipAi) {
                    $this->log(
                        (int) $instance['tenant_id'],
                        $conversationId,
                        (int) $agent['id'],
                        'calendar.pre_schedule.handled',
                        'success',
                        null,
                        null,
                        [
                            'incoming_message_id' => $storedMessageId,
                            'appointment_id' => (int) ($calendarGuard['appointment_id'] ?? 0),
                            'calendar_guard' => 'before_ai_provider',
                        ]
                    );
                    return;
                }

                // Se a intenção é explicitamente de agenda e a agenda automática está
                // habilitada, "não tratado" não é autorização para o modelo improvisar.
                // Falha fechada: preserva a mensagem para diagnóstico/reprocessamento e
                // impede frases como "ficou agendado" sem transação no calendário.
                if ($schedulingIntent && $preSchedulingEnabled && !$calendarHandled) {
                    $message = 'Intenção de agenda detectada, mas a camada determinística não criou nem atualizou um pré-agendamento.';
                    try {
                        $pdo->prepare(
                            'INSERT INTO conversation_events (tenant_id, conversation_id, event_type, description, metadata_json) '
                            . 'VALUES (:tenant_id, :conversation_id, "calendar.pre_schedule_unhandled", :description, :metadata_json)'
                        )->execute([
                            'tenant_id' => (int) $instance['tenant_id'],
                            'conversation_id' => $conversationId,
                            'description' => $message,
                            'metadata_json' => json_encode([
                                'incoming_message_id' => $storedMessageId,
                                'agent_id' => (int) $agent['id'],
                                'calendar_guard' => 'before_ai_provider',
                            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        ]);
                    } catch (Throwable) {
                        // A observabilidade não pode esconder a proteção fail-closed.
                    }
                    $this->log(
                        (int) $instance['tenant_id'],
                        $conversationId,
                        (int) $agent['id'],
                        'ai.failed',
                        'error',
                        $message,
                        null,
                        [
                            'failure_phase' => 'calendar.pre_schedule_unhandled',
                            'incoming_message_id' => $storedMessageId,
                            'calendar_fail_closed' => true,
                        ]
                    );
                    return;
                }
            }

            // Se a IA já gerou uma resposta e apenas a Evolution falhou, reaproveita
            // exatamente a saída preservada. Isso evita gastar tokens de novo e mantém
            // a conversa coerente depois que o WhatsApp for reconectado.
            if ($storedMessageId > 0) {
                $failedDelivery = $this->failedAutomatedMessageAfterIncoming($pdo, $conversationId, $storedMessageId);
                if ($failedDelivery !== null) {
                    $failurePhase = 'evolution.retry';
                    $retryResult = $this->retryFailedAutomatedMessage(
                        $pdo,
                        $instance,
                        $conversation,
                        $conversationId,
                        $failedDelivery
                    );
                    $this->log(
                        (int) $instance['tenant_id'],
                        $conversationId,
                        (int) $agent['id'],
                        'ai.delivery.retried',
                        'success',
                        null,
                        (string) ($failedDelivery['content'] ?? ''),
                        [
                            'failed_message_id' => (int) ($failedDelivery['id'] ?? 0),
                            'http_status' => $retryResult['status'] ?? null,
                            'external_id' => $this->extractMessageId($retryResult['body'] ?? []),
                            'provider_call_avoided' => true,
                        ]
                    );
                    return;
                }
            }

            if ($this->shouldHandoff($incomingContent, (string) ($agent['handoff_keywords'] ?? ''))) {
                $this->handoff($pdo, $instance, $conversation, $agent, $conversationId);
                return;
            }

            // Recuperação pós-horário precisa reentrar primeiro na máquina determinística
            // da Agenda. Antes da 36.6.32, a retomada chamava diretamente o modelo de IA;
            // ele podia responder "vou verificar a disponibilidade" sem criar/enviar a
            // solicitação calendar.availability.requested. O resultado era uma conversa
            // aparentemente em andamento, mas sem request de disponibilidade para concluir.
            if ($afterHoursRecovery && $storedMessageId > 0) {
                $calendarRecovery = $this->processSchedulingDuringReprocess($pdo, $instance, [
                    'contact_id' => (int) ($conversation['contact_id'] ?? 0),
                    'conversation_id' => $conversationId,
                    'message_id' => $storedMessageId,
                    'content' => $incomingContent,
                ]);

                if (!empty($calendarRecovery['handled']) && !empty($calendarRecovery['skip_ai'])) {
                    $availabilityResult = is_array($calendarRecovery['availability_request_result'] ?? null)
                        ? $calendarRecovery['availability_request_result']
                        : null;
                    $availabilityFailed = is_array($availabilityResult)
                        && empty($availabilityResult['ok'])
                        && empty($availabilityResult['skipped']);
                    $calendarError = trim((string) (
                        $calendarRecovery['calendar_error']
                        ?? ($availabilityResult['message'] ?? '')
                    ));

                    $this->log(
                        (int) $instance['tenant_id'],
                        $conversationId,
                        (int) $agent['id'],
                        $availabilityFailed ? 'calendar.recovery.failed' : 'calendar.recovery.handled',
                        $availabilityFailed ? 'error' : 'success',
                        $availabilityFailed ? ($calendarError !== '' ? $calendarError : 'A consulta de disponibilidade não pôde ser iniciada.') : null,
                        null,
                        [
                            'after_hours_recovery' => true,
                            'calendar_handled' => true,
                            'appointment_id' => (int) ($calendarRecovery['appointment_id'] ?? 0),
                            'availability_request_needed' => !empty($calendarRecovery['availability_request_needed']),
                            'availability_request' => $availabilityResult,
                        ]
                    );
                    return;
                }
            }

            // Antes de reservar franquia ou chamar o provedor, tenta respostas determinísticas
            // configuradas e o cache exato opcional. Essas saídas não consomem tokens.
            if (!$afterHoursRecovery && $routingTransition === null) {
                $localReply = (new AiLocalReplyService())->match($agent, $incomingContent);
                if (!empty($localReply['matched']) && trim((string) ($localReply['reply'] ?? '')) !== '') {
                    $conversation = $this->conversation($pdo, $conversationId);
                    if (!$this->conversationAllowsAutomaticReply($conversation)) {
                        $this->log((int) $instance['tenant_id'], $conversationId, (int) $agent['id'], 'ai.local_rule.skipped', 'skipped', 'Atendimento assumido ou IA pausada antes da resposta local.', null, ['strategy' => 'local_rule']);
                        return;
                    }
                    $reply = trim((string) $localReply['reply']);
                    $result = $this->sendAutomatedMessage($pdo, $instance, $conversation, $conversationId, $reply, 'ai.local_rule', 'Resposta automática enviada por regra local, sem chamada ao provedor.', $agent);
                    $usageService->recordAvoidedAutoReply(
                        (int) $instance['tenant_id'],
                        $agent,
                        $conversationId,
                        $storedMessageId > 0 ? $storedMessageId : null,
                        (int) ($result['_stored_message_id'] ?? 0),
                        'local_rule',
                        'Regra local: ' . (string) ($localReply['type'] ?? 'configurada')
                    );
                    $this->log((int) $instance['tenant_id'], $conversationId, (int) $agent['id'], 'ai.local_rule', 'success', null, $reply, [
                        'strategy' => 'local_rule',
                        'rule_type' => $localReply['type'] ?? null,
                        'provider_call_avoided' => true,
                    ]);
                    return;
                }

                $cacheResult = (new AiExactCacheService())->lookup($pdo, (int) $instance['tenant_id'], $agent, $incomingContent);
                if (!empty($cacheResult['hit']) && trim((string) ($cacheResult['reply'] ?? '')) !== '') {
                    $conversation = $this->conversation($pdo, $conversationId);
                    if (!$this->conversationAllowsAutomaticReply($conversation)) {
                        $this->log((int) $instance['tenant_id'], $conversationId, (int) $agent['id'], 'ai.cache.skipped', 'skipped', 'Atendimento assumido ou IA pausada antes da resposta em cache.', null, ['strategy' => 'exact_cache']);
                        return;
                    }
                    $reply = trim((string) $cacheResult['reply']);
                    $result = $this->sendAutomatedMessage($pdo, $instance, $conversation, $conversationId, $reply, 'ai.cache.replied', 'Resposta automática reutilizada do cache exato, sem chamada ao provedor.', $agent);
                    $usageService->recordAvoidedAutoReply(
                        (int) $instance['tenant_id'],
                        $agent,
                        $conversationId,
                        $storedMessageId > 0 ? $storedMessageId : null,
                        (int) ($result['_stored_message_id'] ?? 0),
                        'exact_cache',
                        'Cache exato #' . (int) ($cacheResult['cache_id'] ?? 0)
                    );
                    $this->log((int) $instance['tenant_id'], $conversationId, (int) $agent['id'], 'ai.cache.replied', 'success', null, $reply, [
                        'strategy' => 'exact_cache',
                        'cache_id' => $cacheResult['cache_id'] ?? null,
                        'provider_call_avoided' => true,
                    ]);
                    return;
                }
            }

            // A governança financeira pode reduzir temporariamente o perfil para Econômico
            // antes da chamada ao provedor. Regras locais/cache já foram avaliados acima e
            // continuam disponíveis mesmo quando o orçamento bloqueia a IA custeada pela RS.
            $budgetDecision = (new AiBudgetPolicyService())->decision((int) $instance['tenant_id']);
            $routingAgent = $agent;
            if (!empty($budgetDecision['force_economy'])) {
                $routingAgent['ai_efficiency_mode'] = 'economy';
                $routingAgent['_ai_budget_forced_economy'] = 1;
                $this->log(
                    (int) $instance['tenant_id'],
                    $conversationId,
                    (int) $agent['id'],
                    'ai.budget.economy',
                    'success',
                    (string) ($budgetDecision['message'] ?? 'Modo Econômico aplicado pela política de orçamento.'),
                    null,
                    [
                        'budget_usd' => $budgetDecision['budget_usd'] ?? null,
                        'used_usd' => $budgetDecision['used_usd'] ?? null,
                        'used_percent' => $budgetDecision['used_percent'] ?? null,
                    ]
                );
            }

            $aiRoute = (new AiRouterService())->route($routingAgent, $conversation, $incomingContent);
            $generationAgent = array_merge($routingAgent, (array) ($aiRoute['agent_overrides'] ?? []));

            $quota = $usageService->reserveAutoReply(
                (int) $instance['tenant_id'],
                $generationAgent,
                $conversationId,
                $storedMessageId > 0 ? $storedMessageId : null
            );
            if (empty($quota['allowed'])) {
                $this->log(
                    (int) $instance['tenant_id'],
                    $conversationId,
                    (int) $agent['id'],
                    'ai.quota.blocked',
                    'skipped',
                    (string) ($quota['message'] ?? 'Franquia de IA atingida.'),
                    null,
                    [
                        'pending_reprocess' => true,
                        'credential_owner' => $quota['owner'] ?? null,
                        'used' => $quota['used'] ?? null,
                        'limit' => $quota['limit'] ?? null,
                    ]
                );
                return;
            }
            $usageReservationId = (int) ($quota['event_id'] ?? 0);

            if (is_array($routingTransition)) {
                $generationAgent['_routing_handoff_from_agent_id'] = (int) $routingTransition['from_agent_id'];
                $generationAgent['_routing_handoff_from_agent_name'] = (string) $routingTransition['from_agent_name'];
                $generationAgent['_routing_handoff_to_agent_id'] = (int) $routingTransition['to_agent_id'];
                $generationAgent['_routing_handoff_to_agent_name'] = (string) $routingTransition['to_agent_name'];
            }

            $preparedContext = (new AiContextBuilder())->build($pdo, $generationAgent, $conversationId, $incomingContent);
            $messages = (array) ($preparedContext['messages'] ?? []);
            $generationAgent = is_array($preparedContext['agent'] ?? null) ? $preparedContext['agent'] : $generationAgent;
            $efficiencyTelemetry = is_array($preparedContext['telemetry'] ?? null) ? $preparedContext['telemetry'] : [];
            if ($afterHoursRecovery) {
                // A mensagem de ausência é uma saída automática e pode ser o último item do histórico.
                // Acrescenta somente em memória uma instrução de retomada para que o modelo responda
                // à demanda pendente, em vez de interpretar a ausência como encerramento do assunto.
                $messages[] = [
                    'direction' => 'incoming',
                    'sender_type' => 'contact',
                    'content' => 'INSTRUÇÃO INTERNA DE RETOMADA: o horário de atendimento foi reaberto. Responda agora à demanda pendente nas mensagens anteriores do cliente. Não reinicie a conversa, não repita a mensagem de ausência e não peça informações que já estejam no cadastro ou no histórico.',
                    'sent_at' => \App\Core\Clock::nowUtc(),
                ];
            }
            $failurePhase = 'ai.generate';
            $reply = $this->ai->generateReply($generationAgent, $messages, $conversation, $conversation);

            // O horário também é revalidado imediatamente antes do envio. Assim uma
            // resposta que começou dentro do expediente não é entregue depois do fechamento,
            // e nenhuma integração/prompt consegue ultrapassar a regra operacional.
            $sendPolicy = (new AgentOperatingPolicyService())->status($agent);
            if (!empty($sendPolicy['enforced']) && empty($sendPolicy['inside'])) {
                $usageService->cancelReservation($usageReservationId, 'Resposta descartada porque o expediente encerrou antes do envio.', false, array_merge($this->ai->lastUsage(), $efficiencyTelemetry));
                $usageReservationId = 0;
                (new AiAfterHoursRecoveryService())->markPending(
                    $pdo,
                    (int) $instance['tenant_id'],
                    $conversationId,
                    (int) $agent['id'],
                    $storedMessageId > 0 ? $storedMessageId : null
                );
                $this->log(
                    (int) $instance['tenant_id'],
                    $conversationId,
                    (int) $agent['id'],
                    'ai.after_hours',
                    'skipped',
                    'O expediente encerrou antes do envio da resposta gerada; demanda preservada para recuperação.',
                    null,
                    ['send_time_guard' => true, 'operating_policy' => $sendPolicy, 'agent_name' => (string) ($agent['name'] ?? '')]
                );
                return;
            }

            // O atendente pode assumir a conversa enquanto o provedor de IA está gerando a resposta.
            // Revalida imediatamente antes do envio externo para que assumir atendimento pause a IA de fato.
            $conversation = $this->conversation($pdo, $conversationId);
            if (!$this->conversationAllowsAutomaticReply($conversation)) {
                $usageService->cancelReservation($usageReservationId, 'Resposta descartada porque o atendimento foi assumido ou a IA foi pausada.', false, array_merge($this->ai->lastUsage(), $efficiencyTelemetry));
                $usageReservationId = 0;
                $this->log(
                    (int) $instance['tenant_id'],
                    $conversationId,
                    (int) $agent['id'],
                    'ai.skipped',
                    'skipped',
                    'Atendimento assumido ou IA pausada antes do envio da resposta gerada.',
                    null,
                    ['takeover_guard' => true, 'reply_discarded' => true]
                );
                return;
            }

            $failurePhase = 'evolution.send';
            $result = $this->sendAutomatedMessage($pdo, $instance, $conversation, $conversationId, $reply, 'ai.replied', 'Resposta automática enviada pela IA.', $agent);
            $usageService->completeAutoReply($usageReservationId, (int) ($result['_stored_message_id'] ?? 0), array_merge($this->ai->lastUsage(), $efficiencyTelemetry));
            $usageReservationId = 0;

            // O cache é opcional, exato e invalidado automaticamente quando prompt, base ou modelo mudam.
            (new AiExactCacheService())->store($pdo, (int) $instance['tenant_id'], $agent, $incomingContent, $reply);

            $this->log((int) $instance['tenant_id'], $conversationId, (int) $agent['id'], 'ai.replied', 'success', null, $reply, [
                'http_status' => $result['status'] ?? null,
                'external_id' => $this->extractMessageId($result['body'] ?? []),
                'provider' => $agent['credential_provider'] ?? $agent['model_provider'] ?? null,
                'credential_id' => $agent['credential_id'] ?? null,
                'ai_efficiency' => [
                    'mode' => $aiRoute['mode'] ?? ($generationAgent['_ai_efficiency_mode'] ?? 'balanced'),
                    'complexity' => $aiRoute['complexity'] ?? null,
                    'history_messages_total' => $efficiencyTelemetry['history_messages_total'] ?? null,
                    'history_messages_sent' => $efficiencyTelemetry['history_messages_sent'] ?? null,
                    'knowledge_chars_total' => $efficiencyTelemetry['knowledge_chars_total'] ?? null,
                    'knowledge_chars_sent' => $efficiencyTelemetry['knowledge_chars_sent'] ?? null,
                    'estimated_input_tokens_avoided' => $efficiencyTelemetry['estimated_input_tokens_avoided'] ?? 0,
                ],
                'contact_context' => [
                    'status' => $conversation['contact_status'] ?? null,
                    'group' => $conversation['contact_group'] ?? null,
                    'tags' => $this->decodeContactTags($conversation['tags_json'] ?? null),
                    'flow_stage' => $conversation['flow_stage'] ?? null,
                    'demand_status' => $conversation['demand_status'] ?? null,
                ],
            ]);

            // A resposta principal já foi enviada. Libera a conversa antes de chamar integrações
            // externas para que uma nova mensagem não fique aguardando n8n/HTTP.
            if ($conversationLockAcquired) {
                $this->releaseConversationLock($pdo, $conversationLockName);
                $conversationLockAcquired = false;
            }

            // Atualiza a memória depois da resposta já ter sido entregue e fora do lock da conversa.
            // A falha dessa tarefa nunca interfere no atendimento principal.
            try {
                $memoryResult = (new AiProgressiveMemoryService())->refreshIfNeeded(
                    $pdo,
                    (int) $instance['tenant_id'],
                    $agent,
                    $this->conversation($pdo, $conversationId)
                );
                if (($memoryResult['status'] ?? '') === 'refreshed') {
                    $this->log((int) $instance['tenant_id'], $conversationId, (int) $agent['id'], 'ai.memory.refreshed', 'success', null, null, [
                        'source_message_id' => $memoryResult['source_message_id'] ?? null,
                        'new_messages' => $memoryResult['new_messages'] ?? null,
                    ]);
                }
            } catch (Throwable $memoryException) {
                $this->log((int) $instance['tenant_id'], $conversationId, (int) $agent['id'], 'ai.memory.failed', 'error', $memoryException->getMessage(), null, []);
            }

            if ((int) ($agent['n8n_enabled'] ?? 0) === 1) {
                try {
                    $legacyUrl = trim((string) ($agent['n8n_webhook_url'] ?? ''));
                    $this->automationWebhook->dispatch('ai.replied', [
                        'tenant_id' => (int) $instance['tenant_id'],
                        'conversation_id' => $conversationId,
                        'agent_id' => (int) $agent['id'],
                        'incoming_message_id' => $storedMessageId > 0 ? $storedMessageId : null,
                        'reply' => $reply,
                        'incoming' => $incomingContent,
                    ], $legacyUrl !== '' ? $legacyUrl : null, (int) $instance['tenant_id']);
                } catch (Throwable $integrationException) {
                    // Uma falha do n8n depois da resposta não transforma a resposta enviada em ai.failed.
                    $this->log(
                        (int) $instance['tenant_id'],
                        $conversationId,
                        (int) $agent['id'],
                        'ai.integration.failed',
                        'error',
                        $integrationException->getMessage(),
                        null,
                        ['integration' => 'n8n', 'reply_already_sent' => true]
                    );
                }
            }
        } catch (Throwable $exception) {
            $recipientUnavailable = $failurePhase === 'evolution.send'
                && $this->isNonRetryableRecipientError($exception->getMessage());
            if ($usageReservationId > 0) {
                $usageService->cancelReservation(
                    $usageReservationId,
                    $exception->getMessage(),
                    !$recipientUnavailable,
                    array_merge($this->ai->lastUsage(), $efficiencyTelemetry)
                );
                $usageReservationId = 0;
            }
            if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $tenantId = (int) $instance['tenant_id'];
            $agentId = isset($agent['id']) ? (int) $agent['id'] : null;

            if ($recipientUnavailable) {
                $this->log(
                    $tenantId,
                    $conversationId,
                    $agentId,
                    'ai.recipient.unavailable',
                    'skipped',
                    'O destinatário não corresponde a um número WhatsApp disponível. A pendência foi encerrada sem novas tentativas.',
                    null,
                    [
                        'payload_event' => $payload['event'] ?? null,
                        'failure_phase' => $failurePhase,
                        'instance_id' => (int) ($instance['id'] ?? 0),
                        'instance_name' => (string) ($instance['instance_name'] ?? ''),
                        'non_retryable' => true,
                        'provider_error' => mb_substr($exception->getMessage(), 0, 700),
                    ]
                );
                return;
            }

            $evolutionFailure = str_starts_with($failurePhase, 'evolution.');
            if ($evolutionFailure && $pdo instanceof PDO && $this->isClosedEvolutionConnectionError($exception->getMessage())) {
                $this->updateEvolutionConnectionState($pdo, (int) ($instance['id'] ?? 0), 'closed');
            }

            $this->log($tenantId, $conversationId, $agentId, 'ai.failed', 'error', $exception->getMessage(), null, [
                'payload_event' => $payload['event'] ?? null,
                'failure_phase' => $failurePhase,
                'instance_id' => (int) ($instance['id'] ?? 0),
                'instance_name' => (string) ($instance['instance_name'] ?? ''),
                'pending_reprocess' => $evolutionFailure,
                'generated_reply_preserved' => $evolutionFailure && is_string($reply) && trim($reply) !== '',
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
        } finally {
            if ($conversationLockAcquired && $pdo instanceof PDO) {
                $this->releaseConversationLock($pdo, $conversationLockName);
            }
            $this->currentIncomingMessageId = null;
        }
    }

    /**
     * Retoma automaticamente uma mensagem adiada pelo tempo de espera da IA.
     *
     * O processo é disparado fora da resposta HTTP da Evolution. Antes de responder,
     * confirma que a mensagem ainda é a entrada mais recente da conversa. Assim, se o
     * cliente enviar outra mensagem durante a espera, somente o último disparo continua.
     *
     * @return array{status:string,conversation_id:int,message_id:int}
     */
    public function resumeDeferredIncoming(
        int $tenantId,
        int $conversationId,
        int $messageId,
        int $waitSeconds = 0
    ): array {
        $waitSeconds = max(0, min(3600, $waitSeconds));
        if ($waitSeconds > 0) {
            sleep($waitSeconds);
        }
        // Pequena margem para bancos que persistem sent_at com precisão de segundos.
        usleep(250000);

        $pdo = Database::connection();
        $latestStatement = $pdo->prepare(
            'SELECT id
             FROM conversation_messages
             WHERE tenant_id = :tenant_id
               AND conversation_id = :conversation_id
               AND direction = "incoming"
             ORDER BY sent_at DESC, id DESC
             LIMIT 1'
        );
        $latestStatement->execute([
            'tenant_id' => $tenantId,
            'conversation_id' => $conversationId,
        ]);
        $latestMessageId = (int) ($latestStatement->fetchColumn() ?: 0);

        if ($latestMessageId !== $messageId) {
            return [
                'status' => 'superseded',
                'conversation_id' => $conversationId,
                'message_id' => $messageId,
            ];
        }

        if ($this->hasOutgoingAfterStoredMessage($pdo, $conversationId, $messageId)) {
            return [
                'status' => 'already_replied',
                'conversation_id' => $conversationId,
                'message_id' => $messageId,
            ];
        }

        $messageStatement = $pdo->prepare(
            'SELECT cm.content, ei.*
             FROM conversation_messages cm
             INNER JOIN conversations c
                ON c.id = cm.conversation_id
               AND c.tenant_id = cm.tenant_id
             INNER JOIN evolution_instances ei
                ON ei.id = c.evolution_instance_id
               AND ei.tenant_id = c.tenant_id
             WHERE cm.id = :message_id
               AND cm.conversation_id = :conversation_id
               AND cm.tenant_id = :tenant_id
               AND cm.direction = "incoming"
             LIMIT 1'
        );
        $messageStatement->execute([
            'message_id' => $messageId,
            'conversation_id' => $conversationId,
            'tenant_id' => $tenantId,
        ]);
        $row = $messageStatement->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!is_array($row)) {
            return [
                'status' => 'unavailable',
                'conversation_id' => $conversationId,
                'message_id' => $messageId,
            ];
        }

        $content = trim((string) ($row['content'] ?? ''));
        unset($row['content']);
        if ($content === '') {
            return [
                'status' => 'empty',
                'conversation_id' => $conversationId,
                'message_id' => $messageId,
            ];
        }

        $this->handleIncoming($row, $conversationId, $content, [
            'event' => 'ai.deferred.autoresume',
            'stored_message_id' => $messageId,
            'message_id' => $messageId,
            'bypass_cooldown' => true,
            'deferred_autoresume' => true,
        ]);

        return [
            'status' => $this->hasOutgoingAfterStoredMessage($pdo, $conversationId, $messageId)
                ? 'replied'
                : 'processed',
            'conversation_id' => $conversationId,
            'message_id' => $messageId,
        ];
    }

    /**
     * Reavalia a mensagem mais recente sem resposta, incluindo intervalo,
     * falha de IA/Evolution e execução interrompida antes do registro do log.
     *
     * @return array{status:string,conversation_id?:int,message_id?:int,error?:string,event?:string}
     */
    public function reprocessLatestPendingForAgent(int $tenantId, int $agentId, string $source = 'manual'): array
    {
        $pdo = null;
        $lockName = mb_substr('rs_ai_agent_' . $tenantId . '_' . $agentId, 0, 64);
        $lockAcquired = false;

        try {
            $pdo = Database::connection();
            $lockStatement = $pdo->prepare('SELECT GET_LOCK(:lock_name, 0)');
            $lockStatement->execute(['lock_name' => $lockName]);
            $lockAcquired = (int) $lockStatement->fetchColumn() === 1;
            if (!$lockAcquired) {
                return ['status' => 'busy'];
            }

            $agentStatement = $pdo->prepare(
                'SELECT a.id, a.tenant_id, a.status, a.auto_reply_enabled, a.cooldown_seconds,
                        COALESCE(a.reply_to_reactions, 0) AS reply_to_reactions
                 FROM ai_agents a
                 INNER JOIN tenants t
                    ON t.id = a.tenant_id
                   AND t.status = "active"
                 WHERE a.id = :agent_id
                   AND a.tenant_id = :tenant_id
                 LIMIT 1'
            );
            $agentStatement->execute([
                'agent_id' => $agentId,
                'tenant_id' => $tenantId,
            ]);
            $agent = $agentStatement->fetch(PDO::FETCH_ASSOC);

            if (!$agent
                || (string) ($agent['status'] ?? '') !== 'active'
                || (int) ($agent['auto_reply_enabled'] ?? 0) !== 1
            ) {
                return ['status' => 'none'];
            }

            try {
                $tenantAccess = (new AccessControlService())->statusForTenant($tenantId);
                if (empty($tenantAccess['allowed'])) {
                    return ['status' => 'none'];
                }
            } catch (Throwable $exception) {
                return ['status' => 'error', 'error' => 'Não foi possível validar o acesso da empresa: ' . $exception->getMessage()];
            }

            $hasMessageLink = $this->hasColumn($pdo, 'ai_automation_logs', 'incoming_message_id');
            $legacyAgentSelectorSql = '(
                        SELECT aa.id
                        FROM ai_agents aa
                        WHERE aa.tenant_id = cm.tenant_id
                          AND aa.status = "active"
                          AND aa.auto_reply_enabled = 1
                          AND (
                                aa.instance_id = c.evolution_instance_id
                                OR aa.instance_id IS NULL
                                OR aa.is_default = 1
                          )
                        ORDER BY (aa.instance_id = c.evolution_instance_id) DESC,
                                 aa.is_default DESC,
                                 aa.id DESC
                        LIMIT 1
                   )';
            $agentSelectorSql = $this->hasColumn($pdo, 'conversations', 'ai_agent_id')
                ? 'COALESCE(c.ai_agent_id, ' . $legacyAgentSelectorSql . ')'
                : $legacyAgentSelectorSql;
            $queueMonitoringSql = $this->hasColumn($pdo, 'evolution_instances', 'operational_alerts_enabled')
                ? ' AND NOT EXISTS (
                        SELECT 1
                        FROM evolution_instances queue_i
                        WHERE queue_i.id = c.evolution_instance_id
                          AND queue_i.tenant_id = c.tenant_id
                          AND COALESCE(queue_i.operational_alerts_enabled, 1) = 0
                   )'
                : '';

            $candidateSql = $hasMessageLink
                ? 'SELECT cm.id AS message_id,
                        cm.conversation_id,
                        cm.content,
                        cm.message_type,
                        cm.sent_at,
                        c.contact_id,
                        c.evolution_instance_id
                 FROM conversation_messages cm
                 INNER JOIN conversations c
                    ON c.id = cm.conversation_id
                   AND c.tenant_id = cm.tenant_id
                 WHERE cm.tenant_id = :tenant_id
                   AND c.attendance_mode = "ai"
                   AND c.status <> "closed"
                   AND cm.direction = "incoming"'
                   . $queueMonitoringSql . '
                   AND (:reply_to_reactions = 1 OR cm.message_type <> "reaction")
                   AND ' . $agentSelectorSql . ' = :selected_agent_id
                   AND NOT EXISTS (
                        SELECT 1
                        FROM conversation_messages outgoing
                        WHERE outgoing.conversation_id = cm.conversation_id
                          AND outgoing.direction = "outgoing"
                          AND outgoing.status IN ("sent", "delivered", "read")
                          AND (
                                outgoing.sent_at > cm.sent_at
                                OR (outgoing.sent_at = cm.sent_at AND outgoing.id > cm.id)
                          )
                   )
                   AND (
                        COALESCE((
                            SELECT al.event
                            FROM ai_automation_logs al
                            WHERE al.incoming_message_id = cm.id
                            ORDER BY al.id DESC
                            LIMIT 1
                        ), "") IN ("ai.cooldown", "ai.failed", "ai.quota.blocked")
                        OR (
                            NOT EXISTS (
                                SELECT 1
                                FROM ai_automation_logs al_msg
                                WHERE al_msg.incoming_message_id = cm.id
                            )
                            AND cm.sent_at <= DATE_SUB(NOW(), INTERVAL 2 MINUTE)
                            AND (
                                COALESCE((
                                    SELECT al_legacy.event
                                    FROM ai_automation_logs al_legacy
                                    WHERE al_legacy.tenant_id = cm.tenant_id
                                      AND al_legacy.conversation_id = cm.conversation_id
                                      AND al_legacy.agent_id = :legacy_agent_id
                                      AND al_legacy.created_at >= cm.sent_at
                                    ORDER BY al_legacy.id DESC
                                    LIMIT 1
                                ), "") IN ("ai.cooldown", "ai.failed", "ai.quota.blocked")
                                OR NOT EXISTS (
                                    SELECT 1
                                    FROM ai_automation_logs al_missing
                                    WHERE al_missing.tenant_id = cm.tenant_id
                                      AND al_missing.conversation_id = cm.conversation_id
                                      AND al_missing.agent_id = :legacy_agent_id_missing
                                      AND al_missing.created_at >= cm.sent_at
                                )
                            )
                        )
                        OR EXISTS (
                            SELECT 1
                            FROM conversation_messages failed_outgoing
                            WHERE failed_outgoing.conversation_id = cm.conversation_id
                              AND failed_outgoing.direction = "outgoing"
                              AND failed_outgoing.sender_type = "ai"
                              AND failed_outgoing.status IN ("failed", "pending")
                              AND COALESCE((
                                    SELECT al_failed.event
                                    FROM ai_automation_logs al_failed
                                    WHERE al_failed.incoming_message_id = cm.id
                                    ORDER BY al_failed.id DESC
                                    LIMIT 1
                              ), "") IN ("", "ai.replied", "ai.failed")
                              AND (
                                    failed_outgoing.sent_at > cm.sent_at
                                    OR (failed_outgoing.sent_at = cm.sent_at AND failed_outgoing.id > cm.id)
                              )
                        )
                   )
                 ORDER BY cm.sent_at DESC, cm.id DESC
                 LIMIT 1'
                : 'SELECT cm.id AS message_id,
                        cm.conversation_id,
                        cm.content,
                        cm.message_type,
                        cm.sent_at,
                        c.contact_id,
                        c.evolution_instance_id
                 FROM conversation_messages cm
                 INNER JOIN conversations c
                    ON c.id = cm.conversation_id
                   AND c.tenant_id = cm.tenant_id
                 WHERE cm.tenant_id = :tenant_id
                   AND c.attendance_mode = "ai"
                   AND c.status <> "closed"
                   AND cm.direction = "incoming"'
                   . $queueMonitoringSql . '
                   AND (:reply_to_reactions = 1 OR cm.message_type <> "reaction")
                   AND ' . $agentSelectorSql . ' = :selected_agent_id
                   AND NOT EXISTS (
                        SELECT 1
                        FROM conversation_messages outgoing
                        WHERE outgoing.conversation_id = cm.conversation_id
                          AND outgoing.direction = "outgoing"
                          AND outgoing.status IN ("sent", "delivered", "read")
                          AND (
                                outgoing.sent_at > cm.sent_at
                                OR (outgoing.sent_at = cm.sent_at AND outgoing.id > cm.id)
                          )
                   )
                   AND (
                        COALESCE((
                            SELECT al.event
                            FROM ai_automation_logs al
                            WHERE al.tenant_id = cm.tenant_id
                              AND al.conversation_id = cm.conversation_id
                              AND al.agent_id = :event_agent_id
                              AND al.created_at >= cm.sent_at
                            ORDER BY al.id DESC
                            LIMIT 1
                        ), "") IN ("ai.cooldown", "ai.failed", "ai.quota.blocked")
                        OR (
                            NOT EXISTS (
                                SELECT 1
                                FROM ai_automation_logs al_missing
                                WHERE al_missing.tenant_id = cm.tenant_id
                                  AND al_missing.conversation_id = cm.conversation_id
                                  AND al_missing.agent_id = :missing_agent_id
                                  AND al_missing.created_at >= cm.sent_at
                            )
                            AND cm.sent_at <= DATE_SUB(NOW(), INTERVAL 2 MINUTE)
                        )
                        OR EXISTS (
                            SELECT 1
                            FROM conversation_messages failed_outgoing
                            WHERE failed_outgoing.conversation_id = cm.conversation_id
                              AND failed_outgoing.direction = "outgoing"
                              AND failed_outgoing.sender_type = "ai"
                              AND failed_outgoing.status IN ("failed", "pending")
                              AND (
                                    failed_outgoing.sent_at > cm.sent_at
                                    OR (failed_outgoing.sent_at = cm.sent_at AND failed_outgoing.id > cm.id)
                              )
                        )
                   )
                 ORDER BY cm.sent_at DESC, cm.id DESC
                 LIMIT 1';
            $candidateStatement = $pdo->prepare($candidateSql);
            $candidateParams = [
                'tenant_id' => $tenantId,
                'reply_to_reactions' => (int) ($agent['reply_to_reactions'] ?? 0),
                'selected_agent_id' => $agentId,
            ];
            if ($hasMessageLink) {
                $candidateParams['legacy_agent_id'] = $agentId;
                $candidateParams['legacy_agent_id_missing'] = $agentId;
            } else {
                $candidateParams['event_agent_id'] = $agentId;
                $candidateParams['missing_agent_id'] = $agentId;
            }
            $candidateStatement->execute($candidateParams);
            $candidate = $candidateStatement->fetch(PDO::FETCH_ASSOC);

            if (!$candidate || trim((string) ($candidate['content'] ?? '')) === '') {
                return ['status' => 'none'];
            }

            $alertSelect = $this->hasColumn($pdo, 'evolution_instances', 'operational_alerts_enabled')
                ? ', operational_alerts_enabled'
                : ', 1 AS operational_alerts_enabled';
            $instanceStatement = $pdo->prepare(
                'SELECT id, tenant_id, base_url, api_key_encrypted, instance_name, name, status, connection_state'
                . $alertSelect . '
                 FROM evolution_instances
                 WHERE id = :instance_id
                   AND tenant_id = :tenant_id
                 LIMIT 1'
            );
            $instanceStatement->execute([
                'instance_id' => (int) $candidate['evolution_instance_id'],
                'tenant_id' => $tenantId,
            ]);
            $instance = $instanceStatement->fetch(PDO::FETCH_ASSOC);
            if (!$instance) {
                return ['status' => 'error', 'error' => 'A conexão WhatsApp vinculada à conversa não foi encontrada.'];
            }
            if ((int) ($instance['operational_alerts_enabled'] ?? 1) !== 1) {
                // Pausa intencional: preserva a pendência sem consultar a Evolution e sem gerar nova falha.
                return ['status' => 'none'];
            }

            $instanceLabel = trim((string) (($instance['name'] ?? '') ?: ($instance['instance_name'] ?? '')));
            try {
                $live = $this->evolutionService($instance)->connectionState();
                $instanceState = strtolower(trim((string) ($live['state'] ?? '')));
                $this->updateEvolutionConnectionState($pdo, (int) $instance['id'], $instanceState);
            } catch (Throwable $stateException) {
                return [
                    'status' => 'blocked',
                    'conversation_id' => (int) $candidate['conversation_id'],
                    'message_id' => (int) $candidate['message_id'],
                    'event' => 'ai.blocked.instance_unverified',
                    'error' => 'Não foi possível confirmar o estado da Evolution ' . ($instanceLabel !== '' ? $instanceLabel : '#' . (int) $instance['id']) . ': ' . $stateException->getMessage(),
                ];
            }

            if (!in_array($instanceState, ['open', 'connected', 'active', 'online'], true)) {
                return [
                    'status' => 'blocked',
                    'conversation_id' => (int) $candidate['conversation_id'],
                    'message_id' => (int) $candidate['message_id'],
                    'event' => 'ai.blocked.instance_disconnected',
                    'error' => 'A Evolution informou estado “' . ($instanceState !== '' ? $instanceState : 'desconhecido') . '” para ' . ($instanceLabel !== '' ? $instanceLabel : '#' . (int) $instance['id']) . '. A pendência foi preservada até a conexão voltar.',
                ];
            }

            $bypassReplyWait = $source === 'manual';
            $replyWaitRemaining = (new AiReplyTimingService())->remainingForConversation(
                $pdo,
                (int) $candidate['conversation_id'],
                (int) ($agent['cooldown_seconds'] ?? 15)
            );

            $preScheduleResult = ['skip_ai' => false, 'handled' => false];
            if ($bypassReplyWait || $replyWaitRemaining <= 0) {
                $preScheduleResult = $this->processSchedulingDuringReprocess($pdo, $instance, $candidate);
            }

            $calendarError = trim((string) ($preScheduleResult['calendar_error'] ?? ''));
            if (!empty($preScheduleResult['scheduling_intent']) && $calendarError !== '') {
                // Falha fechada: se a camada determinística de agenda falhar, não deixa
                // o modelo livre continuar e afirmar disponibilidade/confirmação sem registro.
                $this->log(
                    $tenantId,
                    (int) $candidate['conversation_id'],
                    $agentId,
                    'ai.failed',
                    'error',
                    'Falha na camada determinística de agenda: ' . $calendarError,
                    null,
                    [
                        'failure_phase' => 'calendar.pre_schedule',
                        'incoming_message_id' => (int) $candidate['message_id'],
                        'calendar_fail_closed' => true,
                    ]
                );
                return [
                    'status' => 'error',
                    'conversation_id' => (int) $candidate['conversation_id'],
                    'message_id' => (int) $candidate['message_id'],
                    'event' => 'ai.failed',
                    'error' => 'A agenda não pôde ser processada com segurança: ' . $calendarError,
                ];
            }

            if (!((bool) ($preScheduleResult['skip_ai'] ?? false))) {
                $this->handleIncoming(
                    $instance,
                    (int) $candidate['conversation_id'],
                    (string) $candidate['content'],
                    [
                        'event' => 'ai.queue.reprocess.' . preg_replace('/[^a-z0-9_.-]+/i', '_', $source),
                        'bypass_cooldown' => $bypassReplyWait,
                        'message_id' => (int) $candidate['message_id'],
                        'stored_message_id' => (int) $candidate['message_id'],
                    ]
                );
            }

            $replyCheck = $pdo->prepare(
                'SELECT id
                 FROM conversation_messages
                 WHERE conversation_id = :conversation_id
                   AND direction = "outgoing"
                   AND status IN ("sent", "delivered", "read")
                   AND (
                        sent_at > :sent_at_after
                        OR (sent_at = :sent_at_equal AND id > :message_id)
                   )
                 ORDER BY sent_at DESC, id DESC
                 LIMIT 1'
            );
            $replyCheck->execute([
                'conversation_id' => (int) $candidate['conversation_id'],
                'sent_at_after' => (string) $candidate['sent_at'],
                'sent_at_equal' => (string) $candidate['sent_at'],
                'message_id' => (int) $candidate['message_id'],
            ]);

            if ($replyCheck->fetchColumn()) {
                return [
                    'status' => 'replied',
                    'conversation_id' => (int) $candidate['conversation_id'],
                    'message_id' => (int) $candidate['message_id'],
                ];
            }

            if ($hasMessageLink) {
                $attemptStatement = $pdo->prepare(
                    'SELECT event, status, error_message
                     FROM ai_automation_logs
                     WHERE incoming_message_id = :message_id
                     ORDER BY id DESC
                     LIMIT 1'
                );
                $attemptStatement->execute(['message_id' => (int) $candidate['message_id']]);
            } else {
                $attemptStatement = $pdo->prepare(
                    'SELECT event, status, error_message
                     FROM ai_automation_logs
                     WHERE tenant_id = :tenant_id
                       AND conversation_id = :conversation_id
                       AND agent_id = :agent_id
                       AND created_at >= :message_sent_at
                     ORDER BY id DESC
                     LIMIT 1'
                );
                $attemptStatement->execute([
                    'tenant_id' => $tenantId,
                    'conversation_id' => (int) $candidate['conversation_id'],
                    'agent_id' => $agentId,
                    'message_sent_at' => (string) $candidate['sent_at'],
                ]);
            }
            $attempt = $attemptStatement->fetch(PDO::FETCH_ASSOC) ?: [];
            $event = (string) ($attempt['event'] ?? '');

            if ($event === 'ai.failed' || (string) ($attempt['status'] ?? '') === 'error') {
                return [
                    'status' => 'error',
                    'conversation_id' => (int) $candidate['conversation_id'],
                    'message_id' => (int) $candidate['message_id'],
                    'event' => $event,
                    'error' => (string) ($attempt['error_message'] ?? 'A IA não conseguiu concluir a resposta.'),
                ];
            }

            if ($event === 'ai.cooldown') {
                return [
                    'status' => 'busy',
                    'conversation_id' => (int) $candidate['conversation_id'],
                    'message_id' => (int) $candidate['message_id'],
                    'event' => $event,
                ];
            }

            return [
                'status' => 'evaluated',
                'conversation_id' => (int) $candidate['conversation_id'],
                'message_id' => (int) $candidate['message_id'],
                'event' => $event,
            ];
        } catch (Throwable $exception) {
            return ['status' => 'error', 'error' => $exception->getMessage()];
        } finally {
            if ($lockAcquired && $pdo instanceof PDO) {
                try {
                    $releaseStatement = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
                    $releaseStatement->execute(['lock_name' => $lockName]);
                } catch (Throwable) {
                    // O lock também é liberado quando a conexão é encerrada.
                }
            }
        }
    }

    private function conversation(PDO $pdo, int $conversationId): ?array
    {
        try {
            $statement = $pdo->prepare(
                'SELECT c.*, ct.name, ct.phone, ct.email, ct.company, ct.notes, ct.tags_json,
                        ct.status AS contact_status,
                        COALESCE(NULLIF(ct.contact_group, ""), "unclassified") AS contact_group,
                        fs.stage AS flow_stage, fs.demand_status, fs.demand_summary,
                        fs.is_existing_patient, fs.last_intent
                 FROM conversations c
                 INNER JOIN contacts ct ON ct.id = c.contact_id
                 LEFT JOIN conversation_flow_states fs
                        ON fs.conversation_id = c.id AND fs.tenant_id = c.tenant_id
                 WHERE c.id = :id
                 LIMIT 1'
            );
            $statement->execute(['id' => $conversationId]);
            $conversation = $statement->fetch(PDO::FETCH_ASSOC);
            return $conversation ?: null;
        } catch (Throwable) {
            $statement = $pdo->prepare(
                'SELECT c.*, ct.name, ct.phone, ct.email, ct.company, ct.notes, ct.tags_json,
                        ct.status AS contact_status,
                        "unclassified" AS contact_group,
                        NULL AS flow_stage, NULL AS demand_status, NULL AS demand_summary,
                        0 AS is_existing_patient, NULL AS last_intent
                 FROM conversations c
                 INNER JOIN contacts ct ON ct.id = c.contact_id
                 WHERE c.id = :id
                 LIMIT 1'
            );
            $statement->execute(['id' => $conversationId]);
            $conversation = $statement->fetch(PDO::FETCH_ASSOC);
            return $conversation ?: null;
        }
    }

    private function conversationAllowsAutomaticReply(?array $conversation): bool
    {
        return is_array($conversation)
            && (string) ($conversation['attendance_mode'] ?? '') === 'ai'
            && (string) ($conversation['status'] ?? '') !== 'closed';
    }

    private function agentFor(PDO $pdo, array $instance, int $conversationId = 0, string $incomingContent = ''): ?array
    {
        return (new AgentRoutingService())->resolveForAutomation($pdo, $instance, $conversationId, $incomingContent, true);
    }

    private function recentMessages(PDO $pdo, int $conversationId, int $limit): array
    {
        $limit = max(4, min(30, $limit));
        $statement = $pdo->prepare(
            'SELECT * FROM (
                SELECT direction, sender_type, content, sent_at
                FROM conversation_messages
                WHERE conversation_id = :conversation_id
                  AND NOT (direction = "outgoing" AND status = "failed")
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
            $this->sendAutomatedMessage($pdo, $instance, $conversation, $conversationId, $message, 'ai.handoff.message', 'Mensagem de transferência enviada pela IA.', $agent);
        }

        $this->insertEvent($pdo, (int) $instance['tenant_id'], $conversationId, 'ai.handoff', 'IA pausada por palavra-chave de transferência.');
        $this->log((int) $instance['tenant_id'], $conversationId, (int) $agent['id'], 'ai.handoff', 'skipped', 'Palavra-chave de transferência detectada.', $message !== '' ? $message : null, null);
    }

    private function isInsideBusinessHours(array $agent): bool
    {
        return (new AgentOperatingPolicyService())->allowsConversationalAutomation($agent);
    }

    private function isStoredIncomingMessage(PDO $pdo, int $conversationId, int $messageId): bool
    {
        try {
            $statement = $pdo->prepare(
                'SELECT 1
                 FROM conversation_messages
                 WHERE id = :message_id
                   AND conversation_id = :conversation_id
                   AND direction = "incoming"
                 LIMIT 1'
            );
            $statement->execute([
                'message_id' => $messageId,
                'conversation_id' => $conversationId,
            ]);
            return (bool) $statement->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }

    private function releaseConversationLock(PDO $pdo, string $lockName): void
    {
        try {
            $releaseStatement = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
            $releaseStatement->execute(['lock_name' => $lockName]);
        } catch (Throwable) {
            // O lock também é liberado quando a conexão é encerrada.
        }
    }

    private function hasOutgoingAfterStoredMessage(PDO $pdo, int $conversationId, int $messageId): bool
    {
        $statement = $pdo->prepare(
            'SELECT cm.sent_at
             FROM conversation_messages cm
             WHERE cm.id = :message_id
               AND cm.conversation_id = :conversation_id
               AND cm.direction = "incoming"
             LIMIT 1'
        );
        $statement->execute([
            'message_id' => $messageId,
            'conversation_id' => $conversationId,
        ]);
        $sentAt = $statement->fetchColumn();
        if (!$sentAt) {
            return true;
        }

        $check = $pdo->prepare(
            'SELECT 1
             FROM conversation_messages outgoing
             WHERE outgoing.conversation_id = :conversation_id
               AND outgoing.direction = "outgoing"
               AND outgoing.status IN ("sent", "delivered", "read")
               AND (
                    outgoing.sent_at > :sent_at_after
                    OR (outgoing.sent_at = :sent_at_equal AND outgoing.id > :message_id)
               )
             LIMIT 1'
        );
        $check->execute([
            'conversation_id' => $conversationId,
            'sent_at_after' => (string) $sentAt,
            'sent_at_equal' => (string) $sentAt,
            'message_id' => $messageId,
        ]);

        return (bool) $check->fetchColumn();
    }

    /**
     * Reexecuta a camada de agenda somente quando a janela de interação já venceu.
     * Isso evita que mensagens como "quero agendar" recebam resposta fixa imediata
     * enquanto a IA geral ainda está aguardando os 60s configurados.
     */
    private function processSchedulingDuringReprocess(PDO $pdo, array $instance, array $candidate): array
    {
        $contactId = (int) ($candidate['contact_id'] ?? 0);
        $conversationId = (int) ($candidate['conversation_id'] ?? 0);
        $messageId = (int) ($candidate['message_id'] ?? 0);
        $content = trim((string) ($candidate['content'] ?? ''));
        if ($contactId < 1 || $conversationId < 1 || $content === '') {
            return ['skip_ai' => false, 'handled' => false];
        }

        // 36.27.20: o cooldown agrupa mensagens rápidas e apenas a última retomada
        // continua. Para a agenda isso não pode significar perda das mensagens anteriores
        // do mesmo bloco (ex.: "Online" seguido de "quinta às 14h"). Reconstituímos
        // somente as entradas consecutivas após a última saída do sistema e entregamos
        // esse bloco à máquina determinística da agenda. A IA livre continua usando o
        // conteúdo normal da conversa; este merge existe apenas para preservar estado.
        $calendarBurst = $this->calendarBurstForMessage($pdo, $conversationId, $messageId, $content);
        $calendarContent = (string) ($calendarBurst['content'] ?? $content);

        $intentProbe = (new PreSchedulingService())->detectIntent($calendarContent, false);
        $schedulingIntent = !empty($intentProbe['has_intent']);

        try {
            $flowContext = (new ConversationFlowService())->ingestIncoming(
                $pdo,
                $instance,
                $contactId,
                $conversationId,
                $calendarContent
            );

            $calendarSelection = (new CalendarConversationService())->handleIncomingSelection(
                $pdo,
                $instance,
                $contactId,
                $conversationId,
                $calendarContent,
                $messageId
            );
            $result = !empty($calendarSelection['handled'])
                ? $calendarSelection
                : (new PreSchedulingService())->handleIncoming(
                    $pdo,
                    $instance,
                    $contactId,
                    $conversationId,
                    $calendarContent,
                    $flowContext,
                    $messageId
                );
            $result['calendar_burst_message_ids'] = $calendarBurst['message_ids'] ?? [$messageId];
            $result['calendar_burst_count'] = count((array) ($calendarBurst['message_ids'] ?? [$messageId]));

            $appointmentEventPayload = $result['appointment_event_payload'] ?? null;
            if (is_array($appointmentEventPayload) && $appointmentEventPayload !== []) {
                try {
                    $this->automationWebhook->dispatch(
                        'appointment.pre_scheduled',
                        $appointmentEventPayload,
                        null,
                        (int) ($instance['tenant_id'] ?? 0)
                    );
                } catch (Throwable) {
                    // A resposta crítica já foi processada; integração externa fica observável pelos logs normais.
                }
            }

            if (!empty($result['availability_request_needed']) && (int) ($result['appointment_id'] ?? 0) > 0) {
                try {
                    $availabilityRequest = (new PreSchedulingService())->requestAvailabilityIfNeeded(
                        (int) ($instance['tenant_id'] ?? 0),
                        (int) $result['appointment_id']
                    );
                    $result['availability_request_result'] = $availabilityRequest;
                    if (empty($availabilityRequest['ok']) && empty($availabilityRequest['skipped'])) {
                        $result['calendar_error'] = (string) ($availabilityRequest['message'] ?? 'Falha ao iniciar a consulta de disponibilidade.');
                    }
                } catch (Throwable $exception) {
                    // Mantém a pendência de agenda para nova tentativa/diagnóstico.
                    $result['availability_request_result'] = [
                        'ok' => false,
                        'message' => $exception->getMessage(),
                    ];
                    $result['calendar_error'] = $exception->getMessage();
                }
            }

            $result['scheduling_intent'] = $schedulingIntent;
            return $result;
        } catch (Throwable $exception) {
            if ($schedulingIntent) {
                try {
                    $pdo->prepare(
                        'INSERT INTO conversation_events (tenant_id, conversation_id, event_type, description, metadata_json)
'
                        . 'VALUES (:tenant_id, :conversation_id, "calendar.pre_schedule_error", :description, :metadata_json)'
                    )->execute([
                        'tenant_id' => (int) ($instance['tenant_id'] ?? 0),
                        'conversation_id' => $conversationId,
                        'description' => 'Falha ao processar a intenção de agenda antes da resposta da IA.',
                        'metadata_json' => json_encode([
                            'incoming_message_id' => $messageId,
                            'error' => mb_substr($exception->getMessage(), 0, 1000),
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ]);
                } catch (Throwable) {
                    // Observabilidade não pode mascarar a falha original.
                }
            }

            return [
                'skip_ai' => false,
                'handled' => false,
                'scheduling_intent' => $schedulingIntent,
                'calendar_error' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @return array{content:string,message_ids:list<int>}
     */
    private function calendarBurstForMessage(PDO $pdo, int $conversationId, int $messageId, string $fallback): array
    {
        $fallback = trim($fallback);
        if ($conversationId < 1 || $messageId < 1) {
            return ['content' => $fallback, 'message_ids' => $messageId > 0 ? [$messageId] : []];
        }

        try {
            $lastOutgoing = $pdo->prepare(
                'SELECT COALESCE(MAX(id), 0)
'
                . 'FROM conversation_messages
'
                . 'WHERE conversation_id = :conversation_id
'
                . '  AND direction = "outgoing"
'
                . '  AND id < :message_id'
            );
            $lastOutgoing->execute([
                'conversation_id' => $conversationId,
                'message_id' => $messageId,
            ]);
            $afterId = (int) ($lastOutgoing->fetchColumn() ?: 0);

            $statement = $pdo->prepare(
                'SELECT id, content
'
                . 'FROM conversation_messages
'
                . 'WHERE conversation_id = :conversation_id
'
                . '  AND direction = "incoming"
'
                . '  AND id > :after_id
'
                . '  AND id <= :message_id
'
                . 'ORDER BY id ASC
'
                . 'LIMIT 8'
            );
            $statement->execute([
                'conversation_id' => $conversationId,
                'after_id' => $afterId,
                'message_id' => $messageId,
            ]);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $parts = [];
            $ids = [];
            foreach ($rows as $row) {
                $id = (int) ($row['id'] ?? 0);
                $part = trim((string) ($row['content'] ?? ''));
                if ($id < 1 || $part === '') {
                    continue;
                }
                $ids[] = $id;
                $parts[] = $part;
            }

            if ($parts === []) {
                return ['content' => $fallback, 'message_ids' => [$messageId]];
            }

            $merged = trim(implode("
", $parts));
            if (mb_strlen($merged) > 1200) {
                $merged = mb_substr($merged, -1200);
            }

            return [
                'content' => $merged !== '' ? $merged : $fallback,
                'message_ids' => $ids !== [] ? $ids : [$messageId],
            ];
        } catch (Throwable) {
            return ['content' => $fallback, 'message_ids' => [$messageId]];
        }
    }

    private function cooldownRemaining(PDO $pdo, int $conversationId, int $seconds): int
    {
        return (new AiReplyTimingService())->remainingForConversation($pdo, $conversationId, $seconds);
    }

    private function payloadMessageId(array $payload): ?string
    {
        $id = $payload['message_id']
            ?? $payload['data']['key']['id']
            ?? $payload['data']['id']
            ?? $payload['key']['id']
            ?? null;

        if (!is_scalar($id)) {
            return null;
        }

        $value = trim((string) $id);
        return $value !== '' ? $value : null;
    }

    private function sendAutomatedMessage(PDO $pdo, array $instance, array $conversation, int $conversationId, string $reply, string $eventType, string $eventDescription, ?array $agent = null): array
    {
        $service = $this->evolutionService($instance);
        $phone = preg_replace('/\D+/', '', (string) ($conversation['phone'] ?? '')) ?: '';
        if (strlen($phone) < 10 || strlen($phone) > 15) {
            throw new RuntimeException('Evolution sendText bloqueado: telefone do contato inválido ou incompleto.');
        }
        $senderDisplayName = $this->aiSenderDisplayName($pdo, (int) ($instance['tenant_id'] ?? 0), $conversationId, $agent);
        $deliveredReply = $this->withAiWhatsappSignature($reply, $senderDisplayName);

        try {
            $result = $service->sendText($phone, $deliveredReply);
        } catch (Throwable $exception) {
            $message = $exception->getMessage();
            if (!str_starts_with($message, 'Evolution ')) {
                $message = 'Evolution sendText: ' . $message;
            }
            $this->storeFailedAutomatedMessage(
                $pdo,
                (int) ($instance['tenant_id'] ?? 0),
                $conversationId,
                $reply,
                $message,
                $agent
            );
            if ($this->isClosedEvolutionConnectionError($message)) {
                $this->updateEvolutionConnectionState($pdo, (int) ($instance['id'] ?? 0), 'closed');
            }
            throw new RuntimeException($message, 0, $exception);
        }
        $externalId = $this->extractMessageId($result['body'] ?? []);
        $sentAt = \App\Core\Clock::nowUtc();

        $pdo->beginTransaction();
        if ($this->hasColumn($pdo, 'conversation_messages', 'sender_display_name')) {
            $insert = $pdo->prepare(
                'INSERT INTO conversation_messages
                    (tenant_id, conversation_id, evolution_message_id, direction, sender_type,
                     sender_display_name, message_type, content, status, raw_payload_json, sent_at)
                 VALUES
                    (:tenant_id, :conversation_id, :external_id, "outgoing", "ai",
                     :sender_display_name, "text", :content, "sent", :raw_payload, :sent_at)'
            );
            $insert->execute([
                'tenant_id' => $instance['tenant_id'],
                'conversation_id' => $conversationId,
                'external_id' => $externalId,
                'sender_display_name' => $senderDisplayName !== '' ? $senderDisplayName : null,
                'content' => $reply,
                'raw_payload' => json_encode($result['body'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'sent_at' => $sentAt,
            ]);
        } else {
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
        }
        $storedMessageId = (int) $pdo->lastInsertId();

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

        // O valor comercial é sincronizado somente depois que a resposta foi
        // efetivamente enviada e persistida, sem contaminar a transação de entrega.
        try {
            (new CrmDealValueService())->captureFromConversation(
                $pdo,
                (int) ($instance['tenant_id'] ?? 0),
                $conversationId,
                $reply,
                'ai'
            );
        } catch (Throwable) {
        }

        $result['_stored_message_id'] = $storedMessageId;
        return $result;
    }

    /** @return array<string,mixed>|null */
    private function failedAutomatedMessageAfterIncoming(PDO $pdo, int $conversationId, int $incomingMessageId): ?array
    {
        try {
            $statement = $pdo->prepare(
                'SELECT failed.id, failed.content, failed.sent_at, failed.error_message, failed.sender_display_name
                 FROM conversation_messages incoming
                 INNER JOIN conversation_messages failed
                    ON failed.conversation_id = incoming.conversation_id
                   AND failed.direction = "outgoing"
                   AND failed.sender_type = "ai"
                   AND failed.status = "failed"
                   AND (
                        failed.sent_at > incoming.sent_at
                        OR (failed.sent_at = incoming.sent_at AND failed.id > incoming.id)
                   )
                 WHERE incoming.id = :incoming_message_id
                   AND incoming.conversation_id = :conversation_id
                   AND incoming.direction = "incoming"
                 ORDER BY failed.id DESC
                 LIMIT 1'
            );
            $statement->execute([
                'incoming_message_id' => $incomingMessageId,
                'conversation_id' => $conversationId,
            ]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            return is_array($row) && trim((string) ($row['content'] ?? '')) !== '' ? $row : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string,mixed> $failedMessage */
    private function retryFailedAutomatedMessage(PDO $pdo, array $instance, array $conversation, int $conversationId, array $failedMessage): array
    {
        $phone = preg_replace('/\D+/', '', (string) ($conversation['phone'] ?? '')) ?: '';
        if (strlen($phone) < 10 || strlen($phone) > 15) {
            throw new RuntimeException('Evolution sendText bloqueado: telefone do contato inválido ou incompleto.');
        }

        $senderDisplayName = trim((string) ($failedMessage['sender_display_name'] ?? ''));
        if ($senderDisplayName === '') {
            $senderDisplayName = $this->aiSenderDisplayName(
                $pdo,
                (int) ($instance['tenant_id'] ?? 0),
                $conversationId,
                null
            );
        }
        $deliveredReply = $this->withAiWhatsappSignature((string) $failedMessage['content'], $senderDisplayName);

        try {
            $result = $this->evolutionService($instance)->sendText($phone, $deliveredReply);
        } catch (Throwable $exception) {
            $message = $exception->getMessage();
            if (!str_starts_with($message, 'Evolution ')) {
                $message = 'Evolution sendText: ' . $message;
            }
            $pdo->prepare(
                'UPDATE conversation_messages
                 SET error_message = :error_message,
                     raw_payload_json = :raw_payload
                 WHERE id = :id AND conversation_id = :conversation_id AND status = "failed"'
            )->execute([
                'error_message' => mb_substr($message, 0, 500),
                'raw_payload' => json_encode(['error' => $message, 'retry_failed_at' => \App\Core\Clock::nowUtc()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'id' => (int) $failedMessage['id'],
                'conversation_id' => $conversationId,
            ]);
            if ($this->isClosedEvolutionConnectionError($message)) {
                $this->updateEvolutionConnectionState($pdo, (int) ($instance['id'] ?? 0), 'closed');
            }
            throw new RuntimeException($message, 0, $exception);
        }

        $externalId = $this->extractMessageId($result['body'] ?? []);
        $sentAt = \App\Core\Clock::nowUtc();
        $pdo->beginTransaction();
        $pdo->prepare(
            'UPDATE conversation_messages
             SET evolution_message_id = :external_id,
                 status = "sent",
                 error_message = NULL,
                 raw_payload_json = :raw_payload,
                 sent_at = :sent_at
             WHERE id = :id AND conversation_id = :conversation_id AND status = "failed"'
        )->execute([
            'external_id' => $externalId,
            'raw_payload' => json_encode($result['body'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'sent_at' => $sentAt,
            'id' => (int) $failedMessage['id'],
            'conversation_id' => $conversationId,
        ]);
        $pdo->prepare(
            'UPDATE conversations
             SET last_message_at = :sent_at,
                 last_message_preview = :preview,
                 status = IF(status = "closed", "open", status)
             WHERE id = :id'
        )->execute([
            'sent_at' => $sentAt,
            'preview' => mb_substr((string) $failedMessage['content'], 0, 255),
            'id' => $conversationId,
        ]);
        $this->insertEvent($pdo, (int) $instance['tenant_id'], $conversationId, 'ai.delivery.retried', 'Resposta já gerada pela IA foi reenviada após a reconexão do WhatsApp.');
        $pdo->commit();

        try {
            (new CrmDealValueService())->captureFromConversation(
                $pdo,
                (int) ($instance['tenant_id'] ?? 0),
                $conversationId,
                (string) ($failedMessage['content'] ?? ''),
                'ai'
            );
        } catch (Throwable) {
        }

        $result['_stored_message_id'] = (int) $failedMessage['id'];
        return $result;
    }

    private function storeFailedAutomatedMessage(PDO $pdo, int $tenantId, int $conversationId, string $reply, string $error, ?array $agent = null): int
    {
        try {
            $incomingMessageId = (int) ($this->currentIncomingMessageId ?? 0);
            $existingSql = 'SELECT id
                 FROM conversation_messages
                 WHERE tenant_id = :tenant_id
                   AND conversation_id = :conversation_id
                   AND direction = "outgoing"
                   AND sender_type = "ai"
                   AND status = "failed"
                   AND content = :content';
            $existingParams = [
                'tenant_id' => $tenantId,
                'conversation_id' => $conversationId,
                'content' => $reply,
            ];
            if ($incomingMessageId > 0) {
                $existingSql .= ' AND id > :incoming_message_id';
                $existingParams['incoming_message_id'] = $incomingMessageId;
            }
            $existing = $pdo->prepare($existingSql . ' ORDER BY id DESC LIMIT 1');
            $existing->execute($existingParams);
            $existingId = (int) ($existing->fetchColumn() ?: 0);
            if ($existingId > 0) {
                $pdo->prepare(
                    'UPDATE conversation_messages
                     SET error_message = :error_message,
                         raw_payload_json = :raw_payload
                     WHERE id = :id'
                )->execute([
                    'error_message' => mb_substr($error, 0, 500),
                    'raw_payload' => json_encode(['error' => $error, 'failed_at' => \App\Core\Clock::nowUtc()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'id' => $existingId,
                ]);
                return $existingId;
            }

            $senderDisplayName = $this->aiSenderDisplayName($pdo, $tenantId, $conversationId, $agent);
            if ($this->hasColumn($pdo, 'conversation_messages', 'sender_display_name')) {
                $insert = $pdo->prepare(
                    'INSERT INTO conversation_messages
                        (tenant_id, conversation_id, evolution_message_id, direction, sender_type,
                         sender_display_name, message_type, content, status, error_message, raw_payload_json, sent_at)
                     VALUES
                        (:tenant_id, :conversation_id, NULL, "outgoing", "ai",
                         :sender_display_name, "text", :content, "failed", :error_message, :raw_payload, :sent_at)'
                );
                $insert->execute([
                    'tenant_id' => $tenantId,
                    'conversation_id' => $conversationId,
                    'sender_display_name' => $senderDisplayName !== '' ? $senderDisplayName : null,
                    'content' => $reply,
                    'error_message' => mb_substr($error, 0, 500),
                    'raw_payload' => json_encode(['error' => $error, 'failed_at' => \App\Core\Clock::nowUtc()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'sent_at' => \App\Core\Clock::nowUtc(),
                ]);
            } else {
                $insert = $pdo->prepare(
                    'INSERT INTO conversation_messages
                        (tenant_id, conversation_id, evolution_message_id, direction, sender_type,
                         message_type, content, status, error_message, raw_payload_json, sent_at)
                     VALUES
                        (:tenant_id, :conversation_id, NULL, "outgoing", "ai",
                         "text", :content, "failed", :error_message, :raw_payload, :sent_at)'
                );
                $insert->execute([
                    'tenant_id' => $tenantId,
                    'conversation_id' => $conversationId,
                    'content' => $reply,
                    'error_message' => mb_substr($error, 0, 500),
                    'raw_payload' => json_encode(['error' => $error, 'failed_at' => \App\Core\Clock::nowUtc()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'sent_at' => \App\Core\Clock::nowUtc(),
                ]);
            }
            return (int) $pdo->lastInsertId();
        } catch (Throwable) {
            return 0;
        }
    }

    private function aiAgentName(PDO $pdo, int $tenantId, int $agentId): string
    {
        if ($tenantId < 1 || $agentId < 1) {
            return '';
        }

        try {
            $statement = $pdo->prepare(
                'SELECT name FROM ai_agents WHERE id = :id AND tenant_id = :tenant_id LIMIT 1'
            );
            $statement->execute(['id' => $agentId, 'tenant_id' => $tenantId]);
            return trim((string) ($statement->fetchColumn() ?: ''));
        } catch (Throwable) {
            return '';
        }
    }

    private function aiSenderDisplayName(PDO $pdo, int $tenantId, int $conversationId, ?array $agent = null): string
    {
        $agentId = (int) ($agent['id'] ?? 0);
        $agentName = trim((string) ($agent['name'] ?? ''));
        $segment = trim((string) ($agent['segment'] ?? ''));
        $instanceId = 0;

        if ($tenantId > 0 && $conversationId > 0) {
            try {
                $conversationStatement = $pdo->prepare(
                    'SELECT evolution_instance_id, ai_agent_id
                     FROM conversations
                     WHERE id = :conversation_id AND tenant_id = :tenant_id
                     LIMIT 1'
                );
                $conversationStatement->execute([
                    'conversation_id' => $conversationId,
                    'tenant_id' => $tenantId,
                ]);
                $conversationRow = $conversationStatement->fetch(PDO::FETCH_ASSOC) ?: [];
                $instanceId = (int) ($conversationRow['evolution_instance_id'] ?? 0);
                if ($agentId < 1) {
                    $agentId = (int) ($conversationRow['ai_agent_id'] ?? 0);
                }
            } catch (Throwable) {
                $instanceId = 0;
            }
        }

        if ($agentId > 0 && ($agentName === '' || $segment === '')) {
            try {
                $agentStatement = $pdo->prepare(
                    'SELECT name, segment FROM ai_agents WHERE id = :id AND tenant_id = :tenant_id LIMIT 1'
                );
                $agentStatement->execute(['id' => $agentId, 'tenant_id' => $tenantId]);
                $row = $agentStatement->fetch(PDO::FETCH_ASSOC) ?: [];
                if ($agentName === '') {
                    $agentName = trim((string) ($row['name'] ?? ''));
                }
                if ($segment === '') {
                    $segment = trim((string) ($row['segment'] ?? ''));
                }
            } catch (Throwable) {
                // Mantém os dados já disponíveis no agente/conversa.
            }
        }

        if ($agentName === '') {
            return 'IA';
        }

        $isSpecialist = false;
        if ($tenantId > 0 && $instanceId > 0 && $agentId > 0) {
            try {
                $bindingStatement = $pdo->prepare(
                    'SELECT routing_keywords
                     FROM ai_agent_instance_bindings
                     WHERE tenant_id = :tenant_id
                       AND instance_id = :instance_id
                       AND agent_id = :agent_id
                       AND status = "active"
                     LIMIT 1'
                );
                $bindingStatement->execute([
                    'tenant_id' => $tenantId,
                    'instance_id' => $instanceId,
                    'agent_id' => $agentId,
                ]);
                $isSpecialist = trim((string) ($bindingStatement->fetchColumn() ?: '')) !== '';
            } catch (Throwable) {
                $isSpecialist = false;
            }
        }

        if ($isSpecialist) {
            $role = $segment !== '' ? $segment : 'Especialista';
            return 'IA ' . $role . ' - ' . $agentName;
        }

        return 'IA - ' . $agentName;
    }

    private function withAiWhatsappSignature(string $message, string $senderDisplayName): string
    {
        $message = trim($message);
        if ($message === '') {
            return $message;
        }

        $signature = trim($senderDisplayName);
        if ($signature === '') {
            $signature = 'IA';
        }

        $plainPrefix = preg_quote($signature, '/');
        if (preg_match('/^\*?' . $plainPrefix . '\*?\s*(?:\r?\n|$)/iu', $message) === 1) {
            return $message;
        }

        // Em conversas individuais o WhatsApp não possui campo separado de "atendente".
        // A primeira linha em negrito cria a identificação visual do assistente que
        // realmente enviou a resposta, sem contaminar o conteúdo armazenado no painel.
        return '*' . $signature . "*\n" . $message;
    }

    private function isClosedEvolutionConnectionError(string $message): bool
    {
        $normalized = mb_strtolower(trim($message));
        return str_contains($normalized, 'connection closed')
            || str_contains($normalized, 'connection is closed')
            || str_contains($normalized, 'socket closed')
            || str_contains($normalized, 'socket hang up')
            || str_contains($normalized, 'not connected')
            || str_contains($normalized, 'disconnected');
    }

    private function updateEvolutionConnectionState(PDO $pdo, int $instanceId, string $state): void
    {
        if ($instanceId < 1) {
            return;
        }
        $state = strtolower(trim($state));
        $connected = in_array($state, ['open', 'connected', 'active', 'online'], true);
        try {
            $pdo->prepare(
                'UPDATE evolution_instances
                 SET connection_state = :connection_state,
                     last_status_check_at = NOW(),
                     status = :status,
                     connected_at = CASE WHEN :mark_connected = 1 THEN COALESCE(connected_at, NOW()) ELSE connected_at END,
                     disconnected_at = CASE WHEN :mark_disconnected = 1 THEN NOW() ELSE disconnected_at END
                 WHERE id = :id'
            )->execute([
                'connection_state' => $state !== '' ? $state : 'unknown',
                'status' => $connected ? 'connected' : 'disconnected',
                'mark_connected' => $connected ? 1 : 0,
                'mark_disconnected' => $connected ? 0 : 1,
                'id' => $instanceId,
            ]);
        } catch (Throwable) {
            try {
                $pdo->prepare(
                    'UPDATE evolution_instances SET connection_state = :connection_state, last_status_check_at = NOW(), status = :status WHERE id = :id'
                )->execute([
                    'connection_state' => $state !== '' ? $state : 'unknown',
                    'status' => $connected ? 'connected' : 'disconnected',
                    'id' => $instanceId,
                ]);
            } catch (Throwable) {
                // Mantém o diagnóstico em memória mesmo em bases legadas.
            }
        }
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


    private function hasColumn(PDO $pdo, string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        try {
            $statement = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = :table_name
                   AND COLUMN_NAME = :column_name'
            );
            $statement->execute([
                'table_name' => $table,
                'column_name' => $column,
            ]);
            return $cache[$key] = (int) $statement->fetchColumn() > 0;
        } catch (Throwable) {
            return $cache[$key] = false;
        }
    }

    private function decodeContactTags(mixed $raw): array
    {
        if (is_array($raw)) {
            return array_values(array_filter(array_map('strval', $raw)));
        }
        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? array_values(array_filter(array_map('strval', $decoded))) : [];
    }

    private function nonReplyableRecipientReason(array $conversation): ?string
    {
        $remoteJid = strtolower(trim((string) ($conversation['remote_jid'] ?? '')));
        $phone = preg_replace('/\D+/', '', (string) ($conversation['phone'] ?? '')) ?: '';
        $name = strtolower(trim((string) ($conversation['name'] ?? '')));
        $compactName = preg_replace('/[^a-z0-9]+/u', '', $name) ?: '';

        if ($remoteJid !== '' && str_ends_with($remoteJid, '@lid')) {
            return 'O contato possui apenas um identificador interno LID e não tem número WhatsApp resolvido. Nenhuma nova tentativa será feita.';
        }
        foreach (['@g.us', 'status@broadcast', '@broadcast', '@newsletter', 'newsletter'] as $pattern) {
            if ($remoteJid !== '' && str_contains($remoteJid, $pattern)) {
                return 'O destinatário é um grupo, canal, status ou contato de sistema e não pode receber resposta automática individual.';
            }
        }
        if (str_contains($remoteJid, 'metaai') || str_contains($remoteJid, 'meta.ai')
            || in_array($compactName, ['metaai', 'iadameta', 'metaia'], true)) {
            return 'O contato pertence à Meta AI e não deve receber resposta automática da RS Connect.';
        }
        if ($phone === '' || strlen($phone) < 10 || strlen($phone) > 15) {
            return 'O contato não possui um telefone WhatsApp válido para resposta automática.';
        }

        return null;
    }

    private function isNonRetryableRecipientError(string $message): bool
    {
        $normalized = strtolower(trim($message));
        $compact = preg_replace('/\s+/', '', $normalized) ?: $normalized;

        return str_contains($compact, '"exists":false')
            || str_contains($compact, "'exists':false")
            || str_contains($normalized, 'number does not exist')
            || str_contains($normalized, 'number not found')
            || str_contains($normalized, 'número não existe')
            || str_contains($normalized, 'numero nao existe')
            || str_contains($normalized, 'jid não encontrado')
            || str_contains($normalized, 'jid nao encontrado');
    }

    private function friendlyAiFailure(string $error): string
    {
        $normalized = mb_strtolower($error);

        if (str_contains($normalized, 'evolution') || str_contains($normalized, 'sendtext') || str_contains($normalized, 'connection closed')) {
            return 'A resposta foi gerada, mas o WhatsApp perdeu a conexão. Reinicie ou reconecte a instância e depois use Reprocessar com IA; a resposta pendente será reaproveitada sem nova cobrança de tokens.';
        }
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
        $payload = [
            'tenant_id' => $tenantId,
            'conversation_id' => $conversationId,
            'agent_id' => $agentId,
            'incoming_message_id' => $this->currentIncomingMessageId,
            'event' => $event,
            'status' => $status,
            'response_preview' => $responsePreview !== null ? mb_substr($responsePreview, 0, 500) : null,
            'error_message' => $error !== null ? mb_substr($error, 0, 500) : null,
            'raw_json' => $raw !== null ? json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        ];

        try {
            Database::connection()->prepare(
                'INSERT INTO ai_automation_logs
                    (tenant_id, conversation_id, agent_id, incoming_message_id, event, status,
                     response_preview, error_message, raw_json)
                 VALUES
                    (:tenant_id, :conversation_id, :agent_id, :incoming_message_id, :event, :status,
                     :response_preview, :error_message, :raw_json)'
            )->execute($payload);
            return;
        } catch (Throwable) {
            // Compatibilidade temporária enquanto a migration 044 ainda não foi executada.
        }

        try {
            unset($payload['incoming_message_id']);
            Database::connection()->prepare(
                'INSERT INTO ai_automation_logs
                    (tenant_id, conversation_id, agent_id, event, status, response_preview, error_message, raw_json)
                 VALUES
                    (:tenant_id, :conversation_id, :agent_id, :event, :status, :response_preview, :error_message, :raw_json)'
            )->execute($payload);
        } catch (Throwable) {
            // Não interrompe webhook por falha de log.
        }
    }
}
