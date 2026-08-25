<?php

use App\Core\Router;
use App\Core\View;

$openAiUsage = is_array($openAiUsage ?? null) ? $openAiUsage : [];
$official = is_array($openAiUsage['totals'] ?? null) ? $openAiUsage['totals'] : [];
$status = (string) ($openAiUsage['status'] ?? 'not_configured');
$period = (string) ($openAiUsage['period'] ?? 'month');
$models = is_array($openAiUsage['models'] ?? null) ? $openAiUsage['models'] : [];
$lineItems = is_array($openAiUsage['line_items'] ?? null) ? $openAiUsage['line_items'] : [];
$officialDaily = is_array($openAiUsage['daily'] ?? null) ? $openAiUsage['daily'] : [];
$insights = is_array($openAiUsage['insights'] ?? null) ? $openAiUsage['insights'] : [];
$aiEfficiency = is_array($aiEfficiency ?? null) ? $aiEfficiency : [];
$localTotals = is_array($aiEfficiency['totals'] ?? null) ? $aiEfficiency['totals'] : [];
$localDaily = is_array($aiEfficiency['daily'] ?? null) ? $aiEfficiency['daily'] : [];
$tenantRanking = is_array($aiEfficiency['tenants'] ?? null) ? $aiEfficiency['tenants'] : [];
$agentRanking = is_array($aiEfficiency['agents'] ?? null) ? $aiEfficiency['agents'] : [];
$memory = is_array($aiEfficiency['memory'] ?? null) ? $aiEfficiency['memory'] : [];
$unpricedModels = is_array($aiEfficiency['unpriced_models'] ?? null) ? $aiEfficiency['unpriced_models'] : [];
$pricingSnapshot = (string) ($aiEfficiency['pricing_snapshot'] ?? '');
$options = is_array($aiEfficiency['filter_options'] ?? null) ? $aiEfficiency['filter_options'] : [];
$filters = is_array($aiEfficiency['filters'] ?? null) ? $aiEfficiency['filters'] : [];
$selectedTenant = (int) ($filters['tenant_id'] ?? 0);
$selectedAgent = (int) ($filters['agent_id'] ?? 0);
$budgetOverview = is_array($aiBudgetOverview ?? null) ? $aiBudgetOverview : [];
$selectedBudgetPolicy = is_array($selectedAiBudgetPolicy ?? null) ? $selectedAiBudgetPolicy : [];
$selectedBudgetDecision = is_array($selectedAiBudgetDecision ?? null) ? $selectedAiBudgetDecision : [];
$commercialOverview = is_array($aiCommercialOverview ?? null) ? $aiCommercialOverview : [];
$selectedCommercialPolicy = is_array($selectedAiCommercialPolicy ?? null) ? $selectedAiCommercialPolicy : [];
$selectedCommercialAnalysis = is_array($selectedAiCommercialAnalysis ?? null) ? $selectedAiCommercialAnalysis : [];
$commercialStatusLabel = static fn (string $status): string => match ($status) {
    'healthy' => 'Saudável',
    'attention' => 'Atenção',
    'critical' => 'Margem baixa',
    'loss' => 'Prejuízo conhecido',
    default => 'Configurar',
};
$budgetActionLabel = static fn (string $action): string => match ($action) {
    'economy' => 'Forçar Econômico',
    'block_rs_ai' => 'Bloquear IA RS',
    'notify_only' => 'Somente alertar',
    default => 'Sem ação automática',
};

$compact = static function (int|float $value): string {
    $absolute = abs((float) $value);
    if ($absolute >= 1000000000) return number_format($value / 1000000000, 2, ',', '.') . ' bi';
    if ($absolute >= 1000000) return number_format($value / 1000000, 2, ',', '.') . ' mi';
    if ($absolute >= 1000) return number_format($value / 1000, 1, ',', '.') . ' mil';
    return number_format($value, 0, ',', '.');
};
$usd = static fn (float $value): string => 'US$ ' . number_format($value, 4, ',', '.');
$brl = static fn (float $value): string => 'R$ ' . number_format($value, 2, ',', '.');
$percent = static fn (float $value): string => number_format(max(0, $value) * 100, 1, ',', '.') . '%';
$fetchedAt = '';
if (!empty($openAiUsage['fetched_at'])) {
    try {
        $fetchedAt = (new DateTimeImmutable((string) $openAiUsage['fetched_at']))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('d/m/Y H:i');
    } catch (Throwable) {
        $fetchedAt = '';
    }
}
$officialMax = max(1, ...array_map(static fn (array $r): int => (int) ($r['total_tokens'] ?? 0), $officialDaily ?: [[]]));
$localMax = max(1, ...array_map(static fn (array $r): int => max((int) ($r['total_tokens'] ?? 0), (int) ($r['avoided_tokens'] ?? 0)), $localDaily ?: [[]]));
$queryBase = ['usage_period' => $period];
if ($selectedTenant > 0) $queryBase['tenant_id'] = $selectedTenant;
if ($selectedAgent > 0) $queryBase['agent_id'] = $selectedAgent;
$refreshUrl = Router::url('/openai-usage') . '?' . http_build_query($queryBase + ['refresh_usage' => 1]);
?>

