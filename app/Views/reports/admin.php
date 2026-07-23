<?php

use App\Core\Router;
use App\Core\View;

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
$money = static fn (float|int|string $value): string => 'R$ ' . number_format((float) $value, 2, ',', '.');
$number = static fn (float|int|string $value): string => number_format((float) $value, 0, ',', '.');
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
?>
<link rel="stylesheet" href="<?= View::e(Router::url('/assets/css/reports.css?v=36.4.2')) ?>">
<div class="executive-report-page executive-report-admin report-v3641">
<section class="admin-executive-hero executive-report-hero">
    <div class="admin-executive-hero-copy">
        <span class="eyebrow">Inteligência do SaaS</span>
        <h2>Relatórios da plataforma RS Connect</h2>
        <p>Uma visão executiva da operação: crescimento, adoção, saúde dos clientes, IA, integrações, agenda, receita e comercial RS.</p>
    </div>
    <div class="admin-executive-hero-actions">
        <a class="btn btn-primary" href="<?= View::e(Router::url('/reports/export?' . http_build_query($queryBase + ['type' => 'companies']))) ?>">Exportar empresas</a>
        <button class="btn btn-outline" type="button" onclick="window.print()">Imprimir relatório</button>
    </div>
</section>

<?php if ($warnings): ?><div class="flash warning executive-report-warning"><strong>Alguns indicadores precisam de atenção.</strong><span><?= View::e(implode(' · ', $warnings)) ?></span></div><?php endif; ?>

<form class="card executive-report-filters" method="get" action="<?= View::e(Router::url('/reports')) ?>">
    <label class="field"><span>Data inicial</span><input type="date" name="start" value="<?= View::e($filters['start']) ?>"></label>
    <label class="field"><span>Data final</span><input type="date" name="end" value="<?= View::e($filters['end']) ?>"></label>
    <label class="field executive-report-company"><span>Empresa</span><select name="tenant_id"><option value="">Toda a operação</option><?php foreach ($tenants as $tenant): ?><option value="<?= (int) $tenant['id'] ?>" <?= (int) ($filters['tenant_id'] ?? 0) === (int) $tenant['id'] ? 'selected' : '' ?>><?= View::e($tenant['name']) ?></option><?php endforeach; ?></select></label>
    <div class="executive-report-filter-actions"><button class="btn btn-primary" type="submit">Atualizar relatório</button><a class="btn btn-quiet" href="<?= View::e(Router::url('/reports')) ?>">Limpar</a></div>
</form>

<div class="executive-report-kpis report-kpi-grid-v2">
    <?php $t = $trend($comparisons['new_companies'] ?? null); ?>
    <a class="card" href="<?= View::e(Router::url('/companies')) ?>"><small>Empresas ativas</small><strong><?= $number($metrics['active_companies'] ?? 0) ?></strong><em class="report-trend <?= $t['class'] ?>">+<?= $number($metrics['new_companies'] ?? 0) ?> no período · <?= View::e($t['text']) ?></em></a>
    <a class="card" href="<?= View::e(Router::url('/billing')) ?>"><small>Receita mensal estimada</small><strong><?= $money($metrics['mrr'] ?? 0) ?></strong><em><?= $number($metrics['active_subscriptions'] ?? 0) ?> assinatura(s)</em></a>
    <a class="card" href="<?= View::e(Router::url('/billing?tab=invoices')) ?>"><small>Recebido no período</small><strong><?= $money($metrics['received'] ?? 0) ?></strong><em><?= $money($metrics['overdue_amount'] ?? 0) ?> em atraso</em></a>
    <?php $t = $trend($comparisons['messages'] ?? null); ?>
    <a class="card" href="<?= View::e(Router::url('/conversations')) ?>"><small>Mensagens processadas</small><strong><?= $number($metrics['messages'] ?? 0) ?></strong><em class="report-trend <?= $t['class'] ?>"><?= $number($metrics['ai_replies'] ?? 0) ?> IA · <?= View::e($t['text']) ?></em></a>
    <a class="card" href="<?= View::e(Router::url('/crm')) ?>"><small>Pipeline comercial RS</small><strong><?= $money($metrics['commercial_pipeline'] ?? 0) ?></strong><em><?= $number($metrics['commercial_open'] ?? 0) ?> oportunidade(s)</em></a>
    <?php $t = $trend($comparisons['automation_failures'] ?? null, true); ?>
    <a class="card" href="<?= View::e(Router::url('/central-operacao?tab=monitoring')) ?>"><small>Falhas de automação</small><strong><?= $number($metrics['automation_failures'] ?? 0) ?></strong><em class="report-trend <?= $t['class'] ?>"><?= View::e($t['text']) ?></em></a>
