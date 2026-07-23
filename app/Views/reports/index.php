<?php

use App\Core\Router;
use App\Core\View;

$money = static fn (float|int|string $value): string => 'R$ ' . number_format((float) $value, 2, ',', '.');
$number = static fn (float|int|string $value): string => number_format((float) $value, 0, ',', '.');
$percent = static fn (float|int|string $value): string => number_format((float) $value, 1, ',', '.') . '%';
$duration = static function (int $seconds): string {
    if ($seconds <= 0) return 'Sem dados';
    if ($seconds < 60) return $seconds . ' seg';
    if ($seconds < 3600) return floor($seconds / 60) . ' min ' . ($seconds % 60) . ' seg';
    return floor($seconds / 3600) . 'h ' . floor(($seconds % 3600) / 60) . 'min';
};
$trend = static function (?float $value): array {
    if ($value === null) return ['class' => 'is-neutral', 'text' => 'Sem base anterior'];
    if (abs($value) < .05) return ['class' => 'is-neutral', 'text' => 'Estável vs. período anterior'];
    return [
        'class' => $value > 0 ? 'is-up' : 'is-down',
        'text' => ($value > 0 ? '↑ ' : '↓ ') . number_format(abs($value), 1, ',', '.') . '% vs. período anterior',
    ];
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
if (!is_string($lineSeries)) {
    $lineSeries = '[]';
}
$lineSeriesB64 = base64_encode($lineSeries);
$donutSeries = json_encode([
    ['label' => 'IA', 'value' => (int) ($metrics['ai_replies'] ?? 0)],
    ['label' => 'Equipe', 'value' => (int) ($metrics['human_replies'] ?? 0)],
    ['label' => 'Automação/Sistema', 'value' => (int) ($metrics['system_replies'] ?? 0)],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
if (!is_string($donutSeries)) {
    $donutSeries = '[]';
}
$donutSeriesB64 = base64_encode($donutSeries);
?>
<link rel="stylesheet" href="<?= View::e(Router::url('/assets/css/reports.css?v=36.4.6')) ?>">
<div class="executive-report-page client-manager-report report-v3646">
    <section class="client-report-hero">
        <div>
            <span class="eyebrow">Relatório executivo</span>
            <h2>Resultados do atendimento</h2>
            <p>Veja o que realmente aconteceu no período: crescimento do atendimento, participação da IA, horários de maior procura, conversão do CRM e desempenho da agenda.</p>
        </div>
        <div class="client-report-hero-actions">
            <a class="btn btn-primary" href="<?= View::e(Router::url('/reports/export?' . http_build_query($queryBase + ['type' => 'conversations']))) ?>">Exportar conversas</a>
            <button class="btn btn-outline" type="button" onclick="window.print()">Imprimir relatório</button>
        </div>
    </section>

    <?php if ($warnings): ?><div class="flash warning executive-report-warning"><strong>Alguns indicadores estão sendo atualizados.</strong><span><?= View::e(implode(' · ', $warnings)) ?></span></div><?php endif; ?>

    <form class="card client-report-filters" method="get" action="<?= View::e(Router::url('/reports')) ?>">
        <label class="field"><span>Data inicial</span><input type="date" name="start" value="<?= View::e($filters['start']) ?>"></label>
        <label class="field"><span>Data final</span><input type="date" name="end" value="<?= View::e($filters['end']) ?>"></label>
        <div class="client-report-filter-actions"><button class="btn btn-primary" type="submit">Atualizar período</button><a class="btn btn-quiet" href="<?= View::e(Router::url('/reports')) ?>">Limpar</a></div>
    </form>

    <section class="client-report-score-grid report-kpi-grid-v2" aria-label="Principais resultados">
        <?php $t = $trend($comparisons['conversations'] ?? null); ?>
        <article class="client-report-score is-primary"><span>Conversas iniciadas</span><strong><?= $number($metrics['conversations'] ?? 0) ?></strong><small class="report-trend <?= $t['class'] ?>"><?= View::e($t['text']) ?></small></article>
        <?php $t = $trend($comparisons['contacts'] ?? null); ?>
        <article class="client-report-score"><span>Novos contatos</span><strong><?= $number($metrics['contacts'] ?? 0) ?></strong><small class="report-trend <?= $t['class'] ?>"><?= View::e($t['text']) ?></small></article>
        <?php $t = $trend($comparisons['total_messages'] ?? null); ?>
        <article class="client-report-score"><span>Mensagens processadas</span><strong><?= $number($metrics['total_messages'] ?? 0) ?></strong><small class="report-trend <?= $t['class'] ?>"><?= View::e($t['text']) ?></small></article>
        <article class="client-report-score"><span>Tempo médio de 1ª resposta</span><strong><?= View::e($duration((int) ($metrics['avg_first_response_seconds'] ?? 0))) ?></strong><small><?= $number($metrics['unread'] ?? 0) ?> não lida(s) agora</small></article>
        <?php $t = $trend($comparisons['ai_replies'] ?? null); ?>
        <article class="client-report-score"><span>Respostas feitas pela IA</span><strong><?= $percent($metrics['ai_share'] ?? 0) ?></strong><small class="report-trend <?= $t['class'] ?>"><?= $number($metrics['ai_replies'] ?? 0) ?> respostas · <?= View::e($t['text']) ?></small></article>
        <?php $t = $trend($comparisons['appointments_successful'] ?? null); ?>
        <article class="client-report-score"><span>Confirmados/concluídos</span><strong><?= $number($metrics['appointments_successful'] ?? 0) ?></strong><small class="report-trend <?= $t['class'] ?>"><?= $percent($metrics['agenda_conversion'] ?? 0) ?> dos compromissos · <?= View::e($t['text']) ?></small></article>
    </section>

    <?php if ($insights): ?>
    <section class="card report-insights-panel">
        <div class="section-heading"><div><span class="eyebrow">Insights do período</span><h2>O que merece sua atenção</h2></div><span class="badge">Leitura automática</span></div>
        <div class="report-insights-grid">
            <?php foreach ($insights as $item): ?>
                <article class="report-insight is-<?= View::e($item['tone'] ?? 'info') ?>"><span class="report-insight-dot"></span><div><strong><?= View::e($item['title'] ?? '') ?></strong><p><?= View::e($item['text'] ?? '') ?></p></div></article>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

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
                <aside class="client-report-summary-card"><span class="eyebrow">Resumo</span><h3>Leitura rápida</h3><dl><div><dt>Total de mensagens</dt><dd><?= $number($metrics['total_messages'] ?? 0) ?></dd></div><div><dt>Média por conversa</dt><dd><?= number_format((float) ($metrics['avg_messages_per_conversation'] ?? 0), 1, ',', '.') ?></dd></div><div><dt>Encerradas</dt><dd><?= $number($metrics['closed_conversations'] ?? 0) ?></dd></div><div><dt>Mensagens com falha</dt><dd><?= $number($metrics['failed_messages'] ?? 0) ?></dd></div></dl><a class="btn btn-outline btn-block" href="<?= View::e(Router::url('/conversations')) ?>">Abrir conversas</a></aside>
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
                <aside class="client-report-summary-card"><span class="eyebrow">Assistente virtual</span><h3>Desempenho da IA</h3><dl><div><dt>Participação da IA</dt><dd><?= $percent($metrics['ai_share'] ?? 0) ?></dd></div><div><dt>Automação/Sistema</dt><dd><?= $percent($metrics['system_share'] ?? 0) ?></dd></div><div><dt>Execuções bem-sucedidas</dt><dd><?= $number($metrics['ai_success'] ?? 0) ?></dd></div><div><dt>Falhas registradas</dt><dd><?= $number($metrics['ai_errors'] ?? 0) ?></dd></div><div><dt>Google Agenda com erro</dt><dd><?= $number($metrics['google_sync_errors'] ?? 0) ?></dd></div></dl></aside>
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
<script src="<?= View::e(Router::url('/assets/js/reports.js?v=36.4.6')) ?>" defer></script>
</div>
