<?php

use App\Core\Router;
use App\Core\View;

$metrics = $data['metrics'] ?? [];
$healthChecks = $data['health_checks'] ?? [];
$attentionCompanies = $data['attention_companies'] ?? [];
$recentCompanies = $data['recent_companies'] ?? [];
$recentActivity = $data['recent_activity'] ?? [];
$companySummary = $data['company_summary'] ?? [];
$refreshedAt = $metrics['refreshed_at'] ?? null;
$dataWarnings = $data['data_warnings'] ?? [];
$diagnostic = $data['diagnostic'] ?? [];
$sourceVersion = (string) ($diagnostic['service_version'] ?? ($metrics['source_version'] ?? ''));
$databaseName = (string) ($diagnostic['database'] ?? '');

$money = static fn (float $value): string => 'R$ ' . number_format($value, 2, ',', '.');
$dateTime = static function (?string $value): string {
    if (!$value) return 'Sem registro';
    $ts = strtotime($value);
    return $ts ? date('d/m/Y H:i', $ts) : $value;
};
$relative = static function (?string $value): string {
    if (!$value) return 'Sem atividade';
    $ts = strtotime($value);
    if (!$ts) return $value;
    $diff = max(0, time() - $ts);
    if ($diff < 60) return 'agora';
    if ($diff < 3600) return 'há ' . (int) floor($diff / 60) . ' min';
    if ($diff < 86400) return 'há ' . (int) floor($diff / 3600) . ' h';
    if ($diff < 604800) return 'há ' . (int) floor($diff / 86400) . ' dia(s)';
    return date('d/m/Y', $ts);
};
$statusLabel = static fn (string $status): string => match ($status) {
    'ok' => 'Operacional',
    'warning' => 'Atenção',
    'down' => 'Indisponível',
    default => ucfirst($status),
};
?>

<section class="admin-executive-hero">
    <div class="admin-executive-hero-copy">
        <span class="eyebrow">Administração RS Connect</span>
        <h2>Visão executiva da operação</h2>
        <p>Acompanhe clientes, receita, uso da plataforma e pontos que precisam de ação em uma única tela.</p>
    </div>
    <div class="admin-executive-hero-actions">
        <a class="btn btn-primary" href="<?= View::e(Router::url('/companies')) ?>">Gerenciar empresas</a>
        <a class="btn btn-outline" href="<?= View::e(Router::url('/operations')) ?>">Abrir monitoramento</a>
        <a class="btn btn-quiet" href="<?= View::e(Router::url('/?refresh=' . time())) ?>">Atualizar dados</a>
    </div>
    <?php if ($refreshedAt): ?><small class="admin-dashboard-updated">Dados consultados em <?= View::e($dateTime((string) $refreshedAt)) ?><?= $sourceVersion !== '' ? ' · motor ' . View::e($sourceVersion) : '' ?><?= $databaseName !== '' ? ' · banco ' . View::e($databaseName) : '' ?></small><?php endif; ?>
</section>

<?php if ($dataWarnings): ?>
<section class="admin-data-warning" role="alert">
    <div><strong>Alguns dados não puderam ser atualizados.</strong><span>O painel evitou exibir números presumidos. Consulte o log da aplicação ou faça o redeploy completo.</span></div>
    <a class="btn btn-outline btn-small" href="<?= View::e(Router::url('/status-sistema')) ?>">Ver diagnóstico</a>
</section>
<?php endif; ?>

