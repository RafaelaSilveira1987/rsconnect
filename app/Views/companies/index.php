<?php

use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;

$filters = $filters ?? ['q' => '', 'status' => '', 'plan' => '', 'health' => '', 'tracking' => ''];
$summary = $summary ?? [];
$dataWarnings = $dataWarnings ?? [];
$relative = static function (?string $value): string {
    if (!$value) return 'Sem atividade';
    $ts = strtotime($value);
    if (!$ts) return $value;
    $diff = max(0, time() - $ts);
    if ($diff < 3600) return 'há ' . max(1, (int) floor($diff / 60)) . ' min';
    if ($diff < 86400) return 'há ' . (int) floor($diff / 3600) . ' h';
    if ($diff < 604800) return 'há ' . (int) floor($diff / 86400) . ' dia(s)';
    return date('d/m/Y', $ts);
};
$subscriptionLabel = static fn (string $status): string => match ($status) {
    'trialing' => 'Em teste',
    'active' => 'Ativa',
    'overdue' => 'Em atraso',
    'suspended' => 'Suspensa',
    'canceled' => 'Cancelada',
    default => 'Sem assinatura',
};
?>

<section class="admin-companies-hero">
    <div>
        <span class="eyebrow">Base de clientes</span>
        <h2>Empresas do RS Connect</h2>
        <p>Veja a saúde de cada cliente, identifique pendências e acesse rapidamente os módulos administrativos.</p>
    </div>
    <button class="btn btn-primary" type="button" data-toggle-panel="admin-company-create-drawer">Nova empresa</button>
</section>

<?php if ($dataWarnings): ?>
<section class="admin-data-warning" role="alert">
    <div><strong>Não foi possível atualizar todos os indicadores.</strong><span>A lista abaixo usa somente dados confirmados pelo banco. Consulte o diagnóstico para identificar a consulta pendente.</span></div>
    <a class="btn btn-outline btn-small" href="<?= View::e(Router::url('/status-sistema')) ?>">Ver diagnóstico</a>
</section>
<?php endif; ?>

<section class="admin-company-summary-grid" aria-label="Resumo das empresas">
    <a href="<?= View::e(Router::url('/companies')) ?>" class="admin-company-summary-card"><span>Total</span><strong><?= (int) ($summary['total'] ?? 0) ?></strong><small>empresas cadastradas</small></a>
    <a href="<?= View::e(Router::url('/companies?health=healthy')) ?>" class="admin-company-summary-card is-healthy"><span>Saudáveis</span><strong><?= (int) ($summary['healthy'] ?? 0) ?></strong><small>operando normalmente</small></a>
    <a href="<?= View::e(Router::url('/companies?health=implantation')) ?>" class="admin-company-summary-card is-implantation"><span>Em implantação</span><strong><?= (int) ($summary['implantation'] ?? 0) ?></strong><small>configuração em andamento</small></a>
    <a href="<?= View::e(Router::url('/companies?health=attention')) ?>" class="admin-company-summary-card is-attention"><span>Atenção</span><strong><?= (int) ($summary['attention'] ?? 0) ?></strong><small>precisam de revisão</small></a>
    <a href="<?= View::e(Router::url('/companies?health=critical')) ?>" class="admin-company-summary-card is-critical"><span>Críticas</span><strong><?= (int) ($summary['critical'] ?? 0) ?></strong><small>ação prioritária</small></a>
    <a href="<?= View::e(Router::url('/companies?status=inactive')) ?>" class="admin-company-summary-card is-inactive"><span>Inativas</span><strong><?= (int) ($summary['inactive'] ?? 0) ?></strong><small>acesso do cliente bloqueado</small></a>
</section>

