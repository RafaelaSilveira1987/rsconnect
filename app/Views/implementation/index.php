<?php

use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;

$summary = $data['summary'] ?? [];
$tenants = $data['tenants'] ?? [];
$statusClass = static fn (string $key): string => match ($key) {
    'ready' => 'badge-success',
    'testing' => 'badge-info',
    'attention' => 'badge-danger',
    default => 'badge-warning',
};
?>

<section class="hero-card implementation-hero">
    <div>
        <span class="eyebrow">Implantação comercial</span>
        <h2>Checklist de entrega por empresa.</h2>
        <p>Veja quais clientes estão prontos para operar, quais ainda estão em configuração e quais pendências bloqueiam a entrega comercial.</p>
    </div>
    <div class="hero-actions">
        <form method="post" action="<?= View::e(Router::url('/implementation/refresh')) ?>">
            <?= Csrf::input() ?>
            <button class="btn btn-primary" type="submit">Recalcular todos</button>
        </form>
    </div>
</section>

<div class="report-kpi-grid implementation-kpis">
    <article class="card report-kpi"><span>Empresas</span><strong><?= (int) ($summary['total'] ?? 0) ?></strong><small>Total cadastradas</small></article>
    <article class="card report-kpi"><span>Em operação</span><strong><?= (int) ($summary['ready'] ?? 0) ?></strong><small>Prontas para entrega</small></article>
    <article class="card report-kpi"><span>Em teste</span><strong><?= (int) ($summary['testing'] ?? 0) ?></strong><small>Prontas para validar</small></article>
    <article class="card report-kpi"><span>Média geral</span><strong><?= (int) ($summary['average_percent'] ?? 0) ?>%</strong><small>Progresso de implantação</small></article>
</div>

<section class="card" style="margin-top:16px">
    <div class="section-heading">
        <div>
            <span class="eyebrow">Clientes</span>
            <h2>Status de implantação</h2>
        </div>
    </div>

    <div class="implementation-list">
        <?php foreach ($tenants as $tenant): ?>
            <article class="implementation-card">
                <div class="implementation-main">
                    <div class="implementation-title-row">
                        <div>
                            <h3><?= View::e($tenant['name'] ?? '') ?></h3>
                            <p><?= View::e($tenant['segment'] ?: 'Segmento não informado') ?> · Plano <?= View::e($tenant['plan'] ?: '-') ?></p>
                        </div>
                        <span class="badge <?= View::e($tenant['status_badge'] ?? $statusClass($tenant['status_key'] ?? 'pending')) ?>"><?= View::e($tenant['status_label'] ?? 'Pendente') ?></span>
                    </div>
                    <div class="implementation-progress" aria-label="Progresso de implantação">
                        <i style="width: <?= max(0, min(100, (int) ($tenant['percent'] ?? 0))) ?>%"></i>
                    </div>
                    <div class="implementation-meta">
                        <span><?= (int) ($tenant['percent'] ?? 0) ?>% concluído</span>
                        <span><?= (int) ($tenant['done_count'] ?? 0) ?>/<?= (int) ($tenant['total_count'] ?? 0) ?> itens finalizados</span>
                        <?php if ((int) ($tenant['attention_count'] ?? 0) > 0): ?><span class="text-danger"><?= (int) $tenant['attention_count'] ?> pendência(s) crítica(s)</span><?php endif; ?>
                    </div>
                    <?php if (!empty($tenant['next_items'])): ?>
                        <div class="implementation-next">
                            <strong>Próximos ajustes</strong>
                            <ul>
                                <?php foreach ($tenant['next_items'] as $item): ?>
                                    <li><?= View::e($item['label'] ?? '') ?> — <?= View::e($item['message'] ?? '') ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="implementation-actions">
                    <a class="btn btn-primary" href="<?= View::e(Router::url('/implementation?tenant_id=' . (int) $tenant['id'])) ?>">Abrir checklist</a>
                    <a class="btn" href="<?= View::e(Router::url('/conversations?tenant_id=' . (int) $tenant['id'])) ?>">Conversas</a>
                </div>
            </article>
        <?php endforeach; ?>
        <?php if (!$tenants): ?>
            <div class="empty-state">Nenhuma empresa cadastrada ainda.</div>
        <?php endif; ?>
    </div>
</section>
