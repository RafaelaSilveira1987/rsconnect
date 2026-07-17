<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use Throwable;

final class AdminExecutiveReportService
{
    private PDO $pdo;
    private array $warnings = [];

    public function __construct()
    {
        $this->pdo = Database::connection();
    }

    public function build(array $filters): array
    {
        $date = [
            'start' => $filters['start'] . ' 00:00:00',
            'end' => $filters['end'] . ' 23:59:59',
        ];
        $tenantId = (int) ($filters['tenant_id'] ?? 0);
        $scope = $tenantId > 0 ? ' AND tenant_id = :tenant_id' : '';
        $scopeJoin = $tenantId > 0 ? ' AND t.id = :tenant_id' : '';
        $params = $date + ($tenantId > 0 ? ['tenant_id' => $tenantId] : []);
        $tenantParams = $tenantId > 0 ? ['tenant_id' => $tenantId] : [];

        $metrics = [
            'new_companies' => $this->scalar('SELECT COUNT(*) FROM tenants WHERE created_at BETWEEN :start AND :end' . $scope, $params),
            'active_companies' => $this->scalar('SELECT COUNT(*) FROM tenants WHERE status = "active"' . $scope, $tenantParams),
            'inactive_companies' => $this->scalar('SELECT COUNT(*) FROM tenants WHERE status <> "active"' . $scope, $tenantParams),
            'active_subscriptions' => $this->scalar('SELECT COUNT(*) FROM tenant_subscriptions WHERE billing_status IN ("active","trialing")' . $scope, $tenantParams),
            'mrr' => $this->money('SELECT COALESCE(SUM(amount),0) FROM tenant_subscriptions WHERE billing_status IN ("active","trialing")' . $scope, $tenantParams),
            'received' => $this->money('SELECT COALESCE(SUM(amount),0) FROM tenant_invoices WHERE status = "paid" AND COALESCE(paid_at, updated_at) BETWEEN :start AND :end' . $scope, $params),
            'expected' => $this->money('SELECT COALESCE(SUM(amount),0) FROM tenant_invoices WHERE status IN ("open","overdue")' . $scope, $tenantParams),
            'overdue_count' => $this->scalar('SELECT COUNT(*) FROM tenant_invoices WHERE status = "overdue"' . $scope, $tenantParams),
            'overdue_amount' => $this->money('SELECT COALESCE(SUM(amount),0) FROM tenant_invoices WHERE status = "overdue"' . $scope, $tenantParams),
            'messages' => $this->scalar('SELECT COUNT(*) FROM conversation_messages WHERE sent_at BETWEEN :start AND :end' . $scope, $params),
            'incoming' => $this->scalar('SELECT COUNT(*) FROM conversation_messages WHERE direction = "incoming" AND sent_at BETWEEN :start AND :end' . $scope, $params),
            'ai_replies' => $this->scalar('SELECT COUNT(*) FROM conversation_messages WHERE sender_type = "ai" AND sent_at BETWEEN :start AND :end' . $scope, $params),
            'human_conversations' => $this->scalar('SELECT COUNT(*) FROM conversations WHERE attendance_mode = "human"' . $scope, $tenantParams),
            'connected_instances' => $this->scalar('SELECT COUNT(*) FROM evolution_instances WHERE status IN ("connected","open","active","online")' . $scope, $tenantParams),
            'disconnected_instances' => $this->scalar('SELECT COUNT(*) FROM evolution_instances WHERE status NOT IN ("connected","open","active","online")' . $scope, $tenantParams),
            'ai_failures' => $this->scalar('SELECT COUNT(*) FROM ai_automation_logs WHERE status = "error" AND created_at BETWEEN :start AND :end' . $scope, $params),
            'n8n_failures' => $this->scalar('SELECT COUNT(*) FROM n8n_flow_logs WHERE status = "error" AND created_at BETWEEN :start AND :end' . $scope, $params),
            'appointments' => $this->scalar('SELECT COUNT(*) FROM calendar_appointments WHERE starts_at BETWEEN :start AND :end' . $scope, $params),
            'appointments_confirmed' => $this->scalar('SELECT COUNT(*) FROM calendar_appointments WHERE status IN ("confirmed","completed") AND starts_at BETWEEN :start AND :end' . $scope, $params),
            'appointments_cancelled' => $this->scalar('SELECT COUNT(*) FROM calendar_appointments WHERE status IN ("cancelled","rejected","no_show") AND starts_at BETWEEN :start AND :end' . $scope, $params),
            'commercial_open' => $this->scalar('SELECT COUNT(*) FROM admin_crm_opportunities WHERE status = "open"'),
            'commercial_pipeline' => $this->money('SELECT COALESCE(SUM(value),0) FROM admin_crm_opportunities WHERE status = "open"'),
            'commercial_won' => $this->scalar('SELECT COUNT(*) FROM admin_crm_opportunities WHERE status IN ("won","active") AND COALESCE(closed_at, updated_at) BETWEEN :start AND :end', $date),
        ];
        $metrics['automation_failures'] = $metrics['ai_failures'] + $metrics['n8n_failures'];
        $metrics['agenda_conversion'] = $metrics['appointments'] > 0
            ? round(($metrics['appointments_confirmed'] / $metrics['appointments']) * 100, 1)
            : 0;

        $companyGrowth = $this->rows(
            'SELECT DATE_FORMAT(created_at, "%Y-%m") AS label, COUNT(*) AS total
             FROM tenants WHERE created_at BETWEEN :start AND :end' . $scope . '
             GROUP BY DATE_FORMAT(created_at, "%Y-%m") ORDER BY label',
            $params
        );

        $messagesByDay = $this->rows(
            'SELECT DATE(sent_at) AS label, COUNT(*) AS total,
                    SUM(direction = "incoming") AS incoming,
                    SUM(sender_type = "ai") AS ai
             FROM conversation_messages WHERE sent_at BETWEEN :start AND :end' . $scope . '
             GROUP BY DATE(sent_at) ORDER BY label',
            $params
        );

        $revenueByPlan = $this->rows(
            'SELECT sp.name AS label, COUNT(ts.id) AS subscriptions, COALESCE(SUM(ts.amount),0) AS total
             FROM tenant_subscriptions ts
             INNER JOIN saas_plans sp ON sp.id = ts.plan_id
             INNER JOIN tenants t ON t.id = ts.tenant_id
             WHERE ts.billing_status IN ("active","trialing")' . $scopeJoin . '
             GROUP BY sp.id, sp.name ORDER BY total DESC',
            $tenantParams
        );

        $usageByTenant = $this->rows(
            'SELECT t.id, t.name,
                    COUNT(DISTINCT c.id) AS conversations,
                    COUNT(m.id) AS messages,
                    SUM(m.sender_type = "ai") AS ai_replies,
                    COUNT(DISTINCT CASE WHEN c.attendance_mode = "human" THEN c.id END) AS human_conversations
             FROM tenants t
             LEFT JOIN conversations c ON c.tenant_id = t.id
             LEFT JOIN conversation_messages m ON m.conversation_id = c.id AND m.sent_at BETWEEN :start AND :end
             WHERE 1=1' . $scopeJoin . '
             GROUP BY t.id, t.name ORDER BY messages DESC, t.name LIMIT 20',
            $params
        );

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
            )
        );
        usort($failures, static fn (array $a, array $b): int => (int) $b['total'] <=> (int) $a['total']);
        $failures = array_slice($failures, 0, 12);

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

        return compact(
            'metrics', 'companyGrowth', 'messagesByDay', 'revenueByPlan', 'usageByTenant',
            'lowUsage', 'failures', 'agendaStatus', 'commercialStages', 'recentInvoices', 'tenants'
        ) + ['warnings' => array_values(array_unique($this->warnings))];
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