<form class="admin-company-filters card" method="get" action="<?= View::e(Router::url('/companies')) ?>">
    <label class="admin-company-search"><span class="sr-only">Buscar empresa</span><input type="search" name="q" value="<?= View::e((string) $filters['q']) ?>" placeholder="Buscar por empresa, responsável, e-mail ou CNPJ"></label>
    <label><span class="sr-only">Saúde</span><select name="health"><option value="">Todas as situações</option><option value="healthy" <?= $filters['health'] === 'healthy' ? 'selected' : '' ?>>Saudável</option><option value="implantation" <?= $filters['health'] === 'implantation' ? 'selected' : '' ?>>Em implantação</option><option value="attention" <?= $filters['health'] === 'attention' ? 'selected' : '' ?>>Atenção</option><option value="critical" <?= $filters['health'] === 'critical' ? 'selected' : '' ?>>Crítica</option><option value="inactive" <?= $filters['health'] === 'inactive' ? 'selected' : '' ?>>Inativa</option></select></label>
    <label><span class="sr-only">Plano</span><select name="plan"><option value="">Todos os planos</option><option value="starter" <?= $filters['plan'] === 'starter' ? 'selected' : '' ?>>Starter</option><option value="pro" <?= $filters['plan'] === 'pro' ? 'selected' : '' ?>>Profissional</option><option value="business" <?= $filters['plan'] === 'business' ? 'selected' : '' ?>>Business</option><option value="custom" <?= $filters['plan'] === 'custom' ? 'selected' : '' ?>>Personalizado</option></select></label>
    <label><span class="sr-only">Status cadastral</span><select name="status"><option value="">Todos os status</option><option value="active" <?= $filters['status'] === 'active' ? 'selected' : '' ?>>Ativa</option><option value="inactive" <?= $filters['status'] === 'inactive' ? 'selected' : '' ?>>Inativa</option><option value="suspended" <?= $filters['status'] === 'suspended' ? 'selected' : '' ?>>Suspensa</option></select></label>
    <label><span class="sr-only">Acompanhamento</span><select name="tracking"><option value="">Todo acompanhamento</option><option value="automatic" <?= $filters['tracking'] === 'automatic' ? 'selected' : '' ?>>Automático</option><option value="attention" <?= $filters['tracking'] === 'attention' ? 'selected' : '' ?>>Atenção manual</option><option value="reviewed" <?= $filters['tracking'] === 'reviewed' ? 'selected' : '' ?>>Em acompanhamento</option><option value="resolved" <?= $filters['tracking'] === 'resolved' ? 'selected' : '' ?>>Corrigida</option></select></label>
    <button class="btn btn-primary" type="submit">Filtrar</button>
    <a class="btn btn-quiet" href="<?= View::e(Router::url('/companies')) ?>">Limpar</a>
</form>

<section class="admin-company-results-heading">
    <div><span class="eyebrow">Resultado</span><h2><?= count($companies) ?> empresa(s) encontrada(s)</h2></div>
</section>

