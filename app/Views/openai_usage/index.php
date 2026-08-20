<?php

use App\Core\Router;
use App\Core\View;

$openAiUsage = is_array($openAiUsage ?? null) ? $openAiUsage : [];
$openAiTotals = is_array($openAiUsage['totals'] ?? null) ? $openAiUsage['totals'] : [];
$openAiStatus = (string) ($openAiUsage['status'] ?? 'not_configured');
$openAiPeriod = (string) ($openAiUsage['period'] ?? 'month');
$openAiModels = is_array($openAiUsage['models'] ?? null) ? $openAiUsage['models'] : [];
$openAiLineItems = is_array($openAiUsage['line_items'] ?? null) ? $openAiUsage['line_items'] : [];
$openAiDaily = is_array($openAiUsage['daily'] ?? null) ? $openAiUsage['daily'] : [];
$openAiMaxDailyTokens = max(1, (int) ($openAiUsage['max_daily_tokens'] ?? 0));
$formatCompactNumber = static function (int|float $value): string {
    $absolute = abs((float) $value);
    if ($absolute >= 1000000000) {
        return number_format($value / 1000000000, 2, ',', '.') . ' bi';
    }
    if ($absolute >= 1000000) {
        return number_format($value / 1000000, 2, ',', '.') . ' mi';
    }
    if ($absolute >= 1000) {
        return number_format($value / 1000, 1, ',', '.') . ' mil';
    }
    return number_format($value, 0, ',', '.');
};
$formatUsd = static fn (float $value): string => 'US$ ' . number_format($value, 4, ',', '.');
$fetchedAtLabel = '';
if (!empty($openAiUsage['fetched_at'])) {
    try {
        $fetchedAtLabel = (new DateTimeImmutable((string) $openAiUsage['fetched_at']))
            ->setTimezone(new DateTimeZone(date_default_timezone_get()))
            ->format('d/m/Y H:i');
    } catch (Throwable) {
        $fetchedAtLabel = '';
    }
}
?>

<section class="ai-credentials-hero openai-usage-hero">
    <div>
        <span class="eyebrow">Administração RS Connect</span>
        <h2>Consumo oficial da OpenAI</h2>
        <p>Acompanhe tokens, chamadas, modelos e custos diretamente pela Usage API administrativa da organização.</p>
    </div>
    <a class="btn btn-outline" href="<?= View::e(Router::url('/ai-credentials')) ?>">Gerenciar credenciais</a>
</section>

