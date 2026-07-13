<?php

use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;

$summary = $data['summary'] ?? [];
$checks = $data['checks'] ?? [];
$lastBackup = $data['last_backup'] ?? null;
$backups = $data['backups'] ?? [];
$alerts = $data['alerts'] ?? [];
$incidents = $data['incidents'] ?? [];
$recovery = $data['recovery'] ?? [];
$settings = $data['settings'] ?? [];
$lastCheckedAt = $checks[0]['checked_at'] ?? null;
$statusBadge = static fn (string $status): string => match ($status) {
    'ok', 'success' => 'badge-success',
    'down', 'error', 'failed' => 'badge-danger',
    'running' => 'badge-info',
    default => 'badge-warning',
};
$statusLabel = static fn (string $status): string => match ($status) {
    'ok' => 'Operando',
    'down' => 'Falha',
    'success' => 'Sucesso',
    'error', 'failed' => 'Erro',
    'running' => 'Executando',
    default => 'Atenção',
};
?>

<section class="hero-card operations-hero-clean">
    <div>
        <span class="eyebrow">Operação e continuidade</span>
        <h2>Backup, saúde do sistema e plano de recuperação.</h2>
        <p>Monitore banco, Evolution, n8n, IA, webhooks, pagamentos, cron de cobrança e status do último backup em uma tela única.</p>
    </div>
    <div class="hero-actions operations-hero-actions">
        <form method="post" action="<?= View::e(Router::url('/operations/checks/run')) ?>" data-operations-check-form>
            <?= Csrf::input() ?>
            <button class="btn btn-primary" type="submit" data-operations-check-button>Verificar agora</button>
        </form>
        <small class="muted-text" data-operations-check-status><?= $lastCheckedAt ? 'Última verificação: ' . View::e((string) $lastCheckedAt) : 'Nenhuma verificação executada nesta tela.' ?></small>
        <span class="badge <?= !empty($settings['strict_backup_token']) ? 'badge-success' : 'badge-warning' ?>">Webhook backup: <?= !empty($settings['strict_backup_token']) ? 'configurado' : 'pendente' ?></span>
    </div>
</section>

<div class="report-kpi-grid operations-kpis">
    <article class="card report-kpi"><span>Serviços OK</span><strong data-operations-kpi="healthy"><?= (int) ($summary['healthy'] ?? 0) ?></strong><small>Última verificação registrada</small></article>
    <article class="card report-kpi"><span>Atenções</span><strong data-operations-kpi="warning"><?= (int) ($summary['warning'] ?? 0) ?></strong><small>Requerem revisão</small></article>
    <article class="card report-kpi"><span>Falhas</span><strong data-operations-kpi="down"><?= (int) ($summary['down'] ?? 0) ?></strong><small>Prioridade operacional</small></article>
    <article class="card report-kpi"><span>Alertas ativos</span><strong data-operations-kpi="alerts"><?= (int) ($summary['alerts'] ?? 0) ?></strong><small>Inclui backup e integrações</small></article>
</div>