<div class="admin-company-list">
    <?php foreach ($companies as $company): ?>
        <?php
            $instanceTotal = (int) ($company['instances']['total'] ?? 0);
            $connectedInstances = (int) ($company['instances']['connected_count'] ?? 0);
            $activeAgents = (int) ($company['agents']['active_count'] ?? 0);
            $messages30 = (int) ($company['messages']['count_30d'] ?? 0);
            $subscriptionStatus = (string) ($company['subscription']['billing_status'] ?? '');
            $implementationPercent = (int) ($company['implementation']['percent_complete'] ?? ($company['onboarding_completed_at'] ? 100 : 0));
            $tracking = (array) ($company['admin_tracking'] ?? []);
            $trackingStatus = (string) ($tracking['tracking_status'] ?? 'automatic');
            $trackingPriority = (string) ($tracking['priority'] ?? 'attention');
            $trackingNote = (string) ($tracking['note'] ?? '');
        ?>
        <article class="admin-company-card is-<?= View::e((string) $company['health']) ?>">
            <div class="admin-company-card-identity">
                <span class="company-avatar"><?= View::e(mb_strtoupper(mb_substr((string) $company['name'], 0, 2))) ?></span>
                <div>
                    <div class="admin-company-title-row"><h3><?= View::e((string) $company['name']) ?></h3><span class="admin-health-badge is-<?= View::e((string) $company['health']) ?>"><?= View::e((string) $company['health_label']) ?></span></div>
                    <p><?= View::e((string) ($company['segment'] ?: 'Segmento não informado')) ?><?= !empty($company['email']) ? ' · ' . View::e((string) $company['email']) : '' ?></p>
                    <div class="badge-row"><span class="badge"><?= View::e(ucfirst((string) $company['plan'])) ?></span><span class="badge badge-<?= View::e((string) $company['status']) ?>"><?= View::e(ucfirst((string) $company['status'])) ?></span><span class="badge"><?= View::e($subscriptionLabel($subscriptionStatus)) ?></span></div>
                </div>
            </div>

            <div class="admin-company-card-metrics">
                <div><span>WhatsApp</span><strong><?= $connectedInstances ?>/<?= $instanceTotal ?></strong><small>conectado(s)</small></div>
                <div><span>Assistentes</span><strong><?= $activeAgents ?></strong><small>ativo(s)</small></div>
                <div><span>Mensagens</span><strong><?= number_format($messages30, 0, ',', '.') ?></strong><small>últimos 30 dias</small></div>
                <div><span>Implantação</span><strong><?= $implementationPercent ?>%</strong><small><?= $company['onboarding_completed_at'] ? 'concluída' : 'em andamento' ?></small></div>
                <div><span>Última atividade</span><strong class="is-date"><?= View::e($relative($company['last_activity_at'] ?? null)) ?></strong><small><?= (int) ($company['conversations']['unread_count'] ?? 0) ?> não lida(s)</small></div>
            </div>

            <div class="admin-company-card-footer">
                <div class="admin-company-reasons">
                    <?php if (!empty($company['attention_reasons'])): ?>
                        <?php foreach (array_slice((array) $company['attention_reasons'], 0, 2) as $reason): ?><span><?= View::e((string) $reason) ?></span><?php endforeach; ?>
                        <?php if ((int) $company['attention_count'] > 2): ?><small>+<?= (int) $company['attention_count'] - 2 ?> ponto(s) para revisar</small><?php endif; ?>
                    <?php else: ?>
                        <span class="is-ok">Configurações principais em ordem.</span>
                    <?php endif; ?>
                </div>
                <div class="admin-company-actions">
                    <a class="btn btn-primary" href="<?= View::e(Router::url('/companies/overview?id=' . (int) $company['id'])) ?>">Visão geral</a>
                    <a class="btn btn-outline" href="<?= View::e(Router::url('/company-settings?id=' . (int) $company['id'])) ?>">Editar dados</a>
                    <a class="btn btn-outline" href="<?= View::e(Router::url('/company-settings?id=' . (int) $company['id'])) ?>#company-module-settings">Menus do cliente</a>
                    <details class="action-popover admin-tracking-popover">
                        <summary class="btn btn-quiet">Acompanhamento</summary>
                        <form class="popover-form" method="post" action="<?= View::e(Router::url('/companies/tracking')) ?>">
                            <?= Csrf::input() ?>
                            <input type="hidden" name="tenant_id" value="<?= (int) $company['id'] ?>">
                            <input type="hidden" name="return_to" value="/companies">
                            <label class="field"><span>Situação administrativa</span><select name="tracking_status">
                                <option value="automatic" <?= $trackingStatus === 'automatic' ? 'selected' : '' ?>>Usar análise automática</option>
                                <option value="attention" <?= $trackingStatus === 'attention' ? 'selected' : '' ?>>Precisa de atenção</option>
                                <option value="reviewed" <?= $trackingStatus === 'reviewed' ? 'selected' : '' ?>>Visualizada / em acompanhamento</option>
                                <option value="resolved" <?= $trackingStatus === 'resolved' ? 'selected' : '' ?>>Corrigida / resolvida</option>
                            </select></label>
                            <label class="field"><span>Prioridade</span><select name="priority">
                                <option value="attention" <?= $trackingPriority === 'attention' ? 'selected' : '' ?>>Atenção</option>
                                <option value="critical" <?= $trackingPriority === 'critical' ? 'selected' : '' ?>>Crítica</option>
                                <option value="implantation" <?= $trackingPriority === 'implantation' ? 'selected' : '' ?>>Implantação</option>
                            </select></label>
                            <label class="field"><span>Observação</span><textarea name="note" rows="3" placeholder="Ex.: conexão refeita; aguardando novo teste."><?= View::e($trackingNote) ?></textarea></label>
                            <button class="btn btn-primary btn-block" type="submit">Atualizar acompanhamento</button>
                        </form>
                    </details>
                    <details class="action-popover">
                        <summary class="btn btn-quiet">Plano e acesso</summary>
                        <form class="popover-form" method="post" action="<?= View::e(Router::url('/companies/status')) ?>">
                            <?= Csrf::input() ?>
                            <input type="hidden" name="tenant_id" value="<?= (int) $company['id'] ?>">
                            <label class="field"><span>Plano</span><select name="plan"><?php foreach (['starter' => 'Starter', 'pro' => 'Profissional', 'business' => 'Business', 'custom' => 'Personalizado'] as $value => $label): ?><option value="<?= $value ?>" <?= $company['plan'] === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label>
                            <label class="field"><span>Acesso da empresa</span><select name="status"><?php foreach (['active' => 'Ativa', 'inactive' => 'Inativa', 'suspended' => 'Suspensa'] as $value => $label): ?><option value="<?= $value ?>" <?= $company['status'] === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label>
                            <small class="field-hint">Empresa inativa não permite login dos usuários do cliente.</small>
                            <button class="btn btn-primary btn-block" type="submit">Salvar plano e acesso</button>
                        </form>
                    </details>
                    <form method="post" action="<?= View::e(Router::url('/companies/status')) ?>" onsubmit="return confirm('<?= $company['status'] === 'inactive' ? 'Reativar esta empresa e liberar o acesso dos usuários?' : 'Inativar esta empresa e bloquear o acesso dos usuários do cliente?' ?>');">
                        <?= Csrf::input() ?>
                        <input type="hidden" name="tenant_id" value="<?= (int) $company['id'] ?>">
                        <input type="hidden" name="plan" value="<?= View::e((string) $company['plan']) ?>">
                        <input type="hidden" name="status" value="<?= $company['status'] === 'inactive' ? 'active' : 'inactive' ?>">
                        <button class="btn <?= $company['status'] === 'inactive' ? 'btn-primary' : 'btn-danger-soft' ?>" type="submit"><?= $company['status'] === 'inactive' ? 'Reativar' : 'Inativar' ?></button>
                    </form>
                </div>
            </div>
        </article>
    <?php endforeach; ?>
    <?php if (!$companies): ?><div class="card empty-state">Nenhuma empresa encontrada com os filtros selecionados.</div><?php endif; ?>
</div>

<aside class="conversation-details conversation-drawer admin-company-drawer" id="admin-company-create-drawer" aria-label="Cadastrar nova empresa" aria-modal="true" role="dialog">
    <div class="conversation-drawer-header">
        <div><span class="eyebrow">Novo cliente</span><h2>Cadastrar empresa</h2><p>Crie a empresa e o primeiro acesso administrativo.</p></div>
        <button class="icon-button drawer-close" type="button" data-close-panel="admin-company-create-drawer" aria-label="Fechar painel">×</button>
    </div>
    <div class="conversation-drawer-body">
        <?php require __DIR__ . '/_create_form.php'; ?>
    </div>
</aside>
