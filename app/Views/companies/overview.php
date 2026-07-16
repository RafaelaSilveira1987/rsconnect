<?php

use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;

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
$date = static function (?string $value): string {
    if (!$value) return 'Não informado';
    $ts = strtotime($value);
    return $ts ? date('d/m/Y', $ts) : $value;
};
$money = static fn (float $value): string => 'R$ ' . number_format($value, 2, ',', '.');
$subscriptionLabel = static fn (string $status): string => match ($status) {
    'trialing' => 'Em teste',
    'active' => 'Ativa',
    'overdue' => 'Em atraso',
    'suspended' => 'Suspensa',
    'canceled' => 'Cancelada',
    default => 'Sem assinatura',
};
$roleLabel = static fn (string $role): string => match ($role) {
    'client_admin' => 'Administrador',
    'client_user' => 'Equipe',
    'super_admin' => 'Super Admin',
    default => ucfirst($role),
};
$tenantId = (int) $company['id'];
$subscription = $company['subscription'] ?? [];
$implementationPercent = (int) ($company['implementation']['percent_complete'] ?? ($company['onboarding_completed_at'] ? 100 : 0));
$instanceTotal = (int) ($company['instances']['total'] ?? 0);
$connectedInstances = (int) ($company['instances']['connected_count'] ?? 0);
$activeAgents = (int) ($company['agents']['active_count'] ?? 0);
?>

<nav class="admin-breadcrumb" aria-label="Navegação"><a href="<?= View::e(Router::url('/companies')) ?>">Empresas</a><span>›</span><strong><?= View::e((string) $company['name']) ?></strong></nav>

<section class="admin-company-overview-hero is-<?= View::e((string) $company['health']) ?>">
    <div class="admin-company-overview-identity">
        <span class="company-avatar is-large"><?= View::e(mb_strtoupper(mb_substr((string) $company['name'], 0, 2))) ?></span>
        <div>
            <span class="eyebrow">Visão geral do cliente</span>
            <div class="admin-company-title-row"><h2><?= View::e((string) $company['name']) ?></h2><span class="admin-health-badge is-<?= View::e((string) $company['health']) ?>"><?= View::e((string) $company['health_label']) ?></span></div>
            <p><?= View::e((string) ($company['segment'] ?: 'Segmento não informado')) ?><?= !empty($company['email']) ? ' · ' . View::e((string) $company['email']) : '' ?><?= !empty($company['phone']) ? ' · ' . View::e((string) $company['phone']) : '' ?></p>
            <div class="badge-row"><span class="badge"><?= View::e(ucfirst((string) $company['plan'])) ?></span><span class="badge badge-<?= View::e((string) $company['status']) ?>"><?= View::e(ucfirst((string) $company['status'])) ?></span><span class="badge"><?= View::e($subscriptionLabel((string) ($subscription['billing_status'] ?? ''))) ?></span></div>
        </div>
    </div>
    <div class="admin-company-overview-actions">
        <a class="btn btn-primary" href="<?= View::e(Router::url('/company-settings?id=' . $tenantId)) ?>">Editar empresa</a>
        <a class="btn btn-outline" href="<?= View::e(Router::url('/implementation?tenant_id=' . $tenantId)) ?>">Ver implantação</a>
        <a class="btn btn-quiet" href="<?= View::e(Router::url('/conversations?tenant_id=' . $tenantId)) ?>">Abrir conversas</a>
    </div>
</section>

<section class="admin-company-overview-kpis">
    <article><span>Implantação</span><strong><?= $implementationPercent ?>%</strong><div class="admin-progress"><i style="width: <?= min(100, max(0, $implementationPercent)) ?>%"></i></div><small><?= $company['onboarding_completed_at'] ? 'Configuração concluída' : 'Ainda em andamento' ?></small></article>
    <article><span>WhatsApp</span><strong><?= $connectedInstances ?>/<?= $instanceTotal ?></strong><small><?= $connectedInstances > 0 ? 'conexão ativa' : 'nenhuma conexão ativa' ?></small></article>
    <article><span>Assistentes</span><strong><?= $activeAgents ?></strong><small><?= (int) ($company['agents']['auto_reply_count'] ?? 0) ?> com respostas automáticas</small></article>
    <article><span>Mensagens em 30 dias</span><strong><?= number_format((int) ($company['messages']['count_30d'] ?? 0), 0, ',', '.') ?></strong><small><?= (int) ($company['conversations']['unread_count'] ?? 0) ?> pendente(s) de leitura</small></article>
    <article><span>Última atividade</span><strong class="is-date"><?= View::e($relative($company['last_activity_at'] ?? null)) ?></strong><small><?= (int) ($company['conversations']['open_count'] ?? 0) ?> conversa(s) aberta(s)</small></article>
