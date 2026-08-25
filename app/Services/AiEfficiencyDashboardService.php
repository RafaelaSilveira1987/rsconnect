<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

/** Consolida a telemetria interna de IA para gestão de custo e eficiência. */
final class AiEfficiencyDashboardService
{
    /** @return array<string,mixed> */
    public function dashboard(string $period = 'month', int $tenantId = 0, int $agentId = 0): array
    {
        $range = $this->range($period);
        try {
            $pdo = Database::connection();
            [$where, $params] = $this->where($range, $tenantId, $agentId);

            $summary = $pdo->prepare(
                'SELECT COUNT(*) AS events,
                        COUNT(DISTINCT conversation_id) AS conversations,
                        SUM(CASE WHEN usage_type = "auto_reply" AND status = "success" THEN 1 ELSE 0 END) AS automatic_replies,
                        SUM(COALESCE(provider_calls,0)) AS provider_calls,
                        SUM(CASE WHEN usage_type = "auto_reply" AND execution_strategy = "provider_ai" AND status = "success" THEN COALESCE(provider_calls,0) ELSE 0 END) AS reply_provider_calls,
                        SUM(COALESCE(provider_calls_avoided,0)) AS provider_calls_avoided,
                        SUM(COALESCE(input_tokens,0)) AS input_tokens,
                        SUM(COALESCE(output_tokens,0)) AS output_tokens,
                        SUM(COALESCE(total_tokens,0)) AS total_tokens,
                        SUM(COALESCE(cached_tokens,0)) AS cached_tokens,
                        SUM(COALESCE(estimated_input_tokens_avoided,0)) AS input_tokens_avoided,
                        SUM(COALESCE(estimated_cost,0)) AS estimated_cost,
                        SUM(CASE WHEN provider = "openai" THEN COALESCE(total_tokens,0) ELSE 0 END) AS openai_tokens,
                        SUM(CASE WHEN provider = "openai" THEN COALESCE(estimated_cost,0) ELSE 0 END) AS openai_estimated_cost,
                        SUM(CASE WHEN execution_strategy = "local_rule" AND status = "success" THEN 1 ELSE 0 END) AS local_rule_replies,
                        SUM(CASE WHEN execution_strategy = "exact_cache" AND status = "success" THEN 1 ELSE 0 END) AS exact_cache_replies,
                        SUM(CASE WHEN usage_type = "summary" AND status = "success" THEN 1 ELSE 0 END) AS memory_refreshes,
                        SUM(CASE WHEN execution_strategy = "provider_ai" AND usage_type = "auto_reply" AND status = "success" THEN 1 ELSE 0 END) AS provider_replies,
                        SUM(CASE WHEN execution_strategy = "provider_ai" AND usage_type = "auto_reply" AND status = "success" THEN COALESCE(total_tokens,0) ELSE 0 END) AS provider_reply_tokens,
                        SUM(CASE WHEN COALESCE(provider_calls,0) > 0 AND COALESCE(total_tokens,0) > 0 THEN COALESCE(total_tokens,0) ELSE 0 END) AS costable_tokens,
                        SUM(CASE WHEN COALESCE(provider_calls,0) > 0 AND COALESCE(total_tokens,0) > 0 AND estimated_cost IS NOT NULL THEN COALESCE(total_tokens,0) ELSE 0 END) AS priced_tokens
                 FROM ai_usage_events e ' . $where
            );
            $summary->execute($params);
            $totals = $summary->fetch(PDO::FETCH_ASSOC) ?: [];
            foreach ($totals as $key => $value) {
                if ($key === 'estimated_cost' || $key === 'openai_estimated_cost') {
                    $totals[$key] = (float) $value;
                } else {
                    $totals[$key] = (int) $value;
                }
            }
            $providerCalls = (int) ($totals['provider_calls'] ?? 0);
            $replyProviderCalls = (int) ($totals['reply_provider_calls'] ?? 0);
            $avoidedCalls = (int) ($totals['provider_calls_avoided'] ?? 0);
            $providerReplies = (int) ($totals['provider_replies'] ?? 0);
            $automaticReplies = (int) ($totals['automatic_replies'] ?? 0);
            $conversations = (int) ($totals['conversations'] ?? 0);
            $totals['avoidance_rate'] = ($replyProviderCalls + $avoidedCalls) > 0 ? $avoidedCalls / ($replyProviderCalls + $avoidedCalls) : 0.0;
            $totals['non_ai_reply_rate'] = $automaticReplies > 0 ? $avoidedCalls / $automaticReplies : 0.0;
            $totals['avg_tokens_per_provider_reply'] = $providerReplies > 0 ? (int) round((int) ($totals['provider_reply_tokens'] ?? 0) / $providerReplies) : 0;
            $totals['avg_cost_per_conversation'] = $conversations > 0 ? (float) ($totals['estimated_cost'] ?? 0) / $conversations : 0.0;
            $costableTokens = (int) ($totals['costable_tokens'] ?? 0);
            $pricedTokens = (int) ($totals['priced_tokens'] ?? 0);
            $totals['cost_pricing_coverage_rate'] = $costableTokens > 0 ? min(1.0, $pricedTokens / $costableTokens) : 0.0;

            $dailyStmt = $pdo->prepare(
                'SELECT DATE(created_at) AS day,
                        SUM(COALESCE(total_tokens,0)) AS total_tokens,
                        SUM(COALESCE(estimated_input_tokens_avoided,0)) AS avoided_tokens,
                        SUM(COALESCE(provider_calls,0)) AS provider_calls,
                        SUM(COALESCE(provider_calls_avoided,0)) AS avoided_calls,
                        SUM(COALESCE(estimated_cost,0)) AS estimated_cost
                 FROM ai_usage_events e ' . $where . '
                 GROUP BY DATE(created_at)
                 ORDER BY day'
            );
            $dailyStmt->execute($params);
            $daily = $dailyStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $tenantStmt = $pdo->prepare(
                'SELECT e.tenant_id, COALESCE(t.name, CONCAT("Empresa #", e.tenant_id)) AS tenant_name,
                        SUM(COALESCE(e.total_tokens,0)) AS total_tokens,
                        SUM(COALESCE(e.estimated_input_tokens_avoided,0)) AS avoided_tokens,
                        SUM(COALESCE(e.provider_calls,0)) AS provider_calls,
                        SUM(COALESCE(e.provider_calls_avoided,0)) AS avoided_calls,
                        SUM(COALESCE(e.estimated_cost,0)) AS estimated_cost,
                        COUNT(DISTINCT e.conversation_id) AS conversations
                 FROM ai_usage_events e
                 LEFT JOIN tenants t ON t.id = e.tenant_id ' . $where . '
                   AND e.provider = "openai"
                 GROUP BY e.tenant_id, t.name
                 ORDER BY estimated_cost DESC, total_tokens DESC
                 LIMIT 20'
            );
            $tenantStmt->execute($params);
            $tenants = $tenantStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $agentStmt = $pdo->prepare(
                'SELECT e.agent_id, COALESCE(a.name, "Sem assistente") AS agent_name,
                        COALESCE(t.name, "Empresa") AS tenant_name,
                        SUM(COALESCE(e.total_tokens,0)) AS total_tokens,
                        SUM(COALESCE(e.estimated_input_tokens_avoided,0)) AS avoided_tokens,
                        SUM(COALESCE(e.provider_calls,0)) AS provider_calls,
                        SUM(COALESCE(e.provider_calls_avoided,0)) AS avoided_calls,
                        SUM(COALESCE(e.estimated_cost,0)) AS estimated_cost
                 FROM ai_usage_events e
                 LEFT JOIN ai_agents a ON a.id = e.agent_id
                 LEFT JOIN tenants t ON t.id = e.tenant_id ' . $where . '
                   AND e.provider = "openai"
                 GROUP BY e.agent_id, a.name, t.name
                 ORDER BY estimated_cost DESC, total_tokens DESC
                 LIMIT 20'
            );
            $agentStmt->execute($params);
            $agents = $agentStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $unpricedStmt = $pdo->prepare(
                'SELECT provider, COALESCE(NULLIF(model, ""), "Não identificado") AS model,
                        SUM(COALESCE(total_tokens,0)) AS total_tokens
                 FROM ai_usage_events e ' . $where . '
                   AND COALESCE(provider_calls,0) > 0
                   AND COALESCE(total_tokens,0) > 0
                   AND estimated_cost IS NULL
                 GROUP BY provider, model
                 ORDER BY total_tokens DESC
                 LIMIT 12'
            );
            $unpricedStmt->execute($params);
            $unpricedModels = $unpricedStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $memory = ['rows' => 0, 'contact_rows' => 0, 'refreshes' => 0, 'errors' => 0];
            try {
                $memoryWhere = 'WHERE m.updated_at >= :start_at AND m.updated_at < :end_at';
                $memoryParams = ['start_at' => $range['start_at'], 'end_at' => $range['end_at']];
                if ($tenantId > 0) { $memoryWhere .= ' AND m.tenant_id = :memory_tenant_id'; $memoryParams['memory_tenant_id'] = $tenantId; }
                if ($agentId > 0) { $memoryWhere .= ' AND m.agent_id = :memory_agent_id'; $memoryParams['memory_agent_id'] = $agentId; }
                $m = $pdo->prepare('SELECT COUNT(*) rows, SUM(refresh_count) refreshes, SUM(status = "error") errors FROM conversation_ai_memory m ' . $memoryWhere);
                $m->execute($memoryParams);
                $memory = $m->fetch(PDO::FETCH_ASSOC) ?: $memory;
                $memory = ['rows' => (int) ($memory['rows'] ?? 0), 'contact_rows' => 0, 'refreshes' => (int) ($memory['refreshes'] ?? 0), 'errors' => (int) ($memory['errors'] ?? 0)];

                $contactWhere = 'WHERE cm.updated_at >= :contact_start_at AND cm.updated_at < :contact_end_at';
                $contactParams = ['contact_start_at' => $range['start_at'], 'contact_end_at' => $range['end_at']];
                if ($tenantId > 0) { $contactWhere .= ' AND cm.tenant_id = :contact_tenant_id'; $contactParams['contact_tenant_id'] = $tenantId; }
                if ($agentId > 0) { $contactWhere .= ' AND cm.agent_id = :contact_agent_id'; $contactParams['contact_agent_id'] = $agentId; }
                $cm = $pdo->prepare('SELECT COUNT(*) FROM contact_ai_memory cm ' . $contactWhere . ' AND cm.status = "active"');
                $cm->execute($contactParams);
                $memory['contact_rows'] = (int) $cm->fetchColumn();
            } catch (Throwable) {
            }

            return [
                'status' => 'ok', 'period' => $range['period'], 'range' => $range,
                'filters' => ['tenant_id' => $tenantId, 'agent_id' => $agentId],
                'totals' => $totals, 'daily' => $daily, 'tenants' => $tenants, 'agents' => $agents, 'memory' => $memory,
                'unpriced_models' => $unpricedModels,
                'pricing_snapshot' => AiCostCalculatorService::DEFAULT_PRICING_SNAPSHOT,
                'filter_options' => $this->filterOptions($pdo),
            ];
        } catch (Throwable $exception) {
            return ['status' => 'error', 'period' => $period, 'range' => $range, 'totals' => [], 'daily' => [], 'tenants' => [], 'agents' => [], 'memory' => [], 'unpriced_models' => [], 'pricing_snapshot' => AiCostCalculatorService::DEFAULT_PRICING_SNAPSHOT, 'filter_options' => [], 'error' => $exception->getMessage()];
        }
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function where(array $range, int $tenantId, int $agentId): array
    {
        $where = 'WHERE e.created_at >= :start_at AND e.created_at < :end_at';
        $params = ['start_at' => $range['start_at'], 'end_at' => $range['end_at']];
        if ($tenantId > 0) { $where .= ' AND e.tenant_id = :tenant_id'; $params['tenant_id'] = $tenantId; }
        if ($agentId > 0) { $where .= ' AND e.agent_id = :agent_id'; $params['agent_id'] = $agentId; }
        return [$where, $params];
    }

    /** @return array<string,mixed> */
    private function range(string $period): array
    {
        $period = in_array($period, ['7d','30d','month'], true) ? $period : 'month';
        $tz = new DateTimeZone('UTC');
        $today = (new DateTimeImmutable('now', $tz))->setTime(0,0);
        $start = $period === '7d' ? $today->sub(new DateInterval('P6D')) : ($period === '30d' ? $today->sub(new DateInterval('P29D')) : $today->modify('first day of this month'));
        $end = $today->add(new DateInterval('P1D'));
        return [
            'period' => $period,
            'start_at' => $start->format('Y-m-d H:i:s'),
            'end_at' => $end->format('Y-m-d H:i:s'),
            'start_date' => $start->format('Y-m-d'),
            'end_date' => $today->format('Y-m-d'),
        ];
    }

    /** @return array<string,mixed> */
    private function filterOptions(PDO $pdo): array
    {
        $tenants = $pdo->query('SELECT id, name FROM tenants ORDER BY name')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $agents = $pdo->query('SELECT a.id, a.name, a.tenant_id, t.name tenant_name FROM ai_agents a JOIN tenants t ON t.id = a.tenant_id ORDER BY t.name, a.name')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return ['tenants' => $tenants, 'agents' => $agents];
    }
}
