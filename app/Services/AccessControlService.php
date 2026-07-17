<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Env;
use PDO;
use Throwable;

final class AccessControlService
{
    public const VERSION = '33.0-access-enforcement';

    public function statusForTenant(int $tenantId): array
    {
        $base = [
            'allowed' => true,
            'code' => 'allowed',
            'title' => 'Acesso liberado',
            'message' => 'A empresa está dentro das regras de acesso.',
            'tenant_id' => $tenantId,
            'tenant_name' => null,
            'subscription' => null,
            'invoice' => null,
            'grace_days' => $this->invoiceGraceDays(),
            'checked_at' => date('Y-m-d H:i:s'),
            'version' => self::VERSION,
        ];

        if ($tenantId < 1) {
            return $base;
        }

        try {
            $pdo = Database::connection();
            $tenantStatement = $pdo->prepare('SELECT id, name, status FROM tenants WHERE id = :id LIMIT 1');
            $tenantStatement->execute(['id' => $tenantId]);
            $tenant = $tenantStatement->fetch(PDO::FETCH_ASSOC);

            if (!$tenant) {
                return $this->blocked($base, 'tenant_not_found', 'Empresa não encontrada', 'Não foi possível localizar a empresa vinculada a este acesso.');
            }

            $base['tenant_name'] = (string) ($tenant['name'] ?? '');
            $tenantStatus = (string) ($tenant['status'] ?? 'active');
            if ($tenantStatus !== 'active') {
                return $this->blocked(
                    $base,
                    'tenant_' . $tenantStatus,
                    'Acesso da empresa indisponível',
                    'A empresa está ' . $this->tenantStatusLabel($tenantStatus) . '. Fale com a equipe RS Connect para revisar o cadastro.'
                );
            }

            $subscriptionStatement = $pdo->prepare(
                'SELECT ts.*, sp.name AS plan_name, sp.plan_key
                 FROM tenant_subscriptions ts
                 INNER JOIN saas_plans sp ON sp.id = ts.plan_id
                 WHERE ts.tenant_id = :tenant_id
                 ORDER BY ts.id DESC
                 LIMIT 1'
            );
            $subscriptionStatement->execute(['tenant_id' => $tenantId]);
            $subscription = $subscriptionStatement->fetch(PDO::FETCH_ASSOC) ?: null;
            $base['subscription'] = $subscription;

            if (!$subscription) {
                $base['code'] = 'subscription_missing';
                $base['title'] = 'Assinatura ainda não configurada';
                $base['message'] = 'A empresa ainda não possui uma assinatura cadastrada. O acesso permanece liberado para não interromper a implantação.';
                return $base;
            }

            $billingStatus = (string) ($subscription['billing_status'] ?? 'active');
            if (in_array($billingStatus, ['suspended', 'canceled'], true)) {
                return $this->blocked(
                    $base,
                    'subscription_' . $billingStatus,
                    $billingStatus === 'suspended' ? 'Assinatura suspensa' : 'Assinatura encerrada',
                    $billingStatus === 'suspended'
                        ? 'A assinatura está suspensa. Regularize a situação ou fale com a equipe RS Connect.'
                        : 'A assinatura foi encerrada. Fale com a equipe RS Connect para reativar o acesso.'
                );
            }

            $effectiveEnd = $billingStatus === 'trialing' && !empty($subscription['trial_ends_at'])
                ? (string) $subscription['trial_ends_at']
                : (string) ($subscription['current_period_ends_at'] ?? '');

            if ($effectiveEnd !== '' && date('Y-m-d') > $effectiveEnd) {
                $base['effective_end_date'] = $effectiveEnd;
                return $this->blocked(
                    $base,
                    'subscription_period_expired',
                    'Período de uso encerrado',
                    'A vigência do plano terminou em ' . $this->formatDate($effectiveEnd) . '. Atualize a assinatura para continuar usando o RS Connect.'
                );
            }

            $graceDays = $this->invoiceGraceDays();
            $invoiceStatement = $pdo->prepare(
                'SELECT i.*, DATEDIFF(CURDATE(), i.due_date) AS overdue_days
                 FROM tenant_invoices i
                 WHERE i.tenant_id = :tenant_id
                   AND i.status IN ("open", "overdue")
                   AND DATEDIFF(CURDATE(), i.due_date) > :grace_days
                 ORDER BY i.due_date ASC, i.id ASC
                 LIMIT 1'
            );
            $invoiceStatement->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
            $invoiceStatement->bindValue(':grace_days', $graceDays, PDO::PARAM_INT);
            $invoiceStatement->execute();
            $invoice = $invoiceStatement->fetch(PDO::FETCH_ASSOC) ?: null;
            $base['invoice'] = $invoice;

            if ($invoice) {
                return $this->blocked(
                    $base,
                    'invoice_overdue_grace_exceeded',
                    'Pagamento pendente',
                    'A cobrança ' . (string) ($invoice['invoice_number'] ?? '') . ' venceu em ' . $this->formatDate((string) ($invoice['due_date'] ?? '')) . ' e ultrapassou o prazo de tolerância de ' . $graceDays . ' dias.'
                );
            }

            return $base;
        } catch (Throwable $exception) {
            // Falha aberta: um erro de leitura nunca deve bloquear todos os clientes por engano.
            $base['code'] = 'validation_unavailable';
            $base['title'] = 'Validação temporariamente indisponível';
            $base['message'] = 'O acesso foi mantido enquanto a validação financeira é revisada.';
            $base['validation_error'] = $exception->getMessage();
            return $base;
        }
    }

