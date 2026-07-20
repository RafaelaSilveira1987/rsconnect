<?php

use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;

$data = $aiReprocessData ?? [];
$settings = $data['settings'] ?? [];
$pending = $data['pending'] ?? [];
$history = $data['history'] ?? [];
$lastSummary = $settings['last_summary'] ?? [];
$formatDate = static function (?string $value): string {
    if (!$value || !($timestamp = strtotime($value))) return 'Ainda não executado';
    return date('d/m/Y H:i', $timestamp);
};
$statusLabel = static function (string $status): string {
    return [
        'success' => 'Concluída',
        'partial' => 'Concluída com atenção',
        'error' => 'Falhou',
        'running' => 'Em execução',
        'skipped' => 'Ignorada',
    ][$status] ?? ucfirst($status ?: 'Sem registro');
};
?>

<?php if (!empty($data['migration_required'])): ?>
<section class="card operations-alert is-warning">
    <strong>Atualização do banco necessária</strong>
    <p>Execute <code><?= View::e((string) ($data['migration'] ?? 'database/migrations/044_ai_pending_failures_message_link.sql')) ?></code> antes de usar esta rotina.</p>
</section>
<?php else: ?>
<section class="admin-module-summary">
    <article class="<?= (int) ($data['pending_total'] ?? 0) > 0 ? 'is-warning' : 'is-success' ?>">
        <span>Mensagens presas</span>
        <strong><?= (int) ($data['pending_total'] ?? 0) ?></strong>
        <small>intervalo, falha ou execução interrompida</small>
    </article>
    <article class="<?= !empty($settings['enabled']) ? 'is-success' : 'is-warning' ?>">
        <span>Rotina automática</span>
        <strong><?= !empty($settings['enabled']) ? 'Ativa' : 'Desativada' ?></strong>
        <small><?= View::e((string) ($settings['run_time'] ?? '03:00')) ?> · <?= View::e((string) ($settings['timezone'] ?? 'America/Sao_Paulo')) ?></small>
    </article>
    <article class="is-blue">
        <span>Última execução</span>
        <strong><?= View::e($statusLabel((string) ($settings['last_run_status'] ?? ''))) ?></strong>
        <small><?= View::e($formatDate($settings['last_run_at'] ?? null)) ?></small>
    </article>
    <article>
        <span>Último resultado</span>
        <strong><?= (int) ($lastSummary['replied'] ?? 0) ?> resposta(s)</strong>
        <small><?= (int) ($lastSummary['attempted'] ?? 0) ?> item(ns) reavaliado(s)</small>
    </article>
</section>

