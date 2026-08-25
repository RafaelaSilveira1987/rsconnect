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
        <span class="eyebrow">Uso e custo da IA</span>
        <h2>Quanto a inteligência artificial está sendo usada</h2>
        <p>Veja o valor cobrado pela OpenAI, quanto cada empresa utiliza e quanto o RS Connect conseguiu economizar.</p>
    </div>
    <div class="openai-usage-hero-actions">
        <a class="btn btn-outline" href="<?= View::e(Router::url('/ai-credentials')) ?>">Chaves de acesso</a>
        <a class="btn btn-outline" href="<?= View::e(Router::url('/ai-profitability')) ?>">Resultados por cliente</a>
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
        <div><strong>Conecte a conta administrativa da OpenAI</strong><p>Para consultar o valor oficial, informe uma chave administrativa da OpenAI no servidor. Os dados registrados pelo RS Connect continuam disponíveis mesmo sem essa chave.</p></div>
        <code>OPENAI_ADMIN_API_KEY=sk-admin-...</code>
        <small>Opcional: <code>OPENAI_USAGE_PROJECT_IDS=proj_...</code> · orçamento: <code>OPENAI_MONTHLY_BUDGET_USD=100</code> · câmbio de referência: <code>OPENAI_USAGE_USD_BRL=5.50</code></small>
    </section>
<?php elseif ($status === 'error'): ?>
    <div class="operations-alert is-danger openai-usage-alert"><strong>Não foi possível atualizar os dados oficiais</strong><p><?= View::e((string) ($openAiUsage['error'] ?? 'Revise a chave administrativa da OpenAI configurada no servidor.')) ?></p></div>
<?php elseif ($status === 'stale'): ?>
    <div class="operations-alert is-warning openai-usage-alert"><strong>Mostrando os últimos dados disponíveis</strong><p><?= View::e((string) ($openAiUsage['error'] ?? 'A atualização em tempo real falhou.')) ?></p></div>
<?php endif; ?>

<section class="openai-executive-grid" aria-label="Resumo executivo">
    <article class="openai-executive-card is-dark"><span>Custo oficial</span><strong><?= View::e($usd((float) ($official['cost'] ?? 0))) ?></strong><small><?= !empty($insights['cost_brl']) ? View::e($brl((float) $insights['cost_brl']) . ' pela cotação configurada') : 'dados oficiais da OpenAI' ?></small></article>
    <article class="openai-executive-card is-blue"><span>Projeção do mês</span><strong><?= View::e($usd((float) ($insights['projected_cost_usd'] ?? $official['cost'] ?? 0))) ?></strong><small><?= !empty($insights['projected_cost_brl']) ? View::e($brl((float) $insights['projected_cost_brl'])) : 'baseada no ritmo atual' ?></small></article>
    <article class="openai-executive-card is-purple"><span>Orçamento utilizado</span><strong><?= (float) ($insights['monthly_budget_usd'] ?? 0) > 0 ? View::e($percent((float) ($insights['budget_used_rate'] ?? 0))) : '—' ?></strong><small><?= (float) ($insights['monthly_budget_usd'] ?? 0) > 0 ? View::e('de ' . $usd((float) $insights['monthly_budget_usd'])) : 'defina um orçamento mensal nas configurações do servidor' ?></small></article>
    <article class="openai-executive-card is-success"><span>Respostas sem nova chamada</span><strong><?= View::e($compact((int) ($localTotals['provider_calls_avoided'] ?? 0))) ?></strong><small><?= View::e($percent((float) ($localTotals['avoidance_rate'] ?? 0))) ?> das oportunidades medidas</small></article>
    <article class="openai-executive-card is-teal"><span>Uso economizado</span><strong><?= View::e($compact((int) ($localTotals['input_tokens_avoided'] ?? 0))) ?></strong><small>informações que não precisaram ser enviadas novamente</small></article>
    <article class="openai-executive-card is-orange"><span>Uso identificado por empresa</span><strong><?= View::e($percent((float) ($insights['tracking_coverage_rate'] ?? 0))) ?></strong><small><?= View::e($compact((int) ($insights['untracked_tokens'] ?? 0))) ?> unidades de uso ainda sem empresa identificada</small></article>
