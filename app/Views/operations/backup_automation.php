<?php

use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;

$summary = $data['summary'] ?? [];
$routines = $data['routines'] ?? [];
$jobs = $data['jobs'] ?? [];
$settings = $data['settings'] ?? [];
$statusBadge = static fn (string $status): string => match ($status) {
    'active', 'success', 'running' => 'badge-success',
    'inactive', 'skipped' => 'badge-warning',
    'error' => 'badge-danger',
    default => 'badge-info',
};
$statusLabel = static fn (string $status): string => match ($status) {
    'active' => 'Ativa',
    'inactive' => 'Inativa',
    'requested' => 'Solicitada',
    'running' => 'Executando',
    'success' => 'Sucesso',
    'error' => 'Erro',
    'skipped' => 'Ignorada',
    default => $status,
};
$storageLabel = static fn (string $storage): string => match ($storage) {
    'server' => 'Servidor/VPS',
    'easypanel' => 'EasyPanel/Provedor',
    'google_drive' => 'Google Drive',
    's3_minio' => 'S3/MinIO',
    'dropbox' => 'Dropbox',
    default => 'Outro',
};
$frequencyLabel = static fn (string $frequency): string => match ($frequency) {
    'daily' => 'Diário',
    'weekly' => 'Semanal',
    'monthly' => 'Mensal',
    'manual' => 'Manual',
    'custom' => 'Personalizado',
    default => $frequency,
};
?>

<section class="hero-card operations-hero-clean">
    <div>
        <span class="eyebrow">Continuidade operacional</span>
        <h2>Backup automático via n8n.</h2>
        <p>Configure uma rotina externa para gerar o dump, salvar o arquivo e registrar o resultado no RS Connect pelo webhook de backup.</p>
    </div>
    <div class="hero-actions operations-hero-actions">
        <a class="btn btn-primary" href="<?= View::e((string) ($settings['template_url'] ?? Router::url('/n8n-templates'))) ?>">Baixar template n8n</a>
        <a class="btn btn-quiet" href="<?= View::e(Router::url('/operations')) ?>">Voltar ao monitoramento</a>
        <span class="badge <?= !empty($settings['backup_token_configured']) ? 'badge-success' : 'badge-warning' ?>">Token de backup: <?= !empty($settings['backup_token_configured']) ? 'configurado' : 'pendente' ?></span>
    </div>
</section>

<div class="report-kpi-grid operations-kpis">
    <article class="card report-kpi"><span>Rotinas ativas</span><strong><?= (int) ($summary['active'] ?? 0) ?></strong><small>Executadas pelo n8n</small></article>
    <article class="card report-kpi"><span>Rotinas inativas</span><strong><?= (int) ($summary['inactive'] ?? 0) ?></strong><small>Pausadas</small></article>
    <article class="card report-kpi"><span>Jobs OK recentes</span><strong><?= (int) ($summary['jobs_success'] ?? 0) ?></strong><small>Últimos registros</small></article>
    <article class="card report-kpi"><span>Jobs com erro</span><strong><?= (int) ($summary['jobs_error'] ?? 0) ?></strong><small>Precisam revisão</small></article>
</div>