</div>

<section class="report-command-grid">
    <article class="card report-health-overview">
        <div class="section-heading"><div><span class="eyebrow">Saúde da base</span><h2>Situação atual das empresas</h2></div><a class="table-link" href="<?= View::e(Router::url('/companies')) ?>">Abrir empresas</a></div>
        <div class="report-health-content"><div class="report-donut" data-report-donut data-series="<?= View::e((string) $healthSeries) ?>" data-center="<?= $number($metrics['active_companies'] ?? 0) ?>"></div><div class="report-health-list"><?php foreach ($healthDistribution as $row): ?><div><span><?= View::e($row['label']) ?></span><strong><?= $number($row['total']) ?></strong></div><?php endforeach; ?><div><span>Incidentes abertos</span><strong><?= $number($metrics['open_health_incidents'] ?? 0) ?></strong></div></div></div>
    </article>
    <?php if ($insights): ?><article class="card report-insights-panel"><div class="section-heading"><div><span class="eyebrow">Insights do período</span><h2>Leitura executiva</h2></div><span class="badge">Automático</span></div><div class="report-insights-grid is-compact"><?php foreach ($insights as $item): ?><article class="report-insight is-<?= View::e($item['tone'] ?? 'info') ?>"><span class="report-insight-dot"></span><div><strong><?= View::e($item['title'] ?? '') ?></strong><p><?= View::e($item['text'] ?? '') ?></p></div></article><?php endforeach; ?></div></article><?php endif; ?>
</section>

<section class="card report-section-directory" id="report-directory" aria-label="Navegação dos relatórios">
    <div class="section-heading report-directory-heading"><div><span class="eyebrow">Conteúdo do relatório</span><h2>Escolha uma análise</h2><p>Todos os indicadores ficam visíveis na mesma página. Use os atalhos para ir direto ao assunto.</p></div></div>
    <nav class="report-section-card-grid">
        <a class="report-section-link" href="#report-growth" data-report-section-link><span class="report-section-number">01</span><strong>Crescimento</strong><small>Base e saúde dos clientes.</small></a>
        <a class="report-section-link" href="#report-revenue" data-report-section-link><span class="report-section-number">02</span><strong>Receita</strong><small>Assinaturas e cobranças.</small></a>
        <a class="report-section-link" href="#report-usage" data-report-section-link><span class="report-section-number">03</span><strong>Uso da plataforma</strong><small>Volume e adoção por empresa.</small></a>
        <a class="report-section-link" href="#report-automation" data-report-section-link><span class="report-section-number">04</span><strong>IA e automações</strong><small>Respostas e falhas.</small></a>
        <a class="report-section-link" href="#report-agenda" data-report-section-link><span class="report-section-number">05</span><strong>Agenda</strong><small>Conversão e sincronização.</small></a>
        <a class="report-section-link" href="#report-commercial" data-report-section-link><span class="report-section-number">06</span><strong>Comercial RS</strong><small>Pipeline e oportunidades.</small></a>
    </nav>
</section>