</section>

<section class="openai-official-metrics" aria-label="Resumo do uso oficial da OpenAI">
    <article><span>Uso total da IA</span><strong><?= View::e($compact((int) ($official['total_tokens'] ?? 0))) ?></strong><small>informações recebidas + respostas geradas</small></article>
    <article><span>Informações processadas</span><strong><?= View::e($compact((int) ($official['input_tokens'] ?? 0))) ?></strong><small>instruções e conteúdo enviados</small></article>
    <article><span>Respostas geradas</span><strong><?= View::e($compact((int) ($official['output_tokens'] ?? 0))) ?></strong><small>respostas geradas</small></article>
    <article><span>Informações reaproveitadas</span><strong><?= View::e($compact((int) ($official['cached_tokens'] ?? 0))) ?></strong><small>informações que não precisaram ser processadas novamente</small></article>
    <article><span>Chamadas à OpenAI</span><strong><?= View::e($compact((int) ($official['requests'] ?? 0))) ?></strong><small>pedidos enviados para a IA</small></article>
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
            <?php if (!empty($insights['token_spike'])): ?>Foi identificado um aumento de uso em um dia: <?= View::e($compact((int) ($insights['peak_daily_tokens'] ?? 0))) ?> unidades, acima da média de <?= View::e($compact((int) ($insights['daily_token_average'] ?? 0))) ?>.<?php endif; ?>
            <?php if ((float) ($insights['agent_concentration_rate'] ?? 0) >= .60): ?> <?= View::e((string) ($insights['top_agent_name'] ?? 'Um assistente')) ?> concentra <?= View::e($percent((float) ($insights['agent_concentration_rate'] ?? 0))) ?> do custo interno estimado no filtro atual.<?php endif; ?>
        </p>
    </div>
<?php endif; ?>

<section class="openai-v2-grid">
    <article class="card openai-v2-panel openai-v2-chart-panel">
        <div class="section-heading"><div><span class="eyebrow">Dados oficiais</span><h2>Uso diário da OpenAI</h2><p>Uso processado diretamente na conta<?= $fetchedAt !== '' ? ' · atualizado em ' . View::e($fetchedAt) : '' ?>.</p></div><span class="badge"><?= View::e($compact((int) ($official['requests'] ?? 0))) ?> chamadas</span></div>
        <?php if ($officialDaily): ?><div class="openai-v2-chart" role="img" aria-label="Uso oficial da OpenAI por dia"><?php foreach ($officialDaily as $index => $day): $height = max(2, (int) round(((int) ($day['total_tokens'] ?? 0) / $officialMax) * 100)); ?><div class="openai-v2-day" title="<?= View::e((string) ($day['label'] ?? '')) ?> · <?= View::e(number_format((int) ($day['total_tokens'] ?? 0),0,',','.')) ?> unidades de uso"><span style="--bar:<?= $height ?>%"></span><?php if ($index % max(1, (int) ceil(count($officialDaily)/8)) === 0 || $index === count($officialDaily)-1): ?><small><?= View::e((string) ($day['label'] ?? '')) ?></small><?php endif; ?></div><?php endforeach; ?></div><?php else: ?><div class="empty-state">Nenhum consumo oficial retornado.</div><?php endif; ?>
    </article>

    <article class="card openai-v2-panel">
        <div class="section-heading"><div><span class="eyebrow">Distribuição</span><h2>Modelos de IA mais utilizados</h2></div></div>
        <div class="openai-usage-ranking"><?php foreach (array_slice($models,0,8) as $model): $share=(int)($official['total_tokens']??0)>0?(int)($model['total_tokens']??0)/(int)$official['total_tokens']:0; ?><div class="openai-usage-rank-row"><div><strong><?= View::e((string) ($model['model'] ?? 'Não identificado')) ?></strong><small><?= View::e(number_format((int)($model['requests']??0),0,',','.')) ?> chamada(s)</small></div><div class="openai-usage-rank-value"><strong><?= View::e($compact((int)($model['total_tokens']??0))) ?></strong><small><?= View::e($percent((float)$share)) ?></small></div></div><?php endforeach; ?><?php if (!$models): ?><div class="empty-state">Sem detalhamento por modelo.</div><?php endif; ?></div>
    </article>
