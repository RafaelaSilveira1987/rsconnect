<?php

use App\Core\Router;
use App\Core\View;

$dashboard = is_array($attentionDashboard ?? null) ? $attentionDashboard : [];
$summary = is_array($dashboard['summary'] ?? null) ? $dashboard['summary'] : [];
$rows = is_array($dashboard['rows'] ?? null) ? $dashboard['rows'] : [];
$filter = (string) ($dashboard['filter'] ?? 'active');
$search = (string) ($dashboard['search'] ?? '');

$brl = static fn (float $value): string => 'R$ ' . number_format($value, 2, ',', '.');
$percent = static fn (?float $value): string => $value === null ? '—' : number_format($value * 100, 1, ',', '.') . '%';
$priorityLabel = static fn (string $value): string => match ($value) {
    'urgent' => 'Ver agora',
    'high' => 'Revisar nesta semana',
    'review' => 'Revisar quando possível',
    'monitor' => 'Acompanhar',
    default => 'Dentro do esperado',
};
$trackingLabel = static fn (string $value): string => match ($value) {
    'reviewing' => 'Em análise',
    'waiting' => 'Aguardando retorno',
    'resolved' => 'Concluído',
    default => 'Precisa de ação',
};
?>

<section class="ai-credentials-hero attention-hero">
    <div>
        <span class="eyebrow">Acompanhamento comercial</span>
        <h2>Clientes que precisam de atenção</h2>
        <p>Veja quem precisa de revisão, entenda o motivo e registre o próximo passo sem analisar várias telas separadas.</p>
    </div>
    <div class="openai-usage-hero-actions">
        <a class="btn btn-outline" href="<?= View::e(Router::url('/ai-profitability')) ?>">Ver resultados por cliente</a>
        <a class="btn btn-primary" href="<?= View::e(Router::url('/client-attention')) ?>">Atualizar lista</a>
    </div>
</section>

<section class="attention-summary-grid">
    <article class="attention-summary is-danger"><span>Ver agora</span><strong><?= (int) ($summary['urgent'] ?? 0) ?></strong><small>situações mais importantes</small></article>
    <article class="attention-summary is-warning"><span>Revisar nesta semana</span><strong><?= (int) ($summary['week'] ?? 0) ?></strong><small>clientes que pedem decisão</small></article>
    <article class="attention-summary is-info"><span>Acompanhar</span><strong><?= (int) ($summary['monitor'] ?? 0) ?></strong><small>mudanças que merecem observação</small></article>
    <article class="attention-summary is-success"><span>Concluídos</span><strong><?= (int) ($summary['resolved'] ?? 0) ?></strong><small>acompanhamentos encerrados</small></article>
    <article class="attention-summary is-dark"><span>Receita em atenção</span><strong><?= View::e($brl((float) ($summary['revenue_at_risk_brl'] ?? 0))) ?></strong><small>valor mensal dos clientes ativos na lista</small></article>
</section>

<section class="card attention-filter-card">
    <form method="get" action="<?= View::e(Router::url('/client-attention')) ?>">
        <label><span>Buscar empresa</span><input type="search" name="search" value="<?= View::e($search) ?>" placeholder="Digite o nome do cliente"></label>
        <label><span>Mostrar</span><select name="filter">
            <option value="active" <?= $filter === 'active' ? 'selected' : '' ?>>Todos que precisam de ação</option>
            <option value="urgent" <?= $filter === 'urgent' ? 'selected' : '' ?>>Ver agora</option>
            <option value="week" <?= $filter === 'week' ? 'selected' : '' ?>>Revisar nesta semana</option>
            <option value="monitor" <?= $filter === 'monitor' ? 'selected' : '' ?>>Acompanhar</option>
            <option value="resolved" <?= $filter === 'resolved' ? 'selected' : '' ?>>Concluídos</option>
            <option value="all" <?= $filter === 'all' ? 'selected' : '' ?>>Todos</option>
        </select></label>
        <button class="btn btn-outline" type="submit">Aplicar</button>
    </form>
    <p>A prioridade é calculada com base no valor que sobra, gasto com IA, limite mensal, tendência e capacidade do plano.</p>
</section>

<section class="attention-list" aria-label="Lista de clientes que precisam de atenção">
<?php foreach ($rows as $row):
    $commercial = is_array($row['commercial'] ?? null) ? $row['commercial'] : [];
    $budget = is_array($row['budget'] ?? null) ? $row['budget'] : [];
    $reasons = is_array($row['reasons'] ?? null) ? $row['reasons'] : [];
    $action = is_array($row['recommended_action'] ?? null) ? $row['recommended_action'] : [];
    $tenantId = (int) ($row['tenant_id'] ?? 0);
    $status = (string) ($row['tracking_status'] ?? 'open');
    $priority = (string) ($row['priority'] ?? 'monitor');