<div class="report-section-stack">
    <section class="card report-content-card executive-report-panel" id="report-growth">
        <header class="report-content-card-header"><span class="report-section-number">01</span><div><span class="eyebrow">Crescimento</span><h2>Base e evolução dos clientes</h2><p>Novas empresas, saúde da operação e clientes que demandam atenção.</p></div><a class="report-back-link" href="#report-directory">Voltar ao índice</a></header>
        <div class="executive-report-grid"><section><div class="section-heading"><div><span class="eyebrow">Base de clientes</span><h2>Novas empresas por mês</h2></div><a class="table-link" href="<?= View::e(Router::url('/reports/export?' . http_build_query($queryBase + ['type' => 'companies']))) ?>">Exportar</a></div><div class="executive-bars"><?php $growthMax = $max($companyGrowth); foreach ($companyGrowth as $row): ?><div><strong><?= View::e(date('m/Y', strtotime($row['label'] . '-01'))) ?></strong><span><i style="width:<?= min(100, ((int) $row['total'] / $growthMax) * 100) ?>%"></i></span><b><?= (int) $row['total'] ?></b></div><?php endforeach; ?><?php if (!$companyGrowth): ?><div class="empty-state">Nenhuma empresa cadastrada no período.</div><?php endif; ?></div></section><aside class="executive-insight-card"><span class="eyebrow">Base atual</span><h3>Movimentação</h3><dl><div><dt>Novas</dt><dd><?= $number($metrics['new_companies'] ?? 0) ?></dd></div><div><dt>Ativas</dt><dd><?= $number($metrics['active_companies'] ?? 0) ?></dd></div><div><dt>Inativas/suspensas</dt><dd><?= $number($metrics['inactive_companies'] ?? 0) ?></dd></div><div><dt>Atenção/críticas</dt><dd><?= $number(($metrics['attention_companies'] ?? 0)+($metrics['critical_companies'] ?? 0)) ?></dd></div></dl></aside></div>
    </section>

    <section class="card report-content-card executive-report-panel" id="report-revenue">
        <header class="report-content-card-header"><span class="report-section-number">02</span><div><span class="eyebrow">Receita</span><h2>Assinaturas e cobranças</h2><p>Receita recorrente estimada, valores recebidos e vencimentos.</p></div><a class="report-back-link" href="#report-directory">Voltar ao índice</a></header>
        <div class="executive-report-grid"><section><div class="section-heading"><div><span class="eyebrow">Planos</span><h2>Receita recorrente por plano</h2></div><a class="table-link" href="<?= View::e(Router::url('/reports/export?' . http_build_query($queryBase + ['type' => 'revenue']))) ?>">Exportar cobranças</a></div><div class="executive-bars"><?php $planMax = $max($revenueByPlan); foreach ($revenueByPlan as $row): ?><div><strong><?= View::e($row['label']) ?></strong><span><i style="width:<?= min(100, ((float) $row['total'] / $planMax) * 100) ?>%"></i></span><b><?= $money($row['total']) ?></b></div><?php endforeach; ?><?php if (!$revenueByPlan): ?><div class="empty-state">Nenhuma assinatura ativa encontrada.</div><?php endif; ?></div></section><aside class="executive-insight-card"><span class="eyebrow">Financeiro</span><h3>Visão de cobrança</h3><dl><div><dt>MRR estimado</dt><dd><?= $money($metrics['mrr'] ?? 0) ?></dd></div><div><dt>A receber</dt><dd><?= $money($metrics['expected'] ?? 0) ?></dd></div><div><dt>Faturas vencidas</dt><dd><?= $number($metrics['overdue_count'] ?? 0) ?></dd></div><div><dt>Em atraso</dt><dd><?= $money($metrics['overdue_amount'] ?? 0) ?></dd></div></dl><a class="btn btn-primary btn-block" href="<?= View::e(Router::url('/billing?tab=invoices')) ?>">Abrir cobranças</a></aside></div>
        <div class="executive-table"><div class="section-heading"><div><span class="eyebrow">Cobranças</span><h2>Últimos vencimentos</h2></div></div><div class="table-wrap"><table><thead><tr><th>Empresa</th><th>Cobrança</th><th>Valor</th><th>Vencimento</th><th>Situação</th></tr></thead><tbody><?php foreach ($recentInvoices as $invoice): ?><tr><td><?= View::e($invoice['tenant_name']) ?></td><td><?= View::e($invoice['invoice_number']) ?></td><td><?= $money($invoice['amount']) ?></td><td><?= View::e(date('d/m/Y', strtotime($invoice['due_date']))) ?></td><td><span class="badge badge-<?= View::e($invoice['status']) ?>"><?= View::e($invoice['status']) ?></span></td></tr><?php endforeach; ?><?php if (!$recentInvoices): ?><tr><td colspan="5"><div class="empty-state">Nenhuma cobrança encontrada.</div></td></tr><?php endif; ?></tbody></table></div></div>
    </section>

    <section class="card report-content-card executive-report-panel" id="report-usage">
        <header class="report-content-card-header"><span class="report-section-number">03</span><div><span class="eyebrow">Uso da plataforma</span><h2>Adoção e movimento por empresa</h2><p>Veja a tendência diária e compare os clientes mais ativos.</p></div><a class="report-back-link" href="#report-directory">Voltar ao índice</a></header>
        <div class="report-chart-layout"><section class="report-chart-card"><div class="section-heading"><div><span class="eyebrow">Volume diário</span><h2>Mensagens processadas</h2></div></div><div class="report-svg-chart" data-report-line-chart data-series="<?= View::e((string) $lineSeries) ?>"></div><div class="report-chart-legend"><span><i class="is-total"></i>Total</span><span><i class="is-incoming"></i>Recebidas</span><span><i class="is-ai"></i>IA</span></div></section><aside class="report-ranking-card"><div class="section-heading"><div><span class="eyebrow">Ranking</span><h2>Empresas com maior uso</h2></div><a class="table-link" href="<?= View::e(Router::url('/reports/export?' . http_build_query($queryBase + ['type' => 'usage']))) ?>">Exportar</a></div><div class="report-ranking"><?php $usageMax=$max($usageByTenant,'messages'); foreach (array_slice($usageByTenant,0,8) as $idx=>$row): ?><a href="<?= View::e(Router::url('/companies/overview?id=' . (int) $row['id'])) ?>"><b><?= $idx+1 ?></b><span><strong><?= View::e($row['name']) ?></strong><i><em style="width:<?= min(100,((int)$row['messages']/$usageMax)*100) ?>%"></em></i></span><small><?= $number($row['messages']) ?></small></a><?php endforeach; ?><?php if (!$usageByTenant): ?><div class="empty-state">Nenhum uso registrado no período.</div><?php endif; ?></div></aside></div>
        <div class="executive-low-usage"><div class="section-heading"><div><span class="eyebrow">Atenção comercial</span><h2>Clientes com pouco uso</h2></div></div><div class="executive-card-list"><?php foreach ($lowUsage as $row): ?><a href="<?= View::e(Router::url('/companies/overview?id=' . (int) $row['id'])) ?>"><strong><?= View::e($row['name']) ?></strong><span><?= $number($row['messages']) ?> mensagem(ns) no período</span><small>Última mensagem: <?= $row['last_message_at'] ? View::e(date('d/m/Y H:i', strtotime($row['last_message_at']))) : 'sem uso registrado' ?></small></a><?php endforeach; ?><?php if (!$lowUsage): ?><div class="empty-state">Nenhuma empresa com baixo uso dentro do filtro.</div><?php endif; ?></div></div>
    </section>

    <section class="card report-content-card executive-report-panel" id="report-automation">
        <header class="report-content-card-header"><span class="report-section-number">04</span><div><span class="eyebrow">IA e automações</span><h2>Qualidade das respostas e integrações</h2><p>Monitore automação, conectividade e tendência de falhas.</p></div><a class="report-back-link" href="#report-directory">Voltar ao índice</a></header>
        <div class="report-chart-layout"><section class="report-chart-card"><div class="section-heading"><div><span class="eyebrow">Erros por dia</span><h2>Tendência de falhas</h2></div></div><div class="report-svg-chart is-compact" data-report-line-chart data-series="<?= View::e((string) $failureSeries) ?>" data-single-series="total"></div></section><aside class="executive-insight-card"><span class="eyebrow">Conectividade</span><h3>Operação das integrações</h3><dl><div><dt>WhatsApps conectados</dt><dd><?= $number($metrics['connected_instances'] ?? 0) ?></dd></div><div><dt>Desconectados</dt><dd><?= $number($metrics['disconnected_instances'] ?? 0) ?></dd></div><div><dt>Falhas IA</dt><dd><?= $number($metrics['ai_failures'] ?? 0) ?></dd></div><div><dt>Falhas n8n</dt><dd><?= $number($metrics['n8n_failures'] ?? 0) ?></dd></div><div><dt>Falhas Google Agenda</dt><dd><?= $number($metrics['google_sync_failures'] ?? 0) ?></dd></div></dl><a class="btn btn-outline btn-block" href="<?= View::e(Router::url('/central-operacao?tab=monitoring')) ?>">Abrir monitoramento</a></aside></div>
        <div class="executive-card-list executive-failure-list"><div class="section-heading"><div><span class="eyebrow">Causas recorrentes</span><h2>Falhas por tipo</h2></div><a class="table-link" href="<?= View::e(Router::url('/reports/export?' . http_build_query($queryBase + ['type' => 'failures']))) ?>">Exportar detalhes</a></div><?php foreach ($failures as $row): ?><article><span class="badge <?= $row['source'] === 'IA' ? 'badge-info' : 'badge-warning' ?>"><?= View::e($row['source']) ?></span><strong><?= View::e($row['label']) ?></strong><b><?= (int) $row['total'] ?></b></article><?php endforeach; ?><?php if (!$failures): ?><div class="empty-state">Nenhuma falha registrada no período.</div><?php endif; ?></div>
    </section>

    <section class="card report-content-card executive-report-panel" id="report-agenda">
        <header class="report-content-card-header"><span class="report-section-number">05</span><div><span class="eyebrow">Agenda</span><h2>Compromissos e conversão</h2><p>Analise confirmações, cancelamentos e uso da agenda pelos clientes.</p></div><a class="report-back-link" href="#report-directory">Voltar ao índice</a></header>
        <div class="executive-report-grid"><section><div class="section-heading"><div><span class="eyebrow">Conversão</span><h2>Situação dos compromissos</h2></div></div><div class="executive-bars"><?php $agendaMax = $max($agendaStatus); foreach ($agendaStatus as $row): ?><div><strong><?= View::e($row['label']) ?></strong><span><i style="width:<?= min(100, ((int) $row['total'] / $agendaMax) * 100) ?>%"></i></span><b><?= (int) $row['total'] ?></b></div><?php endforeach; ?><?php if (!$agendaStatus): ?><div class="empty-state">Nenhum compromisso no período.</div><?php endif; ?></div></section><aside class="executive-insight-card"><span class="eyebrow">Agenda</span><h3>Resultados</h3><dl><div><dt>Total</dt><dd><?= $number($metrics['appointments'] ?? 0) ?></dd></div><div><dt>Confirmados/concluídos</dt><dd><?= $number($metrics['appointments_confirmed'] ?? 0) ?></dd></div><div><dt>Cancelados/no-show</dt><dd><?= $number($metrics['appointments_cancelled'] ?? 0) ?></dd></div><div><dt>Conversão</dt><dd><?= number_format((float) ($metrics['agenda_conversion'] ?? 0), 1, ',', '.') ?>%</dd></div></dl><a class="btn btn-outline btn-block" href="<?= View::e(Router::url('/calendar')) ?>">Abrir agenda</a></aside></div>
    </section>

    <section class="card report-content-card executive-report-panel" id="report-commercial">
        <header class="report-content-card-header"><span class="report-section-number">06</span><div><span class="eyebrow">Comercial RS</span><h2>Pipeline e resultado comercial</h2><p>Acompanhe oportunidades abertas, valores e desempenho das etapas.</p></div><a class="report-back-link" href="#report-directory">Voltar ao índice</a></header>
        <div class="section-heading"><div><span class="eyebrow">Funil RS Connect</span><h2>Oportunidades por etapa</h2></div><div><a class="table-link" href="<?= View::e(Router::url('/reports/export?' . http_build_query($queryBase + ['type' => 'commercial']))) ?>">Exportar CRM</a> · <a class="table-link" href="<?= View::e(Router::url('/crm')) ?>">Abrir CRM</a></div></div>
        <div class="report-funnel is-admin"><?php $commercialMax=$max($commercialStages); foreach ($commercialStages as $row): $width=max(18,min(100,((int)$row['total']/$commercialMax)*100)); ?><article><span><?= View::e($row['label']) ?></span><div style="width:<?= $width ?>%"><strong><?= (int)$row['total'] ?></strong><small><?= $money($row['value']) ?></small></div></article><?php endforeach; ?><?php if (!$commercialStages): ?><div class="empty-state">Aplique a migration 037 para carregar o CRM comercial.</div><?php endif; ?></div>
    </section>
</div>
<script src="<?= View::e(Router::url('/assets/js/reports.js?v=36.4.2')) ?>" defer></script>
</div>
