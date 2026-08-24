<?php

use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;

$tenant = $detail['tenant'] ?? [];
$sections = $detail['sections'] ?? [];
$card = $detail['card'] ?? [];
$status = $detail['status'] ?? [];
$actions = $detail['quick_actions'] ?? [];
$statusBadge = static fn (string $status): string => match ($status) {
    'complete', 'skipped' => 'badge-success',
    'attention' => 'badge-danger',
    default => 'badge-warning',
};
$statusLabel = static fn (string $status): string => match ($status) {
    'complete' => 'Concluído',
    'skipped' => 'Opcional/dispensado',
    'attention' => 'Atenção',
    'pending' => 'Pendente',
    default => 'Automático',
};
?>

<section class="hero-card implementation-hero compact">
    <div>
        <span class="eyebrow">Checklist de implantação</span>
        <h2><?= View::e($tenant['name'] ?? 'Empresa') ?></h2>
        <p>Validação comercial antes de entregar a operação: WhatsApp, IA, agenda, cobrança, LGPD, monitoramento e backup.</p>
    </div>
    <div class="hero-actions">
        <span class="badge <?= View::e($status['badge'] ?? 'badge-warning') ?>"><?= View::e($status['label'] ?? 'Pendente') ?></span>
        <strong class="implementation-percent-big"><?= (int) ($detail['percent'] ?? 0) ?>%</strong>
        <form method="post" action="<?= View::e(Router::url('/implementation/refresh')) ?>">
            <?= Csrf::input() ?>
            <input type="hidden" name="tenant_id" value="<?= (int) ($tenant['id'] ?? 0) ?>">
            <button class="btn btn-primary" type="submit">Recalcular</button>
        </form>
        <a class="btn btn-outline" href="<?= View::e(Router::url('/companies/health?tenant_id=' . (int) ($tenant['id'] ?? 0))) ?>">Ver saúde atual</a>
        <a class="btn" href="<?= View::e(Router::url('/implementation')) ?>">Voltar</a>
    </div>
</section>

<div class="implementation-detail-grid">
    <section class="card">
        <div class="section-heading"><div><span class="eyebrow">Ações rápidas</span><h2>Configuração do cliente</h2></div></div>
        <div class="implementation-action-grid">
            <?php foreach ($actions as $action): ?>
                <a class="btn" href="<?= View::e(Router::url($action['url'])) ?>"><?= View::e($action['label']) ?></a>
            <?php endforeach; ?>
        </div>
    </section>
    <section class="card">
        <div class="section-heading"><div><span class="eyebrow">Resumo</span><h2>Entrega comercial</h2></div></div>
        <div class="implementation-progress large"><i style="width: <?= max(0, min(100, (int) ($detail['percent'] ?? 0))) ?>%"></i></div>
        <p class="muted-text">Quando o checklist atingir pelo menos 95%, a empresa pode ser considerada em operação. Itens opcionais podem ser marcados como dispensados.</p>
    </section>
</div>

<?php foreach ($sections as $category => $items): ?>
    <section class="card implementation-section">
        <div class="section-heading"><div><span class="eyebrow">Módulo</span><h2><?= View::e($category) ?></h2></div></div>
        <div class="implementation-items">
            <?php foreach ($items as $item): ?>
                <article class="implementation-item is-<?= View::e($item['status'] ?? 'pending') ?>">
                    <div class="implementation-item-head">
                        <div>
                            <strong><?= View::e($item['label'] ?? '') ?></strong>
                            <p><?= View::e($item['description'] ?? '') ?></p>
                            <small><?= View::e($item['message'] ?? '') ?></small>
                            <?php if (!empty($item['notes'])): ?><em>Observação: <?= View::e($item['notes']) ?></em><?php endif; ?>
                        </div>
                        <span class="badge <?= $statusBadge((string) ($item['status'] ?? 'pending')) ?>"><?= $statusLabel((string) ($item['status'] ?? 'pending')) ?></span>
                    </div>
                    <form class="implementation-item-form" method="post" action="<?= View::e(Router::url('/implementation/item')) ?>">
                        <?= Csrf::input() ?>
                        <input type="hidden" name="tenant_id" value="<?= (int) ($tenant['id'] ?? 0) ?>">
                        <input type="hidden" name="item_key" value="<?= View::e($item['key'] ?? '') ?>">
                        <select name="manual_status">
                            <option value="auto" <?= ($item['manual_status'] ?? 'auto') === 'auto' ? 'selected' : '' ?>>Automático</option>
                            <option value="complete" <?= ($item['manual_status'] ?? '') === 'complete' ? 'selected' : '' ?>>Marcar concluído</option>
                            <option value="pending" <?= ($item['manual_status'] ?? '') === 'pending' ? 'selected' : '' ?>>Manter pendente</option>
                            <option value="skipped" <?= ($item['manual_status'] ?? '') === 'skipped' ? 'selected' : '' ?>>Dispensar opcional</option>
                            <option value="attention" <?= ($item['manual_status'] ?? '') === 'attention' ? 'selected' : '' ?>>Marcar atenção</option>
                        </select>
                        <input type="text" name="notes" value="<?= View::e($item['notes'] ?? '') ?>" placeholder="Observação interna">
                        <button class="btn btn-quiet" type="submit">Salvar</button>
                    </form>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
<?php endforeach; ?>
