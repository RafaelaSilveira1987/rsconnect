<?php

use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;

$data = $healthData ?? [];
$tenant = $data['tenant'] ?? [];
$snapshot = $data['snapshot'] ?? [];
$groups = $data['groups'] ?? [];
$incidents = $data['incidents'] ?? [];
$events = $data['events'] ?? [];
$summary = $data['summary'] ?? [];
$tenantId = (int) ($tenant['id'] ?? 0);

$statusLabels = [
    'healthy' => 'Saudável',
    'attention' => 'Atenção',
    'critical' => 'Crítico',
    'idle' => 'Sem atividade recente',
    'blocked' => 'Bloqueado',
];
$checkLabels = ['ok' => 'Operacional', 'info' => 'Informação', 'warning' => 'Atenção', 'critical' => 'Crítico'];
$incidentLabels = ['open' => 'Aberto', 'acknowledged' => 'Visualizado', 'monitoring' => 'Em acompanhamento', 'resolved' => 'Resolvido'];
$eventLabels = [
    'opened' => 'Problema identificado',
    'reopened' => 'Problema reaberto',
    'acknowledged' => 'Problema visualizado',
    'monitoring' => 'Acompanhamento iniciado',
    'resolved' => 'Problema resolvido',
    'auto_resolved' => 'Normalização confirmada',
    'note' => 'Observação adicionada',
];
$formatDate = static function (?string $value): string {
    if (!$value) return 'Não disponível';
    $timestamp = strtotime($value);
    return $timestamp ? date('d/m/Y H:i', $timestamp) : $value;
};
$relative = static function (?string $value): string {
    if (!$value || !($time = strtotime($value))) return 'sem registro';
    $seconds = time() - $time;
    if ($seconds < 60) return 'agora';
    if ($seconds < 3600) return 'há ' . max(1, (int) floor($seconds / 60)) . ' min';
    if ($seconds < 86400) return 'há ' . max(1, (int) floor($seconds / 3600)) . ' h';
    return 'há ' . max(1, (int) floor($seconds / 86400)) . ' dia(s)';
};
$overall = (string) ($snapshot['overall_status'] ?? 'attention');
?>

<section class="tenant-health-hero is-<?= View::e($overall) ?>">
    <div>
        <span class="eyebrow">Saúde do cliente</span>
        <h2><?= View::e((string) ($tenant['name'] ?? 'Empresa')) ?></h2>
        <p>Veja se WhatsApp, assistentes, integrações, agenda, assinatura e acessos estão funcionando agora.</p>
        <?php if (!empty($snapshot)): ?>
            <small>Última verificação: <?= View::e($formatDate((string) ($snapshot['checked_at'] ?? ''))) ?> · <?= View::e((string) ($snapshot['checked_by_name'] ?? 'Rotina automática')) ?></small>
        <?php endif; ?>
    </div>
    <div class="tenant-health-hero-actions">
        <span class="tenant-health-status is-<?= View::e($overall) ?>"><?= View::e($statusLabels[$overall] ?? 'Atenção') ?></span>
        <strong><?= (int) ($snapshot['score'] ?? 0) ?>%</strong>
        <form method="post" action="<?= View::e(Router::url('/companies/health/run')) ?>">
            <?= Csrf::input() ?>
            <input type="hidden" name="tenant_id" value="<?= $tenantId ?>">
            <button class="btn btn-primary" type="submit">Verificar agora</button>
        </form>
        <a class="btn btn-outline" href="<?= View::e(Router::url('/companies/overview?id=' . $tenantId)) ?>">Voltar à empresa</a>
    </div>
</section>

