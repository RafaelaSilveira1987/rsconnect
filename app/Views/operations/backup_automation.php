<?php

use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;

$summary = $data['summary'] ?? [];
$routines = $data['routines'] ?? [];
$primaryRoutine = $data['primary_routine'] ?? null;
$jobs = $data['jobs'] ?? [];
$settings = $data['settings'] ?? [];

$statusBadge = static fn (string $status): string => match ($status) {
    'active', 'success' => 'badge-success',
    'requested', 'running' => 'badge-info',
    'inactive', 'skipped', 'timeout' => 'badge-warning',
    'error' => 'badge-danger',
    default => 'badge-info',
};
$statusLabel = static fn (string $status): string => match ($status) {
    'active' => 'Ativa',
    'inactive' => 'Pausada',
    'requested' => 'Aguardando',
    'running' => 'Executando',
    'success' => 'Concluído',
    'error' => 'Falhou',
    'timeout' => 'Tempo esgotado',
    'skipped' => 'Ignorado',
    default => $status,
};
$frequencyLabel = static fn (string $frequency): string => match ($frequency) {
    'daily' => 'Diário',
    'weekly' => 'Semanal',
    'monthly' => 'Mensal',
    'manual' => 'Somente manual',
    'custom' => 'Personalizado',
    default => $frequency,
};
$triggerLabel = static fn (string $trigger): string => match ($trigger) {
    'manual' => 'Manual',
    'scheduled' => 'Automático',
    'test' => 'Teste',
    'webhook' => 'Callback',
    default => $trigger,
};
$formatBytes = static function ($bytes): string {
    if ($bytes === null || !is_numeric($bytes)) {
        return '—';
    }
    $value = (float) $bytes;
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $index = 0;
    while ($value >= 1024 && $index < count($units) - 1) {
        $value /= 1024;
        $index++;
    }
    return number_format($value, $index === 0 ? 0 : 1, ',', '.') . ' ' . $units[$index];
};
$formatDuration = static function ($seconds): string {
    if ($seconds === null || !is_numeric($seconds)) {
        return '—';
    }
    $seconds = max(0, (int) $seconds);
    if ($seconds < 60) {
        return $seconds . 's';
    }
    $minutes = intdiv($seconds, 60);
    $remaining = $seconds % 60;
    return $minutes . 'min ' . $remaining . 's';
};
$lastBackup = $summary['last_valid_backup'] ?? null;
$routineId = (int) ($primaryRoutine['id'] ?? 0);
?>

<section class="hero-card operations-hero-clean backup-hero-v363">
    <div>
        <span class="eyebrow">Continuidade operacional</span>
        <h2>Backup confiável do RS Connect</h2>
        <p>O sucesso só é confirmado depois que o arquivo real é criado, validado e vinculado ao job pelo callback do n8n.</p>
    </div>
    <div class="hero-actions operations-hero-actions">
        <a class="btn btn-primary" href="<?= View::e((string) ($settings['template_url'] ?? Router::url('/n8n-templates'))) ?>">Baixar fluxo n8n atualizado</a>
        <span class="badge <?= !empty($settings['backup_token_configured']) ? 'badge-success' : 'badge-warning' ?>">
            Token: <?= !empty($settings['backup_token_configured']) ? 'configurado' : 'pendente' ?>
        </span>
    </div>
</section>

<?php if (empty($settings['backup_token_configured'])): ?>
    <div class="operations-alert is-warning backup-token-alert">
        <strong>O backup não pode ser executado ainda</strong>
        <p>Configure <code>OPERATIONS_BACKUP_TOKEN</code> no EasyPanel e faça o redeploy do serviço.</p>
    </div>
<?php endif; ?>