</section>

<section class="card openai-efficiency-panel">
    <div class="section-heading"><div><span class="eyebrow">Dados registrados pelo RS Connect</span><h2>Quanto o sistema economizou</h2><p>Mostra o uso da IA e o que foi respondido sem precisar pagar por uma nova chamada.</p></div><span class="badge"><?= View::e($compact((int) ($localTotals['conversations'] ?? 0))) ?> conversas medidas</span></div>
    <div class="openai-efficiency-kpis">
        <article><span>Uso processado</span><strong><?= View::e($compact((int) ($localTotals['total_tokens'] ?? 0))) ?></strong><small>dados de todos os serviços de IA</small></article>
        <article><span>Uso médio por resposta</span><strong><?= View::e($compact((int) ($localTotals['avg_tokens_per_provider_reply'] ?? 0))) ?></strong><small>média das unidades usadas em cada resposta</small></article>
        <article><span>Respostas prontas do sistema</span><strong><?= View::e($compact((int) ($localTotals['local_rule_replies'] ?? 0))) ?></strong><small>sem chamar um serviço de IA</small></article>
        <article><span>Respostas reaproveitadas</span><strong><?= View::e($compact((int) ($localTotals['exact_cache_replies'] ?? 0))) ?></strong><small>perguntas iguais respondidas novamente sem custo</small></article>
        <article><span>Conversas resumidas</span><strong><?= View::e($compact((int) ($memory['refreshes'] ?? $localTotals['memory_refreshes'] ?? 0))) ?></strong><small><?= View::e($compact((int) ($memory['rows'] ?? 0))) ?> conversa(s) · <?= View::e($compact((int) ($memory['contact_rows'] ?? 0))) ?> contato(s)</small></article>
        <article><span>Custo estimado no RS Connect</span><strong><?= View::e($usd((float) ($localTotals['estimated_cost'] ?? 0))) ?></strong><small><?= View::e($usd((float) ($localTotals['avg_cost_per_conversation'] ?? 0))) ?> por conversa</small></article>
    </div>
    <?php if ($localDaily): ?><div class="openai-efficiency-chart"><div class="openai-efficiency-legend"><span class="is-used">Uso realizado</span><span class="is-avoided">Uso economizado</span></div><div class="openai-efficiency-bars"><?php foreach ($localDaily as $day): $used=max(2,(int)round(((int)($day['total_tokens']??0)/$localMax)*100)); $saved=max(2,(int)round(((int)($day['avoided_tokens']??0)/$localMax)*100)); ?><div class="openai-efficiency-day" title="<?= View::e((string)$day['day']) ?>"><div><span class="is-used" style="--bar:<?= $used ?>%"></span><span class="is-avoided" style="--bar:<?= $saved ?>%"></span></div><small><?= View::e(date('d/m',strtotime((string)$day['day']))) ?></small></div><?php endforeach; ?></div></div><?php endif; ?>
</section>

