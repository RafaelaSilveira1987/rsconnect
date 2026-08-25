<?php

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;

$schedules = $data['schedules'] ?? [];
$generated = $data['generated'] ?? [];
$tenants = $data['tenants'] ?? [];
$instances = $data['instances'] ?? [];
$sectionOptions = $data['section_options'] ?? ['tenant' => [], 'admin' => []];
$isSuperAdmin = Auth::isSuperAdmin();
$today = date('Y-m-d');
$monthStart = date('Y-m-01');

$statusClass = static fn (string $status): string => match ($status) {
    'active', 'sent', 'ready' => 'is-success',
    'paused', 'partial', 'sending' => 'is-warning',
    'failed' => 'is-danger',
    default => 'is-neutral',
};
$frequencyLabel = static fn (string $value): string => match ($value) {
    'daily' => 'Diário',
    'weekly' => 'Semanal',
    'monthly' => 'Mensal',
    default => 'Manual',
};
?>
<link rel="stylesheet" href="<?= View::e(Router::url('/assets/css/reports.css?v=36.15.1')) ?>">

<div class="scheduled-reports-page">
    <header class="scheduled-reports-header">
        <div>
            <nav class="rs-report-breadcrumb"><a href="<?= View::e(Router::url('/reports')) ?>">Relatórios</a><b>/</b><strong>Automáticos</strong></nav>
            <h1>Relatórios automáticos</h1>
            <p>Gere PDFs com a identidade visual da empresa, programe envios e acompanhe cada entrega pelo WhatsApp.</p>
        </div>
        <a class="btn btn-outline" href="<?= View::e(Router::url('/reports')) ?>">Voltar ao painel</a>
    </header>

    <?php if (empty($data['cron_token_configured'])): ?>
        <div class="flash warning">
            <strong>Execução automática ainda não configurada.</strong>
            <span>Defina <code>SCHEDULED_REPORTS_CRON_TOKEN</code> no EasyPanel e use o endereço protegido no n8n.</span>
        </div>
    <?php endif; ?>

    <section class="scheduled-reports-grid">
        <article class="card scheduled-report-form-card">
            <header>
                <span class="eyebrow">Geração imediata</span>
                <h2>Criar relatório em PDF</h2>
                <p>Escolha o período, os indicadores e, quando necessário, envie o arquivo no mesmo momento.</p>
            </header>

            <form method="post" action="<?= View::e(Router::url('/reports/automatic/generate')) ?>" class="scheduled-report-form">
                <?= Csrf::input() ?>
                <div class="form-grid two-columns">
                    <label>
                        <span>Nome do relatório</span>
                        <input name="name" value="Relatório executivo" maxlength="150" required>
                    </label>
                    <?php if ($isSuperAdmin): ?>
                        <label>
                            <span>Versão do relatório</span>
                            <select name="report_scope" data-report-scope>
                                <option value="admin">RS Admin</option>
                                <option value="tenant">Empresa cliente</option>
                            </select>
                        </label>
                    <?php else: ?>
                        <input type="hidden" name="report_scope" value="tenant">
                    <?php endif; ?>
                </div>

                <?php if ($isSuperAdmin): ?>
                    <label>
                        <span>Empresa</span>
                        <select name="tenant_id" data-report-tenant>
                            <option value="">Toda a operação (somente RS Admin)</option>
                            <?php foreach ($tenants as $tenant): ?>
                                <option value="<?= (int) $tenant['id'] ?>"><?= View::e((string) $tenant['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                <?php endif; ?>

                <div class="form-grid two-columns">
                    <label><span>De</span><input type="date" name="start" value="<?= View::e($monthStart) ?>" required></label>
                    <label><span>Até</span><input type="date" name="end" value="<?= View::e($today) ?>" required></label>
                </div>

                <fieldset class="scheduled-report-sections" data-sections-scope="tenant">
                    <legend>Indicadores da empresa</legend>
                    <?php foreach ($sectionOptions['tenant'] ?? [] as $option): ?>
                        <label><input type="checkbox" name="sections[]" value="<?= View::e((string) $option['key']) ?>" checked><span><?= View::e((string) $option['label']) ?></span></label>
                    <?php endforeach; ?>
                </fieldset>

                <?php if ($isSuperAdmin): ?>
                    <fieldset class="scheduled-report-sections" data-sections-scope="admin">
                        <legend>Indicadores da RS Admin</legend>
                        <?php foreach ($sectionOptions['admin'] ?? [] as $option): ?>
                            <label><input type="checkbox" name="sections[]" value="<?= View::e((string) $option['key']) ?>" checked><span><?= View::e((string) $option['label']) ?></span></label>
                        <?php endforeach; ?>
                    </fieldset>
                <?php endif; ?>

                <label>
                    <span>Destinatários do WhatsApp</span>
                    <textarea name="recipients" rows="3" placeholder="Rafaela | 5567999999999&#10;Gestor | 5511999999999"></textarea>
                    <small>Use uma linha por destinatário. O nome é opcional.</small>
                </label>

                <label>
                    <span>Conexão usada no envio</span>
                    <select name="evolution_instance_id">
                        <option value="">Usar a conexão padrão da empresa</option>
                        <?php foreach ($instances as $instance): ?>
                            <option value="<?= (int) $instance['id'] ?>">
                                <?= View::e((string) $instance['name']) ?>
                                <?= $isSuperAdmin ? ' · Empresa ' . (int) $instance['tenant_id'] : '' ?>
                                <?= (string) $instance['status'] === 'connected' ? ' · Conectada' : ' · Atenção' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label class="checkbox-row">
                    <input type="checkbox" name="send_now" value="1">
                    <span>Enviar pelo WhatsApp após gerar</span>
                </label>

                <button class="btn btn-primary" type="submit">Gerar PDF agora</button>
            </form>
        </article>

        <article class="card scheduled-report-form-card">
            <header>
                <span class="eyebrow">Programação</span>
                <h2>Novo envio automático</h2>
                <p>Defina a frequência e o período que será fechado em cada execução.</p>
            </header>

            <form method="post" action="<?= View::e(Router::url('/reports/automatic/save')) ?>" class="scheduled-report-form">
                <?= Csrf::input() ?>
                <div class="form-grid two-columns">
                    <label><span>Nome</span><input name="name" placeholder="Resumo semanal" maxlength="150" required></label>
                    <?php if ($isSuperAdmin): ?>
                        <label>
                            <span>Versão</span>
                            <select name="report_scope" data-report-scope>
                                <option value="tenant">Empresa cliente</option>
                                <option value="admin">RS Admin</option>
                            </select>
                        </label>
                    <?php else: ?>
                        <input type="hidden" name="report_scope" value="tenant">
                    <?php endif; ?>
                </div>

                <?php if ($isSuperAdmin): ?>
                    <label>
                        <span>Empresa</span>
                        <select name="tenant_id" data-report-tenant>
                            <option value="">Toda a operação (somente RS Admin)</option>
                            <?php foreach ($tenants as $tenant): ?>
                                <option value="<?= (int) $tenant['id'] ?>"><?= View::e((string) $tenant['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                <?php endif; ?>

                <div class="form-grid three-columns">
                    <label>
                        <span>Frequência</span>
                        <select name="frequency">
                            <option value="daily">Diária</option>
                            <option value="weekly" selected>Semanal</option>
                            <option value="monthly">Mensal</option>
                        </select>
                    </label>
                    <label><span>Horário</span><input type="time" name="time_of_day" value="08:00"></label>
                    <label>
                        <span>Período do relatório</span>
                        <select name="period_mode">
                            <option value="previous_day">Dia anterior</option>
                            <option value="previous_week" selected>Semana anterior</option>
                            <option value="previous_month">Mês anterior</option>
                            <option value="last_7_days">Últimos 7 dias</option>
                            <option value="last_30_days">Últimos 30 dias</option>
                            <option value="current_month">Mês atual</option>
                        </select>
                    </label>
                </div>

                <div class="form-grid three-columns">
                    <label>
                        <span>Dia da semana</span>
                        <select name="weekday">
                            <option value="1">Segunda-feira</option>
                            <option value="2">Terça-feira</option>
                            <option value="3">Quarta-feira</option>
                            <option value="4">Quinta-feira</option>
                            <option value="5">Sexta-feira</option>
                            <option value="6">Sábado</option>
                            <option value="7">Domingo</option>
                        </select>
                    </label>
                    <label><span>Dia do mês</span><input type="number" name="month_day" min="1" max="28" value="1"></label>
                    <label><span>Fuso</span><input name="timezone" value="America/Sao_Paulo"></label>
                </div>

                <fieldset class="scheduled-report-sections" data-sections-scope="tenant">
                    <legend>Indicadores da empresa</legend>
                    <?php foreach ($sectionOptions['tenant'] ?? [] as $option): ?>
                        <label><input type="checkbox" name="sections[]" value="<?= View::e((string) $option['key']) ?>" checked><span><?= View::e((string) $option['label']) ?></span></label>
                    <?php endforeach; ?>
                </fieldset>

                <?php if ($isSuperAdmin): ?>
                    <fieldset class="scheduled-report-sections" data-sections-scope="admin">
                        <legend>Indicadores da RS Admin</legend>
                        <?php foreach ($sectionOptions['admin'] ?? [] as $option): ?>
                            <label><input type="checkbox" name="sections[]" value="<?= View::e((string) $option['key']) ?>"><span><?= View::e((string) $option['label']) ?></span></label>
                        <?php endforeach; ?>
                    </fieldset>
                <?php endif; ?>

                <label>
                    <span>Destinatários</span>
                    <textarea name="recipients" rows="3" placeholder="Rafaela | 5567999999999"></textarea>
                </label>

                <label>
                    <span>Conexão do WhatsApp</span>
                    <select name="evolution_instance_id">
                        <option value="">Usar a conexão padrão da empresa</option>
                        <?php foreach ($instances as $instance): ?>
                            <option value="<?= (int) $instance['id'] ?>">
                                <?= View::e((string) $instance['name']) ?>
                                <?= (string) $instance['status'] === 'connected' ? ' · Conectada' : ' · Atenção' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label class="checkbox-row"><input type="checkbox" name="whatsapp_enabled" value="1" checked><span>Enviar pelo WhatsApp</span></label>
                <button class="btn btn-primary" type="submit">Salvar programação</button>
            </form>
        </article>
    </section>

    <section class="card scheduled-report-list-card">
        <header>
            <div><span class="eyebrow">Agenda de envios</span><h2>Programações</h2></div>
            <span><?= count($schedules) ?> cadastrada(s)</span>
        </header>

        <div class="table-wrap">
            <table class="scheduled-report-table">
                <thead>
                    <tr>
                        <th>Relatório</th>
                        <?php if ($isSuperAdmin): ?><th>Empresa</th><?php endif; ?>
                        <th>Frequência</th>
                        <th>Próxima execução</th>
                        <th>Destinatários</th>
                        <th>Situação</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($schedules as $schedule): ?>
                    <tr>
                        <td><strong><?= View::e((string) $schedule['name']) ?></strong><small><?= View::e((string) $schedule['report_scope'] === 'admin' ? 'RS Admin' : 'Empresa cliente') ?></small></td>
                        <?php if ($isSuperAdmin): ?><td><?= View::e((string) ($schedule['tenant_name'] ?? 'Toda a operação')) ?></td><?php endif; ?>
                        <td><?= View::e((string) ($schedule['frequency_label'] ?? $frequencyLabel((string) $schedule['frequency']))) ?></td>
                        <td><?= View::e((string) ($schedule['next_run_local'] ?? 'Execução manual')) ?></td>
                        <td><?= (int) ($schedule['recipient_count'] ?? 0) ?></td>
                        <td><span class="scheduled-status <?= $statusClass((string) $schedule['status']) ?>"><?= View::e((string) $schedule['status'] === 'active' ? 'Ativa' : 'Pausada') ?></span></td>
                        <td class="scheduled-actions">
                            <form method="post" action="<?= View::e(Router::url('/reports/automatic/generate')) ?>"><?= Csrf::input() ?><input type="hidden" name="schedule_uuid" value="<?= View::e((string) $schedule['uuid']) ?>"><button class="btn btn-quiet btn-small" type="submit">Gerar agora</button></form>
                            <form method="post" action="<?= View::e(Router::url('/reports/automatic/toggle')) ?>"><?= Csrf::input() ?><input type="hidden" name="schedule_uuid" value="<?= View::e((string) $schedule['uuid']) ?>"><button class="btn btn-outline btn-small" type="submit"><?= (string) $schedule['status'] === 'active' ? 'Pausar' : 'Ativar' ?></button></form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$schedules): ?>
                    <tr><td colspan="<?= $isSuperAdmin ? 7 : 6 ?>" class="empty-state">Nenhuma programação cadastrada.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="card scheduled-report-list-card">
        <header>
            <div><span class="eyebrow">Histórico</span><h2>Relatórios gerados</h2></div>
            <span><?= count($generated) ?> recente(s)</span>
        </header>

        <div class="table-wrap">
            <table class="scheduled-report-table">
                <thead>
                    <tr>
                        <th>Relatório</th>
                        <?php if ($isSuperAdmin): ?><th>Empresa</th><?php endif; ?>
                        <th>Período</th>
                        <th>Gerado em</th>
                        <th>Arquivo</th>
                        <th>Entrega</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($generated as $report): ?>
                    <tr>
                        <td><strong><?= View::e((string) $report['report_name']) ?></strong><small><?= View::e((string) ($report['schedule_name'] ?? 'Geração manual')) ?></small></td>
                        <?php if ($isSuperAdmin): ?><td><?= View::e((string) ($report['tenant_name'] ?? 'Toda a operação')) ?></td><?php endif; ?>
                        <td><?= View::e(date('d/m/Y', strtotime((string) $report['period_start']))) ?> a <?= View::e(date('d/m/Y', strtotime((string) $report['period_end']))) ?></td>
                        <td><?= View::e((string) ($report['created_local'] ?? '')) ?></td>
                        <td><?= View::e((string) ($report['size_label'] ?? '')) ?></td>
                        <td>
                            <span class="scheduled-status <?= $statusClass((string) $report['status']) ?>"><?= View::e((string) ($report['status_label'] ?? $report['status'])) ?></span>
                            <small><?= View::e((string) ($report['delivery_summary'] ?? 'Ainda não enviado')) ?></small>
                            <?php if ((int) ($report['delivery_count'] ?? 0) > 0): ?><small><?= (int) ($report['sent_count'] ?? 0) ?> concluída(s) · <?= (int) ($report['failed_count'] ?? 0) ?> falha(s)</small><?php endif; ?>
                        </td>
                        <td class="scheduled-actions">
                            <a class="btn btn-quiet btn-small" target="_blank" href="<?= View::e(Router::url('/reports/automatic/download?report_uuid=' . rawurlencode((string) $report['uuid']))) ?>">Visualizar</a>
                            <a class="btn btn-outline btn-small" href="<?= View::e(Router::url('/reports/automatic/download?download=1&report_uuid=' . rawurlencode((string) $report['uuid']))) ?>">Baixar</a>
                            <?php if ((int) ($report['delivery_count'] ?? 0) > 0): ?>
                                <form method="post" action="<?= View::e(Router::url('/reports/automatic/resend')) ?>"><?= Csrf::input() ?><input type="hidden" name="report_uuid" value="<?= View::e((string) $report['uuid']) ?>"><button class="btn btn-outline btn-small" type="submit"><?= (int) ($report['sent_count'] ?? 0) > 0 ? 'Reenviar' : 'Enviar' ?></button></form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$generated): ?>
                    <tr><td colspan="<?= $isSuperAdmin ? 7 : 6 ?>" class="empty-state">Nenhum relatório foi gerado ainda.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="card scheduled-report-cron-card">
        <span class="eyebrow">Automação n8n</span>
        <h2>Execução recorrente</h2>
        <p>Programe uma chamada a cada 15 minutos. A RS Connect só gera programações que estiverem vencidas e impede duplicidade pelo período.</p>
        <code>POST <?= View::e(Router::url('/webhooks/reports/scheduled/run')) ?></code>
        <small>Cabeçalho técnico: <strong>X-RS-Connect-Token</strong> com o valor de <strong>SCHEDULED_REPORTS_CRON_TOKEN</strong>.</small>
    </section>
</div>

<script>
document.addEventListener('change', function (event) {
    const select = event.target.closest('[data-report-scope]');
    if (!select) return;
    const form = select.closest('form');
    if (!form) return;
    form.querySelectorAll('[data-sections-scope]').forEach(function (fieldset) {
        const active = fieldset.getAttribute('data-sections-scope') === select.value;
        fieldset.hidden = !active;
        fieldset.querySelectorAll('input[type="checkbox"]').forEach(function (input) {
            input.disabled = !active;
        });
    });
    const tenantSelect = form.querySelector('[data-report-tenant]');
    if (tenantSelect) {
        tenantSelect.required = select.value === 'tenant';
    }
});
document.querySelectorAll('[data-report-scope]').forEach(function (select) {
    select.dispatchEvent(new Event('change', { bubbles: true }));
});
</script>
