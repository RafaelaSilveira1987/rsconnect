<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DateTimeImmutable;
use PDO;
use Throwable;

final class AdminExecutiveReportService
{
    private PDO $pdo;
    private ReportingAggregationService $aggregation;
    private array $warnings = [];

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connection();
        $this->aggregation = new ReportingAggregationService($this->pdo);
    }

    public function build(array $filters): array
    {
        $date = [
            'start' => $filters['start'] . ' 00:00:00',
            'end' => $filters['end'] . ' 23:59:59',
        ];
        $tenantId = (int) ($filters['tenant_id'] ?? 0);

        // Tabelas operacionais usam tenant_id; a tabela tenants usa id.
        $scope = $tenantId > 0 ? ' AND tenant_id = :tenant_id' : '';
        $tenantTableScope = $tenantId > 0 ? ' AND id = :tenant_id' : '';
        $scopeJoin = $tenantId > 0 ? ' AND t.id = :tenant_id' : '';
        $params = $date + ($tenantId > 0 ? ['tenant_id' => $tenantId] : []);
        $tenantParams = $tenantId > 0 ? ['tenant_id' => $tenantId] : [];

        $aggregateTotals = [];
        $messagesByDay = [];
        if ($this->aggregation->isAvailable()) {
            try {
                $scopeTenant = $tenantId > 0 ? $tenantId : null;
                $this->aggregation->ensureRange($scopeTenant, (string) $filters['start'], (string) $filters['end']);
                $aggregateTotals = $this->aggregation->totals($scopeTenant, (string) $filters['start'], (string) $filters['end']);
                $messagesByDay = $this->aggregation->dailySeries($scopeTenant, (string) $filters['start'], (string) $filters['end']);
                $this->warnings = array_merge($this->warnings, $this->aggregation->warnings());
            } catch (Throwable) {
                $this->warnings[] = 'A camada agregada não pôde ser atualizada; o relatório executivo usou as tabelas operacionais.';
            }
        }

        $metrics = [
            'new_companies' => $this->scalar(
                'SELECT COUNT(*) FROM tenants WHERE created_at BETWEEN :start AND :end' . $tenantTableScope,
                $params
            ),
            'active_companies' => $this->scalar(
                'SELECT COUNT(*) FROM tenants WHERE status = "active"' . $tenantTableScope,
                $tenantParams
            ),
            'inactive_companies' => $this->scalar(
                'SELECT COUNT(*) FROM tenants WHERE status <> "active"' . $tenantTableScope,
                $tenantParams
            ),
            'active_subscriptions' => $this->scalar(
                'SELECT COUNT(*) FROM tenant_subscriptions WHERE billing_status IN ("active","trialing")' . $scope,
                $tenantParams
            ),
            'mrr' => $this->money(
                'SELECT COALESCE(SUM(amount),0) FROM tenant_subscriptions WHERE billing_status IN ("active","trialing")' . $scope,
                $tenantParams
            ),
            'received' => $this->money(
                'SELECT COALESCE(SUM(amount),0) FROM tenant_invoices WHERE status = "paid" AND COALESCE(paid_at, updated_at) BETWEEN :start AND :end' . $scope,
                $params
            ),
            'expected' => $this->money(
                'SELECT COALESCE(SUM(amount),0) FROM tenant_invoices WHERE status IN ("open","overdue")' . $scope,
                $tenantParams
            ),
            'overdue_count' => $this->scalar(
                'SELECT COUNT(*) FROM tenant_invoices WHERE status = "overdue"' . $scope,
                $tenantParams
            ),
            'overdue_amount' => $this->money(
                'SELECT COALESCE(SUM(amount),0) FROM tenant_invoices WHERE status = "overdue"' . $scope,
                $tenantParams
            ),
            'messages' => $this->metricOrScalar(
                $aggregateTotals,
                ['messages_incoming', 'messages_outgoing'],
                'SELECT COUNT(*) FROM conversation_messages WHERE sent_at BETWEEN :start AND :end' . $scope,
                $params
            ),
            'incoming' => $this->metricOrScalar(
                $aggregateTotals,
                ['messages_incoming'],
                'SELECT COUNT(*) FROM conversation_messages WHERE direction = "incoming" AND sent_at BETWEEN :start AND :end' . $scope,
                $params
            ),
            'ai_replies' => $this->metricOrScalar(
                $aggregateTotals,
                ['messages_ai'],
                'SELECT COUNT(*) FROM conversation_messages WHERE direction = "outgoing" AND sender_type = "ai" AND sent_at BETWEEN :start AND :end' . $scope,
                $params
            ),
            // Corrigido: atendimento humano é medido por resposta humana dentro do período, não pelo modo atual da conversa.
            'human_conversations' => $this->scalar(
                'SELECT COUNT(DISTINCT conversation_id)
                 FROM conversation_messages
                 WHERE direction = "outgoing" AND sender_type = "user" AND sent_at BETWEEN :start AND :end' . $scope,
                $params
            ),
            'connected_instances' => $this->scalar(
                'SELECT COUNT(*) FROM evolution_instances WHERE status IN ("connected","open","active","online")' . $scope,
                $tenantParams
            ),
            'disconnected_instances' => $this->scalar(
                'SELECT COUNT(*) FROM evolution_instances WHERE status NOT IN ("connected","open","active","online")' . $scope,
                $tenantParams
            ),
            'ai_failures' => $this->metricOrScalar(
                $aggregateTotals,
                ['ai_errors'],
                'SELECT COUNT(*) FROM ai_automation_logs WHERE status = "error" AND created_at BETWEEN :start AND :end' . $scope,
                $params
            ),
            'n8n_failures' => $this->metricOrScalar(
                $aggregateTotals,
                ['n8n_errors'],
                'SELECT COUNT(*) FROM n8n_flow_logs WHERE status = "error" AND created_at BETWEEN :start AND :end' . $scope,
                $params
            ),
            'google_sync_failures' => (int) ($aggregateTotals['google_sync_errors'] ?? 0),
            'appointments' => $this->scalar(
                'SELECT COUNT(*) FROM calendar_appointments WHERE starts_at BETWEEN :start AND :end' . $scope,
                $params
            ),
            'appointments_confirmed' => $this->scalar(
                'SELECT COUNT(*) FROM calendar_appointments WHERE status IN ("confirmed","completed") AND starts_at BETWEEN :start AND :end' . $scope,
                $params
            ),
            'appointments_cancelled' => $this->scalar(
                'SELECT COUNT(*) FROM calendar_appointments WHERE status IN ("cancelled","no_show") AND starts_at BETWEEN :start AND :end' . $scope,
                $params
            ),
            'commercial_open' => $this->scalar('SELECT COUNT(*) FROM admin_crm_opportunities WHERE status = "open"'),
            'commercial_pipeline' => $this->money('SELECT COALESCE(SUM(value),0) FROM admin_crm_opportunities WHERE status = "open"'),
            'commercial_won' => $this->scalar(
                'SELECT COUNT(*) FROM admin_crm_opportunities WHERE status IN ("won","active") AND COALESCE(closed_at, updated_at) BETWEEN :start AND :end',
                $date
            ),
        ];

        $health = $this->healthMetrics($tenantId);
        $metrics += $health;
        $metrics['automation_failures'] = (int) $metrics['ai_failures'] + (int) $metrics['n8n_failures'];
        $metrics['agenda_conversion'] = (int) $metrics['appointments'] > 0
            ? round(((int) $metrics['appointments_confirmed'] / (int) $metrics['appointments']) * 100, 1)
            : 0;

        $previousDate = $this->previousDateParams($filters);
        $previousParams = $previousDate + ($tenantId > 0 ? ['tenant_id' => $tenantId] : []);
        $previousMetrics = [
            'new_companies' => $this->scalar(
                'SELECT COUNT(*) FROM tenants WHERE created_at BETWEEN :start AND :end' . $tenantTableScope,
                $previousParams
            ),
            'messages' => $this->scalar(
                'SELECT COUNT(*) FROM conversation_messages WHERE sent_at BETWEEN :start AND :end' . $scope,
                $previousParams
            ),
            'ai_replies' => $this->scalar(
                'SELECT COUNT(*) FROM conversation_messages WHERE direction = "outgoing" AND sender_type = "ai" AND sent_at BETWEEN :start AND :end' . $scope,
                $previousParams
            ),
            'appointments_confirmed' => $this->scalar(
                'SELECT COUNT(*) FROM calendar_appointments WHERE status IN ("confirmed","completed") AND starts_at BETWEEN :start AND :end' . $scope,
                $previousParams
            ),
            'automation_failures' => $this->scalar(
                'SELECT COUNT(*) FROM ai_automation_logs WHERE status = "error" AND created_at BETWEEN :start AND :end' . $scope,
                $previousParams
            ) + $this->scalar(
                'SELECT COUNT(*) FROM n8n_flow_logs WHERE status = "error" AND created_at BETWEEN :start AND :end' . $scope,
                $previousParams
            ),
        ];
        $comparisons = [
            'new_companies' => $this->percentChange((int) $metrics['new_companies'], (int) $previousMetrics['new_companies']),
            'messages' => $this->percentChange((int) $metrics['messages'], (int) $previousMetrics['messages']),
            'ai_replies' => $this->percentChange((int) $metrics['ai_replies'], (int) $previousMetrics['ai_replies']),
            'appointments_confirmed' => $this->percentChange((int) $metrics['appointments_confirmed'], (int) $previousMetrics['appointments_confirmed']),
            'automation_failures' => $this->percentChange((int) $metrics['automation_failures'], (int) $previousMetrics['automation_failures']),
        ];

        $companyGrowth = $this->rows(
            'SELECT DATE_FORMAT(created_at, "%Y-%m") AS label, COUNT(*) AS total
             FROM tenants WHERE created_at BETWEEN :start AND :end' . $tenantTableScope . '
             GROUP BY DATE_FORMAT(created_at, "%Y-%m") ORDER BY label',
            $params
        );

        if ($messagesByDay === []) {
            $messagesByDay = $this->rows(
                'SELECT DATE(sent_at) AS label, COUNT(*) AS total,
                        SUM(direction = "incoming") AS incoming,
                        SUM(direction = "outgoing" AND sender_type = "ai") AS ai
                 FROM conversation_messages WHERE sent_at BETWEEN :start AND :end' . $scope . '
                 GROUP BY DATE(sent_at) ORDER BY label',
                $params
            );
        }

        $revenueByPlan = $this->rows(
            'SELECT sp.name AS label, COUNT(ts.id) AS subscriptions, COALESCE(SUM(ts.amount),0) AS total
             FROM tenant_subscriptions ts
             INNER JOIN saas_plans sp ON sp.id = ts.plan_id
             INNER JOIN tenants t ON t.id = ts.tenant_id
             WHERE ts.billing_status IN ("active","trialing")' . $scopeJoin . '
             GROUP BY sp.id, sp.name ORDER BY total DESC',
            $tenantParams
        );

        $usageByTenant = $this->usageByTenant($tenantId, $date, $params, $scopeJoin);

        $lowUsage = $this->rows(
            'SELECT t.id, t.name, COUNT(m.id) AS messages, MAX(m.sent_at) AS last_message_at
             FROM tenants t
             LEFT JOIN conversation_messages m ON m.tenant_id = t.id AND m.sent_at BETWEEN :start AND :end
             WHERE t.status = "active"' . $scopeJoin . '
             GROUP BY t.id, t.name HAVING COUNT(m.id) < 10
             ORDER BY messages ASC, t.name LIMIT 12',
            $params
        );

        $failures = array_merge(
            $this->rows(
                'SELECT "IA" AS source, event AS label, COUNT(*) AS total
                 FROM ai_automation_logs WHERE status = "error" AND created_at BETWEEN :start AND :end' . $scope . '
                 GROUP BY event ORDER BY total DESC LIMIT 8',
                $params
            ),
            $this->rows(
                'SELECT "n8n" AS source, event AS label, COUNT(*) AS total
                 FROM n8n_flow_logs WHERE status = "error" AND created_at BETWEEN :start AND :end' . $scope . '
                 GROUP BY event ORDER BY total DESC LIMIT 8',
                $params
            ),
            $this->rows(
                'SELECT "Google Agenda" AS source, operation AS label, COUNT(*) AS total
                 FROM calendar_google_sync_logs WHERE status <> "success" AND created_at BETWEEN :start AND :end' . $scope . '
                 GROUP BY operation ORDER BY total DESC LIMIT 8',
                $params
            )
        );
        usort($failures, static fn (array $a, array $b): int => (int) $b['total'] <=> (int) $a['total']);
        $failures = array_slice($failures, 0, 12);

        $failureTrend = $this->failureTrend($tenantId, $date);

        $agendaStatus = $this->rows(
            'SELECT status AS label, COUNT(*) AS total
             FROM calendar_appointments WHERE starts_at BETWEEN :start AND :end' . $scope . '
             GROUP BY status ORDER BY total DESC',
            $params
        );

        $commercialStages = $this->rows(
            'SELECT s.name AS label, s.color_key, COUNT(o.id) AS total, COALESCE(SUM(o.value),0) AS value
             FROM admin_crm_stages s
             LEFT JOIN admin_crm_opportunities o ON o.stage_id = s.id
             GROUP BY s.id, s.name, s.color_key, s.position ORDER BY s.position'
        );

        $recentInvoices = $this->rows(
            'SELECT i.id, i.invoice_number, i.amount, i.due_date, i.status, t.name AS tenant_name
             FROM tenant_invoices i INNER JOIN tenants t ON t.id = i.tenant_id
             WHERE 1=1' . $scopeJoin . ' ORDER BY i.due_date DESC, i.id DESC LIMIT 12',
            $tenantParams
        );

        $tenants = $this->rows('SELECT id, name FROM tenants ORDER BY name');

        $healthDistribution = [
            ['label' => 'Saudáveis', 'total' => (int) ($metrics['healthy_companies'] ?? 0)],
            ['label' => 'Atenção', 'total' => (int) ($metrics['attention_companies'] ?? 0)],
            ['label' => 'Críticas', 'total' => (int) ($metrics['critical_companies'] ?? 0)],
            ['label' => 'Sem uso', 'total' => (int) ($metrics['idle_companies'] ?? 0)],
            ['label' => 'Bloqueadas', 'total' => (int) ($metrics['blocked_companies'] ?? 0)],
        ];
        $insights = $this->buildInsights($metrics, $comparisons, $usageByTenant);

        return compact(
            'metrics', 'comparisons', 'previousMetrics', 'companyGrowth', 'messagesByDay', 'revenueByPlan', 'usageByTenant',
            'lowUsage', 'failures', 'failureTrend', 'healthDistribution', 'insights', 'agendaStatus', 'commercialStages', 'recentInvoices', 'tenants'
        ) + ['warnings' => array_values(array_unique($this->warnings))];
    }


    private function previousDateParams(array $filters): array
    {
        $start = new DateTimeImmutable((string) $filters['start']);
        $end = new DateTimeImmutable((string) $filters['end']);
        $days = max(1, (int) $start->diff($end)->days + 1);
        $previousEnd = $start->modify('-1 day');
        $previousStart = $previousEnd->modify('-' . ($days - 1) . ' days');
        return [
            'start' => $previousStart->format('Y-m-d 00:00:00'),
            'end' => $previousEnd->format('Y-m-d 23:59:59'),
        ];
    }

    private function percentChange(int|float $current, int|float $previous): ?float
    {
        if ((float) $previous === 0.0) {
            return (float) $current === 0.0 ? 0.0 : null;
        }
        return round((((float) $current - (float) $previous) / abs((float) $previous)) * 100, 1);
    }

    private function failureTrend(int $tenantId, array $date): array
    {
        if ($this->aggregation->isAvailable()) {
            $scope = $tenantId > 0 ? ' AND tenant_id = :tenant_id' : '';
            $params = [
                'start_date' => substr((string) $date['start'], 0, 10),
                'end_date' => substr((string) $date['end'], 0, 10),
            ] + ($tenantId > 0 ? ['tenant_id' => $tenantId] : []);
            return $this->rows(
                'SELECT metric_date AS label, SUM(ai_errors + n8n_errors + google_sync_errors) AS total
                 FROM report_daily_metrics
                 WHERE metric_date BETWEEN :start_date AND :end_date' . $scope . '
                 GROUP BY metric_date ORDER BY metric_date',
                $params
            );
        }

        $scope = $tenantId > 0 ? ' AND tenant_id = :tenant_id' : '';
        $params = $date + ($tenantId > 0 ? ['tenant_id' => $tenantId] : []);
        $sources = [
            $this->rows(
                'SELECT DATE(created_at) AS label, COUNT(*) AS total FROM ai_automation_logs WHERE status = "error" AND created_at BETWEEN :start AND :end' . $scope . ' GROUP BY DATE(created_at)',
                $params
            ),
            $this->rows(
                'SELECT DATE(created_at) AS label, COUNT(*) AS total FROM n8n_flow_logs WHERE status = "error" AND created_at BETWEEN :start AND :end' . $scope . ' GROUP BY DATE(created_at)',
                $params
            ),
            $this->rows(
                'SELECT DATE(created_at) AS label, COUNT(*) AS total FROM calendar_google_sync_logs WHERE status <> "success" AND created_at BETWEEN :start AND :end' . $scope . ' GROUP BY DATE(created_at)',
                $params
            ),
        ];

        $totals = [];
        foreach ($sources as $rows) {
            foreach ($rows as $row) {
                $label = (string) ($row['label'] ?? '');
                if ($label === '') continue;
                $totals[$label] = ($totals[$label] ?? 0) + (int) ($row['total'] ?? 0);
            }
        }
        ksort($totals);
        return array_map(
            static fn (string $label, int $total): array => ['label' => $label, 'total' => $total],
            array_keys($totals),
            array_values($totals)
        );
    }

    private function buildInsights(array $metrics, array $comparisons, array $usageByTenant): array
    {
        $insights = [];
        $messageChange = $comparisons['messages'] ?? null;
        if ($messageChange !== null && abs((float) $messageChange) >= 5) {
            $insights[] = [
                'tone' => (float) $messageChange >= 0 ? 'positive' : 'attention',
                'title' => 'Uso da plataforma',
                'text' => 'O volume de mensagens ' . ((float) $messageChange >= 0 ? 'cresceu ' : 'caiu ') . number_format(abs((float) $messageChange), 1, ',', '.') . '% contra o período anterior.',
            ];
        }

        if ($usageByTenant !== []) {
            $top = $usageByTenant[0];
            $insights[] = [
                'tone' => 'info',
                'title' => 'Empresa com maior uso',
                'text' => (string) ($top['name'] ?? 'Empresa') . ' concentrou ' . number_format((int) ($top['messages'] ?? 0), 0, ',', '.') . ' mensagem(ns) no período.',
            ];
        }

        $attention = (int) ($metrics['attention_companies'] ?? 0) + (int) ($metrics['critical_companies'] ?? 0);
        if ($attention > 0) {
            $insights[] = [
                'tone' => 'attention',
                'title' => 'Empresas que precisam de atenção',
                'text' => $attention . ' empresa(s) estão classificadas como atenção ou crítica na última leitura de saúde.',
            ];
        }

        if ((int) ($metrics['automation_failures'] ?? 0) > 0) {
            $insights[] = [
                'tone' => 'attention',
                'title' => 'Falhas de automação',
                'text' => (int) $metrics['automation_failures'] . ' falha(s) de IA/n8n foram registradas no período.',
            ];
        }

        if ((int) ($metrics['appointments'] ?? 0) > 0) {
            $insights[] = [
                'tone' => (float) ($metrics['agenda_conversion'] ?? 0) >= 70 ? 'positive' : 'info',
                'title' => 'Agenda',
                'text' => 'A taxa de compromissos confirmados ou concluídos ficou em ' . number_format((float) ($metrics['agenda_conversion'] ?? 0), 1, ',', '.') . '%.',
            ];
        }
        return array_slice($insights, 0, 5);
    }

    private function usageByTenant(int $tenantId, array $date, array $params, string $scopeJoin): array
    {
        if ($this->aggregation->isAvailable()) {
            $metricScope = $tenantId > 0 ? ' AND tenant_id = :tenant_metric' : '';
            $humanScope = $tenantId > 0 ? ' AND tenant_id = :tenant_human' : '';
            $outerScope = $tenantId > 0 ? ' AND t.id = :tenant_outer' : '';
            $usageParams = [
                'metric_start' => substr((string) $date['start'], 0, 10),
                'metric_end' => substr((string) $date['end'], 0, 10),
                'start' => $date['start'],
                'end' => $date['end'],
            ];
            if ($tenantId > 0) {
                $usageParams += [
                    'tenant_metric' => $tenantId,
                    'tenant_human' => $tenantId,
                    'tenant_outer' => $tenantId,
                ];
            }

            return $this->rows(
                'SELECT t.id, t.name,
                        COALESCE(r.conversations,0) AS conversations,
                        COALESCE(r.messages,0) AS messages,
                        COALESCE(r.ai_replies,0) AS ai_replies,
                        COALESCE(h.human_conversations,0) AS human_conversations
                 FROM tenants t
                 LEFT JOIN (
                    SELECT tenant_id,
                           SUM(conversations_started) AS conversations,
                           SUM(messages_incoming + messages_outgoing) AS messages,
                           SUM(messages_ai) AS ai_replies
                    FROM report_daily_metrics
                    WHERE metric_date BETWEEN :metric_start AND :metric_end' . $metricScope . '
                    GROUP BY tenant_id
                 ) r ON r.tenant_id = t.id
                 LEFT JOIN (
                    SELECT tenant_id, COUNT(DISTINCT conversation_id) AS human_conversations
                    FROM conversation_messages
                    WHERE direction = "outgoing" AND sender_type = "user"
                      AND sent_at BETWEEN :start AND :end' . $humanScope . '
                    GROUP BY tenant_id
                 ) h ON h.tenant_id = t.id
                 WHERE 1=1' . $outerScope . '
                 ORDER BY messages DESC, t.name LIMIT 20',
                $usageParams
            );
        }

        return $this->rows(
            'SELECT t.id, t.name,
                    COUNT(DISTINCT CASE WHEN c.created_at BETWEEN :conversation_start AND :conversation_end THEN c.id END) AS conversations,
                    COUNT(m.id) AS messages,
                    SUM(m.direction = "outgoing" AND m.sender_type = "ai") AS ai_replies,
                    COUNT(DISTINCT CASE WHEN m.direction = "outgoing" AND m.sender_type = "user" THEN m.conversation_id END) AS human_conversations
             FROM tenants t
             LEFT JOIN conversations c ON c.tenant_id = t.id
             LEFT JOIN conversation_messages m ON m.conversation_id = c.id AND m.sent_at BETWEEN :message_start AND :message_end
             WHERE 1=1' . ($tenantId > 0 ? ' AND t.id = :tenant_outer' : '') . '
             GROUP BY t.id, t.name ORDER BY messages DESC, t.name LIMIT 20',
            [
                'conversation_start' => $date['start'],
                'conversation_end' => $date['end'],
                'message_start' => $date['start'],
                'message_end' => $date['end'],
            ] + ($tenantId > 0 ? ['tenant_outer' => $tenantId] : [])
        );
    }

    private function healthMetrics(int $tenantId): array
    {
        $scope = $tenantId > 0 ? ' WHERE s.tenant_id = :tenant_id' : '';
        $incidentScope = $tenantId > 0 ? ' AND tenant_id = :tenant_id' : '';
        $params = $tenantId > 0 ? ['tenant_id' => $tenantId] : [];

        $defaults = [
            'healthy_companies' => 0,
            'attention_companies' => 0,
            'critical_companies' => 0,
            'idle_companies' => 0,
            'blocked_companies' => 0,
            'open_health_incidents' => 0,
        ];

        try {
            $sql = 'SELECT
                        SUM(s.overall_status = "healthy") AS healthy_companies,
                        SUM(s.overall_status = "attention") AS attention_companies,
                        SUM(s.overall_status = "critical") AS critical_companies,
                        SUM(s.overall_status = "idle") AS idle_companies,
                        SUM(s.overall_status = "blocked") AS blocked_companies
                    FROM tenant_health_snapshots s
                    INNER JOIN (
                        SELECT tenant_id, MAX(checked_at) AS max_checked_at
                        FROM tenant_health_snapshots
                        GROUP BY tenant_id
                    ) latest ON latest.tenant_id = s.tenant_id AND latest.max_checked_at = s.checked_at' . $scope;
            $statement = $this->pdo->prepare($sql);
            $statement->execute($params);
            $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
            foreach (array_keys($defaults) as $key) {
                if ($key !== 'open_health_incidents' && isset($row[$key])) {
                    $defaults[$key] = (int) $row[$key];
                }
            }
            $defaults['open_health_incidents'] = $this->scalar(
                'SELECT COUNT(*) FROM tenant_health_incidents WHERE status IN ("open","acknowledged","monitoring")' . $incidentScope,
                $params
            );
        } catch (Throwable $exception) {
            $this->warnings[] = $this->warning($exception);
        }

        return $defaults;
    }

    private function metricOrScalar(array $totals, array $metricKeys, string $sql, array $params): int
    {
        foreach ($metricKeys as $metricKey) {
            if (!array_key_exists($metricKey, $totals)) {
                return $this->scalar($sql, $params);
            }
        }
        return array_sum(array_map(static fn (string $key): int => (int) $totals[$key], $metricKeys));
    }

    private function scalar(string $sql, array $params = []): int
    {
        try {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($params);
            return (int) $statement->fetchColumn();
        } catch (Throwable $exception) {
            $this->warnings[] = $this->warning($exception);
            return 0;
        }
    }

    private function money(string $sql, array $params = []): float
    {
        try {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($params);
            return (float) $statement->fetchColumn();
        } catch (Throwable $exception) {
            $this->warnings[] = $this->warning($exception);
            return 0.0;
        }
    }

    private function rows(string $sql, array $params = []): array
    {
        try {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($params);
            return $statement->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $exception) {
            $this->warnings[] = $this->warning($exception);
            return [];
        }
    }

    private function warning(Throwable $exception): string
    {
        $message = $exception->getMessage();
        if (preg_match('/Table [^ ]+\.([^ ]+) doesn/', $message, $matches)) {
            return 'Tabela pendente: ' . trim($matches[1], "'`");
        }
        return 'Uma consulta executiva não pôde ser concluída.';
    }
}