<section class="openai-v2-grid openai-ranking-grid">
    <article class="card openai-v2-panel"><div class="section-heading"><div><span class="eyebrow">Uso por empresa</span><h2>Empresas com maior uso de IA</h2><p>Os valores são separados pelos dados registrados no RS Connect. O total oficial da OpenAI continua no início da página.</p></div></div><div class="openai-management-ranking"><?php foreach (array_slice($tenantRanking,0,10) as $row): ?><div><div><strong><?= View::e((string)$row['tenant_name']) ?></strong><small><?= View::e($compact((int)$row['conversations'])) ?> conversa(s) · <?= View::e($compact((int)$row['provider_calls'])) ?> chamada(s) · <?= View::e($compact((int)$row['avoided_calls'])) ?> evitada(s)</small></div><div><strong><?= (float)$row['estimated_cost'] > 0 ? View::e($usd((float)$row['estimated_cost'])) : '—' ?></strong><small><?= View::e($compact((int)$row['total_tokens'])) ?> unidades de uso</small></div></div><?php endforeach; ?><?php if (!$tenantRanking): ?><div class="empty-state">Ainda não há dados de uso neste período.</div><?php endif; ?></div></article>
    <article class="card openai-v2-panel"><div class="section-heading"><div><span class="eyebrow">Uso por assistente</span><h2>Assistentes com maior uso de IA</h2><p>Ajuda a encontrar instruções, modelos ou automações que podem ser simplificados.</p></div></div><div class="openai-management-ranking"><?php foreach (array_slice($agentRanking,0,10) as $row): ?><div><div><strong><?= View::e((string)$row['agent_name']) ?></strong><small><?= View::e((string)$row['tenant_name']) ?> · <?= View::e($compact((int)$row['provider_calls'])) ?> chamada(s) · <?= View::e($compact((int)$row['avoided_calls'])) ?> evitada(s)</small></div><div><strong><?= (float)$row['estimated_cost'] > 0 ? View::e($usd((float)$row['estimated_cost'])) : '—' ?></strong><small><?= View::e($compact((int)$row['total_tokens'])) ?> unidades de uso</small></div></div><?php endforeach; ?><?php if (!$agentRanking): ?><div class="empty-state">Ainda não há dados de uso neste período.</div><?php endif; ?></div></article>
</section>

