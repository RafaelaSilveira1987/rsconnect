<?php

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;

$money = static fn (mixed $value): string => 'R$ ' . number_format((float) $value, 2, ',', '.');
$date = static function (?string $value, string $format = 'd/m/Y'): string {
    if (!$value) return 'Sem data';
    try { return (new DateTime($value))->format($format); } catch (Throwable) { return $value; }
};
$typeLabels = ['task' => 'Tarefa', 'follow_up' => 'Retorno', 'call' => 'Ligação', 'meeting' => 'Reunião'];
$priorityLabels = ['low' => 'Baixa', 'medium' => 'Média', 'high' => 'Alta'];
$leadsByStage = [];
foreach ($leads as $lead) $leadsByStage[(int) $lead['stage_id']][] = $lead;
$currentUrl = '/crm?' . http_build_query(array_filter([
    'tenant_id' => (int) ($filters['tenant_id'] ?? 0),
    'pipeline_id' => (int) ($filters['pipeline_id'] ?? 0),
    'search' => $filters['search'] ?? '',
    'owner_id' => (int) ($filters['owner_id'] ?? 0),
    'lead_id' => $selected['id'] ?? 0,
], static fn ($value) => $value !== '' && $value !== 0));
?>

<div class="page-heading">
    <div>
        <span class="eyebrow">Pipeline comercial</span>
        <h2>Funil de vendas</h2>
        <p>Acompanhe cada oportunidade, registre o próximo passo e mantenha a equipe alinhada.</p>
    </div>
    <?php if ($canManage && ($filters['tenant_id'] ?? 0) > 0 && $stages): ?>
        <details class="action-popover">
            <summary class="btn btn-primary">+ Novo negócio</summary>
            <form class="popover-panel form-stack wide" method="post" action="<?= View::e(Router::url('/crm/leads')) ?>">
                <?= Csrf::input() ?><input type="hidden" name="tenant_id" value="<?= (int) $filters['tenant_id'] ?>"><input type="hidden" name="pipeline_id" value="<?= (int) $filters['pipeline_id'] ?>">
                <strong>Adicionar oportunidade</strong>
                <label class="field"><span>Contato *</span><select name="contact_id" required><option value="">Selecione</option><?php foreach ($contacts as $contact): ?><option value="<?= (int) $contact['id'] ?>"><?= View::e(($contact['name'] ?: $contact['phone']) . ($contact['company'] ? ' · ' . $contact['company'] : '')) ?></option><?php endforeach; ?></select></label>
                <label class="field"><span>Título do negócio *</span><input name="title" maxlength="180" placeholder="Ex.: Implantação WhatsApp" required></label>
                <div class="form-grid two">
                    <label class="field"><span>Etapa inicial</span><select name="stage_id" required><?php foreach ($stages as $stage): ?><option value="<?= (int) $stage['id'] ?>"><?= View::e($stage['name']) ?></option><?php endforeach; ?></select></label>
                    <label class="field"><span>Responsável</span><select name="owner_user_id"><option value="">Sem responsável</option><?php foreach ($team as $member): ?><option value="<?= (int) $member['id'] ?>"><?= View::e($member['name']) ?></option><?php endforeach; ?></select></label>
                </div>
                <div class="form-grid three">
                    <label class="field"><span>Valor</span><input name="value" inputmode="decimal" placeholder="0,00"></label>
                    <label class="field"><span>Prioridade</span><select name="priority"><option value="low">Baixa</option><option value="medium" selected>Média</option><option value="high">Alta</option></select></label>
                    <label class="field"><span>Previsão</span><input type="date" name="expected_close_at"></label>
                </div>
                <button class="btn btn-primary" type="submit">Adicionar ao funil</button>
            </form>
        </details>
    <?php endif; ?>
</div>

<div class="metric-grid metric-grid-compact">
    <article class="metric-card"><span>Negócios abertos</span><strong><?= (int) ($metrics['open_count'] ?? 0) ?></strong><small>oportunidades em andamento</small></article>
    <article class="metric-card"><span>Valor em aberto</span><strong class="metric-money"><?= View::e($money($metrics['open_value'] ?? 0)) ?></strong><small>soma do pipeline ativo</small></article>
    <article class="metric-card"><span>Ganhos</span><strong><?= (int) ($metrics['won_count'] ?? 0) ?></strong><small>negócios concluídos</small></article>
    <article class="metric-card"><span>Receita ganha</span><strong class="metric-money"><?= View::e($money($metrics['won_value'] ?? 0)) ?></strong><small>valor dos ganhos</small></article>
</div>

