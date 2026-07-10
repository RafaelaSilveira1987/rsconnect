<?php

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;

$typeLabels = ['task' => 'Tarefa', 'follow_up' => 'Follow-up', 'call' => 'Ligação', 'meeting' => 'Reunião'];
$statusLabels = ['pending' => 'Pendente', 'completed' => 'Concluída', 'cancelled' => 'Cancelada'];
$priorityLabels = ['low' => 'Baixa', 'medium' => 'Média', 'high' => 'Alta'];
$date = static function (?string $value, string $format = 'd/m/Y H:i'): string {
    if (!$value) return 'Sem prazo';
    try { return (new DateTime($value))->format($format); } catch (Throwable) { return $value; }
};
$isOverdue = static function (array $task): bool {
    return $task['status'] === 'pending' && $task['due_at'] && strtotime($task['due_at']) < time();
};
$returnUrl = '/tasks?' . http_build_query(array_filter([
    'tenant_id' => (int) ($filters['tenant_id'] ?? 0),
    'status' => $filters['status'] ?? '',
    'type' => $filters['type'] ?? '',
    'assigned_user_id' => (int) ($filters['assigned_user_id'] ?? 0),
], static fn ($value) => $value !== '' && $value !== 0));
?>

<div class="page-heading">
    <div>
        <span class="eyebrow">Organização comercial</span>
        <h2>Tarefas e follow-ups</h2>
        <p>Controle ligações, reuniões e próximos passos sem perder oportunidades.</p>
    </div>
    <?php if ($canManage && ($filters['tenant_id'] ?? 0) > 0): ?>
        <details class="action-popover">
            <summary class="btn btn-primary">Nova atividade</summary>
            <form class="popover-panel form-stack wide" method="post" action="<?= View::e(Router::url('/tasks')) ?>">
                <?= Csrf::input() ?><input type="hidden" name="tenant_id" value="<?= (int) $filters['tenant_id'] ?>">
                <strong>Criar atividade</strong>
                <div class="form-grid two"><label class="field"><span>Tipo</span><select name="task_type"><option value="follow_up">Follow-up</option><option value="call">Ligação</option><option value="meeting">Reunião</option><option value="task">Tarefa</option></select></label><label class="field"><span>Prazo</span><input type="datetime-local" name="due_at"></label></div>
                <label class="field"><span>Título *</span><input name="title" maxlength="180" required></label>
                <label class="field"><span>Descrição</span><textarea name="description" rows="3"></textarea></label>
                <div class="form-grid two"><label class="field"><span>Negócio</span><select name="lead_id"><option value="">Sem negócio</option><?php foreach ($leads as $lead): ?><option value="<?= (int) $lead['id'] ?>"><?= View::e($lead['title'] . ' · ' . ($lead['contact_name'] ?: $lead['phone'])) ?></option><?php endforeach; ?></select></label><label class="field"><span>Contato</span><select name="contact_id"><option value="">Sem contato</option><?php foreach ($contacts as $contact): ?><option value="<?= (int) $contact['id'] ?>"><?= View::e($contact['name'] ?: $contact['phone']) ?></option><?php endforeach; ?></select></label></div>
                <div class="form-grid two"><label class="field"><span>Responsável</span><select name="assigned_user_id"><option value="">Sem responsável</option><?php foreach ($team as $member): ?><option value="<?= (int) $member['id'] ?>"><?= View::e($member['name']) ?></option><?php endforeach; ?></select></label><label class="field"><span>Prioridade</span><select name="priority"><option value="low">Baixa</option><option value="medium" selected>Média</option><option value="high">Alta</option></select></label></div>
                <button class="btn btn-primary" type="submit">Salvar atividade</button>
            </form>
        </details>
    <?php endif; ?>
</div>

<div class="metric-grid metric-grid-compact">
    <article class="metric-card metric-alert"><span>Atrasadas</span><strong><?= (int) ($metrics['overdue'] ?? 0) ?></strong><small>precisam de atenção</small></article>
    <article class="metric-card"><span>Para hoje</span><strong><?= (int) ($metrics['today_count'] ?? 0) ?></strong><small>atividades do dia</small></article>
    <article class="metric-card"><span>Pendentes</span><strong><?= (int) ($metrics['pending_count'] ?? 0) ?></strong><small>em aberto</small></article>
    <article class="metric-card"><span>Concluídas em 30 dias</span><strong><?= (int) ($metrics['completed_count'] ?? 0) ?></strong><small>ritmo da equipe</small></article>
</div>