<div class="report-kpi-grid operations-kpis backup-kpis-v363">
    <article class="report-kpi-card">
        <span>Último backup válido</span>
        <strong id="backup-kpi-last"><?= View::e($lastBackup['finished_at'] ?? $lastBackup['created_at'] ?? 'Nenhum') ?></strong>
        <small id="backup-kpi-last-detail"><?= $lastBackup ? View::e(($lastBackup['file_name'] ?? 'Arquivo') . ' · ' . $formatBytes($lastBackup['size_bytes'] ?? null)) : 'Aguardando o primeiro arquivo verificado' ?></small>
    </article>
    <article class="report-kpi-card">
        <span>Em execução</span>
        <strong id="backup-kpi-running"><?= (int) ($summary['running'] ?? 0) ?></strong>
        <small>Jobs aguardando callback</small>
    </article>
    <article class="report-kpi-card">
        <span>Falhas recentes</span>
        <strong id="backup-kpi-errors"><?= (int) ($summary['jobs_error'] ?? 0) ?></strong>
        <small>Erro ou tempo esgotado no histórico exibido</small>
    </article>
    <article class="report-kpi-card">
        <span>Próxima execução</span>
        <strong id="backup-kpi-next"><?= View::e($summary['next_execution'] ?? 'Não programada') ?></strong>
        <small>Calculada pelo horário da rotina</small>
    </article>
</div>

