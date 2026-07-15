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
    'empty' => 'Sem horários',
    'failed' => 'Falhou',
    'requested' => 'Solicitado',
    'hold_requested' => 'Pré-reserva solicitada',
    'slot_selected' => 'Horário escolhido',
    'validated' => 'Validado',
];
$sourceLabels = [
    'google_free_slots' => 'Espaços livres do Google',
    'google_marked_slots' => 'Eventos VAGO do Google',
    'internal_fallback' => 'Fallback interno',
    'n8n' => 'n8n',
];
$eventStateLabels = [
    'available' => 'Disponível',
    'selected' => 'Escolhido',
    'held' => 'Pré-reservado',
    'confirmed' => 'Confirmado',
    'released' => 'Liberado',
    'error' => 'Erro',
];
$modeLabels = [
    'free_slots' => 'Calcular espaços livres',
    'marked_events' => 'Usar eventos VAGO',
];
?>

<section class="hero-card operations-hero-clean">
    <div>
        <span class="eyebrow">Agenda inteligente</span>
        <h2>Disponibilidade real no Google Agenda.</h2>
        <p>Escolha por empresa entre calcular as lacunas da agenda ou trabalhar com eventos previamente marcados como VAGO — ONLINE e VAGO — PRESENCIAL.</p>
    </div>
    <div class="hero-actions operations-hero-actions">
        <a class="btn btn-primary" href="<?= View::e(Router::url('/n8n-templates')) ?>">Baixar fluxos n8n</a>
        <a class="btn btn-quiet" href="<?= View::e(Router::url('/calendar?tenant_id=' . (int) $tenantId)) ?>">Abrir agenda</a>
        <span class="badge <?= !empty($settings['enabled']) ? 'badge-success' : 'badge-warning' ?>"><?= !empty($settings['enabled']) ? 'Agenda inteligente ativa' : 'Configuração pendente' ?></span>
    </div>
</section>

<?php if (Auth::isSuperAdmin()): ?>
    <form class="toolbar-card" method="get" action="<?= View::e(Router::url('/agenda-inteligente')) ?>">
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
    <article class="card report-kpi"><span>Pré-agendamentos</span><strong><?= (int) ($metrics['pending'] ?? 0) ?></strong><small>Aguardando validação</small></article>
    <article class="card report-kpi"><span>Consultas</span><strong><?= (int) ($metrics['requests'] ?? 0) ?></strong><small>Histórico recente</small></article>
    <article class="card report-kpi"><span>Horários</span><strong><?= (int) ($metrics['slots'] ?? 0) ?></strong><small>Retornados pelos fluxos</small></article>
    <article class="card report-kpi"><span>Escolhidos</span><strong><?= (int) ($metrics['selected'] ?? 0) ?></strong><small><?= (int) ($metrics['held'] ?? 0) ?> pré-reservado(s) no Google</small></article>
</div>

