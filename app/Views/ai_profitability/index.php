<?php

use App\Core\Router;
use App\Core\View;

$tenants = is_array($tenantOptions ?? null) ? $tenantOptions : [];
$selectedTenant = (int) ($selectedTenantId ?? 0);
$months = (int) ($historyMonths ?? 6);
$portfolio = is_array($portfolioProfitability ?? null) ? $portfolioProfitability : [];
$portfolioHistory = is_array($portfolioProfitabilityHistory ?? null) ? $portfolioProfitabilityHistory : [];
$selected = is_array($selectedProfitability ?? null) ? $selectedProfitability : [];
$history = is_array($selected['history'] ?? null) ? $selected['history'] : [];
$current = is_array($selected['current'] ?? null) ? $selected['current'] : [];
$trends = is_array($selected['trends'] ?? null) ? $selected['trends'] : [];
$simulation = is_array($selected['simulation'] ?? null) ? $selected['simulation'] : [];
$plans = is_array($simulation['plans'] ?? null) ? $simulation['plans'] : [];

$brl = static fn (float $value): string => 'R$ ' . number_format($value, 2, ',', '.');
$usd = static fn (float $value): string => 'US$ ' . number_format($value, 4, ',', '.');
$percent = static fn (?float $value): string => $value === null ? '—' : number_format($value * 100, 1, ',', '.') . '%';
$delta = static function (?float $value): string {
    if ($value === null) return 'sem base anterior';
    $prefix = $value > .0001 ? '+' : '';
    return $prefix . number_format($value * 100, 1, ',', '.') . '%';
};
$qualityLabel = static fn (string $quality): string => match ($quality) {
    'actual' => 'Faturado/pago',
    'contracted' => 'Contratado',
    'estimated' => 'Estimado',
    default => 'Sem base',
};
$businessStatusLabel = static fn (string $value): string => match ($value) {
    'healthy' => 'Dentro do esperado',
    'attention' => 'Precisa de atenção',
    'critical' => 'Margem baixa',
    'loss' => 'Custos acima da receita',
    'unconfigured' => 'Dados incompletos',
    default => 'Dados incompletos',
};
$recommendationLabel = static fn (string $value): string => match ($value) {
    'keep' => 'Manter condição atual',
    'optimize_first' => 'Otimizar antes de reajustar',
    'review_plan' => 'Revisar plano/condição',
    'custom_price' => 'Usar um valor personalizado',
    default => 'Configurar análise',
};

$selectedTenantName = '';
foreach ($tenants as $tenant) {
    if ((int) ($tenant['id'] ?? 0) === $selectedTenant) {
        $selectedTenantName = (string) ($tenant['name'] ?? '');
        break;
    }
}

$historyMax = 1.0;
foreach ($history as $row) {
    $historyMax = max($historyMax, (float) ($row['revenue_brl'] ?? 0), (float) ($row['known_cost_brl'] ?? 0));
}
$portfolioMax = 1.0;
foreach ($portfolioHistory as $row) {
    $portfolioMax = max($portfolioMax, (float) ($row['revenue_brl'] ?? 0), (float) ($row['known_cost_brl'] ?? 0));
}
?>

<section class="ai-credentials-hero profitability-hero">
    <div>
        <span class="eyebrow">Resultados por cliente</span>
        <h2>Receita, custos e resultado da IA</h2>
        <p>Veja quanto cada cliente paga, quanto a IA custa e se o plano ou o valor mensal precisam de atenção.</p>
    </div>
    <div class="openai-usage-hero-actions">
        <a class="btn btn-outline" href="<?= View::e(Router::url('/openai-usage')) ?>">Uso e custo da IA</a>
        <a class="btn btn-primary" href="<?= View::e((string) ($refreshUrl ?? Router::url('/ai-profitability'))) ?>">Atualizar histórico</a>
    </div>
</section>

<section class="card profitability-command-bar">
    <form method="get" action="<?= View::e(Router::url('/ai-profitability')) ?>">
        <label><span>Empresa</span><select name="tenant_id"><option value="0">Visão geral</option><?php foreach ($tenants as $tenant): ?><option value="<?= (int) ($tenant['id'] ?? 0) ?>" <?= (int) ($tenant['id'] ?? 0) === $selectedTenant ? 'selected' : '' ?>><?= View::e((string) ($tenant['name'] ?? '')) ?></option><?php endforeach; ?></select></label>
        <label><span>Histórico</span><select name="months"><?php foreach ([3,6,12,24] as $option): ?><option value="<?= $option ?>" <?= $months === $option ? 'selected' : '' ?>><?= $option ?> meses</option><?php endforeach; ?></select></label>
        <button class="btn btn-outline btn-small" type="submit">Aplicar</button>
    </form>
    <small>Para cada mês, o sistema usa primeiro o valor realmente cobrado. Se não houver, usa o plano ou o valor informado.</small>