</section>

<?php if (!empty($company['attention_reasons'])): ?>
<section class="card admin-company-alert-panel is-<?= View::e((string) $company['health']) ?>">
    <div class="section-heading"><div><span class="eyebrow">Atenção necessária</span><h2>Pontos para revisar</h2></div></div>
    <div class="admin-company-alert-list">
        <?php foreach ((array) $company['attention_reasons'] as $reason): ?><div><span>!</span><p><?= View::e((string) $reason) ?></p></div><?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<div class="admin-company-overview-grid">
    <section class="card">
        <div class="section-heading"><div><span class="eyebrow">Conta e cobrança</span><h2>Plano e assinatura</h2></div><a class="table-link" href="<?= View::e(Router::url('/billing?tenant_id=' . $tenantId)) ?>">Gerenciar</a></div>
        <div class="admin-company-detail-list">
            <div><span>Plano cadastrado</span><strong><?= View::e(ucfirst((string) $company['plan'])) ?></strong></div>
            <div><span>Situação da assinatura</span><strong><?= View::e($subscriptionLabel((string) ($subscription['billing_status'] ?? ''))) ?></strong></div>
            <div><span>Valor contratado</span><strong><?= !empty($subscription) ? View::e($money((float) ($subscription['amount'] ?? 0))) : 'Não informado' ?></strong></div>
            <div><span>Próxima cobrança</span><strong><?= View::e($date($subscription['next_billing_at'] ?? null)) ?></strong></div>
            <div><span>Cobranças vencidas</span><strong><?= (int) ($company['invoices']['overdue_count'] ?? 0) ?></strong></div>
        </div>
        <details class="admin-inline-edit">
            <summary>Alterar plano ou status</summary>
            <form method="post" action="<?= View::e(Router::url('/companies/status')) ?>">
                <?= Csrf::input() ?><input type="hidden" name="tenant_id" value="<?= $tenantId ?>">
                <label class="field"><span>Plano</span><select name="plan"><?php foreach (['starter' => 'Starter', 'pro' => 'Profissional', 'business' => 'Business', 'custom' => 'Personalizado'] as $value => $label): ?><option value="<?= $value ?>" <?= $company['plan'] === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label>
                <label class="field"><span>Status</span><select name="status"><?php foreach (['active' => 'Ativa', 'inactive' => 'Inativa', 'suspended' => 'Suspensa'] as $value => $label): ?><option value="<?= $value ?>" <?= $company['status'] === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label>
                <button class="btn btn-primary" type="submit">Salvar</button>
            </form>
        </details>
    </section>

    <section class="card">
        <div class="section-heading"><div><span class="eyebrow">Operação</span><h2>Uso da plataforma</h2></div></div>
        <div class="admin-company-detail-list">
            <div><span>Usuários ativos</span><strong><?= (int) ($company['users']['active_count'] ?? 0) ?></strong></div>
            <div><span>Conexões cadastradas</span><strong><?= $instanceTotal ?></strong></div>
            <div><span>Conversas totais</span><strong><?= number_format((int) ($company['conversations']['total'] ?? 0), 0, ',', '.') ?></strong></div>
            <div><span>Mensagens em 24 horas</span><strong><?= number_format((int) ($company['messages']['count_24h'] ?? 0), 0, ',', '.') ?></strong></div>
            <div><span>Falhas de IA em 7 dias</span><strong><?= (int) ($company['ai_errors']['error_count'] ?? 0) ?></strong></div>
            <div><span>Falhas de integração em 7 dias</span><strong><?= (int) ($company['n8n_errors']['error_count'] ?? 0) ?></strong></div>
        </div>
    </section>
</div>

