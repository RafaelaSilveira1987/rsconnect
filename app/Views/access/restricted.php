<?php

use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;

$status = $accessStatus ?? [];
$subscription = $status['subscription'] ?? [];
$invoice = $status['invoice'] ?? [];
$whatsapp = '5532987073537';
$message = rawurlencode('Olá! Preciso revisar o acesso da empresa ' . (string) ($status['tenant_name'] ?? '') . ' no RS Connect. Motivo: ' . (string) ($status['title'] ?? 'acesso limitado') . '.');
?>
<section class="access-restricted-card">
    <div class="access-restricted-brand"><span>RS</span><strong>RS Connect</strong></div>
    <div class="access-restricted-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/><path d="M12 14v3"/></svg>
    </div>
    <span class="eyebrow">Acesso temporariamente limitado</span>
    <h1><?= View::e($status['title'] ?? 'Revise sua assinatura') ?></h1>
    <p><?= View::e($status['message'] ?? 'Fale com a equipe RS Connect para revisar o acesso.') ?></p>

    <div class="access-restricted-details">
        <div><span>Empresa</span><strong><?= View::e($status['tenant_name'] ?? 'Empresa') ?></strong></div>
        <?php if (!empty($subscription['plan_name'])): ?><div><span>Plano</span><strong><?= View::e($subscription['plan_name']) ?></strong></div><?php endif; ?>
        <?php if (!empty($subscription['current_period_ends_at'])): ?><div><span>Fim da vigência</span><strong><?= View::e(date('d/m/Y', strtotime((string) $subscription['current_period_ends_at']))) ?></strong></div><?php endif; ?>
        <?php if (!empty($invoice['invoice_number'])): ?><div><span>Cobrança</span><strong><?= View::e($invoice['invoice_number']) ?></strong></div><?php endif; ?>
    </div>

    <div class="access-restricted-actions">
        <a class="btn btn-primary" href="<?= View::e(Router::url('/subscription')) ?>">Ver assinatura e cobranças</a>
        <a class="btn client-whatsapp-button" href="https://wa.me/<?= View::e($whatsapp) ?>?text=<?= View::e($message) ?>" target="_blank" rel="noopener noreferrer">Falar com a RS Connect</a>
    </div>

    <form method="post" action="<?= View::e(Router::url('/logout')) ?>">
        <?= Csrf::input() ?>
        <button class="btn btn-quiet" type="submit">Sair da conta</button>
    </form>
    <small>O acesso é liberado automaticamente quando a vigência ou a cobrança é regularizada.</small>
</section>
