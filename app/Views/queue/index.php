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
        <div class="queue-hero-kicker"><span class="eyebrow">Operação de atendimento</span><span class="mini-badge is-soft">Uso opcional</span></div>
        <h2>Fila, equipe e distribuição</h2>
        <p>Use setores quando sua operação precisar separar responsabilidades. Empresas com fluxo simples podem continuar atendendo diretamente em Conversas, sem usar esta área.</p>
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

<form class="filter-bar queue-filter card<?= Auth::isSuperAdmin() ? ' is-admin' : '' ?>" method="get" action="<?= View::e(Router::url('/queue')) ?>">
    <?php if (Auth::isSuperAdmin()): ?>
        <label class="field compact-field"><span>Empresa</span>
            <select name="tenant_id" data-auto-submit>
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

    <div class="queue-filter-actions">
        <button class="btn btn-secondary" type="submit">Filtrar</button>
        <a class="btn btn-outline" href="<?= View::e(Router::url('/queue')) ?>">Limpar</a>
    </div>
</form>

<div class="queue-layout">
    <section class="queue-board card">
        <div class="section-heading clean-heading queue-board-heading">
            <div>
                <span class="eyebrow">Fila ativa</span>
                <h2>Conversas em operação</h2>
                <p>Abra a conversa para atender ou use Distribuir para definir setor, responsável e prioridade.</p>
            </div>
            <span class="badge"><?= count($conversations) ?> exibida(s)</span>
        </div>

        <div class="queue-table-wrap">
            <table class="queue-table">
                <colgroup>
                    <col class="queue-col-contact">
                    <col class="queue-col-status">
                    <col class="queue-col-department">
                    <col class="queue-col-owner">
                    <col class="queue-col-priority">
                    <col class="queue-col-time">
                    <col class="queue-col-actions">
                </colgroup>
                <thead>
                <tr>
                    <th>Contato</th>
                    <th>Status</th>
                    <th>Setor</th>
                    <th>Responsável</th>
                    <th>Prioridade</th>
                    <th>Última interação</th>
                    <th><span class="sr-only">Ações</span></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($conversations as $conversation): ?>
                    <?php
                    $rowTenantId = (int) $conversation['tenant_id'];
                    $rowDepartments = array_values(array_filter($departments, static fn (array $department): bool => (int) ($department['tenant_id'] ?? 0) === $rowTenantId && ($department['status'] ?? '') === 'active'));
                    $rowUsers = array_values(array_filter($users, static fn (array $member): bool => (int) ($member['tenant_id'] ?? 0) === $rowTenantId));
                    $departmentPayload = [];
                    $userDepartmentMap = [];
                    foreach ($rowDepartments as $department) {
                        $departmentId = (int) ($department['id'] ?? 0);
                        $members = is_array($departmentMembers[$departmentId] ?? null) ? $departmentMembers[$departmentId] : [];
                        $memberIds = array_map('intval', array_keys(array_filter($members)));
                        $departmentPayload[] = [
                            'id' => $departmentId,
                            'name' => (string) ($department['name'] ?? 'Setor'),
                            'has_team' => $memberIds !== [],
                            'member_ids' => $memberIds,
                        ];
                        foreach ($memberIds as $memberId) {
                            $userDepartmentMap[$memberId][] = $departmentId;
                        }
                    }
                    $userPayload = array_map(static function (array $member) use ($userDepartmentMap): array {
                        $memberId = (int) ($member['id'] ?? 0);
                        return [
                            'id' => $memberId,
                            'name' => (string) ($member['name'] ?? 'Usuário'),
                            'departments' => array_values(array_unique($userDepartmentMap[$memberId] ?? [])),
                        ];
                    }, $rowUsers);
                    $assignmentPayload = rawurlencode((string) json_encode([
                        'conversation_id' => (int) $conversation['id'],
                        'contact' => (string) ($conversation['contact_name'] ?: $conversation['phone']),
                        'phone' => (string) $conversation['phone'],
                        'tenant' => (string) ($conversation['tenant_name'] ?? ''),
                        'department_id' => (int) ($conversation['department_id'] ?? 0),
                        'assigned_user_id' => (int) ($conversation['assigned_user_id'] ?? 0),
                        'priority' => (string) ($conversation['priority'] ?? 'normal'),
                        'operational_status' => (string) ($conversation['operational_status'] ?? 'new'),
                        'departments' => $departmentPayload,
                        'users' => $userPayload,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    ?>
                    <tr class="queue-row priority-row-<?= View::e($conversation['priority'] ?? 'normal') ?>">
                        <td data-label="Contato">
                            <div class="queue-contact-cell">
                                <strong><?= View::e($conversation['contact_name'] ?: $conversation['phone']) ?></strong>
                                <small><?= View::e($conversation['phone']) ?><?= Auth::isSuperAdmin() ? ' · ' . View::e($conversation['tenant_name']) : '' ?></small>
                                <span class="queue-preview"><?= View::e($conversation['last_message_preview'] ?: 'Sem prévia') ?></span>
                            </div>
                        </td>
                        <td data-label="Status"><span class="mini-badge queue-status-<?= View::e($conversation['operational_status'] ?? 'new') ?>"><?= View::e($statusLabels[$conversation['operational_status'] ?? 'new'] ?? 'Novo') ?></span></td>
                        <td data-label="Setor"><?= $conversation['department_name'] ? '<span class="department-pill" style="--dept:' . View::e($conversation['department_color'] ?: '#146498') . '">' . View::e($conversation['department_name']) . '</span>' : '<span class="muted-text">Sem setor</span>' ?></td>
                        <td data-label="Responsável"><?= $conversation['assigned_user_name'] ? View::e($conversation['assigned_user_name']) : '<span class="muted-text">Sem responsável</span>' ?></td>
                        <td data-label="Prioridade"><span class="mini-badge priority-<?= View::e($conversation['priority'] ?? 'normal') ?>"><?= View::e($priorityLabels[$conversation['priority'] ?? 'normal'] ?? 'Normal') ?></span></td>
                        <td data-label="Última interação"><span class="queue-last-interaction"><?= View::e($formatDate($conversation['last_message_at'], 'd/m H:i')) ?><?php if ((int) $conversation['unread_count'] > 0): ?><b class="unread-count inline-unread"><?= (int) $conversation['unread_count'] ?></b><?php endif; ?></span></td>
                        <td class="queue-actions-cell" data-label="Ações">
                            <div class="queue-row-actions">
                                <a class="btn btn-outline btn-small" href="<?= View::e(Router::url('/conversations?conversation_id=' . (int) $conversation['id'])) ?>">Abrir</a>
                                <?php if (Auth::can('queue.manage')): ?>
                                    <button class="btn btn-primary-soft btn-small" type="button" data-toggle-panel="queue-distribution-drawer" data-queue-assign-open data-queue-assignment="<?= View::e($assignmentPayload) ?>">Distribuir</button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$conversations): ?>
                    <tr class="queue-empty-row"><td colspan="7"><div class="empty-state">Nenhuma conversa encontrada para os filtros selecionados.</div></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <aside class="queue-side card">
        <div class="section-heading clean-heading queue-side-heading">
            <div>
                <span class="eyebrow">Setores</span>
                <h2>Distribuição</h2>
                <p>Crie áreas e vincule somente quem pode atender cada fila.</p>
            </div>
            <span class="badge"><?= count($departments) ?></span>
        </div>

        <?php if (Auth::can('queue.manage')): ?>
            <details class="queue-side-section queue-new-department" <?= !$departments ? 'open' : '' ?>>
                <summary><span><strong>Novo setor</strong><small>Comercial, Recepção, Suporte...</small></span><span class="queue-summary-icon">+</span></summary>
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
                    <label class="field"><span>Nome do setor</span><input name="name" placeholder="Ex.: Recepção" required></label>
                    <label class="field"><span>Descrição</span><input name="description" placeholder="Ex.: Primeiro atendimento"></label>
                    <label class="field queue-color-field"><span>Identificação</span><input type="color" name="color" value="#146498"><small>Cor usada para identificar o setor.</small></label>
                    <button class="btn btn-primary btn-block" type="submit">Salvar setor</button>
                </form>
            </details>
        <?php endif; ?>

        <div class="department-list">
            <?php foreach ($departments as $department): ?>
                <?php
                $departmentId = (int) $department['id'];
                $departmentTenantId = (int) $department['tenant_id'];
                $eligibleUsers = array_values(array_filter($users, static fn (array $member): bool => (int) ($member['tenant_id'] ?? 0) === $departmentTenantId));
                $memberMap = is_array($departmentMembers[$departmentId] ?? null) ? $departmentMembers[$departmentId] : [];
                $memberNames = [];
                foreach ($eligibleUsers as $member) {
                    if (!empty($memberMap[(int) $member['id']])) {
                        $memberNames[] = (string) $member['name'];
                    }
                }
                ?>
                <article class="department-card<?= $department['status'] === 'inactive' ? ' is-inactive' : '' ?>" style="--dept: <?= View::e($department['color'] ?: '#146498') ?>">
                    <div class="department-card-head">
                        <div>
                            <strong><?= View::e($department['name']) ?></strong>
                            <small><?= View::e($department['tenant_name'] ?? '') ?><?= $department['status'] === 'inactive' ? ' · Inativo' : '' ?></small>
                        </div>
                        <?php if (Auth::can('queue.manage')): ?>
                            <form method="post" action="<?= View::e(Router::url('/queue/departments/status')) ?>">
                                <?= Csrf::input() ?>
                                <input type="hidden" name="department_id" value="<?= $departmentId ?>">
                                <input type="hidden" name="status" value="<?= $department['status'] === 'active' ? 'inactive' : 'active' ?>">
                                <button class="btn btn-quiet btn-small" type="submit"><?= $department['status'] === 'active' ? 'Inativar' : 'Ativar' ?></button>
                            </form>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($department['description'])): ?><p class="department-description"><?= View::e($department['description']) ?></p><?php endif; ?>
                    <div class="department-team-summary">
                        <span>Equipe vinculada</span>
                        <strong><?= $memberNames !== [] ? View::e(implode(', ', $memberNames)) : 'Nenhum usuário vinculado' ?></strong>
                    </div>
                    <?php if (Auth::can('queue.manage') && $department['status'] === 'active'): ?>
                        <details class="department-team-editor">
                            <summary><span>Gerenciar equipe</span><b><?= count($memberNames) ?></b></summary>
                            <form class="department-members-form" method="post" action="<?= View::e(Router::url('/queue/departments/members')) ?>">
                                <?= Csrf::input() ?>
                                <input type="hidden" name="department_id" value="<?= $departmentId ?>">
                                <div class="department-members-options">
                                    <?php foreach ($eligibleUsers as $member): ?>
                                        <label class="department-member-option">
                                            <input type="checkbox" name="member_ids[]" value="<?= (int) $member['id'] ?>" <?= !empty($memberMap[(int) $member['id']]) ? 'checked' : '' ?>>
                                            <span><strong><?= View::e($member['name']) ?></strong><small><?= View::e($member['role'] === 'client_admin' ? 'Administrador' : 'Membro da equipe') ?></small></span>
                                        </label>
                                    <?php endforeach; ?>
                                    <?php if ($eligibleUsers === []): ?><small class="muted-text">Nenhum usuário ativo nesta empresa.</small><?php endif; ?>
                                </div>
                                <button class="btn btn-outline btn-small btn-block" type="submit">Salvar equipe do setor</button>
                            </form>
                        </details>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
            <?php if (!$departments): ?><div class="queue-side-empty"><strong>Nenhum setor cadastrado</strong><span>Crie o primeiro setor somente se sua equipe precisar separar as conversas por área.</span></div><?php endif; ?>
        </div>
    </aside>
</div>

<?php if (Auth::can('queue.manage')): ?>
<aside class="conversation-details conversation-drawer queue-distribution-drawer" id="queue-distribution-drawer" aria-label="Distribuir conversa" aria-modal="true" role="dialog">
    <div class="conversation-drawer-header">
        <div>
            <span class="eyebrow">Distribuição</span>
            <h2>Organizar atendimento</h2>
            <p data-queue-drawer-contact>Selecione setor, responsável e prioridade.</p>
        </div>
        <button class="icon-button drawer-close" type="button" data-close-panel="queue-distribution-drawer" aria-label="Fechar painel">×</button>
    </div>
    <div class="conversation-drawer-body">
        <form method="post" action="<?= View::e(Router::url('/queue/assign')) ?>" class="drawer-form queue-distribution-form" data-queue-distribution-form>
            <?= Csrf::input() ?>
            <input type="hidden" name="conversation_id" value="" data-queue-field="conversation_id">

            <section class="drawer-section queue-distribution-summary">
                <div><span>Contato</span><strong data-queue-summary-contact>—</strong></div>
                <div><span>Telefone</span><strong data-queue-summary-phone>—</strong></div>
                <?php if (Auth::isSuperAdmin()): ?><div><span>Empresa</span><strong data-queue-summary-tenant>—</strong></div><?php endif; ?>
            </section>

            <section class="drawer-section">
                <div class="drawer-section-title"><div><span class="eyebrow">1. Destino</span><h3>Para onde vai a conversa?</h3><small>O setor é opcional. Sem setor, você pode definir um responsável diretamente.</small></div></div>
                <div class="drawer-form-grid queue-distribution-grid">
                    <label class="field"><span>Setor</span><select name="department_id" data-queue-field="department_id"><option value="">Sem setor</option></select></label>
                    <label class="field"><span>Responsável</span><select name="assigned_user_id" data-queue-field="assigned_user_id"><option value="">Sem responsável</option></select></label>
                </div>
                <small class="field-hint" data-queue-assignment-hint>Escolha um setor para limitar os responsáveis aos membros daquela equipe.</small>
            </section>

            <section class="drawer-section">
                <div class="drawer-section-title"><div><span class="eyebrow">2. Operação</span><h3>Prioridade e situação</h3></div></div>
                <div class="drawer-form-grid queue-distribution-grid">
                    <label class="field"><span>Prioridade</span><select name="priority" data-queue-field="priority">
                        <?php foreach ($priorityLabels as $priorityKey => $priorityLabel): ?><option value="<?= View::e($priorityKey) ?>"><?= View::e($priorityLabel) ?></option><?php endforeach; ?>
                    </select></label>
                    <label class="field"><span>Status</span><select name="operational_status" data-queue-field="operational_status">
                        <?php foreach ($statusLabels as $statusKey => $statusLabel): ?><option value="<?= View::e($statusKey) ?>"><?= View::e($statusLabel) ?></option><?php endforeach; ?>
                    </select></label>
                </div>
                <div class="message-info queue-distribution-rule"><strong>Regra de segurança</strong><span>Ao enviar para um setor sem responsável, a conversa fica aguardando a equipe e a IA é pausada. Um responsável selecionado precisa pertencer ao setor.</span></div>
            </section>

            <div class="drawer-savebar">
                <button class="btn btn-quiet" type="button" data-close-panel="queue-distribution-drawer">Cancelar</button>
                <button class="btn btn-primary" type="submit">Salvar distribuição</button>
            </div>
        </form>
    </div>
</aside>
<?php endif; ?>