<section class="card openai-budget-governance-panel">
    <div class="section-heading">
        <div><span class="eyebrow">Controle por empresa</span><h2>Limite e proteção de gasto</h2><p>Defina quanto a RS Connect pode pagar por cliente e o que deve acontecer quando o valor se aproximar do limite.</p></div>
        <span class="badge"><?= View::e(number_format(count(array_filter($budgetOverview, static fn(array $row): bool => !empty($row['enabled']))),0,',','.')) ?> configuração(ões) ativa(s)</span>
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
            <div class="openai-budget-company-head"><div><strong><?= View::e((string) ($budgetRow['tenant_name'] ?? 'Empresa')) ?></strong><small><?= $budgetEnabled ? View::e($usd((float) ($budgetRow['used_usd'] ?? 0)) . ' de ' . $usd((float) ($budgetRow['budget_usd'] ?? 0))) : 'Sem limite de gasto configurado' ?></small></div><a href="<?= View::e($budgetUrl) ?>">Configurar</a></div>
            <div class="openai-budget-progress"><span style="--budget-progress:<?= $budgetEnabled ? $budgetPercent : 0 ?>%"></span></div>
            <div class="openai-budget-company-meta">
                <span><?= $budgetEnabled ? View::e(number_format($budgetRate * 100, 1, ',', '.') . '% utilizado') : 'Apenas acompanhamento' ?></span>
                <span><?= View::e($compact((int) ($budgetRow['provider_calls'] ?? 0))) ?> chamada(s)</span>
                <span><?= $budgetEnabled ? View::e($budgetActionLabel((string) (($budgetRate * 100) >= (float) ($budgetRow['hard_limit_percent'] ?? 100) ? ($budgetRow['hard_limit_action'] ?? 'notify_only') : ($budgetRow['warning_action'] ?? 'none')))) : 'Sem ação automática' ?></span>
            </div>
        </article>
        <?php endforeach; ?>
        <?php if (!$budgetOverview): ?><div class="empty-state">A atualização do banco desta função ainda não foi concluída ou não existem empresas ativas.</div><?php endif; ?>
    </div>

    <?php if ($selectedTenant > 0): ?>
    <form class="openai-budget-policy-form" method="post" action="<?= View::e(Router::url('/openai-usage/budget')) ?>">
        <?= \App\Core\Csrf::input() ?>
        <input type="hidden" name="tenant_id" value="<?= $selectedTenant ?>">
        <div class="openai-budget-policy-title">
            <div><span class="eyebrow">Empresa selecionada</span><h3>Limite de gasto da IA paga pela RS</h3><p>Esse limite não interrompe o atendimento humano, as respostas prontas, as respostas reaproveitadas nem a IA paga pelo próprio cliente.</p></div>
            <label class="check-field"><input type="checkbox" name="enabled" value="1" <?= !empty($selectedBudgetPolicy['enabled']) ? 'checked' : '' ?>><span>Ativar limite de gasto</span></label>
        </div>
        <div class="openai-budget-policy-current">
            <div><span>Gasto no mês</span><strong><?= View::e($usd((float) ($selectedBudgetDecision['used_usd'] ?? 0))) ?></strong></div>
            <div><span>Orçamento</span><strong><?= (float) ($selectedBudgetDecision['budget_usd'] ?? 0) > 0 ? View::e($usd((float) $selectedBudgetDecision['budget_usd'])) : '—' ?></strong></div>
            <div><span>Uso</span><strong><?= (float) ($selectedBudgetDecision['budget_usd'] ?? 0) > 0 ? View::e(number_format((float) ($selectedBudgetDecision['used_percent'] ?? 0), 1, ',', '.') . '%') : '—' ?></strong></div>
            <div><span>Ação atual</span><strong><?= View::e($budgetActionLabel((string) ($selectedBudgetDecision['action'] ?? 'none'))) ?></strong></div>
        </div>
        <div class="form-grid four openai-budget-fields">
            <label class="field"><span>Limite mensal (US$)</span><input type="number" name="monthly_budget_usd" min="0" step="0.0001" value="<?= View::e((string) ($selectedBudgetPolicy['monthly_budget_usd'] ?? '')) ?>"><small>Valor estimado das chamadas pagas pela RS Connect.</small></label>
            <label class="field"><span>Primeiro aviso (%)</span><input type="number" name="warning_percent" min="10" max="99" value="<?= (int) ($selectedBudgetPolicy['warning_percent'] ?? 80) ?>"><small>Percentual em que o primeiro aviso será exibido.</small></label>
            <label class="field"><span>Aviso urgente (%)</span><input type="number" name="critical_percent" min="11" max="100" value="<?= (int) ($selectedBudgetPolicy['critical_percent'] ?? 95) ?>"><small>Percentual em que o aviso passa a exigir atenção rápida.</small></label>
            <label class="field"><span>Limite final (%)</span><input type="number" name="hard_limit_percent" min="11" max="150" value="<?= (int) ($selectedBudgetPolicy['hard_limit_percent'] ?? 100) ?>"><small>Percentual em que a ação final será aplicada.</small></label>
        </div>
        <div class="form-grid two openai-budget-fields">
            <label class="field"><span>Ao atingir o primeiro aviso</span><select name="warning_action"><option value="none" <?= ($selectedBudgetPolicy['warning_action'] ?? 'none') === 'none' ? 'selected' : '' ?>>Somente alertar</option><option value="economy" <?= ($selectedBudgetPolicy['warning_action'] ?? '') === 'economy' ? 'selected' : '' ?>>Forçar modo Econômico</option></select><small>O modo Econômico envia menos informações à IA e mantém o atendimento humano disponível.</small></label>
            <label class="field"><span>Ao atingir o limite final</span><select name="hard_limit_action"><option value="notify_only" <?= ($selectedBudgetPolicy['hard_limit_action'] ?? 'notify_only') === 'notify_only' ? 'selected' : '' ?>>Somente alertar</option><option value="economy" <?= ($selectedBudgetPolicy['hard_limit_action'] ?? '') === 'economy' ? 'selected' : '' ?>>Manter IA em modo Econômico</option><option value="block_rs_ai" <?= ($selectedBudgetPolicy['hard_limit_action'] ?? '') === 'block_rs_ai' ? 'selected' : '' ?>>Pausar novas respostas de IA pagas pela RS</option></select><small>A IA paga pelo próprio cliente continua disponível.</small></label>
        </div>
        <div class="openai-budget-savebar"><p>Recomendação inicial: <strong>80% → Econômico</strong> e <strong>100% → bloquear IA RS</strong> somente após homologar os valores de custo.</p><button class="btn btn-primary" type="submit">Salvar limite e proteção</button></div>
    </form>
    <?php else: ?>
    <div class="openai-budget-select-note">Selecione uma empresa acima para definir o limite de gasto, os avisos e as ações automáticas.</div>
    <?php endif; ?>