<div class="operations-grid" style="margin-top:16px">
    <section class="card">
        <div class="section-heading"><div><span class="eyebrow">Pré-agendamentos</span><h2>Buscar disponibilidade</h2></div></div>
        <div class="operations-check-list">
            <?php foreach ($pending as $appointment): ?>
                <?php
                    $availabilityStatus = (string) ($appointment['availability_status'] ?? '');
                    $googleState = (string) ($appointment['google_event_state'] ?? '');
                    $isReady = $availabilityStatus === 'slot_selected' && (($appointment['availability_source'] ?? '') !== 'google_marked_slots' || in_array($googleState, ['held', 'confirmed'], true));
                ?>
                <article class="operations-check is-<?= $isReady ? 'ok' : 'warning' ?>">
                    <div>
                        <strong><?= View::e($appointment['title'] ?? 'Pré-agendamento') ?></strong>
                        <p><?= View::e(($appointment['contact_name'] ?? '') ?: ($appointment['phone'] ?? 'Sem contato')) ?></p>
                        <small>Preferência: <?= View::e(($appointment['preferred_day_text'] ?? '') ?: 'dia não informado') ?> · <?= View::e(($appointment['preferred_time_text'] ?? '') ?: 'horário não informado') ?></small>
                        <small>Disponibilidade: <?= View::e($statusLabels[$availabilityStatus] ?? ($availabilityStatus ?: 'não consultada')) ?></small>
                        <?php if (($appointment['availability_source'] ?? '') === 'google_marked_slots'): ?>
                            <small>Evento Google: <?= View::e($eventStateLabels[$googleState] ?? ($googleState ?: 'não vinculado')) ?><?= !empty($appointment['google_event_summary']) ? ' · ' . View::e($appointment['google_event_summary']) : '' ?></small>
                        <?php endif; ?>
                        <?php if (!empty($appointment['availability_error'])): ?><small class="text-danger"><?= View::e($appointment['availability_error']) ?></small><?php endif; ?>
                    </div>
                    <form method="post" action="<?= View::e(Router::url('/calendar/availability/request')) ?>">
                        <?= Csrf::input() ?>
                        <input type="hidden" name="tenant_id" value="<?= (int) $tenantId ?>">
                        <input type="hidden" name="appointment_id" value="<?= (int) $appointment['id'] ?>">
                        <button class="btn btn-small btn-primary" type="submit">Buscar disponibilidade</button>
                    </form>
                </article>
            <?php endforeach; ?>
            <?php if (!$pending): ?><div class="empty-state">Nenhum pré-agendamento pendente para validar disponibilidade.</div><?php endif; ?>
        </div>
    </section>

    <aside class="card">
        <div class="section-heading"><div><span class="eyebrow">Configuração por empresa</span><h2>Regras e modo de validação</h2></div></div>
        <form class="operations-form" method="post" action="<?= View::e(Router::url('/calendar/availability/settings')) ?>" id="smart-calendar-settings">
            <?= Csrf::input() ?>
            <input type="hidden" name="tenant_id" value="<?= (int) $tenantId ?>">

            <label class="switch-inline"><input type="checkbox" name="enabled" value="1" <?= !empty($settings['enabled']) ? 'checked' : '' ?>><span>Ativar Agenda inteligente para esta empresa</span></label>
            <label class="switch-inline"><input type="checkbox" name="require_before_approval" value="1" <?= !empty($settings['require_before_approval']) ? 'checked' : '' ?>><span>Exigir horário validado antes de aprovar</span></label>
            <label class="switch-inline"><input type="checkbox" name="auto_request_on_pre_schedule" value="1" <?= !empty($settings['auto_request_on_pre_schedule']) ? 'checked' : '' ?>><span>Consultar automaticamente quando a IA capturar dia e horário</span></label>
            <label class="switch-inline"><input type="checkbox" name="use_n8n" value="1" <?= !empty($settings['use_n8n']) ? 'checked' : '' ?>><span>Usar n8n para consultar o Google Agenda</span></label>

            <div class="field">
                <label>Modo de disponibilidade</label>
                <select name="availability_mode" id="availability-mode">
                    <option value="free_slots" <?= $availabilityMode === 'free_slots' ? 'selected' : '' ?>>Calcular espaços livres da agenda</option>
                    <option value="marked_events" <?= $availabilityMode === 'marked_events' ? 'selected' : '' ?>>Usar eventos marcados como VAGO</option>
                </select>
                <small class="muted-text">A empresa pode trocar o modo sem alterar a tela de pré-agendamentos.</small>
            </div>

            <div class="calendar-mode-panel" data-calendar-mode="free_slots">
                <div class="field"><label>URL de produção — fluxo Espaços livres</label><input type="url" name="free_slots_webhook_url" value="<?= View::e($settings['free_slots_webhook_url'] ?? '') ?>" placeholder="https://n8n.../webhook/rsconnect-agenda-google-espacos-livres"></div>
                <label class="switch-inline"><input type="checkbox" name="ignore_transparent_events" value="1" <?= !empty($settings['ignore_transparent_events']) ? 'checked' : '' ?>><span>Eventos definidos como Disponível/transparent não bloqueiam o horário</span></label>
                <label class="switch-inline"><input type="checkbox" name="use_internal_fallback" value="1" <?= !empty($settings['use_internal_fallback']) ? 'checked' : '' ?>><span>Calcular no RS Connect se o n8n não responder</span></label>
                <p class="muted-text">Neste modo, o fluxo lê os compromissos ocupados e devolve as lacunas dentro do expediente configurado.</p>
            </div>

            <div class="calendar-mode-panel" data-calendar-mode="marked_events">
                <div class="field"><label>URL de produção — fluxo Eventos VAGO</label><input type="url" name="marked_events_webhook_url" value="<?= View::e($settings['marked_events_webhook_url'] ?? '') ?>" placeholder="https://n8n.../webhook/rsconnect-agenda-google-eventos-vago"></div>
                <div class="field-grid two">
                    <div class="field"><label>Título online</label><input type="text" name="marked_online_title" value="<?= View::e($settings['marked_online_title'] ?? 'VAGO — ONLINE') ?>"></div>
                    <div class="field"><label>Título presencial</label><input type="text" name="marked_in_person_title" value="<?= View::e($settings['marked_in_person_title'] ?? 'VAGO — PRESENCIAL') ?>"></div>
                </div>
                <div class="field-grid two">
                    <div class="field"><label>Prefixo da pré-reserva</label><input type="text" name="marked_hold_prefix" value="<?= View::e($settings['marked_hold_prefix'] ?? 'PRÉ-RESERVADO') ?>"></div>
                    <div class="field"><label>Prefixo do confirmado</label><input type="text" name="marked_confirmed_prefix" value="<?= View::e($settings['marked_confirmed_prefix'] ?? 'AGENDADO') ?>"></div>
                </div>
                <div class="field"><label>Validade visual da pré-reserva em minutos</label><input type="number" name="hold_minutes" min="5" max="1440" value="<?= (int) ($settings['hold_minutes'] ?? 30) ?>"></div>
                <label class="switch-inline"><input type="checkbox" name="marked_require_transparent" value="1" <?= !empty($settings['marked_require_transparent']) ? 'checked' : '' ?>><span>Aceitar somente eventos VAGO definidos como Disponível/transparent</span></label>
                <label class="switch-inline"><input type="checkbox" name="revalidate_before_update" value="1" <?= !empty($settings['revalidate_before_update']) ? 'checked' : '' ?>><span>Reler e validar o evento antes de alterar</span></label>
                <label class="switch-inline"><input type="checkbox" name="restore_on_cancel" value="1" <?= !empty($settings['restore_on_cancel']) ? 'checked' : '' ?>><span>Restaurar o título VAGO ao recusar, cancelar ou remarcar</span></label>
                <p class="muted-text">Ao escolher um horário, o evento vira PRÉ-RESERVADO. Ao aprovar, vira AGENDADO. Em cancelamento ou recusa, volta para VAGO.</p>
            </div>

            <div class="field"><label>ID do calendário Google</label><input type="text" name="google_calendar_id" value="<?= View::e($settings['google_calendar_id'] ?? 'primary') ?>" placeholder="primary ou e-mail/ID do calendário"></div>
            <div class="field"><label>Token para proteger os webhooks n8n</label><input type="password" name="secret_token" autocomplete="off" value="<?= View::e($settings['secret_token'] ?? '') ?>" placeholder="Opcional, mas recomendado"></div>
            <div class="field-grid two">
                <div class="field"><label>Timezone</label><input type="text" name="timezone" value="<?= View::e($settings['timezone'] ?? 'America/Sao_Paulo') ?>"></div>
                <div class="field"><label>Offset usado no fluxo</label><input type="text" name="google_utc_offset" value="<?= View::e($settings['google_utc_offset'] ?? '-03:00') ?>" placeholder="-03:00"></div>
            </div>
            <div class="field-grid two">
                <div class="field"><label>Duração do atendimento</label><input type="number" name="default_duration_minutes" min="15" max="240" value="<?= (int) ($settings['default_duration_minutes'] ?? 50) ?>"></div>
                <div class="field"><label>Intervalo entre opções de início</label><input type="number" name="slot_interval_minutes" min="5" max="240" value="<?= (int) ($settings['slot_interval_minutes'] ?? 30) ?>"></div>
            </div>
            <div class="field-grid two">
                <div class="field"><label>Buffer ao redor dos compromissos</label><input type="number" name="buffer_minutes" min="0" max="180" value="<?= (int) ($settings['buffer_minutes'] ?? 10) ?>"></div>
                <div class="field"><label>Antecedência mínima em horas</label><input type="number" name="min_notice_hours" min="0" max="720" value="<?= (int) ($settings['min_notice_hours'] ?? 4) ?>"></div>
            </div>
            <div class="field-grid two">
                <div class="field"><label>Buscar próximos dias</label><input type="number" name="search_days_ahead" min="1" max="90" value="<?= (int) ($settings['search_days_ahead'] ?? 14) ?>"></div>
                <div class="field"><label>Máximo de horários retornados</label><input type="number" name="max_suggestions" min="1" max="200" value="<?= (int) ($settings['max_suggestions'] ?? 5) ?>"></div>
            </div>
            <div class="field-grid two">
                <div class="field"><label>Início do expediente</label><input type="time" name="working_start" value="<?= View::e($hours['start'] ?? '08:00') ?>"></div>
                <div class="field"><label>Fim do expediente</label><input type="time" name="working_end" value="<?= View::e($hours['end'] ?? '18:00') ?>"></div>
            </div>
            <div class="field"><label>Dias de atendimento</label><div class="checkbox-grid compact">
                <?php foreach ([1 => 'Seg', 2 => 'Ter', 3 => 'Qua', 4 => 'Qui', 5 => 'Sex', 6 => 'Sáb', 0 => 'Dom'] as $day => $label): ?>
                    <label><input type="checkbox" name="workdays[]" value="<?= (int) $day ?>" <?= in_array((int) $day, $workdays, true) ? 'checked' : '' ?>> <?= View::e($label) ?></label>
                <?php endforeach; ?>
            </div></div>
            <button class="btn btn-primary btn-block" type="submit">Salvar configuração</button>
        </form>
    </aside>
