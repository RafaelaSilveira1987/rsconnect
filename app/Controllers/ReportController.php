<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\View;
use App\Services\AdminExecutiveReportService;
use PDO;

final class ReportController
{
    public function index(): void
    {
        if (Auth::isSuperAdmin()) {
            $filters = $this->filters();
            $reportData = (new AdminExecutiveReportService())->build($filters);
            View::render('reports.admin', [
                'title' => 'Relatórios executivos',
                'filters' => $filters,
                'reportData' => $reportData,
            ]);
            return;
        }
        $pdo = Database::connection();
        $filters = $this->filters();
        [$tenantScopeSql, $tenantScopeParams] = $this->tenantScope($filters);
        [$conversationScopeSql, $conversationScopeParams] = $this->scope('c', $filters);
        [$messageScopeSql, $messageScopeParams] = $this->scope('m', $filters);
        [$contactScopeSql, $contactScopeParams] = $this->scope('ct', $filters);
        [$leadScopeSql, $leadScopeParams] = $this->scope('l', $filters);
        [$appointmentScopeSql, $appointmentScopeParams] = $this->scope('a', $filters);
        [$invoiceScopeSql, $invoiceScopeParams] = $this->scope('i', $filters);

        $metrics = [
            'conversations' => $this->scalar($pdo, 'SELECT COUNT(*) FROM conversations c WHERE ' . $conversationScopeSql . ' AND c.created_at BETWEEN :start AND :end', $conversationScopeParams + $this->dateParams($filters)),
            'open_conversations' => $this->scalar($pdo, 'SELECT COUNT(*) FROM conversations c WHERE ' . $conversationScopeSql . ' AND c.status = "open"', $conversationScopeParams),
            'unread' => $this->scalar($pdo, 'SELECT COALESCE(SUM(c.unread_count),0) FROM conversations c WHERE ' . $conversationScopeSql, $conversationScopeParams),
            'incoming_messages' => $this->scalar($pdo, 'SELECT COUNT(*) FROM conversation_messages m WHERE ' . $messageScopeSql . ' AND m.direction = "incoming" AND m.sent_at BETWEEN :start AND :end', $messageScopeParams + $this->dateParams($filters)),
            'outgoing_messages' => $this->scalar($pdo, 'SELECT COUNT(*) FROM conversation_messages m WHERE ' . $messageScopeSql . ' AND m.direction = "outgoing" AND m.sent_at BETWEEN :start AND :end', $messageScopeParams + $this->dateParams($filters)),
            'ai_replies' => $this->scalar($pdo, 'SELECT COUNT(*) FROM conversation_messages m WHERE ' . $messageScopeSql . ' AND m.sender_type = "ai" AND m.sent_at BETWEEN :start AND :end', $messageScopeParams + $this->dateParams($filters)),
            'contacts' => $this->scalar($pdo, 'SELECT COUNT(*) FROM contacts ct WHERE ' . $contactScopeSql . ' AND ct.created_at BETWEEN :start AND :end', $contactScopeParams + $this->dateParams($filters)),
            'crm_leads' => $this->scalar($pdo, 'SELECT COUNT(*) FROM crm_leads l WHERE ' . $leadScopeSql . ' AND l.created_at BETWEEN :start AND :end', $leadScopeParams + $this->dateParams($filters)),
            'crm_won' => $this->scalar($pdo, 'SELECT COUNT(*) FROM crm_leads l WHERE ' . $leadScopeSql . ' AND l.status = "won" AND COALESCE(l.closed_at, l.updated_at) BETWEEN :start AND :end', $leadScopeParams + $this->dateParams($filters)),
            'appointments' => $this->scalar($pdo, 'SELECT COUNT(*) FROM calendar_appointments a WHERE ' . $appointmentScopeSql . ' AND a.starts_at BETWEEN :start AND :end', $appointmentScopeParams + $this->dateParams($filters)),
            'overdue_invoices' => $this->scalar($pdo, 'SELECT COUNT(*) FROM tenant_invoices i WHERE ' . $invoiceScopeSql . ' AND i.status = "overdue"', $invoiceScopeParams),
            'received_amount' => $this->money($pdo, 'SELECT COALESCE(SUM(i.amount),0) FROM tenant_invoices i WHERE ' . $invoiceScopeSql . ' AND i.status = "paid" AND COALESCE(i.paid_at, i.updated_at) BETWEEN :start AND :end', $invoiceScopeParams + $this->dateParams($filters)),
            'expected_amount' => $this->money($pdo, 'SELECT COALESCE(SUM(i.amount),0) FROM tenant_invoices i WHERE ' . $invoiceScopeSql . ' AND i.status IN ("open","overdue")', $invoiceScopeParams),
        ];

        $byDay = $this->rows($pdo,
            'SELECT DATE(m.sent_at) AS label, COUNT(*) AS total
             FROM conversation_messages m
             WHERE ' . $messageScopeSql . ' AND m.sent_at BETWEEN :start AND :end
             GROUP BY DATE(m.sent_at)
             ORDER BY label ASC',
            $messageScopeParams + $this->dateParams($filters)
        );

        $byTenant = $this->rows($pdo,
            'SELECT t.name AS label, COUNT(c.id) AS total
             FROM tenants t
             LEFT JOIN conversations c ON c.tenant_id = t.id AND c.created_at BETWEEN :start AND :end
             WHERE ' . $tenantScopeSql . '
             GROUP BY t.id, t.name
             ORDER BY total DESC, t.name ASC
             LIMIT 12',
            $tenantScopeParams + $this->dateParams($filters)
        );

        $attention = $this->rows($pdo,
            'SELECT c.id, c.status, c.attendance_mode, c.unread_count, c.last_message_at,
                    ct.name AS contact_name, ct.phone, t.name AS tenant_name
             FROM conversations c
             INNER JOIN contacts ct ON ct.id = c.contact_id
             INNER JOIN tenants t ON t.id = c.tenant_id
             WHERE ' . $conversationScopeSql . ' AND c.status <> "closed" AND (c.unread_count > 0 OR c.attendance_mode = "human")
             ORDER BY c.unread_count DESC, c.last_message_at DESC
             LIMIT 10',
            $conversationScopeParams
        );

        $recentInvoices = $this->rows($pdo,
            'SELECT i.invoice_number, i.amount, i.due_date, i.status, t.name AS tenant_name
             FROM tenant_invoices i
             INNER JOIN tenants t ON t.id = i.tenant_id
             WHERE ' . $invoiceScopeSql . '
             ORDER BY i.due_date DESC
             LIMIT 10',
            $invoiceScopeParams
        );

        $tenants = Auth::isSuperAdmin()
            ? $pdo->query('SELECT id, name FROM tenants ORDER BY name')->fetchAll(PDO::FETCH_ASSOC)
            : [];

        View::render('reports.index', [
            'title' => 'Relatórios',
            'filters' => $filters,
            'tenants' => $tenants,
            'metrics' => $metrics,
            'byDay' => $byDay,
            'byTenant' => $byTenant,
            'attention' => $attention,
            'recentInvoices' => $recentInvoices,
        ]);
    }