</section>

<section class="profitability-kpi-grid">
    <article class="profitability-kpi is-dark"><span>Receita mensal analisada</span><strong><?= View::e($brl((float) ($portfolio['mrr_brl'] ?? 0))) ?></strong><small><?= (int) ($portfolio['configured_tenants'] ?? 0) ?> empresa(s) analisadas</small></article>
    <article class="profitability-kpi is-teal"><span>Custo previsto da IA</span><strong><?= View::e($brl((float) ($portfolio['projected_ai_cost_brl'] ?? 0))) ?></strong><small>estimativa até o fim do mês</small></article>
    <article class="profitability-kpi is-green"><span>Valor que sobra</span><strong><?= View::e($brl((float) ($portfolio['contribution_brl'] ?? 0))) ?></strong><small>depois dos custos informados</small></article>
    <article class="profitability-kpi is-blue"><span>Percentual que sobra</span><strong><?= View::e($percent(isset($portfolio['margin_rate']) ? (float) $portfolio['margin_rate'] : null)) ?></strong><small>média considerando o valor de cada cliente</small></article>
    <article class="profitability-kpi is-orange"><span>Receita que precisa de atenção</span><strong><?= View::e($brl((float) ($portfolio['mrr_under_target_brl'] ?? 0))) ?></strong><small><?= View::e($percent((float) ($portfolio['mrr_under_target_rate'] ?? 0))) ?> da receita mensal analisada</small></article>
    <article class="profitability-kpi is-purple"><span>Clientes que precisam de atenção</span><strong><?= number_format((int) ($portfolio['review_tenants'] ?? 0),0,',','.') ?></strong><small><?= number_format((int) ($portfolio['healthy_tenants'] ?? 0),0,',','.') ?> dentro do esperado</small></article>
</section>

<section class="card profitability-history-panel">
    <div class="section-heading">
        <div><span class="eyebrow">Visão geral</span><h2>Evolução mensal do valor que sobra</h2><p>Compare o valor recebido, os custos informados e o percentual que sobra em cada mês.</p></div>
    </div>
    <div class="profitability-history-chart" aria-label="Histórico dos resultados de todos os clientes">
        <?php foreach ($portfolioHistory as $row):
            $revenue = (float) ($row['revenue_brl'] ?? 0);
            $cost = (float) ($row['known_cost_brl'] ?? 0);
            $margin = $row['margin_rate'] ?? null;
        ?>
        <div class="profitability-history-month">
            <div class="profitability-history-bars">
                <span class="is-revenue" style="--height:<?= View::e(number_format(max(3, ($revenue / $portfolioMax) * 100),2,'.','')) ?>%" title="Receita <?= View::e($brl($revenue)) ?>"></span>
                <span class="is-cost" style="--height:<?= View::e(number_format(max($cost > 0 ? 3 : 0, ($cost / $portfolioMax) * 100),2,'.','')) ?>%" title="Custos <?= View::e($brl($cost)) ?>"></span>
            </div>
            <strong><?= View::e((string) ($row['label'] ?? '')) ?></strong>
            <small><?= View::e($percent($margin !== null ? (float) $margin : null)) ?></small>
        </div>
        <?php endforeach; ?>
        <?php if (!$portfolioHistory): ?><div class="empty-state">O histórico aparecerá depois que a atualização do banco desta versão for concluída.</div><?php endif; ?>
    </div>
    <div class="profitability-chart-legend"><span><i class="is-revenue"></i> Receita</span><span><i class="is-cost"></i> Custos informados</span></div>
</section>

<section class="card profitability-portfolio-table">
    <div class="section-heading"><div><span class="eyebrow">Clientes</span><h2>Resultado atual por empresa</h2><p>Os clientes que precisam de mais atenção aparecem primeiro.</p></div></div>
    <div class="profitability-table-wrap">
        <table class="data-table profitability-table">
            <thead><tr><th>Empresa</th><th>Plano</th><th>Valor mensal</th><th>Custo previsto da IA</th><th>Percentual que sobra</th><th>Valor mínimo sugerido</th><th></th></tr></thead>
            <tbody>
            <?php foreach (($portfolio['rows'] ?? []) as $row):
                $tenantUrl = Router::url('/ai-profitability') . '?' . http_build_query(['tenant_id' => (int) ($row['tenant_id'] ?? 0), 'months' => $months]);
            ?>
                <tr>
                    <td><strong><?= View::e((string) ($row['tenant_name'] ?? 'Empresa')) ?></strong><small><?= View::e($businessStatusLabel((string) ($row['status'] ?? 'unconfigured'))) ?></small></td>
                    <td><?= View::e((string) ($row['subscription']['plan_name'] ?? 'Sem plano')) ?></td>
                    <td><?= View::e($brl((float) ($row['revenue_brl'] ?? 0))) ?></td>
                    <td><?= View::e($brl((float) ($row['projected_ai_cost_brl'] ?? 0))) ?></td>
                    <td><?= View::e($percent(($row['projected_margin_rate'] ?? null) !== null ? (float) $row['projected_margin_rate'] : null)) ?></td>
                    <td><?= View::e($brl((float) ($row['recommended_revenue_brl'] ?? 0))) ?></td>
                    <td><a class="btn btn-outline btn-small" href="<?= View::e($tenantUrl) ?>">Analisar</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($portfolio['rows'])): ?><tr><td colspan="7"><div class="empty-state">Nenhuma empresa disponível para análise.</div></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php if ($selectedTenant > 0 && $selected): ?>