</div>

<div class="operations-grid" style="margin-top:16px">
    <section class="card">
        <div class="section-heading"><div><span class="eyebrow">Horários disponíveis</span><h2>Opções retornadas</h2></div></div>
        <div class="operations-check-list">
            <?php foreach ($slots as $slot): ?>
                <?php
                    $eventState = (string) ($slot['event_state'] ?? 'available');
                    $isSelected = !empty($slot['selected_at']);
                    $isMarkedSlot = ($slot['source'] ?? '') === 'google_marked_slots' || !empty($slot['google_event_id']);
                ?>
                <article class="operations-check <?= $isSelected ? 'is-ok' : '' ?>">
                    <div>
                        <strong><?= View::e($slot['label'] ?: $date($slot['starts_at'])) ?></strong>
                        <p><?= View::e(($slot['contact_name'] ?? '') ?: ($slot['appointment_title'] ?? 'Agendamento')) ?></p>
                        <small><?= View::e($date($slot['starts_at'])) ?> até <?= View::e($date($slot['ends_at'], 'H:i')) ?> · <?= View::e($sourceLabels[$slot['source'] ?? ''] ?? ($slot['source'] ?? 'n8n')) ?></small>
                        <?php if (($slot['modality'] ?? 'indefinida') !== 'indefinida'): ?><small>Modalidade: <?= View::e(ucfirst((string) $slot['modality'])) ?></small><?php endif; ?>
                        <?php if ($isMarkedSlot): ?>
                            <small>Evento: <?= View::e($eventStateLabels[$eventState] ?? $eventState) ?><?= !empty($slot['event_summary']) ? ' · ' . View::e($slot['event_summary']) : '' ?></small>
                        <?php endif; ?>
                    </div>
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
                </article>
            <?php endforeach; ?>
            <?php if (!$slots): ?><div class="empty-state">Nenhum horário retornado ainda. Faça uma busca de disponibilidade.</div><?php endif; ?>
        </div>
    </section>

    <section class="card">
        <div class="section-heading"><div><span class="eyebrow">Histórico</span><h2>Consultas de disponibilidade</h2></div></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Data</th><th>Contato</th><th>Modo</th><th>Status</th><th>Preferência</th><th>Erro</th></tr></thead>
                <tbody>
                <?php foreach ($requests as $request): ?>
                    <tr>
                        <td><?= View::e($date($request['requested_at'] ?? $request['created_at'] ?? null)) ?></td>
                        <td><?= View::e(($request['contact_name'] ?? '') ?: ($request['appointment_title'] ?? '-')) ?></td>
                        <td><?= View::e($modeLabels[$request['availability_mode'] ?? 'free_slots'] ?? ($request['availability_mode'] ?? '-')) ?></td>
                        <td><span class="badge badge-<?= View::e(in_array($request['status'], ['received', 'sent'], true) ? 'success' : ($request['status'] === 'failed' ? 'danger' : 'warning')) ?>"><?= View::e($statusLabels[$request['status']] ?? $request['status']) ?></span></td>
                        <td><?= View::e(($request['preferred_day_text'] ?? '-') . ' · ' . ($request['preferred_time_text'] ?? '-')) ?></td>
                        <td><?= View::e($request['error_message'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$requests): ?><tr><td colspan="6"><div class="empty-state">Nenhuma consulta registrada.</div></td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

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

<section class="card" style="margin-top:16px">
    <div class="section-heading"><div><span class="eyebrow">Fluxos n8n</span><h2>Como funciona cada modo</h2></div></div>
    <div class="field-grid two">
        <div>
            <strong>Espaços livres</strong>
            <p class="muted-text">O fluxo lista os compromissos do Google, ignora eventos transparent quando configurado, aplica expediente, duração, intervalo e buffer e devolve somente os horários sem conflito.</p>
        </div>
        <div>
            <strong>Eventos VAGO</strong>
            <p class="muted-text">O fluxo busca os títulos configurados, retorna o google_event_id e atualiza o mesmo evento para PRÉ-RESERVADO, AGENDADO ou novamente VAGO.</p>
        </div>
    </div>
    <pre class="codebox">Callback: POST <?= View::e(Router::url('/webhooks/calendar/availability')) ?>?token=REQUEST_TOKEN

Busca:
{"event":"calendar.availability.result","request_id":1,"request_token":"REQUEST_TOKEN","source":"google_marked_slots","slots":[...]}

Atualização:
{"event":"calendar.marked_slot.updated","action":"hold","state":"held","request_id":1,"request_token":"REQUEST_TOKEN","google_event_id":"..."}</pre>
</section>

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
