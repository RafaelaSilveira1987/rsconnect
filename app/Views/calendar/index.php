<?php

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;

$statusLabels = [
    'pre_scheduled' => 'Pré-agendado',
    'awaiting_approval' => 'Aguardando aprovação',
    'scheduled' => 'Agendado',
    'confirmed' => 'Confirmado',
    'completed' => 'Concluído',
    'cancelled' => 'Cancelado',
    'rejected' => 'Recusado',
    'rescheduled' => 'Remarcado',
    'no_show' => 'Não compareceu',
];
$locationLabels = ['indefinida' => 'A definir', 'online' => 'Online', 'presencial' => 'Presencial', 'telefone' => 'Telefone'];
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
    'view' => $filters['view'] ?? 'list',
    'calendar_date' => $filters['calendar_date'] ?? '',
], static fn ($value) => $value !== '' && $value !== 0));
$professionalCalendarSettings = $professionalCalendarSettings ?? ['enabled' => false, 'require_owner' => true, 'auto_from_conversation' => false];
$professionalCalendarEnabled = !empty($professionalCalendarSettings['enabled']);
$professionalOwnerRequired = $professionalCalendarEnabled && !empty($professionalCalendarSettings['require_owner']);
$calendarView = in_array((string) ($filters['view'] ?? 'list'), ['list', 'day', 'week', 'month'], true)
    ? (string) $filters['view']
    : 'list';
