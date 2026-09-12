<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Clock;
use App\Core\Database;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Separa o ciclo operacional do tenant (implantação/go-live) do status de
 * acesso e da assinatura comercial. Somente períodos marcados como LIVE são
 * elegíveis para métricas oficiais de produção e criação manual de cobranças.
 */
final class TenantLifecycleService
{
    public const ONBOARDING = 'onboarding';
    public const READY = 'ready';
    public const LIVE = 'live';
    public const SUSPENDED = 'suspended';

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connection();
    }

    /** @return array<string,string> */
    public static function statuses(): array
    {
        return [
            self::ONBOARDING => 'Onboarding',
            self::READY => 'Pronta para produção',
            self::LIVE => 'Em produção',
            self::SUSPENDED => 'Operação suspensa',
        ];
    }

    public static function label(string $status): string
    {
        return self::statuses()[$status] ?? 'Onboarding';
    }

    public static function description(string $status): string
    {
        return match ($status) {
            self::READY => 'Configuração concluída e pronta para homologação final. Métricas oficiais ainda não contam.',
            self::LIVE => 'Operação oficial. SLA e métricas produtivas passam a ser contabilizados.',
            self::SUSPENDED => 'Operação produtiva pausada. O histórico anterior permanece preservado.',
            default => 'Ambiente de configuração e testes. WhatsApp, IA e atendimento continuam disponíveis sem contaminar métricas oficiais.',
        };
    }

    /** @return array<string,mixed>|null */
    public function tenant(int $tenantId): ?array
    {
        if ($tenantId < 1) {
            return null;
        }
        try {
            $statement = $this->pdo->prepare(
                'SELECT id, name, lifecycle_status, lifecycle_changed_at, lifecycle_changed_by,
                        ready_at, went_live_at, suspended_at
                 FROM tenants WHERE id = :id LIMIT 1'
            );
            $statement->execute(['id' => $tenantId]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    public function status(int $tenantId): string
    {
        $tenant = $this->tenant($tenantId);
        $status = (string) ($tenant['lifecycle_status'] ?? self::ONBOARDING);
        return array_key_exists($status, self::statuses()) ? $status : self::ONBOARDING;
    }

    public function isLive(int $tenantId): bool
    {
        return $this->status($tenantId) === self::LIVE;
    }

    public function allowsProductionBilling(int $tenantId): bool
    {
        return $this->isLive($tenantId);
    }

    /** @return array<int,string> */
    public static function allowedTargets(string $current): array
    {
        return match ($current) {
            self::ONBOARDING => [self::READY],
            self::READY => [self::ONBOARDING, self::LIVE],
            self::LIVE => [self::READY, self::SUSPENDED],
            self::SUSPENDED => [self::LIVE, self::READY, self::ONBOARDING],
            default => [self::ONBOARDING],
        };
    }

    /** @return array<string,mixed> */
    public function transition(int $tenantId, string $target, string $note = ''): array
    {
        $target = strtolower(trim($target));
        $note = trim($note);
        if ($tenantId < 1 || !array_key_exists($target, self::statuses())) {
            throw new RuntimeException('Empresa ou estágio operacional inválido.');
        }

        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                'SELECT id, name, lifecycle_status, ready_at, went_live_at, suspended_at
                 FROM tenants WHERE id = :id LIMIT 1 FOR UPDATE'
            );
            $statement->execute(['id' => $tenantId]);
            $tenant = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$tenant) {
                throw new RuntimeException('Empresa não encontrada.');
            }

            $current = (string) ($tenant['lifecycle_status'] ?? self::ONBOARDING);
            if ($current === $target) {
                throw new RuntimeException('A empresa já está em ' . self::label($target) . '.');
            }
            if (!in_array($target, self::allowedTargets($current), true)) {
                throw new RuntimeException('Transição de ' . self::label($current) . ' para ' . self::label($target) . ' não permitida.');
            }

            $now = Clock::nowUtc();
            $readyAt = $tenant['ready_at'] ?? null;
            $wentLiveAt = $tenant['went_live_at'] ?? null;
            $suspendedAt = $tenant['suspended_at'] ?? null;
            if ($target === self::READY && empty($readyAt)) {
                $readyAt = $now;
            }
            if ($target === self::LIVE && empty($wentLiveAt)) {
                $wentLiveAt = $now;
            }
            if ($target === self::SUSPENDED) {
                $suspendedAt = $now;
            }

            $update = $this->pdo->prepare(
                'UPDATE tenants
                 SET lifecycle_status = :status,
                     lifecycle_changed_at = :changed_at,
                     lifecycle_changed_by = :changed_by,
                     ready_at = :ready_at,
                     went_live_at = :went_live_at,
                     suspended_at = :suspended_at
                 WHERE id = :id'
            );
            $update->execute([
                'status' => $target,
                'changed_at' => $now,
                'changed_by' => Auth::id() ?: null,
                'ready_at' => $readyAt ?: null,
                'went_live_at' => $wentLiveAt ?: null,
                'suspended_at' => $suspendedAt ?: null,
                'id' => $tenantId,
            ]);

            $event = $this->pdo->prepare(
                'INSERT INTO tenant_lifecycle_events
                    (tenant_id, from_status, to_status, note, changed_by_user_id, changed_at)
                 VALUES
                    (:tenant_id, :from_status, :to_status, :note, :changed_by_user_id, :changed_at)'
            );
            $event->execute([
                'tenant_id' => $tenantId,
                'from_status' => $current,
                'to_status' => $target,
                'note' => $note !== '' ? $note : null,
                'changed_by_user_id' => Auth::id() ?: null,
                'changed_at' => $now,
            ]);

            $this->pdo->commit();

            $action = match ($target) {
                self::READY => 'company.lifecycle_ready',
                self::LIVE => 'company.lifecycle_live',
                self::SUSPENDED => 'company.lifecycle_suspended',
                default => 'company.lifecycle_onboarding',
            };
            Audit::log($action, [
                'company_name' => (string) ($tenant['name'] ?? ''),
                'from_status' => $current,
                'to_status' => $target,
                'note' => $note,
                'production_metrics' => $target === self::LIVE ? 'enabled' : 'disabled',
            ], $tenantId);

            return [
                'tenant_id' => $tenantId,
                'from_status' => $current,
                'to_status' => $target,
                'changed_at' => $now,
                'went_live_at' => $wentLiveAt,
            ];
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @return array<int,array<string,mixed>> */
    public function history(int $tenantId, int $limit = 20): array
    {
        if ($tenantId < 1) {
            return [];
        }
        $limit = max(1, min(100, $limit));
        try {
            $statement = $this->pdo->prepare(
                'SELECT e.*, u.name AS changed_by_name
                 FROM tenant_lifecycle_events e
                 LEFT JOIN users u ON u.id = e.changed_by_user_id
                 WHERE e.tenant_id = :tenant_id
                 ORDER BY e.changed_at DESC, e.id DESC
                 LIMIT ' . $limit
            );
            $statement->execute(['tenant_id' => $tenantId]);
            return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * SQL para validar se o último evento de ciclo existente no instante do
     * atendimento era LIVE. Os argumentos são expressões SQL internas, nunca
     * dados fornecidos pelo usuário.
     */
    public static function productionAtSql(string $tenantExpression, string $timestampExpression): string
    {
        return 'EXISTS (
            SELECT 1
            FROM tenant_lifecycle_events tle
            WHERE tle.tenant_id = ' . $tenantExpression . '
              AND tle.to_status = "live"
              AND tle.changed_at <= ' . $timestampExpression . '
              AND NOT EXISTS (
                  SELECT 1
                  FROM tenant_lifecycle_events newer
                  WHERE newer.tenant_id = tle.tenant_id
                    AND newer.changed_at <= ' . $timestampExpression . '
                    AND (newer.changed_at > tle.changed_at OR (newer.changed_at = tle.changed_at AND newer.id > tle.id))
              )
        )';
    }

    public static function currentLiveSql(string $tenantExpression): string
    {
        return 'EXISTS (SELECT 1 FROM tenants lifecycle_tenant WHERE lifecycle_tenant.id = ' . $tenantExpression . ' AND lifecycle_tenant.lifecycle_status = "live")';
    }
}