<div class="backup-layout-v363">
    <section class="card backup-routine-card">
        <div class="section-heading">
            <div>
                <span class="eyebrow">Rotina operacional</span>
                <h2><?= $primaryRoutine ? View::e($primaryRoutine['name'] ?? 'Backup RS Connect') : 'Nenhuma rotina configurada' ?></h2>
            </div>
            <?php if ($primaryRoutine): ?>
                <span id="backup-routine-status" class="badge <?= $statusBadge((string) ($primaryRoutine['status'] ?? 'inactive')) ?>">
                    <?= $statusLabel((string) ($primaryRoutine['status'] ?? 'inactive')) ?>
                </span>
            <?php endif; ?>
        </div>

        <?php if ($primaryRoutine): ?>
            <div class="backup-routine-facts">
                <div><span>Frequência</span><strong><?= View::e($frequencyLabel((string) ($primaryRoutine['frequency'] ?? 'daily'))) ?></strong></div>
                <div><span>Horário</span><strong><?= View::e($primaryRoutine['preferred_time'] ?? '03:00') ?></strong></div>
                <div><span>Fuso</span><strong><?= View::e($primaryRoutine['timezone'] ?? 'America/Sao_Paulo') ?></strong></div>
                <div><span>Retenção</span><strong><?= (int) ($primaryRoutine['retention_days'] ?? 5) ?> dias</strong></div>
                <div><span>Destino</span><strong><?= View::e($primaryRoutine['storage_path'] ?? '/backups/rs-connect') ?></strong></div>
                <div><span>Tempo limite</span><strong><?= (int) ($settings['job_timeout_minutes'] ?? 30) ?> min</strong></div>
            </div>

            <div class="backup-routine-state">
                <div>
                    <span>Última solicitação</span>
                    <strong><?= View::e($primaryRoutine['last_requested_at'] ?? 'Ainda não executada') ?></strong>
                </div>
                <div>
                    <span>Último sucesso real</span>
                    <strong id="backup-routine-last-success"><?= View::e($primaryRoutine['last_success_at'] ?? 'Ainda não confirmado') ?></strong>
                </div>
                <?php if (!empty($primaryRoutine['last_error'])): ?>
                    <div class="is-error">
                        <span>Último erro</span>
                        <strong><?= View::e($primaryRoutine['last_error']) ?></strong>
                    </div>
                <?php endif; ?>
            </div>

            <div class="backup-action-row">
                <form method="post" action="<?= View::e(Router::url('/backup-automatico/trigger')) ?>">
                    <?= Csrf::input() ?>
                    <input type="hidden" name="routine_id" value="<?= $routineId ?>">
                    <input type="hidden" name="trigger_type" value="manual">
                    <input type="hidden" name="return_to" value="/backup-automatico">
                    <button class="btn btn-primary" type="submit" <?= ($primaryRoutine['status'] ?? '') !== 'active' || empty($settings['backup_token_configured']) ? 'disabled' : '' ?>>Executar backup agora</button>
                </form>
                <form method="post" action="<?= View::e(Router::url('/backup-automatico/test')) ?>">
                    <?= Csrf::input() ?>
                    <input type="hidden" name="routine_id" value="<?= $routineId ?>">
                    <button class="btn btn-quiet" type="submit">Testar conexão com n8n</button>
                </form>
                <form method="post" action="<?= View::e(Router::url('/backup-automatico/toggle')) ?>">
                    <?= Csrf::input() ?>
                    <input type="hidden" name="routine_id" value="<?= $routineId ?>">
                    <input type="hidden" name="status" value="<?= ($primaryRoutine['status'] ?? '') === 'active' ? 'inactive' : 'active' ?>">
                    <button class="btn btn-quiet" type="submit"><?= ($primaryRoutine['status'] ?? '') === 'active' ? 'Pausar rotina' : 'Ativar rotina' ?></button>
                </form>
            </div>
            <p class="muted-text backup-webhook-line">Webhook n8n: <?= View::e($primaryRoutine['webhook_url_masked'] ?? 'Não configurado') ?></p>
        <?php else: ?>
            <div class="empty-state">Preencha a configuração ao lado para criar a primeira rotina.</div>
        <?php endif; ?>
    </section>

    <aside class="card backup-config-card">
        <div class="section-heading">
            <div>
                <span class="eyebrow">Configuração</span>
                <h2><?= $primaryRoutine ? 'Editar rotina' : 'Criar rotina' ?></h2>
            </div>
        </div>
        <form class="operations-form" method="post" action="<?= View::e(Router::url('/backup-automatico/save')) ?>">
            <?= Csrf::input() ?>
            <input type="hidden" name="id" value="<?= $routineId ?>">
            <input type="hidden" name="status" value="<?= View::e($primaryRoutine['status'] ?? 'active') ?>">
            <div class="field">
                <label>Nome</label>
                <input type="text" name="name" value="<?= View::e($primaryRoutine['name'] ?? 'Backup diário RS Connect') ?>" required>
            </div>
            <div class="field">
                <label>URL do webhook n8n</label>
                <input type="url" name="n8n_webhook_url" value="<?= View::e($primaryRoutine['n8n_webhook_url'] ?? '') ?>" placeholder="https://n8n.../webhook/rsconnect-backup" <?= $primaryRoutine ? '' : 'required' ?>>
                <small class="muted-text">Use a URL de produção do node “Webhook RS Connect”.</small>
            </div>
            <div class="field">
                <label>Token de entrada do fluxo n8n</label>
                <input type="password" name="secret_token" autocomplete="new-password" placeholder="<?= !empty($primaryRoutine['secret_token_configured']) ? 'Já configurado — preencha somente para trocar' : 'Opcional, mas recomendado' ?>">
            </div>
            <div class="field-grid two">
                <div class="field">
                    <label>Frequência</label>
                    <select name="frequency">
                        <?php foreach (['daily' => 'Diário', 'weekly' => 'Semanal', 'monthly' => 'Mensal', 'manual' => 'Somente manual', 'custom' => 'Personalizado'] as $value => $label): ?>
                            <option value="<?= View::e($value) ?>" <?= ($primaryRoutine['frequency'] ?? 'daily') === $value ? 'selected' : '' ?>><?= View::e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>Horário preferido</label>
                    <input type="time" name="preferred_time" value="<?= View::e($primaryRoutine['preferred_time'] ?? '03:00') ?>">
                </div>
            </div>
            <div class="field-grid two">
                <div class="field">
                    <label>Fuso horário</label>
                    <input type="text" name="timezone" value="<?= View::e($primaryRoutine['timezone'] ?? 'America/Sao_Paulo') ?>">
                </div>
                <div class="field">
                    <label>Retenção em dias</label>
                    <input type="number" name="retention_days" min="1" max="365" value="<?= (int) ($primaryRoutine['retention_days'] ?? 5) ?>">
                </div>
            </div>
            <div class="field-grid two">
                <div class="field">
                    <label>Limite sem backup</label>
                    <input type="number" name="max_age_hours" min="1" max="720" value="<?= (int) ($primaryRoutine['max_age_hours'] ?? 24) ?>">
                </div>
                <div class="field">
                    <label>Armazenamento</label>
                    <select name="storage_type">
                        <option value="server" <?= ($primaryRoutine['storage_type'] ?? 'server') === 'server' ? 'selected' : '' ?>>Servidor/VPS</option>
                        <option value="easypanel" <?= ($primaryRoutine['storage_type'] ?? '') === 'easypanel' ? 'selected' : '' ?>>EasyPanel/Provedor</option>
                        <option value="google_drive" <?= ($primaryRoutine['storage_type'] ?? '') === 'google_drive' ? 'selected' : '' ?>>Google Drive</option>
                        <option value="s3_minio" <?= ($primaryRoutine['storage_type'] ?? '') === 's3_minio' ? 'selected' : '' ?>>S3/MinIO</option>
                        <option value="dropbox" <?= ($primaryRoutine['storage_type'] ?? '') === 'dropbox' ? 'selected' : '' ?>>Dropbox</option>
                        <option value="other" <?= ($primaryRoutine['storage_type'] ?? '') === 'other' ? 'selected' : '' ?>>Outro</option>
                    </select>
                </div>
            </div>
            <div class="field">
                <label>Caminho do arquivo</label>
                <input type="text" name="storage_path" value="<?= View::e($primaryRoutine['storage_path'] ?? '/backups/rs-connect') ?>" required>
            </div>
            <div class="field">
                <label>Observações</label>
                <textarea name="notes" rows="3" placeholder="Informações internas sobre a rotina"><?= View::e($primaryRoutine['notes'] ?? '') ?></textarea>
            </div>
            <button class="btn btn-primary btn-block" type="submit">Salvar configuração</button>
        </form>
    </aside>