<section class="ai-credentials-hero openai-usage-hero openai-usage-v2-hero">
    <div>
        <span class="eyebrow">Inteligência financeira da IA</span>
        <h2>OpenAI + eficiência do RS Connect</h2>
        <p>Compare o custo oficial da organização com a telemetria interna, acompanhe chamadas evitadas e identifique onde a IA está consumindo mais.</p>
    </div>
    <div class="openai-usage-hero-actions">
        <a class="btn btn-outline" href="<?= View::e(Router::url('/ai-credentials')) ?>">Credenciais</a>
        <a class="btn btn-primary" href="<?= View::e($refreshUrl) ?>">Atualizar agora</a>
    </div>
</section>

<section class="card openai-command-bar">
    <div class="openai-period-filter" aria-label="Período">
        <?php foreach (['7d' => '7 dias', '30d' => '30 dias', 'month' => 'Mês atual'] as $key => $label): ?>
            <?php $url = Router::url('/openai-usage') . '?' . http_build_query(array_filter(['usage_period' => $key, 'tenant_id' => $selectedTenant ?: null, 'agent_id' => $selectedAgent ?: null])); ?>
            <a class="<?= $period === $key ? 'is-active' : '' ?>" href="<?= View::e($url) ?>"><?= View::e($label) ?></a>
        <?php endforeach; ?>
    </div>
    <form class="openai-efficiency-filter" method="get" action="<?= View::e(Router::url('/openai-usage')) ?>">
        <input type="hidden" name="usage_period" value="<?= View::e($period) ?>">
        <label><span>Empresa</span><select name="tenant_id"><option value="0">Todas</option><?php foreach (($options['tenants'] ?? []) as $tenant): ?><option value="<?= (int) $tenant['id'] ?>" <?= (int) $tenant['id'] === $selectedTenant ? 'selected' : '' ?>><?= View::e((string) $tenant['name']) ?></option><?php endforeach; ?></select></label>
        <label><span>Assistente</span><select name="agent_id"><option value="0">Todos</option><?php foreach (($options['agents'] ?? []) as $agent): ?><option value="<?= (int) $agent['id'] ?>" <?= (int) $agent['id'] === $selectedAgent ? 'selected' : '' ?>><?= View::e((string) $agent['tenant_name'] . ' · ' . (string) $agent['name']) ?></option><?php endforeach; ?></select></label>
        <button class="btn btn-outline btn-small" type="submit">Aplicar</button>
    </form>
</section>

<?php if ($status === 'not_configured'): ?>
    <section class="card openai-usage-setup">
        <div><strong>Conecte a Usage API administrativa</strong><p>O painel oficial exige uma Admin API Key da organização. A telemetria interna do RS Connect continua funcionando mesmo sem essa chave.</p></div>
        <code>OPENAI_ADMIN_API_KEY=sk-admin-...</code>
        <small>Opcional: <code>OPENAI_USAGE_PROJECT_IDS=proj_...</code> · orçamento: <code>OPENAI_MONTHLY_BUDGET_USD=100</code> · câmbio de referência: <code>OPENAI_USAGE_USD_BRL=5.50</code></small>
    </section>
<?php elseif ($status === 'error'): ?>
    <div class="operations-alert is-danger openai-usage-alert"><strong>Não foi possível atualizar os dados oficiais</strong><p><?= View::e((string) ($openAiUsage['error'] ?? 'Revise a Admin API Key.')) ?></p></div>
<?php elseif ($status === 'stale'): ?>
    <div class="operations-alert is-warning openai-usage-alert"><strong>Exibindo o último cache válido</strong><p><?= View::e((string) ($openAiUsage['error'] ?? 'A atualização em tempo real falhou.')) ?></p></div>
<?php endif; ?>

<section class="openai-executive-grid" aria-label="Resumo executivo">
    <article class="openai-executive-card is-dark"><span>Custo oficial</span><strong><?= View::e($usd((float) ($official['cost'] ?? 0))) ?></strong><small><?= !empty($insights['cost_brl']) ? View::e($brl((float) $insights['cost_brl']) . ' pela cotação configurada') : 'OpenAI Usage API' ?></small></article>
    <article class="openai-executive-card is-blue"><span>Projeção do mês</span><strong><?= View::e($usd((float) ($insights['projected_cost_usd'] ?? $official['cost'] ?? 0))) ?></strong><small><?= !empty($insights['projected_cost_brl']) ? View::e($brl((float) $insights['projected_cost_brl'])) : 'baseada no ritmo atual' ?></small></article>
    <article class="openai-executive-card is-purple"><span>Orçamento utilizado</span><strong><?= (float) ($insights['monthly_budget_usd'] ?? 0) > 0 ? View::e($percent((float) ($insights['budget_used_rate'] ?? 0))) : '—' ?></strong><small><?= (float) ($insights['monthly_budget_usd'] ?? 0) > 0 ? View::e('de ' . $usd((float) $insights['monthly_budget_usd'])) : 'configure OPENAI_MONTHLY_BUDGET_USD' ?></small></article>
    <article class="openai-executive-card is-success"><span>Chamadas evitadas</span><strong><?= View::e($compact((int) ($localTotals['provider_calls_avoided'] ?? 0))) ?></strong><small><?= View::e($percent((float) ($localTotals['avoidance_rate'] ?? 0))) ?> das oportunidades medidas</small></article>
    <article class="openai-executive-card is-teal"><span>Tokens evitados</span><strong><?= View::e($compact((int) ($localTotals['input_tokens_avoided'] ?? 0))) ?></strong><small>contexto e base que deixaram de ser enviados</small></article>
    <article class="openai-executive-card is-orange"><span>Cobertura da telemetria</span><strong><?= View::e($percent((float) ($insights['tracking_coverage_rate'] ?? 0))) ?></strong><small><?= View::e($compact((int) ($insights['untracked_tokens'] ?? 0))) ?> tokens oficiais fora da atribuição interna</small></article>