<section class="card profitability-tenant-panel">
    <div class="section-heading">
        <div><span class="eyebrow">Empresa selecionada</span><h2><?= View::e($selectedTenantName !== '' ? $selectedTenantName : 'Empresa') ?></h2><p>Veja a mudança do valor recebido, do custo da IA e do percentual que sobra.</p></div>
        <div class="profitability-trend-badges">
            <span>Receita <?= View::e($delta(isset($trends['revenue_delta_rate']) ? $trends['revenue_delta_rate'] : null)) ?></span>
            <span>Custo da IA <?= View::e($delta(isset($trends['ai_cost_delta_rate']) ? $trends['ai_cost_delta_rate'] : null)) ?></span>
            <span>Percentual que sobra <?= View::e($delta(isset($trends['margin_delta']) ? $trends['margin_delta'] : null)) ?></span>
        </div>
    </div>

    <div class="profitability-history-chart is-tenant">
        <?php foreach ($history as $row):
            $revenue = (float) ($row['revenue_brl'] ?? 0);
            $cost = (float) ($row['known_cost_brl'] ?? 0);
        ?>
        <div class="profitability-history-month">
            <div class="profitability-history-bars">
                <span class="is-revenue" style="--height:<?= View::e(number_format(max(3, ($revenue / $historyMax) * 100),2,'.','')) ?>%"></span>
                <span class="is-cost" style="--height:<?= View::e(number_format(max($cost > 0 ? 3 : 0, ($cost / $historyMax) * 100),2,'.','')) ?>%"></span>
            </div>
            <strong><?= View::e((string) ($row['label'] ?? '')) ?></strong>
            <small><?= View::e($percent(($row['margin_rate'] ?? null) !== null ? (float) $row['margin_rate'] : null)) ?></small>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="profitability-month-list">
        <?php foreach (array_reverse($history) as $row): ?>
        <article>
            <div><strong><?= View::e((string) ($row['label'] ?? '')) ?></strong><small><?= View::e($qualityLabel((string) ($row['revenue_quality'] ?? 'missing'))) ?> · <?= View::e((string) ($row['plan_name'] ?? 'Sem plano')) ?></small></div>
            <span>Receita<strong><?= View::e($brl((float) ($row['revenue_brl'] ?? 0))) ?></strong></span>
            <span>Custo da IA<strong><?= View::e($brl((float) ($row['ai_cost_brl'] ?? 0))) ?></strong></span>
            <span>Outros custos<strong><?= View::e($brl((float) ($row['other_cost_brl'] ?? 0))) ?></strong></span>
            <span>Valor que sobra<strong><?= View::e($brl((float) ($row['contribution_brl'] ?? 0))) ?></strong></span>
            <span>Percentual que sobra<strong><?= View::e($percent(($row['margin_rate'] ?? null) !== null ? (float) $row['margin_rate'] : null)) ?></strong></span>
            <span>Chamadas à IA<strong><?= number_format((int) ($row['provider_calls'] ?? 0),0,',','.') ?></strong></span>
        </article>
        <?php endforeach; ?>
    </div>
</section>