<form class="filter-bar" method="get" action="<?= View::e(Router::url('/crm')) ?>">
    <?php if (Auth::isSuperAdmin()): ?>
        <select name="tenant_id" aria-label="Empresa" onchange="this.form.submit()"><option value="">Selecione a empresa</option><?php foreach ($tenants as $tenant): ?><option value="<?= (int) $tenant['id'] ?>" <?= (int) ($filters['tenant_id'] ?? 0) === (int) $tenant['id'] ? 'selected' : '' ?>><?= View::e($tenant['name']) ?></option><?php endforeach; ?></select>
    <?php endif; ?>
    <select name="pipeline_id" aria-label="Funil" onchange="this.form.submit()"><?php foreach ($pipelines as $pipeline): ?><option value="<?= (int) $pipeline['id'] ?>" <?= (int) ($filters['pipeline_id'] ?? 0) === (int) $pipeline['id'] ? 'selected' : '' ?>><?= View::e($pipeline['name']) ?></option><?php endforeach; ?></select>
    <label class="filter-search"><span class="search-icon" aria-hidden="true"></span><input name="search" value="<?= View::e($filters['search'] ?? '') ?>" placeholder="Buscar negócio ou contato"></label>
    <select name="owner_id" aria-label="Responsável"><option value="">Todos os responsáveis</option><?php foreach ($team as $member): ?><option value="<?= (int) $member['id'] ?>" <?= (int) ($filters['owner_id'] ?? 0) === (int) $member['id'] ? 'selected' : '' ?>><?= View::e($member['name']) ?></option><?php endforeach; ?></select>
    <button class="btn btn-secondary" type="submit">Filtrar</button>
</form>

<?php if (($filters['tenant_id'] ?? 0) < 1): ?>
    <div class="card empty-state">Selecione uma empresa para visualizar o CRM.</div>
<?php elseif (!$stages): ?>
    <div class="card empty-state">Nenhum funil foi encontrado. Execute a migration 004 para criar o funil padrão.</div>
<?php else: ?>
<?php if ($canManage): ?>
<div class="crm-drag-hint" role="status" data-crm-status>
    <strong>Movimentação rápida:</strong> arraste o negócio para outra etapa. A atualização acontece sem recarregar a página.