</div>

<section class="card backup-history-card">
    <div class="section-heading">
        <div>
            <span class="eyebrow">Histórico operacional</span>
            <h2>Execuções de backup</h2>
        </div>
        <small id="backup-live-status" class="muted-text">Atualização automática ativa</small>
    </div>
    <div class="table-wrap">
        <table class="backup-jobs-table">
            <thead>
                <tr><th>Solicitado em</th><th>Gatilho</th><th>Status</th><th>Duração</th><th>Arquivo</th><th>Validação</th><th>Detalhes</th></tr>
            </thead>
            <tbody id="backup-jobs-body" data-collapsible-list="3">
            <?php foreach ($jobs as $job): ?>
                <?php $duration = $job['duration_seconds_calculated'] ?? $job['duration_seconds'] ?? null; ?>
                <tr data-backup-job-id="<?= (int) ($job['id'] ?? 0) ?>">
                    <td><?= View::e($job['requested_at'] ?? $job['created_at'] ?? '') ?></td>
                    <td><?= View::e($triggerLabel((string) ($job['trigger_type'] ?? 'manual'))) ?></td>
                    <td><span class="badge <?= $statusBadge((string) ($job['status'] ?? 'requested')) ?>"><?= $statusLabel((string) ($job['status'] ?? 'requested')) ?></span></td>
                    <td><?= View::e($formatDuration($duration)) ?></td>
                    <td>
                        <strong><?= View::e($job['file_name'] ?? '—') ?></strong>
                        <?php if (!empty($job['file_size_bytes'])): ?><small><?= View::e($formatBytes($job['file_size_bytes'])) ?></small><?php endif; ?>
                    </td>
                    <td><?= !empty($job['verified']) ? '<span class="badge badge-success">Verificado</span>' : '<span class="badge badge-info">Aguardando</span>' ?></td>
                    <td>
                        <details>
                            <summary>Ver</summary>
                            <div class="backup-job-detail">
                                <strong>Job #<?= (int) ($job['id'] ?? 0) ?></strong>
                                <p><?= View::e($job['error_message'] ?? $job['response_preview'] ?? 'Sem detalhes adicionais.') ?></p>
                                <?php if (!empty($job['backup_location'])): ?><code><?= View::e($job['backup_location']) ?></code><?php endif; ?>
                                <?php if (!empty($job['backup_checksum'])): ?><code>SHA-256: <?= View::e($job['backup_checksum']) ?></code><?php endif; ?>
                            </div>
                        </details>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$jobs): ?><tr><td colspan="7"><div class="empty-state">Nenhum job registrado ainda.</div></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<details class="card backup-advanced-card">
    <summary>
        <span><span class="eyebrow">Configuração avançada</span><strong>Integração técnica e endpoints</strong></span>
        <span>Expandir</span>
    </summary>
    <div class="backup-advanced-content">
        <p>O n8n confirma o recebimento imediatamente. O job só muda para concluído quando o callback final traz arquivo, tamanho, checksum e <code>verified=true</code>.</p>
        <div class="backup-endpoint-grid">
            <div><span>Callback final</span><code><?= View::e((string) ($settings['callback_url'] ?? '')) ?></code></div>
            <div><span>Despacho das rotinas vencidas</span><code><?= View::e((string) ($settings['dispatch_url'] ?? '')) ?></code></div>
        </div>
        <p class="muted-text">Envie o token no cabeçalho <code>X-RS-Connect-Token</code>. O fluxo atualizado já utiliza esse formato.</p>
    </div>
