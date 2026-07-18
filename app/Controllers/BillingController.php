<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Flash;
use App\Core\Router;
use App\Core\View;
use App\Services\AccessControlService;
use App\Services\PaymentGatewayService;
use App\Services\SubscriptionService;
use PDO;
use Throwable;

final class BillingController
{
    public function index(): void
    {
        $pdo = Database::connection();
        $plans = $pdo->query('SELECT * FROM saas_plans ORDER BY sort_order, id')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($plans as &$plan) {
            $plan['limits'] = json_decode((string) ($plan['limits_json'] ?? '{}'), true) ?: [];
            $plan['features'] = json_decode((string) ($plan['features_json'] ?? '[]'), true) ?: [];
        }
        unset($plan);

        $tenants = $pdo->query(
            'SELECT t.id, t.name, t.plan, t.status,
                    ts.id AS subscription_id, ts.plan_id, ts.billing_cycle, ts.billing_status,
                    ts.starts_at, ts.trial_ends_at, ts.current_period_starts_at,
                    ts.current_period_ends_at, ts.next_billing_at, ts.amount, ts.notes,
                    sp.name AS plan_name, sp.plan_key
             FROM tenants t
             LEFT JOIN tenant_subscriptions ts ON ts.id = (
                SELECT ts2.id FROM tenant_subscriptions ts2
                WHERE ts2.tenant_id = t.id
                ORDER BY ts2.id DESC
                LIMIT 1
             )
             LEFT JOIN saas_plans sp ON sp.id = ts.plan_id
             ORDER BY t.name'
        )->fetchAll(PDO::FETCH_ASSOC);

        $invoices = $pdo->query(
            'SELECT i.*, t.name AS tenant_name, sp.name AS plan_name, pg.label AS gateway_label
             FROM tenant_invoices i
             INNER JOIN tenants t ON t.id = i.tenant_id
             LEFT JOIN tenant_subscriptions ts ON ts.id = i.subscription_id
             LEFT JOIN saas_plans sp ON sp.id = ts.plan_id
             LEFT JOIN payment_gateways pg ON pg.id = i.payment_gateway_id
             ORDER BY i.created_at DESC
             LIMIT 120'
        )->fetchAll(PDO::FETCH_ASSOC);

        $paymentGateways = array_values(array_filter(
            (new PaymentGatewayService())->gateways(),
            static fn (array $gateway): bool => ($gateway['status'] ?? '') === 'active'
        ));

        $accessService = new AccessControlService();
        foreach ($tenants as &$tenant) {
            $tenant['access_status'] = $accessService->statusForTenant((int) $tenant['id']);
        }
        unset($tenant);

        View::render('billing.index', [
            'title' => 'Planos e cobrança',
            'plans' => $plans,
            'tenants' => $tenants,
            'invoices' => $invoices,
            'limitLabels' => SubscriptionService::LIMIT_LABELS,
            'selectedTenantId' => (int) ($_GET['tenant_id'] ?? 0),
            'autoEditSubscription' => isset($_GET['edit_subscription']),
            'paymentGateways' => $paymentGateways,
            'paymentMethodLabels' => PaymentGatewayService::METHOD_LABELS,
        ]);
    }

    public function subscription(): void
    {
        $tenantId = Auth::isSuperAdmin() ? (int) ($_GET['tenant_id'] ?? 0) : (int) Auth::tenantId();
        if ($tenantId < 1) {
            Flash::set('warning', 'Selecione uma empresa para consultar a assinatura.');
            $this->redirect('/billing');
        }

        $pdo = Database::connection();
        $tenantStatement = $pdo->prepare('SELECT * FROM tenants WHERE id = :id LIMIT 1');
        $tenantStatement->execute(['id' => $tenantId]);
        $tenant = $tenantStatement->fetch(PDO::FETCH_ASSOC);
        if (!$tenant) {
            Flash::set('error', 'Empresa não encontrada.');
            $this->redirect(Auth::isSuperAdmin() ? '/billing' : '/');
        }

        $service = new SubscriptionService();
        $plan = $service->currentPlanForTenant($tenantId);
        $limitRows = $service->limitRows($tenantId);

        $invoicesStatement = $pdo->prepare('SELECT * FROM tenant_invoices WHERE tenant_id = :tenant_id ORDER BY created_at DESC LIMIT 24');
        $invoicesStatement->execute(['tenant_id' => $tenantId]);

        View::render('billing.subscription', [
            'title' => 'Minha assinatura',
            'tenant' => $tenant,
            'plan' => $plan,
            'limitRows' => $limitRows,
            'invoices' => $invoicesStatement->fetchAll(PDO::FETCH_ASSOC),
        ]);
    }