try {
    $calendarDate = new DateTimeImmutable((string) ($filters['calendar_date'] ?? date('Y-m-d')));
} catch (Throwable) {
    $calendarDate = new DateTimeImmutable('today');
}
$calendarQueryBase = array_filter([
    'tenant_id' => (int) ($filters['tenant_id'] ?? 0),
    'status' => $filters['status'] ?? '',
    'owner_user_id' => (int) ($filters['owner_user_id'] ?? 0),
], static fn ($value) => $value !== '' && $value !== 0);
$calendarViewUrl = static function (string $view, DateTimeImmutable $anchor) use ($calendarQueryBase, $filters): string {
    $query = $calendarQueryBase;
    $query['view'] = $view;
    if ($view === 'list') {
        $query['date_from'] = (string) ($filters['date_from'] ?? '');
        $query['date_to'] = (string) ($filters['date_to'] ?? '');
    } else {
        $query['calendar_date'] = $anchor->format('Y-m-d');
    }
    return Router::url('/calendar?' . http_build_query(array_filter($query, static fn ($value) => $value !== '' && $value !== 0)));
};
$calendarClearQuery = array_filter([
    'tenant_id' => (int) ($filters['tenant_id'] ?? 0),
    'view' => $calendarView,
    'calendar_date' => $calendarView === 'list' ? '' : $calendarDate->format('Y-m-d'),
], static fn ($value) => $value !== '' && $value !== 0);
$calendarClearUrl = Router::url('/calendar?' . http_build_query($calendarClearQuery));
$calendarPreviousDate = match ($calendarView) {
    'day' => $calendarDate->modify('-1 day'),
    'week' => $calendarDate->modify('-7 days'),
    'month' => $calendarDate->modify('-1 month'),
    default => $calendarDate,
};
$calendarNextDate = match ($calendarView) {
    'day' => $calendarDate->modify('+1 day'),
    'week' => $calendarDate->modify('+7 days'),
    'month' => $calendarDate->modify('+1 month'),
    default => $calendarDate,
};
$monthNames = [1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril', 5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto', 9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'];
$shortMonthNames = [1 => 'jan', 2 => 'fev', 3 => 'mar', 4 => 'abr', 5 => 'mai', 6 => 'jun', 7 => 'jul', 8 => 'ago', 9 => 'set', 10 => 'out', 11 => 'nov', 12 => 'dez'];
$weekdayNames = [1 => 'segunda-feira', 2 => 'terça-feira', 3 => 'quarta-feira', 4 => 'quinta-feira', 5 => 'sexta-feira', 6 => 'sábado', 7 => 'domingo'];
$calendarPeriodLabel = '';
if ($calendarView === 'day') {
    $calendarPeriodLabel = ucfirst($weekdayNames[(int) $calendarDate->format('N')]) . ', ' . $calendarDate->format('d') . ' de ' . strtolower($monthNames[(int) $calendarDate->format('n')]) . ' de ' . $calendarDate->format('Y');
} elseif ($calendarView === 'week') {
    $periodStart = new DateTimeImmutable((string) ($filters['date_from'] ?? $calendarDate->format('Y-m-d')));
    $periodEnd = new DateTimeImmutable((string) ($filters['date_to'] ?? $calendarDate->format('Y-m-d')));
    $calendarPeriodLabel = $periodStart->format('d') . ' ' . $shortMonthNames[(int) $periodStart->format('n')] . ' – ' . $periodEnd->format('d') . ' ' . $shortMonthNames[(int) $periodEnd->format('n')] . ' ' . $periodEnd->format('Y');
} elseif ($calendarView === 'month') {
    $calendarPeriodLabel = $monthNames[(int) $calendarDate->format('n')] . ' de ' . $calendarDate->format('Y');
}
$calendarEvents = array_map(static function (array $appointment) use ($statusLabels, $locationLabels, $googleLink, $calendarQueryBase): array {
    $listQuery = $calendarQueryBase;
    $listQuery['view'] = 'list';
    $listQuery['date_from'] = substr((string) ($appointment['starts_at'] ?? ''), 0, 10);
    $listQuery['date_to'] = substr((string) ($appointment['starts_at'] ?? ''), 0, 10);
    return [
        'id' => (int) ($appointment['id'] ?? 0),
        'title' => (string) ($appointment['title'] ?? 'Agendamento'),
        'description' => (string) ($appointment['description'] ?? ''),
        'status' => (string) ($appointment['status'] ?? 'scheduled'),
        'status_label' => (string) ($statusLabels[$appointment['status'] ?? ''] ?? ($appointment['status'] ?? 'Agendado')),
        'location_label' => (string) ($locationLabels[$appointment['location_type'] ?? ''] ?? ($appointment['location_type'] ?? 'A definir')),
        'contact_name' => (string) (($appointment['contact_name'] ?? '') ?: (($appointment['phone'] ?? '') ?: 'Sem contato')),
        'owner_name' => (string) (($appointment['owner_name'] ?? '') ?: 'Não definido'),
        'starts_at' => str_replace(' ', 'T', (string) ($appointment['starts_at'] ?? '')),
        'ends_at' => str_replace(' ', 'T', (string) ($appointment['ends_at'] ?? '')),
        'google_url' => $googleLink($appointment),
        'list_url' => Router::url('/calendar?' . http_build_query($listQuery)) . '#appointment-' . (int) ($appointment['id'] ?? 0),
    ];
}, $appointments);
?>


<nav class="agenda-unified-tabs" aria-label="Áreas da agenda">
    <a class="agenda-unified-tab is-active" href="<?= View::e(Router::url('/calendar' . (($filters['tenant_id'] ?? 0) > 0 ? '?tenant_id=' . (int) $filters['tenant_id'] : ''))) ?>">
        <span class="agenda-tab-icon" aria-hidden="true">1</span>
        <span><strong>Compromissos</strong><small>Agendamentos e pré-agendamentos</small></span>
    </a>
    <a class="agenda-unified-tab" href="<?= View::e(Router::url('/calendar?section=availability' . (($filters['tenant_id'] ?? 0) > 0 ? '&tenant_id=' . (int) $filters['tenant_id'] : ''))) ?>">
        <span class="agenda-tab-icon" aria-hidden="true">2</span>
        <span><strong>Disponibilidade</strong><small>Dias, horários e regras</small></span>
    </a>
</nav>

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
                <label class="switch-inline"><input type="checkbox" name="is_pre_schedule" value="1"><span>Criar como pré-agendamento para aprovação humana</span></label>
                <div class="form-grid two">
                    <label class="field"><span>Dia preferido informado</span><input name="preferred_day_text" maxlength="120" placeholder="Ex.: terça-feira"></label>
                    <label class="field"><span>Horário/período informado</span><input name="preferred_time_text" maxlength="120" placeholder="Ex.: tarde ou 14:00"></label>
                </div>
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
                    <label class="field"><span><?= $professionalCalendarEnabled ? 'Profissional' : 'Responsável' ?><?= $professionalOwnerRequired ? ' *' : '' ?></span><select name="owner_user_id" <?= $professionalOwnerRequired ? 'required' : '' ?>><option value=""><?= $professionalOwnerRequired ? 'Selecione o profissional' : 'Sem responsável' ?></option><?php foreach ($team as $member): ?><option value="<?= (int) $member['id'] ?>"><?= View::e($member['name']) ?></option><?php endforeach; ?></select></label>
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
    <article class="metric-card metric-card-link"><span>Pré-agendamentos</span><strong><?= (int) ($metrics['pre_schedule_pending'] ?? 0) ?></strong><small>aguardando aprovação</small></article>
    <article class="metric-card"><span>Sincronização</span><strong><?= (int) ($metrics['pending_sync'] ?? 0) ?></strong><small>pendentes ou falhas</small></article>
    <article class="metric-card"><span>Concluídos em 30 dias</span><strong><?= (int) ($metrics['completed_count'] ?? 0) ?></strong><small>histórico recente</small></article>
</div>

<section class="calendar-view-toolbar" data-calendar-preference-key="rs_calendar_view_<?= (int) (Auth::id() ?? 0) ?>" aria-label="Visualização da agenda">
    <div class="calendar-view-switch" role="group" aria-label="Escolha a visualização">
        <?php foreach (['list' => 'Lista', 'day' => 'Dia', 'week' => 'Semana', 'month' => 'Mês'] as $viewValue => $viewLabel): ?>
            <a class="calendar-view-button <?= $calendarView === $viewValue ? 'is-active' : '' ?>"
               data-calendar-view-link="<?= View::e($viewValue) ?>"
               href="<?= View::e($calendarViewUrl($viewValue, $calendarDate)) ?>"
               aria-current="<?= $calendarView === $viewValue ? 'page' : 'false' ?>"><?= View::e($viewLabel) ?></a>
        <?php endforeach; ?>
    </div>
    <?php if ($calendarView !== 'list'): ?>
        <div class="calendar-period-navigation">
            <a class="btn btn-small btn-quiet" href="<?= View::e($calendarViewUrl($calendarView, $calendarPreviousDate)) ?>" aria-label="Período anterior">‹</a>
            <a class="btn btn-small btn-secondary" href="<?= View::e($calendarViewUrl($calendarView, new DateTimeImmutable('today'))) ?>">Hoje</a>
            <a class="btn btn-small btn-quiet" href="<?= View::e($calendarViewUrl($calendarView, $calendarNextDate)) ?>" aria-label="Próximo período">›</a>
            <strong><?= View::e($calendarPeriodLabel) ?></strong>
        </div>
    <?php endif; ?>
</section>

<form class="filter-bar calendar-filter-bar" method="get" action="<?= View::e(Router::url('/calendar')) ?>">
    <input type="hidden" name="view" value="<?= View::e($calendarView) ?>">
    <?php if ($calendarView !== 'list'): ?><input type="hidden" name="calendar_date" value="<?= View::e($calendarDate->format('Y-m-d')) ?>"><?php endif; ?>
    <?php if (Auth::isSuperAdmin()): ?>
        <select name="tenant_id" data-auto-submit><option value="">Selecione a empresa</option><?php foreach ($tenants as $tenant): ?><option value="<?= (int) $tenant['id'] ?>" <?= (int) ($filters['tenant_id'] ?? 0) === (int) $tenant['id'] ? 'selected' : '' ?>><?= View::e($tenant['name']) ?></option><?php endforeach; ?></select>
    <?php endif; ?>
    <select name="status"><option value="">Todos os status</option><?php foreach ($statusLabels as $value => $label): ?><option value="<?= View::e($value) ?>" <?= ($filters['status'] ?? '') === $value ? 'selected' : '' ?>><?= View::e($label) ?></option><?php endforeach; ?></select>
    <select name="owner_user_id"><option value="">Toda a equipe</option><?php foreach ($team as $member): ?><option value="<?= (int) $member['id'] ?>" <?= (int) ($filters['owner_user_id'] ?? 0) === (int) $member['id'] ? 'selected' : '' ?>><?= View::e($member['name']) ?></option><?php endforeach; ?></select>
    <?php if ($calendarView === 'list'): ?>
        <input type="date" name="date_from" value="<?= View::e($filters['date_from'] ?? '') ?>" aria-label="Data inicial">
        <input type="date" name="date_to" value="<?= View::e($filters['date_to'] ?? '') ?>" aria-label="Data final">
    <?php endif; ?>
    <button class="btn btn-secondary" type="submit">Filtrar</button>
    <a class="btn btn-quiet" href="<?= View::e($calendarClearUrl) ?>">Limpar</a>
</form>

<?php if ($calendarView === 'list'): ?>
<section class="card calendar-list-card">
    <div class="section-heading compact"><div><span class="eyebrow">Compromissos</span><h2><?= count($appointments) ?> agendamentos</h2></div></div>
    <div class="task-list calendar-list">
        <?php foreach ($appointments as $appointment): ?>
            <?php
                $isPreSchedule = !empty($appointment['is_pre_schedule']);
                $hasPreSchedulePreference = trim((string) ($appointment['preferred_day_text'] ?? '')) !== '' && trim((string) ($appointment['preferred_time_text'] ?? '')) !== '';
            ?>
            <article id="appointment-<?= (int) $appointment['id'] ?>" class="task-row calendar-row calendar-status-<?= View::e($appointment['status']) ?>">
                <span class="activity-icon activity-<?= View::e($appointment['location_type']) ?>" aria-hidden="true"></span>
                <div class="task-main">
                    <div class="task-title-line"><strong><?= View::e($appointment['title']) ?></strong><span class="badge badge-<?= View::e($appointment['status']) ?>"><?= View::e($statusLabels[$appointment['status']] ?? $appointment['status']) ?></span><span class="priority-text"><?= View::e($locationLabels[$appointment['location_type']] ?? $appointment['location_type']) ?></span></div>
                    <p><?= View::e($appointment['description'] ?: 'Sem descrição') ?></p>
                    <?php if ($isPreSchedule): ?>
                        <div class="pre-schedule-note <?= $hasPreSchedulePreference ? 'ready' : 'pending' ?>">
                            <strong><?= $hasPreSchedulePreference ? 'Preferência recebida' : 'Aguardando preferência' ?></strong>
                            <span>Preferência: <?= View::e(($appointment['preferred_day_text'] ?? '') ?: 'dia não informado') ?> · <?= View::e(($appointment['preferred_time_text'] ?? '') ?: 'horário não informado') ?></span>
                        </div>
                        <?php if (!empty($appointment['availability_status'])): ?>
                            <div class="pre-schedule-note ready">
                                <strong>Disponibilidade</strong>
                                <span><?= View::e(match ($appointment['availability_status']) { 'requested' => 'consulta solicitada', 'sent' => 'enviada ao n8n', 'received' => 'horários recebidos', 'options_sent' => 'opções enviadas ao cliente', 'empty' => (($appointment['availability_source'] ?? '') === 'google_marked_slots' ? 'nenhum evento VAGO válido encontrado' : 'sem horários livres'), 'failed' => 'falha ao buscar', 'hold_requested' => 'pré-reserva solicitada ao Google', 'slot_selected' => 'horário validado/escolhido', default => $appointment['availability_status'] }) ?><?= !empty($appointment['availability_slot_count']) ? ' · ' . (int) $appointment['availability_slot_count'] . ' opção(ões)' : '' ?></span>
                                <?php if (($appointment['availability_source'] ?? '') === 'google_marked_slots'): ?>
                                    <span>Evento Google: <?= View::e(match ($appointment['google_event_state'] ?? '') { 'held' => 'pré-reservado', 'confirmed' => 'confirmado', 'created' => 'criado', 'updated' => 'atualizado', 'deleted' => 'removido', 'released' => 'liberado', 'hold_requested' => 'pré-reserva em processamento', 'confirm_requested' => 'confirmação em processamento', 'release_requested' => 'liberação em processamento', 'create_requested' => 'criação em processamento', 'update_requested' => 'atualização em processamento', 'delete_requested' => 'remoção em processamento', 'error' => 'falha na sincronização', default => ($appointment['google_event_state'] ?? 'não vinculado') }) ?><?= !empty($appointment['google_event_summary']) ? ' · ' . View::e($appointment['google_event_summary']) : '' ?></span>
                                <?php endif; ?>
                                <?php if (!empty($appointment['availability_error'])): ?><span class="text-danger"><?= View::e($appointment['availability_error']) ?></span><?php endif; ?>
                                <?php if (!empty($appointment['availability_slot_count'])): ?><span><a href="<?= View::e(Router::url('/calendar?section=availability&tenant_id=' . (int) $appointment['tenant_id'] . '#horarios-disponiveis')) ?>">Ver horários disponíveis</a></span><?php endif; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                    <small><?= View::e($appointment['contact_name'] ?: ($appointment['phone'] ?: 'Sem contato')) ?> · <?= View::e($appointment['lead_title'] ?: 'Sem negócio') ?> · <?= $professionalCalendarEnabled ? 'Profissional' : 'Responsável' ?>: <?= View::e($appointment['owner_name'] ?: 'não definido') ?></small>
                    <?php if ($professionalCalendarEnabled && $canManage && !in_array($appointment['status'], ['completed', 'cancelled', 'rejected'], true)): ?>
                        <form class="calendar-owner-inline" method="post" action="<?= View::e(Router::url('/calendar/owner')) ?>">
                            <?= Csrf::input() ?>
                            <input type="hidden" name="tenant_id" value="<?= (int) $filters['tenant_id'] ?>">
                            <input type="hidden" name="appointment_id" value="<?= (int) $appointment['id'] ?>">
                            <input type="hidden" name="return_to" value="<?= View::e($returnUrl) ?>">
                            <label><span>Profissional</span><select name="owner_user_id" <?= $professionalOwnerRequired ? 'required' : '' ?>>
                                <option value=""><?= $professionalOwnerRequired ? 'Selecione' : 'Sem profissional' ?></option>
                                <?php foreach ($team as $member): ?><option value="<?= (int) $member['id'] ?>" <?= (int) ($appointment['owner_user_id'] ?? 0) === (int) $member['id'] ? 'selected' : '' ?>><?= View::e($member['name']) ?></option><?php endforeach; ?>
                            </select></label>
                            <button class="btn btn-small btn-quiet" type="submit">Alterar</button>
                        </form>
                    <?php endif; ?>
                    <?php if (($appointment['meeting_url'] ?? '') !== ''): ?><small><a href="<?= View::e($appointment['meeting_url']) ?>" target="_blank" rel="noopener">Abrir link da reunião</a></small><?php endif; ?>
                    <?php if (($appointment['sync_status'] ?? '') === 'failed'): ?><small class="text-danger">Falha sync: <?= View::e($appointment['sync_error'] ?? 'erro não informado') ?></small><?php endif; ?>
                    <?php if (!empty($appointment['approval_message_sent_at'])): ?><small class="text-success">Confirmação enviada em <?= View::e($date($appointment['approval_message_sent_at'])) ?></small><?php endif; ?>
                    <?php if (!empty($appointment['approval_message_error'])): ?><small class="text-danger">Mensagem não enviada: <?= View::e($appointment['approval_message_error']) ?></small><?php endif; ?>
                </div>
                <div class="task-deadline">
                    <small>Quando</small>
                    <?php if ($isPreSchedule && !$hasPreSchedulePreference): ?>
                        <strong>Aguardando preferência</strong>
                        <small>não confirme ainda</small>
                    <?php else: ?>
                        <strong><?= View::e($date($appointment['starts_at'])) ?></strong>
                        <small>até <?= View::e($date($appointment['ends_at'], 'H:i')) ?></small>
                    <?php endif; ?>
                </div>
                <div class="task-actions calendar-actions">
                    <a class="btn btn-small btn-quiet" target="_blank" rel="noopener" href="<?= View::e($googleLink($appointment)) ?>">Google</a>
                    <?php if ($canManage): ?>
                        <?php if (!in_array($appointment['status'], ['completed', 'cancelled', 'rejected'], true)): ?>
                            <?php if (in_array($appointment['status'], ['pre_scheduled', 'awaiting_approval', 'rescheduled'], true)): ?>
                                <?php if ($isPreSchedule && $hasPreSchedulePreference): ?>
                                    <form method="post" action="<?= View::e(Router::url('/calendar/availability/request')) ?>"><?= Csrf::input() ?><input type="hidden" name="tenant_id" value="<?= (int) $filters['tenant_id'] ?>"><input type="hidden" name="appointment_id" value="<?= (int) $appointment['id'] ?>"><input type="hidden" name="return_to" value="<?= View::e($returnUrl) ?>"><button class="btn btn-small btn-secondary" type="submit">Buscar disponibilidade</button></form>
                                <?php endif; ?>
                                <?php if ($isPreSchedule && !$hasPreSchedulePreference): ?>
                                    <button class="btn btn-small btn-disabled" type="button" disabled title="Aguardando dia e horário/período informado pelo cliente">Aprovar</button>
                                <?php else: ?>
                                    <form method="post" action="<?= View::e(Router::url('/calendar/status')) ?>"><?= Csrf::input() ?><input type="hidden" name="tenant_id" value="<?= (int) $filters['tenant_id'] ?>"><input type="hidden" name="appointment_id" value="<?= (int) $appointment['id'] ?>"><input type="hidden" name="status" value="confirmed"><input type="hidden" name="return_to" value="<?= View::e($returnUrl) ?>"><button class="btn btn-small btn-primary" type="submit">Aprovar</button></form>
                                <?php endif; ?>
                                <form method="post" action="<?= View::e(Router::url('/calendar/status')) ?>"><?= Csrf::input() ?><input type="hidden" name="tenant_id" value="<?= (int) $filters['tenant_id'] ?>"><input type="hidden" name="appointment_id" value="<?= (int) $appointment['id'] ?>"><input type="hidden" name="status" value="rejected"><input type="hidden" name="return_to" value="<?= View::e($returnUrl) ?>"><button class="btn btn-small btn-quiet" type="submit">Recusar</button></form>
                                <form method="post" action="<?= View::e(Router::url('/calendar/status')) ?>"><?= Csrf::input() ?><input type="hidden" name="tenant_id" value="<?= (int) $filters['tenant_id'] ?>"><input type="hidden" name="appointment_id" value="<?= (int) $appointment['id'] ?>"><input type="hidden" name="status" value="rescheduled"><input type="hidden" name="return_to" value="<?= View::e($returnUrl) ?>"><button class="btn btn-small btn-secondary" type="submit">Remarcar</button></form>
                            <?php else: ?>
                                <form method="post" action="<?= View::e(Router::url('/calendar/status')) ?>"><?= Csrf::input() ?><input type="hidden" name="tenant_id" value="<?= (int) $filters['tenant_id'] ?>"><input type="hidden" name="appointment_id" value="<?= (int) $appointment['id'] ?>"><input type="hidden" name="status" value="confirmed"><input type="hidden" name="return_to" value="<?= View::e($returnUrl) ?>"><button class="btn btn-small btn-secondary" type="submit">Confirmar</button></form>
                                <form method="post" action="<?= View::e(Router::url('/calendar/status')) ?>"><?= Csrf::input() ?><input type="hidden" name="tenant_id" value="<?= (int) $filters['tenant_id'] ?>"><input type="hidden" name="appointment_id" value="<?= (int) $appointment['id'] ?>"><input type="hidden" name="status" value="completed"><input type="hidden" name="return_to" value="<?= View::e($returnUrl) ?>"><button class="btn btn-small btn-primary" type="submit">Concluir</button></form>
                                <form method="post" action="<?= View::e(Router::url('/calendar/status')) ?>"><?= Csrf::input() ?><input type="hidden" name="tenant_id" value="<?= (int) $filters['tenant_id'] ?>"><input type="hidden" name="appointment_id" value="<?= (int) $appointment['id'] ?>"><input type="hidden" name="status" value="cancelled"><input type="hidden" name="return_to" value="<?= View::e($returnUrl) ?>"><button class="btn btn-small btn-quiet" type="submit">Cancelar</button></form>
                            <?php endif; ?>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if ($canManage): ?>
                        <form class="calendar-delete-form" method="post" action="<?= View::e(Router::url('/calendar/delete')) ?>" data-confirm="Excluir este agendamento do RS Connect? Nenhuma mensagem será enviada ao contato.">
                            <?= Csrf::input() ?>
                            <input type="hidden" name="tenant_id" value="<?= (int) $filters['tenant_id'] ?>">
                            <input type="hidden" name="appointment_id" value="<?= (int) $appointment['id'] ?>">
                            <input type="hidden" name="return_to" value="<?= View::e($returnUrl) ?>">
                            <button class="btn btn-small btn-calendar-delete" type="submit">Excluir</button>
                        </form>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
        <?php if (!$appointments): ?><div class="empty-state">Nenhum agendamento encontrado para o período selecionado.</div><?php endif; ?>
    </div>
</section>
<?php else: ?>
<section class="card calendar-visual-card" data-calendar-board data-calendar-view="<?= View::e($calendarView) ?>" data-calendar-anchor="<?= View::e($calendarDate->format('Y-m-d')) ?>" data-calendar-range-start="<?= View::e((string) ($filters['date_from'] ?? '')) ?>" data-calendar-range-end="<?= View::e((string) ($filters['date_to'] ?? '')) ?>">
    <div class="section-heading compact">
        <div><span class="eyebrow">Calendário</span><h2><?= count($appointments) ?> compromissos no período</h2><p>Selecione um compromisso para ver os detalhes. Alterações continuam protegidas pela visualização em lista.</p></div>
    </div>
    <script type="application/json" data-calendar-events><?= json_encode($calendarEvents, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
    <div class="calendar-visual-loading" data-calendar-loading>Montando calendário...</div>
    <div class="calendar-visual-content" data-calendar-content hidden></div>
</section>

<dialog class="calendar-event-dialog" data-calendar-event-dialog>
    <form method="dialog" class="calendar-event-dialog-shell">
        <button class="calendar-dialog-close" value="cancel" aria-label="Fechar">×</button>
        <span class="eyebrow" data-calendar-dialog-status>Agendamento</span>
        <h2 data-calendar-dialog-title>Compromisso</h2>
        <div class="calendar-dialog-meta">
            <div><span>Quando</span><strong data-calendar-dialog-time>—</strong></div>
            <div><span>Cliente</span><strong data-calendar-dialog-contact>—</strong></div>
            <div><span>Profissional</span><strong data-calendar-dialog-owner>—</strong></div>
            <div><span>Modalidade</span><strong data-calendar-dialog-location>—</strong></div>
        </div>
        <p data-calendar-dialog-description>Sem descrição.</p>
        <div class="calendar-dialog-actions">
            <a class="btn btn-primary" data-calendar-dialog-open href="#">Abrir na lista</a>
            <a class="btn btn-quiet" data-calendar-dialog-google href="#" target="_blank" rel="noopener">Adicionar ao Google</a>
            <button class="btn btn-secondary" value="cancel">Fechar</button>
        </div>
    </form>
</dialog>
<?php endif; ?>
