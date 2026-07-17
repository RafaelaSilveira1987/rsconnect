<?php

use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;

$metrics = $securityData ?? [];
$settings = $metrics['settings'] ?? [];
$events = $metrics['events'] ?? [];
$sessions = $metrics['sessions'] ?? [];
$attempts = $metrics['login_attempts'] ?? [];
$warnings = $metrics['api_key_warnings'] ?? [];
$checks = $metrics['checks'] ?? [];
$lockedUsers = $metrics['locked_users'] ?? [];
$access = $metrics['access'] ?? [];
$blockedTenants = $access['blocked_tenants'] ?? [];

$severityClass = static fn (string $severity): string => match ($severity) {
    'critical', 'error' => 'badge-danger',
    'warning' => 'badge-warning',
    default => 'badge-info',
};
$checkClass = static fn (string $status): string => match ($status) {
    'ok' => 'is-ok',
    'error' => 'is-error',
    default => 'is-warning',
};
$sessionLabel = static fn (string $status): string => match ($status) {
    'active' => 'Ativa',
    'revoked' => 'Revogada',
    'expired' => 'Expirada',
    'idle_expired' => 'Expirada por inatividade',
    default => ucfirst($status),
};
$eventLabel = static function (string $event): string {
    return match ($event) {
        'auth.login_success' => 'Login realizado',
        'auth.login_failed' => 'Tentativa de login incorreta',
        'auth.user_temporarily_locked' => 'Usuário bloqueado temporariamente',
        'auth.user_unlocked_by_admin' => 'Usuário desbloqueado pelo administrador',
        'auth.logout' => 'Sessão encerrada',
        'auth.session_expired' => 'Sessão expirada',
        'security.session_revoked' => 'Sessão revogada',
        default => str_starts_with($event, 'access.blocked.') ? 'Acesso bloqueado por regra comercial' : $event,
    };
};
?>

<section class="admin-executive-hero security-hero-v2">
    <div class="admin-executive-hero-copy">
        <span class="eyebrow">Segurança e controle de acesso</span>
        <h2>Auditoria, sessões e proteção do RS Connect</h2>
        <p>Valide acessos, bloqueios de login, vigência das assinaturas, inadimplência e configurações de proteção.</p>
        <small>Validado em <?= View::e($metrics['checked_at'] ?? '-') ?> · motor <?= View::e($metrics['version'] ?? '-') ?> · banco <?= View::e($settings['database'] ?? '-') ?></small>
    </div>
    <div class="admin-executive-hero-actions">
        <a class="btn btn-primary" href="<?= View::e(Router::url('/security?refresh=' . time())) ?>">Atualizar validações</a>
    </div>
</section>

<section class="admin-kpi-grid security-kpis-v2" aria-label="Indicadores de segurança">
    <article class="admin-kpi-card"><span class="admin-kpi-icon is-green">✓</span><span><small>Logins com sucesso 24h</small><strong><?= (int) ($metrics['successful_logins_24h'] ?? 0) ?></strong><em>acessos autenticados</em></span></article>
    <article class="admin-kpi-card"><span class="admin-kpi-icon is-red">!</span><span><small>Tentativas falhas 24h</small><strong><?= (int) ($metrics['failed_logins_24h'] ?? 0) ?></strong><em>limite: <?= (int) ($settings['attempt_limit'] ?? 6) ?> em <?= (int) ($settings['attempt_window'] ?? 15) ?> min</em></span></article>
    <article class="admin-kpi-card"><span class="admin-kpi-icon is-blue">S</span><span><small>Sessões ativas</small><strong><?= (int) ($metrics['active_sessions'] ?? 0) ?></strong><em><?= (int) ($metrics['expired_sessions'] ?? 0) ?> expirada(s)</em></span></article>
    <article class="admin-kpi-card"><span class="admin-kpi-icon is-purple">B</span><span><small>Empresas com acesso bloqueado</small><strong><?= count($blockedTenants) ?></strong><em>vigência, cobrança ou suspensão</em></span></article>
    <article class="admin-kpi-card"><span class="admin-kpi-icon is-cyan">U</span><span><small>Usuários bloqueados</small><strong><?= count($lockedUsers) ?></strong><em>tentativas incorretas</em></span></article>
    <article class="admin-kpi-card"><span class="admin-kpi-icon is-red">E</span><span><small>Eventos críticos 7d</small><strong><?= (int) ($metrics['critical_events_7d'] ?? 0) ?></strong><em>eventos para revisão</em></span></article>