</details>

<script>
(() => {
    const endpoint = <?= json_encode(Router::url('/operations/backups/automation/status'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const body = document.getElementById('backup-jobs-body');
    const live = document.getElementById('backup-live-status');
    if (!body || !endpoint) return;

    const statusMeta = {
        requested: ['Aguardando', 'badge-info'],
        running: ['Executando', 'badge-info'],
        success: ['Concluído', 'badge-success'],
        error: ['Falhou', 'badge-danger'],
        timeout: ['Tempo esgotado', 'badge-warning'],
        skipped: ['Ignorado', 'badge-warning']
    };
    const triggerMeta = { manual: 'Manual', scheduled: 'Automático', test: 'Teste', webhook: 'Callback' };
    const bytes = (value) => {
        if (value === null || value === undefined || Number.isNaN(Number(value))) return '—';
        let number = Number(value); const units = ['B', 'KB', 'MB', 'GB', 'TB']; let index = 0;
        while (number >= 1024 && index < units.length - 1) { number /= 1024; index++; }
        return new Intl.NumberFormat('pt-BR', { maximumFractionDigits: index ? 1 : 0 }).format(number) + ' ' + units[index];
    };
    const duration = (value) => {
        if (value === null || value === undefined || Number.isNaN(Number(value))) return '—';
        const seconds = Math.max(0, Number(value));
        return seconds < 60 ? `${seconds}s` : `${Math.floor(seconds / 60)}min ${seconds % 60}s`;
    };
    const textCell = (value) => { const td = document.createElement('td'); td.textContent = value ?? '—'; return td; };
    const badge = (status) => {
        const span = document.createElement('span'); const meta = statusMeta[status] || [status || 'Aguardando', 'badge-info'];
        span.className = `badge ${meta[1]}`; span.textContent = meta[0]; return span;
    };

    const renderJobs = (jobs) => {
        body.replaceChildren();
        if (!Array.isArray(jobs) || jobs.length === 0) {
            const row = document.createElement('tr'); const td = document.createElement('td'); td.colSpan = 7;
            const empty = document.createElement('div'); empty.className = 'empty-state'; empty.textContent = 'Nenhum job registrado ainda.';
            td.appendChild(empty); row.appendChild(td); body.appendChild(row); return;
        }
        jobs.forEach((job) => {
            const row = document.createElement('tr'); row.dataset.backupJobId = job.id;
            row.appendChild(textCell(job.requested_at || job.created_at || '—'));
            row.appendChild(textCell(triggerMeta[job.trigger_type] || job.trigger_type || '—'));
            const statusTd = document.createElement('td'); statusTd.appendChild(badge(job.status)); row.appendChild(statusTd);
            row.appendChild(textCell(duration(job.duration_seconds_calculated ?? job.duration_seconds)));
            const fileTd = document.createElement('td'); const strong = document.createElement('strong'); strong.textContent = job.file_name || '—'; fileTd.appendChild(strong);
            if (job.file_size_bytes) { const small = document.createElement('small'); small.textContent = bytes(job.file_size_bytes); fileTd.appendChild(small); }
            row.appendChild(fileTd);
            const verifyTd = document.createElement('td');
            const verify = document.createElement('span'); verify.className = `badge ${Number(job.verified) ? 'badge-success' : 'badge-info'}`; verify.textContent = Number(job.verified) ? 'Verificado' : 'Aguardando'; verifyTd.appendChild(verify); row.appendChild(verifyTd);
            const detailsTd = document.createElement('td'); const details = document.createElement('details'); const summary = document.createElement('summary'); summary.textContent = 'Ver'; details.appendChild(summary);
            const detail = document.createElement('div'); detail.className = 'backup-job-detail';
            const title = document.createElement('strong'); title.textContent = `Job #${job.id}`; detail.appendChild(title);
            const message = document.createElement('p'); message.textContent = job.error_message || job.response_preview || 'Sem detalhes adicionais.'; detail.appendChild(message);
            if (job.backup_location) { const code = document.createElement('code'); code.textContent = job.backup_location; detail.appendChild(code); }
            if (job.backup_checksum) { const code = document.createElement('code'); code.textContent = `SHA-256: ${job.backup_checksum}`; detail.appendChild(code); }
            details.appendChild(detail); detailsTd.appendChild(details); row.appendChild(detailsTd);
            body.appendChild(row);
        });
    };

    const renderSummary = (data) => {
        const summary = data.summary || {}; const last = summary.last_valid_backup || null;
        const set = (id, value) => { const element = document.getElementById(id); if (element) element.textContent = value; };
        set('backup-kpi-running', summary.running ?? 0);
        set('backup-kpi-errors', summary.jobs_error ?? 0);
        set('backup-kpi-next', summary.next_execution || 'Não programada');
        set('backup-kpi-last', last ? (last.finished_at || last.created_at || 'Confirmado') : 'Nenhum');
        set('backup-kpi-last-detail', last ? `${last.file_name || 'Arquivo'} · ${bytes(last.size_bytes)}` : 'Aguardando o primeiro arquivo verificado');
        const routine = data.primary_routine || null;
        if (routine) set('backup-routine-last-success', routine.last_success_at || 'Ainda não confirmado');
    };

    const refresh = async () => {
        try {
            const response = await fetch(endpoint, { headers: { Accept: 'application/json' }, cache: 'no-store' });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            const payload = await response.json();
            if (!payload.ok || !payload.data) throw new Error('Resposta inválida');
            renderJobs(payload.data.jobs || []); renderSummary(payload.data);
            if (live) live.textContent = `Atualizado às ${new Date().toLocaleTimeString('pt-BR')}`;
        } catch (_) {
            if (live) live.textContent = 'Não foi possível atualizar agora';
        }
    };

    window.setInterval(refresh, 5000);
})();
</script>
