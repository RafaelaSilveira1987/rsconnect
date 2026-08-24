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
