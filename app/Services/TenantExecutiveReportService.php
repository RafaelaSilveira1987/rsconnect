<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DateTimeImmutable;
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
        $this->warnings = [];

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
                error_log('[reports.client.aggregate] ' . preg_replace('/\s+/', ' ', $exception->getMessage()));
                $this->warnings[] = 'A atualização dos dados diários encontrou uma inconsistência; o relatório usou dados operacionais.';
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

        // Primeira resposta real: primeira saída de IA ou equipe APÓS a primeira mensagem recebida.
        // Mensagens de sistema não entram nessa métrica e uma automação anterior à entrada do contato
        // não invalida a conversa inteira.
        $metrics['avg_first_response_seconds'] = $this->scalar(
            'SELECT COALESCE(AVG(TIMESTAMPDIFF(SECOND, x.first_incoming, x.first_response)), 0)
             FROM (
                SELECT fi.conversation_id, fi.first_incoming, MIN(response.sent_at) AS first_response
                FROM (
                    SELECT conversation_id, MIN(sent_at) AS first_incoming
                    FROM conversation_messages
                    WHERE tenant_id = :tenant_id_in
                      AND direction = "incoming"
                      AND sent_at BETWEEN :start_in AND :end_in
                    GROUP BY conversation_id
                ) fi
                INNER JOIN conversation_messages response
                        ON response.conversation_id = fi.conversation_id
                       AND response.tenant_id = :tenant_id_out
                       AND response.direction = "outgoing"
                       AND response.sender_type IN ("ai", "user")
                       AND response.sent_at >= fi.first_incoming
                       AND response.sent_at BETWEEN :start_out AND :end_out
                GROUP BY fi.conversation_id, fi.first_incoming
             ) x',
            [
                'tenant_id_in' => $tenantId,
                'start_in' => $date['start'],
                'end_in' => $date['end'],
                'tenant_id_out' => $tenantId,
                'start_out' => $date['start'],
                'end_out' => $date['end'],
            ]
        );

        $metrics['total_messages'] = (int) $metrics['incoming_messages'] + (int) $metrics['outgoing_messages'];
        $metrics['ai_share'] = (int) $metrics['outgoing_messages'] > 0
            ? round(((int) $metrics['ai_replies'] / (int) $metrics['outgoing_messages']) * 100, 1)
            : 0;
        $metrics['human_share'] = (int) $metrics['outgoing_messages'] > 0
            ? round(((int) $metrics['human_replies'] / (int) $metrics['outgoing_messages']) * 100, 1)
            : 0;
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
        $metrics['avg_messages_per_conversation'] = (int) $metrics['conversations'] > 0
            ? round((int) $metrics['total_messages'] / (int) $metrics['conversations'], 1)
            : 0;

        $previousDate = $this->previousDateParams($filters);
        $previousMetrics = [
            'conversations' => $this->scalar(
                'SELECT COUNT(*) FROM conversations WHERE tenant_id = :tenant_id AND created_at BETWEEN :start AND :end',
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
        ];
        $comparisons = [
            'conversations' => $this->percentChange((int) $metrics['conversations'], (int) $previousMetrics['conversations']),
            'contacts' => $this->percentChange((int) $metrics['contacts'], (int) $previousMetrics['contacts']),
            'total_messages' => $this->percentChange((int) $metrics['total_messages'], (int) $previousMetrics['total_messages']),
            'ai_replies' => $this->percentChange((int) $metrics['ai_replies'], (int) $previousMetrics['ai_replies']),
            'appointments_successful' => $this->percentChange((int) $metrics['appointments_successful'], (int) $previousMetrics['appointments_successful']),
            'crm_won' => $this->percentChange((int) $metrics['crm_won'], (int) $previousMetrics['crm_won']),
        ];

        if ($byDay === []) {
            $byDay = $this->rows(
                'SELECT DATE(sent_at) AS label, COUNT(*) AS total,
                        SUM(direction = "incoming") AS incoming,
                        SUM(direction = "outgoing") AS outgoing,
                        SUM(direction = "outgoing" AND sender_type = "ai") AS ai,
                        SUM(direction = "outgoing" AND sender_type = "user") AS human,
                        SUM(direction = "outgoing" AND sender_type NOT IN ("ai","user")) AS system_messages
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

        $heatmap = $this->rows(
            'SELECT WEEKDAY(sent_at) AS weekday_index, HOUR(sent_at) AS hour_index, COUNT(*) AS total
             FROM conversation_messages
             WHERE tenant_id = :tenant_id AND direction = "incoming" AND sent_at BETWEEN :start AND :end
             GROUP BY WEEKDAY(sent_at), HOUR(sent_at)
             ORDER BY weekday_index, hour_index',
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

        $agendaAvailability = [
            ['label' => 'Consultas de disponibilidade', 'total' => (int) ($metrics['availability_requests'] ?? 0)],
            ['label' => 'Opções apresentadas', 'total' => (int) ($metrics['availability_slots'] ?? 0)],
            ['label' => 'Escolhas registradas', 'total' => (int) ($metrics['availability_selected_slots'] ?? 0)],
        ];
        $agendaResults = [
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
        error_log('[reports.client] ' . preg_replace('/\s+/', ' ', $message));
        if (preg_match('/Table [^ ]+\.([^ ]+) doesn/', $message, $matches)) {
            return 'Tabela pendente: ' . trim($matches[1], "'`");
        }
        return 'Um indicador complementar está temporariamente indisponível.';
    }
}
