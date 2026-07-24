<?php

use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;

$summary = $data['summary'] ?? [];
$checks = $data['checks'] ?? [];
$lastBackup = $data['last_backup'] ?? null;
$activeBackupRoutine = $data['active_backup_routine'] ?? null;
$backups = $data['backups'] ?? [];
$alerts = $data['alerts'] ?? [];
$incidents = $data['incidents'] ?? [];
$recovery = $data['recovery'] ?? [];
$settings = $data['settings'] ?? [];
$overall = $data['overall'] ?? [];
$checkHistory = $data['check_history'] ?? [];
$lastCheckedAt = $overall['last_checked_at'] ?? null;
$statusBadge = static fn (string $status): string => match ($status) {
    'ok', 'success', 'info' => 'badge-success',
    'down', 'error', 'failed', 'critical' => 'badge-danger',
    'running' => 'badge-info',
    'unknown' => 'badge-neutral',
    default => 'badge-warning',
};
$statusLabel = static fn (string $status): string => match ($status) {
    'ok' => 'Operando',
    'down' => 'Falha',
    'success' => 'Sucesso',
    'error', 'failed' => 'Erro',
    'critical' => 'Crítico',
    'running' => 'Executando',
    'info' => 'Info',
    'warning' => 'Atenção',
    'unknown' => 'Sem evidência',
    default => 'Atenção',
};
$storageLabel = static fn (string $storage): string => match ($storage) {
    'manual_local' => 'Local da minha máquina',
    'server' => 'Servidor/VPS',
    'easypanel' => 'EasyPanel/Provedor',
    'google_drive' => 'Google Drive',
    's3_minio' => 'S3/MinIO',
    'dropbox' => 'Dropbox',
    default => 'Outro',
};
$formatBytes = static function ($bytes): string {
    if ($bytes === null || $bytes === '') return '-';
    $bytes = (float) $bytes;
    if ($bytes <= 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $power = min((int) floor(log($bytes, 1024)), count($units) - 1);
    return round($bytes / (1024 ** $power), 2) . ' ' . $units[$power];
};
?>

<section class="card operations-command-hero is-<?= View::e((string) ($overall['status'] ?? 'unknown')) ?>">
    <div class="operations-command-main">
        <div>
            <span class="eyebrow">Saúde do RS Connect</span>
            <div class="operations-command-title-row">
                <h2><?= View::e((string) ($overall['label'] ?? 'Sem evidência')) ?></h2>
                <span class="badge <?= $statusBadge((string) ($overall['status'] ?? 'unknown')) ?>"><?= $statusLabel((string) ($overall['status'] ?? 'unknown')) ?></span>
            </div>
            <p>Validação operacional de integrações, rotinas automáticas e infraestrutura. Cada item mostra a evidência usada para definir o status.</p>
        </div>
        <div class="operations-command-actions">
            <form method="post" action="<?= View::e(Router::url('/operations/checks/run')) ?>" data-operations-check-form>
                <?= Csrf::input() ?>
                <input type="hidden" name="return_to" value="<?= View::e(str_starts_with((string) ($_SERVER['REQUEST_URI'] ?? ''), '/central-operacao') ? '/central-operacao?tab=monitoring' : '/operations') ?>">
                <button class="btn btn-primary" type="submit" data-operations-check-button>Verificar sistema agora</button>
            </form>
            <small data-operations-check-status><?= $lastCheckedAt ? 'Última verificação: ' . View::e((string) $lastCheckedAt) : 'Ainda não existe uma verificação completa.' ?></small>
        </div>
    </div>
    <div class="operations-command-evidence">
        <div><span>Ferramentas acompanhadas</span><strong><?= (int) ($overall['total'] ?? count($checks)) ?></strong></div>
        <div><span>Operando</span><strong><?= (int) ($summary['healthy'] ?? 0) ?></strong></div>
        <div><span>Com atenção</span><strong><?= (int) ($summary['warning'] ?? 0) ?></strong></div>
        <div><span>Sem evidência</span><strong><?= (int) ($summary['unknown'] ?? 0) ?></strong></div>
    </div>
</section>

<div class="report-kpi-grid operations-kpis operations-kpis-v2">
    <article class="card report-kpi is-success"><span>Operando</span><strong data-operations-kpi="healthy"><?= (int) ($summary['healthy'] ?? 0) ?></strong><small>Evidência recente e válida</small></article>
    <article class="card report-kpi is-warning"><span>Atenções</span><strong data-operations-kpi="warning"><?= (int) ($summary['warning'] ?? 0) ?></strong><small>Funciona, mas requer revisão</small></article>
    <article class="card report-kpi is-danger"><span>Críticos</span><strong data-operations-kpi="down"><?= (int) ($summary['down'] ?? 0) ?></strong><small>Falha ou estrutura obrigatória ausente</small></article>
    <article class="card report-kpi"><span>Alertas abertos</span><strong data-operations-kpi="alerts"><?= (int) ($summary['alerts'] ?? 0) ?></strong><small>Incidentes que ainda precisam de ação</small></article>
</div>

<div class="operations-grid operations-main-grid">
    <section class="card operations-health-panel" data-operations-monitor>
        <div class="section-heading operations-health-heading">
            <div><span class="eyebrow">Monitoramento</span><h2>Ferramentas e rotinas</h2><p>Pesquise uma ferramenta ou filtre pelo estado e pelo tipo de validação.</p></div>
            <span class="badge"><?= count($checks) ?> verificações</span>
        </div>

        <div class="operations-monitor-toolbar">
            <label class="operations-monitor-search"><span class="sr-only">Buscar</span><input type="search" placeholder="Buscar Evolution, cobrança, IA, agenda, backup..." data-operations-search></label>
            <select aria-label="Filtrar categoria" data-operations-category>
                <option value="all">Todas as áreas</option>
                <option value="integration">Integrações</option>
                <option value="routine">Rotinas automáticas</option>
                <option value="infrastructure">Infraestrutura</option>
            </select>
            <div class="operations-filter-chips" aria-label="Filtrar situação">
                <button class="is-active" type="button" data-operations-status="all">Todos</button>
                <button type="button" data-operations-status="down">Críticos</button>
                <button type="button" data-operations-status="warning">Atenção</button>
                <button type="button" data-operations-status="unknown">Sem evidência</button>
                <button type="button" data-operations-status="ok">Operando</button>
            </div>
        </div>

        <div class="operations-check-list operations-check-list-v2" data-operations-list>
            <?php foreach ($checks as $check): ?>
                <?php
                $checkKey = (string) ($check['check_key'] ?? '');
                $checkStatus = (string) ($check['status'] ?? 'unknown');
                $checkCategory = (string) ($check['category'] ?? 'infrastructure');
                $historyItems = $checkHistory[$checkKey] ?? [];
                $searchText = mb_strtolower(trim(implode(' ', [
                    (string) ($check['label'] ?? ''), (string) ($check['message'] ?? ''),
                    (string) ($check['category_label'] ?? ''), $checkKey,
                ])));
                ?>
                <article class="operations-check-row operations-check-row-v2 is-<?= View::e($checkStatus) ?>"
                         data-operations-row data-status="<?= View::e($checkStatus) ?>" data-category="<?= View::e($checkCategory) ?>" data-search="<?= View::e($searchText) ?>">
                    <span class="operations-status-dot" aria-hidden="true"></span>
                    <div class="operations-check-copy">
                        <div class="operations-check-title">
                            <strong><?= View::e($check['label'] ?? $checkKey) ?></strong>
                            <span><?= View::e((string) ($check['category_label'] ?? 'Infraestrutura')) ?></span>
                        </div>
                        <p><?= View::e($check['message'] ?? '') ?></p>
                        <small><?= !empty($check['checked_at']) ? 'Evidência verificada em ' . View::e((string) $check['checked_at']) : 'Nenhuma evidência registrada ainda.' ?><?= isset($check['latency_ms']) && $check['latency_ms'] !== null ? ' · ' . (int) $check['latency_ms'] . 'ms' : '' ?></small>
                    </div>
                    <div class="operations-check-state"><span class="badge <?= $statusBadge($checkStatus) ?>"><?= $statusLabel($checkStatus) ?></span></div>
                    <div class="operations-check-actions">
                        <?php if ($checkKey === 'billing_cron'): ?>
                            <form method="post" action="<?= View::e(Router::url('/billing-reminders/run')) ?>">
                                <?= Csrf::input() ?><input type="hidden" name="return_to" value="/central-operacao?tab=monitoring">
                                <button class="btn btn-small btn-outline" type="submit">Processar agora</button>
                            </form>
                        <?php elseif ($checkKey === 'backup' && !empty($activeBackupRoutine['id'])): ?>
                            <form method="post" action="<?= View::e(Router::url('/backup-automatico/trigger')) ?>">
                                <?= Csrf::input() ?><input type="hidden" name="routine_id" value="<?= (int) $activeBackupRoutine['id'] ?>"><input type="hidden" name="trigger_type" value="manual"><input type="hidden" name="return_to" value="/central-operacao?tab=monitoring">
                                <button class="btn btn-small btn-outline" type="submit">Executar backup</button>
                            </form>
                        <?php endif; ?>
                        <?php if (!empty($check['route'])): ?><a class="btn btn-small btn-quiet" href="<?= View::e(Router::url((string) $check['route'])) ?>">Abrir ferramenta</a><?php endif; ?>
                        <?php if ($historyItems): ?>
                            <details class="operations-check-history"><summary>Histórico</summary><div>
                                <?php foreach ($historyItems as $historyItem): ?>
                                    <p><span class="badge <?= $statusBadge((string) ($historyItem['status'] ?? 'warning')) ?>"><?= $statusLabel((string) ($historyItem['status'] ?? 'warning')) ?></span><strong><?= View::e((string) ($historyItem['checked_at'] ?? '')) ?></strong><small><?= View::e((string) ($historyItem['message'] ?? '')) ?></small></p>
                                <?php endforeach; ?>
                            </div></details>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
            <div class="empty-state" data-operations-empty hidden>Nenhuma ferramenta corresponde aos filtros selecionados.</div>
        </div>
    </section>

    <aside class="card operations-quick-panel">
        <div class="section-heading">
            <div><span class="eyebrow">Atalho operacional</span><h2>Backup</h2><p>O histórico, configuração, disparo e detalhes técnicos ficam concentrados na aba Backups.</p></div>
        </div>
        <?php if ($lastBackup): ?>
            <div class="operations-backup-card">
                <span class="badge <?= $statusBadge((string) ($lastBackup['status'] ?? 'warning')) ?>"><?= $statusLabel((string) ($lastBackup['status'] ?? 'warning')) ?></span>
                <?php if (!empty($lastBackup['verified_at'])): ?><span class="badge badge-success">Verificado</span><?php endif; ?>
                <strong><?= View::e($lastBackup['file_name'] ?? 'Backup registrado') ?></strong>
                <p><?= View::e($storageLabel((string) ($lastBackup['storage_type'] ?? 'manual_local'))) ?><?= !empty($lastBackup['size_bytes']) ? ' · ' . View::e($formatBytes($lastBackup['size_bytes'])) : '' ?></p>
                <small>Último registro: <?= View::e($lastBackup['finished_at'] ?? $lastBackup['created_at'] ?? '') ?></small>
            </div>
        <?php else: ?>
            <div class="operations-backup-card pending"><span class="badge badge-warning">Sem evidência</span><strong>Nenhum backup registrado</strong><p>Abra a aba Backups para configurar e validar a rotina.</p></div>
        <?php endif; ?>
        <a class="btn btn-primary btn-block" href="<?= View::e(Router::url('/central-operacao?tab=backups')) ?>">Abrir Backups</a>
    </aside>
</div>

<div class="operations-grid" style="margin-top:16px">
    <section class="card">
        <div class="section-heading"><div><span class="eyebrow">Alertas ativos</span><h2>O que precisa de atenção</h2></div></div>
        <div class="operations-alert-list" data-collapsible-list="3">
            <?php foreach ($alerts as $alert): ?>
                <article class="operations-alert is-<?= View::e($alert['type'] ?? 'warning') ?>">
                    <div>
                        <strong><?= View::e($alert['title'] ?? '') ?></strong>
                        <p><?= View::e($alert['message'] ?? '') ?></p>
                        <?php if (!empty($alert['created_at'])): ?><small><?= View::e($alert['created_at']) ?></small><?php endif; ?>
                    </div>
                    <?php if (!empty($alert['id'])): ?>
                        <form method="post" action="<?= View::e(Router::url('/operations/incidents/resolve')) ?>">
                            <?= Csrf::input() ?>
                            <input type="hidden" name="id" value="<?= (int) $alert['id'] ?>">
                            <button class="btn btn-quiet" type="submit">Resolver</button>
                        </form>
                    <?php endif; ?>
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

<div style="margin-top:16px">
    <section class="card">
        <div class="section-heading"><div><span class="eyebrow">Incidentes</span><h2>Eventos operacionais</h2></div></div>
        <div class="security-timeline" data-collapsible-list="3">
            <?php foreach ($incidents as $incident): ?>
                <article class="security-event">
                    <span class="badge <?= $statusBadge((string) ($incident['severity'] ?? 'warning')) ?>"><?= View::e($incident['severity'] ?? '') ?></span>
                    <div>
                        <strong><?= View::e($incident['event'] ?? '') ?></strong>
                        <p><?= View::e($incident['message'] ?? '') ?></p>
                        <small><?= View::e($incident['created_at'] ?? '') ?><?= !empty($incident['resolved_at']) ? ' · resolvido em ' . View::e($incident['resolved_at']) : '' ?></small>
                    </div>
                    <?php if (empty($incident['resolved_at']) && in_array((string) ($incident['severity'] ?? ''), ['warning', 'error', 'critical'], true)): ?>
                        <form method="post" action="<?= View::e(Router::url('/operations/incidents/resolve')) ?>">
                            <?= Csrf::input() ?>
                            <input type="hidden" name="id" value="<?= (int) $incident['id'] ?>">
                            <button class="btn btn-quiet" type="submit">Resolver</button>
                        </form>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
            <?php if (!$incidents): ?><div class="empty-state">Nenhum incidente operacional registrado.</div><?php endif; ?>
        </div>
    </section>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const monitor = document.querySelector('[data-operations-monitor]');
    if (!monitor) return;
    const search = monitor.querySelector('[data-operations-search]');
    const category = monitor.querySelector('[data-operations-category]');
    const statusButtons = Array.from(monitor.querySelectorAll('[data-operations-status]'));
    const rows = Array.from(monitor.querySelectorAll('[data-operations-row]'));
    const empty = monitor.querySelector('[data-operations-empty]');
    let activeStatus = 'all';
    const normalize = (value) => (value || '').toLocaleLowerCase('pt-BR').normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    const apply = () => {
        const term = normalize(search?.value || '');
        const selectedCategory = category?.value || 'all';
        let visible = 0;
        rows.forEach((row) => {
            const matchesText = term === '' || normalize(row.dataset.search || row.textContent).includes(term);
            const matchesStatus = activeStatus === 'all' || row.dataset.status === activeStatus;
            const matchesCategory = selectedCategory === 'all' || row.dataset.category === selectedCategory;
            row.hidden = !(matchesText && matchesStatus && matchesCategory);
            if (!row.hidden) visible += 1;
        });
        if (empty) empty.hidden = visible > 0;
    };
    search?.addEventListener('input', apply);
    category?.addEventListener('change', apply);
    statusButtons.forEach((button) => button.addEventListener('click', () => {
        activeStatus = button.dataset.operationsStatus || 'all';
        statusButtons.forEach((item) => item.classList.toggle('is-active', item === button));
        apply();
    }));
});
</script>

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
                status.textContent = 'Verificação concluída. Atualizando o painel...';
            }
            if (button) {
                button.textContent = 'Atualizando...';
            }

            const redirect = payload.redirect || window.location.href;
            const separator = redirect.includes('?') ? '&' : '?';
            window.location.assign(redirect + separator + 'refresh=' + Date.now());
            return;
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
