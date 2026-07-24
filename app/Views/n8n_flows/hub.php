<?php
use App\Core\Router;
use App\Core\View;
$metrics = $metrics ?? [];
$recentLogs = $recentLogs ?? [];
$formatDate = static function (?string $value): string {
    if (!$value || !($timestamp = strtotime($value))) return '—';
    return date('d/m/Y H:i', $timestamp);
};
require __DIR__ . '/_nav.php';
?>
<section class="admin-module-hero n8n-hub-hero">
    <div><span class="eyebrow">Automação e integrações</span><h2>n8n</h2><p>Fluxos, templates e evidências de execução reunidos em um único módulo.</p></div>
    <div class="hero-actions"><a class="btn btn-primary" href="<?= View::e(Router::url('/n8n-flows')) ?>">Gerenciar fluxos</a><a class="btn btn-quiet" href="<?= View::e(Router::url('/n8n-templates')) ?>">Abrir templates</a></div>
</section>
<section class="admin-module-summary n8n-hub-summary">
    <article><span>Fluxos</span><strong><?= (int) ($metrics['flows_total'] ?? 0) ?></strong><small>configurados</small></article>
    <article class="is-success"><span>Ativos</span><strong><?= (int) ($metrics['flows_active'] ?? 0) ?></strong><small>recebendo eventos</small></article>
    <article class="is-blue"><span>Empresas</span><strong><?= (int) ($metrics['tenants_covered'] ?? 0) ?></strong><small>com fluxo ativo</small></article>
    <article class="<?= (int) ($metrics['errors_24h'] ?? 0) > 0 ? 'is-warning' : 'is-success' ?>"><span>Execuções 24h</span><strong><?= (int) ($metrics['executions_24h'] ?? 0) ?></strong><small><?= (int) ($metrics['success_24h'] ?? 0) ?> sucesso(s) · <?= (int) ($metrics['errors_24h'] ?? 0) ?> erro(s)</small></article>
</section>
<div class="n8n-hub-grid">
    <a class="card n8n-hub-card" href="<?= View::e(Router::url('/n8n-flows')) ?>"><span class="eyebrow">Operação</span><h3>Fluxos por empresa</h3><p>Cadastre webhooks, eventos, tokens e teste cada integração diretamente pelo RS Connect.</p><strong>Abrir fluxos →</strong></a>
    <a class="card n8n-hub-card" href="<?= View::e(Router::url('/n8n-templates')) ?>"><span class="eyebrow">Biblioteca</span><h3>Templates n8n</h3><p>Agenda, cobrança, backup e rotinas prontas para importar e adaptar no n8n.</p><strong>Abrir templates →</strong></a>
</div>
<section class="card n8n-hub-history">
    <div class="section-heading"><div><span class="eyebrow">Evidência operacional</span><h2>Execuções recentes</h2><p>Últimos retornos registrados pelos fluxos configurados.</p></div><span class="badge"><?= count($recentLogs) ?> registro(s)</span></div>
    <div class="admin-log-list">
        <?php foreach ($recentLogs as $log): ?>
            <article class="admin-log-item is-<?= View::e((string) ($log['status'] ?? 'info')) ?>">
                <div><strong><?= View::e((string) ($log['flow_name'] ?? 'Fluxo não identificado')) ?></strong><span><?= View::e((string) ($log['tenant_name'] ?? 'Empresa')) ?> · <?= View::e((string) ($log['event'] ?? 'evento')) ?></span><small><?= View::e($formatDate($log['created_at'] ?? null)) ?> · HTTP <?= View::e((string) ($log['http_status'] ?? '—')) ?></small></div>
                <span class="badge badge-<?= View::e((string) ($log['status'] ?? 'info')) ?>"><?= View::e((string) ($log['status'] ?? 'info')) ?></span>
            </article>
        <?php endforeach; ?>
        <?php if (!$recentLogs): ?><div class="empty-state">Nenhuma execução registrada ainda.</div><?php endif; ?>
    </div>
</section>