<section class="admin-kpi-grid" aria-label="Indicadores principais">
    <a class="admin-kpi-card" href="<?= View::e(Router::url('/companies?status=active')) ?>">
        <span class="admin-kpi-icon is-teal"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 21V5h10v16M14 9h6v12M8 9h2M8 13h2M8 17h2"/></svg></span>
        <span><small>Empresas ativas</small><strong><?= (int) ($metrics['active_companies'] ?? 0) ?></strong><em>de <?= (int) ($metrics['total_companies'] ?? 0) ?> cadastradas</em></span>
    </a>
    <a class="admin-kpi-card" href="<?= View::e(Router::url('/companies?health=implantation')) ?>">
        <span class="admin-kpi-icon is-blue"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg></span>
        <span><small>Em implantação</small><strong><?= (int) ($metrics['onboarding'] ?? 0) ?></strong><em>configurações em andamento</em></span>
    </a>
    <a class="admin-kpi-card" href="<?= View::e(Router::url('/billing')) ?>">
        <span class="admin-kpi-icon is-purple"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 7.5c0-2-2-3-5-3s-5 1-5 3 2 3 5 3 5 1 5 3-2 3-5 3-5-1-5-3"/></svg></span>
        <span><small>Receita mensal estimada</small><strong><?= View::e($money((float) ($metrics['mrr'] ?? 0))) ?></strong><em><?= (int) ($metrics['active_subscriptions'] ?? 0) ?> assinatura(s) ativa(s)</em></span>
    </a>
    <a class="admin-kpi-card" href="<?= View::e(Router::url('/conversations')) ?>">
        <span class="admin-kpi-icon is-cyan"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 6h14v9H8l-3 3V6Z"/></svg></span>
        <span><small>Mensagens em 24 horas</small><strong><?= number_format((int) ($metrics['messages_24h'] ?? 0), 0, ',', '.') ?></strong><em><?= (int) ($metrics['unread'] ?? 0) ?> pendente(s) de leitura</em></span>
    </a>
    <a class="admin-kpi-card" href="<?= View::e(Router::url('/instances')) ?>">
        <span class="admin-kpi-icon is-green"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="5" width="14" height="14" rx="3"/><path d="M9 9h6v6H9z"/></svg></span>
        <span><small>WhatsApps conectados</small><strong><?= (int) ($metrics['connected_instances'] ?? 0) ?></strong><em>conexões operacionais</em></span>
    </a>
    <a class="admin-kpi-card<?= (int) ($metrics['critical_incidents'] ?? 0) > 0 ? ' is-alert' : '' ?>" href="<?= View::e(Router::url('/operations')) ?>">
        <span class="admin-kpi-icon is-red"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 9v4M12 17h.01"/><path d="M10.3 3.7 2.6 17a2 2 0 0 0 1.7 3h15.4a2 2 0 0 0 1.7-3L13.7 3.7a2 2 0 0 0-3.4 0Z"/></svg></span>
        <span><small>Falhas críticas abertas</small><strong><?= (int) ($metrics['critical_incidents'] ?? 0) ?></strong><em><?= (int) ($metrics['critical_incidents'] ?? 0) > 0 ? 'requer ação imediata' : 'nenhuma falha crítica' ?></em></span>
    </a>
</section>

<section class="admin-quick-actions card">
    <div class="section-heading">
        <div><span class="eyebrow">Ações rápidas</span><h2>O que você precisa fazer agora?</h2></div>
    </div>
    <div class="admin-quick-action-grid">
        <button class="admin-quick-action" type="button" data-toggle-panel="admin-company-create-drawer">
            <span>+</span><strong>Cadastrar empresa</strong><small>Crie o cliente e o primeiro acesso.</small>
        </button>
        <a class="admin-quick-action" href="<?= View::e(Router::url('/instances')) ?>"><span>WA</span><strong>Preparar WhatsApp</strong><small>Cadastre ou recupere uma conexão.</small></a>
        <a class="admin-quick-action" href="<?= View::e(Router::url('/billing')) ?>"><span>R$</span><strong>Gerenciar assinatura</strong><small>Planos, cobranças e vencimentos.</small></a>
        <a class="admin-quick-action" href="<?= View::e(Router::url('/implementation')) ?>"><span>%</span><strong>Acompanhar implantação</strong><small>Veja o avanço de cada cliente.</small></a>
        <a class="admin-quick-action" href="<?= View::e(Router::url('/reports')) ?>"><span>↗</span><strong>Abrir relatórios</strong><small>Uso, IA, agenda e operação.</small></a>
    </div>
</section>

<div class="admin-dashboard-grid">
    <section class="card admin-attention-card">
        <div class="section-heading">
            <div><span class="eyebrow">Prioridades</span><h2>Clientes que precisam de atenção</h2></div>
            <a class="table-link" href="<?= View::e(Router::url('/companies?health=attention')) ?>">Ver empresas</a>
        </div>
        <div class="admin-attention-list">
            <?php foreach ($attentionCompanies as $company): ?>
                <article class="admin-attention-item is-<?= View::e((string) $company['health']) ?>">
                    <div class="admin-attention-avatar"><?= View::e(mb_strtoupper(mb_substr((string) $company['name'], 0, 2))) ?></div>
                    <div class="admin-attention-copy">
                        <div><strong><?= View::e((string) $company['name']) ?></strong><span class="admin-health-badge is-<?= View::e((string) $company['health']) ?>"><?= View::e((string) $company['health_label']) ?></span></div>
                        <p><?= View::e((string) (($company['attention_reasons'][0] ?? 'Revisar configuração da empresa.'))) ?></p>
                        <small>Última atividade: <?= View::e($relative($company['last_activity_at'] ?? null)) ?></small>
                    </div>
                    <a class="btn btn-soft btn-small" href="<?= View::e(Router::url('/companies/overview?id=' . (int) $company['id'])) ?>">Abrir</a>
                </article>
            <?php endforeach; ?>
            <?php if (!$attentionCompanies): ?><div class="empty-state">Nenhum cliente precisa de atenção neste momento.</div><?php endif; ?>
        </div>
    </section>

    <aside class="card admin-health-card">
        <div class="section-heading">
            <div><span class="eyebrow">Saúde da plataforma</span><h2>Serviços essenciais</h2></div>
            <a class="table-link" href="<?= View::e(Router::url('/operations')) ?>">Detalhes</a>
        </div>
        <div class="admin-health-list">
            <?php foreach ($healthChecks as $check): ?>
                <a href="<?= View::e(Router::url('/operations')) ?>" class="admin-health-row">
                    <span class="admin-health-dot is-<?= View::e((string) $check['status']) ?>"></span>
                    <span><strong><?= View::e((string) $check['label']) ?></strong><small><?= View::e((string) ($check['message'] ?? '')) ?></small></span>
                    <em class="admin-health-status is-<?= View::e((string) $check['status']) ?>"><?= View::e($statusLabel((string) $check['status'])) ?></em>
                </a>
            <?php endforeach; ?>
            <?php if (!$healthChecks): ?><div class="empty-state">Execute uma verificação no Monitoramento para carregar a saúde dos serviços.</div><?php endif; ?>
        </div>
    </aside>
