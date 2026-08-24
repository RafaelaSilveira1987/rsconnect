<?php

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;

$formatDate = static function (?string $date, string $format = 'd/m/Y H:i'): string {
    if (!$date) {
        return '—';
    }
    $timestamp = strtotime($date);
    return $timestamp ? date($format, $timestamp) : $date;
};
$currentTenantId = (int) ($filters['tenant_id'] ?? 0);
?>

<section class="queue-hero card">
    <div>
        <span class="eyebrow">Operação de atendimento</span>
        <h2>Fila, equipe e distribuição</h2>
        <p>Organize conversas por status, responsável, setor e prioridade. A IA continua atuando, mas a equipe ganha controle operacional.</p>
    </div>
    <a class="btn btn-primary" href="<?= View::e(Router::url('/conversations')) ?>">Abrir conversas</a>
</section>

<section class="metric-grid queue-metrics">
    <article class="metric-card compact"><span>Total na fila</span><strong><?= (int) ($metrics['total'] ?? 0) ?></strong><small>conversas filtradas</small></article>
    <article class="metric-card compact"><span>Pendentes</span><strong><?= (int) ($metrics['pending'] ?? 0) ?></strong><small>novo/aguardando</small></article>
    <article class="metric-card compact"><span>Em atendimento</span><strong><?= (int) ($metrics['in_service'] ?? 0) ?></strong><small>com humano/equipe</small></article>
    <article class="metric-card compact"><span>Sem responsável</span><strong><?= (int) ($metrics['unassigned'] ?? 0) ?></strong><small>precisam de triagem</small></article>
    <article class="metric-card compact"><span>Prioridade alta</span><strong><?= (int) ($metrics['priority_open'] ?? 0) ?></strong><small>alta/urgente</small></article>
    <article class="metric-card compact"><span>Com não lidas</span><strong><?= (int) ($metrics['unread_threads'] ?? 0) ?></strong><small>exigem atenção</small></article>
</section>

