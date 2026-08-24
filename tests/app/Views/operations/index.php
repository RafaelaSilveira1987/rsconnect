<?php

use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;
use App\Services\OperationalLanguageService;

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
$statusLabel = static fn (string $status): string => OperationalLanguageService::severityLabel($status);
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
                <h2><?= View::e((string) ($overall['label'] ?? 'Ainda não verificado')) ?></h2>
                <span class="badge <?= $statusBadge((string) ($overall['status'] ?? 'unknown')) ?>"><?= $statusLabel((string) ($overall['status'] ?? 'unknown')) ?></span>
            </div>
            <p>Veja o que está funcionando, o que precisa de atenção e onde agir. Os detalhes técnicos ficam disponíveis somente quando necessários.</p>
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
        <div><span>Áreas acompanhadas</span><strong><?= (int) ($overall['total'] ?? count($checks)) ?></strong></div>
        <div><span>Tudo normal</span><strong><?= (int) ($summary['healthy'] ?? 0) ?></strong></div>
        <div><span>Com atenção</span><strong><?= (int) ($summary['warning'] ?? 0) ?></strong></div>
        <div><span>Ainda não verificado</span><strong><?= (int) ($summary['unknown'] ?? 0) ?></strong></div>
    </div>
</section>

<div class="report-kpi-grid operations-kpis operations-kpis-v2">
    <article class="card report-kpi is-success"><span>Tudo normal</span><strong data-operations-kpi="healthy"><?= (int) ($summary['healthy'] ?? 0) ?></strong><small>Evidência recente e válida</small></article>
    <article class="card report-kpi is-warning"><span>Atenções</span><strong data-operations-kpi="warning"><?= (int) ($summary['warning'] ?? 0) ?></strong><small>Funciona, mas requer revisão</small></article>
    <article class="card report-kpi is-danger"><span>Ação imediata</span><strong data-operations-kpi="down"><?= (int) ($summary['down'] ?? 0) ?></strong><small>Pode interromper uma parte do sistema</small></article>
    <article class="card report-kpi"><span>Situações em aberto</span><strong data-operations-kpi="alerts"><?= (int) ($summary['alerts'] ?? 0) ?></strong><small>Itens que ainda precisam de análise</small></article>
</div>

