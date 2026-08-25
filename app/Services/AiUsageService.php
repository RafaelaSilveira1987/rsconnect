<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Env;
use PDO;
use Throwable;

/**
 * Fonte de verdade do uso técnico e do consumo comercial de IA.
 *
 * Regra 36.6.12:
 * - mensagem trafegada é métrica de plataforma, não franquia de IA;
 * - uma resposta automática entregue ao cliente = uma interação comercial;
 * - somente interação entregue com credencial custeada pela RS Connect reduz a franquia;
 * - credencial própria é medida integralmente, sem reduzir a franquia RS;
 * - chamadas ao provedor, tokens e custo estimado são telemetria técnica e podem existir
 *   mesmo quando a resposta falha ou é descartada antes da entrega;
 * - falha/timeout/entrega não concluída nunca confirma uma interação comercial.
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
                $budgetDecision = (new AiBudgetPolicyService())->decision($tenantId);
                if (empty($budgetDecision['allowed'])) {
                    (new AiBudgetPolicyService())->evaluateAndNotify($tenantId);
                    return [
                        'allowed' => false,
                        'event_id' => 0,
                        'owner' => $owner,
                        'billable' => true,
                        'used' => 0,
                        'limit' => null,
                        'budget_blocked' => true,
                        'budget_used_usd' => $budgetDecision['used_usd'] ?? null,
                        'budget_usd' => $budgetDecision['budget_usd'] ?? null,
                        'message' => (string) ($budgetDecision['message'] ?? 'Orçamento de IA atingido.'),
                    ];
                }

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

                // Reserva interrompida não pode consumir disponibilidade para sempre.
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
            // Durante implantação sem as migrations de telemetria, não interrompe atendimento.
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
            $event = $this->eventIdentity($eventId);
            $telemetry = $this->telemetry($usage, (string) ($event['provider'] ?? ''), (string) ($event['model'] ?? ''));

            try {
                $pdo->prepare(
                    'UPDATE ai_usage_events
                     SET status = "success",
                         delivery_status = "delivered",
                         outgoing_message_id = :outgoing_message_id,
                         provider = :provider,
                         model = :model,
                         efficiency_mode = :efficiency_mode,
                         provider_calls = :provider_calls,
                         input_tokens = :input_tokens,
                         output_tokens = :output_tokens,
                         total_tokens = :total_tokens,
                         cached_tokens = :cached_tokens,
                         history_messages_total = :history_messages_total,
                         history_messages_sent = :history_messages_sent,
                         knowledge_chars_total = :knowledge_chars_total,
                         knowledge_chars_sent = :knowledge_chars_sent,
                         estimated_input_tokens_avoided = :estimated_input_tokens_avoided,
                         estimated_cost = :estimated_cost,
                         estimated_cost_currency = :estimated_cost_currency,
                         completed_at = NOW(),
                         error_message = NULL
                     WHERE id = :id AND status = "reserved"'
                )->execute([
                    'outgoing_message_id' => $outgoingMessageId && $outgoingMessageId > 0 ? $outgoingMessageId : null,
                    'provider' => $telemetry['provider'],
                    'model' => $telemetry['model'] !== '' ? $telemetry['model'] : null,
                    'efficiency_mode' => $telemetry['efficiency_mode'],
                    'provider_calls' => $telemetry['provider_calls'],
                    'input_tokens' => $telemetry['input_tokens'],
                    'output_tokens' => $telemetry['output_tokens'],
                    'total_tokens' => $telemetry['total_tokens'],
                    'cached_tokens' => $telemetry['cached_tokens'],
                    'history_messages_total' => $telemetry['history_messages_total'],
                    'history_messages_sent' => $telemetry['history_messages_sent'],
                    'knowledge_chars_total' => $telemetry['knowledge_chars_total'],
                    'knowledge_chars_sent' => $telemetry['knowledge_chars_sent'],
                    'estimated_input_tokens_avoided' => $telemetry['estimated_input_tokens_avoided'],
                    'estimated_cost' => $telemetry['estimated_cost'],
                    'estimated_cost_currency' => $telemetry['estimated_cost_currency'],
                    'id' => $eventId,
                ]);
            } catch (Throwable) {
                try {
                    // Compatibilidade durante a janela de deploy antes da migration 077.
                    $pdo->prepare(
                        'UPDATE ai_usage_events
                         SET status = "success",
                             delivery_status = "delivered",
                             outgoing_message_id = :outgoing_message_id,
                             provider = :provider,
                             model = :model,
                             provider_calls = :provider_calls,
                             input_tokens = :input_tokens,
                             output_tokens = :output_tokens,
                             total_tokens = :total_tokens,
                             cached_tokens = :cached_tokens,
                             estimated_cost = :estimated_cost,
                             estimated_cost_currency = :estimated_cost_currency,
                             completed_at = NOW(),
                             error_message = NULL
                         WHERE id = :id AND status = "reserved"'
                    )->execute([
                        'outgoing_message_id' => $outgoingMessageId && $outgoingMessageId > 0 ? $outgoingMessageId : null,
                        'provider' => $telemetry['provider'],
                        'model' => $telemetry['model'] !== '' ? $telemetry['model'] : null,
                        'provider_calls' => $telemetry['provider_calls'],
                        'input_tokens' => $telemetry['input_tokens'],
                        'output_tokens' => $telemetry['output_tokens'],
                        'total_tokens' => $telemetry['total_tokens'],
                        'cached_tokens' => $telemetry['cached_tokens'],
                        'estimated_cost' => $telemetry['estimated_cost'],
                        'estimated_cost_currency' => $telemetry['estimated_cost_currency'],
                        'id' => $eventId,
                    ]);
                } catch (Throwable) {
                    // Compatibilidade durante a janela de deploy antes da migration 054.
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
                        'input_tokens' => $telemetry['input_tokens'],
                        'output_tokens' => $telemetry['output_tokens'],
                        'id' => $eventId,
                    ]);
                }
            }

            if ((int) ($event['plan_billable'] ?? 0) === 1) {
                $tenantId = (int) ($event['tenant_id'] ?? 0);
                $this->evaluateThresholds($tenantId);
                (new AiBudgetPolicyService())->evaluateAndNotify($tenantId);
            }
        } catch (Throwable) {
            // O envio ao cliente já aconteceu; falha de telemetria não pode duplicar mensagem.
        }
    }

    /** @param array<string,mixed> $usage */
    public function cancelReservation(int $eventId, string $reason = '', bool $failed = false, array $usage = []): void
    {
        if ($eventId < 1) {
            return;
        }
        try {
            $pdo = Database::connection();
            $event = $this->eventIdentity($eventId);
            $telemetry = $this->telemetry($usage, (string) ($event['provider'] ?? ''), (string) ($event['model'] ?? ''));
            try {
                $pdo->prepare(
                    'UPDATE ai_usage_events
                     SET status = :status,
                         delivery_status = "not_delivered",
                         provider = :provider,
                         model = :model,
                         efficiency_mode = :efficiency_mode,
                         provider_calls = :provider_calls,
                         input_tokens = :input_tokens,
                         output_tokens = :output_tokens,
                         total_tokens = :total_tokens,
                         cached_tokens = :cached_tokens,
                         history_messages_total = :history_messages_total,
                         history_messages_sent = :history_messages_sent,
                         knowledge_chars_total = :knowledge_chars_total,
                         knowledge_chars_sent = :knowledge_chars_sent,
                         estimated_input_tokens_avoided = :estimated_input_tokens_avoided,
                         estimated_cost = :estimated_cost,
                         estimated_cost_currency = :estimated_cost_currency,
                         error_message = :error_message,
                         completed_at = NOW()
                     WHERE id = :id AND status = "reserved"'
                )->execute([
                    'status' => $failed ? 'failed' : 'cancelled',
                    'provider' => $telemetry['provider'],
                    'model' => $telemetry['model'] !== '' ? $telemetry['model'] : null,
                    'efficiency_mode' => $telemetry['efficiency_mode'],
                    'provider_calls' => $telemetry['provider_calls'],
                    'input_tokens' => $telemetry['input_tokens'],
                    'output_tokens' => $telemetry['output_tokens'],
                    'total_tokens' => $telemetry['total_tokens'],
                    'cached_tokens' => $telemetry['cached_tokens'],
                    'history_messages_total' => $telemetry['history_messages_total'],
                    'history_messages_sent' => $telemetry['history_messages_sent'],
                    'knowledge_chars_total' => $telemetry['knowledge_chars_total'],
                    'knowledge_chars_sent' => $telemetry['knowledge_chars_sent'],
                    'estimated_input_tokens_avoided' => $telemetry['estimated_input_tokens_avoided'],
                    'estimated_cost' => $telemetry['estimated_cost'],
                    'estimated_cost_currency' => $telemetry['estimated_cost_currency'],
                    'error_message' => $reason !== '' ? mb_substr($reason, 0, 500) : null,
                    'id' => $eventId,
                ]);
            } catch (Throwable) {
                try {
                    $pdo->prepare(
                        'UPDATE ai_usage_events
                         SET status = :status,
                             delivery_status = "not_delivered",
                             provider = :provider,
                             model = :model,
                             provider_calls = :provider_calls,
                             input_tokens = :input_tokens,
                             output_tokens = :output_tokens,
                             total_tokens = :total_tokens,
                             cached_tokens = :cached_tokens,
                             estimated_cost = :estimated_cost,
                             estimated_cost_currency = :estimated_cost_currency,
                             error_message = :error_message,
                             completed_at = NOW()
                         WHERE id = :id AND status = "reserved"'
                    )->execute([
                        'status' => $failed ? 'failed' : 'cancelled',
                        'provider' => $telemetry['provider'],
                        'model' => $telemetry['model'] !== '' ? $telemetry['model'] : null,
                        'provider_calls' => $telemetry['provider_calls'],
                        'input_tokens' => $telemetry['input_tokens'],
                        'output_tokens' => $telemetry['output_tokens'],
                        'total_tokens' => $telemetry['total_tokens'],
                        'cached_tokens' => $telemetry['cached_tokens'],
                        'estimated_cost' => $telemetry['estimated_cost'],
                        'estimated_cost_currency' => $telemetry['estimated_cost_currency'],
                        'error_message' => $reason !== '' ? mb_substr($reason, 0, 500) : null,
                        'id' => $eventId,
                    ]);
                } catch (Throwable) {
                    $pdo->prepare(
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
                }
            }
            if ((string) ($event['credential_owner'] ?? '') === 'rs_connect' && (int) ($telemetry['provider_calls'] ?? 0) > 0) {
                (new AiBudgetPolicyService())->evaluateAndNotify((int) ($event['tenant_id'] ?? 0));
            }
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
            $provider = $this->provider($agent);
            $model = $this->model($agent);
            $telemetry = $this->telemetry($usage, $provider, $model);
            $provider = (string) ($telemetry['provider'] ?? $provider);
            $model = (string) ($telemetry['model'] ?? $model);
            $pdo = Database::connection();
            try {
                $pdo->prepare(
                    'INSERT INTO ai_usage_events
                        (tenant_id, agent_id, conversation_id, credential_id, credential_owner,
                         provider, model, usage_type, status, delivery_status, plan_billable,
                         provider_calls, input_tokens, output_tokens, total_tokens, cached_tokens,
                         estimated_cost, estimated_cost_currency, completed_at)
                     VALUES
                        (:tenant_id, :agent_id, :conversation_id, :credential_id, :credential_owner,
                         :provider, :model, "suggestion", "success", "not_applicable", 0,
                         :provider_calls, :input_tokens, :output_tokens, :total_tokens, :cached_tokens,
                         :estimated_cost, :estimated_cost_currency, NOW())'
                )->execute([
                    'tenant_id' => $tenantId,
                    'agent_id' => (int) ($agent['id'] ?? 0) ?: null,
                    'conversation_id' => $conversationId > 0 ? $conversationId : null,
                    'credential_id' => (int) ($agent['credential_id'] ?? 0) ?: null,
                    'credential_owner' => $owner,
                    'provider' => $provider,
                    'model' => $model ?: null,
                    'provider_calls' => $telemetry['provider_calls'],
                    'input_tokens' => $telemetry['input_tokens'],
                    'output_tokens' => $telemetry['output_tokens'],
                    'total_tokens' => $telemetry['total_tokens'],
                    'cached_tokens' => $telemetry['cached_tokens'],
                    'estimated_cost' => $telemetry['estimated_cost'],
                    'estimated_cost_currency' => $telemetry['estimated_cost_currency'],
                ]);
            } catch (Throwable) {
                $pdo->prepare(
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
                    'provider' => $provider,
                    'model' => $model ?: null,
                    'input_tokens' => $telemetry['input_tokens'],
                    'output_tokens' => $telemetry['output_tokens'],
                ]);
            }
            if ($owner === 'rs_connect' && (int) ($telemetry['provider_calls'] ?? 0) > 0) {
                (new AiBudgetPolicyService())->evaluateAndNotify($tenantId);
            }
        } catch (Throwable) {
        }
    }

    /**
     * Registra uso auxiliar/técnico que não representa uma resposta automática entregue.
     * Útil para sugestões, classificações e falhas de chamadas auxiliares.
     *
     * @param array<string,mixed> $usage
     */
    public function recordTechnicalEvent(
        int $tenantId,
        array $agent,
        int $conversationId,
        string $usageType,
        string $status,
        array $usage = [],
        string $errorMessage = ''
    ): void {
        if ($tenantId < 1) {
            return;
        }
        $usageType = in_array($usageType, ['suggestion','summary','classification','extraction','intent_detection','scheduling','other'], true)
            ? $usageType
            : 'other';
        $status = in_array($status, ['success','failed','cancelled'], true) ? $status : 'failed';

        try {
            $owner = $this->credentialOwner($agent);
            $provider = $this->provider($agent);
            $model = $this->model($agent);
            $telemetry = $this->telemetry($usage, $provider, $model);
            $provider = (string) ($telemetry['provider'] ?? $provider);
            $model = (string) ($telemetry['model'] ?? $model);
            Database::connection()->prepare(
                'INSERT INTO ai_usage_events
                    (tenant_id, agent_id, conversation_id, credential_id, credential_owner,
                     provider, model, usage_type, status, delivery_status, plan_billable,
                     provider_calls, input_tokens, output_tokens, total_tokens, cached_tokens,
                     estimated_cost, estimated_cost_currency, error_message, completed_at)
                 VALUES
                    (:tenant_id, :agent_id, :conversation_id, :credential_id, :credential_owner,
                     :provider, :model, :usage_type, :status, "not_applicable", 0,
                     :provider_calls, :input_tokens, :output_tokens, :total_tokens, :cached_tokens,
                     :estimated_cost, :estimated_cost_currency, :error_message, NOW())'
            )->execute([
                'tenant_id' => $tenantId,
                'agent_id' => (int) ($agent['id'] ?? 0) ?: null,
                'conversation_id' => $conversationId > 0 ? $conversationId : null,
                'credential_id' => (int) ($agent['credential_id'] ?? 0) ?: null,
                'credential_owner' => $owner,
                'provider' => $provider,
                'model' => $model ?: null,
                'usage_type' => $usageType,
                'status' => $status,
                'provider_calls' => $telemetry['provider_calls'],
                'input_tokens' => $telemetry['input_tokens'],
                'output_tokens' => $telemetry['output_tokens'],
                'total_tokens' => $telemetry['total_tokens'],
                'cached_tokens' => $telemetry['cached_tokens'],
                'estimated_cost' => $telemetry['estimated_cost'],
                'estimated_cost_currency' => $telemetry['estimated_cost_currency'],
                'error_message' => $errorMessage !== '' ? mb_substr($errorMessage, 0, 500) : null,
            ]);
            if ($owner === 'rs_connect' && (int) ($telemetry['provider_calls'] ?? 0) > 0) {
                (new AiBudgetPolicyService())->evaluateAndNotify($tenantId);
            }
        } catch (Throwable) {
            // Telemetria nunca deve impedir o fluxo principal.
        }
    }

    /**
     * Registra uma resposta automática entregue sem chamada ao provedor.
     * Regras locais e cache não reduzem a franquia de IA custeada pela RS Connect.
     */
    public function recordAvoidedAutoReply(
        int $tenantId,
        array $agent,
        int $conversationId,
        ?int $incomingMessageId,
        ?int $outgoingMessageId,
        string $strategy,
        string $detail = ''
    ): void {
        if ($tenantId < 1) {
            return;
        }
        $strategy = in_array($strategy, ['local_rule', 'exact_cache'], true) ? $strategy : 'local_rule';

        try {
            Database::connection()->prepare(
                'INSERT INTO ai_usage_events
                    (tenant_id, agent_id, conversation_id, incoming_message_id, outgoing_message_id,
                     credential_id, credential_owner, provider, model, efficiency_mode, usage_type,
                     status, delivery_status, plan_billable, execution_strategy, provider_calls,
                     provider_calls_avoided, input_tokens, output_tokens, total_tokens, cached_tokens,
                     estimated_input_tokens_avoided, error_message, completed_at)
                 VALUES
                    (:tenant_id, :agent_id, :conversation_id, :incoming_message_id, :outgoing_message_id,
                     NULL, "rs_connect", "local", :model, :efficiency_mode, "auto_reply",
                     "success", "delivered", 0, :execution_strategy, 0,
                     1, 0, 0, 0, 0, 0, NULL, NOW())'
            )->execute([
                'tenant_id' => $tenantId,
                'agent_id' => (int) ($agent['id'] ?? 0) ?: null,
                'conversation_id' => $conversationId > 0 ? $conversationId : null,
                'incoming_message_id' => $incomingMessageId && $incomingMessageId > 0 ? $incomingMessageId : null,
                'outgoing_message_id' => $outgoingMessageId && $outgoingMessageId > 0 ? $outgoingMessageId : null,
                'model' => $strategy,
                'efficiency_mode' => in_array((string) ($agent['ai_efficiency_mode'] ?? ''), ['economy','balanced','quality'], true)
                    ? (string) $agent['ai_efficiency_mode']
                    : 'balanced',
                'execution_strategy' => $strategy,
            ]);
        } catch (Throwable) {
            // Compatibilidade durante a janela anterior à migration 079.
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

    /** @return array<string,mixed> */
    private function eventIdentity(int $eventId): array
    {
        try {
            $statement = Database::connection()->prepare('SELECT tenant_id, plan_billable, credential_owner, provider, model FROM ai_usage_events WHERE id = :id LIMIT 1');
            $statement->execute(['id' => $eventId]);
            return $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param array<string,mixed> $usage
     * @return array<string,mixed>
     */
    private function telemetry(array $usage, string $fallbackProvider, string $fallbackModel): array
    {
        $input = isset($usage['input_tokens']) && $usage['input_tokens'] !== null ? max(0, (int) $usage['input_tokens']) : null;
        $output = isset($usage['output_tokens']) && $usage['output_tokens'] !== null ? max(0, (int) $usage['output_tokens']) : null;
        $total = isset($usage['total_tokens']) && $usage['total_tokens'] !== null ? max(0, (int) $usage['total_tokens']) : null;
        if ($total === null && ($input !== null || $output !== null)) {
            $total = (int) ($input ?? 0) + (int) ($output ?? 0);
        }
        $cached = isset($usage['cached_tokens']) && $usage['cached_tokens'] !== null ? max(0, (int) $usage['cached_tokens']) : null;
        $calls = isset($usage['provider_calls']) ? max(0, (int) $usage['provider_calls']) : 0;
        $provider = strtolower(trim((string) ($usage['provider'] ?? $fallbackProvider)));
        $model = trim((string) ($usage['model'] ?? $fallbackModel));
        $cost = $this->estimateCost($provider, $model, $input, $output, $cached);

        return [
            'provider_calls' => $calls,
            'input_tokens' => $input,
            'output_tokens' => $output,
            'total_tokens' => $total,
            'cached_tokens' => $cached,
            'provider' => $provider,
            'model' => $model,
            'efficiency_mode' => in_array((string) ($usage['efficiency_mode'] ?? ''), ['economy','balanced','quality'], true)
                ? (string) $usage['efficiency_mode']
                : null,
            'history_messages_total' => isset($usage['history_messages_total']) ? max(0, (int) $usage['history_messages_total']) : null,
            'history_messages_sent' => isset($usage['history_messages_sent']) ? max(0, (int) $usage['history_messages_sent']) : null,
            'knowledge_chars_total' => isset($usage['knowledge_chars_total']) ? max(0, (int) $usage['knowledge_chars_total']) : null,
            'knowledge_chars_sent' => isset($usage['knowledge_chars_sent']) ? max(0, (int) $usage['knowledge_chars_sent']) : null,
            'estimated_input_tokens_avoided' => isset($usage['estimated_input_tokens_avoided']) ? max(0, (int) $usage['estimated_input_tokens_avoided']) : 0,
            'estimated_cost' => $cost['cost'],
            'estimated_cost_currency' => $cost['currency'],
        ];
    }

    /** @return array{cost:?float,currency:?string} */
    private function estimateCost(string $provider, string $model, ?int $input, ?int $output, ?int $cached): array
    {
        $estimated = (new AiCostCalculatorService())->estimate($provider, $model, $input, $output, $cached);
        return [
            'cost' => $estimated['cost'],
            'currency' => $estimated['currency'],
        ];
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
        $routedModel = trim((string) ($agent['_ai_selected_model'] ?? ''));
        if ($routedModel !== '') {
            return $routedModel;
        }
        $model = trim((string) ($agent['credential_default_model'] ?? ''));
        return $model !== '' ? $model : trim((string) ($agent['model_name'] ?? ''));
    }
}