</div>
<?php endif; ?>
<div class="crm-shell<?= $selected ? ' has-detail' : '' ?>">
    <section class="kanban-scroll" aria-label="Funil de vendas">
        <div class="kanban-board" data-crm-board data-crm-kind="client"
                 data-move-url="<?= View::e(Router::url('/crm/leads/move')) ?>"
                 data-csrf="<?= View::e(Csrf::token()) ?>"
                 data-tenant-id="<?= (int) $filters['tenant_id'] ?>">
            <?php foreach ($stages as $stage): ?>
                <?php $stageLeads = $leadsByStage[(int) $stage['id']] ?? []; $stageValue = array_sum(array_map(static fn ($item) => (float) $item['value'], $stageLeads)); ?>
                <section class="kanban-column stage-<?= View::e($stage['color_key']) ?>" data-crm-stage data-stage-id="<?= (int) $stage['id'] ?>">
                    <header class="kanban-header">
                        <div><span class="stage-dot"></span><strong><?= View::e($stage['name']) ?></strong><span class="stage-count" data-stage-count><?= count($stageLeads) ?></span></div>
                        <small><?= View::e($money($stageValue)) ?></small>
                    </header>
                    <div class="kanban-cards" data-crm-dropzone>
                        <?php foreach ($stageLeads as $lead): ?>
                            <?php $leadUrl = '/crm?' . http_build_query(array_filter(['tenant_id' => (int) $filters['tenant_id'], 'pipeline_id' => (int) $filters['pipeline_id'], 'search' => $filters['search'] ?? '', 'owner_id' => (int) ($filters['owner_id'] ?? 0), 'lead_id' => (int) $lead['id']], static fn ($v) => $v !== '' && $v !== 0)); ?>
                            <article class="deal-card<?= $selected && (int) $selected['id'] === (int) $lead['id'] ? ' is-selected' : '' ?>"
                                     draggable="<?= $canManage ? 'true' : 'false' ?>" data-crm-card
                                     data-item-id="<?= (int) $lead['id'] ?>"
                                     data-current-stage="<?= (int) $lead['stage_id'] ?>">
                                <a class="deal-main" href="<?= View::e(Router::url($leadUrl)) ?>">
                                    <span class="priority-marker priority-<?= View::e($lead['priority']) ?>"></span>
                                    <strong><?= View::e($lead['title']) ?></strong>
                                    <span class="deal-contact"><?= View::e($lead['contact_name'] ?: $lead['phone']) ?></span>
                                    <span class="deal-value"><?= View::e($money($lead['value'])) ?></span>
                                    <footer><span><?= View::e($lead['owner_name'] ?: 'Sem responsável') ?></span><?php if ((int) $lead['pending_tasks'] > 0): ?><span class="pending-tasks"><?= (int) $lead['pending_tasks'] ?> tarefa(s)</span><?php endif; ?></footer>
                                </a>
                                <?php if ($canManage): ?>
                                    <form class="deal-move" method="post" action="<?= View::e(Router::url('/crm/leads/move')) ?>" data-crm-fallback-move>
                                        <?= Csrf::input() ?><input type="hidden" name="tenant_id" value="<?= (int) $filters['tenant_id'] ?>"><input type="hidden" name="lead_id" value="<?= (int) $lead['id'] ?>">
                                        <select name="stage_id" aria-label="Mover negócio" data-crm-stage-select>
                                            <?php foreach ($stages as $target): ?><option value="<?= (int) $target['id'] ?>" <?= (int) $lead['stage_id'] === (int) $target['id'] ? 'selected' : '' ?>><?= View::e($target['name']) ?></option><?php endforeach; ?>
                                        </select>
                                    </form>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                        <div class="kanban-empty" data-crm-empty <?= $stageLeads ? 'hidden' : '' ?>>Sem negócios nesta etapa</div>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>
    </section>

    <?php if ($selected): ?>
    <aside class="card crm-detail">
        <div class="detail-header">
            <div><span class="eyebrow">Negócio</span><h2><?= View::e($selected['title']) ?></h2><small><?= View::e($selected['contact_name'] ?: $selected['phone']) ?> · <?= View::e($selected['stage_name']) ?></small></div>
            <a class="icon-close" href="<?= View::e(Router::url('/crm?' . http_build_query(['tenant_id' => (int) $filters['tenant_id'], 'pipeline_id' => (int) $filters['pipeline_id']]))) ?>" aria-label="Fechar">×</a>
        </div>

        <div class="detail-tabs" data-tabs>
            <button type="button" class="is-active" data-tab-target="overview">Resumo</button>
            <button type="button" data-tab-target="notes">Notas <span><?= count($notes) ?></span></button>
            <button type="button" data-tab-target="tasks">Atividades <span><?= count($selectedTasks) ?></span></button>
        </div>

        <section data-tab-panel="overview">
            <form class="form-stack" method="post" action="<?= View::e(Router::url('/crm/leads/update')) ?>">
                <?= Csrf::input() ?><input type="hidden" name="tenant_id" value="<?= (int) $filters['tenant_id'] ?>"><input type="hidden" name="lead_id" value="<?= (int) $selected['id'] ?>">
                <label class="field"><span>Título</span><input name="title" value="<?= View::e($selected['title']) ?>" <?= !$canManage ? 'readonly' : '' ?>></label>
                <div class="form-grid two"><label class="field"><span>Valor</span><input name="value" value="<?= View::e(number_format((float) $selected['value'], 2, ',', '.')) ?>" <?= !$canManage ? 'readonly' : '' ?>></label><label class="field"><span>Prioridade</span><select name="priority" <?= !$canManage ? 'disabled' : '' ?>><?php foreach ($priorityLabels as $value => $label): ?><option value="<?= View::e($value) ?>" <?= $selected['priority'] === $value ? 'selected' : '' ?>><?= View::e($label) ?></option><?php endforeach; ?></select></label></div>
                <label class="field"><span>Responsável</span><select name="owner_user_id" <?= !$canManage ? 'disabled' : '' ?>><option value="">Sem responsável</option><?php foreach ($team as $member): ?><option value="<?= (int) $member['id'] ?>" <?= (int) $selected['owner_user_id'] === (int) $member['id'] ? 'selected' : '' ?>><?= View::e($member['name']) ?></option><?php endforeach; ?></select></label>
                <label class="field"><span>Previsão de fechamento</span><input type="date" name="expected_close_at" value="<?= View::e($selected['expected_close_at']) ?>" <?= !$canManage ? 'readonly' : '' ?>></label>
                <?php if ($selected['status'] === 'lost'): ?><label class="field"><span>Motivo da perda</span><textarea name="lost_reason" rows="3" <?= !$canManage ? 'readonly' : '' ?>><?= View::e($selected['lost_reason']) ?></textarea></label><?php else: ?><input type="hidden" name="lost_reason" value="<?= View::e($selected['lost_reason']) ?>"><?php endif; ?>
                <div class="info-grid"><span><small>Status</small><strong><?= View::e($selected['status'] === 'won' ? 'Ganho' : ($selected['status'] === 'lost' ? 'Perdido' : 'Em aberto')) ?></strong></span><span><small>Contato</small><strong><?= View::e($selected['phone']) ?></strong></span><span><small>Criado em</small><strong><?= View::e($date($selected['created_at'], 'd/m/Y H:i')) ?></strong></span><span><small>Funil</small><strong><?= View::e($selected['pipeline_name']) ?></strong></span></div>
                <?php if ($canManage): ?><button class="btn btn-primary" type="submit">Salvar negócio</button><?php endif; ?>
            </form>
        </section>

        <section data-tab-panel="notes" hidden>
            <?php if ($canManage): ?><form class="note-composer" method="post" action="<?= View::e(Router::url('/crm/notes')) ?>"><?= Csrf::input() ?><input type="hidden" name="tenant_id" value="<?= (int) $filters['tenant_id'] ?>"><input type="hidden" name="lead_id" value="<?= (int) $selected['id'] ?>"><textarea name="note" rows="3" placeholder="Registre uma observação importante..." required></textarea><button class="btn btn-primary btn-small" type="submit">Adicionar nota</button></form><?php endif; ?>
            <div class="timeline-list"><?php foreach ($notes as $note): ?><article><span class="timeline-dot"></span><div><strong><?= View::e($note['user_name'] ?: 'Sistema') ?></strong><time><?= View::e($date($note['created_at'], 'd/m/Y H:i')) ?></time><p><?= nl2br(View::e($note['note'])) ?></p></div></article><?php endforeach; ?><?php if (!$notes): ?><div class="empty-state">Nenhuma nota registrada.</div><?php endif; ?></div>
        </section>

        <section data-tab-panel="tasks" hidden>
            <?php if ($canManageTasks): ?>
                <form class="form-stack compact-form" method="post" action="<?= View::e(Router::url('/tasks')) ?>">
                    <?= Csrf::input() ?><input type="hidden" name="tenant_id" value="<?= (int) $filters['tenant_id'] ?>"><input type="hidden" name="lead_id" value="<?= (int) $selected['id'] ?>"><input type="hidden" name="return_to" value="<?= View::e($currentUrl) ?>">
                    <div class="form-grid two"><label class="field"><span>Atividade</span><select name="task_type"><option value="follow_up">Retorno</option><option value="call">Ligação</option><option value="meeting">Reunião</option><option value="task">Tarefa</option></select></label><label class="field"><span>Prazo</span><input type="datetime-local" name="due_at"></label></div>
                    <label class="field"><span>Título</span><input name="title" placeholder="Próximo passo" required></label>
                    <div class="form-grid two"><label class="field"><span>Responsável</span><select name="assigned_user_id"><option value="">Sem responsável</option><?php foreach ($team as $member): ?><option value="<?= (int) $member['id'] ?>"><?= View::e($member['name']) ?></option><?php endforeach; ?></select></label><label class="field"><span>Prioridade</span><select name="priority"><option value="low">Baixa</option><option value="medium" selected>Média</option><option value="high">Alta</option></select></label></div>
                    <button class="btn btn-primary btn-small" type="submit">Criar atividade</button>
                </form>
            <?php endif; ?>
            <div class="task-mini-list">
                <?php foreach ($selectedTasks as $task): ?><article class="task-mini <?= $task['status'] === 'completed' ? 'is-completed' : '' ?>"><div><span class="activity-icon activity-<?= View::e($task['task_type']) ?>" aria-hidden="true"></span><span><strong><?= View::e($task['title']) ?></strong><small><?= View::e($typeLabels[$task['task_type']] ?? $task['task_type']) ?> · <?= View::e($task['assigned_name'] ?: 'Sem responsável') ?> · <?= View::e($date($task['due_at'], 'd/m H:i')) ?></small></span></div><?php if ($canManageTasks && $task['status'] === 'pending'): ?><form method="post" action="<?= View::e(Router::url('/tasks/status')) ?>"><?= Csrf::input() ?><input type="hidden" name="tenant_id" value="<?= (int) $filters['tenant_id'] ?>"><input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>"><input type="hidden" name="status" value="completed"><input type="hidden" name="return_to" value="<?= View::e($currentUrl) ?>"><button class="btn-icon-check" type="submit" title="Concluir"><span class="checkmark-icon" aria-hidden="true"></span></button></form><?php endif; ?></article><?php endforeach; ?>
                <?php if (!$selectedTasks): ?><div class="empty-state">Nenhuma atividade vinculada.</div><?php endif; ?>
            </div>
        </section>
    </aside>
    <?php endif; ?>
</div>
<?php endif; ?>