</section>

<div class="admin-dashboard-grid security-validation-grid">
    <section class="card">
        <div class="section-heading"><div><span class="eyebrow">Validações reais</span><h2>Proteções verificadas</h2><p>Os itens abaixo são consultados no ambiente e no banco a cada atualização.</p></div></div>
        <div class="security-validation-list">
            <?php foreach ($checks as $check): ?>
                <article class="security-validation-item <?= $checkClass((string) ($check['status'] ?? 'warning')) ?>">
                    <span class="security-validation-dot"></span>
                    <div><strong><?= View::e($check['label'] ?? '') ?></strong><small><?= View::e($check['detail'] ?? '') ?></small></div>
                </article>
            <?php endforeach; ?>
        </div>
        <div class="security-settings-summary">
            <div><span>Bloqueio de login</span><strong><?= (int) ($settings['attempt_limit'] ?? 6) ?> tentativas / <?= (int) ($settings['attempt_window'] ?? 15) ?> min</strong></div>
            <div><span>Sessão ociosa</span><strong><?= (int) ($settings['idle_minutes'] ?? 120) ?> minutos</strong></div>
            <div><span>Tolerância financeira</span><strong><?= (int) ($settings['invoice_grace_days'] ?? 5) ?> dias após o vencimento</strong></div>
            <div><span>Timezone</span><strong><?= View::e($settings['timezone'] ?? '-') ?></strong></div>
        </div>
        <?php if ($warnings): ?>
            <div class="security-alert warning"><strong>Chaves ou tokens para revisar</strong><p><?= View::e(implode(', ', $warnings)) ?></p></div>
        <?php endif; ?>
    </section>

    <section class="card">
        <div class="section-heading"><div><span class="eyebrow">Controle comercial</span><h2>Empresas com acesso limitado</h2><p>O acesso é liberado automaticamente quando a situação é regularizada.</p></div><span class="badge"><?= count($blockedTenants) ?> empresa(s)</span></div>
        <div class="security-list">
            <?php foreach ($blockedTenants as $tenant): ?>
                <?php
                    $reason = 'Revisar acesso';
                    if (($tenant['status'] ?? 'active') !== 'active') $reason = 'Empresa ' . ($tenant['status'] ?? 'inativa');
                    elseif (in_array($tenant['billing_status'] ?? '', ['suspended', 'canceled'], true)) $reason = 'Assinatura ' . ($tenant['billing_status'] ?? '');
                    elseif (!empty($tenant['overdue_due_date'])) $reason = 'Fatura vencida há ' . (int) ($tenant['overdue_days'] ?? 0) . ' dias';
                    else $reason = 'Vigência encerrada';
                ?>
                <div class="security-row">
                    <div><strong><?= View::e($tenant['name'] ?? 'Empresa') ?></strong><small><?= View::e($reason) ?></small></div>
                    <a class="btn btn-small btn-outline" href="<?= View::e(Router::url('/subscription?tenant_id=' . (int) ($tenant['id'] ?? 0))) ?>">Revisar assinatura</a>
                </div>
            <?php endforeach; ?>
            <?php if (!$blockedTenants): ?><div class="empty-state">Nenhuma empresa está bloqueada pelas regras comerciais.</div><?php endif; ?>
        </div>
    </section>
</div>

