<?php

use App\Core\Csrf;
use App\Core\View;

$metrics = $data ?? [];
$settings = $metrics['settings'] ?? [];
$events = $metrics['events'] ?? [];
$sessions = $metrics['sessions'] ?? [];
$attempts = $metrics['login_attempts'] ?? [];
$warnings = $metrics['api_key_warnings'] ?? [];
$severityClass = static fn (string $severity): string => match ($severity) {
    'critical', 'error' => 'badge-danger',
    'warning' => 'badge-warning',
    default => 'badge-info',
};
?>

<section class="hero-card hero-admin security-hero">
    <div>
        <span class="eyebrow light">Segurança operacional</span>
        <h2>Auditoria, sessões e proteção do RS Connect.</h2>
        <p>Monitore acessos, tentativas falhas, sessões ativas, webhooks e sinais de configuração insegura antes de escalar o SaaS.</p>
    </div>
    <div class="hero-actions">
        <span class="badge badge-success">Headers ativos</span>
        <span class="badge <?= !empty($settings['webhook_strict']) ? 'badge-success' : 'badge-warning' ?>">Webhook strict: <?= !empty($settings['webhook_strict']) ? 'ativo' : 'desativado' ?></span>
    </div>
</section>

<div class="report-kpi-grid security-kpis">
    <article class="card report-kpi"><span>Logins com sucesso 24h</span><strong><?= (int) ($metrics['successful_logins_24h'] ?? 0) ?></strong><small>Acessos autenticados</small></article>
    <article class="card report-kpi"><span>Tentativas falhas 24h</span><strong><?= (int) ($metrics['failed_logins_24h'] ?? 0) ?></strong><small>Bloqueio por limite ativo</small></article>
    <article class="card report-kpi"><span>Sessões ativas</span><strong><?= (int) ($metrics['active_sessions'] ?? 0) ?></strong><small>Vistas nas últimas 2 horas</small></article>
    <article class="card report-kpi"><span>Eventos críticos 7d</span><strong><?= (int) ($metrics['critical_events_7d'] ?? 0) ?></strong><small>Revisar se maior que zero</small></article>
</div>

<div class="report-grid" style="margin-top:16px">
    <section class="card">
        <div class="section-heading">
            <div><span class="eyebrow">Checklist</span><h2>Configurações recomendadas</h2></div>
        </div>
        <div class="security-checklist">
            <div class="security-check"><strong>Limite de login</strong><span><?= (int) ($settings['attempt_limit'] ?? 6) ?> tentativa(s) em <?= (int) ($settings['attempt_window'] ?? 15) ?> minuto(s)</span></div>
            <div class="security-check"><strong>Sessão ociosa</strong><span><?= (int) ($settings['idle_minutes'] ?? 120) ?> minuto(s)</span></div>
            <div class="security-check"><strong>Headers de segurança</strong><span><?= !empty($settings['headers_enabled']) ? 'Ativos' : 'Desativados' ?></span></div>
            <div class="security-check"><strong>Webhooks com token obrigatório</strong><span><?= !empty($settings['webhook_strict']) ? 'Ativo' : 'Desativado por enquanto' ?></span></div>
        </div>
        <?php if ($warnings): ?>
            <div class="security-alert warning">
                <strong>Chaves/tokens para revisar</strong>
                <p>Revise ou rotacione: <?= View::e(implode(', ', $warnings)) ?>.</p>
            </div>
        <?php else: ?>
            <div class="security-alert ok"><strong>Chaves principais configuradas</strong><p>Nenhum token vazio/fraco foi detectado nas variáveis principais.</p></div>
        <?php endif; ?>
    </section>

    <section class="card">
        <div class="section-heading"><div><span class="eyebrow">Sessões</span><h2>Sessões recentes</h2></div></div>
        <div class="security-list">
            <?php foreach ($sessions as $session): ?>
                <div class="security-row">
                    <div>
                        <strong><?= View::e($session['user_name'] ?? 'Usuário') ?></strong>
                        <small><?= View::e($session['email'] ?? '') ?> · <?= View::e($session['ip_address'] ?? '') ?> · <?= View::e($session['last_seen_at'] ?? '') ?></small>
                    </div>
                    <?php if (empty($session['revoked_at'])): ?>
                        <form method="post" action="<?= View::e(\App\Core\Router::url('/security/sessions/revoke')) ?>">
                            <?= Csrf::input() ?>
                            <input type="hidden" name="session_id" value="<?= View::e($session['session_id']) ?>">
                            <button class="btn btn-small btn-outline" type="submit">Revogar</button>
                        </form>
                    <?php else: ?>
                        <span class="badge badge-danger">Revogada</span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <?php if (!$sessions): ?><div class="empty-state">Nenhuma sessão registrada ainda.</div><?php endif; ?>
        </div>
    </section>
</div>

<div class="report-grid" style="margin-top:16px">
    <section class="card">
        <div class="section-heading"><div><span class="eyebrow">Auditoria</span><h2>Eventos de segurança</h2></div></div>
        <div class="security-timeline">
            <?php foreach ($events as $event): ?>
                <article class="security-event">
                    <span class="badge <?= $severityClass((string) ($event['severity'] ?? 'info')) ?>"><?= View::e($event['severity'] ?? 'info') ?></span>
                    <div>
                        <strong><?= View::e($event['event'] ?? '') ?></strong>
                        <p><?= View::e($event['tenant_name'] ?? 'Operação RS') ?> · <?= View::e($event['user_name'] ?? 'sistema') ?> · <?= View::e($event['ip_address'] ?? '') ?></p>
                        <small><?= View::e($event['created_at'] ?? '') ?></small>
                    </div>
                </article>
            <?php endforeach; ?>
            <?php if (!$events): ?><div class="empty-state">Nenhum evento de segurança registrado ainda.</div><?php endif; ?>
        </div>
    </section>

    <section class="card">
        <div class="section-heading"><div><span class="eyebrow">Acessos</span><h2>Tentativas de login</h2></div></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Data</th><th>E-mail</th><th>IP</th><th>Status</th></tr></thead>
                <tbody>
                    <?php foreach ($attempts as $attempt): ?>
                        <tr>
                            <td><?= View::e($attempt['created_at'] ?? '') ?></td>
                            <td><?= View::e($attempt['email'] ?? '') ?></td>
                            <td><?= View::e($attempt['ip_address'] ?? '') ?></td>
                            <td><span class="badge <?= (int) ($attempt['success'] ?? 0) === 1 ? 'badge-success' : 'badge-warning' ?>"><?= (int) ($attempt['success'] ?? 0) === 1 ? 'sucesso' : 'falha' ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$attempts): ?><tr><td colspan="4"><div class="empty-state">Nenhuma tentativa registrada ainda.</div></td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
