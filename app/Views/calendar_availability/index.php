<?php

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;

$settings = $settings ?? [];
$pending = $pending ?? [];
$requests = $requests ?? [];
$slots = $slots ?? [];
$metrics = $metrics ?? [];
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
    'slot_selected' => 'Horário escolhido',
];
?>

<section class="hero-card operations-hero-clean">
    <div>
        <span class="eyebrow">Agenda inteligente</span>
        <h2>Disponibilidade real antes da aprovação.</h2>
        <p>Conecte o pré-agendamento ao n8n para buscar horários vagos no Google Calendar ou em outra agenda externa antes de confirmar com o cliente.</p>
    </div>
    <div class="hero-actions operations-hero-actions">
        <a class="btn btn-primary" href="<?= View::e(Router::url('/n8n-templates')) ?>">Baixar template n8n</a>
        <a class="btn btn-quiet" href="<?= View::e(Router::url('/calendar?tenant_id=' . (int) $tenantId)) ?>">Abrir agenda</a>
        <span class="badge <?= !empty($settings['enabled']) ? 'badge-success' : 'badge-warning' ?>"><?= !empty($settings['enabled']) ? 'Disponibilidade ativa' : 'Configuração pendente' ?></span>
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
    <article class="card report-kpi"><span>Consultas enviadas</span><strong><?= (int) ($metrics['requests'] ?? 0) ?></strong><small>Histórico recente</small></article>
    <article class="card report-kpi"><span>Horários retornados</span><strong><?= (int) ($metrics['slots'] ?? 0) ?></strong><small>Recebidos do n8n/fallback</small></article>
    <article class="card report-kpi"><span>Horários escolhidos</span><strong><?= (int) ($metrics['selected'] ?? 0) ?></strong><small>Aplicados à agenda</small></article>
</div>

<div class="operations-grid" style="margin-top:16px">
    <section class="card">
        <div class="section-heading"><div><span class="eyebrow">Pré-agendamentos</span><h2>Buscar disponibilidade</h2></div></div>
        <div class="operations-check-list">
            <?php foreach ($pending as $appointment): ?>
                <article class="operations-check is-<?= View::e(($appointment['availability_status'] ?? '') === 'received' ? 'ok' : 'warning') ?>">
                    <div>
                        <strong><?= View::e($appointment['title'] ?? 'Pré-agendamento') ?></strong>
                        <p><?= View::e(($appointment['contact_name'] ?? '') ?: ($appointment['phone'] ?? 'Sem contato')) ?></p>
                        <small>Preferência: <?= View::e(($appointment['preferred_day_text'] ?? '') ?: 'dia não informado') ?> · <?= View::e(($appointment['preferred_time_text'] ?? '') ?: 'horário não informado') ?></small>
                        <small>Status disponibilidade: <?= View::e($statusLabels[$appointment['availability_status'] ?? ''] ?? (($appointment['availability_status'] ?? '') ?: 'não consultada')) ?></small>
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
        <div class="section-heading"><div><span class="eyebrow">Configuração</span><h2>Regras de disponibilidade</h2></div></div>
        <form class="operations-form" method="post" action="<?= View::e(Router::url('/calendar/availability/settings')) ?>">
            <?= Csrf::input() ?>
            <input type="hidden" name="tenant_id" value="<?= (int) $tenantId ?>">
            <label class="switch-inline"><input type="checkbox" name="enabled" value="1" <?= !empty($settings['enabled']) ? 'checked' : '' ?>><span>Ativar agenda inteligente para esta empresa</span></label>
            <label class="switch-inline"><input type="checkbox" name="require_before_approval" value="1" <?= !empty($settings['require_before_approval']) ? 'checked' : '' ?>><span>Exigir disponibilidade antes de aprovar pré-agendamento</span></label>
            <label class="switch-inline"><input type="checkbox" name="auto_request_on_pre_schedule" value="1" <?= !empty($settings['auto_request_on_pre_schedule']) ? 'checked' : '' ?>><span>Consultar automaticamente quando a IA capturar dia e horário</span></label>
            <label class="switch-inline"><input type="checkbox" name="use_n8n" value="1" <?= !empty($settings['use_n8n']) ? 'checked' : '' ?>><span>Usar n8n para buscar agenda externa</span></label>
            <label class="switch-inline"><input type="checkbox" name="use_internal_fallback" value="1" <?= !empty($settings['use_internal_fallback']) ? 'checked' : '' ?>><span>Usar fallback interno se n8n não responder</span></label>

            <div class="field"><label>URL do webhook n8n</label><input type="url" name="n8n_webhook_url" value="<?= View::e($settings['n8n_webhook_url'] ?? '') ?>" placeholder="https://n8n.../webhook/rsconnect-disponibilidade"></div>
            <div class="field"><label>Token do webhook n8n</label><input type="text" name="secret_token" value="<?= View::e($settings['secret_token'] ?? '') ?>" placeholder="Opcional"></div>
            <div class="field-grid two">
                <div class="field"><label>Timezone</label><input type="text" name="timezone" value="<?= View::e($settings['timezone'] ?? 'America/Sao_Paulo') ?>"></div>
                <div class="field"><label>Duração padrão</label><input type="number" name="default_duration_minutes" min="15" max="240" value="<?= (int) ($settings['default_duration_minutes'] ?? 50) ?>"></div>
            </div>
            <div class="field-grid two">
                <div class="field"><label>Intervalo dos slots</label><input type="number" name="slot_interval_minutes" min="10" max="180" value="<?= (int) ($settings['slot_interval_minutes'] ?? 30) ?>"></div>
                <div class="field"><label>Buffer entre atendimentos</label><input type="number" name="buffer_minutes" min="0" max="180" value="<?= (int) ($settings['buffer_minutes'] ?? 10) ?>"></div>
            </div>
            <div class="field-grid two">
                <div class="field"><label>Buscar próximos dias</label><input type="number" name="search_days_ahead" min="1" max="90" value="<?= (int) ($settings['search_days_ahead'] ?? 14) ?>"></div>
                <div class="field"><label>Máximo de sugestões</label><input type="number" name="max_suggestions" min="1" max="20" value="<?= (int) ($settings['max_suggestions'] ?? 5) ?>"></div>
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
            <div class="field"><label>Antecedência mínima em horas</label><input type="number" name="min_notice_hours" min="0" max="720" value="<?= (int) ($settings['min_notice_hours'] ?? 4) ?>"></div>
            <button class="btn btn-primary btn-block" type="submit">Salvar configuração</button>
        </form>
    </aside>
