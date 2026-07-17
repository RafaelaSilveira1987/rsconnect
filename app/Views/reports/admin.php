<?php

use App\Core\Router;
use App\Core\View;

$metrics = $reportData['metrics'] ?? [];
$companyGrowth = $reportData['companyGrowth'] ?? [];
$messagesByDay = $reportData['messagesByDay'] ?? [];
$revenueByPlan = $reportData['revenueByPlan'] ?? [];
$usageByTenant = $reportData['usageByTenant'] ?? [];
$lowUsage = $reportData['lowUsage'] ?? [];
$failures = $reportData['failures'] ?? [];
$agendaStatus = $reportData['agendaStatus'] ?? [];
$commercialStages = $reportData['commercialStages'] ?? [];
$recentInvoices = $reportData['recentInvoices'] ?? [];
$tenants = $reportData['tenants'] ?? [];
$warnings = $reportData['warnings'] ?? [];
$money = static fn (float|int|string $value): string => 'R$ ' . number_format((float) $value, 2, ',', '.');
$number = static fn (float|int|string $value): string => number_format((float) $value, 0, ',', '.');
$max = static function (array $rows, string $key = 'total'): int {
    $values = array_map(static fn (array $row): int => (int) ($row[$key] ?? 0), $rows);
    return max(1, ...($values ?: [1]));
};
$requestedSection = (string) ($_GET['section'] ?? 'growth');
$activeSection = in_array($requestedSection, ['growth','revenue','usage','automation','agenda','commercial'], true) ? $requestedSection : 'growth';
$queryBase = array_filter([
    'start' => $filters['start'] ?? '',
    'end' => $filters['end'] ?? '',
    'tenant_id' => (int) ($filters['tenant_id'] ?? 0),
], static fn ($value) => $value !== '' && $value !== 0);
?>
<link rel="stylesheet" href="<?= View::e(Router::url('/assets/css/reports.css?v=34.1')) ?>">
<div class="executive-report-page executive-report-admin">
<section class="admin-executive-hero executive-report-hero">
    <div class="admin-executive-hero-copy">
        <span class="eyebrow">Inteligência administrativa</span>
        <h2>Relatórios executivos da RS Connect</h2>
        <p>Compare crescimento, receita, adoção, qualidade das automações, agenda e desempenho comercial com acesso direto aos registros.</p>
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

<div class="executive-report-kpis">
    <a class="card" href="<?= View::e(Router::url('/companies')) ?>"><small>Empresas ativas</small><strong><?= $number($metrics['active_companies'] ?? 0) ?></strong><em>+<?= $number($metrics['new_companies'] ?? 0) ?> no período</em></a>
    <a class="card" href="<?= View::e(Router::url('/billing')) ?>"><small>Receita mensal estimada</small><strong><?= $money($metrics['mrr'] ?? 0) ?></strong><em><?= $number($metrics['active_subscriptions'] ?? 0) ?> assinatura(s)</em></a>
    <a class="card" href="<?= View::e(Router::url('/billing?tab=invoices')) ?>"><small>Recebido no período</small><strong><?= $money($metrics['received'] ?? 0) ?></strong><em><?= $money($metrics['overdue_amount'] ?? 0) ?> em atraso</em></a>
    <a class="card" href="<?= View::e(Router::url('/conversations')) ?>"><small>Mensagens processadas</small><strong><?= $number($metrics['messages'] ?? 0) ?></strong><em><?= $number($metrics['ai_replies'] ?? 0) ?> respostas da IA</em></a>
    <a class="card" href="<?= View::e(Router::url('/crm')) ?>"><small>Pipeline comercial RS</small><strong><?= $money($metrics['commercial_pipeline'] ?? 0) ?></strong><em><?= $number($metrics['commercial_open'] ?? 0) ?> oportunidade(s)</em></a>
    <a class="card" href="<?= View::e(Router::url('/central-operacao?tab=monitoring')) ?>"><small>Falhas de automação</small><strong><?= $number($metrics['automation_failures'] ?? 0) ?></strong><em>IA e n8n no período</em></a>
</div>