</section>

<section class="openai-official-metrics" aria-label="Métricas oficiais da OpenAI">
    <article><span>Tokens totais</span><strong><?= View::e($compact((int) ($official['total_tokens'] ?? 0))) ?></strong><small>entrada + saída</small></article>
    <article><span>Tokens de entrada</span><strong><?= View::e($compact((int) ($official['input_tokens'] ?? 0))) ?></strong><small>prompts e contexto</small></article>
    <article><span>Tokens de saída</span><strong><?= View::e($compact((int) ($official['output_tokens'] ?? 0))) ?></strong><small>respostas geradas</small></article>
    <article><span>Tokens em cache</span><strong><?= View::e($compact((int) ($official['cached_tokens'] ?? 0))) ?></strong><small>entrada reaproveitada</small></article>
    <article><span>Chamadas oficiais</span><strong><?= View::e($compact((int) ($official['requests'] ?? 0))) ?></strong><small>requisições processadas</small></article>
</section>

<?php if ((float) ($insights['monthly_budget_usd'] ?? 0) > 0 && ($insights['alert_level'] ?? 'none') !== 'ok'): ?>
    <div class="operations-alert <?= ($insights['alert_level'] ?? '') === 'critical' ? 'is-danger' : 'is-warning' ?> openai-budget-alert">
        <strong><?= ($insights['alert_level'] ?? '') === 'critical' ? 'Orçamento mensal atingido' : 'Consumo se aproximando do orçamento' ?></strong>
        <p>O consumo atual está em <?= View::e($percent((float) ($insights['budget_used_rate'] ?? 0))) ?> e a projeção é <?= View::e($percent((float) ($insights['projected_budget_rate'] ?? 0))) ?> do orçamento configurado.</p>
    </div>
<?php endif; ?>

<?php if (!empty($insights['token_spike']) || (float) ($insights['agent_concentration_rate'] ?? 0) >= .60): ?>
    <div class="operations-alert is-warning openai-management-alert">
        <strong>Sinais para revisão</strong>
        <p>
            <?php if (!empty($insights['token_spike'])): ?>Foi identificado um pico diário de <?= View::e($compact((int) ($insights['peak_daily_tokens'] ?? 0))) ?> tokens, acima da média de <?= View::e($compact((int) ($insights['daily_token_average'] ?? 0))) ?>.<?php endif; ?>
            <?php if ((float) ($insights['agent_concentration_rate'] ?? 0) >= .60): ?> <?= View::e((string) ($insights['top_agent_name'] ?? 'Um assistente')) ?> concentra <?= View::e($percent((float) ($insights['agent_concentration_rate'] ?? 0))) ?> do custo interno estimado no filtro atual.<?php endif; ?>
        </p>
    </div>
<?php endif; ?>

<section class="openai-v2-grid">
    <article class="card openai-v2-panel openai-v2-chart-panel">
        <div class="section-heading"><div><span class="eyebrow">Dados oficiais</span><h2>Consumo diário da OpenAI</h2><p>Tokens processados diretamente na organização<?= $fetchedAt !== '' ? ' · atualizado em ' . View::e($fetchedAt) : '' ?>.</p></div><span class="badge"><?= View::e($compact((int) ($official['requests'] ?? 0))) ?> chamadas</span></div>
        <?php if ($officialDaily): ?><div class="openai-v2-chart" role="img" aria-label="Tokens oficiais por dia"><?php foreach ($officialDaily as $index => $day): $height = max(2, (int) round(((int) ($day['total_tokens'] ?? 0) / $officialMax) * 100)); ?><div class="openai-v2-day" title="<?= View::e((string) ($day['label'] ?? '')) ?> · <?= View::e(number_format((int) ($day['total_tokens'] ?? 0),0,',','.')) ?> tokens"><span style="--bar:<?= $height ?>%"></span><?php if ($index % max(1, (int) ceil(count($officialDaily)/8)) === 0 || $index === count($officialDaily)-1): ?><small><?= View::e((string) ($day['label'] ?? '')) ?></small><?php endif; ?></div><?php endforeach; ?></div><?php else: ?><div class="empty-state">Nenhum consumo oficial retornado.</div><?php endif; ?>
    </article>

    <article class="card openai-v2-panel">
        <div class="section-heading"><div><span class="eyebrow">Distribuição</span><h2>Modelos mais utilizados</h2></div></div>
        <div class="openai-usage-ranking"><?php foreach (array_slice($models,0,8) as $model): $share=(int)($official['total_tokens']??0)>0?(int)($model['total_tokens']??0)/(int)$official['total_tokens']:0; ?><div class="openai-usage-rank-row"><div><strong><?= View::e((string) ($model['model'] ?? 'Não identificado')) ?></strong><small><?= View::e(number_format((int)($model['requests']??0),0,',','.')) ?> chamada(s)</small></div><div class="openai-usage-rank-value"><strong><?= View::e($compact((int)($model['total_tokens']??0))) ?></strong><small><?= View::e($percent((float)$share)) ?></small></div></div><?php endforeach; ?><?php if (!$models): ?><div class="empty-state">Sem detalhamento por modelo.</div><?php endif; ?></div>
    </article>
