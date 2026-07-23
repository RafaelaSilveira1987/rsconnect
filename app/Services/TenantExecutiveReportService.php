<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use Throwable;

final class TenantExecutiveReportService
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
        $tenantId = (int) ($filters['tenant_id'] ?? 0);
        if ($tenantId < 1) {
            throw new \InvalidArgumentException('Empresa obrigatória para o relatório do cliente.');
        }

        $date = $this->dateParams($filters);
        $aggregateTotals = [];
        $byDay = [];
        if ($this->aggregation->isAvailable()) {
            try {
                $this->aggregation->ensureRange($tenantId, (string) $filters['start'], (string) $filters['end']);
                $aggregateTotals = $this->aggregation->totals($tenantId, (string) $filters['start'], (string) $filters['end']);
                $byDay = $this->aggregation->dailySeries($tenantId, (string) $filters['start'], (string) $filters['end']);
                $this->warnings = array_merge($this->warnings, $this->aggregation->warnings());
            } catch (Throwable $exception) {
                $this->warnings[] = 'A camada agregada não pôde ser atualizada; o relatório usou as tabelas operacionais.';
            }
        }

        $metrics = [
            'conversations' => $this->metricOrScalar(
                $aggregateTotals,
                'conversations_started',
                'SELECT COUNT(*) FROM conversations WHERE tenant_id = :tenant_id AND created_at BETWEEN :start AND :end',
                ['tenant_id' => $tenantId] + $date
            ),
            'open_conversations' => $this->scalar(
                'SELECT COUNT(*) FROM conversations WHERE tenant_id = :tenant_id AND status = "open"',
                ['tenant_id' => $tenantId]
            ),
            'unread' => $this->scalar(
                'SELECT COALESCE(SUM(unread_count),0) FROM conversations WHERE tenant_id = :tenant_id',
                ['tenant_id' => $tenantId]
            ),
            'incoming_messages' => $this->metricOrScalar(
                $aggregateTotals,
                'messages_incoming',
                'SELECT COUNT(*) FROM conversation_messages WHERE tenant_id = :tenant_id AND direction = "incoming" AND sent_at BETWEEN :start AND :end',
                ['tenant_id' => $tenantId] + $date
            ),
            'outgoing_messages' => $this->metricOrScalar(
                $aggregateTotals,
                'messages_outgoing',
                'SELECT COUNT(*) FROM conversation_messages WHERE tenant_id = :tenant_id AND direction = "outgoing" AND sent_at BETWEEN :start AND :end',
                ['tenant_id' => $tenantId] + $date
            ),
            'ai_replies' => $this->metricOrScalar(
                $aggregateTotals,
                'messages_ai',
                'SELECT COUNT(*) FROM conversation_messages WHERE tenant_id = :tenant_id AND direction = "outgoing" AND sender_type = "ai" AND sent_at BETWEEN :start AND :end',
                ['tenant_id' => $tenantId] + $date
            ),
            'human_replies' => $this->metricOrScalar(
                $aggregateTotals,
                'messages_human',
                'SELECT COUNT(*) FROM conversation_messages WHERE tenant_id = :tenant_id AND direction = "outgoing" AND sender_type = "user" AND sent_at BETWEEN :start AND :end',
                ['tenant_id' => $tenantId] + $date
            ),
            'failed_messages' => $this->metricOrScalar(
                $aggregateTotals,
                'messages_failed',
                'SELECT COUNT(*) FROM conversation_messages WHERE tenant_id = :tenant_id AND status = "failed" AND sent_at BETWEEN :start AND :end',
                ['tenant_id' => $tenantId] + $date
            ),
            'contacts' => $this->metricOrScalar(
                $aggregateTotals,
                'contacts_new',
                'SELECT COUNT(*) FROM contacts WHERE tenant_id = :tenant_id AND created_at BETWEEN :start AND :end',
                ['tenant_id' => $tenantId] + $date
            ),
            'closed_conversations' => $this->scalar(
                'SELECT COUNT(*) FROM conversations WHERE tenant_id = :tenant_id AND status = "closed" AND updated_at BETWEEN :start AND :end',
                ['tenant_id' => $tenantId] + $date
            ),
            // Coorte consistente: oportunidades criadas no período e situação atual dessas mesmas oportunidades.
            'crm_leads' => $this->scalar(
                'SELECT COUNT(*) FROM crm_leads WHERE tenant_id = :tenant_id AND created_at BETWEEN :start AND :end',
                ['tenant_id' => $tenantId] + $date
            ),
            'crm_won' => $this->scalar(
                'SELECT COUNT(*) FROM crm_leads WHERE tenant_id = :tenant_id AND status = "won" AND created_at BETWEEN :start AND :end',
                ['tenant_id' => $tenantId] + $date
            ),
            'crm_lost' => $this->scalar(
                'SELECT COUNT(*) FROM crm_leads WHERE tenant_id = :tenant_id AND status = "lost" AND created_at BETWEEN :start AND :end',
                ['tenant_id' => $tenantId] + $date
            ),
            'appointments' => $this->scalar(
                'SELECT COUNT(*) FROM calendar_appointments WHERE tenant_id = :tenant_id AND starts_at BETWEEN :start AND :end',
                ['tenant_id' => $tenantId] + $date
            ),
            'appointments_confirmed' => $this->scalar(
                'SELECT COUNT(*) FROM calendar_appointments WHERE tenant_id = :tenant_id AND status IN ("confirmed","completed") AND starts_at BETWEEN :start AND :end',
                ['tenant_id' => $tenantId] + $date
            ),
            'appointments_cancelled' => $this->scalar(
                'SELECT COUNT(*) FROM calendar_appointments WHERE tenant_id = :tenant_id AND status IN ("cancelled","no_show") AND starts_at BETWEEN :start AND :end',
                ['tenant_id' => $tenantId] + $date
            ),
            'overdue_invoices' => $this->scalar(
                'SELECT COUNT(*) FROM tenant_invoices WHERE tenant_id = :tenant_id AND status = "overdue"',
                ['tenant_id' => $tenantId]
            ),
            'received_amount' => $this->money(
                'SELECT COALESCE(SUM(amount),0) FROM tenant_invoices WHERE tenant_id = :tenant_id AND status = "paid" AND COALESCE(paid_at, updated_at) BETWEEN :start AND :end',
                ['tenant_id' => $tenantId] + $date
            ),
            'expected_amount' => $this->money(
                'SELECT COALESCE(SUM(amount),0) FROM tenant_invoices WHERE tenant_id = :tenant_id AND status IN ("open","overdue")',
                ['tenant_id' => $tenantId]
            ),
            'ai_success' => $this->metricOrScalar(
                $aggregateTotals,
                'ai_success',
                'SELECT COUNT(*) FROM ai_automation_logs WHERE tenant_id = :tenant_id AND status = "success" AND created_at BETWEEN :start AND :end',
                ['tenant_id' => $tenantId] + $date
            ),
            'ai_errors' => $this->metricOrScalar(
                $aggregateTotals,
                'ai_errors',
                'SELECT COUNT(*) FROM ai_automation_logs WHERE tenant_id = :tenant_id AND status = "error" AND created_at BETWEEN :start AND :end',
                ['tenant_id' => $tenantId] + $date
            ),
            // Métricas preparadas para a próxima camada visual da Agenda.
            'availability_requests' => (int) ($aggregateTotals['availability_requests'] ?? 0),
            'availability_slots' => (int) ($aggregateTotals['availability_slots'] ?? 0),
            'availability_selected_slots' => (int) ($aggregateTotals['availability_selected_slots'] ?? 0),
            'google_sync_success' => (int) ($aggregateTotals['google_sync_success'] ?? 0),
            'google_sync_errors' => (int) ($aggregateTotals['google_sync_errors'] ?? 0),
        ];

        $metrics['avg_first_response_seconds'] = $this->scalar(
            'SELECT COALESCE(AVG(TIMESTAMPDIFF(SECOND, x.first_incoming, x.first_outgoing)), 0)
             FROM (
                SELECT conversation_id,
                       MIN(CASE WHEN direction = "incoming" THEN sent_at END) AS first_incoming,
                       MIN(CASE WHEN direction = "outgoing" THEN sent_at END) AS first_outgoing
                FROM conversation_messages
                WHERE tenant_id = :tenant_id AND sent_at BETWEEN :start AND :end
                GROUP BY conversation_id
                HAVING first_incoming IS NOT NULL AND first_outgoing IS NOT NULL AND first_outgoing >= first_incoming
             ) x',
            ['tenant_id' => $tenantId] + $date
        );

        $metrics['total_messages'] = (int) $metrics['incoming_messages'] + (int) $metrics['outgoing_messages'];
        $metrics['ai_share'] = (int) $metrics['outgoing_messages'] > 0
            ? round(((int) $metrics['ai_replies'] / (int) $metrics['outgoing_messages']) * 100, 1)
            : 0;
        $metrics['human_share'] = (int) $metrics['outgoing_messages'] > 0
            ? round(((int) $metrics['human_replies'] / (int) $metrics['outgoing_messages']) * 100, 1)
            : 0;
        $metrics['crm_conversion'] = (int) $metrics['crm_leads'] > 0
            ? round(((int) $metrics['crm_won'] / (int) $metrics['crm_leads']) * 100, 1)
            : 0;
        $metrics['agenda_conversion'] = (int) $metrics['appointments'] > 0
            ? round(((int) $metrics['appointments_confirmed'] / (int) $metrics['appointments']) * 100, 1)
            : 0;
        $metrics['avg_messages_per_conversation'] = (int) $metrics['conversations'] > 0
            ? round((int) $metrics['total_messages'] / (int) $metrics['conversations'], 1)
            : 0;

        if ($byDay === []) {
            $byDay = $this->rows(
                'SELECT DATE(sent_at) AS label, COUNT(*) AS total,
                        SUM(direction = "incoming") AS incoming,
                        SUM(direction = "outgoing") AS outgoing,
                        SUM(direction = "outgoing" AND sender_type = "ai") AS ai
                 FROM conversation_messages
                 WHERE tenant_id = :tenant_id AND sent_at BETWEEN :start AND :end
                 GROUP BY DATE(sent_at)
                 ORDER BY label ASC',
                ['tenant_id' => $tenantId] + $date
            );
        }

        $byHour = $this->rows(
            'SELECT HOUR(sent_at) AS label, COUNT(*) AS total
             FROM conversation_messages
             WHERE tenant_id = :tenant_id AND direction = "incoming" AND sent_at BETWEEN :start AND :end
             GROUP BY HOUR(sent_at)
             ORDER BY label ASC',
            ['tenant_id' => $tenantId] + $date
        );

        $crmByStage = $this->rows(
            'SELECT s.name AS label, s.color_key, COUNT(l.id) AS total, COALESCE(SUM(l.value),0) AS value
             FROM crm_stages s
             LEFT JOIN crm_leads l ON l.stage_id = s.id AND l.tenant_id = s.tenant_id
                  AND l.created_at BETWEEN :start AND :end
             WHERE s.tenant_id = :tenant_id
             GROUP BY s.id, s.name, s.color_key, s.position
             ORDER BY s.position',
            ['tenant_id' => $tenantId] + $date
        );

        $agendaByStatus = $this->rows(
            'SELECT status AS label, COUNT(*) AS total
             FROM calendar_appointments
             WHERE tenant_id = :tenant_id AND starts_at BETWEEN :start AND :end
             GROUP BY status ORDER BY total DESC',
            ['tenant_id' => $tenantId] + $date
        );

        $teamPerformance = $this->rows(
            'SELECT COALESCE(u.name, "Equipe") AS label, COUNT(m.id) AS total,
                    COUNT(DISTINCT m.conversation_id) AS conversations
             FROM conversation_messages m
             LEFT JOIN users u ON u.id = m.sender_user_id
             WHERE m.tenant_id = :tenant_id AND m.sender_type = "user" AND m.direction = "outgoing"
               AND m.sent_at BETWEEN :start AND :end
             GROUP BY m.sender_user_id, u.name ORDER BY total DESC LIMIT 10',
            ['tenant_id' => $tenantId] + $date
        );

        $topContacts = $this->rows(
            'SELECT ct.id, COALESCE(NULLIF(ct.name, ""), ct.phone) AS label, ct.phone,
                    COUNT(m.id) AS total, MAX(m.sent_at) AS last_message_at
             FROM conversation_messages m
             INNER JOIN conversations c ON c.id = m.conversation_id
             INNER JOIN contacts ct ON ct.id = c.contact_id
             WHERE m.tenant_id = :tenant_id AND m.sent_at BETWEEN :start AND :end
             GROUP BY ct.id, ct.name, ct.phone ORDER BY total DESC LIMIT 8',
            ['tenant_id' => $tenantId] + $date
        );

        $byTenant = $this->rows(
            'SELECT t.name AS label, COUNT(c.id) AS total
             FROM tenants t
             LEFT JOIN conversations c ON c.tenant_id = t.id AND c.created_at BETWEEN :start AND :end
             WHERE t.id = :tenant_id
             GROUP BY t.id, t.name
             ORDER BY total DESC, t.name ASC',
            ['tenant_id' => $tenantId] + $date
        );

        $attention = $this->rows(
            'SELECT c.id, c.status, c.attendance_mode, c.unread_count, c.last_message_at,
                    ct.name AS contact_name, ct.phone, t.name AS tenant_name
             FROM conversations c
             INNER JOIN contacts ct ON ct.id = c.contact_id
             INNER JOIN tenants t ON t.id = c.tenant_id
             WHERE c.tenant_id = :tenant_id AND c.status <> "closed"
               AND (c.unread_count > 0 OR c.attendance_mode = "human")
             ORDER BY c.unread_count DESC, c.last_message_at DESC
             LIMIT 10',
            ['tenant_id' => $tenantId]
        );

        $recentInvoices = $this->rows(
            'SELECT i.invoice_number, i.amount, i.due_date, i.status, t.name AS tenant_name
             FROM tenant_invoices i
             INNER JOIN tenants t ON t.id = i.tenant_id
             WHERE i.tenant_id = :tenant_id
             ORDER BY i.due_date DESC
             LIMIT 10',
            ['tenant_id' => $tenantId]
        );

        return compact(
            'metrics', 'byDay', 'byHour', 'crmByStage', 'agendaByStatus',
            'teamPerformance', 'topContacts', 'byTenant', 'attention', 'recentInvoices'
        ) + ['warnings' => array_values(array_unique($this->warnings))];
    }

    private function metricOrScalar(array $totals, string $metric, string $sql, array $params): int
    {
        if (array_key_exists($metric, $totals)) {
            return (int) $totals[$metric];
        }
        return $this->scalar($sql, $params);
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

    private function dateParams(array $filters): array
    {
        return [
            'start' => $filters['start'] . ' 00:00:00',
            'end' => $filters['end'] . ' 23:59:59',
        ];
    }

    private function warning(Throwable $exception): string
    {
        $message = $exception->getMessage();
        if (preg_match('/Table [^ ]+\.([^ ]+) doesn/', $message, $matches)) {
            return 'Tabela pendente: ' . trim($matches[1], "'`");
        }
        return 'Uma consulta do relatório do cliente não pôde ser concluída.';
    }
}