<section class="tenant-health-summary-grid" aria-label="Resumo da saúde">
    <article><span class="health-summary-icon is-ok">✓</span><div><small>Operacionais</small><strong><?= (int) ($snapshot['ok_count'] ?? 0) ?></strong><em>componentes sem falhas</em></div></article>
    <article><span class="health-summary-icon is-warning">!</span><div><small>Pontos de atenção</small><strong><?= (int) ($snapshot['warning_count'] ?? 0) ?></strong><em>precisam ser revisados</em></div></article>
    <article><span class="health-summary-icon is-critical">×</span><div><small>Problemas críticos</small><strong><?= (int) ($snapshot['critical_count'] ?? 0) ?></strong><em>podem interromper o atendimento</em></div></article>
    <article><span class="health-summary-icon is-monitoring">◎</span><div><small>Em acompanhamento</small><strong><?= (int) ($summary['monitoring'] ?? 0) ?></strong><em>vistos pela equipe RS</em></div></article>
</section>

<?php if (empty($snapshot)): ?>
<section class="card tenant-health-empty">
    <h2>Execute a primeira verificação</h2>
    <p>O diagnóstico ainda não possui dados desta empresa.</p>
</section>
<?php else: ?>
<section class="tenant-health-section-heading">
    <div><span class="eyebrow">Diagnóstico atual</span><h2>Componentes da operação</h2><p>Itens informativos não são tratados como falha. Uma empresa sem mensagens recentes pode apenas estar sem atividade.</p></div>
</section>