<section class="card admin-company-module-card">
    <div class="section-heading"><div><span class="eyebrow">Administração do cliente</span><h2>Acessos rápidos</h2></div></div>
    <div class="admin-company-module-grid">
        <a href="<?= View::e(Router::url('/company-settings?id=' . $tenantId)) ?>"><span>01</span><strong>Dados da empresa</strong><small>Cadastro, contato e informações usadas pela IA.</small></a>
        <a href="<?= View::e(Router::url('/instances')) ?>"><span>02</span><strong>Conexões WhatsApp</strong><small>Prepare, atualize ou recupere a conexão.</small></a>
        <a href="<?= View::e(Router::url('/agents')) ?>"><span>03</span><strong>Assistentes virtuais</strong><small>Revise vínculo, instruções e respostas automáticas.</small></a>
        <a href="<?= View::e(Router::url('/billing?tenant_id=' . $tenantId)) ?>"><span>04</span><strong>Assinatura e cobrança</strong><small>Plano, faturas, vencimentos e pagamentos.</small></a>
        <a href="<?= View::e(Router::url('/implementation?tenant_id=' . $tenantId)) ?>"><span>05</span><strong>Implantação</strong><small>Acompanhe o checklist e as pendências.</small></a>
        <a href="<?= View::e(Router::url('/reports?tenant_id=' . $tenantId)) ?>"><span>06</span><strong>Relatórios da empresa</strong><small>Uso, conversas, IA, CRM e agenda.</small></a>
        <a href="<?= View::e(Router::url('/calendar?tenant_id=' . $tenantId)) ?>"><span>07</span><strong>Agenda</strong><small>Compromissos, disponibilidade e pré-agendamentos.</small></a>
        <a href="<?= View::e(Router::url('/n8n-flows')) ?>"><span>08</span><strong>Integrações</strong><small>Fluxos n8n e comunicação com serviços externos.</small></a>
    </div>
</section>

<div class="admin-company-overview-grid">
    <section class="card">
        <div class="section-heading"><div><span class="eyebrow">Equipe</span><h2>Últimos acessos cadastrados</h2></div><a class="table-link" href="<?= View::e(Router::url('/users')) ?>">Gerenciar usuários</a></div>
        <div class="admin-company-user-list">
            <?php foreach (($company['latest_users'] ?? []) as $user): ?>
                <article><span class="avatar"><?= View::e(mb_strtoupper(mb_substr((string) $user['name'], 0, 1))) ?></span><div><strong><?= View::e((string) $user['name']) ?></strong><small><?= View::e((string) $user['email']) ?> · <?= View::e($roleLabel((string) $user['role'])) ?></small></div><em class="badge badge-<?= View::e((string) $user['status']) ?>"><?= View::e(ucfirst((string) $user['status'])) ?></em></article>
            <?php endforeach; ?>
            <?php if (empty($company['latest_users'])): ?><div class="empty-state">Nenhum usuário cadastrado.</div><?php endif; ?>
        </div>
    </section>

    <section class="card">
        <div class="section-heading"><div><span class="eyebrow">Histórico</span><h2>Atividade administrativa</h2></div></div>
        <div class="admin-activity-list">
            <?php foreach (($company['recent_activity'] ?? []) as $activity): ?>
                <article class="admin-activity-item"><span class="admin-activity-marker"></span><div><strong><?= View::e((string) $activity['label']) ?></strong><p><?= View::e((string) ($activity['user_name'] ?: 'Sistema')) ?></p></div><time><?= View::e($relative((string) $activity['created_at'])) ?></time></article>
            <?php endforeach; ?>
            <?php if (empty($company['recent_activity'])): ?><div class="empty-state">Nenhuma atividade administrativa registrada.</div><?php endif; ?>
        </div>
    </section>
</div>

<?php if (!empty($company['recent_failures'])): ?>
<section class="card admin-company-failure-card">
    <div class="section-heading"><div><span class="eyebrow">Falhas recentes</span><h2>IA e integrações</h2></div><a class="table-link" href="<?= View::e(Router::url('/automations')) ?>">Abrir monitoramento do cliente</a></div>
    <div class="admin-company-failure-list">
        <?php foreach ($company['recent_failures'] as $failure): ?><article><span class="admin-health-dot is-warning"></span><div><strong><?= ($failure['source'] ?? '') === 'ia' ? 'Assistente virtual' : 'Integração' ?></strong><p><?= View::e((string) ($failure['message'] ?: $failure['event'])) ?></p></div><time><?= View::e($relative((string) $failure['created_at'])) ?></time></article><?php endforeach; ?>
    </div>
</section>
<?php endif; ?>