<div class="operations-grid operations-main-grid">
    <section class="card operations-health-panel" data-operations-monitor>
        <div class="section-heading operations-health-heading">
            <div><span class="eyebrow">Acompanhamento</span><h2>Áreas e rotinas do sistema</h2><p>Pesquise uma área ou filtre pelo estado para encontrar rapidamente o que precisa de ação.</p></div>
            <span class="badge"><?= count($checks) ?> verificações</span>
        </div>

        <div class="operations-monitor-toolbar">
            <label class="operations-monitor-search"><span class="sr-only">Buscar</span><input type="search" placeholder="Buscar WhatsApp, cobrança, assistente, agenda, cópia..." data-operations-search></label>
            <select aria-label="Filtrar categoria" data-operations-category>
                <option value="all">Todas as áreas</option>
                <option value="integration">Integrações</option>
                <option value="routine">Rotinas automáticas</option>
                <option value="infrastructure">Infraestrutura</option>
            </select>
            <div class="operations-filter-chips" aria-label="Filtrar situação">
                <button class="is-active" type="button" data-operations-status="all">Todos</button>
                <button type="button" data-operations-status="down">Ação imediata</button>
                <button type="button" data-operations-status="warning">Atenção</button>
                <button type="button" data-operations-status="unknown">Ainda não verificado</button>
                <button type="button" data-operations-status="ok">Tudo normal</button>
            </div>
        </div>

        <div class="operations-check-list operations-check-list-v2" data-operations-list>
            <?php foreach ($checks as $check): ?>
                <?php
                $checkKey = (string) ($check['check_key'] ?? '');
                $checkStatus = (string) ($check['status'] ?? 'unknown');
                $checkCategory = (string) ($check['category'] ?? 'infrastructure');
                $historyItems = $checkHistory[$checkKey] ?? [];
                $checkPresentation = OperationalLanguageService::check($check, false);
                $searchText = mb_strtolower(trim(implode(' ', [
                    (string) ($checkPresentation['label'] ?? ''), (string) ($checkPresentation['summary'] ?? ''),
                    (string) ($check['category_label'] ?? ''), $checkKey,
                ])));
                ?>
                <article class="operations-check-row operations-check-row-v2 is-<?= View::e($checkStatus) ?>"
                         data-operations-row data-status="<?= View::e($checkStatus) ?>" data-category="<?= View::e($checkCategory) ?>" data-search="<?= View::e($searchText) ?>">
                    <span class="operations-status-dot" aria-hidden="true"></span>
                    <div class="operations-check-copy">
                        <div class="operations-check-title">
                            <strong><?= View::e((string) $checkPresentation['label']) ?></strong>
                            <span><?= View::e((string) ($check['category_label'] ?? 'Infraestrutura')) ?></span>
                        </div>
                        <p><?= View::e((string) $checkPresentation['summary']) ?></p>
                        <small><?= !empty($check['checked_at']) ? 'Evidência verificada em ' . View::e((string) $check['checked_at']) : 'Nenhuma evidência registrada ainda.' ?><?= isset($check['latency_ms']) && $check['latency_ms'] !== null ? ' · ' . (int) $check['latency_ms'] . 'ms' : '' ?></small>
                        <?php if (($checkPresentation['technical_message'] ?? '') !== ''): ?><details class="health-technical-details"><summary>Ver detalhes técnicos</summary><pre><?= View::e(trim((string) $checkPresentation['technical_event'] . "
" . (string) $checkPresentation['technical_title'] . "
" . (string) $checkPresentation['technical_message'])) ?></pre></details><?php endif; ?>
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
                                <button class="btn btn-small btn-outline" type="submit">Gerar cópia agora</button>
                            </form>
                        <?php endif; ?>
                        <?php if (!empty($check['route'])): ?><a class="btn btn-small btn-quiet" href="<?= View::e(Router::url((string) $check['route'])) ?>">Abrir área relacionada</a><?php endif; ?>
                        <?php if ($historyItems): ?>
                            <details class="operations-check-history"><summary>Histórico</summary><div>
                                <?php foreach ($historyItems as $historyItem): ?>
                                    <?php $historyPresentation = OperationalLanguageService::check(array_merge($historyItem, ['check_key' => $checkKey, 'label' => $check['label'] ?? '']), false); ?>
                                    <p><span class="badge <?= $statusBadge((string) ($historyItem['status'] ?? 'warning')) ?>"><?= $statusLabel((string) ($historyItem['status'] ?? 'warning')) ?></span><strong><?= View::e((string) ($historyItem['checked_at'] ?? '')) ?></strong><small><?= View::e((string) $historyPresentation['summary']) ?></small></p>
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
                <strong><?= View::e($lastBackup['file_name'] ?? 'Cópia registrada') ?></strong>
                <p><?= View::e($storageLabel((string) ($lastBackup['storage_type'] ?? 'manual_local'))) ?><?= !empty($lastBackup['size_bytes']) ? ' · ' . View::e($formatBytes($lastBackup['size_bytes'])) : '' ?></p>
                <small>Último registro: <?= View::e($lastBackup['finished_at'] ?? $lastBackup['created_at'] ?? '') ?></small>
            </div>
        <?php else: ?>
            <div class="operations-backup-card pending"><span class="badge badge-warning">Ainda não verificado</span><strong>Nenhuma cópia registrada</strong><p>Abra a área de cópias de segurança para configurar e validar a rotina.</p></div>
        <?php endif; ?>
        <a class="btn btn-primary btn-block" href="<?= View::e(Router::url('/central-operacao?tab=backups')) ?>">Abrir cópias de segurança</a>
    </aside>
</div>

<div class="operations-grid" style="margin-top:16px">
    <section class="card">
        <div class="section-heading"><div><span class="eyebrow">Situação atual</span><h2>O que precisa de atenção</h2></div></div>
        <div class="operations-alert-list" data-collapsible-list="3">
            <?php foreach ($alerts as $alert): ?>
                <?php $alertPresentation = OperationalLanguageService::incident($alert, false); ?>
                <article class="operations-alert is-<?= View::e($alert['type'] ?? 'warning') ?>">
                    <div>
                        <strong><?= View::e((string) $alertPresentation['title']) ?></strong>
                        <p><b>O que aconteceu:</b> <?= View::e((string) $alertPresentation['summary']) ?></p>
                        <p><b>O que fazer:</b> <?= View::e((string) $alertPresentation['action']) ?></p>
                        <?php if (!empty($alert['created_at'])): ?><small><?= View::e($alert['created_at']) ?></small><?php endif; ?>
                        <details class="health-technical-details"><summary>Ver detalhes técnicos</summary><pre><?= View::e(trim((string) $alertPresentation['technical_event'] . "
" . (string) $alertPresentation['technical_title'] . "
" . (string) $alertPresentation['technical_message'])) ?></pre></details>
                    </div>
                    <?php if (!empty($alert['id'])): ?>
                        <form method="post" action="<?= View::e(Router::url('/operations/incidents/resolve')) ?>">
                            <?= Csrf::input() ?>
                            <input type="hidden" name="id" value="<?= (int) $alert['id'] ?>">
                            <button class="btn btn-quiet" type="submit">Marcar como normalizado</button>
                        </form>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
            <?php if (!$alerts): ?><div class="empty-state">Nenhuma situação precisa de atenção neste momento.</div><?php endif; ?>
        </div>
    </section>

    <section class="card">
        <div class="section-heading"><div><span class="eyebrow">Orientações</span><h2>Como corrigir</h2></div></div>
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
        <div class="section-heading"><div><span class="eyebrow">Histórico</span><h2>Situações registradas</h2></div></div>
        <div class="security-timeline" data-collapsible-list="3">
            <?php foreach ($incidents as $incident): ?>
                <?php $incidentPresentation = OperationalLanguageService::incident($incident, false); ?>
                <article class="security-event">
                    <span class="badge <?= $statusBadge((string) ($incident['severity'] ?? 'warning')) ?>"><?= View::e((string) $incidentPresentation['severity_label']) ?></span>
                    <div>
                        <strong><?= View::e((string) $incidentPresentation['title']) ?></strong>
                        <p><?= View::e((string) $incidentPresentation['summary']) ?></p>
                        <small><?= View::e($incident['created_at'] ?? '') ?><?= !empty($incident['resolved_at']) ? ' · normalizado em ' . View::e($incident['resolved_at']) : '' ?></small>
                        <details class="health-technical-details"><summary>Ver detalhes técnicos</summary><pre><?= View::e(trim((string) $incidentPresentation['technical_event'] . "
" . (string) $incidentPresentation['technical_title'] . "
" . (string) $incidentPresentation['technical_message'])) ?></pre></details>
                    </div>
                    <?php if (empty($incident['resolved_at']) && in_array((string) ($incident['severity'] ?? ''), ['warning', 'error', 'critical'], true)): ?>
                        <form method="post" action="<?= View::e(Router::url('/operations/incidents/resolve')) ?>">
                            <?= Csrf::input() ?>
                            <input type="hidden" name="id" value="<?= (int) $incident['id'] ?>">
                            <button class="btn btn-quiet" type="submit">Marcar como normalizado</button>
                        </form>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
            <?php if (!$incidents): ?><div class="empty-state">Nenhuma situação foi registrada.</div><?php endif; ?>
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