<div class="tenant-health-category-grid">
    <?php foreach ($groups as $category => $checks): ?>
        <?php
        $categoryWorst = 'ok';
        foreach ($checks as $item) {
            if (($item['status'] ?? '') === 'critical') { $categoryWorst = 'critical'; break; }
            if (($item['status'] ?? '') === 'warning') $categoryWorst = 'warning';
            elseif (($item['status'] ?? '') === 'info' && $categoryWorst === 'ok') $categoryWorst = 'info';
        }
        ?>
        <section class="card tenant-health-category is-<?= View::e($categoryWorst) ?>">
            <header>
                <div><span class="tenant-health-dot is-<?= View::e($categoryWorst) ?>"></span><h3><?= View::e((string) $category) ?></h3></div>
                <span class="badge health-badge is-<?= View::e($categoryWorst) ?>"><?= View::e($checkLabels[$categoryWorst] ?? 'Informação') ?></span>
            </header>
            <div class="tenant-health-check-list">
                <?php foreach ($checks as $check): ?>
                    <article class="tenant-health-check is-<?= View::e((string) ($check['status'] ?? 'info')) ?>">
                        <div class="tenant-health-check-main">
                            <span class="tenant-health-dot is-<?= View::e((string) ($check['status'] ?? 'info')) ?>"></span>
                            <div>
                                <strong><?= View::e((string) ($check['component_label'] ?? 'Verificação')) ?></strong>
                                <p><?= View::e((string) ($check['summary'] ?? '')) ?></p>
                                <small>Verificado <?= View::e($relative((string) ($check['checked_at'] ?? ''))) ?></small>
                            </div>
                            <span class="health-check-label is-<?= View::e((string) ($check['status'] ?? 'info')) ?>"><?= View::e($checkLabels[(string) ($check['status'] ?? 'info')] ?? 'Informação') ?></span>
                        </div>
                        <div class="tenant-health-check-actions">
                            <?php if (!empty($check['action_url'])): ?><a class="btn btn-quiet" href="<?= View::e(Router::url((string) $check['action_url'])) ?>">Abrir configuração</a><?php endif; ?>
                            <?php if (!empty($check['details'])): ?>
                                <details>
                                    <summary>Ver detalhes</summary>
                                    <dl>
                                        <?php foreach ($check['details'] as $label => $value): ?>
                                            <div><dt><?= View::e((string) $label) ?></dt><dd><?= View::e((string) $value) ?></dd></div>
                                        <?php endforeach; ?>
                                    </dl>
                                </details>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<section class="card tenant-health-incidents" id="incidents">
    <div class="section-heading">
        <div><span class="eyebrow">Acompanhamento</span><h2>Incidentes e correções</h2><p>O mesmo problema não gera alertas duplicados: ele permanece aberto até ser resolvido.</p></div>
        <span class="badge"><?= (int) ($summary['open'] ?? 0) ?> aberto(s)</span>
    </div>
    <div class="tenant-health-incident-list">
        <?php foreach ($incidents as $incident): ?>
            <article class="tenant-health-incident is-<?= View::e((string) ($incident['severity'] ?? 'warning')) ?> <?= ($incident['status'] ?? '') === 'resolved' ? 'is-resolved' : '' ?>">
                <header>
                    <div><span class="tenant-health-dot is-<?= View::e((string) ($incident['severity'] ?? 'warning')) ?>"></span><div><strong><?= View::e((string) ($incident['title'] ?? 'Incidente')) ?></strong><small><?= View::e((string) ($incident['category'] ?? '')) ?></small></div></div>
                    <span class="health-incident-status is-<?= View::e((string) ($incident['status'] ?? 'open')) ?>"><?= View::e($incidentLabels[(string) ($incident['status'] ?? 'open')] ?? 'Aberto') ?></span>
                </header>
                <p><?= View::e((string) ($incident['summary'] ?? '')) ?></p>
                <div class="tenant-health-incident-meta">
                    <span>Primeira ocorrência: <?= View::e($formatDate((string) ($incident['first_seen_at'] ?? ''))) ?></span>
                    <span>Última ocorrência: <?= View::e($relative((string) ($incident['last_seen_at'] ?? ''))) ?></span>
                    <span>Ocorrências: <?= (int) ($incident['occurrence_count'] ?? 1) ?></span>
                    <?php if (!empty($incident['assigned_user_name'])): ?><span>Responsável: <?= View::e((string) $incident['assigned_user_name']) ?></span><?php endif; ?>
                </div>
                <?php if (!empty($incident['notes'])): ?><div class="tenant-health-note"><strong>Observação:</strong> <?= View::e((string) $incident['notes']) ?></div><?php endif; ?>
                <div class="tenant-health-incident-actions">
                    <?php if (!empty($incident['related_url'])): ?><a class="btn btn-outline" href="<?= View::e(Router::url((string) $incident['related_url'])) ?>">Abrir correção</a><?php endif; ?>
                    <form method="post" action="<?= View::e(Router::url('/companies/health/incident')) ?>">
                        <?= Csrf::input() ?>
                        <input type="hidden" name="tenant_id" value="<?= $tenantId ?>">
                        <input type="hidden" name="incident_id" value="<?= (int) $incident['id'] ?>">
                        <label class="field"><span>Observação interna</span><input name="note" placeholder="O que foi verificado ou corrigido?"></label>
                        <div class="tenant-health-inline-actions">
                            <?php if (($incident['status'] ?? '') !== 'resolved'): ?>
                                <button class="btn btn-quiet" name="incident_action" value="acknowledge" type="submit">Marcar visualizado</button>
                                <button class="btn btn-outline" name="incident_action" value="monitor" type="submit">Acompanhar</button>
                                <button class="btn btn-primary" name="incident_action" value="resolve" type="submit">Marcar resolvido</button>
                            <?php else: ?>
                                <button class="btn btn-outline" name="incident_action" value="reopen" type="submit">Reabrir incidente</button>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </article>
        <?php endforeach; ?>
        <?php if (!$incidents): ?><div class="empty-state">Nenhum incidente registrado. Execute uma verificação para validar a operação.</div><?php endif; ?>
    </div>
</section>

<section class="card tenant-health-history">
    <div class="section-heading"><div><span class="eyebrow">Histórico</span><h2>Linha do tempo de resolução</h2></div></div>
    <div class="tenant-health-timeline">
        <?php foreach ($events as $event): ?>
            <article>
                <span></span>
                <div><strong><?= View::e($eventLabels[(string) ($event['event_type'] ?? '')] ?? 'Atualização') ?> — <?= View::e((string) ($event['title'] ?? 'Incidente')) ?></strong><p><?= View::e((string) ($event['note'] ?? '')) ?></p><small><?= View::e((string) ($event['user_name'] ?? 'Sistema')) ?></small></div>
                <time><?= View::e($formatDate((string) ($event['created_at'] ?? ''))) ?></time>
            </article>
        <?php endforeach; ?>
        <?php if (!$events): ?><div class="empty-state">Ainda não há movimentações no histórico.</div><?php endif; ?>
    </div>
</section>
