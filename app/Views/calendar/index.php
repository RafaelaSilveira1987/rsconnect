<?php

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;

$statusLabels = [
    'scheduled' => 'Agendado',
    'confirmed' => 'Confirmado',
    'completed' => 'Concluído',
    'cancelled' => 'Cancelado',
    'no_show' => 'Não compareceu',
];
$locationLabels = ['online' => 'Online', 'presencial' => 'Presencial', 'telefone' => 'Telefone'];
$date = static function (?string $value, string $format = 'd/m/Y H:i'): string {
    if (!$value) return '—';
    try { return (new DateTime($value))->format($format); } catch (Throwable) { return $value; }
};
$googleLink = static function (array $appointment): string {
    $start = gmdate('Ymd\THis\Z', strtotime((string) $appointment['starts_at']));
    $end = gmdate('Ymd\THis\Z', strtotime((string) $appointment['ends_at']));
    return 'https://calendar.google.com/calendar/render?action=TEMPLATE&' . http_build_query([
        'text' => $appointment['title'] ?? 'Agendamento RS Connect',
        'dates' => $start . '/' . $end,
        'details' => $appointment['description'] ?? '',
        'location' => ($appointment['meeting_url'] ?? '') ?: ($appointment['location'] ?? ''),
    ]);
};
$returnUrl = '/calendar?' . http_build_query(array_filter([
    'tenant_id' => (int) ($filters['tenant_id'] ?? 0),
    'status' => $filters['status'] ?? '',
    'owner_user_id' => (int) ($filters['owner_user_id'] ?? 0),
    'date_from' => $filters['date_from'] ?? '',
    'date_to' => $filters['date_to'] ?? '',
], static fn ($value) => $value !== '' && $value !== 0));
?>

<div class="page-heading">
    <div>
        <span class="eyebrow">Agenda comercial</span>
        <h2>Agenda e compromissos</h2>
        <p>Agende reuniões, ligações e retornos vinculados ao contato, conversa ou negócio do CRM.</p>
    </div>
    <?php if ($canManage && ($filters['tenant_id'] ?? 0) > 0): ?>
        <details class="action-popover">
            <summary class="btn btn-primary">Novo agendamento</summary>
            <form class="popover-panel form-stack wide" method="post" action="<?= View::e(Router::url('/calendar/appointments')) ?>">
                <?= Csrf::input() ?>
                <input type="hidden" name="tenant_id" value="<?= (int) $filters['tenant_id'] ?>">
                <strong>Criar agendamento</strong>
                <label class="field"><span>Título *</span><input name="title" maxlength="180" required placeholder="Ex.: Reunião de diagnóstico"></label>
                <div class="form-grid two">
                    <label class="field"><span>Início *</span><input type="datetime-local" name="starts_at" required></label>
                    <label class="field"><span>Fim *</span><input type="datetime-local" name="ends_at" required></label>
                </div>
                <div class="form-grid two">
                    <label class="field"><span>Contato</span><select name="contact_id"><option value="">Sem contato</option><?php foreach ($contacts as $contact): ?><option value="<?= (int) $contact['id'] ?>"><?= View::e($contact['name'] ?: $contact['phone']) ?></option><?php endforeach; ?></select></label>
                    <label class="field"><span>Negócio</span><select name="crm_lead_id"><option value="">Sem negócio</option><?php foreach ($leads as $lead): ?><option value="<?= (int) $lead['id'] ?>"><?= View::e($lead['title'] . ' · ' . ($lead['contact_name'] ?: $lead['phone'])) ?></option><?php endforeach; ?></select></label>
                </div>
                <div class="form-grid two">
                    <label class="field"><span>Conversa</span><select name="conversation_id"><option value="">Sem conversa</option><?php foreach ($conversations as $conversation): ?><option value="<?= (int) $conversation['id'] ?>"><?= View::e(($conversation['contact_name'] ?: $conversation['phone']) . ' · #' . $conversation['id']) ?></option><?php endforeach; ?></select></label>
                    <label class="field"><span>Responsável</span><select name="owner_user_id"><option value="">Sem responsável</option><?php foreach ($team as $member): ?><option value="<?= (int) $member['id'] ?>"><?= View::e($member['name']) ?></option><?php endforeach; ?></select></label>
                </div>
                <div class="form-grid two">
                    <label class="field"><span>Tipo/local</span><select name="location_type"><option value="online">Online</option><option value="presencial">Presencial</option><option value="telefone">Telefone</option></select></label>
                    <label class="field"><span>Lembrete</span><select name="reminder_minutes"><option value="15">15 min antes</option><option value="30">30 min antes</option><option value="60" selected>1h antes</option><option value="1440">1 dia antes</option></select></label>
                </div>
                <label class="field"><span>Link de reunião</span><input name="meeting_url" maxlength="500" placeholder="https://meet.google.com/..."></label>
                <label class="field"><span>Local / observação curta</span><input name="location" maxlength="255" placeholder="Endereço, telefone ou orientação"></label>
                <label class="field"><span>Descrição</span><textarea name="description" rows="3" placeholder="Contexto para a reunião ou próxima ação"></textarea></label>
                <input type="hidden" name="timezone" value="America/Sao_Paulo">
                <button class="btn btn-primary" type="submit">Salvar agendamento</button>
            </form>
        </details>
    <?php endif; ?>