<section class="card executive-report-tabs-card">
    <div class="settings-tabs executive-report-tabs" data-tabs>
        <button class="<?= $activeSection === 'growth' ? 'is-active' : '' ?>" type="button" data-tab-target="growth">Crescimento</button>
        <button class="<?= $activeSection === 'revenue' ? 'is-active' : '' ?>" type="button" data-tab-target="revenue">Receita</button>
        <button class="<?= $activeSection === 'usage' ? 'is-active' : '' ?>" type="button" data-tab-target="usage">Uso da plataforma</button>
        <button class="<?= $activeSection === 'automation' ? 'is-active' : '' ?>" type="button" data-tab-target="automation">IA e automações</button>
        <button class="<?= $activeSection === 'agenda' ? 'is-active' : '' ?>" type="button" data-tab-target="agenda">Agenda</button>
        <button class="<?= $activeSection === 'commercial' ? 'is-active' : '' ?>" type="button" data-tab-target="commercial">Comercial RS</button>
    </div>

    <div class="executive-report-panel" data-tab-panel="growth" <?= $activeSection !== 'growth' ? 'hidden' : '' ?>>
        <div class="executive-report-grid">
            <section><div class="section-heading"><div><span class="eyebrow">Base de clientes</span><h2>Novas empresas por mês</h2></div><a class="table-link" href="<?= View::e(Router::url('/reports/export?' . http_build_query($queryBase + ['type' => 'companies']))) ?>">Exportar</a></div><div class="executive-bars"><?php $growthMax = $max($companyGrowth); foreach ($companyGrowth as $row): ?><div><strong><?= View::e(date('m/Y', strtotime($row['label'] . '-01'))) ?></strong><span><i style="width:<?= min(100, ((int) $row['total'] / $growthMax) * 100) ?>%"></i></span><b><?= (int) $row['total'] ?></b></div><?php endforeach; ?><?php if (!$companyGrowth): ?><div class="empty-state">Nenhuma empresa cadastrada no período.</div><?php endif; ?></div></section>
            <aside class="executive-insight-card"><span class="eyebrow">Resumo</span><h3>Movimentação da base</h3><dl><div><dt>Novas</dt><dd><?= $number($metrics['new_companies'] ?? 0) ?></dd></div><div><dt>Ativas</dt><dd><?= $number($metrics['active_companies'] ?? 0) ?></dd></div><div><dt>Inativas/suspensas</dt><dd><?= $number($metrics['inactive_companies'] ?? 0) ?></dd></div></dl><a class="btn btn-outline btn-block" href="<?= View::e(Router::url('/companies')) ?>">Analisar empresas</a></aside>
        </div>
    </div>

    <div class="executive-report-panel" data-tab-panel="revenue" <?= $activeSection !== 'revenue' ? 'hidden' : '' ?>>
        <div class="executive-report-grid">
            <section><div class="section-heading"><div><span class="eyebrow">Planos</span><h2>Receita recorrente por plano</h2></div><a class="table-link" href="<?= View::e(Router::url('/reports/export?' . http_build_query($queryBase + ['type' => 'revenue']))) ?>">Exportar cobranças</a></div><div class="executive-bars"><?php $planMax = $max($revenueByPlan); foreach ($revenueByPlan as $row): ?><div><strong><?= View::e($row['label']) ?></strong><span><i style="width:<?= min(100, ((float) $row['total'] / $planMax) * 100) ?>%"></i></span><b><?= $money($row['total']) ?></b></div><?php endforeach; ?><?php if (!$revenueByPlan): ?><div class="empty-state">Nenhuma assinatura ativa encontrada.</div><?php endif; ?></div></section>
            <aside class="executive-insight-card"><span class="eyebrow">Financeiro</span><h3>Visão de cobrança</h3><dl><div><dt>MRR estimado</dt><dd><?= $money($metrics['mrr'] ?? 0) ?></dd></div><div><dt>A receber</dt><dd><?= $money($metrics['expected'] ?? 0) ?></dd></div><div><dt>Faturas vencidas</dt><dd><?= $number($metrics['overdue_count'] ?? 0) ?></dd></div></dl><a class="btn btn-primary btn-block" href="<?= View::e(Router::url('/billing?tab=invoices')) ?>">Abrir cobranças</a></aside>
        </div>
        <div class="executive-table"><div class="section-heading"><div><span class="eyebrow">Cobranças</span><h2>Últimos vencimentos</h2></div></div><div class="table-wrap"><table><thead><tr><th>Empresa</th><th>Cobrança</th><th>Valor</th><th>Vencimento</th><th>Situação</th></tr></thead><tbody><?php foreach ($recentInvoices as $invoice): ?><tr><td><?= View::e($invoice['tenant_name']) ?></td><td><?= View::e($invoice['invoice_number']) ?></td><td><?= $money($invoice['amount']) ?></td><td><?= View::e(date('d/m/Y', strtotime($invoice['due_date']))) ?></td><td><span class="badge badge-<?= View::e($invoice['status']) ?>"><?= View::e($invoice['status']) ?></span></td></tr><?php endforeach; ?><?php if (!$recentInvoices): ?><tr><td colspan="5"><div class="empty-state">Nenhuma cobrança encontrada.</div></td></tr><?php endif; ?></tbody></table></div></div>
    </div>

    <div class="executive-report-panel" data-tab-panel="usage" <?= $activeSection !== 'usage' ? 'hidden' : '' ?>>
        <div class="section-heading"><div><span class="eyebrow">Adoção</span><h2>Uso por empresa</h2></div><a class="table-link" href="<?= View::e(Router::url('/reports/export?' . http_build_query($queryBase + ['type' => 'usage']))) ?>">Exportar uso</a></div>
        <div class="executive-table"><div class="table-wrap"><table><thead><tr><th>Empresa</th><th>Conversas</th><th>Mensagens</th><th>Respostas IA</th><th>Atendimento humano</th><th>Ação</th></tr></thead><tbody><?php foreach ($usageByTenant as $row): ?><tr><td><strong><?= View::e($row['name']) ?></strong></td><td><?= $number($row['conversations']) ?></td><td><?= $number($row['messages']) ?></td><td><?= $number($row['ai_replies']) ?></td><td><?= $number($row['human_conversations']) ?></td><td><a class="table-link" href="<?= View::e(Router::url('/companies/overview?id=' . (int) $row['id'])) ?>">Abrir empresa</a></td></tr><?php endforeach; ?><?php if (!$usageByTenant): ?><tr><td colspan="6"><div class="empty-state">Nenhum uso registrado no período.</div></td></tr><?php endif; ?></tbody></table></div></div>
        <div class="executive-low-usage"><div class="section-heading"><div><span class="eyebrow">Atenção comercial</span><h2>Clientes com pouco uso</h2></div></div><div class="executive-card-list"><?php foreach ($lowUsage as $row): ?><a href="<?= View::e(Router::url('/companies/overview?id=' . (int) $row['id'])) ?>"><strong><?= View::e($row['name']) ?></strong><span><?= $number($row['messages']) ?> mensagem(ns) no período</span><small>Última mensagem: <?= $row['last_message_at'] ? View::e(date('d/m/Y H:i', strtotime($row['last_message_at']))) : 'sem uso registrado' ?></small></a><?php endforeach; ?><?php if (!$lowUsage): ?><div class="empty-state">Nenhuma empresa com baixo uso dentro do filtro.</div><?php endif; ?></div></div>
    </div>

    <div class="executive-report-panel" data-tab-panel="automation" <?= $activeSection !== 'automation' ? 'hidden' : '' ?>>
        <div class="executive-report-grid">
            <section><div class="section-heading"><div><span class="eyebrow">Volume</span><h2>Mensagens e IA por dia</h2></div></div><div class="executive-bars"><?php $messageMax = $max($messagesByDay); foreach ($messagesByDay as $row): ?><div><strong><?= View::e(date('d/m', strtotime($row['label']))) ?></strong><span><i style="width:<?= min(100, ((int) $row['total'] / $messageMax) * 100) ?>%"></i></span><b><?= (int) $row['total'] ?></b><small><?= (int) $row['ai'] ?> IA</small></div><?php endforeach; ?><?php if (!$messagesByDay): ?><div class="empty-state">Nenhuma mensagem no período.</div><?php endif; ?></div></section>
            <aside class="executive-insight-card"><span class="eyebrow">Conectividade</span><h3>Operação das integrações</h3><dl><div><dt>WhatsApps conectados</dt><dd><?= $number($metrics['connected_instances'] ?? 0) ?></dd></div><div><dt>Desconectados</dt><dd><?= $number($metrics['disconnected_instances'] ?? 0) ?></dd></div><div><dt>Falhas IA</dt><dd><?= $number($metrics['ai_failures'] ?? 0) ?></dd></div><div><dt>Falhas n8n</dt><dd><?= $number($metrics['n8n_failures'] ?? 0) ?></dd></div></dl><a class="btn btn-outline btn-block" href="<?= View::e(Router::url('/central-operacao?tab=monitoring')) ?>">Abrir monitoramento</a></aside>
        </div>
        <div class="executive-card-list executive-failure-list"><div class="section-heading"><div><span class="eyebrow">Causas recorrentes</span><h2>Falhas por tipo</h2></div><a class="table-link" href="<?= View::e(Router::url('/reports/export?' . http_build_query($queryBase + ['type' => 'failures']))) ?>">Exportar detalhes</a></div><?php foreach ($failures as $row): ?><article><span class="badge <?= $row['source'] === 'IA' ? 'badge-info' : 'badge-warning' ?>"><?= View::e($row['source']) ?></span><strong><?= View::e($row['label']) ?></strong><b><?= (int) $row['total'] ?></b></article><?php endforeach; ?><?php if (!$failures): ?><div class="empty-state">Nenhuma falha de IA ou n8n no período.</div><?php endif; ?></div>
    </div>

    <div class="executive-report-panel" data-tab-panel="agenda" <?= $activeSection !== 'agenda' ? 'hidden' : '' ?>>
        <div class="executive-report-grid"><section><div class="section-heading"><div><span class="eyebrow">Conversão</span><h2>Situação dos compromissos</h2></div></div><div class="executive-bars"><?php $agendaMax = $max($agendaStatus); foreach ($agendaStatus as $row): ?><div><strong><?= View::e($row['label']) ?></strong><span><i style="width:<?= min(100, ((int) $row['total'] / $agendaMax) * 100) ?>%"></i></span><b><?= (int) $row['total'] ?></b></div><?php endforeach; ?><?php if (!$agendaStatus): ?><div class="empty-state">Nenhum compromisso no período.</div><?php endif; ?></div></section><aside class="executive-insight-card"><span class="eyebrow">Agenda</span><h3>Resultados do período</h3><dl><div><dt>Total</dt><dd><?= $number($metrics['appointments'] ?? 0) ?></dd></div><div><dt>Confirmados/concluídos</dt><dd><?= $number($metrics['appointments_confirmed'] ?? 0) ?></dd></div><div><dt>Cancelados/recusados</dt><dd><?= $number($metrics['appointments_cancelled'] ?? 0) ?></dd></div><div><dt>Conversão</dt><dd><?= number_format((float) ($metrics['agenda_conversion'] ?? 0), 1, ',', '.') ?>%</dd></div></dl><a class="btn btn-outline btn-block" href="<?= View::e(Router::url('/calendar')) ?>">Abrir agenda</a></aside></div>
    </div>

    <div class="executive-report-panel" data-tab-panel="commercial" <?= $activeSection !== 'commercial' ? 'hidden' : '' ?>>
        <div class="section-heading"><div><span class="eyebrow">Funil RS Connect</span><h2>Oportunidades por etapa</h2></div><div><a class="table-link" href="<?= View::e(Router::url('/reports/export?' . http_build_query($queryBase + ['type' => 'commercial']))) ?>">Exportar CRM</a> · <a class="table-link" href="<?= View::e(Router::url('/crm')) ?>">Abrir CRM</a></div></div>
        <div class="executive-commercial-grid"><?php foreach ($commercialStages as $row): ?><a href="<?= View::e(Router::url('/crm')) ?>" class="stage-<?= View::e($row['color_key']) ?>"><span><?= View::e($row['label']) ?></span><strong><?= (int) $row['total'] ?></strong><small><?= $money($row['value']) ?></small></a><?php endforeach; ?><?php if (!$commercialStages): ?><div class="empty-state">Aplique a migration 037 para carregar o CRM comercial.</div><?php endif; ?></div>
    </div>
</section>
<script src="<?= View::e(Router::url('/assets/js/reports.js?v=34.1')) ?>" defer></script>
</div>