</section>

<section class="card openai-efficiency-panel">
    <div class="section-heading"><div><span class="eyebrow">Telemetria RS Connect</span><h2>Eficiência real das automações</h2><p>Mostra o que foi enviado à IA e o que o RS Connect conseguiu resolver sem nova chamada ao provedor.</p></div><span class="badge"><?= View::e($compact((int) ($localTotals['conversations'] ?? 0))) ?> conversas medidas</span></div>
    <div class="openai-efficiency-kpis">
        <article><span>Tokens processados</span><strong><?= View::e($compact((int) ($localTotals['total_tokens'] ?? 0))) ?></strong><small>telemetria de todos os provedores</small></article>
        <article><span>Média por resposta IA</span><strong><?= View::e($compact((int) ($localTotals['avg_tokens_per_provider_reply'] ?? 0))) ?></strong><small>tokens por resposta gerada</small></article>
        <article><span>Respostas locais</span><strong><?= View::e($compact((int) ($localTotals['local_rule_replies'] ?? 0))) ?></strong><small>zero chamada ao modelo</small></article>
        <article><span>Respostas por cache</span><strong><?= View::e($compact((int) ($localTotals['exact_cache_replies'] ?? 0))) ?></strong><small>perguntas idênticas reaproveitadas</small></article>
        <article><span>Memórias atualizadas</span><strong><?= View::e($compact((int) ($memory['refreshes'] ?? $localTotals['memory_refreshes'] ?? 0))) ?></strong><small><?= View::e($compact((int) ($memory['rows'] ?? 0))) ?> conversa(s) · <?= View::e($compact((int) ($memory['contact_rows'] ?? 0))) ?> contato(s)</small></article>
        <article><span>Custo interno estimado</span><strong><?= View::e($usd((float) ($localTotals['estimated_cost'] ?? 0))) ?></strong><small><?= View::e($usd((float) ($localTotals['avg_cost_per_conversation'] ?? 0))) ?> por conversa</small></article>
    </div>
    <?php if ($localDaily): ?><div class="openai-efficiency-chart"><div class="openai-efficiency-legend"><span class="is-used">Tokens usados</span><span class="is-avoided">Tokens evitados</span></div><div class="openai-efficiency-bars"><?php foreach ($localDaily as $day): $used=max(2,(int)round(((int)($day['total_tokens']??0)/$localMax)*100)); $saved=max(2,(int)round(((int)($day['avoided_tokens']??0)/$localMax)*100)); ?><div class="openai-efficiency-day" title="<?= View::e((string)$day['day']) ?>"><div><span class="is-used" style="--bar:<?= $used ?>%"></span><span class="is-avoided" style="--bar:<?= $saved ?>%"></span></div><small><?= View::e(date('d/m',strtotime((string)$day['day']))) ?></small></div><?php endforeach; ?></div></div><?php endif; ?>
</section>

<section class="openai-v2-grid openai-ranking-grid">
    <article class="card openai-v2-panel"><div class="section-heading"><div><span class="eyebrow">Consumo por empresa</span><h2>Empresas que estão consumindo IA</h2><p>Valores financeiros são estimativas atribuídas pela telemetria do RS Connect; o custo oficial consolidado permanece no topo.</p></div></div><div class="openai-management-ranking"><?php foreach (array_slice($tenantRanking,0,10) as $row): ?><div><div><strong><?= View::e((string)$row['tenant_name']) ?></strong><small><?= View::e($compact((int)$row['conversations'])) ?> conversa(s) · <?= View::e($compact((int)$row['provider_calls'])) ?> chamada(s) · <?= View::e($compact((int)$row['avoided_calls'])) ?> evitada(s)</small></div><div><strong><?= (float)$row['estimated_cost'] > 0 ? View::e($usd((float)$row['estimated_cost'])) : '—' ?></strong><small><?= View::e($compact((int)$row['total_tokens'])) ?> tokens</small></div></div><?php endforeach; ?><?php if (!$tenantRanking): ?><div class="empty-state">Sem telemetria no período.</div><?php endif; ?></div></article>
    <article class="card openai-v2-panel"><div class="section-heading"><div><span class="eyebrow">Consumo por assistente</span><h2>Assistentes que mais consomem</h2><p>Ajuda a localizar prompts, modelos ou fluxos com maior impacto no custo.</p></div></div><div class="openai-management-ranking"><?php foreach (array_slice($agentRanking,0,10) as $row): ?><div><div><strong><?= View::e((string)$row['agent_name']) ?></strong><small><?= View::e((string)$row['tenant_name']) ?> · <?= View::e($compact((int)$row['provider_calls'])) ?> chamada(s) · <?= View::e($compact((int)$row['avoided_calls'])) ?> evitada(s)</small></div><div><strong><?= (float)$row['estimated_cost'] > 0 ? View::e($usd((float)$row['estimated_cost'])) : '—' ?></strong><small><?= View::e($compact((int)$row['total_tokens'])) ?> tokens</small></div></div><?php endforeach; ?><?php if (!$agentRanking): ?><div class="empty-state">Sem telemetria no período.</div><?php endif; ?></div></article>
