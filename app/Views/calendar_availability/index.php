<?php

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;

$settings = $settings ?? [];
$calendarSourceSettings = $calendarSourceSettings ?? ['source' => (!empty($settings['use_n8n']) ? 'google' : (!empty($settings['enabled']) ? 'internal' : 'none'))];
$calendarSource = in_array((string) ($calendarSourceSettings['source'] ?? ''), ['none', 'internal', 'google'], true)
    ? (string) $calendarSourceSettings['source']
    : 'none';
$pending = $pending ?? [];
$requests = $requests ?? [];
$slots = $slots ?? [];
$googleLogs = $googleLogs ?? [];
$metrics = $metrics ?? [];
$integration = $integration ?? [];
$maintenance = $maintenance ?? [];
$professionalCalendarSettings = $professionalCalendarSettings ?? ['enabled' => false, 'require_owner' => true, 'auto_from_conversation' => false];
$professionalProfiles = $professionalProfiles ?? [];
$calendarClientSettings = $calendarClientSettings ?? ['ready' => false, 'lookup_enabled' => 1, 'lookup_outside_hours' => 0, 'send_created_enabled' => 0, 'send_confirmed_enabled' => 1, 'send_cancelled_enabled' => 1, 'send_rescheduled_enabled' => 1, 'reminder_enabled' => 0, 'reminder_minutes' => 120, 'presence_request_enabled' => 0, 'presence_request_minutes' => 1440];
$leadTimeParts = static function (int $minutes, int $minimum = 1): array {
    $minutes = max($minimum, $minutes);
    if ($minutes % 1440 === 0) {
        return ['value' => max(1, intdiv($minutes, 1440)), 'unit' => 'days'];
    }
    if ($minutes % 60 === 0) {
        return ['value' => max(1, intdiv($minutes, 60)), 'unit' => 'hours'];
    }
    return ['value' => $minutes, 'unit' => 'minutes'];
};
$reminderLead = $leadTimeParts((int) ($calendarClientSettings['reminder_minutes'] ?? 120), 5);
$presenceLead = $leadTimeParts((int) ($calendarClientSettings['presence_request_minutes'] ?? 1440), 15);
$publishedSlots = $publishedSlots ?? [];
$internalStrategy = (($settings['internal_availability_strategy'] ?? 'calculated') === 'published') ? 'published' : 'calculated';
$activeTab = in_array((string) ($activeTab ?? 'overview'), ['overview', 'availability', 'preschedules', 'settings'], true)
    ? (string) ($activeTab ?? 'overview')
    : 'overview';
$tabUrl = static function (string $tab) use ($tenantId): string {
    return Router::url('/calendar?section=availability&tab=' . rawurlencode($tab) . ($tenantId > 0 ? '&tenant_id=' . (int) $tenantId : ''));
};
$tabLabels = [
    'overview' => ['Visão geral', 'Resumo da operação e atalhos'],
    'availability' => ['Disponibilidades', 'Horários que o agente pode oferecer'],
    'preschedules' => ['Pré-agendamentos', 'Buscas, escolhas e validações'],
    'settings' => ['Configurações', 'Regras, equipe e integrações'],
];
$publishedStatusLabels = ['available' => 'Disponível', 'held' => 'Pré-reservado', 'booked' => 'Confirmado', 'blocked' => 'Bloqueado', 'expired' => 'Expirado'];
$publishedStatusClasses = ['available' => 'badge-success', 'held' => 'badge-warning', 'booked' => 'badge-info', 'blocked' => '', 'expired' => 'badge-danger'];
$availabilityStatusFilter = strtolower(trim((string) ($_GET['availability_status'] ?? 'all')));
$availabilityStatusFilter = in_array($availabilityStatusFilter, array_merge(['all'], array_keys($publishedStatusLabels)), true) ? $availabilityStatusFilter : 'all';
$availabilityModalityFilter = strtolower(trim((string) ($_GET['availability_modality'] ?? 'all')));
$availabilityModalityFilter = in_array($availabilityModalityFilter, ['all', 'indefinida', 'online', 'presencial', 'telefone'], true) ? $availabilityModalityFilter : 'all';
$availabilityOwnerFilter = max(0, (int) ($_GET['availability_owner'] ?? 0));
$availabilityFromFilter = trim((string) ($_GET['availability_from'] ?? ''));
$availabilityToFilter = trim((string) ($_GET['availability_to'] ?? ''));
$availabilityFromFilter = preg_match('/^\d{4}-\d{2}-\d{2}$/', $availabilityFromFilter) === 1 ? $availabilityFromFilter : '';
$availabilityToFilter = preg_match('/^\d{4}-\d{2}-\d{2}$/', $availabilityToFilter) === 1 ? $availabilityToFilter : '';
$allPublishedSlots = array_values(array_filter($publishedSlots, static fn (array $slot): bool => (string) ($slot['status'] ?? '') !== 'cancelled'));
$publishedSlotStats = array_fill_keys(array_keys($publishedStatusLabels), 0);
foreach ($allPublishedSlots as $publishedSlotForStats) {
    $statusForStats = (string) ($publishedSlotForStats['status'] ?? 'available');
    if (array_key_exists($statusForStats, $publishedSlotStats)) $publishedSlotStats[$statusForStats]++;
}
$visiblePublishedSlots = array_values(array_filter($allPublishedSlots, static function (array $slot) use ($availabilityStatusFilter, $availabilityModalityFilter, $availabilityOwnerFilter, $availabilityFromFilter, $availabilityToFilter): bool {
    $status = (string) ($slot['status'] ?? 'available');
    $modality = (string) ($slot['modality'] ?? 'indefinida');
    $ownerId = (int) ($slot['owner_user_id'] ?? 0);
    $slotDate = substr((string) ($slot['starts_at'] ?? ''), 0, 10);
    if ($availabilityStatusFilter !== 'all' && $status !== $availabilityStatusFilter) return false;
    if ($availabilityModalityFilter !== 'all' && $modality !== $availabilityModalityFilter) return false;
    if ($availabilityOwnerFilter > 0 && $ownerId !== $availabilityOwnerFilter) return false;
    if ($availabilityFromFilter !== '' && $slotDate < $availabilityFromFilter) return false;
    if ($availabilityToFilter !== '' && $slotDate > $availabilityToFilter) return false;
    return true;
}));
$publishedSlotsByDate = [];
foreach ($visiblePublishedSlots as $publishedSlot) {
    $slotDateKey = substr((string) ($publishedSlot['starts_at'] ?? ''), 0, 10);
    $publishedSlotsByDate[$slotDateKey][] = $publishedSlot;
}
$isRsAdmin = Auth::isSuperAdmin();
$availabilityMode = ($settings['availability_mode'] ?? 'free_slots') === 'marked_events' ? 'marked_events' : 'free_slots';
$workdays = json_decode((string) ($settings['workdays_json'] ?? '[]'), true);
$workdays = is_array($workdays) ? array_map('intval', $workdays) : [1, 2, 3, 4, 5];
$hours = json_decode((string) ($settings['working_hours_json'] ?? '{}'), true);
$hours = is_array($hours) ? $hours : ['start' => '08:00', 'end' => '18:00'];
$internalHoursByDay = [];
foreach ([1 => 'Segunda', 2 => 'Terça', 3 => 'Quarta', 4 => 'Quinta', 5 => 'Sexta', 6 => 'Sábado', 0 => 'Domingo'] as $dayNumber => $dayLabel) {
    $dayConfig = (isset($hours['by_day']) && is_array($hours['by_day']))
        ? ($hours['by_day'][(string) $dayNumber] ?? $hours['by_day'][$dayNumber] ?? [])
        : [];
    $internalHoursByDay[$dayNumber] = [
        'label' => $dayLabel,
        'enabled' => $dayConfig !== [] ? !empty($dayConfig['enabled']) : in_array($dayNumber, $workdays, true),
        'start' => (string) ($dayConfig['start'] ?? $hours['start'] ?? '08:00'),
        'end' => (string) ($dayConfig['end'] ?? $hours['end'] ?? ($dayNumber === 6 ? '12:00' : '18:00')),
    ];
}
$date = static fn (?string $value, string $format = 'd/m/Y H:i'): string => $value ? date($format, strtotime($value)) : '-';
$statusLabels = [
    'pending' => 'Pendente',
    'sent' => 'Enviado ao n8n',
    'received' => 'Disponibilidade recebida',
    'communicating' => 'Enviando opções ao cliente',
    'options_sent' => 'Opções enviadas ao cliente',
    'empty' => 'Nenhum horário encontrado',
    'failed' => 'Falhou',
    'requested' => 'Consulta solicitada',
    'hold_requested' => 'Pré-reserva solicitada',
    'slot_selected' => 'Horário escolhido',
    'validated' => 'Validado',
];
$sourceLabels = [
    'google_free_slots' => 'Espaços livres do Google',
    'google_marked_slots' => 'Eventos VAGO do Google',
    'internal_fallback' => 'Agenda interna RS Connect · calculada',
    'internal_published' => 'Agenda interna RS Connect · horários liberados',
    'n8n' => 'n8n',
    'n8n_google_calendar' => 'Google Agenda',
];
$eventStateLabels = [
    'available' => 'Disponível',
    'selected' => 'Escolhido',
    'held' => 'Pré-reservado',
    'confirmed' => 'Confirmado',
    'released' => 'Liberado',
    'error' => 'Erro',
    'create_requested' => 'Criação em andamento',
    'update_requested' => 'Atualização em andamento',
    'delete_requested' => 'Remoção em andamento',
    'created' => 'Criado no Google',
    'updated' => 'Atualizado no Google',
    'deleted' => 'Removido do Google',
];
$modeLabels = [
    'free_slots' => 'Calcular espaços livres no Google',
    'marked_events' => 'Usar eventos VAGO no Google',
];
$calendarSourceLabels = [
    'internal' => 'Agenda interna',
    'google' => 'Google Agenda',
    'none' => 'Sem agenda',
];

$slotsByAppointment = [];
foreach ($slots as $slot) {
    $key = (int) ($slot['appointment_id'] ?? 0);
    $slotsByAppointment[$key][] = $slot;
}

$requestInsight = static function (array $request): string {
    if (!empty($request['error_message'])) {
        return (string) $request['error_message'];
    }
    $requested = json_decode((string) ($request['requested_payload_json'] ?? ''), true);
    $raw = json_decode((string) ($request['response_payload_json'] ?? ''), true);
    $requestedSource = is_array($requested) ? (string) ($requested['calendar_source'] ?? '') : '';
    $responseSource = is_array($raw) ? (string) ($raw['calendar_source'] ?? $raw['source'] ?? '') : '';
    if ($requestedSource === 'internal' || in_array($responseSource, ['internal', 'internal_fallback', 'internal_published'], true)) {
        return 'Agenda interna do RS Connect · Google/n8n não utilizados';
    }
    if (!is_array($raw)) {
        return '';
    }
    $meta = isset($raw['meta']) && is_array($raw['meta']) ? $raw['meta'] : [];
    $eventsRead = (int) ($meta['events_read'] ?? $meta['occupied_events_considered'] ?? 0);
    $titleMatches = (int) ($meta['title_matches'] ?? 0);
    if (($request['availability_mode'] ?? '') === 'marked_events') {
        return $eventsRead . ' evento(s) lido(s) · ' . $titleMatches . ' título(s) VAGO encontrado(s)';
    }
    return $eventsRead > 0 ? $eventsRead . ' compromisso(s) analisado(s)' : '';
};
?>