?>
    <article class="card attention-client-card is-<?= View::e($priority) ?><?= $status === 'resolved' ? ' is-resolved' : '' ?>">
        <header class="attention-client-head">
            <div>
                <span class="attention-priority"><?= View::e($priorityLabel($priority)) ?></span>
                <h3><?= View::e((string) ($row['tenant_name'] ?? 'Empresa')) ?></h3>
                <p><?= View::e((string) ($commercial['subscription']['plan_name'] ?? 'Sem plano')) ?> · <?= View::e($trackingLabel($status)) ?></p>
            </div>
            <div class="attention-client-actions">
                <a class="btn btn-outline btn-small" href="<?= View::e(Router::url('/ai-profitability') . '?tenant_id=' . $tenantId) ?>">Ver resultados</a>
                <a class="btn btn-soft btn-small" href="<?= View::e(Router::url('/companies/overview') . '?id=' . $tenantId) ?>">Abrir empresa</a>
            </div>
        </header>

        <?php if (!empty($row['reopened'])): ?><div class="operations-alert is-warning"><strong>Nova mudança encontrada</strong><p>Este acompanhamento havia sido concluído, mas os números mudaram e precisam de uma nova revisão.</p></div><?php endif; ?>

        <div class="attention-client-kpis">
            <span><small>Valor mensal</small><strong><?= View::e($brl((float) ($commercial['revenue_brl'] ?? 0))) ?></strong></span>
            <span><small>Custo previsto da IA</small><strong><?= View::e($brl((float) ($commercial['projected_ai_cost_brl'] ?? 0))) ?></strong></span>
            <span><small>Percentual que sobra</small><strong><?= View::e($percent(($commercial['projected_margin_rate'] ?? null) !== null ? (float) $commercial['projected_margin_rate'] : null)) ?></strong></span>
            <span><small>Limite de gasto usado</small><strong><?= !empty($budget['enabled']) ? number_format((float) ($budget['used_percent'] ?? 0), 1, ',', '.') . '%' : 'Não configurado' ?></strong></span>
        </div>

        <div class="attention-client-body">
            <div class="attention-reasons">
                <h4>Por que aparece nesta lista?</h4>
                <?php if ($reasons): ?><ul><?php foreach (array_slice($reasons, 0, 4) as $reason): ?><li><strong><?= View::e((string) ($reason['title'] ?? 'Revisar')) ?></strong><span><?= View::e((string) ($reason['detail'] ?? '')) ?></span></li><?php endforeach; ?></ul><?php else: ?><p>Nenhuma mudança importante foi encontrada.</p><?php endif; ?>
            </div>
            <div class="attention-next-step">
                <span>Próximo passo sugerido</span>
                <strong><?= View::e((string) ($action['title'] ?? 'Acompanhar')) ?></strong>
                <p><?= View::e((string) ($action['description'] ?? 'Continue acompanhando os números.')) ?></p>
            </div>
        </div>

        <details class="attention-followup"<?= $status !== 'resolved' && ((string) ($row['tracking_note'] ?? '') !== '' || !empty($row['due_at'])) ? ' open' : '' ?>>
            <summary>Registrar acompanhamento</summary>
            <form method="post" action="<?= View::e(Router::url('/client-attention/save')) ?>">
                <?= \App\Core\Csrf::input() ?>
                <input type="hidden" name="tenant_id" value="<?= $tenantId ?>">
                <input type="hidden" name="return_filter" value="<?= View::e($filter) ?>">
                <input type="hidden" name="return_search" value="<?= View::e($search) ?>">
                <label><span>Situação</span><select name="status">
                    <option value="open" <?= $status === 'open' ? 'selected' : '' ?>>Precisa de ação</option>
                    <option value="reviewing" <?= $status === 'reviewing' ? 'selected' : '' ?>>Em análise</option>
                    <option value="waiting" <?= $status === 'waiting' ? 'selected' : '' ?>>Aguardando retorno</option>
                    <option value="resolved" <?= $status === 'resolved' ? 'selected' : '' ?>>Concluído</option>
                </select></label>
                <label><span>Próxima revisão</span><input type="date" name="due_at" value="<?= View::e((string) ($row['due_at'] ?? '')) ?>"></label>
                <label class="is-wide"><span>Anotação</span><textarea name="note" rows="3" placeholder="Ex.: revisar o plano com o cliente na próxima reunião"><?= View::e((string) ($row['tracking_note'] ?? '')) ?></textarea></label>
                <button class="btn btn-primary" type="submit">Salvar acompanhamento</button>
            </form>
        </details>
    </article>
<?php endforeach; ?>

<?php if (!$rows): ?>
    <div class="card empty-state attention-empty"><strong>Nenhum cliente encontrado</strong><p>Altere o filtro ou aguarde novos dados de uso e resultado.</p></div>
<?php endif; ?>
</section>

<section class="card attention-method-card">
    <span class="eyebrow">Como a lista funciona</span>
    <h2>O sistema reúne sinais e explica o motivo</h2>
    <div class="attention-method-grid">
        <span><strong>Resultado</strong> verifica se o valor recebido cobre os custos informados e a meta definida.</span>
        <span><strong>Gasto com IA</strong> observa aumento de custo, peso no valor mensal e limite utilizado.</span>
        <span><strong>Plano</strong> confere se o plano atual suporta a quantidade de usuários, canais e uso.</span>
        <span><strong>Tendência</strong> identifica piora do resultado ou aumento do custo nos últimos meses.</span>
    </div>
    <p>A lista serve como apoio. Nenhum preço, plano ou limite é alterado automaticamente.</p>
</section>