<div class="operations-grid">
    <section class="card">
        <div class="section-heading">
            <div>
                <span class="eyebrow">Agendamento geral</span>
                <h2>Reprocessar fila da IA</h2>
                <p>A rotina percorre todas as empresas e identifica mensagens sem resposta por intervalo, erro da IA/Evolution ou execução interrompida. Conversas já respondidas não recebem novo envio.</p>
            </div>
        </div>

        <form method="post" action="<?= View::e(Router::url('/operations/ai-reprocess/save')) ?>" class="form-grid two">
            <?= Csrf::input() ?>
            <label class="switch-card field-span-2">
                <input type="checkbox" name="enabled" value="1" <?= !empty($settings['enabled']) ? 'checked' : '' ?>>
                <span><strong>Ativar verificação diária</strong><small>O cron pode consultar o sistema várias vezes; a execução ocorre somente uma vez por dia, depois do horário configurado.</small></span>
            </label>
            <label class="field">
                <span>Horário diário</span>
                <input type="time" name="run_time" value="<?= View::e((string) ($settings['run_time'] ?? '03:00')) ?>" required>
            </label>
            <label class="field">
                <span>Fuso horário</span>
                <input name="timezone" value="<?= View::e((string) ($settings['timezone'] ?? 'America/Sao_Paulo')) ?>" required>
                <small class="field-hint">Ex.: America/Sao_Paulo</small>
            </label>
            <label class="field field-span-2">
                <span>Limite de mensagens por execução</span>
                <input type="number" name="max_messages_per_run" min="1" max="1000" value="<?= (int) ($settings['max_messages_per_run'] ?? 100) ?>" required>
                <small class="field-hint">Protege o servidor caso exista um volume anormal de mensagens pendentes.</small>
            </label>
            <div class="field-span-2 action-row">
                <button class="btn btn-primary" type="submit">Salvar rotina</button>
            </div>
        </form>
    </section>

    <section class="card">
        <div class="section-heading">
            <div><span class="eyebrow">Ação imediata</span><h2>Verificar agora</h2></div>
        </div>
        <p>Executa a mesma validação segura em todas as empresas. Falhas continuam registradas na fila e, se não houver mensagem sem resposta, nenhum WhatsApp será enviado.</p>
        <form method="post" action="<?= View::e(Router::url('/operations/ai-reprocess/run')) ?>" onsubmit="return confirm('Verificar agora as filas de IA de todas as empresas? Mensagens já respondidas não serão reenviadas.');">
            <?= Csrf::input() ?>
            <button class="btn btn-primary" type="submit">Reprocessar pendências agora</button>
        </form>

        <div class="operations-alert <?= !empty($data['cron_token_configured']) ? 'is-ok' : 'is-warning' ?>" style="margin-top:16px">
            <strong>Acionamento externo</strong>
            <p><?= !empty($data['cron_token_configured']) ? 'Token configurado no ambiente.' : 'Configure AI_REPROCESS_CRON_TOKEN no .env antes de ativar o cron.' ?></p>
            <small>Endpoint: <code><?= View::e((string) ($data['cron_url'] ?? '')) ?>?token=SEU_TOKEN</code></small>
        </div>
    </section>
</div>

<section class="card" style="margin-top:16px">
    <div class="section-heading">
        <div><span class="eyebrow">Situação atual</span><h2>Empresas com mensagem presa</h2></div>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Empresa</th><th>Pendências</th><th>Mensagem mais recente</th><th>Ação</th></tr></thead>
            <tbody>
                <?php foreach ($pending as $item): ?>
                    <tr>
                        <td><strong><?= View::e((string) ($item['tenant_name'] ?? 'Empresa')) ?></strong></td>
                        <td><span class="badge badge-warning"><?= (int) ($item['pending_count'] ?? 0) ?></span></td>
                        <td><?= View::e($formatDate($item['oldest_or_latest_pending_at'] ?? null)) ?></td>
                        <td><a class="btn btn-quiet" href="<?= View::e(Router::url('/companies/health?tenant_id=' . (int) ($item['tenant_id'] ?? 0))) ?>">Abrir diagnóstico</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$pending): ?><tr><td colspan="4"><div class="empty-state">Nenhuma mensagem presa na fila da IA.</div></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card" style="margin-top:16px">
    <div class="section-heading">
        <div><span class="eyebrow">Auditoria</span><h2>Últimas execuções</h2></div>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Início</th><th>Origem</th><th>Status</th><th>Reavaliadas</th><th>Respondidas</th><th>Pendentes após</th><th>Responsável</th></tr></thead>
            <tbody>
                <?php foreach ($history as $run): ?>
                    <tr>
                        <td><?= View::e($formatDate($run['started_at'] ?? null)) ?></td>
                        <td><?= View::e(['manual' => 'Manual', 'scheduled' => 'Agendada', 'webhook' => 'Webhook', 'cli' => 'CLI'][(string) ($run['source'] ?? '')] ?? (string) ($run['source'] ?? '')) ?></td>
                        <td><span class="badge <?= ($run['status'] ?? '') === 'success' ? 'badge-success' : (($run['status'] ?? '') === 'error' ? 'badge-danger' : 'badge-warning') ?>"><?= View::e($statusLabel((string) ($run['status'] ?? ''))) ?></span></td>
                        <td><?= (int) ($run['attempted_count'] ?? 0) ?></td>
                        <td><?= (int) ($run['replied_count'] ?? 0) ?></td>
                        <td><?= (int) ($run['pending_after'] ?? 0) ?></td>
                        <td><?= View::e((string) ($run['created_by_name'] ?? 'Rotina automática')) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$history): ?><tr><td colspan="7"><div class="empty-state">Nenhuma execução registrada ainda.</div></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>
