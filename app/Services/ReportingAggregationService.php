<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use PDO;
use Throwable;

/**
 * Mantém a camada derivada de métricas dos relatórios.
 *
 * A fonte de verdade continua nas tabelas operacionais. report_daily_metrics
 * pode ser apagada e reconstruída a qualquer momento.
 */
final class ReportingAggregationService
{
    private const MAX_RANGE_DAYS = 366;
    private const METRICS_VERSION = 2;

    private PDO $pdo;
    private array $warnings = [];

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connection();
    }

    public function isAvailable(): bool
    {
        try {
            $statement = $this->pdo->query(
                "SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'report_daily_metrics'"
            );
            return (int) $statement->fetchColumn() === 1;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Garante que o intervalo tenha cache materializado.
     * Dias históricos existentes são preservados; hoje e ontem são atualizados
     * para absorver mensagens atrasadas e mudanças recentes de status.
     */
    public function ensureRange(?int $tenantId, string $start, string $end): void
    {
        if (!$this->isAvailable()) {
            return;
        }

        [$startDate, $endDate] = $this->normalizeRange($start, $end);
        $tenantIds = $this->tenantIds($tenantId);
        if ($tenantIds === []) {
            return;
        }

        $expectedRows = count($tenantIds) * $this->dayCount($startDate, $endDate);
        $actualRows = $this->countCachedRows($tenantId, $startDate, $endDate);

        if ($actualRows < $expectedRows) {
            $this->rebuildRange($tenantId, $startDate->format('Y-m-d'), $endDate->format('Y-m-d'));
            return;
        }

        $today = new DateTimeImmutable('today');
        $yesterday = $today->sub(new DateInterval('P1D'));
        foreach ([$yesterday, $today] as $volatileDate) {
            if ($volatileDate >= $startDate && $volatileDate <= $endDate) {
                $this->rebuildRange($tenantId, $volatileDate->format('Y-m-d'), $volatileDate->format('Y-m-d'));
            }
        }
    }

    public function rebuildRange(?int $tenantId, string $start, string $end): void
    {
        if (!$this->isAvailable()) {
            return;
        }

        [$startDate, $endDate] = $this->normalizeRange($start, $end);
        $tenantIds = $this->tenantIds($tenantId);
        if ($tenantIds === []) {
            return;
        }

        $this->pdo->beginTransaction();
        try {
            $this->deleteRange($tenantId, $startDate, $endDate);
            $this->seedRange($tenantIds, $startDate, $endDate);

            $dateParams = [
                'start' => $startDate->format('Y-m-d') . ' 00:00:00',
                'end' => $endDate->format('Y-m-d') . ' 23:59:59',
            ];

            $this->aggregateContacts($tenantId, $dateParams);
            $this->aggregateConversations($tenantId, $dateParams);
            $this->aggregateMessages($tenantId, $dateParams);
            $this->aggregateAi($tenantId, $dateParams);
            $this->aggregateN8n($tenantId, $dateParams);
            $this->aggregateAvailability($tenantId, $dateParams);
            $this->aggregateAppointments($tenantId, $dateParams);
            $this->aggregateGoogleSync($tenantId, $dateParams);
            $this->aggregateCrm($tenantId, $dateParams);

            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function totals(?int $tenantId, string $start, string $end): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        [$startDate, $endDate] = $this->normalizeRange($start, $end);
        [$scope, $params] = $this->metricScope($tenantId);
        $params += [
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d'),
        ];

        $columns = [
            'contacts_new', 'conversations_started', 'conversations_closed',
            'messages_incoming', 'messages_outgoing', 'messages_ai', 'messages_human', 'messages_failed',
            'ai_success', 'ai_errors', 'n8n_success', 'n8n_errors',
            'availability_requests', 'availability_slots', 'availability_selected_slots',
            'appointments_total', 'appointments_scheduled', 'appointments_confirmed', 'appointments_completed',
            'appointments_cancelled', 'appointments_no_show',
            'google_sync_success', 'google_sync_errors',
            'crm_leads_created', 'crm_won', 'crm_lost', 'crm_value_won',
        ];
        $select = implode(', ', array_map(
            static fn (string $column): string => "COALESCE(SUM({$column}),0) AS {$column}",
            $columns
        ));

        $statement = $this->pdo->prepare(
            "SELECT {$select}
             FROM report_daily_metrics
             WHERE metric_date BETWEEN :start_date AND :end_date{$scope}"
        );
        $statement->execute($params);
        return $statement->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    public function dailySeries(?int $tenantId, string $start, string $end): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        [$startDate, $endDate] = $this->normalizeRange($start, $end);
        [$scope, $params] = $this->metricScope($tenantId);
        $params += [
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d'),
        ];

        $statement = $this->pdo->prepare(
            'SELECT metric_date AS label,
                    SUM(messages_incoming + messages_outgoing) AS total,
                    SUM(messages_incoming) AS incoming,
                    SUM(messages_outgoing) AS outgoing,
                    SUM(messages_ai) AS ai,
                    SUM(messages_human) AS human,
                    GREATEST(CAST(SUM(messages_outgoing) AS SIGNED) - CAST(SUM(messages_ai) AS SIGNED) - CAST(SUM(messages_human) AS SIGNED), 0) AS system,
                    SUM(contacts_new) AS contacts,
                    SUM(conversations_started) AS conversations,
                    SUM(appointments_total) AS appointments
             FROM report_daily_metrics
             WHERE metric_date BETWEEN :start_date AND :end_date' . $scope . '
             GROUP BY metric_date
             ORDER BY metric_date ASC'
        );
        $statement->execute($params);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function warnings(): array
    {
        return array_values(array_unique($this->warnings));
    }

    private function aggregateContacts(?int $tenantId, array $dateParams): void
    {
        $this->aggregate(
            'contacts',
            'SELECT tenant_id, DATE(created_at) AS metric_date, COUNT(*) AS contacts_new
             FROM contacts
             WHERE created_at BETWEEN :start AND :end' . $this->tenantWhere($tenantId) . '
             GROUP BY tenant_id, DATE(created_at)',
            $this->params($tenantId, $dateParams),
            ['contacts_new']
        );
    }

    private function aggregateConversations(?int $tenantId, array $dateParams): void
    {
        $params = $this->params($tenantId, $dateParams);
        $this->aggregate(
            'conversations',
            'SELECT tenant_id, DATE(created_at) AS metric_date, COUNT(*) AS conversations_started
             FROM conversations
             WHERE created_at BETWEEN :start AND :end' . $this->tenantWhere($tenantId) . '
             GROUP BY tenant_id, DATE(created_at)',
            $params,
            ['conversations_started']
        );
        $this->aggregate(
            'conversations',
            'SELECT tenant_id, DATE(updated_at) AS metric_date, COUNT(*) AS conversations_closed
             FROM conversations
             WHERE status = "closed" AND updated_at BETWEEN :start AND :end' . $this->tenantWhere($tenantId) . '
             GROUP BY tenant_id, DATE(updated_at)',
            $params,
            ['conversations_closed']
        );
    }

    private function aggregateMessages(?int $tenantId, array $dateParams): void
    {
        $this->aggregate(
            'conversation_messages',
            'SELECT tenant_id, DATE(sent_at) AS metric_date,
                    SUM(direction = "incoming") AS messages_incoming,
                    SUM(direction = "outgoing") AS messages_outgoing,
                    SUM(direction = "outgoing" AND sender_type = "ai") AS messages_ai,
                    SUM(direction = "outgoing" AND sender_type = "user") AS messages_human,
                    SUM(status = "failed") AS messages_failed
             FROM conversation_messages
             WHERE sent_at BETWEEN :start AND :end' . $this->tenantWhere($tenantId) . '
             GROUP BY tenant_id, DATE(sent_at)',
            $this->params($tenantId, $dateParams),
            ['messages_incoming', 'messages_outgoing', 'messages_ai', 'messages_human', 'messages_failed']
        );
    }

    private function aggregateAi(?int $tenantId, array $dateParams): void
    {
        $this->aggregate(
            'ai_automation_logs',
            'SELECT tenant_id, DATE(created_at) AS metric_date,
                    SUM(status = "success") AS ai_success,
                    SUM(status = "error") AS ai_errors
             FROM ai_automation_logs
             WHERE created_at BETWEEN :start AND :end' . $this->tenantWhere($tenantId) . '
             GROUP BY tenant_id, DATE(created_at)',
            $this->params($tenantId, $dateParams),
            ['ai_success', 'ai_errors']
        );
    }

    private function aggregateN8n(?int $tenantId, array $dateParams): void
    {
        $this->aggregate(
            'n8n_flow_logs',
            'SELECT tenant_id, DATE(created_at) AS metric_date,
                    SUM(status = "success") AS n8n_success,
                    SUM(status = "error") AS n8n_errors
             FROM n8n_flow_logs
             WHERE created_at BETWEEN :start AND :end' . $this->tenantWhere($tenantId) . '
             GROUP BY tenant_id, DATE(created_at)',
            $this->params($tenantId, $dateParams),
            ['n8n_success', 'n8n_errors']
        );
    }

    private function aggregateAvailability(?int $tenantId, array $dateParams): void
    {
        $params = $this->params($tenantId, $dateParams);
        $this->aggregate(
            'calendar_availability_requests',
            'SELECT tenant_id, DATE(COALESCE(requested_at, created_at)) AS metric_date,
                    COUNT(*) AS availability_requests
             FROM calendar_availability_requests
             WHERE COALESCE(requested_at, created_at) BETWEEN :start AND :end' . $this->tenantWhere($tenantId) . '
             GROUP BY tenant_id, DATE(COALESCE(requested_at, created_at))',
            $params,
            ['availability_requests']
        );
        $this->aggregate(
            'calendar_availability_slots',
            'SELECT tenant_id, DATE(created_at) AS metric_date,
                    COUNT(*) AS availability_slots
             FROM calendar_availability_slots
             WHERE created_at BETWEEN :start AND :end' . $this->tenantWhere($tenantId) . '
             GROUP BY tenant_id, DATE(created_at)',
            $params,
            ['availability_slots']
        );
        $this->aggregate(
            'calendar_availability_slots',
            'SELECT tenant_id, DATE(selected_at) AS metric_date,
                    COUNT(*) AS availability_selected_slots
             FROM calendar_availability_slots
             WHERE selected_at BETWEEN :start AND :end' . $this->tenantWhere($tenantId) . '
             GROUP BY tenant_id, DATE(selected_at)',
            $params,
            ['availability_selected_slots']
        );
    }

    private function aggregateAppointments(?int $tenantId, array $dateParams): void
    {
        $this->aggregate(
            'calendar_appointments',
            'SELECT tenant_id, DATE(starts_at) AS metric_date,
                    COUNT(*) AS appointments_total,
                    SUM(status = "scheduled") AS appointments_scheduled,
                    SUM(status = "confirmed") AS appointments_confirmed,
                    SUM(status = "completed") AS appointments_completed,
                    SUM(status = "cancelled") AS appointments_cancelled,
                    SUM(status = "no_show") AS appointments_no_show
             FROM calendar_appointments
             WHERE starts_at BETWEEN :start AND :end' . $this->tenantWhere($tenantId) . '
             GROUP BY tenant_id, DATE(starts_at)',
            $this->params($tenantId, $dateParams),
            [
                'appointments_total', 'appointments_scheduled', 'appointments_confirmed',
                'appointments_completed', 'appointments_cancelled', 'appointments_no_show',
            ]
        );
    }

    private function aggregateGoogleSync(?int $tenantId, array $dateParams): void
    {
        $this->aggregate(
            'calendar_google_sync_logs',
            'SELECT tenant_id, DATE(created_at) AS metric_date,
                    SUM(status = "success") AS google_sync_success,
                    SUM(status <> "success") AS google_sync_errors
             FROM calendar_google_sync_logs
             WHERE created_at BETWEEN :start AND :end' . $this->tenantWhere($tenantId) . '
             GROUP BY tenant_id, DATE(created_at)',
            $this->params($tenantId, $dateParams),
            ['google_sync_success', 'google_sync_errors']
        );
    }

    private function aggregateCrm(?int $tenantId, array $dateParams): void
    {
        $params = $this->params($tenantId, $dateParams);
        $this->aggregate(
            'crm_leads',
            'SELECT tenant_id, DATE(created_at) AS metric_date,
                    COUNT(*) AS crm_leads_created
             FROM crm_leads
             WHERE created_at BETWEEN :start AND :end' . $this->tenantWhere($tenantId) . '
             GROUP BY tenant_id, DATE(created_at)',
            $params,
            ['crm_leads_created']
        );
        $this->aggregate(
            'crm_leads',
            'SELECT tenant_id, DATE(created_at) AS metric_date,
                    SUM(status = "won") AS crm_won,
                    SUM(status = "lost") AS crm_lost,
                    COALESCE(SUM(CASE WHEN status = "won" THEN value ELSE 0 END),0) AS crm_value_won
             FROM crm_leads
             WHERE created_at BETWEEN :start AND :end' . $this->tenantWhere($tenantId) . '
             GROUP BY tenant_id, DATE(created_at)',
            $params,
            ['crm_won', 'crm_lost', 'crm_value_won']
        );
    }

    private function aggregate(string $table, string $sql, array $params, array $columns): void
    {
        if (!$this->tableExists($table)) {
            $this->warnings[] = "Tabela pendente para agregação: {$table}.";
            return;
        }

        try {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($params);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            if ($rows === []) {
                return;
            }

            $sets = array_map(static fn (string $column): string => "`{$column}` = :{$column}", $columns);
            $update = $this->pdo->prepare(
                'UPDATE report_daily_metrics SET ' . implode(', ', $sets) . ', refreshed_at = NOW()
                 WHERE tenant_id = :tenant_id AND metric_date = :metric_date'
            );

            foreach ($rows as $row) {
                $updateParams = [
                    'tenant_id' => (int) $row['tenant_id'],
                    'metric_date' => (string) $row['metric_date'],
                ];
                foreach ($columns as $column) {
                    $updateParams[$column] = $row[$column] ?? 0;
                }
                $update->execute($updateParams);
            }
        } catch (Throwable $exception) {
            $this->warnings[] = "Falha ao agregar {$table}: " . $this->compactError($exception);
        }
    }

    private function seedRange(array $tenantIds, DateTimeImmutable $start, DateTimeImmutable $end): void
    {
        $insert = $this->pdo->prepare(
            'INSERT INTO report_daily_metrics (tenant_id, metric_date, metrics_version, refreshed_at)
             VALUES (:tenant_id, :metric_date, :metrics_version, NOW())'
        );
        foreach ($tenantIds as $tenantId) {
            foreach ($this->dates($start, $end) as $date) {
                $insert->execute([
                    'tenant_id' => $tenantId,
                    'metric_date' => $date->format('Y-m-d'),
                    'metrics_version' => self::METRICS_VERSION,
                ]);
            }
        }
    }

    private function deleteRange(?int $tenantId, DateTimeImmutable $start, DateTimeImmutable $end): void
    {
        $sql = 'DELETE FROM report_daily_metrics WHERE metric_date BETWEEN :start_date AND :end_date';
        $params = [
            'start_date' => $start->format('Y-m-d'),
            'end_date' => $end->format('Y-m-d'),
        ];
        if (($tenantId ?? 0) > 0) {
            $sql .= ' AND tenant_id = :tenant_id';
            $params['tenant_id'] = $tenantId;
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
    }

    private function tenantIds(?int $tenantId): array
    {
        if (($tenantId ?? 0) > 0) {
            $statement = $this->pdo->prepare('SELECT id FROM tenants WHERE id = :tenant_id');
            $statement->execute(['tenant_id' => $tenantId]);
            $id = $statement->fetchColumn();
            return $id ? [(int) $id] : [];
        }
        return array_map('intval', $this->pdo->query('SELECT id FROM tenants ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
    }

    private function countCachedRows(?int $tenantId, DateTimeImmutable $start, DateTimeImmutable $end): int
    {
        $sql = 'SELECT COUNT(*) FROM report_daily_metrics WHERE metric_date BETWEEN :start_date AND :end_date AND metrics_version = :metrics_version';
        $params = [
            'start_date' => $start->format('Y-m-d'),
            'end_date' => $end->format('Y-m-d'),
            'metrics_version' => self::METRICS_VERSION,
        ];
        if (($tenantId ?? 0) > 0) {
            $sql .= ' AND tenant_id = :tenant_id';
            $params['tenant_id'] = $tenantId;
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return (int) $statement->fetchColumn();
    }

    private function metricScope(?int $tenantId): array
    {
        if (($tenantId ?? 0) > 0) {
            return [' AND tenant_id = :tenant_id', ['tenant_id' => $tenantId]];
        }
        return ['', []];
    }

    private function tenantWhere(?int $tenantId): string
    {
        return ($tenantId ?? 0) > 0 ? ' AND tenant_id = :tenant_id' : '';
    }

    private function params(?int $tenantId, array $dateParams): array
    {
        return $dateParams + (($tenantId ?? 0) > 0 ? ['tenant_id' => $tenantId] : []);
    }

    private function tableExists(string $table): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name'
        );
        $statement->execute(['table_name' => $table]);
        return (int) $statement->fetchColumn() === 1;
    }

    private function normalizeRange(string $start, string $end): array
    {
        $startDate = DateTimeImmutable::createFromFormat('!Y-m-d', $start);
        $endDate = DateTimeImmutable::createFromFormat('!Y-m-d', $end);
        if (!$startDate || !$endDate) {
            throw new \InvalidArgumentException('Período inválido para agregação de relatórios.');
        }
        if ($startDate > $endDate) {
            [$startDate, $endDate] = [$endDate, $startDate];
        }
        if ($this->dayCount($startDate, $endDate) > self::MAX_RANGE_DAYS) {
            throw new \InvalidArgumentException('O motor de métricas aceita no máximo 366 dias por processamento.');
        }
        return [$startDate, $endDate];
    }

    private function dayCount(DateTimeImmutable $start, DateTimeImmutable $end): int
    {
        return (int) $start->diff($end)->days + 1;
    }

    private function dates(DateTimeImmutable $start, DateTimeImmutable $end): iterable
    {
        return new DatePeriod($start, new DateInterval('P1D'), $end->add(new DateInterval('P1D')));
    }

    private function compactError(Throwable $exception): string
    {
        $message = preg_replace('/\s+/', ' ', $exception->getMessage()) ?: 'erro desconhecido';
        return substr($message, 0, 220);
    }
}