</div>

<div class="metric-grid metric-grid-compact">
    <article class="metric-card"><span>Hoje</span><strong><?= (int) ($metrics['today_count'] ?? 0) ?></strong><small>compromissos do dia</small></article>
    <article class="metric-card"><span>Próximos</span><strong><?= (int) ($metrics['upcoming_count'] ?? 0) ?></strong><small>em aberto</small></article>
    <article class="metric-card"><span>Sincronização</span><strong><?= (int) ($metrics['pending_sync'] ?? 0) ?></strong><small>pendentes ou falhas</small></article>
    <article class="metric-card"><span>Concluídos em 30 dias</span><strong><?= (int) ($metrics['completed_count'] ?? 0) ?></strong><small>histórico recente</small></article>
</div>

<form class="filter-bar" method="get" action="<?= View::e(Router::url('/calendar')) ?>">
    <?php if (Auth::isSuperAdmin()): ?>
        <select name="tenant_id" onchange="this.form.submit()"><option value="">Selecione a empresa</option><?php foreach ($tenants as $tenant): ?><option value="<?= (int) $tenant['id'] ?>" <?= (int) ($filters['tenant_id'] ?? 0) === (int) $tenant['id'] ? 'selected' : '' ?>><?= View::e($tenant['name']) ?></option><?php endforeach; ?></select>
    <?php endif; ?>
    <select name="status"><option value="">Todos os status</option><?php foreach ($statusLabels as $value => $label): ?><option value="<?= View::e($value) ?>" <?= ($filters['status'] ?? '') === $value ? 'selected' : '' ?>><?= View::e($label) ?></option><?php endforeach; ?></select>
    <select name="owner_user_id"><option value="">Toda a equipe</option><?php foreach ($team as $member): ?><option value="<?= (int) $member['id'] ?>" <?= (int) ($filters['owner_user_id'] ?? 0) === (int) $member['id'] ? 'selected' : '' ?>><?= View::e($member['name']) ?></option><?php endforeach; ?></select>
    <input type="date" name="date_from" value="<?= View::e($filters['date_from'] ?? '') ?>">
    <input type="date" name="date_to" value="<?= View::e($filters['date_to'] ?? '') ?>">
    <button class="btn btn-secondary" type="submit">Filtrar</button>
    <a class="btn btn-quiet" href="<?= View::e(Router::url('/calendar')) ?>">Limpar</a>
</form>