<nav class="agenda-main-tabs" aria-label="Áreas da agenda">
    <a class="agenda-main-tab" href="<?= View::e(Router::url('/calendar' . ($tenantId > 0 ? '?tenant_id=' . (int) $tenantId : ''))) ?>">
        <strong>Compromissos</strong><small>Agenda marcada</small>
    </a>
    <a class="agenda-main-tab <?= $activeTab === 'overview' ? 'is-active' : '' ?>" href="<?= View::e($tabUrl('overview')) ?>" <?= $activeTab === 'overview' ? 'aria-current="page"' : '' ?>>
        <strong>Visão geral</strong><small>Resumo da agenda</small>
    </a>
    <a class="agenda-main-tab <?= $activeTab === 'availability' ? 'is-active' : '' ?>" href="<?= View::e($tabUrl('availability')) ?>" <?= $activeTab === 'availability' ? 'aria-current="page"' : '' ?>>
        <strong>Disponibilidades</strong><small>Horários liberados</small>
    </a>
    <a class="agenda-main-tab <?= $activeTab === 'preschedules' ? 'is-active' : '' ?>" href="<?= View::e($tabUrl('preschedules')) ?>" <?= $activeTab === 'preschedules' ? 'aria-current="page"' : '' ?>>
        <strong>Pré-agendamentos</strong><small>Pedidos e validações</small>
    </a>
    <a class="agenda-main-tab <?= $activeTab === 'settings' ? 'is-active' : '' ?>" href="<?= View::e($tabUrl('settings')) ?>" <?= $activeTab === 'settings' ? 'aria-current="page"' : '' ?>>
        <strong>Configurações</strong><small>Regras da agenda</small>
    </a>
</nav>

<section class="hero-card operations-hero-clean calendar-smart-hero">
    <div>
        <span class="eyebrow">Agenda · <?= View::e($tabLabels[$activeTab][0]) ?></span>
        <h2><?= View::e(match ($activeTab) {
            'availability' => 'Disponibilidades que podem ser oferecidas aos clientes.',
            'preschedules' => 'Pré-agendamentos, buscas e horários escolhidos.',
            'settings' => 'Configurações da agenda em um espaço separado da operação.',
            default => 'Central da agenda e disponibilidade.',
        }) ?></h2>
        <p><?= View::e(match ($activeTab) {
            'availability' => 'Libere e acompanhe horários sem misturar a operação com as regras estruturais da agenda.',
            'preschedules' => 'Acompanhe o que o cliente pediu, refaça buscas quando necessário e valide as opções encontradas.',
            'settings' => $isRsAdmin ? 'Ajuste estratégia, equipe, horários e integrações técnicas sem poluir a tela operacional.' : 'Ajuste estratégia, equipe e regras da agenda em um único lugar.',
            default => 'Veja o estado atual e escolha a área da agenda em que deseja trabalhar.',
        }) ?></p>
    </div>
    <div class="hero-actions operations-hero-actions">
        <?php if ($isRsAdmin): ?><a class="btn btn-primary" href="<?= View::e(Router::url('/n8n-templates')) ?>">Fluxos n8n</a><?php endif; ?>
        <span class="badge <?= !empty($settings['enabled']) ? 'badge-success' : 'badge-warning' ?>"><?= !empty($settings['enabled']) ? 'Busca automática ativa' : 'Busca automática desativada' ?></span>
    </div>
</section>

