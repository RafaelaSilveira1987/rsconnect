<?php

use App\Core\Auth;
use App\Core\Router;
use App\Core\View;

$statusLabel = [
    'success' => 'Sucesso',
    'error' => 'Erro',
    'skipped' => 'Ignorado',
];
$formatDate = static function (?string $date): string {
    if (!$date) {
        return '—';
    }
    $timestamp = strtotime($date);
    return $timestamp ? date('d/m/Y H:i:s', $timestamp) : $date;
};
?>

<div class="automation-page automation-page-pro">
    <section class="metric-strip automation-strip">
        <article class="metric-card compact-metric"><span>IA configurada</span><strong><?= ($openaiConfigured || $geminiConfigured) ? 'Sim' : 'Pendente' ?></strong><small><?= $openaiConfigured ? 'OpenAI global configurada' : ($geminiConfigured ? 'Gemini global configurada' : 'Use .env ou Credenciais de IA') ?></small></article>
        <article class="metric-card compact-metric"><span>Resposta automática</span><strong><?= $autoReplyEnabled ? 'Ativa' : 'Inativa' ?></strong><small>Controle final em Agentes de IA</small></article>
        <article class="metric-card compact-metric"><span>Fluxos n8n</span><strong><?= $n8nConfigured ? 'Ativos' : 'Opcional' ?></strong><small><?= (int) ($tenantN8nFlows ?? 0) > 0 ? ((int) $tenantN8nFlows . ' fluxo(s) por empresa') : 'Configure no painel RS' ?></small></article>
        <article class="metric-card compact-metric"><span>Execuções</span><strong><?= array_sum($stats) ?></strong><small><?= (int) ($stats['success'] ?? 0) ?> sucesso · <?= (int) ($stats['error'] ?? 0) ?> erro</small></article>
    </section>

    <details class="help-panel card">
        <summary><span class="help-icon">?</span><div><strong>Checklist da IA</strong><small>Abra apenas quando precisar revisar a configuração.</small></div></summary>
        <div class="checklist-grid help-checklist">
            <div><b>1</b><span>Configure <code>OPENAI_API_KEY</code> global ou cadastre a chave do cliente em <code>Credenciais de IA</code>.</span></div>
            <div><b>2</b><span>Abra <a href="<?= View::e(Router::url('/agents')) ?>">Agentes de IA</a> e marque “Responder automaticamente”, horário e regras de transferência.</span></div>
            <div><b>3</b><span>Deixe a conversa em modo <strong>IA ativa</strong>. Se humano assumir, a IA para.</span></div>
            <div><b>4</b><span>Configure fluxos externos por empresa em <code>Fluxos n8n</code> quando precisar integrar agenda, CRM ou pós-venda.</span></div>
        </div>
    </details>

    <section class="card automation-log-card">
        <div class="section-heading"><div><span class="eyebrow">Monitoramento</span><h2>Logs de IA e automações</h2></div><span class="badge"><?= count($logs) ?> registros</span></div>
        <div class="automation-log-list">
            <?php foreach ($logs as $log): ?>
                <article class="automation-log-item log-<?= View::e($log['status']) ?>">
                    <div class="log-status-marker"></div>
                    <div class="log-main">
                        <div class="log-title-row">
                            <strong><?= View::e($log['event']) ?></strong>
                            <span class="badge badge-<?= View::e($log['status']) ?>"><?= View::e($statusLabel[$log['status']] ?? $log['status']) ?></span>
                        </div>
                        <p><?= View::e($log['error_message'] ?: ($log['response_preview'] ?: 'Sem detalhe retornado.')) ?></p>
                        <div class="log-meta-row">
                            <span><?= View::e($formatDate($log['created_at'])) ?></span>
                            <?php if (Auth::isSuperAdmin()): ?><span><?= View::e($log['tenant_name'] ?? 'Empresa não informada') ?></span><?php endif; ?>
                            <span><?= View::e($log['agent_name'] ?? 'Agente não informado') ?></span>
                            <span><?= View::e($log['contact_name'] ?: ($log['phone'] ?? 'Contato não informado')) ?></span>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
            <?php if (!$logs): ?><div class="empty-state">Nenhum log de automação ainda.</div><?php endif; ?>
        </div>
    </section>
</div>