</section>

<section class="card openai-commercial-margin-panel">
    <div class="section-heading">
        <div><span class="eyebrow">Resultado financeiro por cliente</span><h2>Quanto sobra depois dos custos informados</h2><p>Compara o valor mensal recebido com o custo previsto da IA e outros gastos cadastrados. Esse resultado não é o lucro líquido da empresa.</p></div>
        <span class="badge"><?= View::e(number_format(count(array_filter($commercialOverview, static fn(array $row): bool => !empty($row['configured']))),0,',','.')) ?> empresa(s) analisada(s)</span>
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
                <span>Valor mensal<strong><?= View::e($brl((float) ($commercialRow['revenue_brl'] ?? 0))) ?></strong></span>
                <span>Custo previsto da IA<strong><?= (float) ($commercialRow['usd_brl_rate'] ?? 0) > 0 ? View::e($brl((float) ($commercialRow['projected_ai_cost_brl'] ?? 0))) : '—' ?></strong></span>
                <span>Percentual que sobra<strong><?= $margin !== null ? View::e($percent((float) $margin)) : '—' ?></strong></span>
            </div>
            <div class="openai-commercial-company-foot">
                <?php if ((float) ($commercialRow['price_gap_brl'] ?? 0) > .009): ?>O valor mensal está abaixo da meta em <strong><?= View::e($brl((float) $commercialRow['price_gap_brl'])) ?></strong>.<?php elseif ($statusKey === 'healthy'): ?>O percentual que sobra está acima da meta definida.<?php elseif (empty($commercialRow['configured'])): ?>Informe o valor mensal e o valor do dólar para calcular quanto sobra.<?php else: ?>O percentual que sobra está abaixo da meta. Revise os custos ou o valor mensal.<?php endif; ?>
            </div>
        </article>
        <?php endforeach; ?>
        <?php if (!$commercialOverview): ?><div class="empty-state">A atualização do banco desta função ainda não foi concluída ou não existem empresas ativas.</div><?php endif; ?>
    </div>

    <?php if ($selectedTenant > 0): ?>
    <form class="openai-commercial-policy-form" method="post" action="<?= View::e(Router::url('/openai-usage/commercial')) ?>">
        <?= \App\Core\Csrf::input() ?>
        <input type="hidden" name="tenant_id" value="<?= $selectedTenant ?>">
        <div class="openai-commercial-policy-title">
            <div><span class="eyebrow">Empresa selecionada</span><h3>Análise financeira do cliente</h3><p>Use o valor do plano atual ou informe outro valor mensal para comparar receita e custos.</p></div>
            <label class="check-field"><input type="checkbox" name="enabled" value="1" <?= !isset($selectedCommercialPolicy['enabled']) || !empty($selectedCommercialPolicy['enabled']) ? 'checked' : '' ?>><span>Ativar análise financeira</span></label>
        </div>

        <div class="openai-commercial-current">
            <div><span>Valor mensal do cliente</span><strong><?= View::e($brl((float) ($selectedCommercialAnalysis['revenue_brl'] ?? 0))) ?></strong><small><?= ($selectedCommercialAnalysis['revenue_source'] ?? 'subscription') === 'manual' ? 'valor manual' : 'assinatura mensal equivalente' ?></small></div>
            <div><span>Custo atual da IA</span><strong><?= (float) ($selectedCommercialAnalysis['usd_brl_rate'] ?? 0) > 0 ? View::e($brl((float) ($selectedCommercialAnalysis['current_ai_cost_brl'] ?? 0))) : '—' ?></strong><small><?= View::e($usd((float) ($selectedCommercialAnalysis['current_ai_cost_usd'] ?? 0))) ?></small></div>
            <div><span>Custo previsto da IA</span><strong><?= (float) ($selectedCommercialAnalysis['usd_brl_rate'] ?? 0) > 0 ? View::e($brl((float) ($selectedCommercialAnalysis['projected_ai_cost_brl'] ?? 0))) : '—' ?></strong><small>ritmo do mês atual</small></div>
            <div><span>Percentual que sobra</span><strong><?= ($selectedCommercialAnalysis['projected_margin_rate'] ?? null) !== null ? View::e($percent((float) $selectedCommercialAnalysis['projected_margin_rate'])) : '—' ?></strong><small>alvo <?= View::e($percent((float) ($selectedCommercialAnalysis['target_margin_rate'] ?? .60))) ?></small></div>
            <div><span>Valor que deve sobrar</span><strong><?= View::e($brl((float) ($selectedCommercialAnalysis['projected_contribution_brl'] ?? 0))) ?></strong><small>antes de custos não informados</small></div>
            <div><span>Valor mínimo sugerido</span><strong><?= View::e($brl((float) ($selectedCommercialAnalysis['recommended_revenue_brl'] ?? 0))) ?></strong><small><?= (float) ($selectedCommercialAnalysis['price_gap_brl'] ?? 0) > 0 ? View::e('ajuste sugerido +' . $brl((float) $selectedCommercialAnalysis['price_gap_brl'])) : 'preço atual cobre o alvo conhecido' ?></small></div>
        </div>

        <div class="form-grid three openai-commercial-fields">
            <label class="field"><span>De onde vem o valor mensal</span><select name="revenue_source"><option value="subscription" <?= ($selectedCommercialPolicy['revenue_source'] ?? 'subscription') === 'subscription' ? 'selected' : '' ?>>Assinatura atual</option><option value="manual" <?= ($selectedCommercialPolicy['revenue_source'] ?? '') === 'manual' ? 'selected' : '' ?>>Outro valor mensal</option></select><small>Assinatura converte ciclos trimestral/semestral/anual para equivalente mensal.</small></label>
            <label class="field"><span>Outro valor mensal (R$)</span><input type="number" name="monthly_revenue_brl" min="0" step="0.01" value="<?= View::e((string) ($selectedCommercialPolicy['monthly_revenue_brl'] ?? '')) ?>"><small>Usado somente quando a origem manual estiver selecionada.</small></label>
            <label class="field"><span>Outros custos mensais (R$)</span><input type="number" name="other_monthly_cost_brl" min="0" step="0.01" value="<?= View::e((string) ($selectedCommercialPolicy['other_monthly_cost_brl'] ?? '0')) ?>"><small>Infra, suporte, WhatsApp, automações ou custos atribuídos ao cliente.</small></label>
        </div>
        <div class="form-grid three openai-commercial-fields">
            <label class="field"><span>Percentual que deseja manter (%)</span><input type="number" name="target_margin_percent" min="5" max="95" step="0.1" value="<?= View::e((string) ($selectedCommercialPolicy['target_margin_percent'] ?? '60')) ?>"><small>Usada para calcular a receita mínima de referência.</small></label>
            <label class="field"><span>Avisar quando ficar abaixo de (%)</span><input type="number" name="warning_margin_percent" min="-100" max="94" step="0.1" value="<?= View::e((string) ($selectedCommercialPolicy['warning_margin_percent'] ?? '40')) ?>"><small>Abaixo deste ponto a empresa aparece como margem baixa.</small></label>
            <label class="field"><span>Valor do dólar em reais</span><input type="number" name="usd_brl_rate" min="0" step="0.0001" value="<?= View::e((string) ($selectedCommercialPolicy['usd_brl_rate'] ?? '')) ?>"><small>Opcional. Se ficar vazio, usa o valor definido no servidor. Valor atual: <?= (float) ($selectedCommercialAnalysis['usd_brl_rate'] ?? 0) > 0 ? View::e(number_format((float) $selectedCommercialAnalysis['usd_brl_rate'],4,',','.')) : 'não configurada' ?>.</small></label>
        </div>
        <div class="openai-commercial-savebar"><p><strong>Importante:</strong> o valor que sobra considera apenas os custos cadastrados. Informe os demais gastos mensais para ter uma estimativa mais próxima da realidade.</p><button class="btn btn-primary" type="submit">Salvar análise financeira</button></div>
    </form>
    <?php else: ?>
    <div class="openai-budget-select-note">Selecione uma empresa acima para comparar o valor recebido, os custos e o valor mínimo sugerido.</div>
    <?php endif; ?>
