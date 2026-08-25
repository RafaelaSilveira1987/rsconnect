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
$options = is_array($aiEfficiency['filter_options'] ?? null) ? $aiEfficiency['filter_options'] : [];
$filters = is_array($aiEfficiency['filters'] ?? null) ? $aiEfficiency['filters'] : [];
$selectedTenant = (int) ($filters['tenant_id'] ?? 0);
$selectedAgent = (int) ($filters['agent_id'] ?? 0);

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
    <article class="card openai-v2-panel"><div class="section-heading"><div><span class="eyebrow">Custo por empresa</span><h2>Onde a IA está sendo consumida</h2></div></div><div class="openai-management-ranking"><?php foreach (array_slice($tenantRanking,0,10) as $row): ?><div><div><strong><?= View::e((string)$row['tenant_name']) ?></strong><small><?= View::e($compact((int)$row['conversations'])) ?> conversa(s) · <?= View::e($compact((int)$row['avoided_calls'])) ?> chamada(s) evitada(s)</small></div><div><strong><?= View::e($usd((float)$row['estimated_cost'])) ?></strong><small><?= View::e($compact((int)$row['total_tokens'])) ?> tokens</small></div></div><?php endforeach; ?><?php if (!$tenantRanking): ?><div class="empty-state">Sem telemetria no período.</div><?php endif; ?></div></article>
    <article class="card openai-v2-panel"><div class="section-heading"><div><span class="eyebrow">Custo por assistente</span><h2>Assistentes que mais consomem</h2></div></div><div class="openai-management-ranking"><?php foreach (array_slice($agentRanking,0,10) as $row): ?><div><div><strong><?= View::e((string)$row['agent_name']) ?></strong><small><?= View::e((string)$row['tenant_name']) ?> · <?= View::e($compact((int)$row['avoided_calls'])) ?> evitada(s)</small></div><div><strong><?= View::e($usd((float)$row['estimated_cost'])) ?></strong><small><?= View::e($compact((int)$row['total_tokens'])) ?> tokens</small></div></div><?php endforeach; ?><?php if (!$agentRanking): ?><div class="empty-state">Sem telemetria no período.</div><?php endif; ?></div></article>
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
