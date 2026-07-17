<?php

use App\Core\Router;
use App\Core\View;

$dashboard = is_array($data['data'] ?? null) ? $data['data'] : ($data ?? []);

$score = (int) ($dashboard['score'] ?? 0);
$statusClass = $score >= 90 ? 'badge-success' : ($score >= 70 ? 'badge-warning' : 'badge-danger');
$statusToBadge = static fn (string $status): string => match ($status) {
    'ok' => 'badge-success',
    'warning' => 'badge-warning',
    'blocked' => 'badge-danger',
    default => 'badge-info',
};
$statusText = static fn (string $status): string => match ($status) {
    'ok' => 'Operando',
    'warning' => 'Atenção',
    'blocked' => 'Bloqueado',
    default => 'Informação',
};
$metrics = $dashboard['metrics'] ?? [];
?>

<section class="hero-card docs-hero beta-hero">
    <div>
        <span class="eyebrow">Beta Comercial 1.0</span>
        <h2>Prontidão para operar clientes reais em beta controlada.</h2>
        <p>Este painel consolida os módulos centrais do RS Connect e substitui o status genérico de preparação por critérios objetivos de Beta 1.0.</p>
    </div>
    <div class="beta-score-card">
        <span class="eyebrow">Status Beta 1.0</span>
        <strong><?= $score ?>%</strong>
        <span class="badge <?= $statusClass ?>"><?= View::e($dashboard['status_label'] ?? 'Em preparação') ?></span>
    </div>
</section>

<div class="report-kpi-grid implementation-kpis" style="margin-top:16px">
    <article class="card report-kpi"><span>Empresas ativas</span><strong><?= (int) ($metrics['active_tenants'] ?? 0) ?></strong><small>Total: <?= (int) ($metrics['tenants'] ?? 0) ?></small></article>
    <article class="card report-kpi"><span>Implantação média</span><strong><?= (int) ($metrics['implementation_avg'] ?? 0) ?>%</strong><small>Clientes em teste/operação: <?= (int) ($metrics['implementation_testing'] ?? 0) ?></small></article>
    <article class="card report-kpi"><span>Mensagens 24h</span><strong><?= (int) ($metrics['conversations_24h'] ?? 0) ?></strong><small>Valida webhooks e atendimento</small></article>
    <article class="card report-kpi"><span>Backups automáticos</span><strong><?= (int) ($metrics['automatic_backups'] ?? 0) ?></strong><small>Último: <?= View::e($metrics['last_backup'] ?? 'sem registro') ?></small></article>
</div>

<div class="operations-grid" style="margin-top:16px">
    <section class="card">
        <div class="section-heading"><div><span class="eyebrow">Checklist beta</span><h2>Critérios de prontidão</h2></div></div>
        <div class="beta-check-list">
            <?php foreach (($dashboard['checks'] ?? []) as $check): ?>
                <div class="beta-check-row is-<?= View::e($check['status'] ?? 'warning') ?>">
                    <div>
                        <strong><?= View::e($check['label'] ?? '') ?></strong>
                        <small><?= View::e($check['message'] ?? '') ?></small>
                        <p><?= View::e($check['action'] ?? '') ?></p>
                    </div>
                    <span class="badge <?= $statusToBadge((string) ($check['status'] ?? 'warning')) ?>"><?= View::e($statusText((string) ($check['status'] ?? 'warning'))) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
    <aside class="card">
        <div class="section-heading"><div><span class="eyebrow">Ações rápidas</span><h2>Operar beta</h2></div></div>
        <div class="docs-action-list">
            <?php foreach (($dashboard['quick_actions'] ?? []) as $action): ?>
                <?php if (($action['scope'] ?? 'all') === 'client') { continue; } ?>
                <a href="<?= View::e(Router::url($action['url'])) ?>"><?= View::e($action['label']) ?></a>
            <?php endforeach; ?>
            <a href="<?= View::e(Router::url('/status-sistema')) ?>">Status Beta 1.0</a>
        </div>
        <div class="operations-alert is-info" style="margin-top:12px">
            <strong>Critério comercial sugerido</strong>
            <p>Use a Beta 1.0 com poucos clientes controlados, onboarding acompanhado, backup validado e monitoramento diário.</p>
        </div>
    </aside>
</div>

<div class="operations-grid" style="margin-top:16px">
    <section class="card">
        <div class="section-heading"><div><span class="eyebrow">Rotina</span><h2>Governança operacional</h2></div></div>
        <div class="docs-timeline">
            <?php foreach (($dashboard['operational_routine'] ?? []) as $period => $items): ?>
                <div>
                    <strong><?= View::e($period) ?></strong>
                    <span><?= View::e(implode(' · ', $items)) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
    <section class="card">
        <div class="section-heading"><div><span class="eyebrow">Release notes</span><h2>Últimas entregas</h2></div></div>
        <div class="security-list">
            <?php foreach (($dashboard['release_notes'] ?? []) as $release): ?>
                <div class="security-row">
                    <div><strong><?= View::e($release['version'] . ' — ' . $release['title']) ?></strong><small><?= View::e($release['summary']) ?></small></div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
</div>

<section class="card" style="margin-top:16px">
    <div class="section-heading"><div><span class="eyebrow">Escopo mantido</span><h2>O que fica dentro e fora do beta</h2></div></div>
    <div class="docs-scope-grid">
        <div class="operations-alert is-success"><strong>Dentro</strong><p>Atendimento WhatsApp, IA, CRM, agenda, pré-agendamento, n8n por empresa, cobrança, LGPD, monitoramento, backup e onboarding.</p></div>
        <div class="operations-alert is-warning"><strong>Fora por decisão comercial</strong><p>Campanhas e disparos em massa não fazem parte do escopo atual do RS Connect.</p></div>
    </div>
</section>