<div class="operations-grid" style="margin-top:16px">
    <section class="card">
        <div class="section-heading"><div><span class="eyebrow">Rotinas</span><h2>Backup automático configurado</h2></div></div>
        <div class="operations-check-list">
            <?php foreach ($routines as $routine): ?>
                <article class="operations-check-row is-<?= View::e(($routine['status'] ?? '') === 'active' ? 'ok' : 'warning') ?>">
                    <span class="operations-status-dot"></span>
                    <div>
                        <strong><?= View::e($routine['name'] ?? '') ?></strong>
                        <p><?= View::e($frequencyLabel((string) ($routine['frequency'] ?? 'daily'))) ?><?= !empty($routine['preferred_time']) ? ' · ' . View::e($routine['preferred_time']) : '' ?><?= !empty($routine['timezone']) ? ' · ' . View::e($routine['timezone']) : '' ?></p>
                        <small>
                            Webhook: <?= View::e($routine['webhook_url_masked'] ?? '-') ?>
                            <?= !empty($routine['last_success_at']) ? ' · Último sucesso: ' . View::e($routine['last_success_at']) : '' ?>
                            <?= !empty($routine['last_error']) ? ' · Erro: ' . View::e($routine['last_error']) : '' ?>
                        </small>
                    </div>
                    <span class="badge <?= $statusBadge((string) ($routine['status'] ?? 'inactive')) ?>"><?= $statusLabel((string) ($routine['status'] ?? 'inactive')) ?></span>
                    <form method="post" action="<?= View::e(Router::url('/backup-automatico/trigger')) ?>">
                        <?= Csrf::input() ?>
                        <input type="hidden" name="routine_id" value="<?= (int) ($routine['id'] ?? 0) ?>">
                        <input type="hidden" name="trigger_type" value="manual">
                        <button class="btn btn-quiet" type="submit">Testar agora</button>
                    </form>
                    <form method="post" action="<?= View::e(Router::url('/backup-automatico/toggle')) ?>">
                        <?= Csrf::input() ?>
                        <input type="hidden" name="routine_id" value="<?= (int) ($routine['id'] ?? 0) ?>">
                        <input type="hidden" name="status" value="<?= ($routine['status'] ?? '') === 'active' ? 'inactive' : 'active' ?>">
                        <button class="btn btn-quiet" type="submit"><?= ($routine['status'] ?? '') === 'active' ? 'Pausar' : 'Ativar' ?></button>
                    </form>
                </article>
            <?php endforeach; ?>
            <?php if (!$routines): ?>
                <div class="empty-state">Nenhuma rotina automática cadastrada ainda. Cadastre a URL do webhook n8n ao lado.</div>
            <?php endif; ?>
        </div>
    </section>

    <aside class="card">
        <div class="section-heading"><div><span class="eyebrow">Configuração</span><h2>Nova rotina</h2></div></div>
        <form class="operations-form" method="post" action="<?= View::e(Router::url('/backup-automatico/save')) ?>">
            <?= Csrf::input() ?>
            <div class="field">
                <label>Nome</label>
                <input type="text" name="name" value="Backup diário RS Connect" required>
            </div>
            <div class="field">
                <label>URL do webhook n8n</label>
                <input type="url" name="n8n_webhook_url" placeholder="https://n8n.../webhook/rsconnect-backup" required>
                <small class="muted-text">Use a URL de produção do Webhook node do n8n.</small>
            </div>
            <div class="field">
                <label>Token do fluxo n8n</label>
                <input type="text" name="secret_token" placeholder="Opcional: token de segurança do webhook">
            </div>
            <div class="field-grid two">
                <div class="field">
                    <label>Frequência</label>
                    <select name="frequency">
                        <option value="daily">Diário</option>
                        <option value="weekly">Semanal</option>
                        <option value="monthly">Mensal</option>
                        <option value="manual">Manual</option>
                        <option value="custom">Personalizado</option>
                    </select>
                </div>
                <div class="field">
                    <label>Horário preferido</label>
                    <input type="time" name="preferred_time" value="03:00">
                </div>
            </div>
            <div class="field-grid two">
                <div class="field">
                    <label>Timezone</label>
                    <input type="text" name="timezone" value="America/Sao_Paulo">
                </div>
                <div class="field">
                    <label>Retenção em dias</label>
                    <input type="number" name="retention_days" min="1" max="365" value="14">
                </div>
            </div>
            <div class="field-grid two">
                <div class="field">
                    <label>Armazenamento</label>
                    <select name="storage_type">
                        <option value="server">Servidor/VPS</option>
                        <option value="easypanel">EasyPanel/Provedor</option>
                        <option value="google_drive">Google Drive</option>
                        <option value="s3_minio">S3/MinIO</option>
                        <option value="dropbox">Dropbox</option>
                        <option value="other">Outro</option>
                    </select>
                </div>
                <div class="field">
                    <label>Limite sem backup</label>
                    <input type="number" name="max_age_hours" min="1" max="720" value="24">
                </div>
            </div>
            <div class="field">
                <label>Caminho/base do arquivo</label>
                <input type="text" name="storage_path" placeholder="Ex: /backups/rs-connect ou s3://bucket/rs-connect">
            </div>
            <div class="field">
                <label>Observações</label>
                <textarea name="notes" rows="3" placeholder="Ex: backup diário antes do horário comercial"></textarea>
            </div>
            <button class="btn btn-primary btn-block" type="submit">Salvar rotina</button>
        </form>
    </aside>
</div>

<div class="operations-grid" style="margin-top:16px">
    <section class="card">
        <div class="section-heading"><div><span class="eyebrow">Histórico</span><h2>Jobs de backup</h2></div></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Data</th><th>Rotina</th><th>Gatilho</th><th>Status</th><th>Retorno</th></tr></thead>
                <tbody>
                <?php foreach ($jobs as $job): ?>
                    <tr>
                        <td><?= View::e($job['created_at'] ?? $job['requested_at'] ?? '') ?></td>
                        <td><?= View::e($job['routine_name'] ?? '-') ?></td>
                        <td><?= View::e($job['trigger_type'] ?? '-') ?></td>
                        <td><span class="badge <?= $statusBadge((string) ($job['status'] ?? 'requested')) ?>"><?= $statusLabel((string) ($job['status'] ?? 'requested')) ?></span></td>
                        <td><?= View::e($job['error_message'] ?? $job['response_preview'] ?? '-') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$jobs): ?><tr><td colspan="5"><div class="empty-state">Nenhum job de backup registrado ainda.</div></td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="card">
        <div class="section-heading"><div><span class="eyebrow">Webhook</span><h2>Callback de resultado</h2></div></div>
        <p class="muted-text">Depois de gerar e salvar o arquivo, o n8n deve chamar este endpoint para registrar o backup no painel de monitoramento.</p>
        <pre class="codebox">POST <?= View::e((string) ($settings['callback_url_sample'] ?? '')) ?>{
  "status": "success",
  "backup_type": "automatic",
  "storage_type": "server",
  "routine_id": 1,
  "backup_job_id": 10,
  "file_name": "rs-connect-2026-07-13.sql.gz",
  "location": "/backups/rs-connect/rs-connect-2026-07-13.sql.gz",
  "size_bytes": 1234567,
  "checksum": "sha256...",
  "verified": true,
  "notes": "Backup diário concluído"
}</pre>
        <?php if (empty($settings['backup_token_configured'])): ?>
            <div class="operations-alert is-warning"><strong>Token pendente</strong><p>Configure <code>OPERATIONS_BACKUP_TOKEN</code> no ambiente do serviço RS Connect no EasyPanel e faça redeploy. Também é aceito <code>BACKUP_WEBHOOK_TOKEN</code> como compatibilidade.</p></div>
        <?php endif; ?>
    </section>
</div>
