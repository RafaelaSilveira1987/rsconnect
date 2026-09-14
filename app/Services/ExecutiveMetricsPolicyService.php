<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use Throwable;

/**
 * Política única para os indicadores executivos equivalentes da RS Admin e
 * das empresas clientes.
 *
 * Histórico recuperado continua disponível nos relatórios de auditoria, mas
 * não entra nos cards executivos de desempenho.
 */
final class ExecutiveMetricsPolicyService
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connection();
    }

    /**
     * @return array{count:int,average_seconds:int,min_seconds:int,max_seconds:int}
     */
    public function operationalFirstResponses(?int $tenantId, string $start, string $end): array
    {
        $scope = $tenantId !== null && $tenantId > 0 ? ' AND sc.tenant_id = :tenant_id' : '';
        $params = ['start' => $start, 'end' => $end];
        if ($scope !== '') {
            $params['tenant_id'] = $tenantId;
        }

        try {
            $statement = $this->pdo->prepare(
                'SELECT sc.tenant_id, sc.first_incoming_at, sc.first_response_at,
                        sc.sla_target_minutes, sc.sla_warning_percent,
                        sc.sla_count_outside_business_hours, sc.sla_timezone,
                        sc.sla_business_hours_json
                 FROM conversation_service_cycles sc
                 WHERE sc.first_incoming_at BETWEEN :start AND :end
                   AND sc.first_response_at IS NOT NULL
                   AND sc.first_response_user_id IS NOT NULL
                   AND ' . self::operationalCycleSql('sc') . '
                   AND ' . TenantLifecycleService::productionAtSql('sc.tenant_id', 'sc.first_incoming_at') . $scope
            );
            $statement->execute($params);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            $clock = new SlaPolicyService($this->pdo);
            $seconds = [];
            foreach ($rows as $row) {
                $rowTenant = (int) ($row['tenant_id'] ?? 0);
                if ($rowTenant < 1) {
                    continue;
                }
                $policy = $clock->policyForCycle($rowTenant, $row);
                $seconds[] = $clock->elapsedSeconds(
                    $rowTenant,
                    (string) ($row['first_incoming_at'] ?? ''),
                    (string) ($row['first_response_at'] ?? ''),
                    $policy
                );
            }
            if ($seconds === []) {
                return ['count' => 0, 'average_seconds' => 0, 'min_seconds' => 0, 'max_seconds' => 0];
            }
            return [
                'count' => count($seconds),
                'average_seconds' => (int) round(array_sum($seconds) / count($seconds)),
                'min_seconds' => (int) min($seconds),
                'max_seconds' => (int) max($seconds),
            ];
        } catch (Throwable $exception) {
            error_log('[reports.executive.consistency] ' . preg_replace('/\s+/', ' ', $exception->getMessage()));
            return ['count' => 0, 'average_seconds' => 0, 'min_seconds' => 0, 'max_seconds' => 0];
        }
    }

    /**
     * Indicadores operacionais de ciclo. A duração representa o tempo corrido
     * entre abertura e encerramento do ciclo; o SLA usa a primeira resposta
     * humana persistida nos ciclos e a meta informada no filtro do relatório.
     *
     * @return array{closed_cycles:int,avg_service_duration_seconds:int,min_service_duration_seconds:int,max_service_duration_seconds:int,sla_target_minutes:int,sla_measured:int,sla_met:int,sla_breached:int,sla_compliance:float,waiting_now:int,waiting_over_sla:int,avg_current_wait_seconds:int,max_current_wait_seconds:int}
     */
    public function operationalServiceMetrics(?int $tenantId, string $start, string $end, int $slaMinutes = 30): array
    {
        $slaMinutes = max(5, min(1440, $slaMinutes));
        $slaSeconds = $slaMinutes * 60;
        $scope = $tenantId !== null && $tenantId > 0 ? ' AND sc.tenant_id = :tenant_id' : '';

        $empty = [
            'closed_cycles' => 0,
            'avg_service_duration_seconds' => 0,
            'min_service_duration_seconds' => 0,
            'max_service_duration_seconds' => 0,
            'sla_target_minutes' => $slaMinutes,
            'sla_measured' => 0,
            'sla_met' => 0,
            'sla_breached' => 0,
            'sla_compliance' => 0.0,
            'waiting_now' => 0,
            'waiting_over_sla' => 0,
            'avg_current_wait_seconds' => 0,
            'max_current_wait_seconds' => 0,
        ];

        $closedRow = [];
        try {
            $closedParams = ['start' => $start, 'end' => $end];
            if ($scope !== '') {
                $closedParams['tenant_id'] = $tenantId;
            }
            $closed = $this->pdo->prepare(
                'SELECT COUNT(*) AS closed_cycles,
                        COALESCE(ROUND(AVG(GREATEST(0, TIMESTAMPDIFF(SECOND, sc.opened_at, sc.closed_at)))), 0) AS avg_service_duration_seconds,
                        COALESCE(MIN(GREATEST(0, TIMESTAMPDIFF(SECOND, sc.opened_at, sc.closed_at))), 0) AS min_service_duration_seconds,
                        COALESCE(MAX(GREATEST(0, TIMESTAMPDIFF(SECOND, sc.opened_at, sc.closed_at))), 0) AS max_service_duration_seconds
                 FROM conversation_service_cycles sc
                 WHERE sc.cycle_status = "closed"
                   AND sc.opened_at IS NOT NULL
                   AND sc.closed_at IS NOT NULL
                   AND sc.closed_at BETWEEN :start AND :end
                   AND ' . self::operationalCycleSql('sc') . '
                   AND ' . TenantLifecycleService::productionAtSql('sc.tenant_id', 'sc.opened_at') . $scope
            );
            $closed->execute($closedParams);
            $closedRow = $closed->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $exception) {
            error_log('[reports.executive.service-cycle.closed] ' . preg_replace('/\s+/', ' ', $exception->getMessage()));
        }

        $clock = new SlaPolicyService($this->pdo);
        $slaMeasured = 0;
        $slaMet = 0;
        $slaBreached = 0;
        try {
            $slaParams = ['start' => $start, 'end' => $end];
            if ($scope !== '') {
                $slaParams['tenant_id'] = $tenantId;
            }
            $sla = $this->pdo->prepare(
                'SELECT sc.tenant_id, sc.first_incoming_at, sc.first_response_at,
                        sc.sla_target_minutes, sc.sla_warning_percent,
                        sc.sla_count_outside_business_hours, sc.sla_timezone,
                        sc.sla_business_hours_json
                 FROM conversation_service_cycles sc
                 WHERE sc.first_incoming_at IS NOT NULL
                   AND sc.first_response_at IS NOT NULL
                   AND sc.first_response_user_id IS NOT NULL
                   AND sc.first_incoming_at BETWEEN :start AND :end
                   AND ' . self::operationalCycleSql('sc') . '
                   AND ' . TenantLifecycleService::productionAtSql('sc.tenant_id', 'sc.first_incoming_at') . $scope
            );
            $sla->execute($slaParams);
            foreach ($sla->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $rowTenant = (int) ($row['tenant_id'] ?? 0);
                if ($rowTenant < 1) {
                    continue;
                }
                $policy = $clock->policyForCycle($rowTenant, $row);
                // O filtro do relatório continua podendo simular outra meta;
                // o relógio (expediente/fuso) permanece o snapshot do ciclo.
                $policy['target_minutes'] = $slaMinutes;
                $elapsed = $clock->elapsedSeconds(
                    $rowTenant,
                    (string) ($row['first_incoming_at'] ?? ''),
                    (string) ($row['first_response_at'] ?? ''),
                    $policy
                );
                $slaMeasured++;
                if ($elapsed <= $slaSeconds) {
                    $slaMet++;
                } else {
                    $slaBreached++;
                }
            }
        } catch (Throwable $exception) {
            error_log('[reports.executive.service-cycle.sla] ' . preg_replace('/\s+/', ' ', $exception->getMessage()));
        }

        $waitingSeconds = [];
        $waitingOver = 0;
        try {
            $waitingParams = [];
            if ($scope !== '') {
                $waitingParams['tenant_id'] = $tenantId;
            }
            $waiting = $this->pdo->prepare(
                'SELECT sc.tenant_id, sc.first_incoming_at,
                        sc.sla_target_minutes, sc.sla_warning_percent,
                        sc.sla_count_outside_business_hours, sc.sla_timezone,
                        sc.sla_business_hours_json
                 FROM conversation_service_cycles sc
                 INNER JOIN conversations c ON c.id = sc.conversation_id AND c.tenant_id = sc.tenant_id
                 WHERE sc.cycle_status = "active"
                   AND c.status <> "closed"
                   AND sc.first_incoming_at IS NOT NULL
                   AND sc.first_response_at IS NULL
                   AND ' . TenantLifecycleService::productionAtSql('sc.tenant_id', 'sc.first_incoming_at') . '
                   AND ' . TenantLifecycleService::currentLiveSql('sc.tenant_id') . $scope
            );
            $waiting->execute($waitingParams);
            $now = gmdate('Y-m-d H:i:s');
            foreach ($waiting->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $rowTenant = (int) ($row['tenant_id'] ?? 0);
                if ($rowTenant < 1) {
                    continue;
                }
                $policy = $clock->policyForCycle($rowTenant, $row);
                $policy['target_minutes'] = $slaMinutes;
                $elapsed = $clock->elapsedSeconds($rowTenant, (string) ($row['first_incoming_at'] ?? ''), $now, $policy);
                $waitingSeconds[] = $elapsed;
                if ($elapsed > $slaSeconds) {
                    $waitingOver++;
                }
            }
        } catch (Throwable $exception) {
            error_log('[reports.executive.service-cycle.waiting] ' . preg_replace('/\s+/', ' ', $exception->getMessage()));
        }

        $waitingCount = count($waitingSeconds);
        return [
            'closed_cycles' => (int) ($closedRow['closed_cycles'] ?? $empty['closed_cycles']),
            'avg_service_duration_seconds' => (int) ($closedRow['avg_service_duration_seconds'] ?? $empty['avg_service_duration_seconds']),
            'min_service_duration_seconds' => (int) ($closedRow['min_service_duration_seconds'] ?? $empty['min_service_duration_seconds']),
            'max_service_duration_seconds' => (int) ($closedRow['max_service_duration_seconds'] ?? $empty['max_service_duration_seconds']),
            'sla_target_minutes' => $slaMinutes,
            'sla_measured' => $slaMeasured,
            'sla_met' => $slaMet,
            'sla_breached' => $slaBreached,
            'sla_compliance' => $slaMeasured > 0 ? round(($slaMet / $slaMeasured) * 100, 1) : 0.0,
            'waiting_now' => $waitingCount,
            'waiting_over_sla' => $waitingOver,
            'avg_current_wait_seconds' => $waitingCount > 0 ? (int) round(array_sum($waitingSeconds) / $waitingCount) : 0,
            'max_current_wait_seconds' => $waitingCount > 0 ? (int) max($waitingSeconds) : 0,
        ];
    }

    /**
     * O percentual da IA usa apenas respostas atribuídas à IA ou à equipe.
     * Mensagens de sistema permanecem visíveis separadamente, sem diluir a
     * participação da IA nos cards equivalentes.
     *
     * @return array{base:int,ai_share:float,human_share:float}
     */
    public function attributedResponseShares(int $aiReplies, int $humanReplies): array
    {
        $base = max(0, $aiReplies) + max(0, $humanReplies);
        return [
            'base' => $base,
            'ai_share' => $base > 0 ? round(($aiReplies / $base) * 100, 1) : 0.0,
            'human_share' => $base > 0 ? round(($humanReplies / $base) * 100, 1) : 0.0,
        ];
    }

    public static function operationalCycleSql(string $alias = ''): string
    {
        $prefix = trim($alias) !== '' ? rtrim(trim($alias), '.') . '.' : '';
        return $prefix . 'source NOT IN ("migration_snapshot", "migration_069_recovery")';
    }
}