<div class="operations-grid">
    <section class="card">
        <div class="section-heading">
            <div><span class="eyebrow">Saúde</span><h2>Status dos serviços</h2></div>
        </div>
        <div class="operations-check-list">
            <?php foreach ($checks as $check): ?>
                <article class="operations-check-row is-<?= View::e($check['status'] ?? 'warning') ?>">
                    <span class="operations-status-dot"></span>
                    <div>
                        <strong><?= View::e($check['label'] ?? $check['check_key'] ?? '') ?></strong>
                        <p><?= View::e($check['message'] ?? '') ?></p>
                        <small>Verificado em <?= View::e($check['checked_at'] ?? '') ?><?= isset($check['latency_ms']) && $check['latency_ms'] !== null ? ' · ' . (int) $check['latency_ms'] . 'ms' : '' ?></small>
                    </div>
                    <span class="badge <?= $statusBadge((string) ($check['status'] ?? 'warning')) ?>"><?= $statusLabel((string) ($check['status'] ?? 'warning')) ?></span>
                </article>
            <?php endforeach; ?>
            <?php if (!$checks): ?>
                <div class="empty-state">Nenhuma verificação registrada ainda. Clique em <strong>Verificar agora</strong> para iniciar.</div>
            <?php endif; ?>
        </div>
    </section>

    <aside class="card">
        <div class="section-heading">
            <div><span class="eyebrow">Backup</span><h2>Último backup</h2></div>
        </div>
        <?php if ($lastBackup): ?>
            <div class="operations-backup-card">
                <span class="badge <?= $statusBadge((string) ($lastBackup['status'] ?? 'warning')) ?>"><?= $statusLabel((string) ($lastBackup['status'] ?? 'warning')) ?></span>
                <strong><?= View::e($lastBackup['file_name'] ?? 'Backup registrado') ?></strong>
                <p><?= View::e($lastBackup['location'] ?? '') ?></p>
                <small>Finalizado em <?= View::e($lastBackup['finished_at'] ?? $lastBackup['created_at'] ?? '') ?></small>
            </div>
        <?php else: ?>
            <div class="operations-backup-card pending">
                <span class="badge badge-warning">Pendente</span>
                <strong>Nenhum backup registrado</strong>
                <p>Registre manualmente ou configure uma rotina externa para chamar o webhook de backup.</p>
            </div>
        <?php endif; ?>

        <form class="operations-form" method="post" action="<?= View::e(Router::url('/operations/backups/register')) ?>">
            <?= Csrf::input() ?>
            <div class="field">
                <label>Tipo</label>
                <select name="backup_type">
                    <option value="manual">Manual</option>
                    <option value="automatic">Automático</option>
                    <option value="provider">Provedor/VPS</option>
                </select>
            </div>
            <div class="field">
                <label>Local/arquivo</label>
                <input type="text" name="location" placeholder="Ex: EasyPanel backup 13/07/2026">
            </div>
            <div class="field">
                <label>Observações</label>
                <textarea name="notes" rows="3" placeholder="Ex: backup realizado antes do deploy"></textarea>
            </div>
            <button class="btn btn-primary btn-block" type="submit">Registrar backup</button>
        </form>
    </aside>
</div>

<div class="operations-grid" style="margin-top:16px">
    <section class="card">
        <div class="section-heading"><div><span class="eyebrow">Alertas</span><h2>O que precisa de atenção</h2></div></div>
        <div class="operations-alert-list">
            <?php foreach ($alerts as $alert): ?>
                <article class="operations-alert is-<?= View::e($alert['type'] ?? 'warning') ?>">
                    <strong><?= View::e($alert['title'] ?? '') ?></strong>
                    <p><?= View::e($alert['message'] ?? '') ?></p>
                </article>
            <?php endforeach; ?>
            <?php if (!$alerts): ?><div class="empty-state">Nenhum alerta ativo no momento.</div><?php endif; ?>
        </div>
    </section>

    <section class="card">
        <div class="section-heading"><div><span class="eyebrow">Recuperação</span><h2>Planos rápidos</h2></div></div>
        <div class="operations-playbooks">
            <?php foreach ($recovery as $playbook): ?>
                <details class="operations-playbook">
                    <summary><?= View::e($playbook['title'] ?? '') ?></summary>
                    <ol>
                        <?php foreach (($playbook['steps'] ?? []) as $step): ?>
                            <li><?= View::e($step) ?></li>
                        <?php endforeach; ?>
                    </ol>
                </details>
            <?php endforeach; ?>
        </div>
    </section>
</div>

