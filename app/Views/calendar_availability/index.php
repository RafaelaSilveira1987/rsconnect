<?php

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;

$settings = $settings ?? [];
$pending = $pending ?? [];
$requests = $requests ?? [];
$slots = $slots ?? [];
$googleLogs = $googleLogs ?? [];
$metrics = $metrics ?? [];
$integration = $integration ?? [];
$maintenance = $maintenance ?? [];
$professionalCalendarSettings = $professionalCalendarSettings ?? ['enabled' => false, 'require_owner' => true, 'auto_from_conversation' => false];
$professionalProfiles = $professionalProfiles ?? [];
$isRsAdmin = Auth::isSuperAdmin();
$availabilityMode = ($settings['availability_mode'] ?? 'free_slots') === 'marked_events' ? 'marked_events' : 'free_slots';
$workdays = json_decode((string) ($settings['workdays_json'] ?? '[]'), true);
$workdays = is_array($workdays) ? array_map('intval', $workdays) : [1, 2, 3, 4, 5];
$hours = json_decode((string) ($settings['working_hours_json'] ?? '{}'), true);
$hours = is_array($hours) ? $hours : ['start' => '08:00', 'end' => '18:00'];
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
    'internal_fallback' => 'Fallback interno',
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
    'free_slots' => 'Calcular espaços livres',
    'marked_events' => 'Usar eventos VAGO',
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
    $raw = json_decode((string) ($request['response_payload_json'] ?? ''), true);
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


<nav class="agenda-unified-tabs" aria-label="Áreas da agenda">
    <a class="agenda-unified-tab" href="<?= View::e(Router::url('/calendar' . ($tenantId > 0 ? '?tenant_id=' . (int) $tenantId : ''))) ?>">
        <span class="agenda-tab-icon" aria-hidden="true">1</span>
        <span><strong>Agenda</strong><small>Compromissos e pré-agendamentos</small></span>
    </a>
    <a class="agenda-unified-tab is-active" href="<?= View::e(Router::url('/calendar?section=availability' . ($tenantId > 0 ? '&tenant_id=' . (int) $tenantId : ''))) ?>">
        <span class="agenda-tab-icon" aria-hidden="true">2</span>
        <span><strong>Horários e regras</strong><small>Resultados e configurações</small></span>
    </a>
</nav>

<section class="hero-card operations-hero-clean calendar-smart-hero">
    <div>
        <span class="eyebrow">Agenda</span>
        <h2>Horários encontrados e regras de disponibilidade.</h2>
        <p><?= $isRsAdmin
            ? 'Configure a integração técnica do n8n separadamente das regras que o cliente utiliza no dia a dia.'
            : 'Veja primeiro os horários encontrados e abra as configurações somente quando precisar alterar as regras.' ?></p>
    </div>
    <div class="hero-actions operations-hero-actions">
        <a class="btn btn-quiet" href="<?= View::e(Router::url('/calendar?tenant_id=' . (int) $tenantId)) ?>">Ver compromissos</a>
        <?php if ($isRsAdmin): ?><a class="btn btn-primary" href="<?= View::e(Router::url('/n8n-templates')) ?>">Fluxos n8n</a><?php endif; ?>
        <span class="badge <?= !empty($settings['enabled']) ? 'badge-success' : 'badge-warning' ?>"><?= !empty($settings['enabled']) ? 'Busca automática ativa' : 'Busca automática desativada' ?></span>
    </div>
</section>

