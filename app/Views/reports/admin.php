<?php

use App\Core\Router;
use App\Core\View;
use App\Services\OperationalLanguageService;

$metrics = $reportData['metrics'] ?? [];
$comparisons = $reportData['comparisons'] ?? [];
$companyGrowth = $reportData['companyGrowth'] ?? [];
$messagesByDay = $reportData['messagesByDay'] ?? [];
$revenueByPlan = $reportData['revenueByPlan'] ?? [];
$usageByTenant = $reportData['usageByTenant'] ?? [];
$lowUsage = $reportData['lowUsage'] ?? [];
$failures = $reportData['failures'] ?? [];
$failureTrend = $reportData['failureTrend'] ?? [];
$healthDistribution = $reportData['healthDistribution'] ?? [];
$insights = $reportData['insights'] ?? [];
$agendaStatus = $reportData['agendaStatus'] ?? [];
$commercialStages = $reportData['commercialStages'] ?? [];
$recentInvoices = $reportData['recentInvoices'] ?? [];
$tenants = $reportData['tenants'] ?? [];
$warnings = $reportData['warnings'] ?? [];
$interactionsByHour = $reportData['interactionsByHour'] ?? [];
$responseDistribution = $reportData['responseDistribution'] ?? [];
$teamPerformance = $reportData['teamPerformance'] ?? [];

