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
        $scope = $tenantId !== null && $tenantId > 0 ? ' AND tenant_id = :tenant_id' : '';
        $params = [
            'start' => $start,
            'end' => $end,
        ];
        if ($scope !== '') {
            $params['tenant_id'] = $tenantId;
        }

        try {
            $statement = $this->pdo->prepare(
                'SELECT COUNT(*) AS measured,
                        COALESCE(ROUND(AVG(GREATEST(0, TIMESTAMPDIFF(SECOND, first_incoming_at, first_response_at)))), 0) AS average_seconds,
                        COALESCE(MIN(GREATEST(0, TIMESTAMPDIFF(SECOND, first_incoming_at, first_response_at))), 0) AS min_seconds,
                        COALESCE(MAX(GREATEST(0, TIMESTAMPDIFF(SECOND, first_incoming_at, first_response_at))), 0) AS max_seconds
                 FROM conversation_service_cycles
                 WHERE first_incoming_at BETWEEN :start AND :end
                   AND first_response_at IS NOT NULL
                   AND source NOT IN ("migration_snapshot", "migration_069_recovery")' . $scope
            );
            $statement->execute($params);
            $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];

            return [
                'count' => (int) ($row['measured'] ?? 0),
                'average_seconds' => (int) ($row['average_seconds'] ?? 0),
                'min_seconds' => (int) ($row['min_seconds'] ?? 0),
                'max_seconds' => (int) ($row['max_seconds'] ?? 0),
            ];
        } catch (Throwable $exception) {
            error_log('[reports.executive.consistency] ' . preg_replace('/\s+/', ' ', $exception->getMessage()));
            return [
                'count' => 0,
                'average_seconds' => 0,
                'min_seconds' => 0,
                'max_seconds' => 0,
            ];
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
        $params = ['start' => $start, 'end' => $end, 'sla_seconds' => $slaSeconds];
        if ($scope !== '') {
            $params['tenant_id'] = $tenantId;
        }

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

        try {
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
                   AND ' . self::operationalCycleSql('sc') . $scope
            );
            $closedParams = $params;
            unset($closedParams['sla_seconds']);
            $closed->execute($closedParams);
            $closedRow = $closed->fetch(PDO::FETCH_ASSOC) ?: [];

            $sla = $this->pdo->prepare(
                'SELECT COUNT(*) AS sla_measured,
                        COALESCE(SUM(GREATEST(0, TIMESTAMPDIFF(SECOND, sc.first_incoming_at, sc.first_response_at)) <= :sla_seconds),0) AS sla_met,
                        COALESCE(SUM(GREATEST(0, TIMESTAMPDIFF(SECOND, sc.first_incoming_at, sc.first_response_at)) > :sla_seconds),0) AS sla_breached
                 FROM conversation_service_cycles sc
                 WHERE sc.first_incoming_at IS NOT NULL
                   AND sc.first_response_at IS NOT NULL
                   AND sc.first_response_user_id IS NOT NULL
                   AND sc.first_incoming_at BETWEEN :start AND :end
                   AND ' . self::operationalCycleSql('sc') . $scope
            );
            $sla->execute($params);
            $slaRow = $sla->fetch(PDO::FETCH_ASSOC) ?: [];

            $waitingScope = $tenantId !== null && $tenantId > 0 ? ' AND sc.tenant_id = :tenant_id' : '';
            $waitingParams = ['sla_seconds' => $slaSeconds];
            if ($waitingScope !== '') {
                $waitingParams['tenant_id'] = $tenantId;
            }
            $waiting = $this->pdo->prepare(
                'SELECT COUNT(*) AS waiting_now,
                        COALESCE(SUM(GREATEST(0, TIMESTAMPDIFF(SECOND, sc.first_incoming_at, UTC_TIMESTAMP())) > :sla_seconds),0) AS waiting_over_sla,
                        COALESCE(ROUND(AVG(GREATEST(0, TIMESTAMPDIFF(SECOND, sc.first_incoming_at, UTC_TIMESTAMP())))),0) AS avg_current_wait_seconds,
                        COALESCE(MAX(GREATEST(0, TIMESTAMPDIFF(SECOND, sc.first_incoming_at, UTC_TIMESTAMP()))),0) AS max_current_wait_seconds
                 FROM conversation_service_cycles sc
                 INNER JOIN conversations c ON c.id = sc.conversation_id AND c.tenant_id = sc.tenant_id
                 WHERE sc.cycle_status = "active"
                   AND c.status <> "closed"
                   AND sc.first_incoming_at IS NOT NULL
                   AND sc.first_response_at IS NULL' . $waitingScope
            );
            $waiting->execute($waitingParams);
            $waitingRow = $waiting->fetch(PDO::FETCH_ASSOC) ?: [];

            $measured = (int) ($slaRow['sla_measured'] ?? 0);
            $met = (int) ($slaRow['sla_met'] ?? 0);
            return [
                'closed_cycles' => (int) ($closedRow['closed_cycles'] ?? 0),
                'avg_service_duration_seconds' => (int) ($closedRow['avg_service_duration_seconds'] ?? 0),
                'min_service_duration_seconds' => (int) ($closedRow['min_service_duration_seconds'] ?? 0),
                'max_service_duration_seconds' => (int) ($closedRow['max_service_duration_seconds'] ?? 0),
                'sla_target_minutes' => $slaMinutes,
                'sla_measured' => $measured,
                'sla_met' => $met,
                'sla_breached' => (int) ($slaRow['sla_breached'] ?? 0),
                'sla_compliance' => $measured > 0 ? round(($met / $measured) * 100, 1) : 0.0,
                'waiting_now' => (int) ($waitingRow['waiting_now'] ?? 0),
                'waiting_over_sla' => (int) ($waitingRow['waiting_over_sla'] ?? 0),
                'avg_current_wait_seconds' => (int) ($waitingRow['avg_current_wait_seconds'] ?? 0),
                'max_current_wait_seconds' => (int) ($waitingRow['max_current_wait_seconds'] ?? 0),
            ];
        } catch (Throwable $exception) {
            error_log('[reports.executive.service-cycle] ' . preg_replace('/\s+/', ' ', $exception->getMessage()));
            return $empty;
        }
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