    public function export(): void
    {
        $pdo = Database::connection();
        if (Auth::isSuperAdmin()) {
            $this->adminExport($pdo);
        }
        $filters = $this->filters();
        $type = (string) ($_GET['type'] ?? 'conversations');

        if ($type === 'leads') {
            [$scopeSql, $params] = $this->scope('l', $filters);
            $sql = 'SELECT l.id, t.name AS empresa, ct.name AS contato, ct.phone AS telefone, l.title AS oportunidade, l.value AS valor, l.priority AS prioridade, l.status, l.created_at
                    FROM crm_leads l INNER JOIN tenants t ON t.id = l.tenant_id INNER JOIN contacts ct ON ct.id = l.contact_id
                    WHERE ' . $scopeSql . ' AND l.created_at BETWEEN :start AND :end ORDER BY l.created_at DESC LIMIT 5000';
            $rows = $this->rows($pdo, $sql, $params + $this->dateParams($filters));
            $this->csv('rs-connect-leads.csv', $rows);
        }

        if ($type === 'billing') {
            [$scopeSql, $params] = $this->scope('i', $filters);
            $sql = 'SELECT i.invoice_number AS cobranca, t.name AS empresa, i.amount AS valor, i.due_date AS vencimento, i.status, i.paid_at AS pago_em, i.external_checkout_url AS link
                    FROM tenant_invoices i INNER JOIN tenants t ON t.id = i.tenant_id
                    WHERE ' . $scopeSql . ' ORDER BY i.due_date DESC LIMIT 5000';
            $rows = $this->rows($pdo, $sql, $params);
            $this->csv('rs-connect-cobrancas.csv', $rows);
        }

        [$scopeSql, $params] = $this->scope('c', $filters);
        $sql = 'SELECT c.id, t.name AS empresa, ct.name AS contato, ct.phone AS telefone, c.status, c.attendance_mode AS modo, c.unread_count AS nao_lidas, c.last_message_preview AS ultima_mensagem, c.last_message_at AS ultima_interacao
                FROM conversations c INNER JOIN tenants t ON t.id = c.tenant_id INNER JOIN contacts ct ON ct.id = c.contact_id
                WHERE ' . $scopeSql . ' AND c.created_at BETWEEN :start AND :end ORDER BY c.last_message_at DESC LIMIT 5000';
        $rows = $this->rows($pdo, $sql, $params + $this->dateParams($filters));
        $this->csv('rs-connect-conversas.csv', $rows);
    }


