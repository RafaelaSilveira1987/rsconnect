<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use Throwable;

/**
 * Fonte de verdade do consumo comercial de IA.
 *
 * Regra 36.6.8:
 * - somente resposta automática efetivamente enviada conta como interação do plano;
 * - credencial custeada pela RS Connect consome franquia;
 * - credencial própria do cliente é registrada, mas não consome franquia RS;
 * - mensagens recebidas, respostas humanas e mensagens fixas de automação não contam.
 */
final class AiUsageService
{
    /** @return array{allowed:bool,event_id:int,owner:string,billable:bool,used:int,limit:?int,message:string} */
    public function reserveAutoReply(int $tenantId, array $agent, int $conversationId, ?int $incomingMessageId = null): array
    {
        $owner = $this->credentialOwner($agent);
        $billable = $owner === 'rs_connect';
        $provider = $this->provider($agent);
        $model = $this->model($agent);
        $credentialId = (int) ($agent['credential_id'] ?? 0);

        if ($tenantId < 1) {
            return ['allowed' => true, 'event_id' => 0, 'owner' => $owner, 'billable' => $billable, 'used' => 0, 'limit' => null, 'message' => 'Empresa não identificada; controle de franquia ignorado.'];
        }

        $pdo = Database::connection();
        $lockName = mb_substr('rs_ai_quota_tenant_' . $tenantId, 0, 64);
        $locked = false;

        try {
            $limit = null;
            $used = 0;

            // Credencial própria do cliente não depende da franquia RS e não deve ser
            // interrompida por contenção da trava comercial de outra execução.
            if ($billable) {
                $lock = $pdo->prepare('SELECT GET_LOCK(:lock_name, 5)');
                $lock->execute(['lock_name' => $lockName]);
                $locked = (int) $lock->fetchColumn() === 1;
                if (!$locked) {
                    return ['allowed' => false, 'event_id' => 0, 'owner' => $owner, 'billable' => true, 'used' => 0, 'limit' => null, 'message' => 'Não foi possível reservar a franquia da IA agora. A mensagem foi preservada para nova tentativa.'];
                }

                $subscription = new SubscriptionService();
                $plan = $subscription->currentPlanForTenant($tenantId);
                $period = $subscription->usagePeriodForTenant($tenantId);
                $limit = $plan['limits']['ai_interactions_month']
                    ?? $plan['limits']['messages_month']
                    ?? $plan['limits']['ai_replies_month']
                    ?? null;
                $limit = $limit === null ? null : max(0, (int) $limit);

                // Uma execução interrompida não pode consumir franquia para sempre.
                // Reservas antigas são liberadas antes de calcular a disponibilidade do ciclo.
                $pdo->prepare(
                    'UPDATE ai_usage_events
                     SET status = "failed", completed_at = NOW(), error_message = COALESCE(error_message, "Reserva expirada antes da confirmação do envio.")
                     WHERE tenant_id = :tenant_id
                       AND usage_type = "auto_reply"
                       AND status = "reserved"
                       AND created_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)'
                )->execute(['tenant_id' => $tenantId]);

                $statement = $pdo->prepare(
                    'SELECT COUNT(*)
                     FROM ai_usage_events
                     WHERE tenant_id = :tenant_id
                       AND usage_type = "auto_reply"
                       AND plan_billable = 1
                       AND status IN ("reserved", "success")
                       AND created_at BETWEEN :start_at AND :end_at'
                );
                $statement->execute([
                    'tenant_id' => $tenantId,
                    'start_at' => $period['start_at'],
                    'end_at' => $period['end_at'],
                ]);
                $used = (int) $statement->fetchColumn();

                if ($limit !== null && $used >= $limit) {
                    $this->notifyThreshold($tenantId, 100, $used, $limit, $period);
                    return [
                        'allowed' => false,
                        'event_id' => 0,
                        'owner' => $owner,
                        'billable' => true,
                        'used' => $used,
                        'limit' => $limit,
                        'message' => 'A franquia de IA da RS Connect foi atingida. A conversa permanece preservada; atendimento humano e credenciais próprias continuam disponíveis.',
                    ];
                }
            }

            $insert = $pdo->prepare(
                'INSERT INTO ai_usage_events
                    (tenant_id, agent_id, conversation_id, incoming_message_id, credential_id,
                     credential_owner, provider, model, usage_type, status, plan_billable)
                 VALUES
                    (:tenant_id, :agent_id, :conversation_id, :incoming_message_id, :credential_id,
                     :credential_owner, :provider, :model, "auto_reply", "reserved", :plan_billable)'
            );
            $insert->execute([
                'tenant_id' => $tenantId,
                'agent_id' => (int) ($agent['id'] ?? 0) ?: null,
                'conversation_id' => $conversationId > 0 ? $conversationId : null,
                'incoming_message_id' => $incomingMessageId && $incomingMessageId > 0 ? $incomingMessageId : null,
                'credential_id' => $credentialId > 0 ? $credentialId : null,
                'credential_owner' => $owner,
                'provider' => $provider,
                'model' => $model !== '' ? $model : null,
                'plan_billable' => $billable ? 1 : 0,
            ]);

            return [
                'allowed' => true,
                'event_id' => (int) $pdo->lastInsertId(),
                'owner' => $owner,
                'billable' => $billable,
                'used' => $used,
                'limit' => $limit,
                'message' => 'Interação reservada.',
            ];
        } catch (Throwable) {
            // Durante uma implantação sem a migration 052, não interrompe o atendimento.
            return ['allowed' => true, 'event_id' => 0, 'owner' => $owner, 'billable' => $billable, 'used' => 0, 'limit' => null, 'message' => 'Controle de consumo ainda não disponível.'];
        } finally {
            if ($locked) {
                try {
                    $release = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
                    $release->execute(['lock_name' => $lockName]);
                } catch (Throwable) {
                }
            }
        }
    }

    /** @param array<string,mixed> $usage */
    public function completeAutoReply(int $eventId, ?int $outgoingMessageId = null, array $usage = []): void
    {
        if ($eventId < 1) {
            return;
        }

        try {
            $pdo = Database::connection();
            $pdo->prepare(
                'UPDATE ai_usage_events
                 SET status = "success",
                     outgoing_message_id = :outgoing_message_id,
                     input_tokens = :input_tokens,
                     output_tokens = :output_tokens,
                     completed_at = NOW(),
                     error_message = NULL
                 WHERE id = :id AND status = "reserved"'
            )->execute([
                'outgoing_message_id' => $outgoingMessageId && $outgoingMessageId > 0 ? $outgoingMessageId : null,
                'input_tokens' => isset($usage['input_tokens']) && $usage['input_tokens'] !== null ? max(0, (int) $usage['input_tokens']) : null,
                'output_tokens' => isset($usage['output_tokens']) && $usage['output_tokens'] !== null ? max(0, (int) $usage['output_tokens']) : null,
                'id' => $eventId,
            ]);

            $statement = $pdo->prepare('SELECT tenant_id, plan_billable FROM ai_usage_events WHERE id = :id LIMIT 1');
            $statement->execute(['id' => $eventId]);
            $event = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
            if ((int) ($event['plan_billable'] ?? 0) === 1) {
                $this->evaluateThresholds((int) ($event['tenant_id'] ?? 0));
            }
        } catch (Throwable) {
            // O envio ao cliente já aconteceu; uma falha de telemetria não pode gerar mensagem duplicada.
        }
    }

    public function cancelReservation(int $eventId, string $reason = '', bool $failed = false): void
    {
        if ($eventId < 1) {
            return;
        }
        try {
            Database::connection()->prepare(
                'UPDATE ai_usage_events
                 SET status = :status,
                     error_message = :error_message,
                     completed_at = NOW()
                 WHERE id = :id AND status = "reserved"'
            )->execute([
                'status' => $failed ? 'failed' : 'cancelled',
                'error_message' => $reason !== '' ? mb_substr($reason, 0, 500) : null,
                'id' => $eventId,
            ]);
        } catch (Throwable) {
        }
    }

    /** @param array<string,mixed> $usage */
    public function recordSuggestion(int $tenantId, array $agent, int $conversationId, array $usage = []): void
    {
        if ($tenantId < 1) {
            return;
        }
        try {
            $owner = $this->credentialOwner($agent);
            Database::connection()->prepare(
                'INSERT INTO ai_usage_events
                    (tenant_id, agent_id, conversation_id, credential_id, credential_owner,
                     provider, model, usage_type, status, plan_billable, input_tokens, output_tokens, completed_at)
                 VALUES
                    (:tenant_id, :agent_id, :conversation_id, :credential_id, :credential_owner,
                     :provider, :model, "suggestion", "success", 0, :input_tokens, :output_tokens, NOW())'
            )->execute([
                'tenant_id' => $tenantId,
                'agent_id' => (int) ($agent['id'] ?? 0) ?: null,
                'conversation_id' => $conversationId > 0 ? $conversationId : null,
                'credential_id' => (int) ($agent['credential_id'] ?? 0) ?: null,
                'credential_owner' => $owner,
                'provider' => $this->provider($agent),
                'model' => $this->model($agent) ?: null,
                'input_tokens' => isset($usage['input_tokens']) && $usage['input_tokens'] !== null ? max(0, (int) $usage['input_tokens']) : null,
                'output_tokens' => isset($usage['output_tokens']) && $usage['output_tokens'] !== null ? max(0, (int) $usage['output_tokens']) : null,
            ]);
        } catch (Throwable) {
        }
    }

    public function credentialOwner(array $agent): string
    {
        $owner = strtolower(trim((string) ($agent['credential_owner'] ?? '')));
        if (in_array($owner, ['rs_connect', 'tenant'], true)) {
            return $owner;
        }

        $credentialId = (int) ($agent['credential_id'] ?? 0);
        if ($credentialId > 0) {
            try {
                $statement = Database::connection()->prepare('SELECT credential_owner FROM ai_provider_credentials WHERE id = :id LIMIT 1');
                $statement->execute(['id' => $credentialId]);
                $storedOwner = strtolower(trim((string) $statement->fetchColumn()));
                if (in_array($storedOwner, ['rs_connect', 'tenant'], true)) {
                    return $storedOwner;
                }
            } catch (Throwable) {
                // Antes da migration 052, toda credencial cadastrada por empresa era considerada própria do cliente.
            }
            return 'tenant';
        }

        // Sem credencial cadastrada, o AiModelService usa a chave global do ambiente da RS Connect.
        return 'rs_connect';
    }

    private function evaluateThresholds(int $tenantId): void
    {
        if ($tenantId < 1) {
            return;
        }

        try {
            $subscription = new SubscriptionService();
            $plan = $subscription->currentPlanForTenant($tenantId);
            $limit = $plan['limits']['ai_interactions_month'] ?? null;
            if ($limit === null || (int) $limit < 1) {
                return;
            }
            $period = $subscription->usagePeriodForTenant($tenantId);
            $statement = Database::connection()->prepare(
                'SELECT COUNT(*) FROM ai_usage_events
                 WHERE tenant_id = :tenant_id
                   AND usage_type = "auto_reply"
                   AND plan_billable = 1
                   AND status = "success"
                   AND created_at BETWEEN :start_at AND :end_at'
            );
            $statement->execute(['tenant_id' => $tenantId, 'start_at' => $period['start_at'], 'end_at' => $period['end_at']]);
            $used = (int) $statement->fetchColumn();

            foreach ([80, 95, 100] as $threshold) {
                $target = (int) ceil(((int) $limit) * ($threshold / 100));
                if ($used >= $target) {
                    $this->notifyThreshold($tenantId, $threshold, $used, (int) $limit, $period);
                }
            }
        } catch (Throwable) {
        }
    }

    /** @param array{start_at:string,end_at:string,start_date:string,end_date:string} $period */
    private function notifyThreshold(int $tenantId, int $threshold, int $used, int $limit, array $period): void
    {
        if ($tenantId < 1 || !in_array($threshold, [80, 95, 100], true) || $limit < 1) {
            return;
        }

        try {
            $claim = Database::connection()->prepare(
                'INSERT IGNORE INTO ai_usage_threshold_events
                    (tenant_id, period_start, period_end, threshold_percent, used_count, limit_count)
                 VALUES (:tenant_id, :period_start, :period_end, :threshold, :used_count, :limit_count)'
            );
            $claim->execute([
                'tenant_id' => $tenantId,
                'period_start' => $period['start_date'],
                'period_end' => $period['end_date'],
                'threshold' => $threshold,
                'used_count' => $used,
                'limit_count' => $limit,
            ]);
            if ($claim->rowCount() < 1) {
                return;
            }
        } catch (Throwable) {
            return;
        }

        $remaining = max(0, $limit - $used);
        $severity = $threshold >= 100 ? 'danger' : 'warning';
        $title = match ($threshold) {
            100 => 'Franquia de IA atingida',
            95 => 'Franquia de IA quase no fim',
            default => 'Uso de IA chegou a 80%',
        };
        $message = $threshold >= 100
            ? 'As ' . $limit . ' interações de IA custeadas pela RS Connect foram utilizadas neste ciclo. Novas respostas automáticas com credencial RS ficam pausadas; mensagens e atendimento humano continuam funcionando.'
            : 'Foram utilizadas ' . $used . ' de ' . $limit . ' interações de IA da franquia. Restam ' . $remaining . ' neste ciclo.';

        try {
            (new NotificationService())->createIfEnabled(
                $tenantId,
                'billing',
                $title,
                $message,
                $severity,
                '/subscription',
                'billing',
                'ai.usage.' . $threshold,
                'tenant',
                $tenantId,
                ['threshold' => $threshold, 'used' => $used, 'limit' => $limit, 'period' => $period],
                0
            );
        } catch (Throwable) {
        }

        $this->notifyAdmins($tenantId, $threshold, $used, $limit, $period);
    }

    /** @param array<string,string> $period */
    private function notifyAdmins(int $tenantId, int $threshold, int $used, int $limit, array $period): void
    {
        try {
            $pdo = Database::connection();
            $tenantStatement = $pdo->prepare('SELECT name FROM tenants WHERE id = :id LIMIT 1');
            $tenantStatement->execute(['id' => $tenantId]);
            $tenantName = trim((string) $tenantStatement->fetchColumn()) ?: ('Empresa #' . $tenantId);
            $admins = $pdo->query("SELECT id FROM users WHERE role = 'super_admin' AND status = 'active'")->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $title = $threshold >= 100 ? 'Limite de IA atingido — ' . $tenantName : 'Consumo de IA — ' . $tenantName;
            $message = $used . ' de ' . $limit . ' interações da franquia RS utilizadas (' . $threshold . '%+). Ciclo: ' . ($period['start_date'] ?? '') . ' a ' . ($period['end_date'] ?? '') . '.';
            foreach ($admins as $admin) {
                $userId = (int) ($admin['id'] ?? 0);
                if ($userId < 1) {
                    continue;
                }
                $preferences = (new OperationalAlertService())->preferences($userId);
                if (empty($preferences['platform_enabled']) || empty($preferences['ai_enabled'])) {
                    continue;
                }
                $pdo->prepare(
                    'INSERT INTO admin_operational_notifications
                        (user_id, incident_id, notification_kind, severity, title, message, action_url)
                     VALUES (:user_id, NULL, "manual", :severity, :title, :message, :action_url)'
                )->execute([
                    'user_id' => $userId,
                    'severity' => $threshold >= 100 ? 'danger' : 'warning',
                    'title' => mb_substr($title, 0, 180),
                    'message' => $message,
                    'action_url' => '/subscription?tenant_id=' . $tenantId,
                ]);
            }
        } catch (Throwable) {
        }
    }

    private function provider(array $agent): string
    {
        $provider = trim((string) ($agent['credential_provider'] ?? ''));
        if ($provider === '') {
            $provider = trim((string) ($agent['model_provider'] ?? 'google'));
        }
        return strtolower($provider !== '' ? $provider : 'google');
    }

    private function model(array $agent): string
    {
        $model = trim((string) ($agent['credential_default_model'] ?? ''));
        return $model !== '' ? $model : trim((string) ($agent['model_name'] ?? ''));
    }
}
