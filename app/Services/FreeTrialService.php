<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DateTimeImmutable;
use PDO;
use Throwable;

final class FreeTrialService
{
    /** @return array<string,mixed>|null */
    public function currentForTenant(int $tenantId): ?array
    {
        if ($tenantId < 1) {
            return null;
        }
        try {
            $statement = Database::connection()->prepare(
                'SELECT ts.*, sp.name AS plan_name, sp.plan_key
                 FROM tenant_subscriptions ts
                 INNER JOIN saas_plans sp ON sp.id = ts.plan_id
                 WHERE ts.tenant_id = :tenant_id
                 ORDER BY ts.id DESC
                 LIMIT 1'
            );
            $statement->execute(['tenant_id' => $tenantId]);
            $subscription = $statement->fetch(PDO::FETCH_ASSOC) ?: null;
            return $subscription ? $this->summary($subscription) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string,mixed> $subscription @return array<string,mixed> */
    public function summary(array $subscription): array
    {
        $status = (string) ($subscription['billing_status'] ?? 'active');
        $trialEnd = trim((string) ($subscription['trial_ends_at'] ?? ''));
        $behavior = (string) ($subscription['trial_end_behavior'] ?? 'await_payment');
        $graceDays = max(0, (int) ($subscription['trial_grace_days'] ?? 3));
        $today = new DateTimeImmutable('today');
        $end = $trialEnd !== '' ? new DateTimeImmutable($trialEnd) : null;
        $graceEnd = $end?->modify('+' . $graceDays . ' days');
        $daysRemaining = $end ? max(0, (int) $today->diff($end)->format('%r%a') + 1) : null;
        $inTrial = $status === 'trialing' && $end !== null && $today <= $end;
        $inGrace = $status === 'trialing' && $end !== null && $today > $end && $graceEnd !== null && $today <= $graceEnd && $behavior === 'await_payment';

        return $subscription + [
            'is_trial' => $status === 'trialing',
            'trial_active' => $inTrial,
            'trial_in_grace' => $inGrace,
            'trial_days_remaining' => $daysRemaining,
            'trial_grace_ends_at' => $graceEnd?->format('Y-m-d'),
            'trial_end_behavior' => $behavior,
            'trial_grace_days' => $graceDays,
        ];
    }

    /** @param array<string,mixed> $subscription @return array<string,mixed> */
    public function reconcile(array $subscription): array
    {
        $summary = $this->summary($subscription);
        if (($summary['billing_status'] ?? '') !== 'trialing' || empty($summary['trial_ends_at'])) {
            return $summary;
        }

        $today = new DateTimeImmutable('today');
        $end = new DateTimeImmutable((string) $summary['trial_ends_at']);
        if ($today <= $end) {
            return $summary;
        }

        $behavior = (string) ($summary['trial_end_behavior'] ?? 'await_payment');
        if ($behavior === 'activate') {
            $this->activateAfterTrial($summary, $end);
            $summary['billing_status'] = 'active';
            $summary['trial_converted_at'] = date('Y-m-d H:i:s');
            $summary['trial_active'] = false;
            $summary['trial_in_grace'] = false;
            return $summary;
        }
        if ($behavior === 'suspend') {
            $this->suspendAfterTrial($summary);
            $summary['billing_status'] = 'suspended';
            $summary['trial_active'] = false;
            $summary['trial_in_grace'] = false;
            return $summary;
        }

        return $summary;
    }

    /** @param array<string,mixed> $subscription */
    private function activateAfterTrial(array $subscription, DateTimeImmutable $trialEnd): void
    {
        $cycle = (string) ($subscription['billing_cycle'] ?? 'monthly');
        $start = $trialEnd->modify('+1 day');
        $end = match ($cycle) {
            'quarterly' => $start->modify('+3 months -1 day'),
            'semiannual' => $start->modify('+6 months -1 day'),
            'annual' => $start->modify('+1 year -1 day'),
            default => $start->modify('+1 month -1 day'),
        };
        Database::connection()->prepare(
            'UPDATE tenant_subscriptions
             SET billing_status = "active", trial_converted_at = NOW(),
                 current_period_starts_at = :start_at,
                 current_period_ends_at = :end_at,
                 next_billing_at = :next_billing_at
             WHERE id = :id AND billing_status = "trialing"'
        )->execute([
            'start_at' => $start->format('Y-m-d'),
            'end_at' => $end->format('Y-m-d'),
            'next_billing_at' => $start->format('Y-m-d'),
            'id' => (int) $subscription['id'],
        ]);
    }

    /** @param array<string,mixed> $subscription */
    private function suspendAfterTrial(array $subscription): void
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE tenant_subscriptions SET billing_status = "suspended", trial_expired_at = NOW() WHERE id = :id AND billing_status = "trialing"')
                ->execute(['id' => (int) $subscription['id']]);
            $pdo->prepare('UPDATE tenants SET status = "suspended" WHERE id = :tenant_id')
                ->execute(['tenant_id' => (int) $subscription['tenant_id']]);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }
}