</section>

<section class="card openai-budget-governance-panel">
    <div class="section-heading">
        <div><span class="eyebrow">Governança por empresa</span><h2>Orçamento e proteção de consumo</h2><p>Defina quanto a RS Connect pode custear por empresa e escolha o comportamento automático ao se aproximar ou atingir o limite.</p></div>
        <span class="badge"><?= View::e(number_format(count(array_filter($budgetOverview, static fn(array $row): bool => !empty($row['enabled']))),0,',','.')) ?> política(s) ativa(s)</span>
    </div>
    <div class="openai-budget-company-list">
        <?php foreach (array_slice($budgetOverview, 0, 30) as $budgetRow):
            $budgetEnabled = !empty($budgetRow['enabled']) && (float) ($budgetRow['budget_usd'] ?? 0) > 0;
            $budgetRate = max(0.0, (float) ($budgetRow['used_rate'] ?? 0));
            $budgetPercent = min(100, (int) round($budgetRate * 100));
            $budgetState = !empty($budgetRow['blocked']) ? 'is-danger' : (!empty($budgetRow['force_economy']) ? 'is-warning' : ($budgetRate >= .8 ? 'is-attention' : ''));
            $budgetUrl = Router::url('/openai-usage') . '?' . http_build_query(['usage_period' => 'month', 'tenant_id' => (int) ($budgetRow['tenant_id'] ?? 0)]);
        ?>
        <article class="openai-budget-company <?= View::e($budgetState) ?>">
            <div class="openai-budget-company-head"><div><strong><?= View::e((string) ($budgetRow['tenant_name'] ?? 'Empresa')) ?></strong><small><?= $budgetEnabled ? View::e($usd((float) ($budgetRow['used_usd'] ?? 0)) . ' de ' . $usd((float) ($budgetRow['budget_usd'] ?? 0))) : 'Sem orçamento configurado' ?></small></div><a href="<?= View::e($budgetUrl) ?>">Configurar</a></div>
            <div class="openai-budget-progress"><span style="--budget-progress:<?= $budgetEnabled ? $budgetPercent : 0 ?>%"></span></div>
            <div class="openai-budget-company-meta">
                <span><?= $budgetEnabled ? View::e(number_format($budgetRate * 100, 1, ',', '.') . '% utilizado') : 'Monitoramento apenas' ?></span>
                <span><?= View::e($compact((int) ($budgetRow['provider_calls'] ?? 0))) ?> chamada(s)</span>
                <span><?= $budgetEnabled ? View::e($budgetActionLabel((string) (($budgetRate * 100) >= (float) ($budgetRow['hard_limit_percent'] ?? 100) ? ($budgetRow['hard_limit_action'] ?? 'notify_only') : ($budgetRow['warning_action'] ?? 'none')))) : 'Sem automação' ?></span>
            </div>
        </article>
        <?php endforeach; ?>
        <?php if (!$budgetOverview): ?><div class="empty-state">A migration de governança ainda não foi aplicada ou não existem empresas ativas.</div><?php endif; ?>
    </div>

    <?php if ($selectedTenant > 0): ?>
    <form class="openai-budget-policy-form" method="post" action="<?= View::e(Router::url('/openai-usage/budget')) ?>">
        <?= \App\Core\Csrf::input() ?>
        <input type="hidden" name="tenant_id" value="<?= $selectedTenant ?>">
        <div class="openai-budget-policy-title">
            <div><span class="eyebrow">Política da empresa selecionada</span><h3>Limite financeiro da IA custeada pela RS</h3><p>O limite não interrompe atendimento humano, respostas locais, cache nem IA usada com credencial própria do cliente.</p></div>
            <label class="check-field"><input type="checkbox" name="enabled" value="1" <?= !empty($selectedBudgetPolicy['enabled']) ? 'checked' : '' ?>><span>Ativar orçamento</span></label>
        </div>
        <div class="openai-budget-policy-current">
            <div><span>Custo no ciclo</span><strong><?= View::e($usd((float) ($selectedBudgetDecision['used_usd'] ?? 0))) ?></strong></div>
            <div><span>Orçamento</span><strong><?= (float) ($selectedBudgetDecision['budget_usd'] ?? 0) > 0 ? View::e($usd((float) $selectedBudgetDecision['budget_usd'])) : '—' ?></strong></div>
            <div><span>Uso</span><strong><?= (float) ($selectedBudgetDecision['budget_usd'] ?? 0) > 0 ? View::e(number_format((float) ($selectedBudgetDecision['used_percent'] ?? 0), 1, ',', '.') . '%') : '—' ?></strong></div>
            <div><span>Ação efetiva</span><strong><?= View::e($budgetActionLabel((string) ($selectedBudgetDecision['action'] ?? 'none'))) ?></strong></div>
        </div>
        <div class="form-grid four openai-budget-fields">
            <label class="field"><span>Orçamento do ciclo (US$)</span><input type="number" name="monthly_budget_usd" min="0" step="0.0001" value="<?= View::e((string) ($selectedBudgetPolicy['monthly_budget_usd'] ?? '')) ?>"><small>Custo técnico estimado das chamadas com credencial RS.</small></label>
            <label class="field"><span>Atenção (%)</span><input type="number" name="warning_percent" min="10" max="99" value="<?= (int) ($selectedBudgetPolicy['warning_percent'] ?? 80) ?>"><small>Primeiro alerta financeiro.</small></label>
            <label class="field"><span>Crítico (%)</span><input type="number" name="critical_percent" min="11" max="100" value="<?= (int) ($selectedBudgetPolicy['critical_percent'] ?? 95) ?>"><small>Eleva a severidade do alerta.</small></label>
            <label class="field"><span>Limite (%)</span><input type="number" name="hard_limit_percent" min="11" max="150" value="<?= (int) ($selectedBudgetPolicy['hard_limit_percent'] ?? 100) ?>"><small>Ponto da ação final.</small></label>
        </div>
        <div class="form-grid two openai-budget-fields">
            <label class="field"><span>Ao atingir atenção</span><select name="warning_action"><option value="none" <?= ($selectedBudgetPolicy['warning_action'] ?? 'none') === 'none' ? 'selected' : '' ?>>Somente alertar</option><option value="economy" <?= ($selectedBudgetPolicy['warning_action'] ?? '') === 'economy' ? 'selected' : '' ?>>Forçar modo Econômico</option></select><small>O modo Econômico reduz contexto e saída sem trocar o atendimento humano.</small></label>
            <label class="field"><span>Ao atingir o limite</span><select name="hard_limit_action"><option value="notify_only" <?= ($selectedBudgetPolicy['hard_limit_action'] ?? 'notify_only') === 'notify_only' ? 'selected' : '' ?>>Somente alertar</option><option value="economy" <?= ($selectedBudgetPolicy['hard_limit_action'] ?? '') === 'economy' ? 'selected' : '' ?>>Manter IA em modo Econômico</option><option value="block_rs_ai" <?= ($selectedBudgetPolicy['hard_limit_action'] ?? '') === 'block_rs_ai' ? 'selected' : '' ?>>Bloquear novas chamadas custeadas pela RS</option></select><small>Credenciais próprias do cliente continuam disponíveis mesmo no bloqueio.</small></label>
        </div>
        <div class="openai-budget-savebar"><p>Recomendação inicial: <strong>80% → Econômico</strong> e <strong>100% → bloquear IA RS</strong> somente após homologar os valores de custo.</p><button class="btn btn-primary" type="submit">Salvar política</button></div>
    </form>
    <?php else: ?>
    <div class="openai-budget-select-note">Selecione uma empresa no filtro superior para editar orçamento, limites e ações automáticas.</div>
    <?php endif; ?>