<?php if ($isRsAdmin): ?>
    <form class="toolbar-card" method="get" action="<?= View::e(Router::url('/calendar')) ?>"><input type="hidden" name="section" value="availability"><input type="hidden" name="tab" value="<?= View::e($activeTab) ?>">
        <div class="field inline-field">
            <label>Empresa</label>
            <select name="tenant_id" data-auto-submit>
                <?php foreach ($tenants as $tenant): ?>
                    <option value="<?= (int) $tenant['id'] ?>" <?= (int) $tenant['id'] === (int) $tenantId ? 'selected' : '' ?>><?= View::e($tenant['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="btn btn-secondary" type="submit">Carregar</button>
    </form>
<?php endif; ?>

<?php if ($activeTab === 'overview'): ?>
<div class="report-kpi-grid operations-kpis agenda-overview-kpis">
    <article class="card report-kpi"><span>Pré-agendamentos</span><strong><?= (int) ($metrics['pending'] ?? 0) ?></strong><small>Aguardando decisão</small></article>
    <article class="card report-kpi"><span>Horários atuais</span><strong><?= (int) ($metrics['slots'] ?? 0) ?></strong><small>Somente da última busca</small></article>
    <article class="card report-kpi"><span>Escolhidos</span><strong><?= (int) ($metrics['selected'] ?? 0) ?></strong><small>Aplicados ao pré-agendamento</small></article>
    <article class="card report-kpi"><span>Origem atual</span><strong class="calendar-mode-kpi"><?= View::e($calendarSource === 'internal' ? 'Interna' : ($calendarSource === 'google' ? ($availabilityMode === 'marked_events' ? 'Google VAGO' : 'Google') : 'Desativada')) ?></strong><small><?= View::e($calendarSource === 'google' ? $modeLabels[$availabilityMode] : ($calendarSourceLabels[$calendarSource] ?? 'Sem agenda')) ?></small></article>
</div>

<div class="agenda-overview-grid">
    <section class="card agenda-overview-card">
        <div class="agenda-overview-card-head"><span class="eyebrow">Agenda interna</span><span class="badge <?= $calendarSource === 'internal' && $internalStrategy === 'published' ? 'badge-success' : 'badge-info' ?>"><?= View::e($calendarSource === 'internal' ? ($internalStrategy === 'published' ? 'Horários liberados' : 'Cálculo por jornada') : ($calendarSourceLabels[$calendarSource] ?? 'Sem agenda')) ?></span></div>
        <h3>Disponibilidade</h3>
        <p><?= $calendarSource === 'internal' && $internalStrategy === 'published' ? 'O agente oferece apenas vagas explicitamente publicadas.' : 'Consulte e organize os horários que podem ser apresentados aos clientes.' ?></p>
        <div class="agenda-overview-mini-stats">
            <div><strong><?= (int) ($publishedSlotStats['available'] ?? 0) ?></strong><span>disponíveis</span></div>
            <div><strong><?= (int) ($publishedSlotStats['held'] ?? 0) ?></strong><span>pré-reservados</span></div>
            <div><strong><?= (int) ($publishedSlotStats['booked'] ?? 0) ?></strong><span>confirmados</span></div>
        </div>
        <a class="btn btn-secondary" href="<?= View::e($tabUrl('availability')) ?>">Gerenciar disponibilidades</a>
    </section>
    <section class="card agenda-overview-card">
        <div class="agenda-overview-card-head"><span class="eyebrow">Atendimento</span><span class="badge <?= (int) ($metrics['pending'] ?? 0) > 0 ? 'badge-warning' : 'badge-success' ?>"><?= (int) ($metrics['pending'] ?? 0) ?> pendente(s)</span></div>
        <h3>Pré-agendamentos</h3>
        <p>Acompanhe pedidos, preferências e resultados da última consulta sem misturar com a configuração da agenda.</p>
        <div class="agenda-overview-mini-stats">
            <div><strong><?= (int) ($metrics['slots'] ?? 0) ?></strong><span>opções atuais</span></div>
            <div><strong><?= (int) ($metrics['selected'] ?? 0) ?></strong><span>escolhidos</span></div>
            <div><strong><?= (int) ($metrics['held'] ?? 0) ?></strong><span>em espera</span></div>
        </div>
        <a class="btn btn-secondary" href="<?= View::e($tabUrl('preschedules')) ?>">Abrir pré-agendamentos</a>
    </section>
    <section class="card agenda-overview-card agenda-overview-card-wide">
        <div class="agenda-overview-card-head"><span class="eyebrow">Regras</span><span class="badge">Separadas da operação</span></div>
        <div class="agenda-overview-settings-line">
            <div><h3>Configurações da agenda</h3><p>Estratégia de disponibilidade, agenda por profissional, horários de trabalho e integrações ficam em uma aba própria.</p></div>
            <a class="btn btn-quiet" href="<?= View::e($tabUrl('settings')) ?>">Abrir configurações</a>
        </div>
    </section>
</div>
<?php endif; ?>

<?php if ($activeTab === 'preschedules'): ?>
<section class="card" id="horarios-disponiveis" style="margin-top:16px">
    <div class="section-heading">
        <div><span class="eyebrow">Resultado da última busca</span><h2>Horários disponíveis</h2></div>
        <small class="muted-text">São exibidos apenas os horários da busca mais recente de cada pré-agendamento.</small>
    </div>

    <div class="calendar-slot-groups">
        <?php foreach ($slotsByAppointment as $appointmentId => $appointmentSlots): ?>
            <?php $firstSlot = $appointmentSlots[0] ?? []; ?>
            <article class="calendar-slot-group" id="horarios-<?= (int) $appointmentId ?>">
                <div class="calendar-slot-group-title">
                    <div><strong><?= View::e(($firstSlot['contact_name'] ?? '') ?: ($firstSlot['appointment_title'] ?? 'Pré-agendamento')) ?></strong><small><?= count($appointmentSlots) ?> opção(ões) da última busca</small></div>
                </div>
                <div class="calendar-slot-list">
                    <?php foreach ($appointmentSlots as $slot): ?>
                        <?php
                            $eventState = (string) ($slot['event_state'] ?? 'available');
                            $isSelected = !empty($slot['selected_at']);
                            $isMarkedSlot = ($slot['source'] ?? '') === 'google_marked_slots' || !empty($slot['google_event_id']);
                        ?>
                        <div class="calendar-slot-row <?= $isSelected ? 'is-selected' : '' ?>">
                            <div>
                                <strong><?= View::e($date($slot['starts_at'])) ?> até <?= View::e($date($slot['ends_at'], 'H:i')) ?></strong>
                                <?php if (!empty($slot['suggestion_position'])): ?><span class="badge badge-info">Opção <?= (int) $slot['suggestion_position'] ?></span><?php endif; ?>
                                <small><?= View::e($sourceLabels[$slot['source'] ?? ''] ?? ($slot['source'] ?? 'n8n')) ?><?= ($slot['modality'] ?? 'indefinida') !== 'indefinida' ? ' · ' . View::e(ucfirst((string) $slot['modality'])) : '' ?></small>
                                <?php if ($isMarkedSlot): ?><small>Evento: <?= View::e($eventStateLabels[$eventState] ?? $eventState) ?><?= !empty($slot['event_summary']) ? ' · ' . View::e($slot['event_summary']) : '' ?></small><?php endif; ?>
                            </div>
                            <div>
                                <?php if (!$isSelected && !in_array($eventState, ['held', 'confirmed'], true)): ?>
                                    <form method="post" action="<?= View::e(Router::url('/calendar/availability/apply')) ?>">
                                        <?= Csrf::input() ?>
                                        <input type="hidden" name="tenant_id" value="<?= (int) $tenantId ?>">
                                        <input type="hidden" name="appointment_id" value="<?= (int) $slot['appointment_id'] ?>">
                                        <input type="hidden" name="slot_id" value="<?= (int) $slot['id'] ?>">
                                        <input type="hidden" name="return_to" value="/calendar?section=availability&amp;tab=preschedules&amp;tenant_id=<?= (int) $tenantId ?>#horarios-<?= (int) $slot['appointment_id'] ?>">
                                        <button class="btn btn-small btn-secondary" type="submit">Usar este horário</button>
                                    </form>
                                <?php elseif ($isMarkedSlot && in_array($eventState, ['held', 'confirmed'], true)): ?>
                                    <form method="post" action="<?= View::e(Router::url('/calendar/availability/release')) ?>">
                                        <?= Csrf::input() ?>
                                        <input type="hidden" name="tenant_id" value="<?= (int) $tenantId ?>">
                                        <input type="hidden" name="appointment_id" value="<?= (int) $slot['appointment_id'] ?>">
                                        <input type="hidden" name="return_to" value="/calendar?section=availability&amp;tab=preschedules&amp;tenant_id=<?= (int) $tenantId ?>#horarios-<?= (int) $slot['appointment_id'] ?>">
                                        <button class="btn btn-small btn-quiet" type="submit">Liberar horário</button>
                                    </form>
                                <?php else: ?>
                                    <span class="badge badge-success">Escolhido</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </article>
        <?php endforeach; ?>
        <?php if (!$slotsByAppointment): ?><div class="empty-state">Nenhum horário válido na busca atual. A mensagem do pré-agendamento informa o motivo encontrado.</div><?php endif; ?>
    </div>
</section>


<section class="card" style="margin-top:16px">
    <div class="section-heading">
        <div><span class="eyebrow">Atendimento</span><h2>Pré-agendamentos para validar</h2></div>
        <small class="muted-text">A busca sempre substitui as opções anteriores daquele pré-agendamento.</small>
    </div>
    <div class="calendar-appointment-list">
        <?php foreach ($pending as $appointment): ?>
            <?php
                $availabilityStatus = (string) ($appointment['availability_status'] ?? '');
                $googleState = (string) ($appointment['google_event_state'] ?? '');
                $source = (string) ($appointment['availability_source'] ?? '');
                $isMarked = $source === 'google_marked_slots';
                $isReady = $availabilityStatus === 'slot_selected' && (!$isMarked || in_array($googleState, ['held', 'confirmed'], true));
                $statusText = $statusLabels[$availabilityStatus] ?? ($availabilityStatus ?: 'Disponibilidade ainda não consultada');
            ?>
            <article class="calendar-appointment-card <?= $isReady ? 'is-ready' : '' ?>">
                <div class="calendar-appointment-main">
                    <div class="calendar-title-line">
                        <strong><?= View::e($appointment['title'] ?? 'Pré-agendamento') ?></strong>
                        <span class="badge <?= $isReady ? 'badge-success' : 'badge-warning' ?>"><?= View::e($statusText) ?></span>
                        <?php if (($appointment['appointment_modality'] ?? 'indefinida') !== 'indefinida'): ?><span class="badge badge-info"><?= View::e(ucfirst((string) $appointment['appointment_modality'])) ?></span><?php endif; ?>
                    </div>
                    <p><?= View::e(($appointment['contact_name'] ?? '') ?: ($appointment['phone'] ?? 'Sem contato identificado')) ?></p>
                    <small>Preferência: <?= View::e(($appointment['preferred_day_text'] ?? '') ?: 'dia não informado') ?> · <?= View::e(($appointment['preferred_time_text'] ?? '') ?: 'horário não informado') ?></small>
                    <?php if (!empty($professionalCalendarSettings['enabled'])): ?><small>Profissional: <?= View::e(($appointment['owner_name'] ?? '') ?: 'não selecionado') ?></small><?php endif; ?>
                    <?php if ($isMarked): ?>
                        <small>Evento Google: <?= View::e($eventStateLabels[$googleState] ?? ($googleState ?: 'não vinculado')) ?><?= !empty($appointment['google_event_summary']) ? ' · ' . View::e($appointment['google_event_summary']) : '' ?></small>
                    <?php endif; ?>
                    <?php if ($availabilityStatus === 'options_sent'): ?>
                        <small>Opções enviadas ao cliente<?= !empty($appointment['availability_options_sent_at']) ? ' em ' . View::e($date($appointment['availability_options_sent_at'])) : '' ?><?= !empty($appointment['availability_selection_expires_at']) ? ' · escolha válida até ' . View::e($date($appointment['availability_selection_expires_at'])) : '' ?></small>
                    <?php endif; ?>
                    <?php if (!empty($appointment['availability_selected_at'])): ?>
                        <small>Horário escolhido em <?= View::e($date($appointment['availability_selected_at'])) ?><?= !empty($appointment['availability_selected_by']) ? ' · origem: ' . View::e((string) $appointment['availability_selected_by']) : '' ?></small>
                    <?php endif; ?>
                    <?php if (!empty($appointment['availability_error'])): ?><div class="calendar-inline-alert"><?= View::e($appointment['availability_error']) ?></div><?php endif; ?>
                </div>
                <div class="calendar-appointment-actions">
                    <?php if (!empty($professionalCalendarSettings['enabled']) && $canManage): ?>
                        <form method="post" action="<?= View::e(Router::url('/calendar/owner')) ?>" class="calendar-professional-quick-form">
                            <?= Csrf::input() ?>
                            <input type="hidden" name="tenant_id" value="<?= (int) $tenantId ?>">
                            <input type="hidden" name="appointment_id" value="<?= (int) $appointment['id'] ?>">
                            <input type="hidden" name="return_to" value="/calendar?section=availability&amp;tab=preschedules&amp;tenant_id=<?= (int) $tenantId ?>">
                            <select name="owner_user_id" <?= !empty($professionalCalendarSettings['require_owner']) ? 'required' : '' ?>>
                                <option value="">Selecione o profissional</option>
                                <?php foreach ($professionalProfiles as $profile): ?><option value="<?= (int) $profile['id'] ?>" <?= (int) ($appointment['owner_user_id'] ?? 0) === (int) $profile['id'] ? 'selected' : '' ?> <?= empty($profile['accepting_appointments']) ? 'disabled' : '' ?>><?= View::e($profile['name']) ?><?= empty($profile['accepting_appointments']) ? ' — agenda pausada' : '' ?></option><?php endforeach; ?>
                            </select>
                            <button class="btn btn-small btn-quiet" type="submit">Definir</button>
                        </form>
                    <?php endif; ?>
                    <?php if (!empty($appointment['availability_slot_count'])): ?>
                        <a class="btn btn-small btn-quiet" href="#horarios-<?= (int) $appointment['id'] ?>">Ver <?= (int) $appointment['availability_slot_count'] ?> horário(s)</a>
                    <?php endif; ?>
                    <form method="post" action="<?= View::e(Router::url('/calendar/availability/request')) ?>">
                        <?= Csrf::input() ?>
                        <input type="hidden" name="tenant_id" value="<?= (int) $tenantId ?>">
                        <input type="hidden" name="appointment_id" value="<?= (int) $appointment['id'] ?>">
                        <input type="hidden" name="return_to" value="/calendar?section=availability&amp;tab=preschedules&amp;tenant_id=<?= (int) $tenantId ?>#horarios-<?= (int) $appointment['id'] ?>">
                        <button class="btn btn-small btn-primary" type="submit">Buscar disponibilidade</button>
                    </form>
                </div>
            </article>
        <?php endforeach; ?>
        <?php if (!$pending): ?><div class="empty-state">Nenhum pré-agendamento pendente.</div><?php endif; ?>
    </div>
</section>
<?php endif; ?>

<?php if ($activeTab === 'settings'): ?>
<details class="calendar-settings-disclosure" id="comunicacao-cliente" open>
    <summary>
        <span><span class="eyebrow">Cliente / paciente</span><strong>Comunicação e confirmação do agendamento</strong><small>Reconhece compromissos existentes e controla confirmações, lembretes e pedidos de presença.</small></span>
        <span class="calendar-settings-chevron" aria-hidden="true">⌄</span>
    </summary>

    <section class="card" style="margin-top:16px">
        <div class="section-heading">
            <div>
                <span class="eyebrow">Continuidade da agenda</span>
                <h2>O cliente não volta para um novo fluxo quando já tem compromisso</h2>
                <p>Perguntas como “minha consulta está confirmada?”, “que horas é?” ou “qual o endereço?” são respondidas a partir do compromisso real registrado na Agenda.</p>
            </div>
            <span class="badge <?= !empty($calendarClientSettings['ready']) ? 'badge-success' : 'badge-warning' ?>"><?= !empty($calendarClientSettings['ready']) ? 'Disponível' : 'Migration necessária' ?></span>
        </div>

        <?php if (empty($calendarClientSettings['ready'])): ?>
            <div class="message-warning"><strong>Atualização do banco necessária</strong><span>Execute <code>122_calendar_client_communications.sql</code> para ativar esta configuração e a fila de mensagens da Agenda.</span></div>
        <?php endif; ?>

        <form method="post" action="<?= View::e(Router::url('/calendar/availability/client-communications')) ?>" class="form-stack">
            <?= Csrf::input() ?>
            <input type="hidden" name="tenant_id" value="<?= (int) $tenantId ?>">

            <div class="section-heading compact"><div><span class="eyebrow">Identificação</span><h3>Agendamento existente</h3><p>Esta camada roda antes da triagem e de uma nova pesquisa de horários.</p></div></div>
            <div class="settings-toggle-grid">
                <label class="switch-card">
                    <input type="checkbox" name="client_lookup_enabled" value="1" <?= !empty($calendarClientSettings['lookup_enabled']) ? 'checked' : '' ?>>
                    <span><strong>Identificar compromisso existente</strong><small>Consulta o próximo agendamento ativo do contato quando a mensagem se refere à consulta, horário, local, confirmação, cancelamento ou remarcação.</small></span>
                </label>
                <label class="switch-card">
                    <input type="checkbox" name="client_lookup_outside_hours" value="1" <?= !empty($calendarClientSettings['lookup_outside_hours']) ? 'checked' : '' ?>>
                    <span><strong>Responder sobre a própria agenda fora do expediente</strong><small>Permite respostas operacionais sobre um compromisso já registrado mesmo quando o atendimento comercial estiver fechado.</small></span>
                </label>
            </div>
            <label class="field"><span>Mensagem quando o contato pergunta por um agendamento e nenhum compromisso ativo é encontrado</span><textarea name="client_lookup_no_appointment_message" rows="3" maxlength="4000"><?= View::e((string) ($calendarClientSettings['lookup_no_appointment_message'] ?? '')) ?></textarea></label>

            <div class="section-heading compact" style="margin-top:8px">
                <div>
                    <span class="eyebrow">Disparos automáticos</span>
                    <h3>Confirmação, lembrete e presença</h3>
                    <p>As automações abaixo só usam dados reais do compromisso confirmado. O WhatsApp é obtido do contato vinculado ao agendamento/conversa, e alterar uma regra recalcula os disparos futuros já pendentes.</p>
                </div>
            </div>

            <div class="message-info">
                <strong>Variáveis disponíveis nas mensagens</strong>
                <span><code>{{nome}}</code> · <code>{{data}}</code> · <code>{{hora}}</code> · <code>{{local}}</code> · <code>{{modalidade}}</code> · <code>{{profissional}}</code></span>
            </div>

            <div class="form-grid two calendar-client-automation-grid">
                <div class="card-subtle form-stack">
                    <label class="switch-card">
                        <input type="checkbox" name="client_send_confirmed_enabled" value="1" <?= !empty($calendarClientSettings['send_confirmed_enabled']) ? 'checked' : '' ?>>
                        <span><strong>Confirmação do agendamento</strong><small>Envia imediatamente quando o compromisso passa efetivamente para Confirmado.</small></span>
                    </label>
                    <label class="field"><span>Mensagem de confirmação</span><textarea name="client_confirmed_message" rows="4" maxlength="4000"><?= View::e((string) ($calendarClientSettings['confirmed_message'] ?? '')) ?></textarea></label>
                </div>

                <div class="card-subtle form-stack">
                    <label class="switch-card">
                        <input type="checkbox" name="client_reminder_enabled" value="1" <?= !empty($calendarClientSettings['reminder_enabled']) ? 'checked' : '' ?>>
                        <span><strong>Lembrete automático</strong><small>Envia um aviso antes do horário somente para compromissos que continuam confirmados.</small></span>
                    </label>
                    <div class="form-grid two">
                        <label class="field"><span>Enviar antes</span><input type="number" name="client_reminder_lead_value" min="1" max="10080" step="1" value="<?= (int) ($reminderLead['value'] ?? 2) ?>"></label>
                        <label class="field"><span>Unidade</span><select name="client_reminder_lead_unit">
                            <option value="minutes" <?= ($reminderLead['unit'] ?? '') === 'minutes' ? 'selected' : '' ?>>minuto(s)</option>
                            <option value="hours" <?= ($reminderLead['unit'] ?? '') === 'hours' ? 'selected' : '' ?>>hora(s)</option>
                            <option value="days" <?= ($reminderLead['unit'] ?? '') === 'days' ? 'selected' : '' ?>>dia(s)</option>
                        </select></label>
                    </div>
                    <label class="field"><span>Mensagem do lembrete</span><textarea name="client_reminder_message" rows="4" maxlength="4000"><?= View::e((string) ($calendarClientSettings['reminder_message'] ?? '')) ?></textarea></label>
                </div>

                <div class="card-subtle form-stack">
                    <label class="switch-card">
                        <input type="checkbox" name="client_presence_request_enabled" value="1" <?= !empty($calendarClientSettings['presence_request_enabled']) ? 'checked' : '' ?>>
                        <span><strong>Pedir confirmação de presença</strong><small>O cliente pode responder naturalmente: confirmar, cancelar ou pedir remarcação. A resposta fica separada do status do compromisso.</small></span>
                    </label>
                    <div class="form-grid two">
                        <label class="field"><span>Solicitar antes</span><input type="number" name="client_presence_request_lead_value" min="1" max="20160" step="1" value="<?= (int) ($presenceLead['value'] ?? 1) ?>"></label>
                        <label class="field"><span>Unidade</span><select name="client_presence_request_lead_unit">
                            <option value="minutes" <?= ($presenceLead['unit'] ?? '') === 'minutes' ? 'selected' : '' ?>>minuto(s)</option>
                            <option value="hours" <?= ($presenceLead['unit'] ?? '') === 'hours' ? 'selected' : '' ?>>hora(s)</option>
                            <option value="days" <?= ($presenceLead['unit'] ?? '') === 'days' ? 'selected' : '' ?>>dia(s)</option>
                        </select></label>
                    </div>
                    <label class="field"><span>Mensagem para confirmação de presença</span><textarea name="client_presence_request_message" rows="4" maxlength="4000"><?= View::e((string) ($calendarClientSettings['presence_request_message'] ?? '')) ?></textarea></label>
                </div>

                <div class="card-subtle form-stack">
                    <div class="section-heading compact"><div><span class="eyebrow">Regras complementares</span><h3>Outros eventos</h3><p>Mensagens opcionais para o ciclo do compromisso.</p></div></div>
                    <label class="switch-card"><input type="checkbox" name="client_send_created_enabled" value="1" <?= !empty($calendarClientSettings['send_created_enabled']) ? 'checked' : '' ?>><span><strong>Ao registrar um agendamento</strong><small>Útil quando a criação do compromisso precisa ser comunicada antes da confirmação.</small></span></label>
                    <label class="switch-card"><input type="checkbox" name="client_send_cancelled_enabled" value="1" <?= !empty($calendarClientSettings['send_cancelled_enabled']) ? 'checked' : '' ?>><span><strong>Ao cancelar ou recusar</strong><small>Avisa somente depois que o status real da Agenda for alterado.</small></span></label>
                    <label class="switch-card"><input type="checkbox" name="client_send_rescheduled_enabled" value="1" <?= !empty($calendarClientSettings['send_rescheduled_enabled']) ? 'checked' : '' ?>><span><strong>Ao registrar remarcação</strong><small>Comunica que o compromisso entrou no fluxo de ajuste sem inventar uma nova data.</small></span></label>
                </div>
            </div>

            <div class="message-info">
                <strong>Sem mensagens duplicadas no mesmo instante</strong>
                <span>Se lembrete e pedido de confirmação estiverem configurados para o mesmo momento, o pedido de confirmação de presença tem prioridade e o lembrete simples não é enviado.</span>
            </div>

            <details class="calendar-settings-disclosure" style="margin-top:8px">
                <summary><span><strong>Mensagens dos eventos complementares</strong><small>Edite apenas se utilizar registro, cancelamento ou remarcação automáticos.</small></span><span class="calendar-settings-chevron" aria-hidden="true">⌄</span></summary>
                <div class="form-stack" style="padding-top:16px">
                    <label class="field"><span>Agendamento registrado</span><textarea name="client_created_message" rows="3" maxlength="4000"><?= View::e((string) ($calendarClientSettings['created_message'] ?? '')) ?></textarea></label>
                    <label class="field"><span>Agendamento cancelado</span><textarea name="client_cancelled_message" rows="3" maxlength="4000"><?= View::e((string) ($calendarClientSettings['cancelled_message'] ?? '')) ?></textarea></label>
                    <label class="field"><span>Remarcação</span><textarea name="client_rescheduled_message" rows="3" maxlength="4000"><?= View::e((string) ($calendarClientSettings['rescheduled_message'] ?? '')) ?></textarea></label>
                </div>
            </details>

            <div class="message-info"><strong>Cancelamento e remarcação solicitados pelo WhatsApp são protegidos</strong><span>O pedido do cliente é registrado e sinalizado para a equipe, mas o horário atual não é liberado automaticamente até a alteração efetiva do compromisso. Isso evita perder uma vaga por interpretação ambígua.</span></div>
            <div><button class="btn btn-primary" type="submit" <?= empty($calendarClientSettings['ready']) ? 'disabled' : '' ?>>Salvar comunicação da agenda</button></div>
        </form>
    </section>
</details>

<details class="calendar-settings-disclosure professional-calendar-disclosure" id="agenda-profissionais" <?= !empty($professionalCalendarSettings['enabled']) ? 'open' : '' ?>>
    <summary>
        <span><span class="eyebrow">Equipe</span><strong>Agenda por profissional</strong><small>Horários individuais, calendário e proteção contra conflitos do profissional e do cliente.</small></span>
        <span class="calendar-settings-chevron" aria-hidden="true">⌄</span>
    </summary>

    <section class="card professional-calendar-settings-card" style="margin-top:16px">
        <div class="section-heading">
            <div><span class="eyebrow">Recurso opcional</span><h2>Funcionamento da agenda individual</h2></div>
            <span class="badge <?= !empty($professionalCalendarSettings['enabled']) ? 'badge-success' : 'badge-warning' ?>"><?= !empty($professionalCalendarSettings['enabled']) ? 'Ativa' : 'Desativada' ?></span>
        </div>
        <form method="post" action="<?= View::e(Router::url('/calendar/availability/professional-settings')) ?>" class="form-stack">
            <?= Csrf::input() ?>
            <input type="hidden" name="tenant_id" value="<?= (int) $tenantId ?>">
            <div class="settings-toggle-grid professional-calendar-toggle-grid">
                <label class="switch-card"><input type="checkbox" name="professional_calendar_enabled" value="1" <?= !empty($professionalCalendarSettings['enabled']) ? 'checked' : '' ?>><span><strong>Usar agenda por profissional</strong><small>Cada usuário pode ter seus próprios dias, horários e calendário.</small></span></label>
                <label class="switch-card"><input type="checkbox" name="professional_calendar_require_owner" value="1" <?= !array_key_exists('require_owner', $professionalCalendarSettings) || !empty($professionalCalendarSettings['require_owner']) ? 'checked' : '' ?>><span><strong>Exigir profissional no agendamento</strong><small>Impede consultar ou confirmar um horário sem selecionar quem realizará o atendimento.</small></span></label>
                <label class="switch-card"><input type="checkbox" name="professional_calendar_auto_from_conversation" value="1" <?= !empty($professionalCalendarSettings['auto_from_conversation']) ? 'checked' : '' ?>><span><strong>Usar responsável da conversa automaticamente</strong><small>Opcional e desativado por padrão. Quando desligado, o profissional é escolhido manualmente na agenda.</small></span></label>
                <input type="hidden" name="professional_calendar_prevent_contact_overlap" value="0">
                <label class="switch-card"><input type="checkbox" name="professional_calendar_prevent_contact_overlap" value="1" <?= !array_key_exists('prevent_contact_overlap', $professionalCalendarSettings) || !empty($professionalCalendarSettings['prevent_contact_overlap']) ? 'checked' : '' ?>><span><strong>Impedir dois horários para o mesmo cliente</strong><small>Bloqueia agendamentos sobrepostos do mesmo contato, mesmo quando os profissionais são diferentes.</small></span></label>
            </div>
            <div class="message-info"><strong>Nenhuma atribuição automática é obrigatória</strong><span>A conversa pode continuar sendo assumida manualmente. A atribuição automática é independente do bloqueio de conflito do cliente.</span></div>
            <div><button class="btn btn-primary" type="submit">Salvar funcionamento</button></div>
        </form>
    </section>

    <?php if (!empty($professionalCalendarSettings['enabled'])): ?>
        <div class="professional-calendar-profile-grid">
            <?php foreach ($professionalProfiles as $profile): ?>
                <?php
                    $profileWorkdays = json_decode((string) ($profile['workdays_json'] ?? '[]'), true);
                    $profileWorkdays = is_array($profileWorkdays) ? array_map('intval', $profileWorkdays) : [1, 2, 3, 4, 5];
                    $profileHours = json_decode((string) ($profile['working_hours_json'] ?? '{}'), true);
                    $profileHours = is_array($profileHours) ? $profileHours : [];
                    $profileByDay = isset($profileHours['by_day']) && is_array($profileHours['by_day']) ? $profileHours['by_day'] : [];
                    $fallbackStart = (string) ($profileHours['start'] ?? '08:00');
                    $fallbackEnd = (string) ($profileHours['end'] ?? '18:00');
                ?>
                <article class="card professional-calendar-profile" id="profissional-<?= (int) $profile['id'] ?>">
                    <form method="post" action="<?= View::e(Router::url('/calendar/availability/professional-profile')) ?>" class="form-stack">
                        <?= Csrf::input() ?>
                        <input type="hidden" name="tenant_id" value="<?= (int) $tenantId ?>">
                        <input type="hidden" name="user_id" value="<?= (int) $profile['id'] ?>">
                        <div class="section-heading compact">
                            <div><span class="eyebrow">Profissional</span><h2><?= View::e($profile['name']) ?></h2><p><?= View::e($profile['email'] ?? '') ?></p></div>
                            <span class="badge <?= !empty($profile['accepting_appointments']) ? 'badge-success' : 'badge-warning' ?>"><?= !empty($profile['accepting_appointments']) ? 'Recebendo agenda' : 'Agenda pausada' ?></span>
                        </div>
                        <label class="switch-inline"><input type="checkbox" name="accepting_appointments" value="1" <?= !empty($profile['accepting_appointments']) ? 'checked' : '' ?>><span>Receber novos agendamentos</span></label>
                        <div class="field-grid two">
                            <div class="field"><label>Calendário Google deste profissional</label><input type="text" name="google_calendar_id" value="<?= View::e($profile['google_calendar_id'] ?? '') ?>" placeholder="primary ou ID do calendário"><small class="muted-text">Vazio usa o calendário geral da empresa.</small></div>
                            <div class="field"><label>Fuso horário</label><input type="text" name="timezone" value="<?= View::e($profile['timezone'] ?? 'America/Sao_Paulo') ?>"></div>
                        </div>
                        <div class="field-grid three">
                            <div class="field"><label>Duração</label><div class="input-with-suffix"><input type="number" name="default_duration_minutes" min="15" max="240" value="<?= (int) ($profile['default_duration_minutes'] ?? 50) ?>"><span>min</span></div></div>
                            <div class="field"><label>Intervalo das opções</label><div class="input-with-suffix"><input type="number" name="slot_interval_minutes" min="5" max="240" value="<?= (int) ($profile['slot_interval_minutes'] ?? 30) ?>"><span>min</span></div></div>
                            <div class="field"><label>Margem entre compromissos</label><div class="input-with-suffix"><input type="number" name="buffer_minutes" min="0" max="180" value="<?= (int) ($profile['buffer_minutes'] ?? 10) ?>"><span>min</span></div></div>
                        </div>
                        <div class="field-grid three">
                            <div class="field"><label>Antecedência mínima</label><div class="input-with-suffix"><input type="number" name="min_notice_hours" min="0" max="720" value="<?= (int) ($profile['min_notice_hours'] ?? 4) ?>"><span>h</span></div></div>
                            <div class="field"><label>Buscar por</label><div class="input-with-suffix"><input type="number" name="search_days_ahead" min="1" max="90" value="<?= (int) ($profile['search_days_ahead'] ?? 14) ?>"><span>dias</span></div></div>
                            <div class="field"><label>Máximo de opções</label><input type="number" name="max_suggestions" min="1" max="20" value="<?= (int) ($profile['max_suggestions'] ?? 5) ?>"></div>
                        </div>
                        <div class="professional-week-schedule">
                            <?php foreach ([1 => 'Segunda', 2 => 'Terça', 3 => 'Quarta', 4 => 'Quinta', 5 => 'Sexta', 6 => 'Sábado', 0 => 'Domingo'] as $day => $label): ?>
                                <?php $dayConfig = $profileByDay[(string) $day] ?? $profileByDay[$day] ?? []; $dayEnabled = array_key_exists('enabled', $dayConfig) ? !empty($dayConfig['enabled']) : in_array($day, $profileWorkdays, true); ?>
                                <div class="professional-week-row">
                                    <label class="professional-day-toggle"><input type="checkbox" name="workdays[]" value="<?= (int) $day ?>" <?= $dayEnabled ? 'checked' : '' ?>><span><?= View::e($label) ?></span></label>
                                    <input type="time" name="working_start[<?= (int) $day ?>]" value="<?= View::e($dayConfig['start'] ?? $fallbackStart) ?>">
                                    <span>até</span>
                                    <input type="time" name="working_end[<?= (int) $day ?>]" value="<?= View::e($dayConfig['end'] ?? ($day === 6 ? '12:00' : $fallbackEnd)) ?>">
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div><button class="btn btn-secondary" type="submit">Salvar agenda de <?= View::e($profile['name']) ?></button></div>
                    </form>
                </article>
            <?php endforeach; ?>
            <?php if (!$professionalProfiles): ?><div class="empty-state">Cadastre usuários ativos na empresa para configurar agendas individuais.</div><?php endif; ?>
        </div>
    <?php endif; ?>
</details>

<details class="calendar-settings-disclosure" id="configuracoes-da-agenda">
    <summary>
        <span><span class="eyebrow">Configurações</span><strong>Regras da agenda</strong><small>Dias, horários, duração, modo VAGO e integração.</small></span>
        <span class="calendar-settings-chevron" aria-hidden="true">⌄</span>
    </summary>
<form method="post" action="<?= View::e(Router::url('/calendar/availability/settings')) ?>" id="smart-calendar-settings" style="margin-top:16px">
    <?= Csrf::input() ?>
    <input type="hidden" name="tenant_id" value="<?= (int) $tenantId ?>">

    <div class="calendar-settings-grid <?= $isRsAdmin ? '' : 'single' ?>">
        <section class="card calendar-rule-card">
            <div class="section-heading">
                <div><span class="eyebrow">Configurações da agenda</span><h2>Regras de atendimento</h2></div>
                <span class="badge badge-info">Visível para a empresa</span>
            </div>

            <div class="onboarding-pre-agent-note">
                <strong>Origem da agenda</strong>
                <span>Escolha onde o assistente deve consultar disponibilidade. A Agenda interna usa somente compromissos e regras salvos no RS Connect; Google Agenda continua disponível sem apagar a integração existente.</span>
            </div>
            <div class="calendar-mode-grid" role="radiogroup" aria-label="Origem da agenda" data-calendar-source-choices>
                <label class="calendar-mode-card <?= $calendarSource === 'internal' ? 'is-selected' : '' ?>">
                    <input type="radio" name="calendar_source" value="internal" <?= $calendarSource === 'internal' ? 'checked' : '' ?>>
                    <span class="calendar-mode-icon" aria-hidden="true">✓</span>
                    <span><strong>Agenda interna do RS Connect</strong><small>Consulta automaticamente horários, bloqueios e compromissos diretamente no banco da plataforma. Não chama Google nem n8n.</small></span>
                </label>
                <label class="calendar-mode-card <?= $calendarSource === 'google' ? 'is-selected' : '' ?>">
                    <input type="radio" name="calendar_source" value="google" <?= $calendarSource === 'google' ? 'checked' : '' ?>>
                    <span class="calendar-mode-icon" aria-hidden="true">G</span>
                    <span><strong>Google Agenda</strong><small>Usa a integração n8n/Google já configurada para espaços livres ou eventos VAGO.</small></span>
                </label>
                <label class="calendar-mode-card <?= $calendarSource === 'none' ? 'is-selected' : '' ?>">
                    <input type="radio" name="calendar_source" value="none" <?= $calendarSource === 'none' ? 'checked' : '' ?>>
                    <span class="calendar-mode-icon" aria-hidden="true">—</span>
                    <span><strong>Não utilizar agenda</strong><small>O assistente não consulta nem sugere horários automaticamente.</small></span>
                </label>
            </div>

            <div class="calendar-toggle-stack" data-calendar-source-shared>
                <label class="switch-inline"><input type="checkbox" name="enabled" value="1" <?= !empty($settings['enabled']) ? 'checked' : '' ?>><span>Ativar busca automática de horários</span></label>
                <label class="switch-inline"><input type="checkbox" name="require_before_approval" value="1" <?= !empty($settings['require_before_approval']) ? 'checked' : '' ?>><span>Exigir horário validado antes de aprovar</span></label>
                <label class="switch-inline"><input type="checkbox" name="auto_request_on_pre_schedule" value="1" <?= !empty($settings['auto_request_on_pre_schedule']) ? 'checked' : '' ?> data-auto-request-toggle><span>Consultar automaticamente quando a IA identificar dia e horário</span></label>
            </div>

            <div data-calendar-source-panel="internal">
                <div class="section-heading compact" style="margin-top:16px"><div><span class="eyebrow">Agenda interna</span><h3>Como determinar os horários disponíveis?</h3><p>Escolha entre manter o cálculo atual ou oferecer somente vagas liberadas explicitamente pela equipe.</p></div><span class="badge badge-success">Sem Google</span></div>
                <div class="internal-strategy-grid" data-internal-strategy-choices>
                    <label class="calendar-mode-card <?= $internalStrategy === 'calculated' ? 'is-selected' : '' ?>">
                        <input type="radio" name="internal_availability_strategy" value="calculated" <?= $internalStrategy === 'calculated' ? 'checked' : '' ?>>
                        <span class="calendar-mode-icon" aria-hidden="true">∑</span>
                        <span><strong>Calcular pelos horários de trabalho</strong><small>Comportamento atual. Considera expediente menos compromissos e bloqueios.</small></span>
                    </label>
                    <label class="calendar-mode-card <?= $internalStrategy === 'published' ? 'is-selected' : '' ?>">
                        <input type="radio" name="internal_availability_strategy" value="published" <?= $internalStrategy === 'published' ? 'checked' : '' ?>>
                        <span class="calendar-mode-icon" aria-hidden="true">✓</span>
                        <span><strong>Oferecer somente horários liberados</strong><small>O agente só informa vagas publicadas manualmente para a empresa ou profissional.</small></span>
                    </label>
                </div>
                <div data-internal-strategy-panel="published">
                    <div class="calendar-inline-info"><strong>Fonte de verdade: vagas publicadas.</strong><span>Um espaço vazio no calendário não será considerado disponível. Primeiro libere os horários na aba “Disponibilidades”.</span></div>
                    <label class="switch-inline" style="margin-top:12px"><input type="checkbox" name="published_slots_respect_min_notice" value="1" <?= !array_key_exists('published_slots_respect_min_notice', $settings) || !empty($settings['published_slots_respect_min_notice']) ? 'checked' : '' ?>><span>Aplicar a antecedência mínima também aos horários liberados</span></label>
                    <small class="muted-text">Ativado: uma vaga publicada só pode ser oferecida depois da antecedência configurada. Desativado: publicar a vaga autoriza o agente a oferecê-la mesmo com antecedência menor, desde que o horário ainda seja futuro e esteja livre.</small>
                </div>
                <div data-internal-strategy-panel="calculated">
                <div class="internal-calendar-days">
                    <?php foreach ($internalHoursByDay as $dayNumber => $dayConfig): ?>
                        <div class="internal-calendar-day">
                            <label class="internal-day-toggle"><input type="checkbox" name="internal_days[]" value="<?= (int) $dayNumber ?>" <?= !empty($dayConfig['enabled']) ? 'checked' : '' ?>><span><?= View::e((string) $dayConfig['label']) ?></span></label>
                            <label class="field"><span>Início</span><input type="time" name="internal_start[<?= (int) $dayNumber ?>]" value="<?= View::e((string) $dayConfig['start']) ?>"></label>
                            <label class="field"><span>Fim</span><input type="time" name="internal_end[<?= (int) $dayNumber ?>]" value="<?= View::e((string) $dayConfig['end']) ?>"></label>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="calendar-inline-info"><strong>Fonte de verdade interna.</strong><span>Quando essa opção estiver ativa, pedidos como “quinta-feira às 14:00” são validados somente contra a Agenda do RS Connect. Nenhuma consulta é enviada ao Google.</span></div>
                </div>
            </div>

            <div class="field" data-calendar-source-panel="google">
                <label>Como a disponibilidade será encontrada no Google?</label>
                <select name="availability_mode" id="availability-mode">
                    <option value="free_slots" <?= $availabilityMode === 'free_slots' ? 'selected' : '' ?>>Buscar espaços livres no Google Agenda</option>
                    <option value="marked_events" <?= $availabilityMode === 'marked_events' ? 'selected' : '' ?>>Buscar eventos marcados como VAGO</option>
                </select>
            </div>

            <div class="field-grid two">
                <div class="field"><label>Duração do atendimento</label><div class="input-with-suffix"><input type="number" name="default_duration_minutes" min="15" max="240" value="<?= (int) ($settings['default_duration_minutes'] ?? 50) ?>"><span>min</span></div></div>
                <div class="field"><label>Início de uma opção para a próxima</label><div class="input-with-suffix"><input type="number" name="slot_interval_minutes" min="5" max="240" value="<?= (int) ($settings['slot_interval_minutes'] ?? 30) ?>"><span>min</span></div><small class="muted-text">Ex.: 60 oferece 08:00, 09:00, 10:00.</small></div>
            </div>
            <div class="field-grid two" data-calendar-source-panel="google">
                <div class="field"><label>Início do expediente</label><input type="time" name="working_start" value="<?= View::e($hours['start'] ?? '08:00') ?>"></div>
                <div class="field"><label>Fim do expediente</label><input type="time" name="working_end" value="<?= View::e($hours['end'] ?? '18:00') ?>"></div>
            </div>
            <div class="field" data-calendar-source-panel="google">
                <label>Dias de atendimento no Google</label>
                <div class="calendar-weekday-grid">
                    <?php foreach ([1 => 'Seg', 2 => 'Ter', 3 => 'Qua', 4 => 'Qui', 5 => 'Sex', 6 => 'Sáb', 0 => 'Dom'] as $day => $label): ?>
                        <label><input type="checkbox" name="workdays[]" value="<?= (int) $day ?>" <?= in_array((int) $day, $workdays, true) ? 'checked' : '' ?>><span><?= View::e($label) ?></span></label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="field-grid two">
                <div class="field"><label>Antecedência mínima</label><div class="input-with-suffix"><input type="number" name="min_notice_hours" min="0" max="720" value="<?= (int) ($settings['min_notice_hours'] ?? 4) ?>"><span>h</span></div><small class="muted-text">Ex.: 48h impede novos agendamentos para períodos que terminem antes de 48 horas a partir de agora. Em horários liberados, a regra pode ser desligada acima.</small></div>
                <div class="field"><label>Quantidade de sugestões</label><input type="number" name="max_suggestions" min="1" max="50" value="<?= (int) ($settings['max_suggestions'] ?? 5) ?>"></div>
            </div>
            <div class="field-grid two">
                <div class="field"><label>Buscar por quantos dias</label><div class="input-with-suffix"><input type="number" name="search_days_ahead" min="1" max="90" value="<?= (int) ($settings['search_days_ahead'] ?? 14) ?>"><span>dias</span></div></div>
                <div class="field"><label>Margem ao redor dos compromissos</label><div class="input-with-suffix"><input type="number" name="buffer_minutes" min="0" max="180" value="<?= (int) ($settings['buffer_minutes'] ?? 10) ?>"><span>min</span></div></div>
            </div>

            <div class="calendar-mode-panel" data-calendar-mode="free_slots" data-calendar-source-panel="google">
                <h3>Regras para espaços livres</h3>
                <label class="switch-inline"><input type="checkbox" name="ignore_transparent_events" value="1" <?= !empty($settings['ignore_transparent_events']) ? 'checked' : '' ?>><span>Eventos configurados como “Disponível” não bloqueiam o horário</span></label>
            </div>

            <div class="calendar-mode-panel" data-calendar-mode="marked_events" data-calendar-source-panel="google">
                <h3>Regras para eventos VAGO</h3>
                <div class="field-grid two">
                    <div class="field"><label>Títulos disponíveis online ou genéricos</label><input type="text" name="marked_online_title" value="<?= View::e($settings['marked_online_title'] ?? 'VAGO — ONLINE') ?>" placeholder="Ex.: Vago, Disponível, VAGO — ONLINE"></div>
                    <div class="field"><label>Títulos disponíveis presenciais ou repita os genéricos</label><input type="text" name="marked_in_person_title" value="<?= View::e($settings['marked_in_person_title'] ?? 'VAGO — PRESENCIAL') ?>" placeholder="Ex.: Vago, Disponível, VAGO — PRESENCIAL"></div>
                </div>
                <div class="calendar-inline-info">
                    <strong>Você pode informar mais de um título.</strong>
                    <span>Separe por vírgula, ponto e vírgula ou quebra de linha. Ex.: <b>Vago, Disponível</b>. Quando os mesmos títulos estiverem nos dois campos, a modalidade vem da conversa ou do pré-agendamento.</span>
                </div>
                <div class="calendar-inline-info">
                    <strong>Modalidade obrigatória antes da busca.</strong>
                    <span>O RS Connect pergunta <b>Online ou Presencial?</b> antes de consultar o Google. Online consulta somente títulos configurados para online; Presencial consulta somente os títulos presenciais. Sem modalidade definida, nenhuma busca é enviada ao n8n.</span>
                </div>
                <div class="field-grid two">
                    <div class="field"><label>Título ao pré-reservar</label><input type="text" name="marked_hold_prefix" value="<?= View::e($settings['marked_hold_prefix'] ?? 'PRÉ-RESERVADO') ?>"></div>
                    <div class="field"><label>Título ao confirmar</label><input type="text" name="marked_confirmed_prefix" value="<?= View::e($settings['marked_confirmed_prefix'] ?? 'AGENDADO') ?>"></div>
                </div>
                <div class="field"><label>Tempo de pré-reserva</label><div class="input-with-suffix"><input type="number" name="hold_minutes" min="5" max="1440" value="<?= (int) ($settings['hold_minutes'] ?? 30) ?>"><span>min</span></div></div>
                <label class="switch-inline"><input type="checkbox" name="marked_require_transparent" value="1" <?= !empty($settings['marked_require_transparent']) ? 'checked' : '' ?>><span>Além do título, exigir “Mostrar como: Disponível” no Google Agenda</span></label>
                <label class="switch-inline"><input type="checkbox" name="revalidate_before_update" value="1" <?= !empty($settings['revalidate_before_update']) ? 'checked' : '' ?>><span>Confirmar que o evento ainda está VAGO antes de reservar</span></label>
                <label class="switch-inline"><input type="checkbox" name="restore_on_cancel" value="1" <?= !empty($settings['restore_on_cancel']) ? 'checked' : '' ?>><span>Restaurar o evento para VAGO ao recusar, cancelar ou remarcar</span></label>
            </div>
        </section>

        <?php if ($isRsAdmin): ?>
            <aside class="card calendar-integration-card">
                <div class="section-heading"><div><span class="eyebrow">Somente RS Admin</span><h2>Integração n8n e Google</h2></div></div>

                <div class="calendar-diagnostic-grid">
                    <div class="calendar-diagnostic <?= !empty($integration['n8n_enabled']) ? 'is-ok' : 'is-warning' ?>"><strong>n8n</strong><span><?= !empty($integration['n8n_enabled']) ? 'Ativado' : 'Desativado' ?></span></div>
                    <div class="calendar-diagnostic <?= !empty($integration['active_url_configured']) ? 'is-ok' : 'is-error' ?>"><strong>URL do modo atual</strong><span><?= !empty($integration['active_url_configured']) ? 'Configurada' : 'Não configurada' ?></span></div>
                    <div class="calendar-diagnostic <?= !empty($integration['token_configured']) ? 'is-ok' : 'is-warning' ?>"><strong>Chave de segurança</strong><span><?= !empty($integration['token_configured']) ? 'Protegido' : 'Não informado' ?></span></div>
                    <div class="calendar-diagnostic <?= !empty($settings['calendar_event_webhook_url']) ? 'is-ok' : 'is-error' ?>"><strong>Ciclo do evento</strong><span><?= !empty($settings['calendar_event_webhook_url']) ? 'Configurado' : 'Não configurado' ?></span></div>
                    <div class="calendar-diagnostic <?= (($integration['last_status'] ?? '') === 'received') ? 'is-ok' : ((($integration['last_status'] ?? '') === 'failed') ? 'is-error' : 'is-warning') ?>"><strong>Última consulta</strong><span><?= View::e($statusLabels[$integration['last_status'] ?? ''] ?? (($integration['last_status'] ?? '') ?: 'Sem teste')) ?></span></div>
                </div>
                <?php if (!empty($integration['last_error'])): ?><div class="calendar-inline-alert"><?= View::e($integration['last_error']) ?></div><?php endif; ?>
                <?php if (!empty($integration['last_online_title']) || !empty($integration['last_in_person_title'])): ?>
                    <div class="calendar-admin-note">
                        <strong>Configuração usada na última busca</strong>
                        <p>
                            Online: <b><?= View::e($integration['last_online_title'] ?: 'não informado') ?></b> ·
                            Presencial: <b><?= View::e($integration['last_in_person_title'] ?: 'não informado') ?></b>
                            <?= !empty($integration['last_shared_title']) ? ' · título genérico compartilhado' : '' ?>
                            <?php if (!empty($integration['last_requested_modality'])): ?> · modalidade: <b><?= View::e($integration['last_requested_modality']) ?></b><?php endif; ?>
                        </p>
                        <?php if (!empty($integration['last_event_titles'])): ?>
                            <p>Títulos lidos no Google: <?= View::e(implode(' · ', array_slice($integration['last_event_titles'], 0, 8))) ?></p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="calendar-toggle-stack">
                    <label class="switch-inline"><input type="checkbox" name="use_n8n" value="1" <?= !empty($settings['use_n8n']) ? 'checked' : '' ?>><span>Usar n8n para consultar o Google Agenda</span></label>
                </div>

                <div class="field"><label>URL — fluxo Espaços livres</label><input type="url" name="free_slots_webhook_url" value="<?= View::e($settings['free_slots_webhook_url'] ?? '') ?>" placeholder="https://n8n.../webhook/..."></div>
                <div class="field"><label>URL — fluxo Eventos VAGO</label><input type="url" name="marked_events_webhook_url" value="<?= View::e($settings['marked_events_webhook_url'] ?? '') ?>" placeholder="https://n8n.../webhook/..."></div>
                <div class="field"><label>URL — criar, atualizar e remover eventos confirmados</label><input type="url" name="calendar_event_webhook_url" value="<?= View::e($settings['calendar_event_webhook_url'] ?? '') ?>" placeholder="https://n8n.../webhook/rsconnect-agenda-google-ciclo-completo"><small class="muted-text">Usada no modo Espaços livres depois que o horário é aprovado.</small></div>
                <div class="field"><label>Chave das atualizações automáticas</label><input type="password" name="secret_token" autocomplete="off" value="<?= View::e($settings['secret_token'] ?? '') ?>" placeholder="Mesmo token configurado no n8n"></div>
                <div class="field"><label>ID do calendário Google</label><input type="text" name="google_calendar_id" value="<?= View::e($settings['google_calendar_id'] ?? 'primary') ?>" placeholder="primary ou ID do calendário"></div>
                <div class="field-grid two">
                    <div class="field"><label>Timezone</label><input type="text" name="timezone" value="<?= View::e($settings['timezone'] ?? 'America/Sao_Paulo') ?>"></div>
                    <div class="field"><label>Offset</label><input type="text" name="google_utc_offset" value="<?= View::e($settings['google_utc_offset'] ?? '-03:00') ?>"></div>
                </div>
                <div class="calendar-mode-panel" data-calendar-mode="free_slots" data-calendar-source-panel="google">
                    <label class="switch-inline"><input type="checkbox" name="use_internal_fallback" value="1" <?= !empty($settings['use_internal_fallback']) ? 'checked' : '' ?>><span>Usar opção de apoio quando a automação n8n falhar</span></label>
                    <p class="muted-text">Durante os testes, desative a opção de apoio para não confundir horários locais com o retorno real do Google.</p>
                </div>
                <div class="calendar-lifecycle-settings">
                    <h3>Ciclo do compromisso confirmado</h3>
                    <label class="switch-inline"><input type="checkbox" name="create_google_event_on_confirm" value="1" <?= !empty($settings['create_google_event_on_confirm']) ? 'checked' : '' ?>><span>Criar evento no Google ao confirmar um horário livre</span></label>
                    <label class="switch-inline"><input type="checkbox" name="require_google_sync_on_confirm" value="1" <?= !empty($settings['require_google_sync_on_confirm']) ? 'checked' : '' ?>><span>Não concluir a aprovação se o Google não confirmar o evento</span></label>
                    <label class="switch-inline"><input type="checkbox" name="update_google_event_on_reschedule" value="1" <?= !empty($settings['update_google_event_on_reschedule']) ? 'checked' : '' ?>><span>Atualizar o mesmo evento quando o horário mudar</span></label>
                    <label class="switch-inline"><input type="checkbox" name="delete_google_event_on_cancel" value="1" <?= !empty($settings['delete_google_event_on_cancel']) ? 'checked' : '' ?>><span>Remover o evento ao cancelar, recusar ou remarcar</span></label>
                    <label class="switch-inline"><input type="checkbox" name="maintenance_enabled" value="1" <?= !empty($settings['maintenance_enabled']) ? 'checked' : '' ?>><span>Ativar manutenção automática da agenda</span></label>
                    <div class="field-grid two">
                        <div class="field"><label>Executar manutenção a cada</label><div class="input-with-suffix"><input type="number" name="maintenance_interval_minutes" min="5" max="1440" value="<?= (int) ($settings['maintenance_interval_minutes'] ?? 10) ?>"><span>min</span></div></div>
                        <div class="field"><label>Máximo de novas tentativas</label><input type="number" name="max_sync_attempts" min="1" max="10" value="<?= (int) ($settings['max_sync_attempts'] ?? 3) ?>"></div>
                    </div>
                </div>
                <div class="calendar-admin-note">
                    <strong>Teste recomendado</strong>
                    <p>Desative a opção de apoio, faça uma busca e confira a execução no n8n. No modo VAGO, o retorno precisa conter <code>google_event_id</code>. No modo Espaços livres, confirme um horário e valide a criação do evento pelo fluxo de ciclo completo.</p>
                </div>
            </aside>
        <?php endif; ?>
    </div>

    <div class="calendar-settings-submit">
        <button class="btn btn-primary" type="submit">Salvar configurações da agenda</button>
        <?php if (!$isRsAdmin): ?><small class="muted-text">As URLs, chaves de acesso e segurança são administrados pela equipe RS.</small><?php endif; ?>
    </div>
</form>
</details>
<?php endif; ?>

<?php if ($activeTab === 'availability'): ?>
<section class="card availability-workspace-card" id="horarios-liberados" style="margin-top:16px">
    <div class="section-heading availability-workspace-heading">
        <div>
            <span class="eyebrow">Disponibilidades</span>
            <h2>Horários que podem ser oferecidos</h2>
            <p>Gerencie vagas publicadas em uma tela operacional. Configurações de estratégia e regras ficam na aba “Configurações”.</p>
        </div>
        <div class="availability-heading-actions">
            <?php if ($calendarSource === 'internal'): ?>
                <span class="badge <?= $internalStrategy === 'published' ? 'badge-success' : 'badge-warning' ?>"><?= $internalStrategy === 'published' ? 'Usados pelo agente' : 'Modo calculado ativo' ?></span>
            <?php else: ?>
                <span class="badge badge-info"><?= View::e($calendarSourceLabels[$calendarSource] ?? 'Sem agenda') ?></span>
            <?php endif; ?>
            <a class="btn btn-small btn-quiet" href="<?= View::e($tabUrl('settings')) ?>">Configurações</a>
        </div>
    </div>

    <?php if ($calendarSource !== 'internal'): ?>
        <div class="availability-empty-source">
            <strong>A origem atual não é a Agenda interna.</strong>
            <p>Os horários publicados abaixo são usados somente quando a empresa escolhe a Agenda interna. Altere a origem na aba Configurações se quiser trabalhar com vagas liberadas.</p>
            <a class="btn btn-primary" href="<?= View::e($tabUrl('settings')) ?>">Revisar origem da agenda</a>
        </div>
    <?php else: ?>
        <?php if ($internalStrategy !== 'published'): ?>
            <div class="message-info availability-strategy-note">
                <strong>Os horários publicados ainda não são a fonte de verdade do agente.</strong>
                <span>A empresa continua calculando disponibilidade pela jornada. Para que o agente ofereça somente vagas liberadas, altere a estratégia na aba <b>Configurações</b>.</span>
            </div>
        <?php endif; ?>

        <div class="availability-status-strip" aria-label="Resumo dos horários publicados">
            <div><span>Disponíveis</span><strong><?= (int) ($publishedSlotStats['available'] ?? 0) ?></strong></div>
            <div><span>Pré-reservados</span><strong><?= (int) ($publishedSlotStats['held'] ?? 0) ?></strong></div>
            <div><span>Confirmados</span><strong><?= (int) ($publishedSlotStats['booked'] ?? 0) ?></strong></div>
            <div><span>Bloqueados</span><strong><?= (int) ($publishedSlotStats['blocked'] ?? 0) ?></strong></div>
        </div>

        <?php if ($canManage): ?>
        <section class="availability-publisher-panel">
            <div class="availability-panel-title">
                <div><span class="eyebrow">Nova disponibilidade</span><h3>Liberar horários</h3></div>
                <small>Defina uma faixa e o RS Connect cria somente as vagas concretas desse período.</small>
            </div>
            <form method="post" action="<?= View::e(Router::url('/calendar/availability/internal-slots/publish')) ?>" class="availability-publish-form form-stack">
                <?= Csrf::input() ?>
                <input type="hidden" name="tenant_id" value="<?= (int) $tenantId ?>">
                <div class="availability-form-section">
                    <strong>Quando</strong>
                    <div class="field-grid three">
                        <label class="field"><span>Data</span><input type="date" name="slot_date" min="<?= View::e(date('Y-m-d')) ?>" value="<?= View::e(date('Y-m-d', strtotime('+1 day'))) ?>" required></label>
                        <label class="field"><span>Início da faixa</span><input type="time" name="slot_start" value="08:00" required></label>
                        <label class="field"><span>Fim da faixa</span><input type="time" name="slot_end" value="12:00" required></label>
                    </div>
                </div>
                <div class="availability-form-section">
                    <strong>Como criar as vagas</strong>
                    <div class="field-grid three">
                        <label class="field"><span>Duração do atendimento</span><div class="input-with-suffix"><input type="number" name="slot_duration_minutes" min="15" max="240" value="<?= (int) ($settings['default_duration_minutes'] ?? 50) ?>"><span>min</span></div></label>
                        <label class="field"><span>Intervalo entre inícios</span><div class="input-with-suffix"><input type="number" name="slot_interval_minutes" min="5" max="240" value="<?= (int) ($settings['slot_interval_minutes'] ?? 30) ?>"><span>min</span></div><small class="muted-text">Ex.: 50 min de duração + 60 min de intervalo libera 14:00, 15:00, 16:00...</small></label>
                        <label class="field"><span>Repetir no mesmo dia da semana</span><div class="input-with-suffix"><input type="number" name="repeat_weeks" min="1" max="52" value="1"><span>sem.</span></div><small class="muted-text">1 = somente esta data.</small></label>
                    </div>
                </div>
                <div class="availability-form-section availability-form-section-last">
                    <strong>Contexto</strong>
                    <div class="field-grid three">
                        <label class="field"><span>Profissional</span><select name="owner_user_id"><option value="0">Disponibilidade geral da empresa</option><?php foreach ($professionalProfiles as $profile): ?><option value="<?= (int) ($profile['id'] ?? 0) ?>"><?= View::e((string) ($profile['name'] ?? 'Profissional')) ?></option><?php endforeach; ?></select></label>
                        <label class="field"><span>Modalidade</span><select name="modality"><option value="indefinida">Qualquer / não se aplica</option><option value="online">Online</option><option value="presencial">Presencial</option><option value="telefone">Telefone</option></select></label>
                        <label class="field"><span>Observação interna</span><input type="text" name="notes" maxlength="500" placeholder="Ex.: agenda aberta para avaliações"></label>
                    </div>
                </div>
                <div class="availability-publish-footer">
                    <p><strong>Exemplo:</strong> 14:00–18:00, duração 50 min e intervalo 60 min gera 14:00, 15:00, 16:00 e 17:00. O agente não inventa horários entre essas opções.</p>
                    <button class="btn btn-primary" type="submit">Publicar horários</button>
                </div>
            </form>
        </section>
        <?php endif; ?>

        <section class="availability-list-panel">
            <div class="availability-panel-title">
                <div><span class="eyebrow">Agenda publicada</span><h3>Horários liberados</h3></div>
                <small><?= count($visiblePublishedSlots) ?> horário(s) exibido(s)</small>
            </div>
            <form class="availability-filter-bar" method="get" action="<?= View::e(Router::url('/calendar')) ?>">
                <input type="hidden" name="section" value="availability">
                <input type="hidden" name="tab" value="availability">
                <?php if ($tenantId > 0): ?><input type="hidden" name="tenant_id" value="<?= (int) $tenantId ?>"><?php endif; ?>
                <label class="field"><span>De</span><input type="date" name="availability_from" value="<?= View::e($availabilityFromFilter) ?>"></label>
                <label class="field"><span>Até</span><input type="date" name="availability_to" value="<?= View::e($availabilityToFilter) ?>"></label>
                <label class="field"><span>Profissional</span><select name="availability_owner"><option value="0">Todos</option><?php foreach ($professionalProfiles as $profile): ?><option value="<?= (int) ($profile['id'] ?? 0) ?>" <?= $availabilityOwnerFilter === (int) ($profile['id'] ?? 0) ? 'selected' : '' ?>><?= View::e((string) ($profile['name'] ?? 'Profissional')) ?></option><?php endforeach; ?></select></label>
                <label class="field"><span>Modalidade</span><select name="availability_modality"><option value="all">Todas</option><option value="indefinida" <?= $availabilityModalityFilter === 'indefinida' ? 'selected' : '' ?>>Qualquer / não se aplica</option><option value="online" <?= $availabilityModalityFilter === 'online' ? 'selected' : '' ?>>Online</option><option value="presencial" <?= $availabilityModalityFilter === 'presencial' ? 'selected' : '' ?>>Presencial</option><option value="telefone" <?= $availabilityModalityFilter === 'telefone' ? 'selected' : '' ?>>Telefone</option></select></label>
                <label class="field"><span>Status</span><select name="availability_status"><option value="all">Todos</option><?php foreach ($publishedStatusLabels as $statusValue => $statusLabel): ?><option value="<?= View::e($statusValue) ?>" <?= $availabilityStatusFilter === $statusValue ? 'selected' : '' ?>><?= View::e($statusLabel) ?></option><?php endforeach; ?></select></label>
                <div class="availability-filter-actions"><button class="btn btn-secondary" type="submit">Filtrar</button><a class="btn btn-quiet" href="<?= View::e($tabUrl('availability')) ?>">Limpar</a></div>
            </form>

            <div class="availability-day-groups">
                <?php foreach ($publishedSlotsByDate as $slotDateKey => $daySlots): ?>
                    <?php
                        $dayTimestamp = strtotime($slotDateKey . ' 12:00:00');
                        $dayNames = [1 => 'Segunda-feira', 2 => 'Terça-feira', 3 => 'Quarta-feira', 4 => 'Quinta-feira', 5 => 'Sexta-feira', 6 => 'Sábado', 7 => 'Domingo'];
                        $dayLabel = ($dayNames[(int) date('N', $dayTimestamp)] ?? '') . ', ' . date('d/m/Y', $dayTimestamp);
                    ?>
                    <article class="availability-day-group">
                        <header><div><strong><?= View::e($dayLabel) ?></strong><small><?= count($daySlots) ?> horário(s)</small></div></header>
                        <div class="availability-slot-card-list">
                            <?php foreach ($daySlots as $publishedSlot): ?>
                                <?php
                                    $publishedStatus = (string) ($publishedSlot['status'] ?? 'available');
                                    $publishedModality = match ((string) ($publishedSlot['modality'] ?? 'indefinida')) { 'online' => 'Online', 'presencial' => 'Presencial', 'telefone' => 'Telefone', default => 'Qualquer modalidade' };
                                ?>
                                <div class="availability-slot-card">
                                    <div class="availability-slot-time"><strong><?= View::e($date($publishedSlot['starts_at'] ?? null, 'H:i')) ?></strong><span>até <?= View::e($date($publishedSlot['ends_at'] ?? null, 'H:i')) ?></span></div>
                                    <div class="availability-slot-details">
                                        <div class="availability-slot-badges"><span class="badge <?= View::e($publishedStatusClasses[$publishedStatus] ?? '') ?>"><?= View::e($publishedStatusLabels[$publishedStatus] ?? $publishedStatus) ?></span><span class="badge badge-info"><?= View::e($publishedModality) ?></span></div>
                                        <strong><?= View::e(($publishedSlot['owner_name'] ?? '') ?: 'Disponibilidade geral da empresa') ?></strong>
                                        <?php if (!empty($publishedSlot['notes'])): ?><small><?= View::e((string) $publishedSlot['notes']) ?></small><?php endif; ?>
                                        <?php if ($publishedStatus === 'held' && !empty($publishedSlot['hold_expires_at'])): ?><small>Pré-reserva até <?= View::e($date($publishedSlot['hold_expires_at'], 'H:i')) ?><?= !empty($publishedSlot['hold_appointment_title']) ? ' · ' . View::e((string) $publishedSlot['hold_appointment_title']) : '' ?></small><?php endif; ?>
                                    </div>
                                    <div class="availability-slot-actions">
                                        <?php if ($canManage && in_array($publishedStatus, ['available', 'blocked'], true)): ?>
                                            <form method="post" action="<?= View::e(Router::url('/calendar/availability/internal-slots/cancel')) ?>" onsubmit="return confirm('Remover este horário da disponibilidade?');">
                                                <?= Csrf::input() ?><input type="hidden" name="tenant_id" value="<?= (int) $tenantId ?>"><input type="hidden" name="slot_id" value="<?= (int) ($publishedSlot['id'] ?? 0) ?>"><button class="btn btn-small btn-quiet" type="submit">Remover</button>
                                            </form>
                                        <?php else: ?>
                                            <span class="muted-text">Sem ação disponível</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
                <?php if (!$publishedSlotsByDate): ?><div class="empty-state">Nenhum horário liberado corresponde aos filtros selecionados.</div><?php endif; ?>
            </div>
        </section>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($activeTab === 'settings' && $isRsAdmin): ?>
<section class="card calendar-maintenance-card" id="calendar-maintenance" style="margin-top:16px">
    <div class="section-heading">
        <div><span class="eyebrow">Automação da agenda</span><h2>Manutenção e ciclo do Google</h2></div>
        <div style="text-align:right">
            <form method="post" action="<?= View::e(Router::url('/calendar/maintenance/run')) ?>">
                <?= Csrf::input() ?>
                <input type="hidden" name="tenant_id" value="<?= (int) $tenantId ?>">
                <button class="btn btn-secondary" type="submit">Executar manutenção agora</button>
            </form>
            <small class="muted-text">Execução manual direta no RS Connect — não chama o n8n.</small>
        </div>
    </div>
    <div class="calendar-maintenance-grid">
        <div><span>Pré-reservas vencidas</span><strong><?= (int) ($maintenance['expired_holds'] ?? 0) ?></strong><small>eventos VAGO aguardando liberação</small></div>
        <div><span>Confirmados sem evento</span><strong><?= (int) ($maintenance['confirmed_without_event'] ?? 0) ?></strong><small>sincronização pendente</small></div>
        <div><span>Atualizações com problema</span><strong><?= (int) ($maintenance['failed_syncs'] ?? 0) ?></strong><small>novas tentativas limitadas</small></div>
        <div><span>Callbacks pendentes vencidos</span><strong><?= (int) ($maintenance['stale_requests'] ?? 0) ?></strong><small>sem resposta há mais de 30 minutos</small></div>
    </div>
    <?php
        $lastRun = $maintenance['last_run'] ?? null;
        $lastOrigin = (string) ($lastRun['origin'] ?? '');
        $originLabels = ['manual' => 'Manual', 'n8n' => 'n8n', 'cron' => 'Cron', 'webhook' => 'Automação externa'];
        $statusLabelsMaintenance = ['running' => 'Em execução', 'success' => 'Sucesso', 'partial' => 'Com avisos', 'failed' => 'Falhou'];
    ?>
    <div class="calendar-maintenance-footer" style="align-items:flex-start;gap:12px;flex-wrap:wrap">
        <span class="badge <?= !empty($maintenance['enabled']) ? 'badge-success' : 'badge-warning' ?>"><?= !empty($maintenance['enabled']) ? 'Rotina habilitada' : 'Rotina desativada' ?></span>
        <?php if ($lastRun): ?>
            <span>
                Última execução: <strong><?= View::e($date($lastRun['finished_at'] ?? $lastRun['started_at'] ?? null)) ?></strong>
                · <?= View::e($originLabels[$lastOrigin] ?? ($lastOrigin !== '' ? $lastOrigin : 'Origem não informada')) ?>
                · <?= View::e($statusLabelsMaintenance[(string) ($lastRun['status'] ?? '')] ?? (string) ($lastRun['status'] ?? '')) ?>
            </span>
        <?php else: ?>
            <span>Nenhuma execução registrada para esta empresa.</span>
        <?php endif; ?>
    </div>
    <?php if ($lastRun): ?>
        <div class="calendar-maintenance-grid" style="margin-top:10px">
            <div><span>Liberadas no último ciclo</span><strong><?= (int) ($lastRun['expired_holds_released'] ?? 0) ?></strong><small>pré-reservas vencidas</small></div>
            <div><span>Callbacks encerrados</span><strong><?= (int) ($lastRun['stale_requests_closed'] ?? 0) ?></strong><small>somente pendentes vencidos</small></div>
            <div><span>Atualizações tentadas</span><strong><?= (int) ($lastRun['syncs_retried'] ?? 0) ?></strong><small>novas tentativas no ciclo</small></div>
            <div><span>Erros no último ciclo</span><strong><?= (int) ($lastRun['errors_count'] ?? 0) ?></strong><small><?= (int) ($lastRun['errors_count'] ?? 0) === 0 ? 'execução sem erro' : 'requer revisão' ?></small></div>
        </div>
    <?php endif; ?>
    <div class="calendar-admin-note">
        <strong>Automático via n8n</strong>
        <p>A automação agenda uma chamada técnica para <code>POST /webhooks/calendar/maintenance/run</code> a cada 10 minutos e confirma o acesso pelo cabeçalho técnico <code>X-RS-Calendar-Maintenance-Token</code>. O botão acima é independente: executa a manutenção imediatamente no próprio RS Connect.</p>
    </div>
</section>
<?php endif; ?>


<?php if ($activeTab === 'settings' && $isRsAdmin): ?>
    <section class="card" style="margin-top:16px">
        <div class="section-heading"><div><span class="eyebrow">Diagnóstico RS</span><h2>Histórico das consultas</h2></div></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Data</th><th>Contato</th><th>Modo</th><th>Status</th><th>Preferência</th><th>Diagnóstico</th></tr></thead>
                <tbody>
                <?php foreach ($requests as $request): ?>
                    <tr>
                        <td><?= View::e($date($request['requested_at'] ?? $request['created_at'] ?? null)) ?></td>
                        <td><?= View::e(($request['contact_name'] ?? '') ?: ($request['appointment_title'] ?? '-')) ?></td>
                        <?php
                            $requestedPayload = json_decode((string) ($request['requested_payload_json'] ?? ''), true);
                            $requestCalendarSource = is_array($requestedPayload) ? (string) ($requestedPayload['calendar_source'] ?? '') : '';
                            $requestModeText = $requestCalendarSource === 'internal'
                                ? 'Agenda interna RS Connect'
                                : ($modeLabels[$request['availability_mode'] ?? 'free_slots'] ?? ($request['availability_mode'] ?? '-'));
                        ?>
                        <td><?= View::e($requestModeText) ?></td>
                        <td><span class="badge badge-<?= View::e(in_array($request['status'], ['received', 'sent'], true) ? 'success' : ($request['status'] === 'failed' ? 'danger' : 'warning')) ?>"><?= View::e($statusLabels[$request['status']] ?? $request['status']) ?></span></td>
                        <td><?= View::e(($request['preferred_day_text'] ?? '-') . ' · ' . ($request['preferred_time_text'] ?? '-')) ?></td>
                        <td><?= View::e($requestInsight($request)) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$requests): ?><tr><td colspan="6"><div class="empty-state">Nenhuma consulta registrada.</div></td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <?php if ($googleLogs): ?>
        <section class="card" style="margin-top:16px">
            <div class="section-heading"><div><span class="eyebrow">Integração Google</span><h2>Últimas operações</h2></div></div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Data</th><th>Agendamento</th><th>Operação</th><th>Status</th><th>Evento</th><th>Erro</th></tr></thead>
                    <tbody>
                    <?php foreach ($googleLogs as $log): ?>
                        <tr>
                            <td><?= View::e($date($log['created_at'] ?? null)) ?></td>
                            <td><?= View::e($log['appointment_title'] ?? ('#' . (int) ($log['appointment_id'] ?? 0))) ?></td>
                            <td><?= View::e($log['operation'] ?? '-') ?></td>
                            <td><span class="badge badge-<?= View::e(($log['status'] ?? '') === 'success' ? 'success' : 'danger') ?>"><?= View::e($log['status'] ?? '-') ?></span></td>
                            <td><?= View::e($log['google_event_id'] ?? '-') ?></td>
                            <td><?= View::e($log['error_message'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
<?php endif; ?>

<script>
(function () {
    const form = document.getElementById('smart-calendar-settings');
    if (!form) return;

    const modeSelect = document.getElementById('availability-mode');
    const sourceInputs = Array.from(form.querySelectorAll('input[name="calendar_source"]'));
    const sourcePanels = Array.from(form.querySelectorAll('[data-calendar-source-panel]'));
    const modePanels = Array.from(form.querySelectorAll('[data-calendar-mode]'));
    const sourceCards = Array.from(form.querySelectorAll('[data-calendar-source-choices] .calendar-mode-card'));
    const internalStrategyInputs = Array.from(form.querySelectorAll('input[name="internal_availability_strategy"]'));
    const internalStrategyPanels = Array.from(form.querySelectorAll('[data-internal-strategy-panel]'));
    const internalStrategyCards = Array.from(form.querySelectorAll('[data-internal-strategy-choices] .calendar-mode-card'));

    const refresh = () => {
        const checked = sourceInputs.find((input) => input.checked);
        const source = checked ? checked.value : 'none';
        const mode = modeSelect ? modeSelect.value : 'free_slots';
        const internalStrategyChecked = internalStrategyInputs.find((input) => input.checked);
        const internalStrategy = internalStrategyChecked ? internalStrategyChecked.value : 'calculated';
        const autoRequestToggle = form.querySelector('[data-auto-request-toggle]');

        // A Agenda interna é conversacional: ao escolhê-la, a consulta automática
        // precisa estar ligada. O backend também aplica a mesma regra para evitar
        // estados legados inconsistentes.
        if (source === 'internal' && autoRequestToggle) {
            autoRequestToggle.checked = true;
        }

        sourceCards.forEach((card) => {
            const input = card.querySelector('input[name="calendar_source"]');
            card.classList.toggle('is-selected', !!input && input.checked);
        });

        sourcePanels.forEach((panel) => {
            const sourceMatches = panel.getAttribute('data-calendar-source-panel') === source;
            const requiredMode = panel.getAttribute('data-calendar-mode');
            const modeMatches = !requiredMode || requiredMode === mode;
            panel.style.display = sourceMatches && modeMatches ? '' : 'none';
        });

        modePanels.forEach((panel) => {
            if (panel.hasAttribute('data-calendar-source-panel')) return;
            panel.style.display = panel.getAttribute('data-calendar-mode') === mode ? '' : 'none';
        });

        internalStrategyCards.forEach((card) => {
            const input = card.querySelector('input[name="internal_availability_strategy"]');
            card.classList.toggle('is-selected', !!input && input.checked);
        });
        internalStrategyPanels.forEach((panel) => {
            panel.style.display = panel.getAttribute('data-internal-strategy-panel') === internalStrategy ? '' : 'none';
        });
    };

    sourceInputs.forEach((input) => input.addEventListener('change', refresh));
    internalStrategyInputs.forEach((input) => input.addEventListener('change', refresh));
    if (modeSelect) modeSelect.addEventListener('change', refresh);
    refresh();
})();
</script>