<form class="filter-bar" method="get" action="<?= View::e(Router::url('/tasks')) ?>">
    <?php if (Auth::isSuperAdmin()): ?><select name="tenant_id" onchange="this.form.submit()"><option value="">Selecione a empresa</option><?php foreach ($tenants as $tenant): ?><option value="<?= (int) $tenant['id'] ?>" <?= (int) ($filters['tenant_id'] ?? 0) === (int) $tenant['id'] ? 'selected' : '' ?>><?= View::e($tenant['name']) ?></option><?php endforeach; ?></select><?php endif; ?>
    <select name="status"><option value="">Todos os status</option><?php foreach ($statusLabels as $value => $label): ?><option value="<?= View::e($value) ?>" <?= ($filters['status'] ?? '') === $value ? 'selected' : '' ?>><?= View::e($label) ?></option><?php endforeach; ?></select>
    <select name="type"><option value="">Todos os tipos</option><?php foreach ($typeLabels as $value => $label): ?><option value="<?= View::e($value) ?>" <?= ($filters['type'] ?? '') === $value ? 'selected' : '' ?>><?= View::e($label) ?></option><?php endforeach; ?></select>
    <select name="assigned_user_id"><option value="">Toda a equipe</option><?php foreach ($team as $member): ?><option value="<?= (int) $member['id'] ?>" <?= (int) ($filters['assigned_user_id'] ?? 0) === (int) $member['id'] ? 'selected' : '' ?>><?= View::e($member['name']) ?></option><?php endforeach; ?></select>
    <button class="btn btn-secondary" type="submit">Filtrar</button>
    <a class="btn btn-quiet" href="<?= View::e(Router::url('/tasks')) ?>">Limpar</a>
</form>

<section class="card task-list-card">
    <div class="section-heading compact"><div><span class="eyebrow">Agenda</span><h2><?= count($tasks) ?> atividades</h2></div></div>
    <div class="task-list">
        <?php foreach ($tasks as $task): ?>
            <article class="task-row<?= $isOverdue($task) ? ' is-overdue' : '' ?><?= $task['status'] === 'completed' ? ' is-completed' : '' ?>">
                <span class="activity-icon activity-<?= View::e($task['task_type']) ?>" aria-hidden="true"></span>
                <div class="task-main"><div class="task-title-line"><strong><?= View::e($task['title']) ?></strong><span class="badge badge-<?= View::e($task['status']) ?>"><?= View::e($statusLabels[$task['status']] ?? $task['status']) ?></span><span class="priority-text priority-<?= View::e($task['priority']) ?>"><?= View::e($priorityLabels[$task['priority']] ?? $task['priority']) ?></span></div><p><?= View::e($task['description'] ?: 'Sem descrição') ?></p><small><?= View::e($typeLabels[$task['task_type']] ?? $task['task_type']) ?> · <?= View::e($task['contact_name'] ?: ($task['lead_title'] ?: 'Sem vínculo')) ?> · Responsável: <?= View::e($task['assigned_name'] ?: 'não definido') ?></small></div>
                <div class="task-deadline"><small><?= $isOverdue($task) ? 'Atrasada' : 'Prazo' ?></small><strong><?= View::e($date($task['due_at'])) ?></strong></div>
                <?php if ($canManage): ?><div class="task-actions"><?php if ($task['status'] !== 'completed'): ?><form method="post" action="<?= View::e(Router::url('/tasks/status')) ?>"><?= Csrf::input() ?><input type="hidden" name="tenant_id" value="<?= (int) $filters['tenant_id'] ?>"><input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>"><input type="hidden" name="status" value="completed"><input type="hidden" name="return_to" value="<?= View::e($returnUrl) ?>"><button class="btn btn-small btn-primary" type="submit">Concluir</button></form><?php endif; ?><?php if ($task['status'] === 'pending'): ?><form method="post" action="<?= View::e(Router::url('/tasks/status')) ?>"><?= Csrf::input() ?><input type="hidden" name="tenant_id" value="<?= (int) $filters['tenant_id'] ?>"><input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>"><input type="hidden" name="status" value="cancelled"><input type="hidden" name="return_to" value="<?= View::e($returnUrl) ?>"><button class="btn btn-small btn-quiet" type="submit">Cancelar</button></form><?php endif; ?></div><?php endif; ?>
            </article>
        <?php endforeach; ?>
        <?php if (!$tasks): ?><div class="empty-state">Nenhuma atividade encontrada para os filtros selecionados.</div><?php endif; ?>
    </div>
</section>
