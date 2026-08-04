<?php

declare(strict_types=1);

use App\Core\Router;
use App\Core\View;
use App\Services\OperationalLanguageService;

$metrics = $reportData['metrics'] ?? [];
$comparisons = $reportData['comparisons'] ?? [];
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
$money = static fn (float|int|string $value): string => 'R$ ' . number_format((float) $value, 2, ',', '.');
$number = static fn (float|int|string $value): string => number_format((float) $value, 0, ',', '.');
$percent = static fn (float|int|string $value): string => number_format((float) $value, 1, ',', '.') . '%';
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
        'companies' => '<path d="M4 21V5h10v16M14 9h6v12M8 9h2M8 13h2M8 17h2M17 13h1M17 17h1"/>',
        'money' => '<circle cx="12" cy="12" r="9"/><path d="M16 8.5c0-1.4-1.8-2.5-4-2.5S8 7.1 8 8.5 9.8 11 12 11s4 1.1 4 2.5S14.2 16 12 16s-4-1.1-4-2.5M12 4v16"/>',
        'received' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M8 15h3"/><path d="m16 14 2 2 3-4"/>',
        'messages' => '<path d="M5 5h14v10H8l-3 3V5Z"/><path d="M9 9h6M9 12h4"/>',
        'pipeline' => '<path d="M4 19V8M10 19V5M16 19v-8M22 19V3"/><path d="M2 19h22"/>',
        'alert' => '<path d="M12 3 2 21h20L12 3Z"/><path d="M12 9v5M12 18h.01"/>',
        'users' => '<path d="M16 21v-2a4 4 0 0 0-8 0v2"/><circle cx="12" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.9"/>',
        'download' => '<path d="M12 3v12M7 10l5 5 5-5"/><path d="M4 20h16"/>',
        'filter' => '<path d="M4 5h16l-6 7v5l-4 2v-7L4 5Z"/>',
        'print' => '<path d="M6 9V3h12v6M6 17H4a2 2 0 0 1-2-2v-4a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2h-2"/><path d="M6 14h12v7H6z"/>',
        'arrow' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'calendar' => '<path d="M7 3v4M17 3v4M4 9h16M5 5h14v16H5z"/>',
        'monitor' => '<rect x="3" y="4" width="18" height="14" rx="2"/><path d="M8 21h8M12 18v3M7 12l3-3 3 2 4-5"/>',
    ];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? $paths['companies']) . '</svg>';
};
$statusLabels = [
    'scheduled' => 'Agendado', 'confirmed' => 'Confirmado', 'completed' => 'Concluído',
    'cancelled' => 'Cancelado', 'no_show' => 'Não compareceu', 'rejected' => 'Rejeitado',
];
$queryBase = array_filter([
    'start' => $filters['start'] ?? '',
    'end' => $filters['end'] ?? '',
    'tenant_id' => (int) ($filters['tenant_id'] ?? 0),
], static fn ($value) => $value !== '' && $value !== 0);
$lineJson = json_encode(array_map(static fn (array $row): array => [
    'label' => date('d/m', strtotime((string) ($row['label'] ?? 'now'))),
    'total' => (int) ($row['total'] ?? 0),
    'incoming' => (int) ($row['incoming'] ?? 0),
    'ai' => (int) ($row['ai'] ?? 0),
], $messagesByDay), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$lineSeriesB64 = base64_encode(is_string($lineJson) ? $lineJson : '[]');
$failureJson = json_encode(array_map(static fn (array $row): array => [
    'label' => date('d/m', strtotime((string) ($row['label'] ?? 'now'))),
    'total' => (int) ($row['total'] ?? 0),
], $failureTrend), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$failureSeriesB64 = base64_encode(is_string($failureJson) ? $failureJson : '[]');
$healthJson = json_encode(array_map(static fn (array $row): array => [
    'label' => (string) ($row['label'] ?? ''),
    'value' => (int) ($row['total'] ?? 0),
], $healthDistribution), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$healthSeriesB64 = base64_encode(is_string($healthJson) ? $healthJson : '[]');
$usageMax = max(1, ...array_map(static fn (array $row): int => (int) ($row['messages'] ?? 0), $usageByTenant ?: [['messages' => 1]]));
$planMax = max(1.0, ...array_map(static fn (array $row): float => (float) ($row['total'] ?? 0), $revenueByPlan ?: [['total' => 1]]));
$agendaMax = max(1, ...array_map(static fn (array $row): int => (int) ($row['total'] ?? 0), $agendaStatus ?: [['total' => 1]]));
$commercialMax = max(1, ...array_map(static fn (array $row): int => (int) ($row['total'] ?? 0), $commercialStages ?: [['total' => 1]]));
$periodLabel = date('d/m/Y', strtotime((string) $filters['start'])) . ' — ' . date('d/m/Y', strtotime((string) $filters['end']));
?>
<link rel="stylesheet" href="<?= View::e(Router::url('/assets/css/reports.css?v=36.10.1')) ?>">
<link rel="stylesheet" href="<?= View::e(Router::url('/assets/css/reports-v2.css?v=36.13.0')) ?>">

<div class="rs-report-v2 rs-report-v2--admin">
    <section class="rsv2-toolbar" aria-label="Filtros e ações do relatório">
        <div class="rsv2-toolbar-copy">
            <span class="rsv2-kicker">Inteligência do SaaS</span>
            <h2>Dashboard executivo</h2>
            <p>Crescimento, receita, adoção, saúde e operação da plataforma.</p>
        </div>
        <form class="rsv2-filter rsv2-filter--admin" method="get" action="<?= View::e(Router::url('/reports')) ?>">
            <label><span>De</span><input type="date" name="start" value="<?= View::e((string) $filters['start']) ?>"></label>
            <span class="rsv2-filter-separator">→</span>
            <label><span>Até</span><input type="date" name="end" value="<?= View::e((string) $filters['end']) ?>"></label>
            <label class="rsv2-filter-company"><span>Empresa</span><select name="tenant_id"><option value="">Toda a operação</option><?php foreach ($tenants as $tenant): ?><option value="<?= (int) $tenant['id'] ?>" <?= (int) ($filters['tenant_id'] ?? 0) === (int) $tenant['id'] ? 'selected' : '' ?>><?= View::e((string) $tenant['name']) ?></option><?php endforeach; ?></select></label>
            <button class="rsv2-icon-button" type="submit" title="Atualizar filtros" aria-label="Atualizar filtros"><?= $icon('filter') ?></button>
        </form>
        <div class="rsv2-actions">
            <a class="rsv2-button is-secondary" href="<?= View::e(Router::url('/reports/team?' . http_build_query($queryBase))) ?>"><?= $icon('users') ?><span>Equipe</span></a>
            <a class="rsv2-button" href="<?= View::e(Router::url('/reports/export?' . http_build_query($queryBase + ['type' => 'companies']))) ?>"><?= $icon('download') ?><span>Exportar</span></a>
            <button class="rsv2-icon-button" type="button" onclick="window.print()" title="Imprimir" aria-label="Imprimir relatório"><?= $icon('print') ?></button>
        </div>
    </section>

    <?php if ($warnings): ?>
        <div class="rsv2-warning"><?= $icon('alert') ?><div><strong>Alguns indicadores precisam de atenção.</strong><span><?= View::e(implode(' · ', $warnings)) ?></span></div></div>
    <?php endif; ?>

    <section class="rsv2-kpi-grid" aria-label="Principais indicadores da plataforma">
        <?php $t = $trend($comparisons['new_companies'] ?? null); ?>
        <a class="rsv2-kpi is-blue" href="<?= View::e(Router::url('/companies')) ?>"><span class="rsv2-kpi-icon"><?= $icon('companies') ?></span><div><small>Empresas ativas</small><strong><?= $number($metrics['active_companies'] ?? 0) ?></strong><em class="<?= $t['class'] ?>">+<?= $number($metrics['new_companies'] ?? 0) ?> no período · <?= $t['icon'] ?> <?= View::e($t['text']) ?></em></div><i class="rsv2-mini-line"><b style="--h:30%"></b><b style="--h:42%"></b><b style="--h:54%"></b><b style="--h:68%"></b><b style="--h:86%"></b></i></a>
        <a class="rsv2-kpi is-purple" href="<?= View::e(Router::url('/billing')) ?>"><span class="rsv2-kpi-icon"><?= $icon('money') ?></span><div><small>Receita mensal estimada</small><strong><?= $money($metrics['mrr'] ?? 0) ?></strong><em><?= $number($metrics['active_subscriptions'] ?? 0) ?> assinatura(s)</em></div><i class="rsv2-mini-line"><b style="--h:28%"></b><b style="--h:40%"></b><b style="--h:58%"></b><b style="--h:70%"></b><b style="--h:92%"></b></i></a>
        <a class="rsv2-kpi is-green" href="<?= View::e(Router::url('/billing?tab=invoices')) ?>"><span class="rsv2-kpi-icon"><?= $icon('received') ?></span><div><small>Recebido no período</small><strong><?= $money($metrics['received'] ?? 0) ?></strong><em><?= $money($metrics['overdue_amount'] ?? 0) ?> em atraso</em></div><i class="rsv2-mini-line"><b style="--h:34%"></b><b style="--h:52%"></b><b style="--h:46%"></b><b style="--h:78%"></b><b style="--h:90%"></b></i></a>
        <?php $t = $trend($comparisons['messages'] ?? null); ?>
        <a class="rsv2-kpi is-cyan" href="<?= View::e(Router::url('/conversations')) ?>"><span class="rsv2-kpi-icon"><?= $icon('messages') ?></span><div><small>Mensagens processadas</small><strong><?= $number($metrics['messages'] ?? 0) ?></strong><em class="<?= $t['class'] ?>"><?= $number($metrics['ai_replies'] ?? 0) ?> IA · <?= $t['icon'] ?> <?= View::e($t['text']) ?></em></div><i class="rsv2-mini-line"><b style="--h:24%"></b><b style="--h:48%"></b><b style="--h:64%"></b><b style="--h:56%"></b><b style="--h:96%"></b></i></a>
        <a class="rsv2-kpi is-pink" href="<?= View::e(Router::url('/crm')) ?>"><span class="rsv2-kpi-icon"><?= $icon('pipeline') ?></span><div><small>Pipeline comercial RS</small><strong><?= $money($metrics['commercial_pipeline'] ?? 0) ?></strong><em><?= $number($metrics['commercial_open'] ?? 0) ?> oportunidade(s) abertas</em></div><i class="rsv2-mini-line"><b style="--h:36%"></b><b style="--h:42%"></b><b style="--h:62%"></b><b style="--h:76%"></b><b style="--h:88%"></b></i></a>
        <?php $t = $trend($comparisons['automation_failures'] ?? null, true); ?>
        <a class="rsv2-kpi is-orange" href="<?= View::e(Router::url('/central-operacao?tab=monitoring')) ?>"><span class="rsv2-kpi-icon"><?= $icon('alert') ?></span><div><small>Falhas de automação</small><strong><?= $number($metrics['automation_failures'] ?? 0) ?></strong><em class="<?= $t['class'] ?>"><?= $t['icon'] ?> <?= View::e($t['text']) ?></em></div><i class="rsv2-mini-line"><b style="--h:82%"></b><b style="--h:72%"></b><b style="--h:58%"></b><b style="--h:46%"></b><b style="--h:34%"></b></i></a>
    </section>

    <section class="rsv2-dashboard-grid">
        <article class="rsv2-panel rsv2-panel--span-2">
            <header class="rsv2-panel-header"><div><span>Uso da plataforma</span><h3>Mensagens processadas</h3></div><small><?= View::e($periodLabel) ?></small></header>
            <div class="rsv2-chart report-svg-chart" data-report-line-chart data-series-b64="<?= View::e($lineSeriesB64) ?>" aria-label="Mensagens processadas por dia"></div>
            <div class="rsv2-legend"><span><i class="is-total"></i>Total</span><span><i class="is-incoming"></i>Recebidas</span><span><i class="is-ai"></i>IA</span></div>
        </article>

        <article class="rsv2-panel">
            <header class="rsv2-panel-header"><div><span>Saúde da base</span><h3>Situação das empresas</h3></div><a href="<?= View::e(Router::url('/companies')) ?>">Abrir empresas <?= $icon('arrow') ?></a></header>
            <div class="rsv2-donut-layout">
                <div class="report-donut" data-report-donut data-series-b64="<?= View::e($healthSeriesB64) ?>" data-center="<?= View::e($number($metrics['active_companies'] ?? 0)) ?>"></div>
                <div class="rsv2-donut-legend is-health"><?php foreach ($healthDistribution as $index => $row): ?><div><i class="is-<?= ($index % 5) + 1 ?>"></i><span><?= View::e((string) ($row['label'] ?? 'Situação')) ?></span><strong><?= $number($row['total'] ?? 0) ?></strong></div><?php endforeach; ?><div><i class="is-alert"></i><span>Incidentes abertos</span><strong><?= $number($metrics['open_health_incidents'] ?? 0) ?></strong></div></div>
            </div>
        </article>

        <article class="rsv2-panel">
            <header class="rsv2-panel-header"><div><span>Receita recorrente</span><h3>Distribuição por plano</h3></div><a href="<?= View::e(Router::url('/billing')) ?>">Financeiro <?= $icon('arrow') ?></a></header>
            <div class="rsv2-funnel-list is-revenue">
                <?php foreach ($revenueByPlan as $row): ?>
                    <div><span><strong><?= View::e((string) ($row['label'] ?? 'Plano')) ?></strong><small><?= $number($row['subscriptions'] ?? 0) ?> assinatura(s)</small></span><i><b style="width:<?= max(8, min(100, ((float) ($row['total'] ?? 0) / $planMax) * 100)) ?>%"></b></i><em><?= $money($row['total'] ?? 0) ?></em></div>
                <?php endforeach; ?>
                <?php if (!$revenueByPlan): ?><div class="rsv2-empty">Nenhuma assinatura ativa encontrada.</div><?php endif; ?>
            </div>
            <div class="rsv2-summary-strip"><div><span>A receber</span><strong><?= $money($metrics['expected'] ?? 0) ?></strong></div><div><span>Vencidas</span><strong><?= $number($metrics['overdue_count'] ?? 0) ?></strong></div></div>
        </article>

        <article class="rsv2-panel rsv2-panel--span-2">
            <header class="rsv2-panel-header"><div><span>Adoção por empresa</span><h3>Clientes com maior uso</h3></div><a href="<?= View::e(Router::url('/reports/export?' . http_build_query($queryBase + ['type' => 'usage']))) ?>">Exportar ranking <?= $icon('arrow') ?></a></header>
            <div class="rsv2-usage-list">
                <?php foreach (array_slice($usageByTenant, 0, 8) as $index => $row): ?>
                    <a href="<?= View::e(Router::url('/companies/overview?id=' . (int) ($row['id'] ?? 0))) ?>"><b><?= $index + 1 ?></b><span><strong><?= View::e((string) ($row['name'] ?? 'Empresa')) ?></strong><small><?= $number($row['conversations'] ?? 0) ?> conversa(s) · <?= $number($row['ai_replies'] ?? 0) ?> resposta(s) da IA</small></span><i><em style="width:<?= min(100, ((int) ($row['messages'] ?? 0) / $usageMax) * 100) ?>%"></em></i><strong><?= $number($row['messages'] ?? 0) ?></strong></a>
                <?php endforeach; ?>
                <?php if (!$usageByTenant): ?><div class="rsv2-empty">Nenhum uso registrado no período.</div><?php endif; ?>
            </div>
        </article>

        <article class="rsv2-panel">
            <header class="rsv2-panel-header"><div><span>Monitoramento</span><h3>Falhas ao longo do tempo</h3></div><a href="<?= View::e(Router::url('/central-operacao?tab=monitoring')) ?>">Abrir central <?= $icon('arrow') ?></a></header>
            <div class="rsv2-chart is-compact report-svg-chart" data-report-line-chart data-series-b64="<?= View::e($failureSeriesB64) ?>" data-single-series="total" aria-label="Falhas por dia"></div>
            <div class="rsv2-summary-strip is-three"><div><span>IA</span><strong><?= $number($metrics['ai_failures'] ?? 0) ?></strong></div><div><span>n8n</span><strong><?= $number($metrics['n8n_failures'] ?? 0) ?></strong></div><div><span>Agenda</span><strong><?= $number($metrics['google_sync_failures'] ?? 0) ?></strong></div></div>
        </article>

        <article class="rsv2-panel">
            <header class="rsv2-panel-header"><div><span>Agenda da plataforma</span><h3>Resultado dos compromissos</h3></div><a href="<?= View::e(Router::url('/calendar')) ?>">Abrir agenda <?= $icon('arrow') ?></a></header>
            <div class="rsv2-status-list">
                <?php foreach ($agendaStatus as $row): $status = (string) ($row['label'] ?? 'scheduled'); ?>
                    <div><span><i class="is-<?= View::e($status) ?>"></i><?= View::e($statusLabels[$status] ?? ucfirst($status)) ?></span><b><?= $number($row['total'] ?? 0) ?></b><em><i style="width:<?= min(100, ((int) ($row['total'] ?? 0) / $agendaMax) * 100) ?>%"></i></em></div>
                <?php endforeach; ?>
                <?php if (!$agendaStatus): ?><div class="rsv2-empty">Nenhum compromisso no período.</div><?php endif; ?>
            </div>
            <div class="rsv2-summary-strip"><div><span>Confirmados/concluídos</span><strong><?= $number($metrics['appointments_confirmed'] ?? 0) ?></strong></div><div><span>Conversão</span><strong><?= $percent($metrics['agenda_conversion'] ?? 0) ?></strong></div></div>
        </article>

        <article class="rsv2-panel rsv2-panel--span-2">
            <header class="rsv2-panel-header"><div><span>Comercial RS</span><h3>Pipeline por etapa</h3></div><a href="<?= View::e(Router::url('/crm')) ?>">Abrir CRM <?= $icon('arrow') ?></a></header>
            <div class="rsv2-commercial-board">
                <?php foreach ($commercialStages as $row): ?>
                    <div><span><i></i><strong><?= View::e((string) ($row['label'] ?? 'Etapa')) ?></strong><b><?= $number($row['total'] ?? 0) ?></b></span><em><?= $money($row['value'] ?? 0) ?></em><small><i style="width:<?= max(5, min(100, ((int) ($row['total'] ?? 0) / $commercialMax) * 100)) ?>%"></i></small></div>
                <?php endforeach; ?>
                <?php if (!$commercialStages): ?><div class="rsv2-empty">Nenhuma etapa comercial disponível.</div><?php endif; ?>
            </div>
        </article>

        <article class="rsv2-panel">
            <header class="rsv2-panel-header"><div><span>Ocorrências recorrentes</span><h3>Pontos de atenção</h3></div><a href="<?= View::e(Router::url('/reports/export?' . http_build_query($queryBase + ['type' => 'failures']))) ?>">Exportar <?= $icon('arrow') ?></a></header>
            <div class="rsv2-failure-list">
                <?php foreach (array_slice($failures, 0, 7) as $row): $sourceClass = trim((string) preg_replace('/[^a-z0-9]+/i', '-', mb_strtolower((string) ($row['source'] ?? 'sistema'))), '-'); ?><div><span class="is-<?= View::e($sourceClass) ?>"><?= View::e(OperationalLanguageService::replaceTechnicalTerms((string) ($row['source'] ?? 'Sistema'))) ?></span><strong><?= View::e(OperationalLanguageService::replaceTechnicalTerms((string) ($row['label'] ?? 'Ocorrência'))) ?></strong><b><?= $number($row['total'] ?? 0) ?></b></div><?php endforeach; ?>
                <?php if (!$failures): ?><div class="rsv2-empty">Nenhuma situação de atenção registrada.</div><?php endif; ?>
            </div>
        </article>

        <article class="rsv2-panel rsv2-panel--span-2">
            <header class="rsv2-panel-header"><div><span>Financeiro</span><h3>Últimos vencimentos</h3></div><a href="<?= View::e(Router::url('/billing?tab=invoices')) ?>">Abrir cobranças <?= $icon('arrow') ?></a></header>
            <div class="rsv2-table-wrap"><table class="rsv2-table"><thead><tr><th>Empresa</th><th>Cobrança</th><th>Valor</th><th>Vencimento</th><th>Status</th></tr></thead><tbody><?php foreach (array_slice($recentInvoices, 0, 8) as $invoice): ?><tr><td><?= View::e((string) ($invoice['tenant_name'] ?? '')) ?></td><td><?= View::e((string) ($invoice['invoice_number'] ?? '')) ?></td><td><?= $money($invoice['amount'] ?? 0) ?></td><td><?= !empty($invoice['due_date']) ? View::e(date('d/m/Y', strtotime((string) $invoice['due_date']))) : '—' ?></td><td><span class="rsv2-status-badge is-<?= View::e((string) ($invoice['status'] ?? 'open')) ?>"><?= View::e((string) ($invoice['status'] ?? 'open')) ?></span></td></tr><?php endforeach; ?><?php if (!$recentInvoices): ?><tr><td colspan="5"><div class="rsv2-empty">Nenhuma cobrança encontrada.</div></td></tr><?php endif; ?></tbody></table></div>
        </article>

        <article class="rsv2-panel">
            <header class="rsv2-panel-header"><div><span>Atenção comercial</span><h3>Clientes com pouco uso</h3></div></header>
            <div class="rsv2-low-usage-list">
                <?php foreach (array_slice($lowUsage, 0, 7) as $row): ?><a href="<?= View::e(Router::url('/companies/overview?id=' . (int) ($row['id'] ?? 0))) ?>"><span><strong><?= View::e((string) ($row['name'] ?? 'Empresa')) ?></strong><small><?= !empty($row['last_message_at']) ? 'Última mensagem em ' . View::e(date('d/m/Y H:i', strtotime((string) $row['last_message_at']))) : 'Sem uso registrado' ?></small></span><b><?= $number($row['messages'] ?? 0) ?></b></a><?php endforeach; ?>
                <?php if (!$lowUsage): ?><div class="rsv2-empty">Nenhuma empresa com baixo uso dentro do filtro.</div><?php endif; ?>
            </div>
        </article>

        <?php if ($insights): ?>
        <article class="rsv2-panel rsv2-panel--span-3">
            <header class="rsv2-panel-header"><div><span>Leitura automática</span><h3>Insights executivos</h3></div><small>Gerados a partir dos dados reais</small></header>
            <div class="rsv2-insights"><?php foreach ($insights as $item): ?><div class="is-<?= View::e((string) ($item['tone'] ?? 'info')) ?>"><i></i><span><strong><?= View::e((string) ($item['title'] ?? 'Insight')) ?></strong><small><?= View::e((string) ($item['text'] ?? '')) ?></small></span></div><?php endforeach; ?></div>
        </article>
        <?php endif; ?>
    </section>

    <footer class="rsv2-footer-note"><span>Visual executivo V2 · consultas, serviços e exportações originais preservados.</span><a href="<?= View::e(Router::url('/reports?' . http_build_query($queryBase + ['layout' => 'legacy']))) ?>">Abrir visual clássico</a></footer>
</div>
<script src="<?= View::e(Router::url('/assets/js/reports.js?v=36.13.0')) ?>" defer></script>
