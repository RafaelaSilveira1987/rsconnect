<?php

use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;
use App\Services\OperationalLanguageService;

$summary = $data['summary'] ?? [];
$checks = $data['checks'] ?? [];
$lastBackup = $data['last_backup'] ?? null;
$activeBackupRoutine = $data['active_backup_routine'] ?? null;
$alerts = $data['alerts'] ?? [];
$incidents = $data['incidents'] ?? [];
$recovery = $data['recovery'] ?? [];
$overall = $data['overall'] ?? [];
$analytics = $data['analytics'] ?? [];
$checkHistory = $data['check_history'] ?? [];
$lastCheckedAt = $overall['last_checked_at'] ?? null;
$trend = is_array($analytics['trend_7d'] ?? null) ? $analytics['trend_7d'] : [];
$distribution = is_array($analytics['status_distribution'] ?? null) ? $analytics['status_distribution'] : [];
$attentionByArea = is_array($analytics['attention_by_area'] ?? null) ? $analytics['attention_by_area'] : [];
$healthScore = max(0, min(100, (int) ($analytics['health_score'] ?? 0)));
$trendMax = 1;
foreach ($trend as $trendItem) {
    $trendMax = max($trendMax, (int) ($trendItem['opened'] ?? 0), (int) ($trendItem['resolved'] ?? 0));
}