</section>

<section class="card openai-commercial-margin-panel">
    <div class="section-heading">
        <div><span class="eyebrow">Gestão comercial da IA</span><h2>Margem, custo e preço de referência</h2><p>Compara a receita contratada com o custo projetado da IA custeada pela RS e outros custos informados. A margem abaixo é de contribuição conhecida, não lucro líquido contábil.</p></div>
        <span class="badge"><?= View::e(number_format(count(array_filter($commercialOverview, static fn(array $row): bool => !empty($row['configured']))),0,',','.')) ?> empresa(s) configurada(s)</span>
    </div>

    <div class="openai-commercial-summary">
        <?php foreach (array_slice($commercialOverview, 0, 30) as $commercialRow):
            $statusKey = (string) ($commercialRow['status'] ?? 'unconfigured');
            $margin = $commercialRow['projected_margin_rate'] ?? null;
            $commercialUrl = Router::url('/openai-usage') . '?' . http_build_query(['usage_period' => 'month', 'tenant_id' => (int) ($commercialRow['tenant_id'] ?? 0)]);
        ?>
        <article class="openai-commercial-company is-<?= View::e($statusKey) ?>">
            <div class="openai-commercial-company-head">
                <div><strong><?= View::e((string) ($commercialRow['tenant_name'] ?? 'Empresa')) ?></strong><small><?= View::e((string) (($commercialRow['subscription']['plan_name'] ?? 'Sem plano'))) ?> · <?= View::e($commercialStatusLabel($statusKey)) ?></small></div>
                <a href="<?= View::e($commercialUrl) ?>">Analisar</a>
            </div>
            <div class="openai-commercial-kpis">
                <span>Receita ref.<strong><?= View::e($brl((float) ($commercialRow['revenue_brl'] ?? 0))) ?></strong></span>
                <span>IA projetada<strong><?= (float) ($commercialRow['usd_brl_rate'] ?? 0) > 0 ? View::e($brl((float) ($commercialRow['projected_ai_cost_brl'] ?? 0))) : '—' ?></strong></span>
                <span>Margem conhecida<strong><?= $margin !== null ? View::e($percent((float) $margin)) : '—' ?></strong></span>
            </div>
            <div class="openai-commercial-company-foot">
                <?php if ((float) ($commercialRow['price_gap_brl'] ?? 0) > .009): ?>Preço de referência abaixo do alvo em <strong><?= View::e($brl((float) $commercialRow['price_gap_brl'])) ?></strong>.<?php elseif ($statusKey === 'healthy'): ?>Margem projetada acima do alvo configurado.<?php elseif (empty($commercialRow['configured'])): ?>Defina receita e cotação USD/BRL para calcular a margem.<?php else: ?>Margem abaixo do alvo; revise custo ou condição comercial.<?php endif; ?>
            </div>
        </article>
        <?php endforeach; ?>
        <?php if (!$commercialOverview): ?><div class="empty-state">A migration de gestão comercial ainda não foi aplicada ou não existem empresas ativas.</div><?php endif; ?>
    </div>

    <?php if ($selectedTenant > 0): ?>
    <form class="openai-commercial-policy-form" method="post" action="<?= View::e(Router::url('/openai-usage/commercial')) ?>">
        <?= \App\Core\Csrf::input() ?>
        <input type="hidden" name="tenant_id" value="<?= $selectedTenant ?>">
        <div class="openai-commercial-policy-title">
            <div><span class="eyebrow">Empresa selecionada</span><h3>Política comercial da franquia de IA</h3><p>Use a assinatura atual como receita de referência ou informe manualmente quanto da mensalidade deve sustentar esta operação.</p></div>
            <label class="check-field"><input type="checkbox" name="enabled" value="1" <?= !isset($selectedCommercialPolicy['enabled']) || !empty($selectedCommercialPolicy['enabled']) ? 'checked' : '' ?>><span>Ativar análise</span></label>
        </div>

        <div class="openai-commercial-current">
            <div><span>Receita de referência</span><strong><?= View::e($brl((float) ($selectedCommercialAnalysis['revenue_brl'] ?? 0))) ?></strong><small><?= ($selectedCommercialAnalysis['revenue_source'] ?? 'subscription') === 'manual' ? 'valor manual' : 'assinatura mensal equivalente' ?></small></div>
            <div><span>IA atual</span><strong><?= (float) ($selectedCommercialAnalysis['usd_brl_rate'] ?? 0) > 0 ? View::e($brl((float) ($selectedCommercialAnalysis['current_ai_cost_brl'] ?? 0))) : '—' ?></strong><small><?= View::e($usd((float) ($selectedCommercialAnalysis['current_ai_cost_usd'] ?? 0))) ?></small></div>
            <div><span>IA projetada</span><strong><?= (float) ($selectedCommercialAnalysis['usd_brl_rate'] ?? 0) > 0 ? View::e($brl((float) ($selectedCommercialAnalysis['projected_ai_cost_brl'] ?? 0))) : '—' ?></strong><small>ritmo do mês atual</small></div>
            <div><span>Margem conhecida</span><strong><?= ($selectedCommercialAnalysis['projected_margin_rate'] ?? null) !== null ? View::e($percent((float) $selectedCommercialAnalysis['projected_margin_rate'])) : '—' ?></strong><small>alvo <?= View::e($percent((float) ($selectedCommercialAnalysis['target_margin_rate'] ?? .60))) ?></small></div>
            <div><span>Contribuição projetada</span><strong><?= View::e($brl((float) ($selectedCommercialAnalysis['projected_contribution_brl'] ?? 0))) ?></strong><small>antes de custos não informados</small></div>
            <div><span>Receita mínima p/ alvo</span><strong><?= View::e($brl((float) ($selectedCommercialAnalysis['recommended_revenue_brl'] ?? 0))) ?></strong><small><?= (float) ($selectedCommercialAnalysis['price_gap_brl'] ?? 0) > 0 ? View::e('ajuste sugerido +' . $brl((float) $selectedCommercialAnalysis['price_gap_brl'])) : 'preço atual cobre o alvo conhecido' ?></small></div>
        </div>

        <div class="form-grid three openai-commercial-fields">
            <label class="field"><span>Origem da receita</span><select name="revenue_source"><option value="subscription" <?= ($selectedCommercialPolicy['revenue_source'] ?? 'subscription') === 'subscription' ? 'selected' : '' ?>>Assinatura atual</option><option value="manual" <?= ($selectedCommercialPolicy['revenue_source'] ?? '') === 'manual' ? 'selected' : '' ?>>Valor manual de referência</option></select><small>Assinatura converte ciclos trimestral/semestral/anual para equivalente mensal.</small></label>
            <label class="field"><span>Receita manual (R$/mês)</span><input type="number" name="monthly_revenue_brl" min="0" step="0.01" value="<?= View::e((string) ($selectedCommercialPolicy['monthly_revenue_brl'] ?? '')) ?>"><small>Usado somente quando a origem manual estiver selecionada.</small></label>
            <label class="field"><span>Outros custos mensais (R$)</span><input type="number" name="other_monthly_cost_brl" min="0" step="0.01" value="<?= View::e((string) ($selectedCommercialPolicy['other_monthly_cost_brl'] ?? '0')) ?>"><small>Infra, suporte, WhatsApp, automações ou custos atribuídos ao cliente.</small></label>
        </div>
        <div class="form-grid three openai-commercial-fields">
            <label class="field"><span>Margem alvo (%)</span><input type="number" name="target_margin_percent" min="5" max="95" step="0.1" value="<?= View::e((string) ($selectedCommercialPolicy['target_margin_percent'] ?? '60')) ?>"><small>Usada para calcular a receita mínima de referência.</small></label>
            <label class="field"><span>Margem de atenção (%)</span><input type="number" name="warning_margin_percent" min="-100" max="94" step="0.1" value="<?= View::e((string) ($selectedCommercialPolicy['warning_margin_percent'] ?? '40')) ?>"><small>Abaixo deste ponto a empresa aparece como margem baixa.</small></label>
            <label class="field"><span>Cotação USD → BRL</span><input type="number" name="usd_brl_rate" min="0" step="0.0001" value="<?= View::e((string) ($selectedCommercialPolicy['usd_brl_rate'] ?? '')) ?>"><small>Opcional. Vazio herda <code>OPENAI_USAGE_USD_BRL</code>. Atual: <?= (float) ($selectedCommercialAnalysis['usd_brl_rate'] ?? 0) > 0 ? View::e(number_format((float) $selectedCommercialAnalysis['usd_brl_rate'],4,',','.')) : 'não configurada' ?>.</small></label>
        </div>
        <div class="openai-commercial-savebar"><p><strong>Importante:</strong> esta é uma margem de contribuição conhecida. Para aproximar a rentabilidade real, informe os demais custos mensais atribuíveis ao cliente.</p><button class="btn btn-primary" type="submit">Salvar política comercial</button></div>
    </form>
    <?php else: ?>
    <div class="openai-budget-select-note">Selecione uma empresa no filtro superior para analisar receita, custo projetado, margem e preço de referência.</div>
    <?php endif; ?>
