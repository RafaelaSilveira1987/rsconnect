<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Env;
use DateTimeImmutable;
use PDO;
use Throwable;

/** Preserva conversas recebidas fora do horário e retoma a demanda na próxima janela válida. */
final class AiAfterHoursRecoveryService
{
    /** @return array{pending_id:int,should_ack:bool} */
    public function markPending(PDO $pdo, int $tenantId, int $conversationId, int $agentId, ?int $messageId): array
    {
        if ($tenantId < 1 || $conversationId < 1 || $agentId < 1) {
            return ['pending_id' => 0, 'should_ack' => true];
        }

        try {
            $message = null;
            if ($messageId && $messageId > 0) {
                $st = $pdo->prepare('SELECT id, sent_at FROM conversation_messages WHERE id = :id AND conversation_id = :conversation_id AND direction = "incoming" LIMIT 1');
                $st->execute(['id' => $messageId, 'conversation_id' => $conversationId]);
                $message = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            if (!$message) {
                $st = $pdo->prepare('SELECT id, sent_at FROM conversation_messages WHERE conversation_id = :conversation_id AND direction = "incoming" ORDER BY sent_at DESC, id DESC LIMIT 1');
                $st->execute(['conversation_id' => $conversationId]);
                $message = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            }

            $resolvedMessageId = (int) ($message['id'] ?? 0) ?: null;
            $receivedAt = (string) ($message['sent_at'] ?? \App\Core\Clock::nowUtc());
            $businessTimezone = (string) Env::get('APP_TIMEZONE', 'America/Sao_Paulo');
            try {
                $timezoneStatement = $pdo->prepare('SELECT business_timezone FROM ai_agents WHERE id = :agent_id AND tenant_id = :tenant_id LIMIT 1');
                $timezoneStatement->execute(['agent_id' => $agentId, 'tenant_id' => $tenantId]);
                $configuredTimezone = trim((string) $timezoneStatement->fetchColumn());
                if ($configuredTimezone !== '') {
                    $businessTimezone = $configuredTimezone;
                }
            } catch (Throwable) {
                // Mantém o fuso padrão do app.
            }
            $existing = $pdo->prepare('SELECT * FROM ai_after_hours_pending WHERE conversation_id = :conversation_id LIMIT 1');
            $existing->execute(['conversation_id' => $conversationId]);
            $row = $existing->fetch(PDO::FETCH_ASSOC) ?: null;

            if (!$row) {
                $insert = $pdo->prepare(
                    'INSERT INTO ai_after_hours_pending
                        (tenant_id, conversation_id, agent_id, first_message_id, last_message_id,
                         first_received_at, last_received_at, status, next_attempt_at)
                     VALUES
                        (:tenant_id, :conversation_id, :agent_id, :message_id, :message_id_last,
                         :first_received_at, :last_received_at, "pending", NOW())'
                );
                $insert->execute([
                    'tenant_id' => $tenantId,
                    'conversation_id' => $conversationId,
                    'agent_id' => $agentId,
                    'message_id' => $resolvedMessageId,
                    'message_id_last' => $resolvedMessageId,
                    'first_received_at' => $receivedAt,
                    'last_received_at' => $receivedAt,
                ]);
                return ['pending_id' => (int) $pdo->lastInsertId(), 'should_ack' => true];
            }

            $status = (string) ($row['status'] ?? 'pending');
            $newWindow = in_array($status, ['recovered', 'cancelled'], true);
            $newLocalDay = (new AfterHoursAcknowledgementPolicyService())->shouldSend(
                isset($row['ack_sent_at']) ? (string) $row['ack_sent_at'] : null,
                $receivedAt,
                $businessTimezone
            );
            // 36.6.16: o aviso de ausência é deduplicado por DIA local do agente,
            // não por toda a janela fechada. Ex.: sábado e domingo podem receber
            // um aviso cada, mas várias mensagens no mesmo dia recebem só um.
            $shouldAck = $newWindow || $newLocalDay;

            $update = $pdo->prepare(
                'UPDATE ai_after_hours_pending
                 SET tenant_id = :tenant_id,
                     agent_id = :agent_id,
                     first_message_id = :first_message_id,
                     last_message_id = :last_message_id,
                     first_received_at = :first_received_at,
                     last_received_at = :last_received_at,
                     status = "pending",
                     ack_sent_at = :ack_sent_at,
                     next_attempt_at = NOW(),
                     recovered_at = NULL,
                     recovery_source = NULL,
                     last_error = NULL
                 WHERE id = :id'
            );
            $update->execute([
                'tenant_id' => $tenantId,
                'agent_id' => $agentId,
                'first_message_id' => $newWindow ? $resolvedMessageId : ($row['first_message_id'] ?? $resolvedMessageId),
                'last_message_id' => $resolvedMessageId,
                'first_received_at' => $newWindow ? $receivedAt : (string) ($row['first_received_at'] ?? $receivedAt),
                'last_received_at' => $receivedAt,
                'ack_sent_at' => ($newWindow || $newLocalDay) ? null : ($row['ack_sent_at'] ?? null),
                'id' => (int) $row['id'],
            ]);

            return ['pending_id' => (int) $row['id'], 'should_ack' => $shouldAck];
        } catch (Throwable) {
            // Antes da migration 052, mantém o comportamento antigo de mensagem de ausência.
            return ['pending_id' => 0, 'should_ack' => true];
        }
    }

    public function markAcknowledged(int $pendingId): void
    {
        if ($pendingId < 1) {
            return;
        }
        try {
            Database::connection()->prepare('UPDATE ai_after_hours_pending SET ack_sent_at = COALESCE(ack_sent_at, :ack_sent_at) WHERE id = :id')->execute([
                'ack_sent_at' => \App\Core\Clock::nowUtc(),
                'id' => $pendingId,
            ]);
        } catch (Throwable) {
        }
    }

    /** @return array<string,int|string> */
    public function recoverDue(int $limit = 25, string $source = 'operations_monitor'): array
    {
        $limit = max(1, min(200, $limit));
        $summary = [
            'status' => 'ok',
            'evaluated' => 0,
            'recovered' => 0,
            'blocked_plan' => 0,
            'blocked_human' => 0,
            'waiting_hours' => 0,
            'errors' => 0,
            'expired' => 0,
        ];

        try {
            $pdo = Database::connection();
            // Execuções interrompidas voltam para a fila depois de 30 minutos.
            $pdo->exec('UPDATE ai_after_hours_pending SET status = "error", next_attempt_at = NOW(), last_error = "Execução anterior interrompida." WHERE status = "processing" AND last_attempt_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)');

            $statement = $pdo->prepare(
                'SELECT p.*, c.attendance_mode, c.status AS conversation_status, c.evolution_instance_id,
                        a.status AS agent_status, a.auto_reply_enabled, a.business_hours_enabled,
                        a.business_timezone, a.business_hours_json
                 FROM ai_after_hours_pending p
                 INNER JOIN conversations c ON c.id = p.conversation_id AND c.tenant_id = p.tenant_id
                 LEFT JOIN ai_agents a ON a.id = p.agent_id AND a.tenant_id = p.tenant_id
                 WHERE p.status IN ("pending","blocked_plan","blocked_human","error")
                   AND (p.next_attempt_at IS NULL OR p.next_attempt_at <= NOW())
                 ORDER BY p.last_received_at ASC, p.id ASC
                 LIMIT ' . $limit
            );
            $statement->execute();
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($rows as $row) {
                $summary['evaluated']++;
                $id = (int) ($row['id'] ?? 0);
                if ($id < 1) {
                    continue;
                }

                $maxAgeHours = max(24, min(720, (int) Env::get('AI_AFTER_HOURS_MAX_AGE_HOURS', 168)));
                $lastReceivedTs = strtotime((string) ($row['last_received_at'] ?? '')) ?: 0;
                if ($lastReceivedTs > 0 && $lastReceivedTs < time() - ($maxAgeHours * 3600)) {
                    $this->finish($id, 'cancelled', 'Pendência expirada após ' . $maxAgeHours . ' horas sem recuperação automática.', null, $source);
                    $summary['expired']++;
                    continue;
                }

                if ((string) ($row['conversation_status'] ?? '') === 'closed') {
                    $this->finish($id, 'cancelled', 'Conversa encerrada antes da recuperação.', null, $source);
                    continue;
                }

                // Se alguém da equipe já respondeu a demanda, ela está resolvida mesmo que a conversa continue em modo humano.
                if ($this->humanRepliedAfter($pdo, (int) $row['conversation_id'], (string) $row['last_received_at'])) {
                    $this->finish($id, 'cancelled', 'A equipe respondeu manualmente antes da recuperação automática.', null, $source);
                    $summary['blocked_human']++;
                    continue;
                }

                if ((string) ($row['attendance_mode'] ?? '') !== 'ai') {
                    $this->defer($id, 'blocked_human', 'Atendimento está em modo humano/pausado.', '+15 minutes');
                    $summary['blocked_human']++;
                    continue;
                }

                if ((string) ($row['agent_status'] ?? '') !== 'active' || (int) ($row['auto_reply_enabled'] ?? 0) !== 1) {
                    $this->defer($id, 'blocked_human', 'Assistente está inativo ou com resposta automática desligada.', '+30 minutes');
                    $summary['blocked_human']++;
                    continue;
                }

                if (!$this->insideBusinessHours($row)) {
                    $this->defer($id, 'pending', null, '+15 minutes');
                    $summary['waiting_hours']++;
                    continue;
                }

                $lastMessageId = (int) ($row['last_message_id'] ?? 0);
                if ($lastMessageId < 1) {
                    $this->finish($id, 'error', 'Mensagem original da pendência não foi encontrada.', '+30 minutes', $source);
                    $summary['errors']++;
                    continue;
                }

                $content = $this->pendingConversationContent($pdo, $row);
                if ($content === '') {
                    $this->finish($id, 'error', 'As mensagens pendentes estão vazias ou indisponíveis.', '+30 minutes', $source);
                    $summary['errors']++;
                    continue;
                }

                $instanceStatement = $pdo->prepare('SELECT id, tenant_id, base_url, api_key_encrypted, instance_name, name, status, connection_state FROM evolution_instances WHERE id = :id AND tenant_id = :tenant_id LIMIT 1');
                $instanceStatement->execute(['id' => (int) $row['evolution_instance_id'], 'tenant_id' => (int) $row['tenant_id']]);
                $instance = $instanceStatement->fetch(PDO::FETCH_ASSOC);
                if (!$instance) {
                    $this->finish($id, 'error', 'Conexão WhatsApp da conversa não foi encontrada.', '+30 minutes', $source);
                    $summary['errors']++;
                    continue;
                }

                $attemptStartedAt = \App\Core\Clock::nowUtc();
                $pdo->prepare('UPDATE ai_after_hours_pending SET status = "processing", recovery_attempts = recovery_attempts + 1, last_attempt_at = :attempt_started_at, recovery_source = :source, last_error = NULL WHERE id = :id')
                    ->execute([
                        'attempt_started_at' => $attemptStartedAt,
                        'source' => mb_substr($source, 0, 80),
                        'id' => $id,
                    ]);

                (new AiAutomationService())->handleIncoming(
                    $instance,
                    (int) $row['conversation_id'],
                    $content,
                    [
                        'event' => 'ai.after_hours.recovery.' . preg_replace('/[^a-z0-9_.-]+/i', '_', $source),
                        // Recuperação automática respeita o tempo de espera do agente.
                        // Apenas uma ação manual explícita pode ignorar essa espera.
                        'bypass_cooldown' => false,
                        'after_hours_recovery' => true,
                        'message_id' => $lastMessageId,
                        'stored_message_id' => $lastMessageId,
                    ]
                );

                $attempt = $this->latestAttempt($pdo, $lastMessageId, (int) $row['conversation_id'], (int) $row['agent_id'], $attemptStartedAt);
                $event = (string) ($attempt['event'] ?? '');
                $status = (string) ($attempt['status'] ?? '');
                $error = trim((string) ($attempt['error_message'] ?? ''));

                if (($event === 'ai.replied' || $event === 'calendar.recovery.handled') && $status === 'success') {
                    $this->finish(
                        $id,
                        'recovered',
                        $event === 'calendar.recovery.handled'
                            ? 'A demanda pós-horário foi retomada pela Agenda e seguirá pelo ciclo de disponibilidade.'
                            : null,
                        null,
                        $source
                    );
                    $summary['recovered']++;
                    continue;
                }
                if ($event === 'ai.handoff') {
                    $this->finish($id, 'recovered', 'A demanda foi encaminhada para atendimento humano pela regra de transferência.', null, $source);
                    $summary['recovered']++;
                    continue;
                }
                if ($event === 'ai.cooldown') {
                    // Se a última mensagem chegou segundos antes da abertura, espera a
                    // janela de interação terminar e tenta novamente no próximo minuto.
                    $this->defer($id, 'pending', $error !== '' ? $error : 'Aguardando o tempo de espera configurado para a IA.', '+1 minute');
                    continue;
                }
                if ($event === 'ai.quota.blocked') {
                    $this->defer($id, 'blocked_plan', $error !== '' ? $error : 'Franquia de IA da RS Connect atingida.', '+1 hour');
                    $summary['blocked_plan']++;
                    continue;
                }
                if ($event === 'ai.recipient.unavailable') {
                    $this->finish(
                        $id,
                        'cancelled',
                        $error !== '' ? $error : 'Destinatário não respondível; recuperação encerrada sem novas tentativas.',
                        null,
                        $source
                    );
                    continue;
                }
                if ($event === 'ai.failed' || $status === 'error') {
                    $this->finish($id, 'error', $error !== '' ? $error : 'Falha ao recuperar a conversa.', '+15 minutes', $source);
                    $summary['errors']++;
                    continue;
                }

                $currentConversation = $pdo->prepare('SELECT attendance_mode FROM conversations WHERE id = :id LIMIT 1');
                $currentConversation->execute(['id' => (int) $row['conversation_id']]);
                if ((string) $currentConversation->fetchColumn() !== 'ai') {
                    $this->defer($id, 'blocked_human', 'Atendimento foi assumido durante a recuperação.', '+15 minutes');
                    $summary['blocked_human']++;
                    continue;
                }

                $this->defer($id, 'pending', $error !== '' ? $error : null, '+15 minutes');
            }
        } catch (Throwable $exception) {
            $summary['status'] = 'warning';
            $summary['errors']++;
        }

        if ($summary['errors'] > 0) {
            $summary['status'] = 'warning';
        }
        return $summary;
    }

    /** @return array<int,array<string,mixed>> */
    public function pendingItems(int $limit = 40): array
    {
        $limit = max(1, min(100, $limit));
        try {
            $statement = Database::connection()->query(
                'SELECT p.*, t.name AS tenant_name, a.name AS agent_name,
                        c.attendance_mode, c.status AS conversation_status,
                        ct.name AS contact_name, ct.phone AS contact_phone
                 FROM ai_after_hours_pending p
                 INNER JOIN tenants t ON t.id = p.tenant_id
                 INNER JOIN conversations c ON c.id = p.conversation_id
                 LEFT JOIN contacts ct ON ct.id = c.contact_id
                 LEFT JOIN ai_agents a ON a.id = p.agent_id
                 WHERE p.status IN ("pending","processing","blocked_plan","blocked_human","error")
                 ORDER BY p.last_received_at ASC, p.id ASC
                 LIMIT ' . $limit
            );
            return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    public function pendingCounts(): array
    {
        try {
            $row = Database::connection()->query(
                'SELECT COUNT(*) AS total,
                        SUM(status = "blocked_plan") AS blocked_plan,
                        SUM(status = "blocked_human") AS blocked_human,
                        SUM(status = "error") AS errors
                 FROM ai_after_hours_pending
                 WHERE status IN ("pending","processing","blocked_plan","blocked_human","error")'
            )->fetch(PDO::FETCH_ASSOC) ?: [];
            return [
                'total' => (int) ($row['total'] ?? 0),
                'blocked_plan' => (int) ($row['blocked_plan'] ?? 0),
                'blocked_human' => (int) ($row['blocked_human'] ?? 0),
                'errors' => (int) ($row['errors'] ?? 0),
            ];
        } catch (Throwable) {
            return ['total' => 0, 'blocked_plan' => 0, 'blocked_human' => 0, 'errors' => 0];
        }
    }

    /**
     * Reúne todas as mensagens recebidas na mesma janela fora do horário.
     * Isso preserva pedidos fragmentados como "quero agendar" + "quarta 13h" + "online"
     * para que a máquina de Agenda reconstrua a intenção antes de chamar a IA geral.
     */
    private function pendingConversationContent(PDO $pdo, array $row): string
    {
        $conversationId = (int) ($row['conversation_id'] ?? 0);
        $firstMessageId = (int) ($row['first_message_id'] ?? 0);
        $lastMessageId = (int) ($row['last_message_id'] ?? 0);
        if ($conversationId < 1 || $lastMessageId < 1) {
            return '';
        }

        try {
            if ($firstMessageId > 0 && $firstMessageId <= $lastMessageId) {
                $statement = $pdo->prepare(
                    'SELECT content
                     FROM conversation_messages
                     WHERE conversation_id = :conversation_id
                       AND direction = "incoming"
                       AND id BETWEEN :first_message_id AND :last_message_id
                     ORDER BY sent_at ASC, id ASC
                     LIMIT 30'
                );
                $statement->execute([
                    'conversation_id' => $conversationId,
                    'first_message_id' => $firstMessageId,
                    'last_message_id' => $lastMessageId,
                ]);
                $parts = [];
                foreach ($statement->fetchAll(PDO::FETCH_COLUMN) ?: [] as $message) {
                    $message = trim((string) $message);
                    if ($message !== '') {
                        $parts[] = $message;
                    }
                }
                if ($parts !== []) {
                    return implode("\n", $parts);
                }
            }

            $statement = $pdo->prepare(
                'SELECT content
                 FROM conversation_messages
                 WHERE id = :id
                   AND conversation_id = :conversation_id
                   AND direction = "incoming"
                 LIMIT 1'
            );
            $statement->execute(['id' => $lastMessageId, 'conversation_id' => $conversationId]);
            return trim((string) $statement->fetchColumn());
        } catch (Throwable) {
            return '';
        }
    }

    private function humanRepliedAfter(PDO $pdo, int $conversationId, string $after): bool
    {
        $statement = $pdo->prepare(
            'SELECT 1 FROM conversation_messages
             WHERE conversation_id = :conversation_id
               AND direction = "outgoing"
               AND sender_type = "user"
               AND sent_at > :after_at
               AND status IN ("sent","delivered","read")
             LIMIT 1'
        );
        $statement->execute(['conversation_id' => $conversationId, 'after_at' => $after]);
        return (bool) $statement->fetchColumn();
    }

    private function insideBusinessHours(array $agent): bool
    {
        // Usa a mesma fonte de verdade do webhook, IA e agenda. Assim a
        // recuperação nunca interpreta dias/horários de forma diferente.
        return (new AgentOperatingPolicyService())->allowsConversationalAutomation($agent);
    }

    private function latestAttempt(PDO $pdo, int $messageId, int $conversationId, int $agentId, string $fallbackAfter): array
    {
        try {
            $statement = $pdo->prepare('SELECT event, status, error_message FROM ai_automation_logs WHERE incoming_message_id = :message_id ORDER BY id DESC LIMIT 1');
            $statement->execute(['message_id' => $messageId]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return $row;
            }
        } catch (Throwable) {
        }

        try {
            $statement = $pdo->prepare(
                'SELECT event, status, error_message FROM ai_automation_logs
                 WHERE conversation_id = :conversation_id AND agent_id = :agent_id
                   AND created_at >= :after_at
                 ORDER BY id DESC LIMIT 1'
            );
            $statement->execute([
                'conversation_id' => $conversationId,
                'agent_id' => $agentId,
                'after_at' => $fallbackAfter !== '' ? $fallbackAfter : date('Y-m-d H:i:s', time() - 120),
            ]);
            return $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    private function defer(int $id, string $status, ?string $error, string $relative): void
    {
        $next = (new DateTimeImmutable('now'))->modify($relative)->format('Y-m-d H:i:s');
        try {
            Database::connection()->prepare(
                'UPDATE ai_after_hours_pending
                 SET status = :status, next_attempt_at = :next_attempt_at, last_error = :last_error
                 WHERE id = :id'
            )->execute([
                'status' => $status,
                'next_attempt_at' => $next,
                'last_error' => $error !== null ? mb_substr($error, 0, 500) : null,
                'id' => $id,
            ]);
        } catch (Throwable) {
        }
    }

    private function finish(int $id, string $status, ?string $error, ?string $retryRelative, string $source): void
    {
        if ($retryRelative !== null) {
            $this->defer($id, $status, $error, $retryRelative);
            return;
        }
        try {
            Database::connection()->prepare(
                'UPDATE ai_after_hours_pending
                 SET status = :status,
                     recovered_at = CASE WHEN :is_recovered = 1 THEN NOW() ELSE recovered_at END,
                     next_attempt_at = NULL,
                     recovery_source = :source,
                     last_error = :last_error
                 WHERE id = :id'
            )->execute([
                'status' => $status,
                'is_recovered' => $status === 'recovered' ? 1 : 0,
                'source' => mb_substr($source, 0, 80),
                'last_error' => $error !== null ? mb_substr($error, 0, 500) : null,
                'id' => $id,
            ]);
        } catch (Throwable) {
        }
    }
}