<section class="card profitability-simulator-panel">
    <div class="section-heading">
        <div><span class="eyebrow">Simulação</span><h2>Comparar planos e valores</h2><p>Veja quais planos comportam o uso atual e qual percentual sobraria. Nada é alterado automaticamente.</p></div>
        <span class="badge"><?= View::e($recommendationLabel((string) ($simulation['recommendation'] ?? 'configure'))) ?></span>
    </div>
    <div class="operations-alert is-info profitability-recommendation"><strong><?= View::e($recommendationLabel((string) ($simulation['recommendation'] ?? 'configure'))) ?></strong><p><?= View::e((string) ($simulation['recommendation_message'] ?? '')) ?></p></div>

    <div class="profitability-simulation-kpis">
        <div><span>Plano atual</span><strong><?= View::e((string) ($simulation['current_plan_name'] ?? 'Sem plano')) ?></strong></div>
        <div><span>Receita atual</span><strong><?= View::e($brl((float) ($simulation['current_revenue_brl'] ?? 0))) ?></strong></div>
        <div><span>Custos informados</span><strong><?= View::e($brl((float) ($simulation['known_cost_brl'] ?? 0))) ?></strong></div>
        <div><span>Valor mínimo para a meta</span><strong><?= View::e($brl((float) ($simulation['recommended_revenue_brl'] ?? 0))) ?></strong></div>
        <div><span>Quanto a IA pesa no valor mensal</span><strong><?= View::e($percent((float) ($simulation['ai_cost_share_rate'] ?? 0))) ?></strong></div>
        <div><span>Chamadas economizadas</span><strong><?= View::e($percent((float) ($simulation['avoidance_rate'] ?? 0))) ?></strong></div>
    </div>

    <div class="profitability-plan-grid">
        <?php foreach ($plans as $plan): ?>
        <article class="profitability-plan<?= !empty($plan['recommended']) ? ' is-recommended' : '' ?><?= (string) ($plan['plan_key'] ?? '') === (string) ($simulation['current_plan_key'] ?? '') ? ' is-current' : '' ?>">
            <div class="profitability-plan-head"><div><strong><?= View::e((string) ($plan['name'] ?? 'Plano')) ?></strong><small><?= (string) ($plan['plan_key'] ?? '') === (string) ($simulation['current_plan_key'] ?? '') ? 'Plano atual' : (!empty($plan['recommended']) ? 'Referência recomendada' : 'Cenário') ?></small></div><span><?= View::e($brl((float) ($plan['monthly_price_brl'] ?? 0))) ?>/mês</span></div>
            <div class="profitability-plan-metrics"><span>Percentual que sobra<strong><?= View::e($percent(($plan['projected_margin_rate'] ?? null) !== null ? (float) $plan['projected_margin_rate'] : null)) ?></strong></span><span>O plano comporta o uso?<strong><?= !empty($plan['capacity_ok']) ? 'Compatível' : 'Insuficiente' ?></strong></span></div>
            <?php if (!empty($plan['capacity_issues'])): ?><details><summary>Ver por que o plano não comporta o uso</summary><ul><?php foreach ($plan['capacity_issues'] as $issue): ?><li><?= View::e((string) $issue) ?></li><?php endforeach; ?></ul></details><?php endif; ?>
        </article>
        <?php endforeach; ?>
        <?php if (!$plans): ?><div class="empty-state">Nenhum plano ativo encontrado.</div><?php endif; ?>
    </div>

    <form class="profitability-custom-sim" method="get" action="<?= View::e(Router::url('/ai-profitability')) ?>">
        <input type="hidden" name="tenant_id" value="<?= $selectedTenant ?>"><input type="hidden" name="months" value="<?= $months ?>">
        <label><span>Simular outro valor mensal</span><input type="number" name="simulated_revenue_brl" min="0" step="0.01" value="<?= $simulatedRevenueBrl !== null ? View::e(number_format((float) $simulatedRevenueBrl,2,'.','')) : '' ?>" placeholder="Ex.: 249,00"></label>
        <button class="btn btn-outline" type="submit">Simular</button>
        <?php if (($simulation['custom_revenue_brl'] ?? null) !== null): ?><div class="profitability-custom-result"><span>Percentual que sobraria</span><strong><?= View::e($percent(($simulation['custom_margin_rate'] ?? null) !== null ? (float) $simulation['custom_margin_rate'] : null)) ?></strong><small><?= !empty($simulation['custom_meets_margin']) ? 'atinge a meta definida' : 'fica abaixo da meta definida' ?></small></div><?php endif; ?>
    </form>
</section>
<?php else: ?>
<section class="card profitability-select-note"><strong>Selecione uma empresa</strong><p>Abra um cliente para ver o histórico, entender as mudanças e comparar planos ou valores mensais.</p></section>
<?php endif; ?>

<section class="card profitability-method-note">
    <div><span class="eyebrow">Entenda os valores</span><h2>Como os números são calculados</h2></div>
    <div class="profitability-method-grid">
        <span><strong>Valor recebido</strong> usa primeiro as cobranças do mês; se não houver, usa o plano ou o valor informado.</span>
        <span><strong>Custo da IA</strong> soma o uso de IA registrado para cada empresa.</span>
        <span><strong>Histórico</strong> guarda um registro de cada mês para não alterar os resultados antigos.</span>
        <span><strong>Sugestão</strong> compara o valor recebido, os custos e os limites de cada plano.</span>
    </div>
    <p>O valor que sobra considera somente os custos cadastrados. Gastos que não foram informados não entram no cálculo.</p>
</section>
