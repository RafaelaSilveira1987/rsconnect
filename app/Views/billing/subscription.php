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
$statusLabel = ['trialing' => 'Em teste', 'active' => 'Ativa', 'overdue' => 'Em atraso', 'suspended' => 'Suspensa', 'canceled' => 'Cancelada', 'open' => 'Aberta', 'paid' => 'Paga', 'cancelled' => 'Cancelada'];
?>

<section class="card hero-card">
    <div class="section-heading">
        <div><span class="eyebrow">Assinatura</span><h2><?= View::e($tenant['name']) ?></h2></div>
        <span class="badge badge-<?= View::e($plan['billing_status']) ?>"><?= View::e($statusLabel[$plan['billing_status']] ?? $plan['billing_status']) ?></span>
    </div>
    <div class="stats-grid">
        <article class="stat-card"><span>Plano atual</span><strong><?= View::e($plan['name']) ?></strong><small><?= View::e($plan['key']) ?></small></article>
        <article class="stat-card"><span>Valor mensal</span><strong><?= View::e($money($plan['monthly_price'])) ?></strong><small><?= View::e($plan['billing_cycle']) ?></small></article>
        <article class="stat-card"><span>Período atual</span><strong><?= View::e($date($plan['current_period_ends_at'])) ?></strong><small>Início: <?= View::e($date($plan['current_period_starts_at'])) ?></small></article>
        <article class="stat-card"><span>Próxima cobrança</span><strong><?= View::e($date($plan['next_billing_at'])) ?></strong><small>Fim do teste: <?= View::e($date($plan['trial_ends_at'])) ?></small></article>
    </div>
    <?php if (!empty($plan['features'])): ?>
        <div class="pill-list"><?php foreach ($plan['features'] as $feature): ?><span class="tag-pill"><?= View::e($feature) ?></span><?php endforeach; ?></div>
    <?php endif; ?>
    <?php if (Auth::isSuperAdmin()): ?><p><a class="btn btn-outline" href="<?= View::e(Router::url('/billing')) ?>">Voltar para cobrança</a></p><?php endif; ?>
</section>

<section class="card table-card">
    <div class="section-heading"><div><span class="eyebrow">Uso do mês</span><h2>Limites do plano</h2></div></div>
    <div class="table-wrap"><table class="clean-table"><thead><tr><th>Recurso</th><th>Usado</th><th>Limite</th><th>Consumo</th><th>Status</th></tr></thead><tbody>
    <?php foreach ($limitRows as $row): ?>
        <tr>
            <td><strong><?= View::e($row['label']) ?></strong><br><small><?= View::e($row['key']) ?></small></td>
            <td><?= (int) $row['used'] ?></td>
            <td><?= $row['limit'] === null ? 'Ilimitado' : (int) $row['limit'] ?></td>
            <td><div class="usage-bar"><span style="width: <?= (int) $row['percent'] ?>%"></span></div><small><?= (int) $row['percent'] ?>%</small></td>
            <td><?= $row['blocked'] ? '<span class="badge badge-overdue">Limite atingido</span>' : '<span class="badge badge-active">OK</span>' ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody></table></div>
</section>

<section class="card table-card">
    <div class="section-heading"><div><span class="eyebrow">Financeiro</span><h2>Cobranças</h2></div><span class="badge"><?= count($invoices) ?> registro(s)</span></div>
    <div class="table-wrap"><table class="clean-table"><thead><tr><th>Número</th><th>Período</th><th>Valor</th><th>Vencimento</th><th>Status</th><th>Pago em</th></tr></thead><tbody>
    <?php foreach ($invoices as $invoice): ?>
        <tr><td><strong><?= View::e($invoice['invoice_number']) ?></strong></td><td><?= View::e($date($invoice['period_start'])) ?> a <?= View::e($date($invoice['period_end'])) ?></td><td><?= View::e($money($invoice['amount'])) ?></td><td><?= View::e($date($invoice['due_date'])) ?></td><td><span class="badge badge-<?= View::e($invoice['status']) ?>"><?= View::e($statusLabel[$invoice['status']] ?? $invoice['status']) ?></span></td><td><?= View::e($date($invoice['paid_at'] ?? null)) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$invoices): ?><tr><td colspan="6"><div class="empty-state">Nenhuma cobrança encontrada.</div></td></tr><?php endif; ?>
    </tbody></table></div>
</section>