<?php if ($lockedUsers): ?>
<section class="card" style="margin-top:16px">
    <div class="section-heading"><div><span class="eyebrow">Bloqueio de login</span><h2>Usuários temporariamente bloqueados</h2><p>O desbloqueio também ocorre automaticamente ao terminar o período configurado.</p></div></div>
    <div class="security-list">
        <?php foreach ($lockedUsers as $user): ?>
            <div class="security-row">
                <div><strong><?= View::e($user['name'] ?? 'Usuário') ?></strong><small><?= View::e($user['email'] ?? '') ?> · <?= (int) ($user['failed_login_count'] ?? 0) ?> tentativa(s) · até <?= View::e($user['locked_until'] ?? '') ?></small></div>
                <form method="post" action="<?= View::e(Router::url('/security/users/unlock')) ?>">
                    <?= Csrf::input() ?><input type="hidden" name="user_id" value="<?= (int) ($user['id'] ?? 0) ?>">
                    <button class="btn btn-small btn-outline" type="submit">Desbloquear</button>
                </form>
            </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<div class="admin-dashboard-grid" style="margin-top:16px">
    <section class="card">
        <div class="section-heading"><div><span class="eyebrow">Sessões</span><h2>Sessões recentes</h2><p>Revogue acessos que não reconhece.</p></div></div>
        <div class="security-list">
            <?php foreach ($sessions as $session): ?>
                <?php $sessionStatus = (string) ($session['session_status'] ?? 'active'); ?>
                <div class="security-row">
                    <div><strong><?= View::e($session['user_name'] ?? 'Usuário') ?></strong><small><?= View::e($session['email'] ?? '') ?> · <?= View::e($session['ip_address'] ?? '') ?> · visto em <?= View::e($session['last_seen_at'] ?? '') ?></small></div>
                    <div class="security-row-actions">
                        <span class="badge <?= $sessionStatus === 'active' ? 'badge-success' : 'badge-warning' ?>"><?= View::e($sessionLabel($sessionStatus)) ?></span>
                        <?php if ($sessionStatus === 'active'): ?>
                            <form method="post" action="<?= View::e(Router::url('/security/sessions/revoke')) ?>"><?= Csrf::input() ?><input type="hidden" name="session_id" value="<?= View::e($session['session_id']) ?>"><button class="btn btn-small btn-outline" type="submit">Revogar</button></form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if (!$sessions): ?><div class="empty-state">Nenhuma sessão registrada.</div><?php endif; ?>
        </div>
    </section>

    <section class="card">
        <div class="section-heading"><div><span class="eyebrow">Tentativas de acesso</span><h2>Logins recentes</h2><p>Histórico real das últimas tentativas.</p></div></div>
        <div class="security-login-list">
            <?php foreach ($attempts as $attempt): ?>
                <article class="security-login-item">
                    <span class="badge <?= (int) ($attempt['success'] ?? 0) === 1 ? 'badge-success' : 'badge-warning' ?>"><?= (int) ($attempt['success'] ?? 0) === 1 ? 'Sucesso' : 'Falha' ?></span>
                    <div><strong><?= View::e($attempt['email'] ?? '') ?></strong><small><?= View::e($attempt['ip_address'] ?? '') ?> · <?= View::e($attempt['created_at'] ?? '') ?> · <?= View::e($attempt['reason'] ?? '') ?></small></div>
                </article>
            <?php endforeach; ?>
            <?php if (!$attempts): ?><div class="empty-state">Nenhuma tentativa registrada.</div><?php endif; ?>
        </div>
    </section>
</div>

<section class="card" style="margin-top:16px">
    <div class="section-heading"><div><span class="eyebrow">Auditoria</span><h2>Eventos de segurança</h2><p>Últimos eventos relevantes registrados pelo sistema.</p></div></div>
    <div class="security-timeline">
        <?php foreach ($events as $event): ?>
            <article class="security-event">
                <span class="badge <?= $severityClass((string) ($event['severity'] ?? 'info')) ?>"><?= View::e($event['severity'] ?? 'info') ?></span>
                <div><strong><?= View::e($eventLabel((string) ($event['event'] ?? ''))) ?></strong><p><?= View::e($event['tenant_name'] ?? 'Operação RS') ?> · <?= View::e($event['user_name'] ?? 'sistema') ?> · <?= View::e($event['ip_address'] ?? '') ?></p><small><?= View::e($event['created_at'] ?? '') ?></small></div>
            </article>
        <?php endforeach; ?>
        <?php if (!$events): ?><div class="empty-state">Nenhum evento de segurança registrado.</div><?php endif; ?>
    </div>
</section>
