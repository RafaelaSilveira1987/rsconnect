<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use Throwable;

/**
 * Fonte canônica para contagens de empresas usadas por dashboard, monitor e alertas.
 *
 * O campo `non_active` representa tudo que não está com status `active` e, portanto,
 * reconcilia diretamente com a leitura visual "Total - Ativas" do dashboard.
 */
final class TenantMetricsService
{
    /**
     * @return array{
     *   available:bool,
     *   total:int,
     *   active:int,
     *   non_active:int,
     *   inactive:int,
     *   suspended:int,
     *   other:int
     * }
     */
    public function counts(): array
    {
        $empty = [
            'available' => false,
            'total' => 0,
            'active' => 0,
            'non_active' => 0,
            'inactive' => 0,
            'suspended' => 0,
            'other' => 0,
        ];

        try {
            $statement = Database::connection()->query(
                "SELECT COUNT(*) AS total,
                        COALESCE(SUM(status = 'active'), 0) AS active_count,
                        COALESCE(SUM(status <> 'active'), 0) AS non_active_count,
                        COALESCE(SUM(status = 'inactive'), 0) AS inactive_count,
                        COALESCE(SUM(status = 'suspended'), 0) AS suspended_count,
                        COALESCE(SUM(status NOT IN ('active', 'inactive', 'suspended')), 0) AS other_count
                 FROM tenants"
            );
            $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];

            return [
                'available' => true,
                'total' => (int) ($row['total'] ?? 0),
                'active' => (int) ($row['active_count'] ?? 0),
                'non_active' => (int) ($row['non_active_count'] ?? 0),
                'inactive' => (int) ($row['inactive_count'] ?? 0),
                'suspended' => (int) ($row['suspended_count'] ?? 0),
                'other' => (int) ($row['other_count'] ?? 0),
            ];
        } catch (Throwable) {
            return $empty;
        }
    }
}