</section>

<section class="card openai-cost-attribution-note">
    <div><span class="eyebrow">Atribuição financeira</span><h2>Como o custo por empresa é calculado</h2><p>O endpoint oficial da OpenAI conhece organização, projeto e modelo, mas não conhece o <code>tenant_id</code> do RS Connect. Por isso, empresa e assistente são atribuídos pela telemetria interna, usando tokens de entrada, cache e saída de cada chamada.</p></div>
    <div class="openai-cost-attribution-metrics"><span>Cobertura de tarifação <strong><?= View::e($percent((float)($localTotals['cost_pricing_coverage_rate'] ?? 0))) ?></strong></span><span>Tarifas padrão <strong><?= $pricingSnapshot !== '' ? View::e($pricingSnapshot) : '—' ?></strong></span></div>
    <?php if ($unpricedModels): ?><details><summary>Modelos sem tarifa automática (<?= count($unpricedModels) ?>)</summary><div class="openai-cost-list"><?php foreach ($unpricedModels as $model): ?><div><span><?= View::e((string)($model['provider'] ?? '')) ?> · <?= View::e((string)($model['model'] ?? '')) ?></span><strong><?= View::e($compact((int)($model['total_tokens'] ?? 0))) ?> tokens</strong></div><?php endforeach; ?></div><p class="openai-usage-footnote">Para esses modelos, configure uma tarifa explícita em <code>AI_COST_RATES_JSON</code>.</p></details><?php endif; ?>
