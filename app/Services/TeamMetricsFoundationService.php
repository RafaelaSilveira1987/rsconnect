<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Define o escopo seguro dos futuros relatórios por profissional e informa
 * se a base histórica e o contrato UTC até a migration 071 estão prontos.
 */
final class TeamMetricsFoundationService
{
    public const VERSION = '36.10.4-utc-datetime-contract';

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connection();
    }

    /**
     * @return array{allowed:bool,mode:string,tenant_id:int,user_id:?int,reason:string}
     */
    public function scopeForCurrentUser(int $tenantId): array
    {
        $userId = (int) (Auth::id() ?? 0);
        if ($tenantId < 1 || $userId < 1) {
            return $this->denied($tenantId, $userId, 'Usuário ou empresa inválido.');
        }

        if (Auth::isSuperAdmin()) {
            return [
                'allowed' => true,
                'mode' => 'all',
                'tenant_id' => $tenantId,
                'user_id' => null,
                'reason' => 'Super Admin pode consultar a empresa selecionada.',
            ];
        }

        if ((int) (Auth::tenantId() ?? 0) !== $tenantId) {
            return $this->denied($tenantId, $userId, 'O usuário não pertence à empresa consultada.');
        }

        if (Auth::can('reports.team.view_all')) {
            return [
                'allowed' => true,
                'mode' => 'all',
                'tenant_id' => $tenantId,
                'user_id' => null,
                'reason' => 'Usuário autorizado a visualizar toda a equipe.',
            ];
        }

        if (Auth::can('reports.team.view_own')) {
            return [
                'allowed' => true,
                'mode' => 'own',
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'reason' => 'Usuário autorizado a visualizar somente os próprios indicadores.',
            ];
        }

        return $this->denied($tenantId, $userId, 'O perfil não possui permissão para indicadores da equipe.');
    }

    public function assertMayView(int $tenantId): array
    {
        $scope = $this->scopeForCurrentUser($tenantId);
        if (empty($scope['allowed'])) {
            throw new RuntimeException((string) $scope['reason']);
        }
        return $scope;
    }

    /**
     * @return array{ready:bool,missing:string[],version:string}
     */
    public function readiness(): array
    {
        $requiredTables = [
            'conversation_assignment_history',
            'conversation_status_history',
            'calendar_appointment_history',
            'conversation_service_cycles',
            'rs_datetime_contract',
        ];
        $requiredColumns = [
            ['conversations', 'first_incoming_at'],
            ['conversations', 'first_response_at'],
            ['conversations', 'first_response_user_id'],
            ['conversations', 'opened_at'],
            ['conversations', 'closed_at'],
            ['calendar_appointments', 'confirmed_at'],
            ['calendar_appointments', 'completed_at'],
            ['calendar_appointments', 'cancelled_at'],
            ['calendar_appointments', 'no_show_at'],
        ];

        $missing = [];
        foreach ($requiredTables as $table) {
            if (!$this->tableExists($table)) {
                $missing[] = 'table:' . $table;
            }
        }
        foreach ($requiredColumns as [$table, $column]) {
            if (!$this->columnExists($table, $column)) {
                $missing[] = 'column:' . $table . '.' . $column;
            }
        }

        return [
            'ready' => $missing === [],
            'missing' => $missing,
            'version' => self::VERSION,
        ];
    }

    private function denied(int $tenantId, int $userId, string $reason): array
    {
        return [
            'allowed' => false,
            'mode' => 'denied',
            'tenant_id' => $tenantId,
            'user_id' => $userId > 0 ? $userId : null,
            'reason' => $reason,
        ];
    }

    private function tableExists(string $table): bool
    {
        try {
            $statement = $this->pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name'
            );
            $statement->execute(['table_name' => $table]);
            return (int) $statement->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        try {
            $statement = $this->pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = :table_name
                   AND COLUMN_NAME = :column_name'
            );
            $statement->execute(['table_name' => $table, 'column_name' => $column]);
            return (int) $statement->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }
}