$money = static fn (float|int|string $value): string => 'R$ ' . number_format((float) $value, 2, ',', '.');
$number = static fn (float|int|string $value): string => number_format((float) $value, 0, ',', '.');
$duration = static function (float|int|string $seconds): string {
    $seconds = max(0, (int) round((float) $seconds));
    if ($seconds < 60) return $seconds . 's';
    if ($seconds < 3600) return intdiv($seconds, 60) . 'min ' . ($seconds % 60) . 's';
    return intdiv($seconds, 3600) . 'h ' . intdiv($seconds % 3600, 60) . 'min';
};
$trend = static function (?float $value, bool $inverse = false): array {
    if ($value === null) return ['class' => 'is-neutral', 'text' => 'Sem base anterior'];
    if (abs($value) < .05) return ['class' => 'is-neutral', 'text' => 'Estável vs. período anterior'];
    $positive = $value > 0;
    if ($inverse) $positive = !$positive;
    return [
        'class' => $positive ? 'is-up' : 'is-down',
        'text' => ($value > 0 ? '↑ ' : '↓ ') . number_format(abs($value), 1, ',', '.') . '% vs. período anterior',
    ];
};
$max = static function (array $rows, string $key = 'total'): int {
    $values = array_map(static fn (array $row): int => (int) ($row[$key] ?? 0), $rows);
    return max(1, ...($values ?: [1]));
};
$icon = static function (string $name): string {
    $paths = [
        'companies' => '<path d="M4 20h16M6 20V7l6-3v16M14 20V10h4v10M9 9h.01M9 13h.01M9 17h.01"/>',
        'chat' => '<path d="M21 15a4 4 0 0 1-4 4H8l-5 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4z"/><path d="M8 9h8M8 13h5"/>',
        'human' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/>',
        'check' => '<circle cx="12" cy="12" r="9"/><path d="m8 12 2.5 2.5L16 9"/>',
        'spark' => '<path d="m12 3 1.8 4.2L18 9l-4.2 1.8L12 15l-1.8-4.2L6 9l4.2-1.8z"/><path d="m18 15 .9 2.1L21 18l-2.1.9L18 21l-.9-2.1L15 18l2.1-.9z"/>',
        'alert' => '<path d="M10.3 3.6 2.6 17a2 2 0 0 0 1.7 3h15.4a2 2 0 0 0 1.7-3L13.7 3.6a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/>',
        'download' => '<path d="M12 3v12M7 10l5 5 5-5M4 21h16"/>',
        'refresh' => '<path d="M20 6v5h-5M4 18v-5h5"/><path d="M18.5 9A7 7 0 0 0 6 6.5L4 11M5.5 15A7 7 0 0 0 18 17.5l2-4.5"/>',
        'filter' => '<path d="M4 5h16l-6 7v5l-4 2v-7z"/>',
        'report' => '<path d="M5 3h10l4 4v14H5z"/><path d="M14 3v5h5M8 13h8M8 17h6"/>',
    ];
    return '<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . ($paths[$name] ?? $paths['report']) . '</svg>';
};
$queryBase = array_filter([
    'start' => $filters['start'] ?? '',
    'end' => $filters['end'] ?? '',
    'tenant_id' => (int) ($filters['tenant_id'] ?? 0),
], static fn ($value) => $value !== '' && $value !== 0);
$lineSeries = json_encode(array_map(static fn (array $row): array => [
    'label' => date('d/m', strtotime((string) $row['label'])),
    'total' => (int) ($row['total'] ?? 0),
    'incoming' => (int) ($row['incoming'] ?? 0),
    'ai' => (int) ($row['ai'] ?? 0),
], $messagesByDay), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$failureSeries = json_encode(array_map(static fn (array $row): array => [
    'label' => date('d/m', strtotime((string) $row['label'])),
    'total' => (int) ($row['total'] ?? 0),
], $failureTrend), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$healthSeries = json_encode(array_map(static fn (array $row): array => [
    'label' => (string) ($row['label'] ?? ''),
    'value' => (int) ($row['total'] ?? 0),
], $healthDistribution), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$responseSeries = json_encode($responseDistribution, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$agendaLabels = [
    'scheduled' => 'Agendados', 'confirmed' => 'Confirmados', 'completed' => 'Concluídos',
    'cancelled' => 'Cancelados', 'no_show' => 'Não compareceram', 'rejected' => 'Recusados',
];
$agendaSeries = json_encode(array_map(static fn (array $row): array => [
    'label' => $agendaLabels[(string) ($row['label'] ?? '')] ?? ucfirst((string) ($row['label'] ?? 'Outros')),
    'value' => (int) ($row['total'] ?? 0),
], $agendaStatus), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$hourMax = $max($interactionsByHour);
$periodLabel = date('d/m/Y', strtotime((string) ($filters['start'] ?? 'now'))) . ' — ' . date('d/m/Y', strtotime((string) ($filters['end'] ?? 'now')));
$quickReports = [
    ['name' => 'Resumo executivo da operação', 'type' => 'Visão geral', 'metric' => $number($metrics['messages'] ?? 0) . ' interações', 'export' => 'companies'],
    ['name' => 'Desempenho das empresas', 'type' => 'Adoção', 'metric' => $number(count($usageByTenant)) . ' empresas', 'export' => 'usage'],
    ['name' => 'Receita e cobranças', 'type' => 'Financeiro', 'metric' => $money($metrics['received'] ?? 0), 'export' => 'revenue'],
    ['name' => 'Situações das integrações', 'type' => 'Operação', 'metric' => $number($metrics['automation_failures'] ?? 0) . ' ocorrências', 'export' => 'failures'],
    ['name' => 'Pipeline comercial RS', 'type' => 'Comercial', 'metric' => $money($metrics['commercial_pipeline'] ?? 0), 'export' => 'commercial'],
];
?>
<link rel="stylesheet" href="<?= View::e(Router::url('/assets/css/reports.css?v=36.15.1')) ?>">
<div class="executive-report-page executive-report-admin report-v3646 report-v3647 report-v36140">
    <header class="rs-admin-report-header">
        <div>
            <nav class="rs-report-breadcrumb" aria-label="Navegação"><span>Relatórios</span><b>/</b><strong>Visão geral</strong></nav>
            <h1>Painel executivo</h1>
            <p>Indicadores e insights da operação RS Connect para decisões rápidas e acompanhamento das empresas.</p>
        </div>
        <div class="rs-admin-report-header-actions">
            <a class="btn btn-outline" href="<?= View::e(Router::url('/reports/automatic')) ?>">Relatórios automáticos</a>
            <a class="btn btn-outline" href="<?= View::e(Router::url('/reports/team?' . http_build_query($queryBase))) ?>">Equipe e profissionais</a>
            <button class="btn btn-outline" type="button" onclick="window.location.reload()"><?= $icon('refresh') ?> Atualizar</button>
            <a class="btn btn-primary" href="<?= View::e(Router::url('/reports/export?' . http_build_query($queryBase + ['type' => 'companies']))) ?>"><?= $icon('download') ?> Exportar</a>
        </div>
    </header>

    <form class="card rs-admin-report-toolbar" method="get" action="<?= View::e(Router::url('/reports')) ?>">
        <div class="rs-admin-date-range">
            <label><span>De</span><input type="date" name="start" value="<?= View::e($filters['start']) ?>"></label>
            <i>→</i>
            <label><span>Até</span><input type="date" name="end" value="<?= View::e($filters['end']) ?>"></label>
        </div>
        <label class="rs-admin-company-filter"><span>Empresa</span><select name="tenant_id"><option value="">Toda a operação</option><?php foreach ($tenants as $tenant): ?><option value="<?= (int) $tenant['id'] ?>" <?= (int) ($filters['tenant_id'] ?? 0) === (int) $tenant['id'] ? 'selected' : '' ?>><?= View::e($tenant['name']) ?></option><?php endforeach; ?></select></label>
        <div class="rs-admin-toolbar-actions"><button class="btn btn-primary" type="submit"><?= $icon('filter') ?> Aplicar filtros</button><a class="btn btn-quiet" href="<?= View::e(Router::url('/reports')) ?>">Limpar</a></div>
    </form>

    <?php if ($warnings): ?><div class="flash warning executive-report-warning"><strong>Alguns indicadores precisam de atenção.</strong><span><?= View::e(implode(' · ', $warnings)) ?></span></div><?php endif; ?>

    <section class="rs-admin-kpi-grid" aria-label="Indicadores principais">
        <?php $t = $trend($comparisons['new_companies'] ?? null); ?>
        <a class="card rs-admin-kpi is-blue" href="<?= View::e(Router::url('/companies')) ?>"><span class="rs-admin-kpi-icon"><?= $icon('companies') ?></span><div><small>Empresas ativas</small><strong><?= $number($metrics['active_companies'] ?? 0) ?></strong><em class="report-trend <?= $t['class'] ?>">+<?= $number($metrics['new_companies'] ?? 0) ?> no período</em></div></a>
        <?php $t = $trend($comparisons['conversations_started'] ?? null); ?>
        <a class="card rs-admin-kpi is-indigo" href="<?= View::e(Router::url('/conversations')) ?>"><span class="rs-admin-kpi-icon"><?= $icon('chat') ?></span><div><small>Conversas iniciadas</small><strong><?= $number($metrics['conversations_started'] ?? 0) ?></strong><em class="report-trend <?= $t['class'] ?>"><?= View::e($t['text']) ?></em></div></a>
        <?php $t = $trend($comparisons['human_messages'] ?? null); ?>
        <a class="card rs-admin-kpi is-teal" href="<?= View::e(Router::url('/reports/team?' . http_build_query($queryBase))) ?>"><span class="rs-admin-kpi-icon"><?= $icon('human') ?></span><div><small>Atendimentos humanos</small><strong><?= $number($metrics['human_conversations'] ?? 0) ?></strong><em class="report-trend <?= $t['class'] ?>"><?= $number($metrics['human_messages'] ?? 0) ?> respostas da equipe</em></div></a>
        <?php $t = $trend($comparisons['avg_first_response_seconds'] ?? null, true); ?>
        <a class="card rs-admin-kpi is-purple" href="<?= View::e(Router::url('/reports/team?' . http_build_query($queryBase))) ?>"><span class="rs-admin-kpi-icon"><?= $icon('clock') ?></span><div><small>Tempo médio da 1ª resposta</small><strong><?= View::e($duration($metrics['avg_first_response_seconds'] ?? 0)) ?></strong><em class="report-trend <?= $t['class'] ?>"><?= $number($metrics['first_responses'] ?? 0) ?> respostas medidas</em></div></a>
        <?php $t = $trend($comparisons['appointments_confirmed'] ?? null); ?>
        <a class="card rs-admin-kpi is-orange" href="<?= View::e(Router::url('/calendar')) ?>"><span class="rs-admin-kpi-icon"><?= $icon('calendar') ?></span><div><small>Agendamentos</small><strong><?= $number($metrics['appointments'] ?? 0) ?></strong><em class="report-trend <?= $t['class'] ?>"><?= number_format((float) ($metrics['agenda_conversion'] ?? 0), 1, ',', '.') ?>% confirmados/concluídos</em></div></a>
        <a class="card rs-admin-kpi is-green" href="<?= View::e(Router::url('/calendar')) ?>"><span class="rs-admin-kpi-icon"><?= $icon('check') ?></span><div><small>Comparecimento</small><strong><?= number_format((float) ($metrics['attendance_rate'] ?? 0), 1, ',', '.') ?>%</strong><em><?= $number($metrics['appointments_completed'] ?? 0) ?> concluído(s)</em></div></a>
        <?php $t = $trend($comparisons['ai_replies'] ?? null); ?>
        <a class="card rs-admin-kpi is-violet" href="#report-automation"><span class="rs-admin-kpi-icon"><?= $icon('spark') ?></span><div><small>Uso da IA</small><strong><?= $number($metrics['ai_replies'] ?? 0) ?></strong><em class="report-trend <?= $t['class'] ?>"><?= number_format((float) ($metrics['ai_share'] ?? 0), 1, ',', '.') ?>% das respostas</em></div></a>
        <?php $t = $trend($comparisons['automation_failures'] ?? null, true); ?>
        <a class="card rs-admin-kpi is-red" href="<?= View::e(Router::url('/operacao-alertas')) ?>"><span class="rs-admin-kpi-icon"><?= $icon('alert') ?></span><div><small>Incidentes operacionais</small><strong><?= $number(($metrics['open_operational_incidents'] ?? 0) + ($metrics['open_health_incidents'] ?? 0)) ?></strong><em class="report-trend <?= $t['class'] ?>"><?= $number($metrics['automation_failures'] ?? 0) ?> no período</em></div></a>
    </section>

    <section class="rs-admin-chart-grid rs-admin-chart-grid-primary">
        <article class="card rs-admin-dashboard-card rs-admin-chart-wide">
            <header><div><h2>Atendimentos ao longo do tempo</h2><p>Mensagens recebidas, respostas humanas e interações da IA.</p></div><span class="rs-admin-period-pill"><?= View::e($periodLabel) ?></span></header>
            <div class="report-svg-chart rs-admin-main-line-chart" data-report-line-chart data-series="<?= View::e((string) $lineSeries) ?>"></div>
            <div class="report-chart-legend"><span><i class="is-total"></i>Total</span><span><i class="is-incoming"></i>Recebidas</span><span><i class="is-ai"></i>IA</span></div>
        </article>
        <article class="card rs-admin-dashboard-card">
            <header><div><h2>Distribuição das interações</h2><p>Entrada do cliente e respostas enviadas.</p></div></header>
            <div class="rs-admin-donut-layout"><div class="report-donut" data-report-donut data-series="<?= View::e((string) $responseSeries) ?>" data-center="<?= $number($metrics['messages'] ?? 0) ?>"></div><div class="rs-admin-donut-legend"><?php foreach ($responseDistribution as $index => $row): ?><div><i class="is-color-<?= ($index % 5) + 1 ?>"></i><span><?= View::e($row['label']) ?></span><strong><?= $number($row['value']) ?></strong></div><?php endforeach; ?></div></div>
        </article>
        <article class="card rs-admin-dashboard-card">
            <header><div><h2>Interações por horário</h2><p>Horários com maior movimento na plataforma.</p></div></header>
            <div class="rs-admin-hour-chart" aria-label="Interações por horário"><?php foreach ($interactionsByHour as $row): $height = max(3, min(100, ((int) $row['total'] / $hourMax) * 100)); ?><article title="<?= View::e($row['label']) ?>h — <?= $number($row['total']) ?>"><span style="height:<?= $height ?>%"></span><small><?= ((int) $row['hour'] % 2 === 0) ? View::e($row['label']) : '' ?></small></article><?php endforeach; ?></div>
        </article>
    </section>

    <section class="rs-admin-chart-grid rs-admin-chart-grid-secondary">
        <article class="card rs-admin-dashboard-card rs-admin-team-card">
            <header><div><h2>Desempenho da equipe</h2><p>Volume atendido e primeira resposta dos profissionais.</p></div><a href="<?= View::e(Router::url('/reports/team?' . http_build_query($queryBase))) ?>">Ver relatório completo →</a></header>
            <div class="table-wrap"><table class="rs-admin-compact-table"><thead><tr><th>Profissional</th><th>Conversas</th><th>Respostas</th><th>1ª resposta</th></tr></thead><tbody><?php foreach (array_slice($teamPerformance, 0, 6) as $row): ?><tr><td><span class="rs-admin-person"><b><?= View::e(mb_strtoupper(mb_substr((string) $row['name'], 0, 1))) ?></b><span><strong><?= View::e($row['name']) ?></strong><small><?= View::e($row['role_label'] ?: 'Atendimento') ?></small></span></span></td><td><?= $number($row['conversations']) ?></td><td><?= $number($row['human_messages']) ?></td><td><?= View::e($duration($row['avg_response_seconds'])) ?></td></tr><?php endforeach; ?><?php if (!$teamPerformance): ?><tr><td colspan="4"><div class="empty-state">Nenhum atendimento humano no período.</div></td></tr><?php endif; ?></tbody></table></div>
        </article>
        <article class="card rs-admin-dashboard-card">
            <header><div><h2>Resultado da agenda</h2><p>Distribuição dos compromissos no período.</p></div></header>
            <div class="rs-admin-donut-layout"><div class="report-donut" data-report-donut data-series="<?= View::e((string) $agendaSeries) ?>" data-center="<?= $number($metrics['appointments'] ?? 0) ?>"></div><div class="rs-admin-donut-legend"><?php foreach ($agendaStatus as $index => $row): ?><div><i class="is-color-<?= ($index % 5) + 1 ?>"></i><span><?= View::e($agendaLabels[$row['label']] ?? ucfirst((string) $row['label'])) ?></span><strong><?= $number($row['total']) ?></strong></div><?php endforeach; ?></div></div>
        </article>
        <article class="card rs-admin-dashboard-card rs-admin-ai-summary">
            <header><div><h2>Uso da IA</h2><p>Participação da automação no atendimento.</p></div></header>
            <div class="rs-admin-ai-ring" style="--ai-share:<?= min(100, max(0, (float) ($metrics['ai_share'] ?? 0))) ?>%"><span><strong><?= number_format((float) ($metrics['ai_share'] ?? 0), 1, ',', '.') ?>%</strong><small>das respostas</small></span></div>
            <dl><div><dt>Respostas da IA</dt><dd><?= $number($metrics['ai_replies'] ?? 0) ?></dd></div><div><dt>Respostas humanas</dt><dd><?= $number($metrics['human_messages'] ?? 0) ?></dd></div><div><dt>Falhas da IA</dt><dd><?= $number($metrics['ai_failures'] ?? 0) ?></dd></div></dl>
        </article>
    </section>

    <section class="card rs-admin-ready-reports">
        <header><div><h2>Relatórios prontos para exportar</h2><p>Arquivos atualizados conforme o período e a empresa selecionados.</p></div><span><?= View::e($periodLabel) ?></span></header>
        <div class="table-wrap"><table><thead><tr><th>Nome do relatório</th><th>Tipo</th><th>Período</th><th>Escopo</th><th>Indicador</th><th>Ações</th></tr></thead><tbody><?php foreach ($quickReports as $row): ?><tr><td><span class="rs-admin-report-name"><i><?= $icon('report') ?></i><strong><?= View::e($row['name']) ?></strong></span></td><td><span class="badge"><?= View::e($row['type']) ?></span></td><td><?= View::e($periodLabel) ?></td><td><?= (int) ($filters['tenant_id'] ?? 0) > 0 ? 'Empresa selecionada' : 'Toda a operação' ?></td><td><?= View::e($row['metric']) ?></td><td><a class="rs-admin-download-action" href="<?= View::e(Router::url('/reports/export?' . http_build_query($queryBase + ['type' => $row['export']]))) ?>" aria-label="Exportar <?= View::e($row['name']) ?>"><?= $icon('download') ?></a></td></tr><?php endforeach; ?></tbody></table></div>
    </section>

    <?php if ($insights): ?><section class="card rs-admin-insights-strip"><div><span class="eyebrow">Insights automáticos</span><h2>Leitura rápida do período</h2></div><div class="report-insights-grid is-compact"><?php foreach ($insights as $item): ?><article class="report-insight is-<?= View::e($item['tone'] ?? 'info') ?>"><span class="report-insight-dot"></span><div><strong><?= View::e($item['title'] ?? '') ?></strong><p><?= View::e($item['text'] ?? '') ?></p></div></article><?php endforeach; ?></div></section><?php endif; ?>

    <div class="rs-admin-detailed-heading"><span class="eyebrow">Análises detalhadas</span><h2>Aprofunde cada indicador</h2><p>Os blocos abaixo preservam os relatórios financeiros, comerciais, de adoção, agenda e integrações já existentes.</p></div>
<section class="card report-section-directory" id="report-directory" aria-label="Navegação dos relatórios">
    <div class="section-heading report-directory-heading"><div><span class="eyebrow">Conteúdo do relatório</span><h2>Escolha uma análise</h2><p>Todos os indicadores ficam visíveis na mesma página. Use os atalhos para ir direto ao assunto.</p></div></div>
    <nav class="report-section-card-grid">
        <a class="report-section-link" href="#report-growth" data-report-section-link><span class="report-section-number">01</span><strong>Crescimento</strong><small>Base e saúde dos clientes.</small></a>
        <a class="report-section-link" href="#report-revenue" data-report-section-link><span class="report-section-number">02</span><strong>Receita</strong><small>Assinaturas e cobranças.</small></a>
        <a class="report-section-link" href="#report-usage" data-report-section-link><span class="report-section-number">03</span><strong>Uso da plataforma</strong><small>Volume e adoção por empresa.</small></a>
        <a class="report-section-link" href="#report-automation" data-report-section-link><span class="report-section-number">04</span><strong>IA e automações</strong><small>Respostas e pontos de atenção.</small></a>
        <a class="report-section-link" href="#report-agenda" data-report-section-link><span class="report-section-number">05</span><strong>Agenda</strong><small>Conversão e sincronização.</small></a>
        <a class="report-section-link" href="#report-commercial" data-report-section-link><span class="report-section-number">06</span><strong>Comercial RS</strong><small>Pipeline e oportunidades.</small></a>
    </nav>
</section>

<div class="report-section-stack">
    <section class="card report-content-card executive-report-panel" id="report-growth">
        <header class="report-content-card-header"><span class="report-section-number">01</span><div><span class="eyebrow">Crescimento</span><h2>Base e evolução dos clientes</h2><p>Novas empresas, saúde da operação e clientes que demandam atenção.</p></div><a class="report-back-link" href="#report-directory">Voltar ao índice</a></header>
        <div class="executive-report-grid"><section><div class="section-heading"><div><span class="eyebrow">Base de clientes</span><h2>Novas empresas por mês</h2></div><a class="table-link" href="<?= View::e(Router::url('/reports/export?' . http_build_query($queryBase + ['type' => 'companies']))) ?>">Exportar</a></div><div class="executive-bars"><?php $growthMax = $max($companyGrowth); foreach ($companyGrowth as $row): ?><div><strong><?= View::e(date('m/Y', strtotime($row['label'] . '-01'))) ?></strong><span><i style="width:<?= min(100, ((int) $row['total'] / $growthMax) * 100) ?>%"></i></span><b><?= (int) $row['total'] ?></b></div><?php endforeach; ?><?php if (!$companyGrowth): ?><div class="empty-state">Nenhuma empresa cadastrada no período.</div><?php endif; ?></div></section><aside class="executive-insight-card"><span class="eyebrow">Base atual</span><h3>Movimentação</h3><dl><div><dt>Novas</dt><dd><?= $number($metrics['new_companies'] ?? 0) ?></dd></div><div><dt>Ativas</dt><dd><?= $number($metrics['active_companies'] ?? 0) ?></dd></div><div><dt>Inativas/suspensas</dt><dd><?= $number($metrics['inactive_companies'] ?? 0) ?></dd></div><div><dt>Atenção/urgentes</dt><dd><?= $number(($metrics['attention_companies'] ?? 0)+($metrics['critical_companies'] ?? 0)) ?></dd></div></dl></aside></div>
    </section>

    <section class="card report-content-card executive-report-panel" id="report-revenue">
        <header class="report-content-card-header"><span class="report-section-number">02</span><div><span class="eyebrow">Receita</span><h2>Assinaturas e cobranças</h2><p>Receita recorrente estimada, valores recebidos e vencimentos.</p></div><a class="report-back-link" href="#report-directory">Voltar ao índice</a></header>
        <div class="executive-report-grid"><section><div class="section-heading"><div><span class="eyebrow">Planos</span><h2>Receita recorrente por plano</h2></div><a class="table-link" href="<?= View::e(Router::url('/reports/export?' . http_build_query($queryBase + ['type' => 'revenue']))) ?>">Exportar cobranças</a></div><div class="executive-bars"><?php $planMax = $max($revenueByPlan); foreach ($revenueByPlan as $row): ?><div><strong><?= View::e($row['label']) ?></strong><span><i style="width:<?= min(100, ((float) $row['total'] / $planMax) * 100) ?>%"></i></span><b><?= $money($row['total']) ?></b></div><?php endforeach; ?><?php if (!$revenueByPlan): ?><div class="empty-state">Nenhuma assinatura ativa encontrada.</div><?php endif; ?></div></section><aside class="executive-insight-card"><span class="eyebrow">Financeiro</span><h3>Visão de cobrança</h3><dl><div><dt>Receita mensal estimada</dt><dd><?= $money($metrics['mrr'] ?? 0) ?></dd></div><div><dt>A receber</dt><dd><?= $money($metrics['expected'] ?? 0) ?></dd></div><div><dt>Faturas vencidas</dt><dd><?= $number($metrics['overdue_count'] ?? 0) ?></dd></div><div><dt>Em atraso</dt><dd><?= $money($metrics['overdue_amount'] ?? 0) ?></dd></div></dl><a class="btn btn-primary btn-block" href="<?= View::e(Router::url('/billing?tab=invoices')) ?>">Abrir cobranças</a></aside></div>
        <div class="executive-table"><div class="section-heading"><div><span class="eyebrow">Cobranças</span><h2>Últimos vencimentos</h2></div></div><div class="table-wrap"><table><thead><tr><th>Empresa</th><th>Cobrança</th><th>Valor</th><th>Vencimento</th><th>Situação</th></tr></thead><tbody><?php foreach ($recentInvoices as $invoice): ?><tr><td><?= View::e($invoice['tenant_name']) ?></td><td><?= View::e($invoice['invoice_number']) ?></td><td><?= $money($invoice['amount']) ?></td><td><?= View::e(date('d/m/Y', strtotime($invoice['due_date']))) ?></td><td><span class="badge badge-<?= View::e($invoice['status']) ?>"><?= View::e($invoice['status']) ?></span></td></tr><?php endforeach; ?><?php if (!$recentInvoices): ?><tr><td colspan="5"><div class="empty-state">Nenhuma cobrança encontrada.</div></td></tr><?php endif; ?></tbody></table></div></div>
    </section>

    <section class="card report-content-card executive-report-panel" id="report-usage">
        <header class="report-content-card-header"><span class="report-section-number">03</span><div><span class="eyebrow">Uso da plataforma</span><h2>Adoção e movimento por empresa</h2><p>Veja a tendência diária e compare os clientes mais ativos.</p></div><a class="report-back-link" href="#report-directory">Voltar ao índice</a></header>
        <div class="report-chart-layout"><section class="report-chart-card"><div class="section-heading"><div><span class="eyebrow">Volume diário</span><h2>Mensagens processadas</h2></div></div><div class="report-svg-chart" data-report-line-chart data-series="<?= View::e((string) $lineSeries) ?>"></div><div class="report-chart-legend"><span><i class="is-total"></i>Total</span><span><i class="is-incoming"></i>Recebidas</span><span><i class="is-ai"></i>IA</span></div></section><aside class="report-ranking-card"><div class="section-heading"><div><span class="eyebrow">Ranking</span><h2>Empresas com maior uso</h2></div><a class="table-link" href="<?= View::e(Router::url('/reports/export?' . http_build_query($queryBase + ['type' => 'usage']))) ?>">Exportar</a></div><div class="report-ranking"><?php $usageMax=$max($usageByTenant,'messages'); foreach (array_slice($usageByTenant,0,8) as $idx=>$row): ?><a href="<?= View::e(Router::url('/companies/overview?id=' . (int) $row['id'])) ?>"><b><?= $idx+1 ?></b><span><strong><?= View::e($row['name']) ?></strong><i><em style="width:<?= min(100,((int)$row['messages']/$usageMax)*100) ?>%"></em></i></span><small><?= $number($row['messages']) ?></small></a><?php endforeach; ?><?php if (!$usageByTenant): ?><div class="empty-state">Nenhum uso registrado no período.</div><?php endif; ?></div></aside></div>
        <div class="executive-low-usage"><div class="section-heading"><div><span class="eyebrow">Atenção comercial</span><h2>Clientes com pouco uso</h2></div></div><div class="executive-card-list"><?php foreach ($lowUsage as $row): ?><a href="<?= View::e(Router::url('/companies/overview?id=' . (int) $row['id'])) ?>"><strong><?= View::e($row['name']) ?></strong><span><?= $number($row['messages']) ?> mensagem(ns) no período</span><small>Última mensagem: <?= $row['last_message_at'] ? View::e(date('d/m/Y H:i', strtotime($row['last_message_at']))) : 'sem uso registrado' ?></small></a><?php endforeach; ?><?php if (!$lowUsage): ?><div class="empty-state">Nenhuma empresa com baixo uso dentro do filtro.</div><?php endif; ?></div></div>
    </section>

    <section class="card report-content-card executive-report-panel" id="report-automation">
        <header class="report-content-card-header"><span class="report-section-number">04</span><div><span class="eyebrow">IA e automações</span><h2>Qualidade das respostas e automações</h2><p>Acompanhe automações, conexões e situações que precisam de atenção.</p></div><a class="report-back-link" href="#report-directory">Voltar ao índice</a></header>
        <div class="report-chart-layout"><section class="report-chart-card"><div class="section-heading"><div><span class="eyebrow">Ocorrências por dia</span><h2>Tendência de atenção</h2></div></div><div class="report-svg-chart is-compact" data-report-line-chart data-series="<?= View::e((string) $failureSeries) ?>" data-single-series="total"></div></section><aside class="executive-insight-card"><span class="eyebrow">Conectividade</span><h3>Operação das integrações</h3><dl><div><dt>WhatsApps conectados</dt><dd><?= $number($metrics['connected_instances'] ?? 0) ?></dd></div><div><dt>Desconectados</dt><dd><?= $number($metrics['disconnected_instances'] ?? 0) ?></dd></div><div><dt>Assistente virtual</dt><dd><?= $number($metrics['ai_failures'] ?? 0) ?></dd></div><div><dt>Automações</dt><dd><?= $number($metrics['n8n_failures'] ?? 0) ?></dd></div><div><dt>Agenda</dt><dd><?= $number($metrics['google_sync_failures'] ?? 0) ?></dd></div></dl><a class="btn btn-outline btn-block" href="<?= View::e(Router::url('/central-operacao?tab=monitoring')) ?>">Abrir monitoramento</a></aside></div>
        <div class="executive-card-list executive-failure-list"><div class="section-heading"><div><span class="eyebrow">Situações recorrentes</span><h2>Pontos de atenção por tipo</h2></div><a class="table-link" href="<?= View::e(Router::url('/reports/export?' . http_build_query($queryBase + ['type' => 'failures']))) ?>">Exportar detalhes</a></div><?php foreach ($failures as $row): ?><article><span class="badge <?= $row['source'] === 'IA' ? 'badge-info' : 'badge-warning' ?>"><?= View::e(OperationalLanguageService::replaceTechnicalTerms((string) $row['source'])) ?></span><strong><?= View::e($row['label']) ?></strong><b><?= (int) $row['total'] ?></b></article><?php endforeach; ?><?php if (!$failures): ?><div class="empty-state">Nenhuma situação de atenção registrada no período.</div><?php endif; ?></div>
    </section>

    <section class="card report-content-card executive-report-panel" id="report-agenda">
        <header class="report-content-card-header"><span class="report-section-number">05</span><div><span class="eyebrow">Agenda</span><h2>Compromissos e conversão</h2><p>Analise confirmações, cancelamentos e uso da agenda pelos clientes.</p></div><a class="report-back-link" href="#report-directory">Voltar ao índice</a></header>
        <div class="executive-report-grid"><section><div class="section-heading"><div><span class="eyebrow">Conversão</span><h2>Situação dos compromissos</h2></div></div><div class="executive-bars"><?php $agendaMax = $max($agendaStatus); foreach ($agendaStatus as $row): ?><div><strong><?= View::e($row['label']) ?></strong><span><i style="width:<?= min(100, ((int) $row['total'] / $agendaMax) * 100) ?>%"></i></span><b><?= (int) $row['total'] ?></b></div><?php endforeach; ?><?php if (!$agendaStatus): ?><div class="empty-state">Nenhum compromisso no período.</div><?php endif; ?></div></section><aside class="executive-insight-card"><span class="eyebrow">Agenda</span><h3>Resultados</h3><dl><div><dt>Total</dt><dd><?= $number($metrics['appointments'] ?? 0) ?></dd></div><div><dt>Confirmados/concluídos</dt><dd><?= $number($metrics['appointments_confirmed'] ?? 0) ?></dd></div><div><dt>Cancelados/no-show</dt><dd><?= $number($metrics['appointments_cancelled'] ?? 0) ?></dd></div><div><dt>Conversão</dt><dd><?= number_format((float) ($metrics['agenda_conversion'] ?? 0), 1, ',', '.') ?>%</dd></div></dl><a class="btn btn-outline btn-block" href="<?= View::e(Router::url('/calendar')) ?>">Abrir agenda</a></aside></div>
    </section>

    <section class="card report-content-card executive-report-panel" id="report-commercial">
        <header class="report-content-card-header"><span class="report-section-number">06</span><div><span class="eyebrow">Comercial RS</span><h2>Pipeline e resultado comercial</h2><p>Acompanhe oportunidades abertas, valores e desempenho das etapas.</p></div><a class="report-back-link" href="#report-directory">Voltar ao índice</a></header>
        <div class="section-heading"><div><span class="eyebrow">Funil RS Connect</span><h2>Oportunidades por etapa</h2></div><div><a class="table-link" href="<?= View::e(Router::url('/reports/export?' . http_build_query($queryBase + ['type' => 'commercial']))) ?>">Exportar CRM</a> · <a class="table-link" href="<?= View::e(Router::url('/crm')) ?>">Abrir CRM</a></div></div>
        <div class="report-funnel is-admin"><?php $commercialMax=$max($commercialStages); foreach ($commercialStages as $row): $width=max(18,min(100,((int)$row['total']/$commercialMax)*100)); ?><article><span><?= View::e($row['label']) ?></span><div style="width:<?= $width ?>%"><strong><?= (int)$row['total'] ?></strong><small><?= $money($row['value']) ?></small></div></article><?php endforeach; ?><?php if (!$commercialStages): ?><div class="empty-state">Conclua a atualização do banco indicada para carregar a área comercial.</div><?php endif; ?></div>
    </section>
</div>
<script src="<?= View::e(Router::url('/assets/js/reports.js?v=36.14.0')) ?>" defer></script>
</div>