</section>
<section class="card openai-governance-panel">
    <div class="section-heading"><div><span class="eyebrow">Governança</span><h2>Oficial x RS Connect</h2><p>Diferenças podem indicar chamadas realizadas fora do RS Connect, outras chaves/projetos ou eventos ainda não atribuídos pela telemetria local.</p></div></div>
    <div class="openai-governance-grid">
        <div><span>Tokens oficiais OpenAI</span><strong><?= View::e($compact((int) ($insights['official_openai_tokens'] ?? 0))) ?></strong></div>
        <div><span>Tokens OpenAI identificados internamente</span><strong><?= View::e($compact((int) ($insights['internal_openai_tokens'] ?? 0))) ?></strong></div>
        <div><span>Diferença não atribuída</span><strong><?= View::e($compact((int) ($insights['untracked_tokens'] ?? 0))) ?></strong></div>
        <div><span>Cobertura</span><strong><?= View::e($percent((float) ($insights['tracking_coverage_rate'] ?? 0))) ?></strong></div>
    </div>
    <?php if ($lineItems): ?><details class="openai-cost-details"><summary>Ver composição do custo oficial</summary><div class="openai-cost-list"><?php foreach ($lineItems as $item): ?><div><span><?= View::e((string)($item['line_item']??'Outros serviços')) ?></span><strong><?= View::e($usd((float)($item['cost']??0))) ?></strong></div><?php endforeach; ?></div></details><?php endif; ?>
</section>