<form class="filter-bar queue-filter card" method="get" action="<?= View::e(Router::url('/queue')) ?>">
    <?php if (Auth::isSuperAdmin()): ?>
        <label class="field compact-field"><span>Empresa</span>
            <select name="tenant_id" onchange="this.form.submit()">
                <option value="">Todas</option>
                <?php foreach ($tenants as $tenant): ?>
                    <option value="<?= (int) $tenant['id'] ?>" <?= $currentTenantId === (int) $tenant['id'] ? 'selected' : '' ?>><?= View::e($tenant['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    <?php endif; ?>

    <label class="field compact-field"><span>Status</span>
        <select name="operational_status">
            <option value="">Todos</option>
            <?php foreach ($statusLabels as $value => $label): ?>
                <option value="<?= View::e($value) ?>" <?= ($filters['operational_status'] ?? '') === $value ? 'selected' : '' ?>><?= View::e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </label>

    <label class="field compact-field"><span>Setor</span>
        <select name="department_id">
            <option value="">Todos</option>
            <?php foreach ($departments as $department): ?>
                <option value="<?= (int) $department['id'] ?>" <?= (int) ($filters['department_id'] ?? 0) === (int) $department['id'] ? 'selected' : '' ?>><?= View::e((Auth::isSuperAdmin() && !$currentTenantId ? ($department['tenant_name'] ?? '') . ' — ' : '') . $department['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </label>

    <label class="field compact-field"><span>Responsável</span>
        <select name="assigned_user_id">
            <option value="">Todos</option>
            <?php foreach ($users as $member): ?>
                <option value="<?= (int) $member['id'] ?>" <?= (int) ($filters['assigned_user_id'] ?? 0) === (int) $member['id'] ? 'selected' : '' ?>><?= View::e($member['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </label>

    <label class="field compact-field"><span>Prioridade</span>
        <select name="priority">
            <option value="">Todas</option>
            <?php foreach ($priorityLabels as $value => $label): ?>
                <option value="<?= View::e($value) ?>" <?= ($filters['priority'] ?? '') === $value ? 'selected' : '' ?>><?= View::e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </label>

    <button class="btn btn-secondary" type="submit">Filtrar</button>
    <a class="btn btn-outline" href="<?= View::e(Router::url('/queue')) ?>">Limpar</a>
</form>

<div class="queue-layout">
    <section class="queue-board card">
        <div class="section-heading clean-heading">
            <div>
                <span class="eyebrow">Fila ativa</span>
                <h2>Conversas em operação</h2>
            </div>
        </div>

        <div class="queue-table-wrap">
            <table class="queue-table">
                <thead>
                <tr>
                    <th>Contato</th>
                    <th>Status</th>
                    <th>Setor</th>
                    <th>Responsável</th>
                    <th>Prioridade</th>
                    <th>Última interação</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($conversations as $conversation): ?>
                    <tr class="queue-row priority-row-<?= View::e($conversation['priority'] ?? 'normal') ?>">
                        <td>
                            <strong><?= View::e($conversation['contact_name'] ?: $conversation['phone']) ?></strong>
                            <small><?= View::e($conversation['phone']) ?><?= Auth::isSuperAdmin() ? ' · ' . View::e($conversation['tenant_name']) : '' ?></small>
                            <span class="queue-preview"><?= View::e($conversation['last_message_preview'] ?: 'Sem prévia') ?></span>
                        </td>
                        <td><span class="mini-badge queue-status-<?= View::e($conversation['operational_status'] ?? 'new') ?>"><?= View::e($statusLabels[$conversation['operational_status'] ?? 'new'] ?? 'Novo') ?></span></td>
                        <td><?= $conversation['department_name'] ? '<span class="department-pill" style="--dept:' . View::e($conversation['department_color'] ?: '#146498') . '">' . View::e($conversation['department_name']) . '</span>' : '<span class="muted-text">Sem setor</span>' ?></td>
                        <td><?= $conversation['assigned_user_name'] ? View::e($conversation['assigned_user_name']) : '<span class="muted-text">Sem responsável</span>' ?></td>
                        <td><span class="mini-badge priority-<?= View::e($conversation['priority'] ?? 'normal') ?>"><?= View::e($priorityLabels[$conversation['priority'] ?? 'normal'] ?? 'Normal') ?></span></td>
                        <td><?= View::e($formatDate($conversation['last_message_at'], 'd/m H:i')) ?><?php if ((int) $conversation['unread_count'] > 0): ?><b class="unread-count inline-unread"><?= (int) $conversation['unread_count'] ?></b><?php endif; ?></td>
                        <td><a class="btn btn-outline btn-small" href="<?= View::e(Router::url('/conversations?conversation_id=' . (int) $conversation['id'])) ?>">Abrir</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$conversations): ?>
                    <tr><td colspan="7"><div class="empty-state">Nenhuma conversa encontrada para os filtros selecionados.</div></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <aside class="queue-side card">
        <div class="section-heading clean-heading">
            <div>
                <span class="eyebrow">Setores</span>
                <h2>Distribuição</h2>
            </div>
        </div>

        <?php if (Auth::can('queue.manage')): ?>
            <form class="queue-department-form" method="post" action="<?= View::e(Router::url('/queue/departments')) ?>">
                <?= Csrf::input() ?>
                <?php if (Auth::isSuperAdmin()): ?>
                    <label class="field"><span>Empresa</span>
                        <select name="tenant_id" required>
                            <option value="">Selecione</option>
                            <?php foreach ($tenants as $tenant): ?>
                                <option value="<?= (int) $tenant['id'] ?>" <?= $currentTenantId === (int) $tenant['id'] ? 'selected' : '' ?>><?= View::e($tenant['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                <?php endif; ?>
                <label class="field"><span>Novo setor</span><input name="name" placeholder="Comercial, Suporte, Financeiro"></label>
                <label class="field"><span>Descrição</span><input name="description" placeholder="Opcional"></label>
                <label class="field"><span>Cor</span><input type="color" name="color" value="#146498"></label>
                <button class="btn btn-primary btn-block" type="submit">Salvar setor</button>
            </form>
        <?php endif; ?>

        <div class="department-list">
            <?php foreach ($departments as $department): ?>
                <article class="department-card" style="--dept: <?= View::e($department['color'] ?: '#146498') ?>">
                    <strong><?= View::e($department['name']) ?></strong>
                    <small><?= View::e($department['tenant_name'] ?? '') ?><?= $department['status'] === 'inactive' ? ' · Inativo' : '' ?></small>
                    <?php if (!empty($department['description'])): ?><p><?= View::e($department['description']) ?></p><?php endif; ?>
                </article>
            <?php endforeach; ?>
            <?php if (!$departments): ?><p class="muted-text">Nenhum setor cadastrado ainda.</p><?php endif; ?>
        </div>
    </aside>
</div>
