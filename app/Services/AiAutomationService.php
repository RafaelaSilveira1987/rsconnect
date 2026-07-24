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

    public function handleIncoming(array $instance, int $conversationId, string $incomingContent, array $payload): void
    {
        $candidateMessageId = $payload['stored_message_id'] ?? $payload['message_id'] ?? null;
        $this->currentIncomingMessageId = is_numeric($candidateMessageId) && (int) $candidateMessageId > 0
            ? (int) $candidateMessageId
            : null;

        $pdo = null;
        $agent = null;
        $failurePhase = 'bootstrap';

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

            $storedMessageId = (int) ($this->currentIncomingMessageId ?? 0);
            $isFreshPersistedIncoming = $storedMessageId > 0
                && $this->isStoredIncomingMessage($pdo, $conversationId, $storedMessageId);

            // A proteção contra duplicidade vale para toda execução, não apenas para o reprocessamento.
            // Assim, um webhook repetido nunca gera uma segunda saída para a mesma mensagem recebida.
            if ($storedMessageId > 0 && $this->hasOutgoingAfterStoredMessage($pdo, $conversationId, $storedMessageId)) {
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

            $cooldownSeconds = max(0, min(3600, (int) ($agent['cooldown_seconds'] ?? 15)));
            $remainingSeconds = $this->cooldownRemaining($pdo, $conversationId, $cooldownSeconds);

            // O intervalo continua protegendo execuções legadas/sem vínculo e chamadas repetidas,
            // mas não descarta uma nova mensagem legítima já persistida e identificada no banco.
            // Essa mensagem passa pela trava da conversa e pela checagem de resposta posterior acima.
            $cooldownApplies = !$bypassCooldown && !$isFreshPersistedIncoming;
            if ($cooldownApplies && $remainingSeconds > 0) {
                $this->log(
                    (int) $instance['tenant_id'],
                    $conversationId,
                    (int) $agent['id'],
                    'ai.cooldown',
                    'skipped',
                    'Mensagem aguardando o intervalo mínimo configurado antes da próxima resposta.',
                    null,
                    [
                        'pending_reprocess' => true,
                        'cooldown_seconds' => $cooldownSeconds,
                        'remaining_seconds' => $remainingSeconds,
                        'incoming_message_id' => $this->payloadMessageId($payload),
                    ]
                );
                return;
            }

            if ($this->shouldHandoff($incomingContent, (string) ($agent['handoff_keywords'] ?? ''))) {
                $this->handoff($pdo, $instance, $conversation, $agent, $conversationId);
                return;
            }

            if (!$this->isInsideBusinessHours($agent)) {
                $afterHoursMessage = trim((string) ($agent['after_hours_message'] ?? ''));
                if ($afterHoursMessage !== '') {
                    $conversation = $this->conversation($pdo, $conversationId);
                    if (!$this->conversationAllowsAutomaticReply($conversation)) {
                        $this->log((int) $instance['tenant_id'], $conversationId, (int) $agent['id'], 'ai.skipped', 'skipped', 'Atendimento assumido ou IA pausada antes do envio automático.', null, ['takeover_guard' => true]);
                        return;
                    }
                    $this->sendAutomatedMessage($pdo, $instance, $conversation, $conversationId, $afterHoursMessage, 'ai.after_hours', 'Mensagem fora do horário enviada pela IA.');
                    $this->log((int) $instance['tenant_id'], $conversationId, (int) $agent['id'], 'ai.after_hours', 'success', null, $afterHoursMessage, null);
                    return;
                }

                $this->log((int) $instance['tenant_id'], $conversationId, (int) $agent['id'], 'ai.after_hours', 'skipped', 'Fora do horário de atendimento e sem mensagem configurada.', null, null);
                return;
            }

            $messages = $this->recentMessages($pdo, $conversationId, (int) ($agent['max_context_messages'] ?? 12));
            $failurePhase = 'ai.generate';
            $reply = $this->ai->generateReply($agent, $messages, $conversation, $conversation);

            // O atendente pode assumir a conversa enquanto o provedor de IA está gerando a resposta.
            // Revalida imediatamente antes do envio externo para que assumir atendimento pause a IA de fato.
            $conversation = $this->conversation($pdo, $conversationId);
            if (!$this->conversationAllowsAutomaticReply($conversation)) {
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
            $result = $this->sendAutomatedMessage($pdo, $instance, $conversation, $conversationId, $reply, 'ai.replied', 'Resposta automática enviada pela IA.');

            $this->log((int) $instance['tenant_id'], $conversationId, (int) $agent['id'], 'ai.replied', 'success', null, $reply, [
                'http_status' => $result['status'] ?? null,
                'external_id' => $this->extractMessageId($result['body'] ?? []),
                'provider' => $agent['credential_provider'] ?? $agent['model_provider'] ?? null,
                'credential_id' => $agent['credential_id'] ?? null,
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
            if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $tenantId = (int) $instance['tenant_id'];
            $agentId = isset($agent['id']) ? (int) $agent['id'] : null;
            $this->log($tenantId, $conversationId, $agentId, 'ai.failed', 'error', $exception->getMessage(), null, [
                'payload_event' => $payload['event'] ?? null,
                'failure_phase' => $failurePhase,
                'instance_id' => (int) ($instance['id'] ?? 0),
                'instance_name' => (string) ($instance['instance_name'] ?? ''),
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
                'SELECT a.id, a.tenant_id, a.status, a.auto_reply_enabled,
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

            $candidateSql = $hasMessageLink
                ? 'SELECT cm.id AS message_id,
                        cm.conversation_id,
                        cm.content,
                        cm.message_type,
                        cm.sent_at,
                        c.evolution_instance_id
                 FROM conversation_messages cm
                 INNER JOIN conversations c
                    ON c.id = cm.conversation_id
                   AND c.tenant_id = cm.tenant_id
                 WHERE cm.tenant_id = :tenant_id
                   AND c.attendance_mode = "ai"
                   AND c.status <> "closed"
                   AND cm.direction = "incoming"
                   AND (:reply_to_reactions = 1 OR cm.message_type <> "reaction")
                   AND (
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
                   ) = :selected_agent_id
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
                        ), "") IN ("ai.cooldown", "ai.failed")
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
                                ), "") IN ("ai.cooldown", "ai.failed")
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
                        c.evolution_instance_id
                 FROM conversation_messages cm
                 INNER JOIN conversations c
                    ON c.id = cm.conversation_id
                   AND c.tenant_id = cm.tenant_id
                 WHERE cm.tenant_id = :tenant_id
                   AND c.attendance_mode = "ai"
                   AND c.status <> "closed"
                   AND cm.direction = "incoming"
                   AND (:reply_to_reactions = 1 OR cm.message_type <> "reaction")
                   AND (
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
                   ) = :selected_agent_id
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
                        ), "") IN ("ai.cooldown", "ai.failed")
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

            $instanceStatement = $pdo->prepare(
                'SELECT id, tenant_id, base_url, api_key_encrypted, instance_name, name, status, connection_state
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

            $this->handleIncoming(
                $instance,
                (int) $candidate['conversation_id'],
                (string) $candidate['content'],
                [
                    'event' => 'ai.queue.reprocess.' . preg_replace('/[^a-z0-9_.-]+/i', '_', $source),
                    'bypass_cooldown' => true,
                    'message_id' => (int) $candidate['message_id'],
                    'stored_message_id' => (int) $candidate['message_id'],
                ]
            );

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

    private function cooldownRemaining(PDO $pdo, int $conversationId, int $seconds): int
    {
        $seconds = max(0, min(3600, $seconds));
        if ($seconds === 0) {
            return 0;
        }

        $statement = $pdo->prepare(
            'SELECT sent_at
             FROM conversation_messages
             WHERE conversation_id = :conversation_id
               AND direction = "outgoing"
               AND sender_type = "ai"
               AND status IN ("sent", "delivered", "read")
             ORDER BY sent_at DESC, id DESC
             LIMIT 1'
        );
        $statement->execute(['conversation_id' => $conversationId]);
        $last = $statement->fetchColumn();
        if (!$last) {
            return 0;
        }

        $elapsed = max(0, time() - strtotime((string) $last));
        return max(0, $seconds - $elapsed);
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

    private function sendAutomatedMessage(PDO $pdo, array $instance, array $conversation, int $conversationId, string $reply, string $eventType, string $eventDescription): array
    {
        $service = $this->evolutionService($instance);
        $phone = preg_replace('/\D+/', '', (string) ($conversation['phone'] ?? '')) ?: '';
        if (strlen($phone) < 10) {
            throw new RuntimeException('Evolution sendText bloqueado: telefone do contato inválido ou incompleto.');
        }
        try {
            $result = $service->sendText($phone, $reply);
        } catch (Throwable $exception) {
            $message = $exception->getMessage();
            if (!str_starts_with($message, 'Evolution ')) {
                $message = 'Evolution sendText: ' . $message;
            }
            throw new RuntimeException($message, 0, $exception);
        }
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
                     connected_at = CASE WHEN :is_connected = 1 THEN COALESCE(connected_at, NOW()) ELSE connected_at END,
                     disconnected_at = CASE WHEN :is_connected = 0 THEN NOW() ELSE disconnected_at END
                 WHERE id = :id'
            )->execute([
                'connection_state' => $state !== '' ? $state : 'unknown',
                'status' => $connected ? 'connected' : 'disconnected',
                'is_connected' => $connected ? 1 : 0,
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