    private function adminExport(PDO $pdo): never
    {
        $filters = $this->filters();
        $type = (string) ($_GET['type'] ?? 'companies');
        $date = $this->dateParams($filters);
        $tenantId = (int) ($filters['tenant_id'] ?? 0);
        $scope = $tenantId > 0 ? ' AND t.id = :tenant_id' : '';
        $params = $date + ($tenantId > 0 ? ['tenant_id' => $tenantId] : []);

        if ($type === 'revenue') {
            $rows = $this->rows($pdo,
                'SELECT i.invoice_number AS cobranca, t.name AS empresa, i.amount AS valor, i.due_date AS vencimento,
                        i.status, i.paid_at AS pago_em
                 FROM tenant_invoices i INNER JOIN tenants t ON t.id = i.tenant_id
                 WHERE i.created_at BETWEEN :start AND :end' . $scope . ' ORDER BY i.due_date DESC',
                $params
            );
            $this->csv('rs-connect-receita-executiva.csv', $rows);
        }

        if ($type === 'usage') {
            $rows = $this->rows($pdo,
                'SELECT t.name AS empresa, COUNT(DISTINCT c.id) AS conversas, COUNT(m.id) AS mensagens,
                        SUM(m.sender_type = "ai") AS respostas_ia, COUNT(DISTINCT CASE WHEN c.attendance_mode = "human" THEN c.id END) AS conversas_humanas
                 FROM tenants t
                 LEFT JOIN conversations c ON c.tenant_id = t.id
                 LEFT JOIN conversation_messages m ON m.conversation_id = c.id AND m.sent_at BETWEEN :start AND :end
                 WHERE 1=1' . $scope . '
                 GROUP BY t.id, t.name ORDER BY mensagens DESC',
                $params
            );
            $this->csv('rs-connect-uso-por-empresa.csv', $rows);
        }

        if ($type === 'commercial') {
            $rows = $this->rows($pdo,
                'SELECT o.company_name AS empresa, o.contact_name AS contato, o.phone AS whatsapp, o.email,
                        o.title AS oportunidade, o.value AS valor, s.name AS etapa, o.priority AS prioridade,
                        u.name AS responsavel_rs, o.expected_close_at AS fechamento_previsto, o.updated_at
                 FROM admin_crm_opportunities o
                 INNER JOIN admin_crm_stages s ON s.id = o.stage_id
                 LEFT JOIN users u ON u.id = o.owner_user_id
                 ORDER BY s.position, o.updated_at DESC',
                []
            );
            $this->csv('rs-connect-crm-comercial.csv', $rows);
        }

        if ($type === 'failures') {
            $aiRows = $this->rows($pdo,
                'SELECT t.name AS empresa, "IA" AS origem, l.event AS evento, l.error_message AS erro, l.created_at
                 FROM ai_automation_logs l INNER JOIN tenants t ON t.id = l.tenant_id
                 WHERE l.status = "error" AND l.created_at BETWEEN :start AND :end' . $scope,
                $params
            );
            $n8nRows = $this->rows($pdo,
                'SELECT t.name AS empresa, "n8n" AS origem, l.event AS evento, l.error_message AS erro, l.created_at
                 FROM n8n_flow_logs l INNER JOIN tenants t ON t.id = l.tenant_id
                 WHERE l.status = "error" AND l.created_at BETWEEN :start AND :end' . $scope,
                $params
            );
            $rows = array_merge($aiRows, $n8nRows);
            usort($rows, static fn (array $a, array $b): int => strcmp((string) $b['created_at'], (string) $a['created_at']));
            $this->csv('rs-connect-falhas-integracoes.csv', $rows);
        }

        $rows = $this->rows($pdo,
            'SELECT t.id, t.name AS empresa, t.legal_name AS razao_social, t.email, t.phone, t.segment, t.plan,
                    t.status, t.created_at, COUNT(DISTINCT u.id) AS usuarios, COUNT(DISTINCT ei.id) AS conexoes_whatsapp
             FROM tenants t
             LEFT JOIN users u ON u.tenant_id = t.id
             LEFT JOIN evolution_instances ei ON ei.tenant_id = t.id
             WHERE t.created_at BETWEEN :start AND :end' . $scope . '
             GROUP BY t.id ORDER BY t.created_at DESC',
            $params
        );
        $this->csv('rs-connect-empresas.csv', $rows);
    }