</div>

<div class="admin-dashboard-grid admin-dashboard-grid-secondary">
    <section class="card">
        <div class="section-heading">
            <div><span class="eyebrow">Histórico administrativo</span><h2>Últimas correções e atualizações</h2></div>
            <a class="table-link" href="<?= View::e(Router::url('/security')) ?>">Auditoria</a>
        </div>
        <div class="admin-activity-list">
            <?php foreach ($recentActivity as $activity): ?>
                <article class="admin-activity-item">
                    <span class="admin-activity-marker"></span>
                    <div>
                        <strong><?= View::e((string) $activity['label']) ?></strong>
                        <p><?= View::e((string) ($activity['tenant_name'] ?: 'Operação geral')) ?><?= !empty($activity['user_name']) ? ' · ' . View::e((string) $activity['user_name']) : '' ?></p>
                        <?php if (!empty($activity['description'])): ?><small><?= View::e((string) $activity['description']) ?></small><?php endif; ?>
                    </div>
                    <time datetime="<?= View::e((string) $activity['created_at']) ?>" title="<?= View::e($dateTime((string) $activity['created_at'])) ?>"><?= View::e($relative((string) $activity['created_at'])) ?></time>
                </article>
            <?php endforeach; ?>
            <?php if (!$recentActivity): ?><div class="empty-state">Nenhuma atividade administrativa registrada.</div><?php endif; ?>
        </div>
    </section>

    <section class="card">
        <div class="section-heading">
            <div><span class="eyebrow">Clientes recentes</span><h2>Últimas empresas cadastradas</h2></div>
            <a class="table-link" href="<?= View::e(Router::url('/companies')) ?>">Ver todas</a>
        </div>
        <div class="admin-recent-company-list">
            <?php foreach ($recentCompanies as $company): ?>
                <a class="admin-recent-company" href="<?= View::e(Router::url('/companies/overview?id=' . (int) $company['id'])) ?>">
                    <span class="company-avatar"><?= View::e(mb_strtoupper(mb_substr((string) $company['name'], 0, 2))) ?></span>
                    <span><strong><?= View::e((string) $company['name']) ?></strong><small><?= View::e(ucfirst((string) $company['plan'])) ?> · cadastrada em <?= View::e($dateTime((string) ($company['created_at'] ?? ''))) ?></small></span>
                    <span class="admin-health-badge is-<?= View::e((string) $company['health']) ?>"><?= View::e((string) $company['health_label']) ?></span>
                </a>
            <?php endforeach; ?>
            <?php if (!$recentCompanies): ?><div class="empty-state">Nenhuma empresa cadastrada.</div><?php endif; ?>
        </div>
    </section>
</div>

<aside class="conversation-details conversation-drawer admin-company-drawer" id="admin-company-create-drawer" aria-label="Cadastrar nova empresa" aria-modal="true" role="dialog">
    <div class="conversation-drawer-header">
        <div><span class="eyebrow">Novo cliente</span><h2>Cadastrar empresa</h2><p>Crie a empresa e o primeiro acesso administrativo.</p></div>
        <button class="icon-button drawer-close" type="button" data-close-panel="admin-company-create-drawer" aria-label="Fechar painel">×</button>
    </div>
    <div class="conversation-drawer-body">
        <?php require __DIR__ . '/../companies/_create_form.php'; ?>
    </div>
</aside>