<section class="card openai-usage-panel" aria-label="Consumo direto da OpenAI">
    <div class="section-heading openai-usage-heading">
        <div>
            <span class="eyebrow">Dados oficiais da organização</span>
            <h2>Consumo direto da OpenAI</h2>
            <p>A Admin API Key fica somente no servidor e nunca é exibida no navegador.</p>
        </div>
        <div class="openai-usage-actions">
            <nav class="openai-period-filter" aria-label="Período do consumo OpenAI">
                <?php foreach (['7d' => '7 dias', '30d' => '30 dias', 'month' => 'Mês atual'] as $periodKey => $periodLabel): ?>
                    <a
                        class="<?= $openAiPeriod === $periodKey ? 'is-active' : '' ?>"
                        href="<?= View::e(Router::url('/openai-usage') . '?usage_period=' . $periodKey) ?>"
                    ><?= View::e($periodLabel) ?></a>
                <?php endforeach; ?>
            </nav>
            <a
                class="btn btn-outline btn-small"
                href="<?= View::e(Router::url('/openai-usage') . '?usage_period=' . rawurlencode($openAiPeriod) . '&refresh_usage=1') ?>"
            >Atualizar agora</a>
        </div>
    </div>

    <?php if ($openAiStatus === 'not_configured'): ?>
        <div class="openai-usage-setup">
            <div>
                <strong>Conecte a Usage API da OpenAI</strong>
                <p>Cadastre uma <strong>Admin API Key</strong> da organização no ambiente do RS Connect. A chave comum usada pelos assistentes não possui acesso a este relatório.</p>
            </div>
            <code>OPENAI_ADMIN_API_KEY=sk-admin-...</code>
            <small>Opcional: use <code>OPENAI_USAGE_PROJECT_IDS=proj_...</code> para limitar o painel a projetos específicos.</small>
        </div>
    <?php else: ?>
        <?php if ($openAiStatus === 'error'): ?>
            <div class="operations-alert is-danger openai-usage-alert">
                <strong>Não foi possível consultar a OpenAI</strong>
                <p><?= View::e((string) ($openAiUsage['error'] ?? 'Revise a Admin API Key e tente novamente.')) ?></p>
            </div>
        <?php elseif ($openAiStatus === 'stale'): ?>
            <div class="operations-alert is-warning openai-usage-alert">
                <strong>Exibindo a última consulta salva</strong>
                <p><?= View::e((string) ($openAiUsage['error'] ?? 'A atualização em tempo real falhou.')) ?></p>
            </div>
        <?php endif; ?>

        <?php if ($openAiStatus !== 'error' || $openAiDaily !== []): ?>
            <div class="openai-usage-meta">
                <span><?= View::e((string) ($openAiUsage['period_label'] ?? 'Período selecionado')) ?></span>
                <span><?= View::e(date('d/m/Y', strtotime((string) ($openAiUsage['start_date'] ?? 'now')))) ?> a <?= View::e(date('d/m/Y', strtotime((string) ($openAiUsage['end_date'] ?? 'now')))) ?></span>
                <?php if ($fetchedAtLabel !== ''): ?><span>Atualizado em <?= View::e($fetchedAtLabel) ?></span><?php endif; ?>
                <?php if (!empty($openAiUsage['from_cache'])): ?><span class="badge">Cache protegido</span><?php endif; ?>
                <?php if (!empty($openAiUsage['project_filter'])): ?><span class="badge">Projetos filtrados: <?= count($openAiUsage['project_filter']) ?></span><?php endif; ?>
            </div>

            <div class="openai-usage-summary">
                <article>
                    <span>Tokens totais</span>
                    <strong><?= View::e($formatCompactNumber((int) ($openAiTotals['total_tokens'] ?? 0))) ?></strong>
                    <small>entrada + saída</small>
                </article>
                <article class="is-blue">
                    <span>Tokens de entrada</span>
                    <strong><?= View::e($formatCompactNumber((int) ($openAiTotals['input_tokens'] ?? 0))) ?></strong>
                    <small>prompts e contexto</small>
                </article>
                <article class="is-purple">
                    <span>Tokens de saída</span>
                    <strong><?= View::e($formatCompactNumber((int) ($openAiTotals['output_tokens'] ?? 0))) ?></strong>
                    <small>respostas geradas</small>
                </article>
                <article class="is-success">
                    <span>Tokens em cache</span>
                    <strong><?= View::e($formatCompactNumber((int) ($openAiTotals['cached_tokens'] ?? 0))) ?></strong>
                    <small>parte reaproveitada</small>
                </article>
                <article class="is-orange">
                    <span>Chamadas ao modelo</span>
                    <strong><?= View::e($formatCompactNumber((int) ($openAiTotals['requests'] ?? 0))) ?></strong>
                    <small>requisições processadas</small>
                </article>
                <article class="is-dark">
                    <span>Custo oficial</span>
                    <strong><?= View::e($formatUsd((float) ($openAiTotals['cost'] ?? 0))) ?></strong>
                    <small>todos os itens retornados</small>
                </article>
            </div>

            <div class="openai-usage-grid">
                <article class="openai-usage-chart-card">
                    <div class="openai-usage-card-title">
                        <div>
                            <span class="eyebrow">Evolução diária</span>
                            <h3>Tokens consumidos</h3>
                        </div>
                        <small>UTC, conforme o painel da OpenAI</small>
                    </div>
                    <?php if ($openAiDaily !== []): ?>
                        <div class="openai-usage-chart" role="img" aria-label="Consumo diário de tokens">
                            <?php foreach ($openAiDaily as $day): ?>
                                <?php $height = max(3, (int) round(((int) ($day['total_tokens'] ?? 0) / $openAiMaxDailyTokens) * 100)); ?>
                                <div class="openai-usage-day" title="<?= View::e((string) ($day['label'] ?? '')) ?> — <?= View::e(number_format((int) ($day['total_tokens'] ?? 0), 0, ',', '.')) ?> tokens — <?= View::e($formatUsd((float) ($day['cost'] ?? 0))) ?>">
                                    <span class="openai-usage-bar" style="--openai-bar-height: <?= $height ?>%"></span>
                                    <small><?= View::e((string) ($day['label'] ?? '')) ?></small>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state openai-usage-empty">Nenhum token foi retornado para este período.</div>
                    <?php endif; ?>
                </article>

                <article class="openai-usage-ranking-card">
                    <div class="openai-usage-card-title">
                        <div>
                            <span class="eyebrow">Distribuição</span>
                            <h3>Uso por modelo</h3>
                        </div>
                    </div>
                    <?php if ($openAiModels !== []): ?>
                        <div class="openai-usage-ranking">
                            <?php foreach (array_slice($openAiModels, 0, 7) as $model): ?>
                                <?php $modelShare = (int) ($openAiTotals['total_tokens'] ?? 0) > 0 ? ((int) ($model['total_tokens'] ?? 0) / (int) $openAiTotals['total_tokens']) * 100 : 0; ?>
                                <div class="openai-usage-rank-row">
                                    <div>
                                        <strong><?= View::e((string) ($model['model'] ?? 'Não identificado')) ?></strong>
                                        <small><?= View::e(number_format((int) ($model['requests'] ?? 0), 0, ',', '.')) ?> chamada(s)</small>
                                    </div>
                                    <div class="openai-usage-rank-value">
                                        <strong><?= View::e($formatCompactNumber((int) ($model['total_tokens'] ?? 0))) ?></strong>
                                        <small><?= View::e(number_format($modelShare, 1, ',', '.')) ?>%</small>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state openai-usage-empty">A OpenAI não retornou detalhamento por modelo.</div>
                    <?php endif; ?>
                </article>
            </div>

            <details class="openai-cost-details">
                <summary>Ver composição do custo oficial</summary>
                <?php if ($openAiLineItems !== []): ?>
                    <div class="openai-cost-list">
                        <?php foreach ($openAiLineItems as $item): ?>
                            <div>
                                <span><?= View::e((string) ($item['line_item'] ?? 'Outros serviços')) ?></span>
                                <strong><?= View::e($formatUsd((float) ($item['cost'] ?? 0))) ?></strong>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p>Nenhum custo foi retornado para o período.</p>
                <?php endif; ?>
            </details>

            <p class="openai-usage-footnote">Este painel mostra o consumo da organização ou dos projetos configurados na OpenAI. O consumo interno por empresa do RS Connect continua sendo contabilizado separadamente pela telemetria local.</p>
        <?php endif; ?>
    <?php endif; ?>
</section>