    public function savePlan(): void
    {
        $id = (int) ($_POST['id'] ?? 0);
        $planKey = $this->slug(trim((string) ($_POST['plan_key'] ?? '')));
        $name = trim((string) ($_POST['name'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $monthlyPrice = $this->money((string) ($_POST['monthly_price'] ?? '0'));
        $status = in_array($_POST['status'] ?? 'active', ['active', 'inactive'], true) ? (string) $_POST['status'] : 'active';
        $sortOrder = max(1, (int) ($_POST['sort_order'] ?? 10));
        $limits = [];
        foreach (SubscriptionService::LIMIT_LABELS as $key => $label) {
            $value = trim((string) ($_POST['limits'][$key] ?? ''));
            $limits[$key] = $value === '' ? null : max(0, (int) $value);
        }
        $features = array_values(array_filter(array_map('trim', explode("\n", (string) ($_POST['features'] ?? '')))));

        if ($planKey === '' || $name === '') {
            Flash::set('error', 'Informe identificador e nome do plano.');
            $this->redirect('/billing');
        }

        $pdo = Database::connection();
        try {
            if ($id > 0) {
                $statement = $pdo->prepare(
                    'UPDATE saas_plans
                     SET plan_key = :plan_key, name = :name, description = :description, monthly_price = :monthly_price,
                         limits_json = :limits_json, features_json = :features_json, status = :status, sort_order = :sort_order
                     WHERE id = :id'
                );
                $params = ['id' => $id];
                $action = 'billing.plan_updated';
            } else {
                $statement = $pdo->prepare(
                    'INSERT INTO saas_plans
                        (plan_key, name, description, monthly_price, limits_json, features_json, status, sort_order)
                     VALUES
                        (:plan_key, :name, :description, :monthly_price, :limits_json, :features_json, :status, :sort_order)'
                );
                $params = [];
                $action = 'billing.plan_created';
            }
            $statement->execute($params + [
                'plan_key' => $planKey,
                'name' => $name,
                'description' => $description !== '' ? $description : null,
                'monthly_price' => $monthlyPrice,
                'limits_json' => json_encode($limits, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'features_json' => json_encode($features, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'status' => $status,
                'sort_order' => $sortOrder,
            ]);
            Audit::log($action, ['plan_key' => $planKey]);
            Flash::set('success', 'Plano salvo.');
        } catch (Throwable $exception) {
            Flash::set('error', 'Não foi possível salvar o plano: ' . $exception->getMessage());
        }
        $this->redirect('/billing');
    }

    public function saveSubscription(): void
    {
        $subscriptionId = (int) ($_POST['subscription_id'] ?? 0);
        $tenantId = (int) ($_POST['tenant_id'] ?? 0);
        $planId = (int) ($_POST['plan_id'] ?? 0);
        $billingStatus = (string) ($_POST['billing_status'] ?? 'active');
        $billingCycle = (string) ($_POST['billing_cycle'] ?? 'monthly');
        $amount = $this->money((string) ($_POST['amount'] ?? '0'));
        $currentPeriodStartsAt = $this->dateOrNull((string) ($_POST['current_period_starts_at'] ?? '')) ?: date('Y-m-d');
        $currentPeriodEndsAt = $this->dateOrNull((string) ($_POST['current_period_ends_at'] ?? '')) ?: date('Y-m-t');
        $nextBillingAt = $this->dateOrNull((string) ($_POST['next_billing_at'] ?? ''));
        $trialEndsAt = $this->dateOrNull((string) ($_POST['trial_ends_at'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));

        if ($tenantId < 1 || $planId < 1 || !in_array($billingStatus, ['trialing', 'active', 'overdue', 'suspended', 'canceled'], true)) {
            Flash::set('error', 'Informe empresa, plano e status de cobrança.');
            $this->redirect('/billing');
        }
        if (!in_array($billingCycle, ['monthly', 'quarterly', 'semiannual', 'annual'], true)) {
            $billingCycle = 'monthly';
        }
        if ($currentPeriodEndsAt < $currentPeriodStartsAt) {
            Flash::set('error', 'O fim da vigência não pode ser anterior ao início do período.');
            $this->redirect('/billing?tenant_id=' . $tenantId . '&edit_subscription=1');
        }
        if ($billingStatus === 'trialing' && $trialEndsAt !== null && $trialEndsAt < $currentPeriodStartsAt) {
            Flash::set('error', 'O fim do teste não pode ser anterior ao início do período.');
            $this->redirect('/billing?tenant_id=' . $tenantId . '&edit_subscription=1');
        }

        $pdo = Database::connection();
        try {
            $pdo->beginTransaction();
            $action = 'billing.subscription_created';

            if ($subscriptionId > 0) {
                $existing = $pdo->prepare('SELECT id, tenant_id FROM tenant_subscriptions WHERE id = :id LIMIT 1 FOR UPDATE');
                $existing->execute(['id' => $subscriptionId]);
                $current = $existing->fetch(PDO::FETCH_ASSOC);
                if (!$current || (int) $current['tenant_id'] !== $tenantId) {
                    throw new \RuntimeException('A assinatura selecionada não pertence à empresa informada.');
                }

                $statement = $pdo->prepare(
                    'UPDATE tenant_subscriptions
                     SET plan_id = :plan_id,
                         billing_cycle = :billing_cycle,
                         billing_status = :billing_status,
                         starts_at = :starts_at,
                         trial_ends_at = :trial_ends_at,
                         current_period_starts_at = :current_period_starts_at,
                         current_period_ends_at = :current_period_ends_at,
                         next_billing_at = :next_billing_at,
                         amount = :amount,
                         notes = :notes,
                         cancel_at = CASE WHEN :reactivated = 1 THEN NULL ELSE cancel_at END
                     WHERE id = :id'
                );
                $statement->execute([
                    'plan_id' => $planId,
                    'billing_cycle' => $billingCycle,
                    'billing_status' => $billingStatus,
                    'starts_at' => $currentPeriodStartsAt,
                    'trial_ends_at' => $trialEndsAt,
                    'current_period_starts_at' => $currentPeriodStartsAt,
                    'current_period_ends_at' => $currentPeriodEndsAt,
                    'next_billing_at' => $nextBillingAt,
                    'amount' => $amount,
                    'notes' => $notes !== '' ? $notes : null,
                    'reactivated' => in_array($billingStatus, ['trialing', 'active', 'overdue'], true) ? 1 : 0,
                    'id' => $subscriptionId,
                ]);
                $action = 'billing.subscription_updated';
            } else {
                $old = $pdo->prepare('UPDATE tenant_subscriptions SET billing_status = "canceled", cancel_at = NOW() WHERE tenant_id = :tenant_id AND billing_status IN ("trialing", "active", "overdue", "suspended")');
                $old->execute(['tenant_id' => $tenantId]);

                $statement = $pdo->prepare(
                    'INSERT INTO tenant_subscriptions
                        (tenant_id, plan_id, billing_cycle, billing_status, starts_at, trial_ends_at,
                         current_period_starts_at, current_period_ends_at, next_billing_at, amount, notes)
                     VALUES
                        (:tenant_id, :plan_id, :billing_cycle, :billing_status, :starts_at, :trial_ends_at,
                         :current_period_starts_at, :current_period_ends_at, :next_billing_at, :amount, :notes)'
                );
                $statement->execute([
                    'tenant_id' => $tenantId,
                    'plan_id' => $planId,
                    'billing_cycle' => $billingCycle,
                    'billing_status' => $billingStatus,
                    'starts_at' => $currentPeriodStartsAt,
                    'trial_ends_at' => $trialEndsAt,
                    'current_period_starts_at' => $currentPeriodStartsAt,
                    'current_period_ends_at' => $currentPeriodEndsAt,
                    'next_billing_at' => $nextBillingAt,
                    'amount' => $amount,
                    'notes' => $notes !== '' ? $notes : null,
                ]);
                $subscriptionId = (int) $pdo->lastInsertId();
            }

            $planKey = $pdo->prepare('SELECT plan_key FROM saas_plans WHERE id = :id');
            $planKey->execute(['id' => $planId]);
            $tenantPlan = (string) ($planKey->fetchColumn() ?: 'custom');
            $tenantStatus = $billingStatus === 'suspended' ? 'suspended' : 'active';
            $tenantUpdate = $pdo->prepare('UPDATE tenants SET plan = :plan, status = :status WHERE id = :id');
            $tenantUpdate->execute(['plan' => $tenantPlan, 'status' => $tenantStatus, 'id' => $tenantId]);

            $pdo->commit();
            Audit::log($action, [
                'subscription_id' => $subscriptionId,
                'plan_id' => $planId,
                'billing_status' => $billingStatus,
                'current_period_ends_at' => $currentPeriodEndsAt,
            ], $tenantId);

            $accessStatus = (new AccessControlService())->statusForTenant($tenantId);
            if (!empty($accessStatus['allowed'])) {
                Flash::set('success', 'Vigência atualizada e acesso da empresa liberado.');
            } else {
                Flash::set('warning', 'A vigência foi atualizada, mas o acesso continua bloqueado: ' . (string) ($accessStatus['message'] ?? 'revise as cobranças e o status da assinatura.'));
            }
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Flash::set('error', 'Não foi possível atualizar assinatura: ' . $exception->getMessage());
        }
        $this->redirect('/billing?tenant_id=' . $tenantId);
    }

    public function createInvoice(): void
    {
        $tenantId = (int) ($_POST['tenant_id'] ?? 0);
        $subscriptionId = (int) ($_POST['subscription_id'] ?? 0);
        $amount = $this->money((string) ($_POST['amount'] ?? '0'));
        $dueDate = $this->dateOrNull((string) ($_POST['due_date'] ?? '')) ?: date('Y-m-d');
        $periodStart = $this->dateOrNull((string) ($_POST['period_start'] ?? '')) ?: date('Y-m-01');
        $periodEnd = $this->dateOrNull((string) ($_POST['period_end'] ?? '')) ?: date('Y-m-t');
        $notes = trim((string) ($_POST['notes'] ?? ''));

        if ($tenantId < 1 || $subscriptionId < 1 || $amount <= 0) {
            Flash::set('error', 'Informe empresa, assinatura e valor da cobrança.');
            $this->redirect('/billing');
        }

        try {
            $invoiceNumber = 'RS-' . date('Ym') . '-' . str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT);
            $statement = Database::connection()->prepare(
                'INSERT INTO tenant_invoices
                    (tenant_id, subscription_id, invoice_number, period_start, period_end, amount, due_date, status, notes)
                 VALUES
                    (:tenant_id, :subscription_id, :invoice_number, :period_start, :period_end, :amount, :due_date, "open", :notes)'
            );
            $statement->execute([
                'tenant_id' => $tenantId,
                'subscription_id' => $subscriptionId,
                'invoice_number' => $invoiceNumber,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'amount' => $amount,
                'due_date' => $dueDate,
                'notes' => $notes !== '' ? $notes : null,
            ]);
            Audit::log('billing.invoice_created', ['invoice_number' => $invoiceNumber, 'amount' => $amount], $tenantId);
            Flash::set('success', 'Cobrança criada.');
        } catch (Throwable $exception) {
            Flash::set('error', 'Não foi possível criar cobrança: ' . $exception->getMessage());
        }
        $this->redirect('/billing');
    }

    public function updateInvoice(): void
    {
        $invoiceId = (int) ($_POST['invoice_id'] ?? 0);
        $status = (string) ($_POST['status'] ?? 'open');
        if ($invoiceId < 1 || !in_array($status, ['open', 'paid', 'overdue', 'cancelled'], true)) {
            Flash::set('error', 'Cobrança inválida.');
            $this->redirect('/billing');
        }

        try {
            $result = (new PaymentGatewayService())->setInvoiceStatus($invoiceId, $status, ['changed_by' => 'admin']);
            Audit::log('billing.invoice_updated', ['invoice_id' => $invoiceId, 'status' => $status], (int) ($result['tenant_id'] ?? 0) ?: null);
            Flash::set('success', $status === 'paid'
                ? 'Pagamento confirmado. Vigência e acesso foram recalculados automaticamente.'
                : 'Cobrança atualizada. As regras de acesso foram recalculadas.');
        } catch (Throwable $exception) {
            Flash::set('error', 'Não foi possível atualizar a cobrança: ' . $exception->getMessage());
        }
        $this->redirect('/billing');
    }

    private function money(string $value): float
    {
        $value = str_replace(['R$', ' ', '.'], '', $value);
        $value = str_replace(',', '.', $value);
        return round((float) $value, 2);
    }

    private function dateOrNull(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $timestamp = strtotime($value);
        return $timestamp ? date('Y-m-d', $timestamp) : null;
    }

    private function slug(string $value): string
    {
        $value = mb_strtolower($value);
        $value = preg_replace('/[^a-z0-9_.-]+/i', '-', $value) ?: '';
        return trim($value, '-');
    }

    private function redirect(string $path): never
    {
        header('Location: ' . Router::url($path));
        exit;
    }
}