<?php if ($isRsAdmin): ?>
    <form class="toolbar-card" method="get" action="<?= View::e(Router::url('/calendar')) ?>"><input type="hidden" name="section" value="availability">
        <div class="field inline-field">
            <label>Empresa</label>
            <select name="tenant_id" onchange="this.form.submit()">
                <?php foreach ($tenants as $tenant): ?>
                    <option value="<?= (int) $tenant['id'] ?>" <?= (int) $tenant['id'] === (int) $tenantId ? 'selected' : '' ?>><?= View::e($tenant['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="btn btn-secondary" type="submit">Carregar</button>
    </form>
<?php endif; ?>

<div class="report-kpi-grid operations-kpis">
    <article class="card report-kpi"><span>Pré-agendamentos</span><strong><?= (int) ($metrics['pending'] ?? 0) ?></strong><small>Aguardando decisão</small></article>
    <article class="card report-kpi"><span>Horários atuais</span><strong><?= (int) ($metrics['slots'] ?? 0) ?></strong><small>Somente da última busca</small></article>
    <article class="card report-kpi"><span>Escolhidos</span><strong><?= (int) ($metrics['selected'] ?? 0) ?></strong><small>Aplicados ao pré-agendamento</small></article>
    <article class="card report-kpi"><span>Modo atual</span><strong class="calendar-mode-kpi"><?= View::e($availabilityMode === 'marked_events' ? 'VAGO' : 'Livres') ?></strong><small><?= View::e($modeLabels[$availabilityMode]) ?></small></article>
</div>

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
                                        <button class="btn btn-small btn-secondary" type="submit">Usar este horário</button>
                                    </form>
                                <?php elseif ($isMarkedSlot && in_array($eventState, ['held', 'confirmed'], true)): ?>
                                    <form method="post" action="<?= View::e(Router::url('/calendar/availability/release')) ?>">
                                        <?= Csrf::input() ?>
                                        <input type="hidden" name="tenant_id" value="<?= (int) $tenantId ?>">
                                        <input type="hidden" name="appointment_id" value="<?= (int) $slot['appointment_id'] ?>">
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
                            <input type="hidden" name="return_to" value="/calendar?section=availability&amp;tenant_id=<?= (int) $tenantId ?>">
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
                        <button class="btn btn-small btn-primary" type="submit">Buscar disponibilidade</button>
                    </form>
                </div>
            </article>
        <?php endforeach; ?>
        <?php if (!$pending): ?><div class="empty-state">Nenhum pré-agendamento pendente.</div><?php endif; ?>
    </div>
</section>

<details class="calendar-settings-disclosure professional-calendar-disclosure" id="agenda-profissionais" <?= !empty($professionalCalendarSettings['enabled']) ? 'open' : '' ?>>
    <summary>
        <span><span class="eyebrow">Equipe</span><strong>Agenda por profissional</strong><small>Horários individuais, calendário e bloqueio de conflitos por usuário.</small></span>
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
            </div>
            <div class="message-info"><strong>Nenhuma atribuição automática é obrigatória</strong><span>A conversa pode continuar sendo assumida manualmente. Esta terceira opção apenas reaproveita o responsável da conversa quando a IA cria um pré-agendamento.</span></div>
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

            <div class="calendar-toggle-stack">
                <label class="switch-inline"><input type="checkbox" name="enabled" value="1" <?= !empty($settings['enabled']) ? 'checked' : '' ?>><span>Ativar busca automática de horários</span></label>
                <label class="switch-inline"><input type="checkbox" name="require_before_approval" value="1" <?= !empty($settings['require_before_approval']) ? 'checked' : '' ?>><span>Exigir horário validado antes de aprovar</span></label>
                <label class="switch-inline"><input type="checkbox" name="auto_request_on_pre_schedule" value="1" <?= !empty($settings['auto_request_on_pre_schedule']) ? 'checked' : '' ?>><span>Consultar automaticamente quando a IA identificar dia e horário</span></label>
            </div>

            <div class="field">
                <label>Como a disponibilidade será encontrada?</label>
                <select name="availability_mode" id="availability-mode">
                    <option value="free_slots" <?= $availabilityMode === 'free_slots' ? 'selected' : '' ?>>Buscar espaços livres no Google Agenda</option>
                    <option value="marked_events" <?= $availabilityMode === 'marked_events' ? 'selected' : '' ?>>Buscar eventos marcados como VAGO</option>
                </select>
            </div>

            <div class="field-grid two">
                <div class="field"><label>Duração do atendimento</label><div class="input-with-suffix"><input type="number" name="default_duration_minutes" min="15" max="240" value="<?= (int) ($settings['default_duration_minutes'] ?? 50) ?>"><span>min</span></div></div>
                <div class="field"><label>Início de uma opção para a próxima</label><div class="input-with-suffix"><input type="number" name="slot_interval_minutes" min="5" max="240" value="<?= (int) ($settings['slot_interval_minutes'] ?? 30) ?>"><span>min</span></div><small class="muted-text">Ex.: 60 oferece 08:00, 09:00, 10:00.</small></div>
            </div>
            <div class="field-grid two">
                <div class="field"><label>Início do expediente</label><input type="time" name="working_start" value="<?= View::e($hours['start'] ?? '08:00') ?>"></div>
                <div class="field"><label>Fim do expediente</label><input type="time" name="working_end" value="<?= View::e($hours['end'] ?? '18:00') ?>"></div>
            </div>
            <div class="field">
                <label>Dias de atendimento</label>
                <div class="calendar-weekday-grid">
                    <?php foreach ([1 => 'Seg', 2 => 'Ter', 3 => 'Qua', 4 => 'Qui', 5 => 'Sex', 6 => 'Sáb', 0 => 'Dom'] as $day => $label): ?>
                        <label><input type="checkbox" name="workdays[]" value="<?= (int) $day ?>" <?= in_array((int) $day, $workdays, true) ? 'checked' : '' ?>><span><?= View::e($label) ?></span></label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="field-grid two">
                <div class="field"><label>Antecedência mínima</label><div class="input-with-suffix"><input type="number" name="min_notice_hours" min="0" max="720" value="<?= (int) ($settings['min_notice_hours'] ?? 4) ?>"><span>h</span></div></div>
                <div class="field"><label>Quantidade de sugestões</label><input type="number" name="max_suggestions" min="1" max="50" value="<?= (int) ($settings['max_suggestions'] ?? 5) ?>"></div>
            </div>
            <div class="field-grid two">
                <div class="field"><label>Buscar por quantos dias</label><div class="input-with-suffix"><input type="number" name="search_days_ahead" min="1" max="90" value="<?= (int) ($settings['search_days_ahead'] ?? 14) ?>"><span>dias</span></div></div>
                <div class="field"><label>Margem ao redor dos compromissos</label><div class="input-with-suffix"><input type="number" name="buffer_minutes" min="0" max="180" value="<?= (int) ($settings['buffer_minutes'] ?? 10) ?>"><span>min</span></div></div>
            </div>

            <div class="calendar-mode-panel" data-calendar-mode="free_slots">
                <h3>Regras para espaços livres</h3>
                <label class="switch-inline"><input type="checkbox" name="ignore_transparent_events" value="1" <?= !empty($settings['ignore_transparent_events']) ? 'checked' : '' ?>><span>Eventos configurados como “Disponível” não bloqueiam o horário</span></label>
            </div>

            <div class="calendar-mode-panel" data-calendar-mode="marked_events">
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
                    <div class="calendar-diagnostic <?= !empty($integration['token_configured']) ? 'is-ok' : 'is-warning' ?>"><strong>Token</strong><span><?= !empty($integration['token_configured']) ? 'Protegido' : 'Não informado' ?></span></div>
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
                <div class="field"><label>Token dos webhooks</label><input type="password" name="secret_token" autocomplete="off" value="<?= View::e($settings['secret_token'] ?? '') ?>" placeholder="Mesmo token configurado no n8n"></div>
                <div class="field"><label>ID do calendário Google</label><input type="text" name="google_calendar_id" value="<?= View::e($settings['google_calendar_id'] ?? 'primary') ?>" placeholder="primary ou ID do calendário"></div>
                <div class="field-grid two">
                    <div class="field"><label>Timezone</label><input type="text" name="timezone" value="<?= View::e($settings['timezone'] ?? 'America/Sao_Paulo') ?>"></div>
                    <div class="field"><label>Offset</label><input type="text" name="google_utc_offset" value="<?= View::e($settings['google_utc_offset'] ?? '-03:00') ?>"></div>
                </div>
                <div class="calendar-mode-panel" data-calendar-mode="free_slots">
                    <label class="switch-inline"><input type="checkbox" name="use_internal_fallback" value="1" <?= !empty($settings['use_internal_fallback']) ? 'checked' : '' ?>><span>Usar fallback interno quando o n8n falhar</span></label>
                    <p class="muted-text">Durante os testes, desative o fallback para não confundir horários locais com o retorno real do Google.</p>
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
                    <p>Desative o fallback, faça uma busca e confira a execução no n8n. No modo VAGO, o retorno precisa conter <code>google_event_id</code>. No modo Espaços livres, confirme um horário e valide a criação do evento pelo fluxo de ciclo completo.</p>
                </div>
            </aside>
        <?php endif; ?>
    </div>

    <div class="calendar-settings-submit">
        <button class="btn btn-primary" type="submit">Salvar configurações da agenda</button>
        <?php if (!$isRsAdmin): ?><small class="muted-text">As URLs, credenciais e tokens são administrados pela equipe RS.</small><?php endif; ?>
    </div>
</form>
</details>

<?php if ($isRsAdmin): ?>
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
        <div><span>Sincronizações com falha</span><strong><?= (int) ($maintenance['failed_syncs'] ?? 0) ?></strong><small>novas tentativas limitadas</small></div>
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
            <div><span>Sincronizações tentadas</span><strong><?= (int) ($lastRun['syncs_retried'] ?? 0) ?></strong><small>novas tentativas no ciclo</small></div>
            <div><span>Erros no último ciclo</span><strong><?= (int) ($lastRun['errors_count'] ?? 0) ?></strong><small><?= (int) ($lastRun['errors_count'] ?? 0) === 0 ? 'execução sem erro' : 'requer revisão' ?></small></div>
        </div>
    <?php endif; ?>
    <div class="calendar-admin-note">
        <strong>Automático via n8n</strong>
        <p>O workflow agenda a chamada <code>POST /webhooks/calendar/maintenance/run</code> a cada 10 minutos e autentica pelo header <code>X-RS-Calendar-Maintenance-Token</code>. O botão acima é independente: executa a manutenção imediatamente no próprio RS Connect.</p>
    </div>
</section>
<?php endif; ?>


<?php if ($isRsAdmin): ?>
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
                        <td><?= View::e($modeLabels[$request['availability_mode'] ?? 'free_slots'] ?? ($request['availability_mode'] ?? '-')) ?></td>
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
    const select = document.getElementById('availability-mode');
    const panels = document.querySelectorAll('[data-calendar-mode]');
    if (!select || !panels.length) return;

    const refresh = () => {
        panels.forEach((panel) => {
            panel.style.display = panel.getAttribute('data-calendar-mode') === select.value ? '' : 'none';
        });
    };

    select.addEventListener('change', refresh);
    refresh();
})();
</script>
