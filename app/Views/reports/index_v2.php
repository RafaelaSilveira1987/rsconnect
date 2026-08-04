<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Router;
use App\Core\View;

$money = static fn (float|int|string $value): string => 'R$ ' . number_format((float) $value, 2, ',', '.');
$number = static fn (float|int|string $value): string => number_format((float) $value, 0, ',', '.');
$percent = static fn (float|int|string $value): string => number_format((float) $value, 1, ',', '.') . '%';
$duration = static function (int $seconds): string {
    if ($seconds <= 0) return 'Sem dados';
    if ($seconds < 60) return $seconds . 's';
    if ($seconds < 3600) return floor($seconds / 60) . 'm ' . ($seconds % 60) . 's';
    return floor($seconds / 3600) . 'h ' . floor(($seconds % 3600) / 60) . 'm';
};
$trend = static function (?float $value, bool $inverse = false): array {
    if ($value === null) return ['class' => 'is-neutral', 'icon' => '•', 'text' => 'Sem base anterior'];
    if (abs($value) < .05) return ['class' => 'is-neutral', 'icon' => '→', 'text' => 'Estável vs. período anterior'];
    $positive = $value > 0;
    if ($inverse) $positive = !$positive;
    return [
        'class' => $positive ? 'is-up' : 'is-down',
        'icon' => $value > 0 ? '↑' : '↓',
        'text' => number_format(abs($value), 1, ',', '.') . '% vs. período anterior',
    ];
};
$icon = static function (string $name): string {
    $paths = [
        'chat' => '<path d="M4 5h16v11H8l-4 4V5Z"/><path d="M8 9h8M8 12h5"/>',
        'messages' => '<path d="M7 4h13v10H9l-4 4V6a2 2 0 0 1 2-2Z"/><path d="M4 9H3a1 1 0 0 0-1 1v9l3-3h8a1 1 0 0 0 1-1"/>',
        'ai' => '<rect x="4" y="6" width="16" height="13" rx="3"/><path d="M9 11h.01M15 11h.01M9 15h6M12 2v4"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'crm' => '<path d="M4 19V8M10 19V5M16 19v-8M22 19V3"/><path d="M2 19h22"/>',
        'calendar' => '<path d="M7 3v4M17 3v4M4 9h16M5 5h14v16H5z"/><path d="m9 15 2 2 4-4"/>',
        'download' => '<path d="M12 3v12M7 10l5 5 5-5"/><path d="M4 20h16"/>',
        'users' => '<path d="M16 21v-2a4 4 0 0 0-8 0v2"/><circle cx="12" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.9"/>',
        'filter' => '<path d="M4 5h16l-6 7v5l-4 2v-7L4 5Z"/>',
        'print' => '<path d="M6 9V3h12v6M6 17H4a2 2 0 0 1-2-2v-4a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2h-2"/><path d="M6 14h12v7H6z"/>',
        'arrow' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'alert' => '<path d="M12 3 2 21h20L12 3Z"/><path d="M12 9v5M12 18h.01"/>',
        'contact' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
    ];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? $paths['chat']) . '</svg>';
};
$statusLabels = [
    'scheduled' => 'Agendado', 'confirmed' => 'Confirmado', 'completed' => 'Concluído',
    'cancelled' => 'Cancelado', 'no_show' => 'Não compareceu', 'rejected' => 'Rejeitado',
];
$metrics = $metrics ?? [];
$comparisons = $comparisons ?? [];
$byDay = $byDay ?? [];
$byHour = $byHour ?? [];
$crmByStage = $crmByStage ?? [];
$agendaByStatus = $agendaByStatus ?? [];
$teamPerformance = $teamPerformance ?? [];
$attention = $attention ?? [];
$topContacts = $topContacts ?? [];
$insights = $insights ?? [];
$warnings = $warnings ?? [];
$queryBase = array_filter([
    'start' => $filters['start'] ?? '',
    'end' => $filters['end'] ?? '',
], static fn ($value) => $value !== '');
$lineJson = json_encode(array_map(static fn (array $row): array => [
    'label' => date('d/m', strtotime((string) ($row['label'] ?? 'now'))),
    'total' => (int) ($row['total'] ?? 0),
    'incoming' => (int) ($row['incoming'] ?? 0),
    'ai' => (int) ($row['ai'] ?? 0),
], $byDay), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$lineSeriesB64 = base64_encode(is_string($lineJson) ? $lineJson : '[]');
$donutJson = json_encode([
    ['label' => 'IA', 'value' => (int) ($metrics['ai_replies'] ?? 0)],
    ['label' => 'Equipe', 'value' => (int) ($metrics['human_replies'] ?? 0)],
    ['label' => 'Automação', 'value' => (int) ($metrics['system_replies'] ?? 0)],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$donutSeriesB64 = base64_encode(is_string($donutJson) ? $donutJson : '[]');
$hourMax = max(1, ...array_map(static fn (array $row): int => (int) ($row['total'] ?? 0), $byHour ?: [['total' => 1]]));
$teamMax = max(1, ...array_map(static fn (array $row): int => (int) ($row['total'] ?? 0), $teamPerformance ?: [['total' => 1]]));
$crmMax = max(1, ...array_map(static fn (array $row): int => (int) ($row['total'] ?? 0), $crmByStage ?: [['total' => 1]]));
$agendaMax = max(1, ...array_map(static fn (array $row): int => (int) ($row['total'] ?? 0), $agendaByStatus ?: [['total' => 1]]));
$periodLabel = date('d/m/Y', strtotime((string) $filters['start'])) . ' — ' . date('d/m/Y', strtotime((string) $filters['end']));
?>
<link rel="stylesheet" href="<?= View::e(Router::url('/assets/css/reports.css?v=36.10.1')) ?>">
<link rel="stylesheet" href="<?= View::e(Router::url('/assets/css/reports-v2.css?v=36.13.0')) ?>">

<div class="rs-report-v2 rs-report-v2--client">
    <section class="rsv2-toolbar" aria-label="Filtros e ações do relatório">
        <div class="rsv2-toolbar-copy">
            <span class="rsv2-kicker">Inteligência operacional</span>
            <h2>Painel de desempenho</h2>
            <p>Atendimento, automação, comercial e agenda em uma única visão.</p>
        </div>
        <form class="rsv2-filter" method="get" action="<?= View::e(Router::url('/reports')) ?>">
            <label><span>De</span><input type="date" name="start" value="<?= View::e((string) $filters['start']) ?>"></label>
            <span class="rsv2-filter-separator">→</span>
            <label><span>Até</span><input type="date" name="end" value="<?= View::e((string) $filters['end']) ?>"></label>
            <button class="rsv2-icon-button" type="submit" title="Atualizar período" aria-label="Atualizar período"><?= $icon('filter') ?></button>
        </form>
        <div class="rsv2-actions">
            <?php if (Auth::can('reports.team.view_own') || Auth::can('reports.team.view_all')): ?>
                <a class="rsv2-button is-secondary" href="<?= View::e(Router::url('/reports/team?' . http_build_query($queryBase))) ?>"><?= $icon('users') ?><span>Equipe</span></a>
            <?php endif; ?>
            <a class="rsv2-button" href="<?= View::e(Router::url('/reports/export?' . http_build_query($queryBase + ['type' => 'conversations']))) ?>"><?= $icon('download') ?><span>Exportar</span></a>
            <button class="rsv2-icon-button" type="button" onclick="window.print()" title="Imprimir" aria-label="Imprimir relatório"><?= $icon('print') ?></button>
        </div>
    </section>

    <?php if ($warnings): ?>
        <div class="rsv2-warning"><?= $icon('alert') ?><div><strong>Alguns dados complementares estão em atualização.</strong><span><?= View::e(implode(' · ', $warnings)) ?></span></div></div>
    <?php endif; ?>

    <section class="rsv2-kpi-grid" aria-label="Principais indicadores do período">
        <?php $t = $trend($comparisons['conversations'] ?? null); ?>
        <a class="rsv2-kpi is-blue" href="<?= View::e(Router::url('/conversations')) ?>"><span class="rsv2-kpi-icon"><?= $icon('chat') ?></span><div><small>Conversas iniciadas</small><strong><?= $number($metrics['conversations'] ?? 0) ?></strong><em class="<?= $t['class'] ?>"><?= $t['icon'] ?> <?= View::e($t['text']) ?></em></div><i class="rsv2-mini-line"><b style="--h:34%"></b><b style="--h:48%"></b><b style="--h:42%"></b><b style="--h:68%"></b><b style="--h:82%"></b></i></a>
        <?php $t = $trend($comparisons['total_messages'] ?? null); ?>
        <article class="rsv2-kpi is-cyan"><span class="rsv2-kpi-icon"><?= $icon('messages') ?></span><div><small>Mensagens processadas</small><strong><?= $number($metrics['total_messages'] ?? 0) ?></strong><em class="<?= $t['class'] ?>"><?= $t['icon'] ?> <?= View::e($t['text']) ?></em></div><i class="rsv2-mini-line"><b style="--h:28%"></b><b style="--h:44%"></b><b style="--h:70%"></b><b style="--h:56%"></b><b style="--h:88%"></b></i></article>
        <?php $t = $trend($comparisons['ai_replies'] ?? null); ?>
        <article class="rsv2-kpi is-purple"><span class="rsv2-kpi-icon"><?= $icon('ai') ?></span><div><small>Participação da IA</small><strong><?= $percent($metrics['ai_share'] ?? 0) ?></strong><em class="<?= $t['class'] ?>"><?= $number($metrics['ai_replies'] ?? 0) ?> respostas · <?= $t['icon'] ?> <?= View::e($t['text']) ?></em></div><i class="rsv2-mini-line"><b style="--h:38%"></b><b style="--h:52%"></b><b style="--h:48%"></b><b style="--h:72%"></b><b style="--h:94%"></b></i></article>
        <article class="rsv2-kpi is-orange"><span class="rsv2-kpi-icon"><?= $icon('clock') ?></span><div><small>Tempo médio de resposta</small><strong><?= View::e($duration((int) ($metrics['avg_first_response_seconds'] ?? 0))) ?></strong><em><?= $number($metrics['unread'] ?? 0) ?> não lida(s) agora</em></div><i class="rsv2-mini-line"><b style="--h:86%"></b><b style="--h:72%"></b><b style="--h:60%"></b><b style="--h:48%"></b><b style="--h:38%"></b></i></article>
        <?php $t = $trend($comparisons['crm_won'] ?? null); ?>
        <a class="rsv2-kpi is-green" href="<?= View::e(Router::url('/crm')) ?>"><span class="rsv2-kpi-icon"><?= $icon('crm') ?></span><div><small>Conversão comercial</small><strong><?= $percent($metrics['crm_conversion'] ?? 0) ?></strong><em class="<?= $t['class'] ?>"><?= $number($metrics['crm_won'] ?? 0) ?> ganho(s) · <?= $t['icon'] ?> <?= View::e($t['text']) ?></em></div><i class="rsv2-mini-line"><b style="--h:28%"></b><b style="--h:36%"></b><b style="--h:54%"></b><b style="--h:70%"></b><b style="--h:88%"></b></i></a>
        <?php $t = $trend($comparisons['appointments_successful'] ?? null); ?>
        <a class="rsv2-kpi is-pink" href="<?= View::e(Router::url('/calendar')) ?>"><span class="rsv2-kpi-icon"><?= $icon('calendar') ?></span><div><small>Resultado da agenda</small><strong><?= $percent($metrics['agenda_conversion'] ?? 0) ?></strong><em class="<?= $t['class'] ?>"><?= $number($metrics['appointments_successful'] ?? 0) ?> confirmados/concluídos</em></div><i class="rsv2-mini-line"><b style="--h:30%"></b><b style="--h:56%"></b><b style="--h:42%"></b><b style="--h:74%"></b><b style="--h:92%"></b></i></a>
    </section>

    <section class="rsv2-dashboard-grid">
        <article class="rsv2-panel rsv2-panel--span-2">
            <header class="rsv2-panel-header"><div><span>Atendimentos ao longo do tempo</span><h3>Movimento diário</h3></div><small><?= View::e($periodLabel) ?></small></header>
            <div class="rsv2-chart report-svg-chart" data-report-line-chart data-series-b64="<?= View::e($lineSeriesB64) ?>" aria-label="Atendimentos por dia"></div>
            <div class="rsv2-legend"><span><i class="is-total"></i>Total</span><span><i class="is-incoming"></i>Recebidas</span><span><i class="is-ai"></i>IA</span></div>
        </article>

        <article class="rsv2-panel">
            <header class="rsv2-panel-header"><div><span>Origem das respostas</span><h3>IA, equipe e automação</h3></div></header>
            <div class="rsv2-donut-layout">
                <div class="report-donut" data-report-donut data-series-b64="<?= View::e($donutSeriesB64) ?>" data-center="<?= View::e($number($metrics['outgoing_messages'] ?? 0)) ?>"></div>
                <div class="rsv2-donut-legend"><div><i class="is-one"></i><span>IA<small><?= $percent($metrics['ai_share'] ?? 0) ?></small></span><strong><?= $number($metrics['ai_replies'] ?? 0) ?></strong></div><div><i class="is-two"></i><span>Equipe<small><?= $percent($metrics['human_share'] ?? 0) ?></small></span><strong><?= $number($metrics['human_replies'] ?? 0) ?></strong></div><div><i class="is-three"></i><span>Automação<small><?= $percent($metrics['system_share'] ?? 0) ?></small></span><strong><?= $number($metrics['system_replies'] ?? 0) ?></strong></div></div>
            </div>
        </article>

        <article class="rsv2-panel">
            <header class="rsv2-panel-header"><div><span>Atendimentos por horário</span><h3>Picos de demanda</h3></div></header>
            <div class="rsv2-hour-chart">
                <?php foreach ($byHour as $row): $hour = (int) ($row['label'] ?? $row['hour'] ?? 0); $value = (int) ($row['total'] ?? 0); ?>
                    <div title="<?= str_pad((string) $hour, 2, '0', STR_PAD_LEFT) ?>h: <?= $number($value) ?>"><span style="--h:<?= max(4, min(100, ($value / $hourMax) * 100)) ?>%"></span><small><?= str_pad((string) $hour, 2, '0', STR_PAD_LEFT) ?></small></div>
                <?php endforeach; ?>
                <?php if (!$byHour): ?><div class="rsv2-empty">Sem mensagens por horário no período.</div><?php endif; ?>
            </div>
        </article>

        <article class="rsv2-panel rsv2-panel--span-2">
            <header class="rsv2-panel-header"><div><span>Desempenho da equipe</span><h3>Respostas por responsável</h3></div><a href="<?= View::e(Router::url('/reports/team?' . http_build_query($queryBase))) ?>">Ver relatório completo <?= $icon('arrow') ?></a></header>
            <div class="rsv2-team-list">
                <?php foreach (array_slice($teamPerformance, 0, 6) as $index => $row): ?>
                    <div class="rsv2-team-row"><span class="rsv2-avatar"><?= View::e(mb_strtoupper(mb_substr((string) ($row['label'] ?? 'E'), 0, 1))) ?></span><div><strong><?= View::e((string) ($row['label'] ?? 'Equipe')) ?></strong><small><?= $number($row['conversations'] ?? 0) ?> conversa(s)</small></div><span class="rsv2-progress"><i style="width:<?= min(100, ((int) ($row['total'] ?? 0) / $teamMax) * 100) ?>%"></i></span><b><?= $number($row['total'] ?? 0) ?></b></div>
                <?php endforeach; ?>
                <?php if (!$teamPerformance): ?><div class="rsv2-empty">Nenhuma resposta humana registrada no período.</div><?php endif; ?>
            </div>
        </article>

        <article class="rsv2-panel">
            <header class="rsv2-panel-header"><div><span>Comercial</span><h3>Oportunidades por etapa</h3></div><a href="<?= View::e(Router::url('/crm')) ?>">Abrir CRM <?= $icon('arrow') ?></a></header>
            <div class="rsv2-funnel-list">
                <?php foreach ($crmByStage as $row): ?>
                    <div><span><strong><?= View::e((string) ($row['label'] ?? 'Etapa')) ?></strong><small><?= $money($row['value'] ?? 0) ?></small></span><i><b style="width:<?= max(8, min(100, ((int) ($row['total'] ?? 0) / $crmMax) * 100)) ?>%"></b></i><em><?= $number($row['total'] ?? 0) ?></em></div>
                <?php endforeach; ?>
                <?php if (!$crmByStage): ?><div class="rsv2-empty">Nenhuma oportunidade cadastrada.</div><?php endif; ?>
            </div>
        </article>

        <article class="rsv2-panel">
            <header class="rsv2-panel-header"><div><span>Agenda</span><h3>Situação dos compromissos</h3></div><a href="<?= View::e(Router::url('/calendar')) ?>">Abrir agenda <?= $icon('arrow') ?></a></header>
            <div class="rsv2-status-list">
                <?php foreach ($agendaByStatus as $row): $status = (string) ($row['label'] ?? 'scheduled'); ?>
                    <div><span><i class="is-<?= View::e($status) ?>"></i><?= View::e($statusLabels[$status] ?? ucfirst($status)) ?></span><b><?= $number($row['total'] ?? 0) ?></b><em><i style="width:<?= min(100, ((int) ($row['total'] ?? 0) / $agendaMax) * 100) ?>%"></i></em></div>
                <?php endforeach; ?>
                <?php if (!$agendaByStatus): ?><div class="rsv2-empty">Nenhum compromisso no período.</div><?php endif; ?>
            </div>
        </article>

        <article class="rsv2-panel rsv2-panel--span-2">
            <header class="rsv2-panel-header"><div><span>Conversas que exigem atenção</span><h3>Fila operacional atual</h3></div><a href="<?= View::e(Router::url('/conversations')) ?>">Abrir conversas <?= $icon('arrow') ?></a></header>
            <div class="rsv2-attention-list">
                <?php foreach (array_slice($attention, 0, 6) as $row): ?>
                    <a href="<?= View::e(Router::url('/conversations?conversation_id=' . (int) ($row['id'] ?? 0))) ?>"><span class="rsv2-avatar is-contact"><?= $icon('contact') ?></span><div><strong><?= View::e((string) (($row['contact_name'] ?? '') ?: ($row['phone'] ?? 'Contato'))) ?></strong><small><?= View::e((string) ($row['phone'] ?? '')) ?> · <?= View::e((string) ($row['attendance_mode'] ?? 'automático')) ?></small></div><span class="rsv2-attention-badge"><?= $number($row['unread_count'] ?? 0) ?> não lida(s)</span><time><?= !empty($row['last_message_at']) ? View::e(date('d/m H:i', strtotime((string) $row['last_message_at']))) : '—' ?></time></a>
                <?php endforeach; ?>
                <?php if (!$attention): ?><div class="rsv2-empty">Nenhuma conversa exige atenção neste momento.</div><?php endif; ?>
            </div>
        </article>

        <article class="rsv2-panel">
            <header class="rsv2-panel-header"><div><span>Clientes mais ativos</span><h3>Volume de interação</h3></div></header>
            <div class="rsv2-ranking">
                <?php foreach (array_slice($topContacts, 0, 6) as $index => $row): ?>
                    <div><b><?= $index + 1 ?></b><span><strong><?= View::e((string) ($row['label'] ?? 'Contato')) ?></strong><small><?= View::e((string) ($row['phone'] ?? '')) ?></small></span><em><?= $number($row['total'] ?? 0) ?></em></div>
                <?php endforeach; ?>
                <?php if (!$topContacts): ?><div class="rsv2-empty">Nenhuma interação registrada.</div><?php endif; ?>
            </div>
        </article>

        <?php if ($insights): ?>
        <article class="rsv2-panel rsv2-panel--span-3">
            <header class="rsv2-panel-header"><div><span>Leitura automática</span><h3>Insights do período</h3></div><small>Gerados a partir dos dados reais</small></header>
            <div class="rsv2-insights">
                <?php foreach ($insights as $item): ?><div class="is-<?= View::e((string) ($item['tone'] ?? 'info')) ?>"><i></i><span><strong><?= View::e((string) ($item['title'] ?? 'Insight')) ?></strong><small><?= View::e((string) ($item['text'] ?? '')) ?></small></span></div><?php endforeach; ?>
            </div>
        </article>
        <?php endif; ?>
    </section>

    <footer class="rsv2-footer-note"><span>Visual executivo V2 · dados e funcionalidades originais preservados.</span><a href="<?= View::e(Router::url('/reports?' . http_build_query($queryBase + ['layout' => 'legacy']))) ?>">Abrir visual clássico</a></footer>
</div>
<script src="<?= View::e(Router::url('/assets/js/reports.js?v=36.13.0')) ?>" defer></script>