    public function shouldRunAutomations(int $tenantId): bool
    {
        return (bool) ($this->statusForTenant($tenantId)['allowed'] ?? true);
    }

    public function recordBlockedAccess(array $status, string $source = 'web'): void
    {
        if (!empty($status['allowed'])) {
            return;
        }

        $tenantId = (int) ($status['tenant_id'] ?? 0);
        $code = (string) ($status['code'] ?? 'blocked');
        try {
            $recent = Database::connection()->prepare(
                'SELECT COUNT(*) FROM security_events
                 WHERE tenant_id = :tenant_id
                   AND event = :event
                   AND created_at >= (NOW() - INTERVAL 30 MINUTE)'
            );
            $recent->execute(['tenant_id' => $tenantId ?: null, 'event' => 'access.blocked.' . $code]);
            if ((int) $recent->fetchColumn() > 0) {
                return;
            }
        } catch (Throwable) {
            // segue para tentativa de registro
        }

        (new SecurityService())->recordEvent('access.blocked.' . $code, 'warning', [
            'source' => $source,
            'message' => (string) ($status['message'] ?? ''),
            'subscription_id' => $status['subscription']['id'] ?? null,
            'invoice_id' => $status['invoice']['id'] ?? null,
        ], $tenantId ?: null);
    }

    public function securitySummary(): array
    {
        $graceDays = $this->invoiceGraceDays();
        return [
            'invoice_grace_days' => $graceDays,
            'expired_subscriptions' => $this->count(
                'SELECT COUNT(*) FROM tenant_subscriptions ts
                 INNER JOIN (
                    SELECT tenant_id, MAX(id) AS id FROM tenant_subscriptions GROUP BY tenant_id
                 ) latest ON latest.id = ts.id
                 WHERE COALESCE(CASE WHEN ts.billing_status = "trialing" THEN ts.trial_ends_at ELSE ts.current_period_ends_at END, "9999-12-31") < CURDATE()'
            ),
            'overdue_tenants' => $this->count(
                'SELECT COUNT(DISTINCT tenant_id) FROM tenant_invoices
                 WHERE status IN ("open", "overdue")
                   AND DATEDIFF(CURDATE(), due_date) > ' . $graceDays
            ),
            'suspended_subscriptions' => $this->count(
                'SELECT COUNT(*) FROM tenant_subscriptions ts
                 INNER JOIN (SELECT tenant_id, MAX(id) AS id FROM tenant_subscriptions GROUP BY tenant_id) latest ON latest.id = ts.id
                 WHERE ts.billing_status = "suspended"'
            ),
            'locked_users' => $this->count('SELECT COUNT(*) FROM users WHERE locked_until IS NOT NULL AND locked_until > NOW()'),
            'blocked_tenants' => $this->blockedTenants($graceDays),
        ];
    }

    public function invoiceGraceDays(): int
    {
        return max(0, (int) Env::get('BILLING_ACCESS_GRACE_DAYS', 5));
    }

    private function blockedTenants(int $graceDays): array
    {
        try {
            $sql =
                'SELECT t.id, t.name, t.status,
                        ts.billing_status, ts.current_period_ends_at, ts.trial_ends_at,
                        MIN(CASE WHEN i.status IN ("open", "overdue") AND DATEDIFF(CURDATE(), i.due_date) > :grace_days THEN i.due_date END) AS overdue_due_date,
                        MAX(CASE WHEN i.status IN ("open", "overdue") AND DATEDIFF(CURDATE(), i.due_date) > :grace_days THEN DATEDIFF(CURDATE(), i.due_date) END) AS overdue_days
                 FROM tenants t
                 LEFT JOIN tenant_subscriptions ts ON ts.id = (
                    SELECT ts2.id FROM tenant_subscriptions ts2 WHERE ts2.tenant_id = t.id ORDER BY ts2.id DESC LIMIT 1
                 )
                 LEFT JOIN tenant_invoices i ON i.tenant_id = t.id
                 GROUP BY t.id, t.name, t.status, ts.billing_status, ts.current_period_ends_at, ts.trial_ends_at
                 HAVING t.status <> "active"
                    OR ts.billing_status IN ("suspended", "canceled")
                    OR COALESCE(CASE WHEN ts.billing_status = "trialing" THEN ts.trial_ends_at ELSE ts.current_period_ends_at END, "9999-12-31") < CURDATE()
                    OR overdue_due_date IS NOT NULL
                 ORDER BY t.name
                 LIMIT 50';
            $statement = Database::connection()->prepare($sql);
            $statement->bindValue(':grace_days', $graceDays, PDO::PARAM_INT);
            $statement->execute();
            return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    private function blocked(array $base, string $code, string $title, string $message): array
    {
        $base['allowed'] = false;
        $base['code'] = $code;
        $base['title'] = $title;
        $base['message'] = $message;
        return $base;
    }

    private function count(string $sql): int
    {
        try {
            return (int) Database::connection()->query($sql)->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }

    private function formatDate(string $value): string
    {
        $timestamp = strtotime($value);
        return $timestamp ? date('d/m/Y', $timestamp) : $value;
    }

    private function tenantStatusLabel(string $status): string
    {
        return match ($status) {
            'inactive' => 'inativa',
            'suspended' => 'suspensa',
            default => 'indisponível',
        };
    }
}
