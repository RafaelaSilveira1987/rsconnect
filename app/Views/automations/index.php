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

<div class="automation-page">
    <section class="metric-grid-compact">
        <article class="metric-card"><span>IA configurada</span><strong><?= ($openaiConfigured || $geminiConfigured) ? 'Sim' : 'Ver credenciais' ?></strong><small><?= $openaiConfigured ? 'OPENAI_API_KEY global encontrada' : ($geminiConfigured ? 'GEMINI_API_KEY global encontrada' : 'Use .env ou Credenciais de IA') ?></small></article>
        <article class="metric-card"><span>Resposta automática</span><strong><?= $autoReplyEnabled ? 'Ativa' : 'Global off' ?></strong><small>Controle por agente em Agentes de IA</small></article>
        <article class="metric-card"><span>n8n</span><strong><?= $n8nConfigured ? 'Ativo' : 'Opcional' ?></strong><small><?= (int) ($tenantN8nFlows ?? 0) > 0 ? ((int) $tenantN8nFlows . ' fluxo(s) por empresa') : 'Configure em Fluxos n8n no painel RS' ?></small></article>
        <article class="metric-card"><span>Execuções</span><strong><?= array_sum($stats) ?></strong><small><?= (int) ($stats['success'] ?? 0) ?> sucesso · <?= (int) ($stats['error'] ?? 0) ?> erro</small></article>
    </section>

    <section class="card automation-guide">
        <div class="section-heading"><div><span class="eyebrow">Próximos passos</span><h2>Checklist da IA</h2></div></div>
        <div class="checklist-grid">
            <div><b>1</b><span>Configure <code>OPENAI_API_KEY</code> global ou cadastre a chave do cliente em <code>Credenciais de IA</code>.</span></div>
            <div><b>2</b><span>Abra <a href="<?= View::e(Router::url('/agents')) ?>">Agentes de IA</a> e marque “Responder automaticamente”, horário e regras de transferência.</span></div>
            <div><b>3</b><span>Deixe a conversa em modo <strong>IA ativa</strong>. Se humano assumir, a IA para.</span></div>
            <div><b>4</b><span>Configure fluxos externos por empresa em <code>Fluxos n8n</code> quando precisar integrar agenda, CRM ou pós-venda.</span></div>
        </div>
    </section>

    <section class="card table-card">
        <div class="section-heading"><div><span class="eyebrow">Monitoramento</span><h2>Logs de IA e automações</h2></div><span class="badge"><?= count($logs) ?> registros</span></div>
        <div class="table-wrap">
            <table class="clean-table">
                <thead><tr><th>Data</th><?php if (Auth::isSuperAdmin()): ?><th>Empresa</th><?php endif; ?><th>Contato</th><th>Agente</th><th>Evento</th><th>Status</th><th>Detalhe</th></tr></thead>
                <tbody>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td><?= View::e($formatDate($log['created_at'])) ?></td>
                        <?php if (Auth::isSuperAdmin()): ?><td><?= View::e($log['tenant_name'] ?? '—') ?></td><?php endif; ?>
                        <td><strong><?= View::e($log['contact_name'] ?: ($log['phone'] ?? '—')) ?></strong><small><?= View::e($log['phone'] ?? '') ?></small></td>
                        <td><?= View::e($log['agent_name'] ?? '—') ?></td>
                        <td><?= View::e($log['event']) ?></td>
                        <td><span class="badge badge-<?= View::e($log['status']) ?>"><?= View::e($statusLabel[$log['status']] ?? $log['status']) ?></span></td>
                        <td class="automation-detail"><?= View::e($log['error_message'] ?: ($log['response_preview'] ?: '—')) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$logs): ?><tr><td colspan="7"><div class="empty-state">Nenhum log de automação ainda.</div></td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