<section class="card calendar-list-card">
    <div class="section-heading compact"><div><span class="eyebrow">Compromissos</span><h2><?= count($appointments) ?> agendamentos</h2></div></div>
    <div class="task-list calendar-list">
        <?php foreach ($appointments as $appointment): ?>
            <article class="task-row calendar-row calendar-status-<?= View::e($appointment['status']) ?>">
                <span class="activity-icon activity-<?= View::e($appointment['location_type']) ?>" aria-hidden="true"></span>
                <div class="task-main">
                    <div class="task-title-line"><strong><?= View::e($appointment['title']) ?></strong><span class="badge badge-<?= View::e($appointment['status']) ?>"><?= View::e($statusLabels[$appointment['status']] ?? $appointment['status']) ?></span><span class="priority-text"><?= View::e($locationLabels[$appointment['location_type']] ?? $appointment['location_type']) ?></span></div>
                    <p><?= View::e($appointment['description'] ?: 'Sem descrição') ?></p>
                    <small><?= View::e($appointment['contact_name'] ?: ($appointment['phone'] ?: 'Sem contato')) ?> · <?= View::e($appointment['lead_title'] ?: 'Sem negócio') ?> · Responsável: <?= View::e($appointment['owner_name'] ?: 'não definido') ?></small>
                    <?php if (($appointment['meeting_url'] ?? '') !== ''): ?><small><a href="<?= View::e($appointment['meeting_url']) ?>" target="_blank" rel="noopener">Abrir link da reunião</a></small><?php endif; ?>
                    <?php if (($appointment['sync_status'] ?? '') === 'failed'): ?><small class="text-danger">Falha sync: <?= View::e($appointment['sync_error'] ?? 'erro não informado') ?></small><?php endif; ?>
                </div>
                <div class="task-deadline"><small>Quando</small><strong><?= View::e($date($appointment['starts_at'])) ?></strong><small>até <?= View::e($date($appointment['ends_at'], 'H:i')) ?></small></div>
                <div class="task-actions calendar-actions">
                    <a class="btn btn-small btn-quiet" target="_blank" rel="noopener" href="<?= View::e($googleLink($appointment)) ?>">Google</a>
                    <a class="btn btn-small btn-quiet" href="<?= View::e(Router::url('/calendar/ics?id=' . (int) $appointment['id'] . '&tenant_id=' . (int) $appointment['tenant_id'])) ?>">.ics</a>
                    <?php if ($canManage): ?>
                        <?php if (!in_array($appointment['status'], ['completed', 'cancelled'], true)): ?>
                            <form method="post" action="<?= View::e(Router::url('/calendar/status')) ?>"><?= Csrf::input() ?><input type="hidden" name="tenant_id" value="<?= (int) $filters['tenant_id'] ?>"><input type="hidden" name="appointment_id" value="<?= (int) $appointment['id'] ?>"><input type="hidden" name="status" value="confirmed"><input type="hidden" name="return_to" value="<?= View::e($returnUrl) ?>"><button class="btn btn-small btn-secondary" type="submit">Confirmar</button></form>
                            <form method="post" action="<?= View::e(Router::url('/calendar/status')) ?>"><?= Csrf::input() ?><input type="hidden" name="tenant_id" value="<?= (int) $filters['tenant_id'] ?>"><input type="hidden" name="appointment_id" value="<?= (int) $appointment['id'] ?>"><input type="hidden" name="status" value="completed"><input type="hidden" name="return_to" value="<?= View::e($returnUrl) ?>"><button class="btn btn-small btn-primary" type="submit">Concluir</button></form>
                            <form method="post" action="<?= View::e(Router::url('/calendar/status')) ?>"><?= Csrf::input() ?><input type="hidden" name="tenant_id" value="<?= (int) $filters['tenant_id'] ?>"><input type="hidden" name="appointment_id" value="<?= (int) $appointment['id'] ?>"><input type="hidden" name="status" value="cancelled"><input type="hidden" name="return_to" value="<?= View::e($returnUrl) ?>"><button class="btn btn-small btn-quiet" type="submit">Cancelar</button></form>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
        <?php if (!$appointments): ?><div class="empty-state">Nenhum agendamento encontrado para o período selecionado.</div><?php endif; ?>
    </div>
</section>