</section>

<section class="card openai-cost-attribution-note">
    <div><span class="eyebrow">Entenda o cálculo</span><h2>Como o custo é separado por empresa</h2><p>A OpenAI informa o total da conta. O RS Connect registra qual empresa e qual assistente fizeram cada chamada e usa esses dados para separar os valores.</p><details><summary>Ver detalhes técnicos</summary><p>O vínculo é feito pelos registros internos de uso, considerando entrada processada, informações reaproveitadas e respostas geradas.</p></details></div>
    <div class="openai-cost-attribution-metrics"><span>Uso com preço conhecido <strong><?= View::e($percent((float)($localTotals['cost_pricing_coverage_rate'] ?? 0))) ?></strong></span><span>Tabela de preços atualizada em <strong><?= $pricingSnapshot !== '' ? View::e($pricingSnapshot) : '—' ?></strong></span></div>
    <?php if ($unpricedModels): ?><details><summary>Modelos sem preço cadastrado (<?= count($unpricedModels) ?>)</summary><div class="openai-cost-list"><?php foreach ($unpricedModels as $model): ?><div><span><?= View::e((string)($model['provider'] ?? '')) ?> · <?= View::e((string)($model['model'] ?? '')) ?></span><strong><?= View::e($compact((int)($model['total_tokens'] ?? 0))) ?> unidades de uso</strong></div><?php endforeach; ?></div><p class="openai-usage-footnote">Peça ao administrador técnico para cadastrar o preço desses modelos nas configurações do servidor.</p></details><?php endif; ?>