</div>

<div class="operations-grid" style="margin-top:16px">
    <section class="card">
        <div class="section-heading"><div><span class="eyebrow">Horários disponíveis</span><h2>Opções retornadas</h2></div></div>
        <div class="operations-check-list">
            <?php foreach ($slots as $slot): ?>
                <article class="operations-check <?= !empty($slot['selected_at']) ? 'is-ok' : '' ?>">
                    <div>
                        <strong><?= View::e($slot['label'] ?: $date($slot['starts_at'])) ?></strong>
                        <p><?= View::e(($slot['contact_name'] ?? '') ?: ($slot['appointment_title'] ?? 'Agendamento')) ?></p>
                        <small><?= View::e($date($slot['starts_at'])) ?> até <?= View::e($date($slot['ends_at'], 'H:i')) ?> · Fonte: <?= View::e($slot['source'] ?? 'n8n') ?></small>
                    </div>
                    <?php if (empty($slot['selected_at'])): ?>
                        <form method="post" action="<?= View::e(Router::url('/calendar/availability/apply')) ?>">
                            <?= Csrf::input() ?>
                            <input type="hidden" name="tenant_id" value="<?= (int) $tenantId ?>">
                            <input type="hidden" name="appointment_id" value="<?= (int) $slot['appointment_id'] ?>">
                            <input type="hidden" name="slot_id" value="<?= (int) $slot['id'] ?>">
                            <button class="btn btn-small btn-secondary" type="submit">Usar este horário</button>
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
                <thead><tr><th>Data</th><th>Contato</th><th>Status</th><th>Preferência</th><th>Erro</th></tr></thead>
                <tbody>
                <?php foreach ($requests as $request): ?>
                    <tr>
                        <td><?= View::e($date($request['requested_at'] ?? $request['created_at'] ?? null)) ?></td>
                        <td><?= View::e(($request['contact_name'] ?? '') ?: ($request['appointment_title'] ?? '-')) ?></td>
                        <td><span class="badge badge-<?= View::e(in_array($request['status'], ['received', 'sent'], true) ? 'success' : ($request['status'] === 'failed' ? 'danger' : 'warning')) ?>"><?= View::e($statusLabels[$request['status']] ?? $request['status']) ?></span></td>
                        <td><?= View::e(($request['preferred_day_text'] ?? '-') . ' · ' . ($request['preferred_time_text'] ?? '-')) ?></td>
                        <td><?= View::e($request['error_message'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$requests): ?><tr><td colspan="5"><div class="empty-state">Nenhuma consulta registrada.</div></td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<section class="card" style="margin-top:16px">
    <div class="section-heading"><div><span class="eyebrow">Callback n8n</span><h2>Como devolver horários ao RS Connect</h2></div></div>
    <p class="muted-text">O n8n deve chamar o endpoint abaixo depois de consultar Google Calendar, agenda externa ou planilha.</p>
    <pre class="codebox">POST <?= View::e(Router::url('/webhooks/calendar/availability')) ?>?token=REQUEST_TOKEN
{
  "request_id": 1,
  "request_token": "REQUEST_TOKEN",
  "source": "google_calendar",
  "slots": [
    {"start": "2026-07-16 09:00:00", "end": "2026-07-16 09:50:00", "label": "16/07 às 09:00"},
    {"start": "2026-07-16 14:00:00", "end": "2026-07-16 14:50:00", "label": "16/07 às 14:00"}
  ]
}</pre>
</section>