    private function filters(): array
    {
        $start = trim((string) ($_GET['start'] ?? date('Y-m-d', strtotime('-29 days'))));
        $end = trim((string) ($_GET['end'] ?? date('Y-m-d')));
        return [
            'start' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) ? $start : date('Y-m-d', strtotime('-29 days')),
            'end' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $end) ? $end : date('Y-m-d'),
            'tenant_id' => Auth::isSuperAdmin() ? (int) ($_GET['tenant_id'] ?? 0) : (int) Auth::tenantId(),
        ];
    }

    private function dateParams(array $filters): array
    {
        return [
            'start' => $filters['start'] . ' 00:00:00',
            'end' => $filters['end'] . ' 23:59:59',
        ];
    }

    private function tenantScope(array $filters): array
    {
        if (Auth::isSuperAdmin() && (int) ($filters['tenant_id'] ?? 0) < 1) {
            return ['1=1', []];
        }
        return ['t.id = :tenant_id', ['tenant_id' => (int) $filters['tenant_id']]];
    }

    private function scope(string $alias, array $filters): array
    {
        if (Auth::isSuperAdmin() && (int) ($filters['tenant_id'] ?? 0) < 1) {
            return ['1=1', []];
        }
        return [$alias . '.tenant_id = :tenant_id', ['tenant_id' => (int) $filters['tenant_id']]];
    }

    private function scalar(PDO $pdo, string $sql, array $params = []): int
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        return (int) $statement->fetchColumn();
    }

    private function money(PDO $pdo, string $sql, array $params = []): float
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        return (float) $statement->fetchColumn();
    }

    private function rows(PDO $pdo, string $sql, array $params = []): array
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function csv(string $filename, array $rows): never
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'w');
        if ($rows === []) {
            fputcsv($out, ['sem_registros']);
        } else {
            fputcsv($out, array_keys($rows[0]));
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
        }
        fclose($out);
        exit;
    }
}