</section>
<section class="card openai-governance-panel">
    <div class="section-heading"><div><span class="eyebrow">Conferência dos dados</span><h2>OpenAI x RS Connect</h2><p>Uma diferença pode indicar uso feito por outro sistema, outra chave de acesso ou chamadas que ainda não foram ligadas a uma empresa.</p></div></div>
    <div class="openai-governance-grid">
        <div><span>Uso total informado pela OpenAI</span><strong><?= View::e($compact((int) ($insights['official_openai_tokens'] ?? 0))) ?></strong></div>
        <div><span>Uso identificado pelo RS Connect</span><strong><?= View::e($compact((int) ($insights['internal_openai_tokens'] ?? 0))) ?></strong></div>
        <div><span>Uso ainda não identificado</span><strong><?= View::e($compact((int) ($insights['untracked_tokens'] ?? 0))) ?></strong></div>
        <div><span>Percentual identificado</span><strong><?= View::e($percent((float) ($insights['tracking_coverage_rate'] ?? 0))) ?></strong></div>
    </div>
    <?php if ($lineItems): ?><details class="openai-cost-details"><summary>Ver detalhes do valor oficial</summary><div class="openai-cost-list"><?php foreach ($lineItems as $item): ?><div><span><?= View::e((string)($item['line_item']??'Outros serviços')) ?></span><strong><?= View::e($usd((float)($item['cost']??0))) ?></strong></div><?php endforeach; ?></div></details><?php endif; ?>
</section>
