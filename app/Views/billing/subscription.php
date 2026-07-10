<?php

use App\Core\Auth;
use App\Core\Router;
use App\Core\View;

$money = static fn (mixed $value): string => 'R$ ' . number_format((float) $value, 2, ',', '.');
$date = static function (?string $value): string {
    if (!$value) return '—';
    $timestamp = strtotime($value);
    return $timestamp ? date('d/m/Y', $timestamp) : $value;
};
$cycleLabel = ['monthly' => 'Mensal', 'yearly' => 'Anual', 'custom' => 'Personalizado'];
$statusLabel = ['trialing' => 'Em teste', 'active' => 'Ativa', 'overdue' => 'Em atraso', 'suspended' => 'Suspensa', 'canceled' => 'Cancelada', 'open' => 'Aberta', 'paid' => 'Paga', 'cancelled' => 'Cancelada'];
$nextInvoice = $invoices[0] ?? null;
$nextPaymentLink = $nextInvoice['external_checkout_url'] ?? $nextInvoice['external_invoice_url'] ?? '';
?>

<section class="subscription-hero card">
    <div class="subscription-main">
        <div>
            <span class="eyebrow">Minha assinatura</span>
            <h2><?= View::e($tenant['name']) ?></h2>
            <p>Resumo do plano contratado, limites de uso e próximas cobranças.</p>
        </div>
        <span class="badge badge-<?= View::e($plan['billing_status']) ?>"><?= View::e($statusLabel[$plan['billing_status']] ?? $plan['billing_status']) ?></span>
    </div>

    <div class="subscription-summary-grid">
        <article class="subscription-summary-card"><span>Plano atual</span><strong><?= View::e($plan['name']) ?></strong><small><?= View::e(ucfirst((string) $plan['key'])) ?></small></article>
        <article class="subscription-summary-card"><span>Valor do plano</span><strong><?= View::e($money($plan['monthly_price'])) ?></strong><small><?= View::e($cycleLabel[$plan['billing_cycle']] ?? $plan['billing_cycle']) ?></small></article>
        <article class="subscription-summary-card"><span>Período atual</span><strong><?= View::e($date($plan['current_period_starts_at'])) ?></strong><small>até <?= View::e($date($plan['current_period_ends_at'])) ?></small></article>
        <article class="subscription-summary-card"><span>Próxima cobrança</span><strong><?= View::e($date($plan['next_billing_at'])) ?></strong><small>Fim do teste: <?= View::e($date($plan['trial_ends_at'])) ?></small></article>
    </div>

    <?php if (!empty($plan['features'])): ?>
        <div class="pill-list subscription-features"><?php foreach ($plan['features'] as $feature): ?><span class="tag-pill"><?= View::e($feature) ?></span><?php endforeach; ?></div>
    <?php endif; ?>

    <div class="subscription-actions">
        <?php if ($nextInvoice && $nextPaymentLink && ($nextInvoice['status'] ?? '') !== 'paid'): ?>
            <a class="btn btn-primary" href="<?= View::e($nextPaymentLink) ?>" target="_blank" rel="noopener">Pagar próxima cobrança</a>
        <?php endif; ?>
        <?php if (Auth::isSuperAdmin()): ?><a class="btn btn-outline" href="<?= View::e(Router::url('/billing')) ?>">Voltar para cobrança</a><?php endif; ?>
    </div>
</section>

<section class="card table-card subscription-usage-card">
    <div class="section-heading"><div><span class="eyebrow">Uso do mês</span><h2>Limites do plano</h2></div></div>
    <div class="usage-grid">
    <?php foreach ($limitRows as $row): ?>
        <article class="usage-tile <?= $row['blocked'] ? 'is-blocked' : '' ?>">
            <div><strong><?= View::e($row['label']) ?></strong><small><?= View::e($row['key']) ?></small></div>
            <div class="usage-values"><span><?= (int) $row['used'] ?></span><small>de <?= $row['limit'] === null ? 'ilimitado' : (int) $row['limit'] ?></small></div>
            <div><div class="usage-bar"><span style="width: <?= (int) $row['percent'] ?>%"></span></div><small><?= (int) $row['percent'] ?>% utilizado</small></div>
            <span class="badge <?= $row['blocked'] ? 'badge-overdue' : 'badge-active' ?>"><?= $row['blocked'] ? 'Limite atingido' : 'OK' ?></span>
        </article>
    <?php endforeach; ?>
    </div>
</section>

<section class="card table-card">
    <div class="section-heading"><div><span class="eyebrow">Financeiro</span><h2>Cobranças</h2></div><span class="badge"><?= count($invoices) ?> registro(s)</span></div>
    <div class="table-wrap"><table class="clean-table"><thead><tr><th>Número</th><th>Período</th><th>Valor</th><th>Vencimento</th><th>Status</th><th>Pagamento</th><th>Pago em</th></tr></thead><tbody>
    <?php foreach ($invoices as $invoice): ?>
        <?php $paymentLink = $invoice['external_checkout_url'] ?? $invoice['external_invoice_url'] ?? ''; ?>
        <tr>
            <td><strong><?= View::e($invoice['invoice_number']) ?></strong></td>
            <td><?= View::e($date($invoice['period_start'])) ?> a <?= View::e($date($invoice['period_end'])) ?></td>
            <td><?= View::e($money($invoice['amount'])) ?></td>
            <td><?= View::e($date($invoice['due_date'])) ?></td>
            <td><span class="badge badge-<?= View::e($invoice['status']) ?>"><?= View::e($statusLabel[$invoice['status']] ?? $invoice['status']) ?></span></td>
            <td><?php if ($paymentLink && $invoice['status'] !== 'paid'): ?><a class="btn btn-small btn-primary" href="<?= View::e($paymentLink) ?>" target="_blank" rel="noopener">Pagar agora</a><?php elseif ($paymentLink): ?><a class="btn btn-small btn-outline" href="<?= View::e($paymentLink) ?>" target="_blank" rel="noopener">Ver link</a><?php else: ?><small>Aguardando link</small><?php endif; ?></td>
            <td><?= View::e($date($invoice['paid_at'] ?? null)) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$invoices): ?><tr><td colspan="7"><div class="empty-state">Nenhuma cobrança encontrada.</div></td></tr><?php endif; ?>
    </tbody></table></div>
</section>