$statusBadge = static fn (string $status): string => match ($status) {
    'ok', 'success', 'info', 'resolved', 'normalized' => 'badge-success',
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
$isMessagingIncident = static function (string $event): bool {
    return str_starts_with($event, 'operations.alert.evolution') || $event === 'operations.alert.message_queue';
};
$formatBytes = static function ($bytes): string {
    if ($bytes === null || $bytes === '') return '-';
    $bytes = (float) $bytes;
    if ($bytes <= 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $power = min((int) floor(log($bytes, 1024)), count($units) - 1);
    return round($bytes / (1024 ** $power), 2) . ' ' . $units[$power];
};
$incidentRoute = static fn (string $event): string => match (true) {
    str_contains($event, 'payments') => '/payment-gateways',
    str_contains($event, 'evolution') => '/instances',
    str_contains($event, 'message_queue') => '/conversations',
    str_contains($event, 'calendar') => '/calendar/availability',
    str_contains($event, 'openai') => '/ai-credentials',
    str_contains($event, 'n8n') => '/n8n',
    str_contains($event, 'backup') => '/central-operacao?tab=backups',
    default => '/central-operacao?tab=monitoring',
};
$incidentRouteLabel = static fn (string $event): string => match (true) {
    str_contains($event, 'payments') => 'Abrir financeiro',
    str_contains($event, 'evolution') => 'Abrir WhatsApp',
    str_contains($event, 'message_queue') => 'Ver conversas',
    str_contains($event, 'calendar') => 'Abrir agenda',
    str_contains($event, 'openai') => 'Abrir assistente',
    str_contains($event, 'n8n') => 'Abrir automações',
    str_contains($event, 'backup') => 'Abrir backups',
    default => 'Abrir área',
};
?>

<section class="card operations-command-hero operations-command-hero-v3 is-<?= View::e((string) ($overall['status'] ?? 'unknown')) ?>">
    <div class="operations-command-main">
        <div>
            <span class="eyebrow">Central de monitoramento</span>
            <div class="operations-command-title-row">
                <h2><?= View::e((string) ($overall['label'] ?? 'Ainda não verificado')) ?></h2>
                <span class="badge <?= $statusBadge((string) ($overall['status'] ?? 'unknown')) ?>"><?= $statusLabel((string) ($overall['status'] ?? 'unknown')) ?></span>
            </div>
            <p>O painel principal mostra somente o que exige decisão. Rotinas normais e registros antigos ficam separados para evitar excesso de informação.</p>
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
</section>

<div class="operations-summary-strip">
    <article class="card operations-summary-card is-danger">
        <span>Ação imediata</span>
        <strong data-operations-kpi="down"><?= (int) ($summary['down'] ?? 0) ?></strong>
        <small>Rotinas com risco de interrupção</small>
    </article>
    <article class="card operations-summary-card is-warning">
        <span>Em atenção</span>
        <strong data-operations-kpi="warning"><?= (int) ($summary['warning'] ?? 0) ?></strong>
        <small>Precisam ser revisadas</small>
    </article>
    <article class="card operations-summary-card is-open">
        <span>Situações abertas</span>
        <strong data-operations-kpi="alerts"><?= (int) ($analytics['open_incidents'] ?? $summary['alerts'] ?? 0) ?></strong>
        <small>Incidentes que ainda não foram encerrados</small>
    </article>
    <article class="card operations-summary-card is-success">
        <span>Resolvidas em 7 dias</span>
        <strong><?= (int) ($analytics['resolved_7d'] ?? 0) ?></strong>
        <small>Normalizações recentes</small>
    </article>
</div>

<div class="operations-insight-grid">
    <section class="card operations-trend-card">
        <div class="operations-card-heading">
            <div>
                <span class="eyebrow">Últimos 7 dias</span>
                <h3>Movimento das situações</h3>
            </div>
            <div class="operations-chart-legend" aria-label="Legenda do gráfico">
                <span><i class="is-opened"></i>Abertas</span>
                <span><i class="is-resolved"></i>Resolvidas</span>
            </div>
        </div>
        <div class="operations-bar-chart" role="img" aria-label="Situações abertas e resolvidas nos últimos sete dias">
            <?php foreach ($trend as $trendItem): ?>
                <?php
                $opened = (int) ($trendItem['opened'] ?? 0);
                $resolved = (int) ($trendItem['resolved'] ?? 0);
                $openedHeight = $opened > 0 ? max(8, (int) round(($opened / $trendMax) * 100)) : 2;
                $resolvedHeight = $resolved > 0 ? max(8, (int) round(($resolved / $trendMax) * 100)) : 2;
                ?>
                <div class="operations-chart-day" title="<?= View::e((string) ($trendItem['label'] ?? '')) ?>: <?= $opened ?> aberta(s), <?= $resolved ?> resolvida(s)">
                    <div class="operations-chart-bars">
                        <span class="is-opened" style="--bar-height:<?= $openedHeight ?>%"><b><?= $opened ?></b></span>
                        <span class="is-resolved" style="--bar-height:<?= $resolvedHeight ?>%"><b><?= $resolved ?></b></span>
                    </div>
                    <small><?= View::e((string) ($trendItem['label'] ?? '')) ?></small>
                </div>
            <?php endforeach; ?>
            <?php if (!$trend): ?><div class="empty-state">O gráfico será preenchido após as próximas verificações.</div><?php endif; ?>
        </div>
    </section>

    <section class="card operations-health-score-card">
        <div class="operations-card-heading">
            <div><span class="eyebrow">Estado atual</span><h3>Saúde das rotinas</h3></div>
            <span class="badge"><?= count($checks) ?> rotinas monitoradas</span>
        </div>
        <div class="operations-health-score-body">
            <div class="operations-health-donut" style="--health-score:<?= $healthScore ?>" aria-label="<?= $healthScore ?> por cento das rotinas estão normais">
                <div><strong><?= $healthScore ?>%</strong><span>normais</span></div>
            </div>
            <div class="operations-health-legend">
                <p><i class="is-healthy"></i><span>Tudo normal</span><strong><?= (int) ($distribution['healthy'] ?? 0) ?></strong></p>
                <p><i class="is-warning"></i><span>Atenção</span><strong><?= (int) ($distribution['warning'] ?? 0) ?></strong></p>
                <p><i class="is-down"></i><span>Ação imediata</span><strong><?= (int) ($distribution['down'] ?? 0) ?></strong></p>
                <p><i class="is-unknown"></i><span>Sem evidência</span><strong><?= (int) ($distribution['unknown'] ?? 0) ?></strong></p>
            </div>
        </div>
    </section>
</div>

<nav class="operations-view-tabs" aria-label="Seções do monitoramento" data-monitor-tabs>
    <button class="is-active" type="button" data-monitor-tab="overview">Visão geral <span><?= (int) ($analytics['open_incidents'] ?? count($alerts)) ?></span></button>
    <button type="button" data-monitor-tab="routines">Rotinas <span><?= count($checks) ?></span></button>
    <button type="button" data-monitor-tab="history">Histórico <span><?= (int) ($analytics['history_total'] ?? count($incidents)) ?></span></button>
</nav>

<section data-monitor-view="overview">
    <div class="operations-overview-grid-v3">
        <section class="card operations-open-panel">
            <div class="operations-card-heading">
                <div>
                    <span class="eyebrow">Situação atual</span>
                    <h3>O que precisa de ação</h3>
                    <p>Somente situações abertas aparecem aqui. Registros encerrados ficam no Histórico.</p>
                </div>
                <span class="badge <?= count($alerts) > 0 ? 'badge-warning' : 'badge-success' ?>"><?= count($alerts) ?> aberta(s)</span>
            </div>

            <div class="operations-open-list" data-progressive-list data-page-size="4">
                <?php foreach ($alerts as $alert): ?>
                    <?php
                    $alertPresentation = OperationalLanguageService::incident($alert, false);
                    $alertEvent = (string) ($alert['event'] ?? '');
                    $alertMessagingIncident = $isMessagingIncident($alertEvent);
                    ?>
                    <article class="operations-open-incident is-<?= View::e((string) ($alert['type'] ?? 'warning')) ?>" data-progressive-item>
                        <span class="operations-status-dot" aria-hidden="true"></span>
                        <div class="operations-open-copy">
                            <div class="operations-open-title">
                                <strong><?= View::e((string) $alertPresentation['title']) ?></strong>
                                <span class="badge <?= $statusBadge((string) ($alert['type'] ?? 'warning')) ?>"><?= $statusLabel((string) ($alert['type'] ?? 'warning')) ?></span>
                            </div>
                            <p><?= View::e((string) $alertPresentation['summary']) ?></p>
                            <small><?= !empty($alert['created_at']) ? 'Identificada em ' . View::e((string) $alert['created_at']) : 'Identificada pela verificação mais recente.' ?></small>
                            <details class="operations-incident-details">
                                <summary>Orientação e detalhes</summary>
                                <div>
                                    <p><b>Impacto:</b> <?= View::e((string) $alertPresentation['impact']) ?></p>
                                    <p><b>O que fazer:</b> <?= View::e((string) $alertPresentation['action']) ?></p>
                                    <pre><?= View::e(trim((string) $alertPresentation['technical_event'] . "\n" . (string) $alertPresentation['technical_title'] . "\n" . (string) $alertPresentation['technical_message'])) ?></pre>
                                </div>
                            </details>
                        </div>
                        <div class="operations-open-actions">
                            <a class="btn btn-small btn-outline" href="<?= View::e(Router::url($incidentRoute($alertEvent))) ?>"><?= View::e($incidentRouteLabel($alertEvent)) ?></a>
                            <?php if (!empty($alert['id'])): ?>
                                <form method="post" action="<?= View::e(Router::url('/operations/incidents/resolve')) ?>"<?= $alertMessagingIncident ? ' data-confirm="As respostas pendentes ou com falha serão canceladas no histórico e não serão enviadas após a reconexão. Confirmar?"' : '' ?>>
                                    <?= Csrf::input() ?>
                                    <input type="hidden" name="id" value="<?= (int) $alert['id'] ?>">
                                    <?php if ($alertMessagingIncident): ?><input type="hidden" name="release_queue" value="1"><?php endif; ?>
                                    <button class="btn btn-small btn-primary" type="submit"><?= $alertMessagingIncident ? 'Resolver e liberar fila' : 'Marcar como normalizado' ?></button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
                <?php if (!$alerts): ?>
                    <div class="empty-state operations-success-state"><strong>Nenhuma situação aberta.</strong><span>O monitoramento não encontrou itens que exijam ação neste momento.</span></div>
                <?php endif; ?>
            </div>
        </section>

        <aside class="operations-overview-side">
            <?php if (array_filter($alerts, static fn (array $alert): bool => str_contains((string) ($alert['event'] ?? ''), 'payments'))): ?>
                <section class="card operations-explainer-card">
                    <span class="eyebrow">Sobre o aviso financeiro</span>
                    <h3>Não confirma que o Asaas está fora do ar</h3>
                    <p>O aviso indica que houve uma falha e ainda não apareceu uma operação bem-sucedida posterior que comprove a recuperação.</p>
                    <a class="btn btn-small btn-outline btn-block" href="<?= View::e(Router::url('/payment-gateways')) ?>">Conferir meio de pagamento</a>
                </section>
            <?php endif; ?>

            <section class="card operations-backup-summary-card">
                <div class="operations-card-heading">
                    <div><span class="eyebrow">Atalho</span><h3>Cópia de segurança</h3></div>
                </div>
                <?php if ($lastBackup): ?>
                    <div class="operations-backup-compact">
                        <span class="badge <?= $statusBadge((string) ($lastBackup['status'] ?? 'warning')) ?>"><?= $statusLabel((string) ($lastBackup['status'] ?? 'warning')) ?></span>
                        <strong><?= View::e((string) ($lastBackup['file_name'] ?? 'Cópia registrada')) ?></strong>
                        <p><?= View::e($storageLabel((string) ($lastBackup['storage_type'] ?? 'manual_local'))) ?><?= !empty($lastBackup['size_bytes']) ? ' · ' . View::e($formatBytes($lastBackup['size_bytes'])) : '' ?></p>
                        <small><?= View::e((string) ($lastBackup['finished_at'] ?? $lastBackup['created_at'] ?? '')) ?></small>
                    </div>
                <?php else: ?>
                    <div class="empty-state compact">Nenhuma cópia registrada.</div>
                <?php endif; ?>
                <a class="btn btn-small btn-outline btn-block" href="<?= View::e(Router::url('/central-operacao?tab=backups')) ?>">Abrir backups</a>
            </section>

            <?php if ($attentionByArea): ?>
                <section class="card operations-area-summary-card">
                    <span class="eyebrow">Concentração</span>
                    <h3>Atenções por área</h3>
                    <div class="operations-area-summary-list">
                        <?php foreach (array_slice($attentionByArea, 0, 4, true) as $area => $total): ?>
                            <p><span><?= View::e((string) $area) ?></span><strong><?= (int) $total ?></strong></p>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>
        </aside>
    </div>
</section>

<section data-monitor-view="routines" hidden>
    <section class="card operations-health-panel" data-operations-monitor>
        <div class="operations-card-heading operations-health-heading">
            <div>
                <span class="eyebrow">Rotinas monitoradas</span>
                <h3>Áreas e rotinas do sistema</h3>
                <p>Por padrão, a lista destaca apenas o que requer revisão. Use “Todas” para visualizar também as rotinas normais.</p>
            </div>
            <span class="badge"><?= count($checks) ?> rotinas monitoradas</span>
        </div>

        <div class="operations-monitor-toolbar operations-monitor-toolbar-v3">
            <label class="operations-monitor-search"><span class="sr-only">Buscar</span><input type="search" placeholder="Buscar WhatsApp, financeiro, agenda, backup..." data-operations-search></label>
            <select aria-label="Filtrar categoria" data-operations-category>
                <option value="all">Todas as áreas</option>
                <option value="integration">Integrações</option>
                <option value="routine">Rotinas automáticas</option>
                <option value="infrastructure">Infraestrutura</option>
            </select>
            <div class="operations-filter-chips" aria-label="Filtrar situação">
                <button class="is-active" type="button" data-operations-status="attention">Precisam de atenção</button>
                <button type="button" data-operations-status="down">Ação imediata</button>
                <button type="button" data-operations-status="warning">Atenção</button>
                <button type="button" data-operations-status="unknown">Sem evidência</button>
                <button type="button" data-operations-status="ok">Tudo normal</button>
                <button type="button" data-operations-status="all">Todas</button>
            </div>
        </div>

        <div class="operations-check-list operations-check-list-v3" data-operations-list>
            <?php foreach ($checks as $check): ?>
                <?php
                $checkKey = (string) ($check['check_key'] ?? '');
                $checkStatus = (string) ($check['status'] ?? 'unknown');
                $checkCategory = (string) ($check['category'] ?? 'infrastructure');
                $historyItems = $checkHistory[$checkKey] ?? [];
                $checkPresentation = OperationalLanguageService::check($check, false);
                $searchText = mb_strtolower(trim(implode(' ', [
                    (string) ($checkPresentation['label'] ?? ''),
                    (string) ($checkPresentation['summary'] ?? ''),
                    (string) ($check['category_label'] ?? ''),
                    $checkKey,
                ])));
                ?>
                <article class="operations-check-row operations-check-row-v3 is-<?= View::e($checkStatus) ?>"
                         data-operations-row data-status="<?= View::e($checkStatus) ?>" data-category="<?= View::e($checkCategory) ?>" data-search="<?= View::e($searchText) ?>">
                    <span class="operations-status-dot" aria-hidden="true"></span>
                    <div class="operations-check-copy">
                        <div class="operations-check-title">
                            <strong><?= View::e((string) $checkPresentation['label']) ?></strong>
                            <span><?= View::e((string) ($check['category_label'] ?? 'Infraestrutura')) ?></span>
                        </div>
                        <p><?= View::e((string) $checkPresentation['summary']) ?></p>
                        <small><?= !empty($check['checked_at']) ? 'Verificada em ' . View::e((string) $check['checked_at']) : 'Nenhuma evidência registrada.' ?><?= isset($check['latency_ms']) && $check['latency_ms'] !== null ? ' · ' . (int) $check['latency_ms'] . 'ms' : '' ?></small>
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
                        <?php if (!empty($check['route'])): ?><a class="btn btn-small btn-quiet" href="<?= View::e(Router::url((string) $check['route'])) ?>">Abrir área</a><?php endif; ?>
                        <?php if (($checkPresentation['technical_message'] ?? '') !== ''): ?>
                            <details class="operations-inline-details"><summary>Detalhes</summary><pre><?= View::e(trim((string) $checkPresentation['technical_event'] . "\n" . (string) $checkPresentation['technical_title'] . "\n" . (string) $checkPresentation['technical_message'])) ?></pre></details>
                        <?php endif; ?>
                        <?php if ($historyItems): ?>
                            <details class="operations-check-history"><summary>Últimas verificações</summary><div>
                                <?php foreach ($historyItems as $historyItem): ?>
                                    <?php $historyPresentation = OperationalLanguageService::check(array_merge($historyItem, ['check_key' => $checkKey, 'label' => $check['label'] ?? '']), false); ?>
                                    <p><span class="badge <?= $statusBadge((string) ($historyItem['status'] ?? 'warning')) ?>"><?= $statusLabel((string) ($historyItem['status'] ?? 'warning')) ?></span><strong><?= View::e((string) ($historyItem['checked_at'] ?? '')) ?></strong><small><?= View::e((string) $historyPresentation['summary']) ?></small></p>
                                <?php endforeach; ?>
                            </div></details>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
            <div class="empty-state" data-operations-empty hidden>Nenhuma rotina corresponde aos filtros selecionados.</div>
        </div>
    </section>
</section>

<section data-monitor-view="history" hidden>
    <section class="card operations-history-panel">
        <div class="operations-card-heading">
            <div>
                <span class="eyebrow">Histórico operacional</span>
                <h3>Situações registradas</h3>
                <p>Os registros são carregados de forma progressiva para não sobrecarregar a tela.</p>
            </div>
            <div class="operations-history-totals">
                <span><b><?= (int) ($analytics['open_incidents'] ?? 0) ?></b> abertas</span>
                <span><b><?= (int) ($analytics['resolved_total'] ?? 0) ?></b> resolvidas</span>
            </div>
        </div>

        <div class="operations-history-toolbar" data-history-filters>
            <button class="is-active" type="button" data-history-filter="all">Todos</button>
            <button type="button" data-history-filter="open">Abertos</button>
            <button type="button" data-history-filter="resolved">Resolvidos</button>
        </div>

        <div class="operations-history-list" data-history-list data-page-size="8">
            <?php foreach ($incidents as $incident): ?>
                <?php
                $incidentPresentation = OperationalLanguageService::incident($incident, false);
                $resolved = !empty($incident['resolved_at']);
                $historyMessagingIncident = $isMessagingIncident((string) ($incident['event'] ?? ''));
                ?>
                <article class="operations-history-row" data-history-item data-history-state="<?= $resolved ? 'resolved' : 'open' ?>">
                    <div class="operations-history-status">
                        <span class="badge <?= $resolved ? 'badge-success' : $statusBadge((string) ($incident['severity'] ?? 'warning')) ?>"><?= $resolved ? 'Normalizado' : View::e((string) $incidentPresentation['severity_label']) ?></span>
                    </div>
                    <div class="operations-history-copy">
                        <strong><?= View::e((string) $incidentPresentation['title']) ?></strong>
                        <p><?= View::e((string) $incidentPresentation['summary']) ?></p>
                        <small>Registrada em <?= View::e((string) ($incident['created_at'] ?? '')) ?><?= $resolved ? ' · normalizada em ' . View::e((string) $incident['resolved_at']) : '' ?></small>
                        <details class="operations-inline-details"><summary>Ver detalhes técnicos</summary><pre><?= View::e(trim((string) $incidentPresentation['technical_event'] . "\n" . (string) $incidentPresentation['technical_title'] . "\n" . (string) $incidentPresentation['technical_message'])) ?></pre></details>
                    </div>
                    <?php if (!$resolved && in_array((string) ($incident['severity'] ?? ''), ['warning', 'error', 'critical'], true)): ?>
                        <div class="operations-history-actions">
                            <a class="btn btn-small btn-outline" href="<?= View::e(Router::url($incidentRoute((string) ($incident['event'] ?? '')))) ?>"><?= View::e($incidentRouteLabel((string) ($incident['event'] ?? ''))) ?></a>
                            <form method="post" action="<?= View::e(Router::url('/operations/incidents/resolve')) ?>"<?= $historyMessagingIncident ? ' data-confirm="As respostas pendentes ou com falha serão canceladas no histórico. Confirmar?"' : '' ?>>
                                <?= Csrf::input() ?>
                                <input type="hidden" name="id" value="<?= (int) $incident['id'] ?>">
                                <?php if ($historyMessagingIncident): ?><input type="hidden" name="release_queue" value="1"><?php endif; ?>
                                <button class="btn btn-small btn-quiet" type="submit"><?= $historyMessagingIncident ? 'Resolver e liberar fila' : 'Normalizar' ?></button>
                            </form>
                        </div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
            <?php if (!$incidents): ?><div class="empty-state">Nenhuma situação foi registrada.</div><?php endif; ?>
        </div>
        <div class="operations-history-more" data-history-more-wrap hidden><button class="btn btn-outline" type="button" data-history-more>Carregar mais registros</button></div>
    </section>

    <section class="card operations-guidance-panel">
        <div class="operations-card-heading"><div><span class="eyebrow">Orientações</span><h3>Como corrigir situações recorrentes</h3></div></div>
        <div class="operations-playbooks operations-playbooks-grid">
            <?php foreach ($recovery as $playbook): ?>
                <details class="operations-playbook">
                    <summary><?= View::e((string) ($playbook['title'] ?? '')) ?></summary>
                    <ol><?php foreach (($playbook['steps'] ?? []) as $step): ?><li><?= View::e((string) $step) ?></li><?php endforeach; ?></ol>
                </details>
            <?php endforeach; ?>
        </div>
    </section>
</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const tabs = Array.from(document.querySelectorAll('[data-monitor-tab]'));
    const views = Array.from(document.querySelectorAll('[data-monitor-view]'));
    const activateView = function (name) {
        tabs.forEach((button) => button.classList.toggle('is-active', button.dataset.monitorTab === name));
        views.forEach((view) => { view.hidden = view.dataset.monitorView !== name; });
    };
    tabs.forEach((button) => button.addEventListener('click', function () {
        activateView(button.dataset.monitorTab || 'overview');
    }));

    const monitor = document.querySelector('[data-operations-monitor]');
    if (monitor) {
        const search = monitor.querySelector('[data-operations-search]');
        const category = monitor.querySelector('[data-operations-category]');
        const statusButtons = Array.from(monitor.querySelectorAll('[data-operations-status]'));
        const rows = Array.from(monitor.querySelectorAll('[data-operations-row]'));
        const empty = monitor.querySelector('[data-operations-empty]');
        let activeStatus = 'attention';
        const normalize = (value) => (value || '').toLocaleLowerCase('pt-BR').normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        const apply = () => {
            const term = normalize(search?.value || '');
            const selectedCategory = category?.value || 'all';
            let visible = 0;
            rows.forEach((row) => {
                const status = row.dataset.status || 'unknown';
                const matchesText = term === '' || normalize(row.dataset.search || row.textContent).includes(term);
                const matchesStatus = activeStatus === 'all'
                    || (activeStatus === 'attention' && status !== 'ok')
                    || status === activeStatus;
                const matchesCategory = selectedCategory === 'all' || row.dataset.category === selectedCategory;
                row.hidden = !(matchesText && matchesStatus && matchesCategory);
                if (!row.hidden) visible += 1;
            });
            if (empty) empty.hidden = visible > 0;
        };
        search?.addEventListener('input', apply);
        category?.addEventListener('change', apply);
        statusButtons.forEach((button) => button.addEventListener('click', () => {
            activeStatus = button.dataset.operationsStatus || 'attention';
            statusButtons.forEach((item) => item.classList.toggle('is-active', item === button));
            apply();
        }));
        apply();
    }

    document.querySelectorAll('[data-progressive-list]').forEach(function (list) {
        const items = Array.from(list.querySelectorAll('[data-progressive-item]'));
        const pageSize = Math.max(1, Number(list.dataset.pageSize || 4));
        if (items.length <= pageSize) return;
        let visible = pageSize;
        const wrap = document.createElement('div');
        wrap.className = 'operations-list-more';
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-outline';
        button.textContent = 'Mostrar mais situações';
        wrap.appendChild(button);
        list.appendChild(wrap);
        const render = function () {
            items.forEach((item, index) => { item.hidden = index >= visible; });
            wrap.hidden = visible >= items.length;
        };
        button.addEventListener('click', function () { visible += pageSize; render(); });
        render();
    });

    const historyList = document.querySelector('[data-history-list]');
    if (historyList) {
        const items = Array.from(historyList.querySelectorAll('[data-history-item]'));
        const filters = Array.from(document.querySelectorAll('[data-history-filter]'));
        const moreButton = document.querySelector('[data-history-more]');
        const moreWrap = document.querySelector('[data-history-more-wrap]');
        const pageSize = Math.max(1, Number(historyList.dataset.pageSize || 8));
        let activeFilter = 'all';
        let visibleLimit = pageSize;
        const renderHistory = function () {
            const filtered = items.filter((item) => activeFilter === 'all' || item.dataset.historyState === activeFilter);
            filtered.forEach((item, index) => { item.hidden = index >= visibleLimit; });
            items.filter((item) => !filtered.includes(item)).forEach((item) => { item.hidden = true; });
            if (moreWrap) moreWrap.hidden = filtered.length <= visibleLimit;
        };
        filters.forEach((button) => button.addEventListener('click', function () {
            activeFilter = button.dataset.historyFilter || 'all';
            visibleLimit = pageSize;
            filters.forEach((item) => item.classList.toggle('is-active', item === button));
            renderHistory();
        }));
        moreButton?.addEventListener('click', function () { visibleLimit += pageSize; renderHistory(); });
        renderHistory();
    }
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
        if (button) { button.disabled = true; button.textContent = 'Verificando...'; }
        if (status) status.textContent = 'Executando verificações. Aguarde alguns segundos...';

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            });
            const payload = await response.json();
            if (!response.ok || !payload.ok) throw new Error(payload.message || 'Não foi possível executar a verificação.');

            const summary = payload.data && payload.data.summary ? payload.data.summary : {};
            Object.keys(summary).forEach(function (key) {
                const target = document.querySelector('[data-operations-kpi="' + key + '"]');
                if (target) target.textContent = summary[key];
            });
            if (status) status.textContent = 'Verificação concluída. Atualizando o painel...';
            if (button) button.textContent = 'Atualizando...';
            const redirect = payload.redirect || window.location.href;
            const separator = redirect.includes('?') ? '&' : '?';
            window.location.assign(redirect + separator + 'refresh=' + Date.now());
        } catch (error) {
            if (status) status.textContent = error.message + ' Tentando pelo envio tradicional...';
            form.submit();
        } finally {
            window.setTimeout(function () {
                if (button) { button.disabled = false; button.textContent = originalText; }
            }, 1200);
        }
    });
});
</script>