<div class="operations-grid" style="margin-top:16px">
    <section class="card">
        <div class="section-heading"><div><span class="eyebrow">Histórico</span><h2>Backups registrados</h2></div></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Data</th><th>Tipo</th><th>Arquivo</th><th>Status</th><th>Observação</th></tr></thead>
                <tbody>
                    <?php foreach ($backups as $backup): ?>
                        <tr>
                            <td><?= View::e($backup['finished_at'] ?? $backup['created_at'] ?? '') ?></td>
                            <td><?= View::e($backup['backup_type'] ?? '') ?></td>
                            <td><?= View::e($backup['file_name'] ?? '') ?></td>
                            <td><span class="badge <?= $statusBadge((string) ($backup['status'] ?? 'warning')) ?>"><?= $statusLabel((string) ($backup['status'] ?? 'warning')) ?></span></td>
                            <td><?= View::e($backup['notes'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$backups): ?><tr><td colspan="5"><div class="empty-state">Nenhum backup registrado ainda.</div></td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="card">
        <div class="section-heading"><div><span class="eyebrow">Incidentes</span><h2>Eventos operacionais</h2></div></div>
        <div class="security-timeline">
            <?php foreach ($incidents as $incident): ?>
                <article class="security-event">
                    <span class="badge <?= $statusBadge((string) ($incident['severity'] ?? 'warning')) ?>"><?= View::e($incident['severity'] ?? '') ?></span>
                    <div>
                        <strong><?= View::e($incident['event'] ?? '') ?></strong>
                        <p><?= View::e($incident['message'] ?? '') ?></p>
                        <small><?= View::e($incident['created_at'] ?? '') ?></small>
                    </div>
                </article>
            <?php endforeach; ?>
            <?php if (!$incidents): ?><div class="empty-state">Nenhum incidente operacional registrado.</div><?php endif; ?>
        </div>
    </section>
</div>

<section class="card" style="margin-top:16px">
    <div class="section-heading"><div><span class="eyebrow">Webhook de backup externo</span><h2>Integração opcional com cron/n8n</h2></div></div>
    <p class="muted-text">Configure um cron externo ou fluxo n8n para registrar o resultado do backup usando:</p>
    <pre class="codebox">POST <?= View::e(Router::url('/webhooks/operations/backups?token=SEU_TOKEN')) ?>
{
  "status": "success",
  "backup_type": "automatic",
  "file_name": "rs-connect-2026-07-13.sql.gz",
  "location": "s3://bucket/rs-connect-2026-07-13.sql.gz",
  "size_bytes": 1234567,
  "checksum": "sha256...",
  "notes": "Backup diário concluído"
}</pre>
</section>


<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.querySelector('[data-operations-check-form]');
    if (!form) return;

    const button = form.querySelector('[data-operations-check-button]');
    const status = document.querySelector('[data-operations-check-status]');
    const originalText = button ? button.textContent : 'Verificar agora';

    form.addEventListener('submit', async function (event) {
        event.preventDefault();

        if (button) {
            button.disabled = true;
            button.textContent = 'Verificando...';
        }
        if (status) {
            status.textContent = 'Executando verificações. Aguarde alguns segundos...';
        }

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            const payload = await response.json();
            if (!response.ok || !payload.ok) {
                throw new Error(payload.message || 'Não foi possível executar a verificação.');
            }

            const summary = payload.data && payload.data.summary ? payload.data.summary : {};
            Object.keys(summary).forEach(function (key) {
                const target = document.querySelector('[data-operations-kpi="' + key + '"]');
                if (target) target.textContent = summary[key];
            });

            if (status) {
                status.textContent = 'Verificação concluída em ' + (payload.checked_at || 'agora') + '. Atualizando painel...';
            }
            if (button) {
                button.textContent = 'Concluído';
            }

            window.setTimeout(function () {
                window.location.href = (payload.redirect || form.action.replace('/checks/run', '')) + '?checked=' + Date.now();
            }, 650);
        } catch (error) {
            if (status) {
                status.textContent = error.message + ' Tentando pelo envio tradicional...';
            }
            form.submit();
        } finally {
            window.setTimeout(function () {
                if (button) {
                    button.disabled = false;
                    button.textContent = originalText;
                }
            }, 1200);
        }
    });
});
</script>
