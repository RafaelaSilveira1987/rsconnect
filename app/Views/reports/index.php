<?php

use App\Core\Auth;
use App\Core\Router;
use App\Core\View;
use App\Core\PublicId;

$money = static fn (float|int|string $value): string => 'R$ ' . number_format((float) $value, 2, ',', '.');
$number = static fn (float|int|string $value): string => number_format((float) $value, 0, ',', '.');
$percent = static fn (float|int|string $value): string => number_format((float) $value, 1, ',', '.') . '%';
$duration = static function (int|float|string $seconds): string {
    $seconds = max(0, (int) round((float) $seconds));
    if ($seconds <= 0) return 'Sem dados';
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
$icon = static function (string $name): string {
    $paths = [
        'chat' => '<path d="M21 15a4 4 0 0 1-4 4H8l-5 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4z"/><path d="M8 9h8M8 13h5"/>',
        'reply' => '<path d="m9 17-5-5 5-5"/><path d="M4 12h10a6 6 0 0 1 6 6v1"/>',
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
$statusLabels = [
    'scheduled' => 'Agendado', 'confirmed' => 'Confirmado', 'completed' => 'Concluído',
    'cancelled' => 'Cancelado', 'no_show' => 'Não compareceu', 'rejected' => 'Rejeitado',
];
$weekdayLabels = ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'];
$queryBase = array_filter([
    'start' => $filters['start'] ?? '',
    'end' => $filters['end'] ?? '',
], static fn ($value) => $value !== '');
$comparisons = $comparisons ?? [];
$heatmap = $heatmap ?? [];
$agendaAvailability = $agendaAvailability ?? [];
$agendaResults = $agendaResults ?? [];
$insights = $insights ?? [];
$warnings = $warnings ?? [];
$heatmapLookup = [];
$heatmapMax = 1;
foreach ($heatmap as $cell) {
    $key = ((int) ($cell['weekday_index'] ?? 0)) . ':' . ((int) ($cell['hour_index'] ?? 0));
    $heatmapLookup[$key] = (int) ($cell['total'] ?? 0);
    $heatmapMax = max($heatmapMax, (int) ($cell['total'] ?? 0));
}
$hours = range(7, 22);
$lineSeries = json_encode(array_map(static fn (array $row): array => [
    'label' => date('d/m', strtotime((string) $row['label'])),
    'total' => (int) ($row['total'] ?? 0),
    'incoming' => (int) ($row['incoming'] ?? 0),
    'ai' => (int) ($row['ai'] ?? 0),
], $byDay ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
if (!is_string($lineSeries)) $lineSeries = '[]';
$lineSeriesB64 = base64_encode($lineSeries);
$responseDistribution = [
    ['label' => 'Recebidas', 'value' => (int) ($metrics['incoming_messages'] ?? 0)],
    ['label' => 'IA', 'value' => (int) ($metrics['ai_replies'] ?? 0)],
    ['label' => 'Equipe', 'value' => (int) ($metrics['human_replies'] ?? 0)],
    ['label' => 'Sistema', 'value' => (int) ($metrics['system_replies'] ?? 0)],
];
$responseSeries = json_encode($responseDistribution, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($responseSeries)) $responseSeries = '[]';
$donutSeries = json_encode([
    ['label' => 'IA', 'value' => (int) ($metrics['ai_replies'] ?? 0)],
    ['label' => 'Equipe', 'value' => (int) ($metrics['human_replies'] ?? 0)],
    ['label' => 'Automação/Sistema', 'value' => (int) ($metrics['system_replies'] ?? 0)],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
if (!is_string($donutSeries)) $donutSeries = '[]';
$donutSeriesB64 = base64_encode($donutSeries);
$periodLabel = date('d/m/Y', strtotime((string) ($filters['start'] ?? 'now'))) . ' — ' . date('d/m/Y', strtotime((string) ($filters['end'] ?? 'now')));
$teamReportUrl = (Auth::can('reports.team.view_own') || Auth::can('reports.team.view_all'))
    ? Router::url('/reports/team?' . http_build_query($queryBase))
    : '#client-report-team';
$hourMap = array_fill(0, 24, 0);
foreach (($byHour ?? []) as $row) $hourMap[(int) ($row['label'] ?? 0)] = (int) ($row['total'] ?? 0);
$hourMax = max(1, ...array_values($hourMap));
$quickReports = [
    ['name' => 'Conversas do período', 'type' => 'Atendimento', 'metric' => $number($metrics['conversations'] ?? 0) . ' conversas', 'url' => Router::url('/reports/export?' . http_build_query($queryBase + ['type' => 'conversations']))],
    ['name' => 'Oportunidades comerciais', 'type' => 'CRM', 'metric' => $number($metrics['crm_leads'] ?? 0) . ' oportunidades', 'url' => Router::url('/reports/export?' . http_build_query($queryBase + ['type' => 'leads']))],
    ['name' => 'Cobranças da empresa', 'type' => 'Financeiro', 'metric' => $money($metrics['received_amount'] ?? 0) . ' recebido', 'url' => Router::url('/reports/export?' . http_build_query($queryBase + ['type' => 'billing']))],
];
?>
<link rel="stylesheet" href="<?= View::e(Router::url('/assets/css/reports.css?v=36.15.1')) ?>">
<div class="executive-report-page client-manager-report report-v3646 report-v3647 report-v36140 report-v36150">
    <header class="rs-admin-report-header rs-client-report-header">
        <div>
            <nav class="rs-report-breadcrumb" aria-label="Navegação"><span>Relatórios</span><b>/</b><strong>Visão geral</strong></nav>
            <h1>Painel executivo</h1>
            <p>Indicadores da sua empresa para acompanhar atendimento, equipe, automações, agenda e resultados em um só lugar.</p>
        </div>
        <div class="rs-admin-report-header-actions">
            <?php if (Auth::can('reports.schedule.manage')): ?><a class="btn btn-outline" href="<?= View::e(Router::url('/reports/automatic')) ?>">Relatórios automáticos</a><?php endif; ?>
            <?php if (Auth::can('reports.team.view_own') || Auth::can('reports.team.view_all')): ?><a class="btn btn-outline" href="<?= View::e($teamReportUrl) ?>">Equipe e profissionais</a><?php endif; ?>
            <button class="btn btn-outline" type="button" onclick="window.location.reload()"><?= $icon('refresh') ?> Atualizar</button>
            <a class="btn btn-primary" href="<?= View::e(Router::url('/reports/export?' . http_build_query($queryBase + ['type' => 'conversations']))) ?>"><?= $icon('download') ?> Exportar</a>
        </div>
    </header>

    <form class="card rs-admin-report-toolbar rs-client-report-toolbar" method="get" action="<?= View::e(Router::url('/reports')) ?>">
        <div class="rs-admin-date-range">
            <label><span>De</span><input type="date" name="start" value="<?= View::e($filters['start']) ?>"></label>
            <i>→</i>
            <label><span>Até</span><input type="date" name="end" value="<?= View::e($filters['end']) ?>"></label>
        </div>
        <div class="rs-client-filter-copy"><span>Período analisado</span><strong><?= View::e($periodLabel) ?></strong><small>Os indicadores respeitam o fuso e os dados da sua empresa.</small></div>
        <div class="rs-admin-toolbar-actions"><button class="btn btn-primary" type="submit"><?= $icon('filter') ?> Aplicar filtros</button><a class="btn btn-quiet" href="<?= View::e(Router::url('/reports')) ?>">Limpar</a></div>
    </form>

    <?php if ($warnings): ?><div class="flash warning executive-report-warning"><strong>Alguns indicadores estão sendo atualizados.</strong><span><?= View::e(implode(' · ', $warnings)) ?></span></div><?php endif; ?>

    <section class="rs-admin-kpi-grid" aria-label="Principais resultados">
        <?php $t = $trend($comparisons['conversations'] ?? null); ?>
        <a class="card rs-admin-kpi is-blue" href="<?= View::e(Router::url('/conversations')) ?>"><span class="rs-admin-kpi-icon"><?= $icon('chat') ?></span><div><small>Conversas iniciadas</small><strong><?= $number($metrics['conversations'] ?? 0) ?></strong><em class="report-trend <?= $t['class'] ?>"><?= View::e($t['text']) ?></em></div></a>
        <?php $t = $trend($comparisons['responded_conversations'] ?? null); ?>
        <a class="card rs-admin-kpi is-indigo" href="<?= View::e(Router::url('/conversations')) ?>"><span class="rs-admin-kpi-icon"><?= $icon('reply') ?></span><div><small>Conversas respondidas</small><strong><?= $number($metrics['responded_conversations'] ?? 0) ?></strong><em class="report-trend <?= $t['class'] ?>"><?= View::e($t['text']) ?></em></div></a>
        <?php $t = $trend($comparisons['human_conversations'] ?? null); ?>
        <a class="card rs-admin-kpi is-teal" href="<?= View::e($teamReportUrl) ?>"><span class="rs-admin-kpi-icon"><?= $icon('human') ?></span><div><small>Atendimentos humanos</small><strong><?= $number($metrics['human_conversations'] ?? 0) ?></strong><em class="report-trend <?= $t['class'] ?>"><?= $number($metrics['human_replies'] ?? 0) ?> respostas da equipe</em></div></a>
        <a class="card rs-admin-kpi is-purple" href="<?= View::e($teamReportUrl) ?>"><span class="rs-admin-kpi-icon"><?= $icon('clock') ?></span><div><small>Tempo médio da 1ª resposta</small><strong><?= View::e($duration($metrics['avg_first_response_seconds'] ?? 0)) ?></strong><em><?= $number($metrics['first_responses_measured'] ?? 0) ?> respostas medidas</em></div></a>
        <a class="card rs-admin-kpi is-orange" href="<?= View::e(Router::url('/calendar')) ?>"><span class="rs-admin-kpi-icon"><?= $icon('calendar') ?></span><div><small>Agendamentos</small><strong><?= $number($metrics['appointments'] ?? 0) ?></strong><em><?= $percent($metrics['agenda_conversion'] ?? 0) ?> confirmados/concluídos</em></div></a>
        <a class="card rs-admin-kpi is-green" href="<?= View::e(Router::url('/calendar')) ?>"><span class="rs-admin-kpi-icon"><?= $icon('check') ?></span><div><small>Comparecimento</small><strong><?= $percent($metrics['attendance_rate'] ?? 0) ?></strong><em><?= $number($metrics['appointments_completed'] ?? 0) ?> concluído(s)</em></div></a>
        <?php $t = $trend($comparisons['ai_replies'] ?? null); ?>
        <a class="card rs-admin-kpi is-violet" href="#client-report-team"><span class="rs-admin-kpi-icon"><?= $icon('spark') ?></span><div><small>Uso da IA</small><strong><?= $number($metrics['ai_replies'] ?? 0) ?></strong><em class="report-trend <?= $t['class'] ?>"><?= $percent($metrics['ai_share'] ?? 0) ?> das respostas</em></div></a>
        <a class="card rs-admin-kpi is-red" href="#client-report-attention"><span class="rs-admin-kpi-icon"><?= $icon('alert') ?></span><div><small>Itens que precisam de atenção</small><strong><?= $number($metrics['situations_open'] ?? 0) ?></strong><em><?= $number($metrics['unread'] ?? 0) ?> mensagem(ns) não lida(s)</em></div></a>
    </section>

    <section class="rs-admin-chart-grid rs-admin-chart-grid-primary">
        <article class="card rs-admin-dashboard-card rs-admin-chart-wide">
            <header><div><h2>Atendimentos ao longo do tempo</h2><p>Mensagens recebidas, respostas enviadas e participação da IA.</p></div><span class="rs-admin-period-pill"><?= View::e($periodLabel) ?></span></header>
            <div class="report-svg-chart rs-admin-main-line-chart" data-report-line-chart data-series-b64="<?= View::e($lineSeriesB64) ?>"></div>
            <div class="report-chart-legend"><span><i class="is-total"></i>Total</span><span><i class="is-incoming"></i>Recebidas</span><span><i class="is-ai"></i>IA</span></div>
        </article>
        <article class="card rs-admin-dashboard-card">
            <header><div><h2>Distribuição das interações</h2><p>Entrada dos clientes e respostas enviadas.</p></div></header>
            <div class="rs-admin-donut-layout"><div class="report-donut" data-report-donut data-series="<?= View::e($responseSeries) ?>" data-center="<?= $number($metrics['total_messages'] ?? 0) ?>"></div><div class="rs-admin-donut-legend"><?php foreach ($responseDistribution as $index => $row): ?><div><i class="is-color-<?= ($index % 5) + 1 ?>"></i><span><?= View::e($row['label']) ?></span><strong><?= $number($row['value']) ?></strong></div><?php endforeach; ?></div></div>
        </article>
        <article class="card rs-admin-dashboard-card">
            <header><div><h2>Interações por horário</h2><p>Horários com maior procura pela sua empresa.</p></div></header>
            <div class="rs-admin-hour-chart" aria-label="Interações por horário"><?php foreach ($hourMap as $hour => $total): $height = max(3, min(100, ($total / $hourMax) * 100)); ?><article title="<?= str_pad((string)$hour,2,'0',STR_PAD_LEFT) ?>h — <?= $number($total) ?>"><span style="height:<?= $height ?>%"></span><small><?= ($hour % 2 === 0) ? str_pad((string)$hour,2,'0',STR_PAD_LEFT) : '' ?></small></article><?php endforeach; ?></div>
        </article>
    </section>

    <section class="rs-admin-chart-grid rs-admin-chart-grid-secondary">
        <article class="card rs-admin-dashboard-card rs-admin-team-card">
            <header><div><h2>Desempenho da equipe</h2><p>Respostas enviadas e conversas atendidas por profissional.</p></div><a href="<?= View::e($teamReportUrl) ?>">Ver relatório completo →</a></header>
            <div class="table-wrap"><table class="rs-admin-compact-table"><thead><tr><th>Profissional</th><th>Respostas</th><th>Conversas</th></tr></thead><tbody><?php foreach (array_slice($teamPerformance ?? [],0,6) as $row): ?><tr><td><span class="rs-admin-person"><b><?= View::e(strtoupper(substr((string) $row['label'], 0, 1))) ?></b><span><strong><?= View::e($row['label']) ?></strong><small>Atendimento humano</small></span></span></td><td><?= $number($row['total']) ?></td><td><?= $number($row['conversations']) ?></td></tr><?php endforeach; ?><?php if (empty($teamPerformance)): ?><tr><td colspan="3"><div class="empty-state">Nenhuma resposta humana registrada no período.</div></td></tr><?php endif; ?></tbody></table></div>
        </article>
        <article class="card rs-admin-dashboard-card">
            <header><div><h2>Resultado da agenda</h2><p>Situação atual dos compromissos do período.</p></div><a href="<?= View::e(Router::url('/calendar')) ?>">Abrir agenda →</a></header>
            <div class="executive-bars rs-client-agenda-bars"><?php $agendaMax=max(1,...array_map(static fn($r)=>(int)($r['total']??0),$agendaResults?:[['total'=>1]])); foreach ($agendaResults as $row): ?><div><strong><?= View::e($row['label']) ?></strong><span><i style="width:<?= min(100,((int)$row['total']/$agendaMax)*100) ?>%"></i></span><b><?= $number($row['total']) ?></b></div><?php endforeach; ?><?php if (!$agendaResults): ?><div class="empty-state">Nenhum compromisso no período.</div><?php endif; ?></div>
        </article>
        <article class="card rs-admin-dashboard-card rs-admin-ai-summary">
            <header><div><h2>Uso da IA</h2><p>Participação da automação no atendimento.</p></div><a href="#client-report-team">Ver detalhes →</a></header>
            <div class="rs-admin-ai-ring" style="--ai-share:<?= min(100,max(0,(float)($metrics['ai_share']??0))) ?>%"><span><strong><?= $percent($metrics['ai_share'] ?? 0) ?></strong><small>das respostas</small></span></div>
            <dl><div><dt>Respostas da IA</dt><dd><?= $number($metrics['ai_replies'] ?? 0) ?></dd></div><div><dt>Respostas humanas</dt><dd><?= $number($metrics['human_replies'] ?? 0) ?></dd></div><div><dt>Não concluídas</dt><dd><?= $number($metrics['ai_errors'] ?? 0) ?></dd></div></dl>
        </article>
    </section>

    <section class="card rs-client-attention-panel" id="client-report-attention">
        <header><div><span class="eyebrow">Acompanhamento</span><h2>Conversas que precisam de atenção</h2><p>Priorize mensagens não lidas e atendimentos humanos ainda em aberto.</p></div><a href="<?= View::e(Router::url('/conversations')) ?>">Abrir conversas →</a></header>
        <div class="rs-client-attention-grid"><?php foreach (array_slice($attention ?? [],0,6) as $row): ?><a href="<?= View::e(Router::url('/conversations?conversation_uuid=' . rawurlencode(PublicId::encode('conversation', (int) $row['id'])))) ?>"><span><strong><?= View::e((string)(($row['contact_name']??'') ?: ($row['phone']??'Contato'))) ?></strong><small><?= (int)($row['unread_count']??0) ?> não lida(s) · <?= ($row['attendance_mode']??'')==='human'?'atendimento humano':'acompanhamento' ?></small></span><b><?= !empty($row['last_message_at']) ? View::e(date('d/m H:i',strtotime((string)$row['last_message_at']))) : 'sem horário' ?></b></a><?php endforeach; ?><?php if (empty($attention)): ?><div class="empty-state">Nenhuma conversa exige ação imediata.</div><?php endif; ?></div>
    </section>

    <section class="card rs-admin-ready-reports">
        <header><div><h2>Relatórios prontos para exportar</h2><p>Arquivos atualizados conforme o período selecionado.</p></div><span><?= View::e($periodLabel) ?></span></header>
        <div class="table-wrap"><table><thead><tr><th>Nome do relatório</th><th>Tipo</th><th>Período</th><th>Indicador</th><th>Ações</th></tr></thead><tbody><?php foreach ($quickReports as $row): ?><tr><td><span class="rs-admin-report-name"><i><?= $icon('report') ?></i><strong><?= View::e($row['name']) ?></strong></span></td><td><span class="badge"><?= View::e($row['type']) ?></span></td><td><?= View::e($periodLabel) ?></td><td><?= View::e($row['metric']) ?></td><td><a class="rs-admin-download-action" href="<?= View::e($row['url']) ?>" aria-label="Exportar <?= View::e($row['name']) ?>"><?= $icon('download') ?></a></td></tr><?php endforeach; ?></tbody></table></div>
    </section>

    <?php if ($insights): ?><section class="card rs-admin-insights-strip"><div><span class="eyebrow">Insights automáticos</span><h2>Leitura rápida do período</h2></div><div class="report-insights-grid is-compact"><?php foreach ($insights as $item): ?><article class="report-insight is-<?= View::e($item['tone'] ?? 'info') ?>"><span class="report-insight-dot"></span><div><strong><?= View::e($item['title'] ?? '') ?></strong><p><?= View::e($item['text'] ?? '') ?></p></div></article><?php endforeach; ?></div></section><?php endif; ?>

    <div class="rs-admin-detailed-heading"><span class="eyebrow">Análises detalhadas</span><h2>Aprofunde cada resultado</h2><p>Os blocos abaixo mantêm os mapas de calor, a visão da equipe, o CRM e os detalhes da agenda.</p></div>
    <section class="card report-section-directory" id="client-report-directory" aria-label="Navegação do relatório gerencial">
        <div class="section-heading report-directory-heading"><div><span class="eyebrow">Leitura do período</span><h2>Explore os resultados por assunto</h2><p>Os blocos ficam abertos na mesma página para facilitar comparação, impressão e tomada de decisão.</p></div></div>
        <nav class="report-section-card-grid report-section-card-grid-client">
            <a class="report-section-link" href="#client-report-overview" data-report-section-link><span class="report-section-number">01</span><strong>Visão geral</strong><small>Volume diário e prioridades.</small></a>
            <a class="report-section-link" href="#client-report-service" data-report-section-link><span class="report-section-number">02</span><strong>Horários de pico</strong><small>Mapa de calor da demanda.</small></a>
            <a class="report-section-link" href="#client-report-team" data-report-section-link><span class="report-section-number">03</span><strong>IA e equipe</strong><small>Automação x atendimento humano.</small></a>
            <a class="report-section-link" href="#client-report-results" data-report-section-link><span class="report-section-number">04</span><strong>CRM e agenda</strong><small>Funis e conversão.</small></a>
        </nav>
    </section>

    <div class="report-section-stack client-report-section-stack">
        <section class="card report-content-card client-report-panel" id="client-report-overview">
            <header class="report-content-card-header"><span class="report-section-number">01</span><div><span class="eyebrow">Visão geral</span><h2>Evolução do atendimento</h2><p>Compare volume total, mensagens recebidas e participação da IA ao longo dos dias.</p></div><a class="report-back-link" href="#client-report-directory">Voltar ao índice</a></header>
            <div class="report-chart-layout">
                <section class="report-chart-card"><div class="section-heading"><div><span class="eyebrow">Movimento diário</span><h2>Atendimento por dia</h2></div><span class="badge"><?= View::e(date('d/m', strtotime($filters['start']))) ?> → <?= View::e(date('d/m', strtotime($filters['end']))) ?></span></div><div class="report-svg-chart" data-report-line-chart data-series-b64="<?= View::e($lineSeriesB64) ?>" aria-label="Gráfico de mensagens por dia"></div><div class="report-chart-legend"><span><i class="is-total"></i>Total</span><span><i class="is-incoming"></i>Recebidas</span><span><i class="is-ai"></i>IA</span></div></section>
                <aside class="client-report-summary-card"><span class="eyebrow">Resumo</span><h3>Leitura rápida</h3><dl><div><dt>Total de mensagens</dt><dd><?= $number($metrics['total_messages'] ?? 0) ?></dd></div><div><dt>Média por conversa</dt><dd><?= number_format((float) ($metrics['avg_messages_per_conversation'] ?? 0), 1, ',', '.') ?></dd></div><div><dt>Encerradas</dt><dd><?= $number($metrics['closed_conversations'] ?? 0) ?></dd></div><div><dt>Mensagens não enviadas</dt><dd><?= $number($metrics['failed_messages'] ?? 0) ?></dd></div></dl><a class="btn btn-outline btn-block" href="<?= View::e(Router::url('/conversations')) ?>">Abrir conversas</a></aside>
            </div>
            <div class="client-report-priority-grid">
                <section><div class="section-heading"><div><span class="eyebrow">Atenção</span><h2>Conversas prioritárias</h2></div></div><div class="client-report-list"><?php foreach ($attention as $item): ?><a href="<?= View::e(Router::url('/conversations?conversation_id=' . (int) $item['id'])) ?>"><span><strong><?= View::e($item['contact_name'] ?: $item['phone']) ?></strong><small><?= View::e($item['attendance_mode']) ?> · <?= View::e($item['last_message_at'] ?? '') ?></small></span><b><?= (int) $item['unread_count'] ?></b></a><?php endforeach; ?><?php if (!$attention): ?><div class="empty-state">Nenhuma conversa pendente neste momento.</div><?php endif; ?></div></section>
                <section><div class="section-heading"><div><span class="eyebrow">Relacionamento</span><h2>Contatos com mais interações</h2></div></div><div class="client-report-list"><?php foreach ($topContacts as $item): ?><a href="<?= View::e(Router::url('/contacts')) ?>"><span><strong><?= View::e($item['label']) ?></strong><small><?= View::e($item['phone']) ?> · última interação <?= View::e(date('d/m H:i', strtotime($item['last_message_at']))) ?></small></span><b><?= (int) $item['total'] ?></b></a><?php endforeach; ?><?php if (!$topContacts): ?><div class="empty-state">Nenhum contato com mensagens no período.</div><?php endif; ?></div></section>
            </div>
        </section>

        <section class="card report-content-card client-report-panel" id="client-report-service">
            <header class="report-content-card-header"><span class="report-section-number">02</span><div><span class="eyebrow">Atendimento</span><h2>Quando seus clientes mais procuram você</h2><p>O mapa de calor mostra o volume de mensagens recebidas por dia da semana e horário.</p></div><a class="report-back-link" href="#client-report-directory">Voltar ao índice</a></header>
            <div class="client-report-two-columns report-heatmap-layout">
                <section class="report-heatmap-card"><div class="section-heading"><div><span class="eyebrow">Horários de pico</span><h2>Mapa de calor</h2></div></div><div class="report-heatmap-wrap"><div class="report-heatmap"><div class="report-heatmap-corner"></div><?php foreach ($hours as $hour): ?><div class="report-heatmap-hour"><?= str_pad((string) $hour, 2, '0', STR_PAD_LEFT) ?>h</div><?php endforeach; ?><?php foreach ($weekdayLabels as $dayIndex => $dayLabel): ?><div class="report-heatmap-day"><?= View::e($dayLabel) ?></div><?php foreach ($hours as $hour): $value = $heatmapLookup[$dayIndex . ':' . $hour] ?? 0; $level = $value > 0 ? max(.12, $value / $heatmapMax) : 0; ?><div class="report-heatmap-cell" style="--heat:<?= number_format($level, 3, '.', '') ?>" title="<?= View::e($dayLabel . ' ' . str_pad((string) $hour, 2, '0', STR_PAD_LEFT) . 'h: ' . $value . ' mensagem(ns)') ?>"><span><?= $value ?: '' ?></span></div><?php endforeach; ?><?php endforeach; ?></div></div><div class="report-heatmap-scale"><span>Menor demanda</span><i></i><span>Maior demanda</span></div></section>
                <aside class="client-report-summary-card"><span class="eyebrow">Eficiência</span><h3>Indicadores de atendimento</h3><dl><div><dt>Respostas enviadas</dt><dd><?= $number($metrics['outgoing_messages'] ?? 0) ?></dd></div><div><dt>Respostas humanas</dt><dd><?= $number($metrics['human_replies'] ?? 0) ?></dd></div><div><dt>Participação humana</dt><dd><?= $percent($metrics['human_share'] ?? 0) ?></dd></div><div><dt>Conversas abertas agora</dt><dd><?= $number($metrics['open_conversations'] ?? 0) ?></dd></div></dl></aside>
            </div>
        </section>

        <section class="card report-content-card client-report-panel" id="client-report-team">
            <header class="report-content-card-header"><span class="report-section-number">03</span><div><span class="eyebrow">IA, equipe e sistema</span><h2>Quem respondeu seus clientes</h2><p>Separe respostas da IA, respostas humanas e mensagens automáticas do sistema para enxergar a automação com precisão.</p></div><a class="report-back-link" href="#client-report-directory">Voltar ao índice</a></header>
            <div class="report-ai-layout">
                <section class="report-donut-card"><div class="section-heading"><div><span class="eyebrow">Distribuição</span><h2>IA x equipe x sistema</h2></div></div><div class="report-donut" data-report-donut data-series-b64="<?= View::e($donutSeriesB64) ?>" data-center="<?= View::e($percent($metrics['ai_share'] ?? 0)) ?>"></div><div class="report-donut-summary"><div><span>IA</span><strong><?= $number($metrics['ai_replies'] ?? 0) ?></strong></div><div><span>Equipe</span><strong><?= $number($metrics['human_replies'] ?? 0) ?></strong></div><div><span>Automação/Sistema</span><strong><?= $number($metrics['system_replies'] ?? 0) ?></strong></div></div></section>
                <section><div class="section-heading"><div><span class="eyebrow">Equipe</span><h2>Respostas por responsável</h2></div></div><div class="executive-bars client-team-bars"><?php $teamMax = max(1, ...array_map(static fn($r) => (int) ($r['total'] ?? 0), $teamPerformance ?: [['total'=>1]])); foreach ($teamPerformance as $row): ?><div><strong><?= View::e($row['label']) ?></strong><span><i style="width:<?= min(100,((int)$row['total']/$teamMax)*100) ?>%"></i></span><b><?= (int)$row['total'] ?></b><small><?= (int)$row['conversations'] ?> conversa(s)</small></div><?php endforeach; ?><?php if (!$teamPerformance): ?><div class="empty-state">Nenhuma resposta humana registrada no período.</div><?php endif; ?></div></section>
                <aside class="client-report-summary-card"><span class="eyebrow">Assistente virtual</span><h3>Desempenho da IA</h3><dl><div><dt>Participação da IA</dt><dd><?= $percent($metrics['ai_share'] ?? 0) ?></dd></div><div><dt>Automação/Sistema</dt><dd><?= $percent($metrics['system_share'] ?? 0) ?></dd></div><div><dt>Execuções bem-sucedidas</dt><dd><?= $number($metrics['ai_success'] ?? 0) ?></dd></div><div><dt>Respostas não concluídas</dt><dd><?= $number($metrics['ai_errors'] ?? 0) ?></dd></div><div><dt>Agenda com atenção</dt><dd><?= $number($metrics['google_sync_errors'] ?? 0) ?></dd></div></dl></aside>
            </div>
        </section>

        <section class="card report-content-card client-report-panel" id="client-report-results">
            <header class="report-content-card-header"><span class="report-section-number">04</span><div><span class="eyebrow">CRM e agenda</span><h2>Do interesse ao resultado</h2><p>Veja onde as oportunidades estão no funil e em qual etapa os agendamentos avançam ou param.</p></div><a class="report-back-link" href="#client-report-directory">Voltar ao índice</a></header>
            <div class="client-report-two-columns">
                <section><div class="section-heading"><div><span class="eyebrow">Comercial</span><h2>Oportunidades por etapa</h2></div><a class="table-link" href="<?= View::e(Router::url('/reports/export?' . http_build_query($queryBase + ['type' => 'leads']))) ?>">Exportar CRM</a></div><div class="report-funnel"><?php $crmMax = max(1, ...array_map(static fn($r) => (int) ($r['total'] ?? 0), $crmByStage ?: [['total'=>1]])); foreach ($crmByStage as $row): $width = max(18, min(100, ((int) $row['total'] / $crmMax) * 100)); ?><article><span><?= View::e($row['label']) ?></span><div style="width:<?= $width ?>%"><strong><?= (int)$row['total'] ?></strong><small><?= $money($row['value']) ?></small></div></article><?php endforeach; ?><?php if (!$crmByStage): ?><div class="empty-state">Nenhuma oportunidade cadastrada.</div><?php endif; ?></div></section>
                <aside class="client-report-summary-card"><span class="eyebrow">CRM</span><h3>Resultado comercial</h3><dl><div><dt>Oportunidades criadas</dt><dd><?= $number($metrics['crm_leads'] ?? 0) ?></dd></div><div><dt>Ganhas</dt><dd><?= $number($metrics['crm_won'] ?? 0) ?></dd></div><div><dt>Perdidas</dt><dd><?= $number($metrics['crm_lost'] ?? 0) ?></dd></div><div><dt>Taxa de conversão</dt><dd><?= $percent($metrics['crm_conversion'] ?? 0) ?></dd></div></dl><a class="btn btn-primary btn-block" href="<?= View::e(Router::url('/crm')) ?>">Abrir CRM</a></aside>
            </div>
            <div class="client-report-two-columns client-report-agenda-row">
                <section><div class="section-heading"><div><span class="eyebrow">Disponibilidade</span><h2>Uso da busca de horários</h2><p>Consultas e opções podem se repetir; por isso estes números mostram uso do recurso e não formam um funil de conversão.</p></div></div><div class="report-funnel is-agenda"><?php $agendaAvailabilityMax = max(1, ...array_map(static fn($r) => (int) ($r['total'] ?? 0), $agendaAvailability ?: [['total'=>1]])); foreach ($agendaAvailability as $row): $width=max(18,min(100,((int)$row['total']/$agendaAvailabilityMax)*100)); ?><article><span><?= View::e($row['label']) ?></span><div style="width:<?= $width ?>%"><strong><?= $number($row['total']) ?></strong></div></article><?php endforeach; ?></div></section>
                <section><div class="section-heading"><div><span class="eyebrow">Agenda</span><h2>Resultado dos compromissos</h2><p>Status atual dos compromissos cuja data está dentro do período selecionado.</p></div></div><div class="report-funnel is-agenda"><?php $agendaResultsMax = max(1, ...array_map(static fn($r) => (int) ($r['total'] ?? 0), $agendaResults ?: [['total'=>1]])); foreach ($agendaResults as $row): $width=max(18,min(100,((int)$row['total']/$agendaResultsMax)*100)); ?><article class="is-<?= View::e((string)($row['tone'] ?? 'neutral')) ?>"><span><?= View::e($row['label']) ?></span><div style="width:<?= $width ?>%"><strong><?= $number($row['total']) ?></strong></div></article><?php endforeach; ?></div></section>
            </div>
            <div class="client-report-two-columns client-report-agenda-row">
                <aside class="client-report-summary-card"><span class="eyebrow">Resumo da agenda</span><h3>Resultado do período</h3><dl><div><dt>Compromissos</dt><dd><?= $number($metrics['appointments'] ?? 0) ?></dd></div><div><dt>Confirmados</dt><dd><?= $number($metrics['appointments_confirmed'] ?? 0) ?></dd></div><div><dt>Concluídos</dt><dd><?= $number($metrics['appointments_completed'] ?? 0) ?></dd></div><div><dt>Rejeitados</dt><dd><?= $number($metrics['appointments_rejected'] ?? 0) ?></dd></div><div><dt>Cancelados</dt><dd><?= $number($metrics['appointments_cancelled'] ?? 0) ?></dd></div><div><dt>Não compareceram</dt><dd><?= $number($metrics['appointments_no_show'] ?? 0) ?></dd></div><div><dt>Resultado positivo</dt><dd><?= $percent($metrics['agenda_conversion'] ?? 0) ?></dd></div></dl><a class="btn btn-outline btn-block" href="<?= View::e(Router::url('/calendar')) ?>">Abrir agenda</a></aside>
                <aside class="client-report-summary-card"><span class="eyebrow">Leitura correta</span><h3>Como interpretar</h3><p class="muted-text">A busca de disponibilidade mede interações com o motor de horários. Já os compromissos são medidos pelo status atual e pela data marcada. Eles podem ter origens diferentes, inclusive cadastro manual e agendamentos anteriores ao fluxo conversacional.</p></aside>
            </div>
        </section>
    </div>
<script src="<?= View::e(Router::url('/assets/js/reports.js?v=36.15.1')) ?>" defer></script>
</div>
