<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Database;
use DateTimeImmutable;
use PDO;
use Throwable;

final class TenantExecutiveReportService
{
    private PDO $pdo;
    private ReportingAggregationService $aggregation;
    private ExecutiveMetricsPolicyService $executivePolicy;
    private array $warnings = [];

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connection();
        $this->aggregation = new ReportingAggregationService($this->pdo);
        $this->executivePolicy = new ExecutiveMetricsPolicyService($this->pdo);
    }

    public function build(array $filters): array
    {
        $this->warnings = [];

        $tenantId = (int) ($filters['tenant_id'] ?? 0);
        if ($tenantId < 1) {
            throw new \InvalidArgumentException('Empresa obrigatória para o relatório do cliente.');
        }

        $timezone = $this->tenantTimezone($tenantId);
        $date = $this->dateParams($filters, $timezone);

        // O cache report_daily_metrics v2 materializa dias em UTC. Enquanto a
        // camada derivada não for migrada para o contrato de dia local por
        // empresa, o painel executivo do tenant usa diretamente as tabelas
        // operacionais para que 00:00–23:59 represente de fato o fuso exibido.
        $aggregateTotals = [];
        $series = $this->messageSeries($tenantId, $date, $timezone);
        $byDay = $series['by_day'];

        $metrics = [
            // Conversas com movimento no período. Esta é a base executiva de
            // atendimento e pode incluir conversas criadas em dias anteriores.
            'active_conversations' => $this->scalar(
                'SELECT COUNT(DISTINCT conversation_id)
                 FROM conversation_messages
                 WHERE tenant_id = :tenant_id AND sent_at BETWEEN :start AND :end',
                ['tenant_id' => $tenantId] + $date
            ),
            'conversations' => $this->metricOrScalar(
                $aggregateTotals,
                'conversations_started',
                'SELECT COUNT(*) FROM conversations WHERE tenant_id = :tenant_id AND created_at BETWEEN :start AND :end',
                ['tenant_id' => $tenantId] + $date
            ),
            'responded_conversations' => $this->scalar(
                'SELECT COUNT(DISTINCT conversation_id)
                 FROM conversation_messages
                 WHERE tenant_id = :tenant_id
                   AND direction = "outgoing"
                   AND sender_type IN ("ai", "user")
                   AND sent_at BETWEEN :start AND :end',
                ['tenant_id' => $tenantId] + $date
            ),
            'human_conversations' => $this->scalar(
                'SELECT COUNT(DISTINCT conversation_id)
                 FROM conversation_messages
                 WHERE tenant_id = :tenant_id
                   AND direction = "outgoing"
                   AND sender_type = "user"
                   AND sent_at BETWEEN :start AND :end',
                ['tenant_id' => $tenantId] + $date
            ),
            'open_incidents' => $this->scalar(
                'SELECT COUNT(*)
                 FROM system_incidents
                 WHERE tenant_id = :tenant_id AND resolved_at IS NULL',
                ['tenant_id' => $tenantId]
            ),
            'open_conversations' => $this->scalar(
                'SELECT COUNT(*) FROM conversations WHERE tenant_id = :tenant_id AND status = "open"',
                ['tenant_id' => $tenantId]
            ),
            'unread' => $this->scalar(
                'SELECT COALESCE(SUM(unread_count),0) FROM conversations WHERE tenant_id = :tenant_id',
                ['tenant_id' => $tenantId]
            ),
            'attention_conversations' => $this->scalar(
                'SELECT COUNT(*)
                 FROM conversations
                 WHERE tenant_id = :tenant_id AND status <> "closed"
                   AND (unread_count > 0 OR attendance_mode = "human")',
                ['tenant_id' => $tenantId]
            ),
            'attention_human_conversations' => $this->scalar(
                'SELECT COUNT(*)
                 FROM conversations
                 WHERE tenant_id = :tenant_id AND status <> "closed"
                   AND attendance_mode = "human"',
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
            'system_replies' => max(0,
                (int) ($aggregateTotals['messages_outgoing'] ?? $this->scalar(
                    'SELECT COUNT(*) FROM conversation_messages WHERE tenant_id = :tenant_id AND direction = "outgoing" AND sent_at BETWEEN :start AND :end',
                    ['tenant_id' => $tenantId] + $date
                ))
                - (int) ($aggregateTotals['messages_ai'] ?? $this->scalar(
                    'SELECT COUNT(*) FROM conversation_messages WHERE tenant_id = :tenant_id AND direction = "outgoing" AND sender_type = "ai" AND sent_at BETWEEN :start AND :end',
                    ['tenant_id' => $tenantId] + $date
                ))
                - (int) ($aggregateTotals['messages_human'] ?? $this->scalar(
                    'SELECT COUNT(*) FROM conversation_messages WHERE tenant_id = :tenant_id AND direction = "outgoing" AND sender_type = "user" AND sent_at BETWEEN :start AND :end',
                    ['tenant_id' => $tenantId] + $date
                ))
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
            'appointments_scheduled' => $this->scalar(
                'SELECT COUNT(*) FROM calendar_appointments WHERE tenant_id = :tenant_id AND status = "scheduled" AND starts_at BETWEEN :start AND :end',
                ['tenant_id' => $tenantId] + $date
            ),
            'appointments_pending' => $this->scalar(
                'SELECT COUNT(*) FROM calendar_appointments
                 WHERE tenant_id = :tenant_id
                   AND status IN ("pre_scheduled","awaiting_approval","rescheduled")
                   AND starts_at BETWEEN :start AND :end',
                ['tenant_id' => $tenantId] + $date
            ),
            'appointments_confirmed' => $this->scalar(
                'SELECT COUNT(*) FROM calendar_appointments WHERE tenant_id = :tenant_id AND status = "confirmed" AND starts_at BETWEEN :start AND :end',
                ['tenant_id' => $tenantId] + $date
            ),
            'appointments_completed' => $this->scalar(
                'SELECT COUNT(*) FROM calendar_appointments WHERE tenant_id = :tenant_id AND status = "completed" AND starts_at BETWEEN :start AND :end',
                ['tenant_id' => $tenantId] + $date
            ),
            'appointments_rejected' => $this->scalar(
                'SELECT COUNT(*) FROM calendar_appointments WHERE tenant_id = :tenant_id AND status = "rejected" AND starts_at BETWEEN :start AND :end',
                ['tenant_id' => $tenantId] + $date
            ),
            'appointments_cancelled' => $this->scalar(
                'SELECT COUNT(*) FROM calendar_appointments WHERE tenant_id = :tenant_id AND status = "cancelled" AND starts_at BETWEEN :start AND :end',
                ['tenant_id' => $tenantId] + $date
            ),
            'appointments_no_show' => $this->scalar(
                'SELECT COUNT(*) FROM calendar_appointments WHERE tenant_id = :tenant_id AND status = "no_show" AND starts_at BETWEEN :start AND :end',
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

        // Card executivo: somente ciclos operacionais. Dados reconstruídos por
        // migrations permanecem disponíveis na auditoria detalhada.
        $operationalResponses = $this->executivePolicy->operationalFirstResponses(
            $tenantId,
            $date['start'],
            $date['end']
        );
        $serviceMetrics = $this->executivePolicy->operationalServiceMetrics(
            $tenantId,
            $date['start'],
            $date['end'],
            (int) ($filters['sla_minutes'] ?? 30)
        );
        $metrics['avg_first_response_seconds'] = $operationalResponses['average_seconds'];
        $metrics['first_responses_measured'] = $operationalResponses['count'];
        $metrics['min_first_response_seconds'] = $operationalResponses['min_seconds'];
        $metrics['max_first_response_seconds'] = $operationalResponses['max_seconds'];
        $metrics['avg_service_duration_seconds'] = (int) ($serviceMetrics['avg_service_duration_seconds'] ?? 0);
        $metrics['service_cycles_closed'] = (int) ($serviceMetrics['closed_cycles'] ?? 0);
        $metrics['sla_target_minutes'] = (int) ($serviceMetrics['sla_target_minutes'] ?? 30);
        $metrics['sla_measured'] = (int) ($serviceMetrics['sla_measured'] ?? 0);
        $metrics['sla_met'] = (int) ($serviceMetrics['sla_met'] ?? 0);
        $metrics['sla_breached'] = (int) ($serviceMetrics['sla_breached'] ?? 0);
        $metrics['sla_compliance'] = (float) ($serviceMetrics['sla_compliance'] ?? 0);
        $metrics['waiting_now'] = (int) ($serviceMetrics['waiting_now'] ?? 0);
        $metrics['waiting_over_sla'] = (int) ($serviceMetrics['waiting_over_sla'] ?? 0);
        $metrics['avg_current_wait_seconds'] = (int) ($serviceMetrics['avg_current_wait_seconds'] ?? 0);
        $metrics['max_current_wait_seconds'] = (int) ($serviceMetrics['max_current_wait_seconds'] ?? 0);

        $metrics['attendance_rate'] = ((int) $metrics['appointments_completed'] + (int) $metrics['appointments_no_show']) > 0
            ? round(((int) $metrics['appointments_completed'] / ((int) $metrics['appointments_completed'] + (int) $metrics['appointments_no_show'])) * 100, 1)
            : 0;
        $metrics['operational_attention'] = (int) $metrics['open_incidents']
            + (int) $metrics['failed_messages']
            + (int) $metrics['ai_errors']
            + (int) $metrics['google_sync_errors'];
        // Mantido por compatibilidade com telas/integrações antigas. A UI do
        // cliente usa attention_conversations, que corresponde à lista exibida.
        $metrics['situations_open'] = $metrics['attention_conversations'];

        $metrics['total_messages'] = (int) $metrics['incoming_messages'] + (int) $metrics['outgoing_messages'];
        $attributedShares = $this->executivePolicy->attributedResponseShares(
            (int) $metrics['ai_replies'],
            (int) $metrics['human_replies']
        );
        $metrics['attributed_response_base'] = $attributedShares['base'];
        $metrics['ai_share'] = $attributedShares['ai_share'];
        $metrics['human_share'] = $attributedShares['human_share'];
        $metrics['system_share'] = (int) $metrics['outgoing_messages'] > 0
            ? round(((int) $metrics['system_replies'] / (int) $metrics['outgoing_messages']) * 100, 1)
            : 0;
        $metrics['crm_conversion'] = (int) $metrics['crm_leads'] > 0
            ? round(((int) $metrics['crm_won'] / (int) $metrics['crm_leads']) * 100, 1)
            : 0;
        $metrics['appointments_successful'] = (int) $metrics['appointments_confirmed'] + (int) $metrics['appointments_completed'];
        $metrics['agenda_conversion'] = (int) $metrics['appointments'] > 0
            ? round(((int) $metrics['appointments_successful'] / (int) $metrics['appointments']) * 100, 1)
            : 0;
        $metrics['avg_messages_per_conversation'] = (int) $metrics['active_conversations'] > 0
            ? round((int) $metrics['total_messages'] / (int) $metrics['active_conversations'], 1)
            : 0;

        $previousDate = $this->previousDateParams($filters, $timezone);
        $previousServiceMetrics = $this->executivePolicy->operationalServiceMetrics(
            $tenantId,
            $previousDate['start'],
            $previousDate['end'],
            (int) ($filters['sla_minutes'] ?? 30)
        );
        $previousMetrics = [
            'active_conversations' => $this->scalar(
                'SELECT COUNT(DISTINCT conversation_id)
                 FROM conversation_messages
                 WHERE tenant_id = :tenant_id AND sent_at BETWEEN :start AND :end',
                ['tenant_id' => $tenantId] + $previousDate
            ),
            'conversations' => $this->scalar(
                'SELECT COUNT(*) FROM conversations WHERE tenant_id = :tenant_id AND created_at BETWEEN :start AND :end',
                ['tenant_id' => $tenantId] + $previousDate
            ),
            'responded_conversations' => $this->scalar(
                'SELECT COUNT(DISTINCT conversation_id)
                 FROM conversation_messages
                 WHERE tenant_id = :tenant_id
                   AND direction = "outgoing"
                   AND sender_type IN ("ai", "user")
                   AND sent_at BETWEEN :start AND :end',
                ['tenant_id' => $tenantId] + $previousDate
            ),
            'human_conversations' => $this->scalar(
                'SELECT COUNT(DISTINCT conversation_id)
                 FROM conversation_messages
                 WHERE tenant_id = :tenant_id
                   AND direction = "outgoing"
                   AND sender_type = "user"
                   AND sent_at BETWEEN :start AND :end',
                ['tenant_id' => $tenantId] + $previousDate
            ),
            'contacts' => $this->scalar(
                'SELECT COUNT(*) FROM contacts WHERE tenant_id = :tenant_id AND created_at BETWEEN :start AND :end',
                ['tenant_id' => $tenantId] + $previousDate
            ),
            'total_messages' => $this->scalar(
                'SELECT COUNT(*) FROM conversation_messages WHERE tenant_id = :tenant_id AND sent_at BETWEEN :start AND :end',
                ['tenant_id' => $tenantId] + $previousDate
            ),
            'ai_replies' => $this->scalar(
                'SELECT COUNT(*) FROM conversation_messages WHERE tenant_id = :tenant_id AND direction = "outgoing" AND sender_type = "ai" AND sent_at BETWEEN :start AND :end',
                ['tenant_id' => $tenantId] + $previousDate
            ),
            'appointments_successful' => $this->scalar(
                'SELECT COUNT(*) FROM calendar_appointments WHERE tenant_id = :tenant_id AND status IN ("confirmed","completed") AND starts_at BETWEEN :start AND :end',
                ['tenant_id' => $tenantId] + $previousDate
            ),
            'crm_won' => $this->scalar(
                'SELECT COUNT(*) FROM crm_leads WHERE tenant_id = :tenant_id AND status = "won" AND created_at BETWEEN :start AND :end',
                ['tenant_id' => $tenantId] + $previousDate
            ),
            'avg_service_duration_seconds' => (int) ($previousServiceMetrics['avg_service_duration_seconds'] ?? 0),
            'sla_compliance' => (float) ($previousServiceMetrics['sla_compliance'] ?? 0),
        ];
        $comparisons = [
            'active_conversations' => $this->percentChange((int) $metrics['active_conversations'], (int) $previousMetrics['active_conversations']),
            'conversations' => $this->percentChange((int) $metrics['conversations'], (int) $previousMetrics['conversations']),
            'responded_conversations' => $this->percentChange((int) $metrics['responded_conversations'], (int) $previousMetrics['responded_conversations']),
            'human_conversations' => $this->percentChange((int) $metrics['human_conversations'], (int) $previousMetrics['human_conversations']),
            'contacts' => $this->percentChange((int) $metrics['contacts'], (int) $previousMetrics['contacts']),
            'total_messages' => $this->percentChange((int) $metrics['total_messages'], (int) $previousMetrics['total_messages']),
            'ai_replies' => $this->percentChange((int) $metrics['ai_replies'], (int) $previousMetrics['ai_replies']),
            'avg_service_duration_seconds' => $this->percentChange((int) $metrics['avg_service_duration_seconds'], (int) $previousMetrics['avg_service_duration_seconds']),
            'sla_compliance' => $this->percentChange((float) $metrics['sla_compliance'], (float) $previousMetrics['sla_compliance']),
            'appointments_successful' => $this->percentChange((int) $metrics['appointments_successful'], (int) $previousMetrics['appointments_successful']),
            'crm_won' => $this->percentChange((int) $metrics['crm_won'], (int) $previousMetrics['crm_won']),
        ];

        $byHour = $series['by_hour'];
        $heatmap = $series['heatmap'];

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

        $agendaAvailability = [
            ['label' => 'Consultas de disponibilidade', 'total' => (int) ($metrics['availability_requests'] ?? 0)],
            ['label' => 'Opções apresentadas', 'total' => (int) ($metrics['availability_slots'] ?? 0)],
            ['label' => 'Escolhas registradas', 'total' => (int) ($metrics['availability_selected_slots'] ?? 0)],
        ];
        $agendaResults = [
            ['label' => 'Aguardando confirmação', 'total' => (int) ($metrics['appointments_pending'] ?? 0), 'tone' => 'neutral'],
            ['label' => 'Agendados', 'total' => (int) ($metrics['appointments_scheduled'] ?? 0), 'tone' => 'neutral'],
            ['label' => 'Confirmados', 'total' => (int) ($metrics['appointments_confirmed'] ?? 0), 'tone' => 'positive'],
            ['label' => 'Concluídos', 'total' => (int) ($metrics['appointments_completed'] ?? 0), 'tone' => 'positive'],
            ['label' => 'Rejeitados', 'total' => (int) ($metrics['appointments_rejected'] ?? 0), 'tone' => 'attention'],
            ['label' => 'Cancelados', 'total' => (int) ($metrics['appointments_cancelled'] ?? 0), 'tone' => 'attention'],
            ['label' => 'Não compareceram', 'total' => (int) ($metrics['appointments_no_show'] ?? 0), 'tone' => 'attention'],
        ];

        $insights = $this->buildInsights($metrics, $comparisons, $heatmap);

        return compact(
            'metrics', 'comparisons', 'previousMetrics', 'byDay', 'byHour', 'heatmap', 'crmByStage', 'agendaByStatus',
            'agendaAvailability', 'agendaResults', 'insights', 'teamPerformance', 'topContacts', 'byTenant', 'attention', 'recentInvoices'
        ) + ['warnings' => array_values(array_unique($this->warnings))];
    }


    private function previousDateParams(array $filters, string $timezone): array
    {
        $timezone = Clock::safeTimezone($timezone);
        $zone = new \DateTimeZone($timezone);
        $start = new DateTimeImmutable((string) $filters['start'], $zone);
        $end = new DateTimeImmutable((string) $filters['end'], $zone);
        $days = max(1, (int) $start->diff($end)->days + 1);
        $previousEnd = $start->modify('-1 day');
        $previousStart = $previousEnd->modify('-' . ($days - 1) . ' days');
        $utc = Clock::localRangeToUtc(
            $previousStart->format('Y-m-d'),
            $previousEnd->format('Y-m-d'),
            $timezone
        );

        return [
            'start' => $utc['start'],
            'end' => $utc['end'],
        ];
    }

    private function percentChange(int|float $current, int|float $previous): ?float
    {
        if ((float) $previous === 0.0) {
            return (float) $current === 0.0 ? 0.0 : null;
        }
        return round((((float) $current - (float) $previous) / abs((float) $previous)) * 100, 1);
    }

    private function buildInsights(array $metrics, array $comparisons, array $heatmap): array
    {
        $insights = [];
        $conversationChange = $comparisons['conversations'] ?? null;
        if ($conversationChange !== null && abs((float) $conversationChange) >= 5) {
            $direction = (float) $conversationChange >= 0 ? 'cresceu' : 'caiu';
            $insights[] = [
                'tone' => (float) $conversationChange >= 0 ? 'positive' : 'attention',
                'title' => 'Movimento do atendimento',
                'text' => 'O volume de conversas ' . $direction . ' ' . number_format(abs((float) $conversationChange), 1, ',', '.') . '% em relação ao período anterior.',
            ];
        }

        if ((float) ($metrics['ai_share'] ?? 0) > 0) {
            $insights[] = [
                'tone' => (float) $metrics['ai_share'] >= 60 ? 'positive' : 'info',
                'title' => 'Participação da IA',
                'text' => number_format((float) $metrics['ai_share'], 1, ',', '.') . '% das respostas enviadas no período foram feitas pela IA.',
            ];
        }

        $peak = null;
        foreach ($heatmap as $row) {
            if ($peak === null || (int) ($row['total'] ?? 0) > (int) ($peak['total'] ?? 0)) {
                $peak = $row;
            }
        }
        if ($peak && (int) ($peak['total'] ?? 0) > 0) {
            $days = ['segunda-feira', 'terça-feira', 'quarta-feira', 'quinta-feira', 'sexta-feira', 'sábado', 'domingo'];
            $day = $days[(int) ($peak['weekday_index'] ?? 0)] ?? 'dia não identificado';
            $hour = str_pad((string) ((int) ($peak['hour_index'] ?? 0)), 2, '0', STR_PAD_LEFT) . 'h';
            $insights[] = [
                'tone' => 'info',
                'title' => 'Horário de maior procura',
                'text' => ucfirst($day) . ', por volta de ' . $hour . ', concentrou o maior volume de mensagens recebidas.',
            ];
        }

        $selected = (int) ($metrics['availability_selected_slots'] ?? 0);
        if ($selected > 0) {
            $insights[] = [
                'tone' => 'info',
                'title' => 'Uso da disponibilidade automática',
                'text' => $selected . ' escolha(s) de horário foram registradas pelo ciclo automático no período.',
            ];
        }

        $rejected = (int) ($metrics['appointments_rejected'] ?? 0);
        if ($rejected > 0) {
            $insights[] = [
                'tone' => 'attention',
                'title' => 'Pré-agendamentos rejeitados',
                'text' => $rejected . ' solicitação(ões) de agenda ficaram com status rejeitado no período.',
            ];
        }

        if ((int) ($metrics['failed_messages'] ?? 0) > 0) {
            $insights[] = [
                'tone' => 'attention',
                'title' => 'Mensagens com falha',
                'text' => (int) $metrics['failed_messages'] . ' mensagem(ns) tiveram status de falha no período e merecem revisão.',
            ];
        }

        return array_slice($insights, 0, 5);
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

    private function tenantTimezone(int $tenantId): string
    {
        $rows = $this->rows(
            'SELECT COALESCE(NULLIF(os.business_timezone, ""), NULLIF(cas.timezone, ""), "America/Sao_Paulo") AS timezone
             FROM tenants t
             LEFT JOIN tenant_onboarding_settings os ON os.tenant_id = t.id
             LEFT JOIN tenant_calendar_availability_settings cas ON cas.tenant_id = t.id
             WHERE t.id = :tenant_id
             LIMIT 1',
            ['tenant_id' => $tenantId]
        );

        return Clock::safeTimezone((string) ($rows[0]['timezone'] ?? 'America/Sao_Paulo'));
    }

    private function dateParams(array $filters, string $timezone): array
    {
        $utc = Clock::localRangeToUtc(
            (string) $filters['start'],
            (string) $filters['end'],
            $timezone
        );

        return [
            'start' => $utc['start'],
            'end' => $utc['end'],
        ];
    }

    /**
     * Constrói séries de mensagens em horas UTC (no máximo 24 linhas por dia)
     * e converte os baldes para o fuso do tenant em PHP. Evita depender das
     * tabelas de timezone do MySQL e mantém dias/horas coerentes com o filtro.
     *
     * @return array{by_day:array<int,array<string,int|string>>,by_hour:array<int,array<string,int>>,heatmap:array<int,array<string,int>>}
     */
    private function messageSeries(int $tenantId, array $date, string $timezone): array
    {
        $rows = $this->rows(
            'SELECT DATE_FORMAT(sent_at, "%Y-%m-%d %H:00:00") AS utc_hour,
                    COUNT(*) AS total,
                    SUM(direction = "incoming") AS incoming,
                    SUM(direction = "outgoing") AS outgoing,
                    SUM(direction = "outgoing" AND sender_type = "ai") AS ai,
                    SUM(direction = "outgoing" AND sender_type = "user") AS human,
                    SUM(direction = "outgoing" AND sender_type NOT IN ("ai","user")) AS system_messages
             FROM conversation_messages
             WHERE tenant_id = :tenant_id AND sent_at BETWEEN :start AND :end
             GROUP BY DATE_FORMAT(sent_at, "%Y-%m-%d %H:00:00")
             ORDER BY utc_hour ASC',
            [
                'tenant_id' => $tenantId,
                'start' => (string) ($date['start'] ?? ''),
                'end' => (string) ($date['end'] ?? ''),
            ]
        );

        $timezone = Clock::safeTimezone($timezone);
        $days = [];
        $hours = [];
        $heatmap = [];

        foreach ($rows as $row) {
            $utcHour = (string) ($row['utc_hour'] ?? '');
            if ($utcHour === '') {
                continue;
            }
            $localDay = Clock::utcToLocal($utcHour, $timezone, 'Y-m-d');
            $localHour = (int) Clock::utcToLocal($utcHour, $timezone, 'G');
            $weekday = (int) Clock::utcToLocal($utcHour, $timezone, 'N') - 1;

            if (!isset($days[$localDay])) {
                $days[$localDay] = [
                    'label' => $localDay,
                    'total' => 0,
                    'incoming' => 0,
                    'outgoing' => 0,
                    'ai' => 0,
                    'human' => 0,
                    'system_messages' => 0,
                ];
            }
            foreach (['total', 'incoming', 'outgoing', 'ai', 'human', 'system_messages'] as $metric) {
                $days[$localDay][$metric] += (int) ($row[$metric] ?? 0);
            }

            $incoming = (int) ($row['incoming'] ?? 0);
            if ($incoming > 0) {
                $hours[$localHour] = ($hours[$localHour] ?? 0) + $incoming;
                $key = $weekday . ':' . $localHour;
                $heatmap[$key] = ($heatmap[$key] ?? 0) + $incoming;
            }
        }

        ksort($days);
        ksort($hours, SORT_NUMERIC);
        $byHour = [];
        foreach ($hours as $hour => $total) {
            $byHour[] = ['label' => (int) $hour, 'total' => (int) $total];
        }

        $heatmapRows = [];
        foreach ($heatmap as $key => $total) {
            [$weekday, $hour] = array_map('intval', explode(':', $key, 2));
            $heatmapRows[] = [
                'weekday_index' => $weekday,
                'hour_index' => $hour,
                'total' => (int) $total,
            ];
        }
        usort($heatmapRows, static fn (array $a, array $b): int =>
            [$a['weekday_index'], $a['hour_index']] <=> [$b['weekday_index'], $b['hour_index']]
        );

        return [
            'by_day' => array_values($days),
            'by_hour' => $byHour,
            'heatmap' => $heatmapRows,
        ];
    }

    private function warning(Throwable $exception): string
    {
        $message = $exception->getMessage();
        error_log('[reports.client] ' . preg_replace('/\s+/', ' ', $message));
        if (preg_match('/Table [^ ]+\.([^ ]+) doesn/', $message, $matches)) {
            return 'Tabela pendente: ' . trim($matches[1], "'`");
        }
        return 'Um indicador complementar está temporariamente indisponível.';
    }
}
