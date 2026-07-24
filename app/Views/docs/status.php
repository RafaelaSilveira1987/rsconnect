<?php

use App\Core\Router;
use App\Core\View;

$dashboard = is_array($data['data'] ?? null) ? $data['data'] : ($data ?? []);

$score = (int) ($dashboard['score'] ?? 0);
$statusLabel = (string) ($dashboard['status_label'] ?? 'Beta 1.0 em preparação');
$statusClass = str_contains($statusLabel, 'operacional') ? 'badge-success' : (str_contains($statusLabel, 'bloqueios') ? 'badge-danger' : 'badge-warning');
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
?>

<section class="hero-card docs-hero beta-hero version-hero">
    <div>
        <span class="eyebrow">Versão estabilizada</span>
        <h2><?= View::e($dashboard['version'] ?? 'Beta Comercial 1.0') ?></h2>
        <p>Este painel confirma se a instalação está pronta para uso controlado com clientes reais, sem campanhas e sem disparos em massa.</p>
    </div>
    <div class="beta-score-card">
        <span class="eyebrow"><?= View::e($dashboard['package'] ?? 'RS Connect') ?></span>
        <strong><?= $score ?>%</strong>
        <span class="badge <?= $statusClass ?>"><?= View::e($statusLabel) ?></span>
    </div>
</section>

<div class="report-kpi-grid implementation-kpis" style="margin-top:16px">
    <article class="card report-kpi"><span>Checks OK</span><strong><?= (int) ($dashboard['ok'] ?? 0) ?></strong><small>Critérios aprovados</small></article>
    <article class="card report-kpi"><span>Atenções</span><strong><?= (int) ($dashboard['warning'] ?? 0) ?></strong><small>Não bloqueiam venda controlada</small></article>
    <article class="card report-kpi"><span>Bloqueios</span><strong><?= (int) ($dashboard['blocked'] ?? 0) ?></strong><small>Resolver antes de entregar</small></article>
    <article class="card report-kpi"><span>Migration base</span><strong>028</strong><small><?= View::e($dashboard['required_migration'] ?? '') ?></small></article>
</div>

<div class="operations-grid" style="margin-top:16px">
    <section class="card">
        <div class="section-heading"><div><span class="eyebrow">Diagnóstico</span><h2>Checklist técnico final</h2></div></div>
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
        <div class="section-heading"><div><span class="eyebrow">Instalação</span><h2>Versão e ambiente</h2></div></div>
        <div class="version-info-list">
            <div><span>Versão</span><strong><?= View::e($dashboard['deploy']['version'] ?? '') ?></strong></div>
            <div><span>Pacote</span><strong><?= View::e($dashboard['deploy']['package'] ?? '') ?></strong></div>
            <div><span>PHP</span><strong><?= View::e($dashboard['deploy']['php_version'] ?? '') ?></strong></div>
            <div><span>Último arquivo atualizado</span><strong><?= View::e($dashboard['deploy']['last_file_update'] ?? '') ?></strong></div>
        </div>
        <div class="docs-action-list" style="margin-top:12px">
            <a href="<?= View::e(Router::url('/beta-comercial')) ?>">Abrir Beta comercial</a>
            <a href="<?= View::e(Router::url('/monitoramento')) ?>">Abrir Monitoramento</a>
            <a href="<?= View::e(Router::url('/implantacao')) ?>">Abrir Implantação</a>
            <a href="<?= View::e(Router::url('/backup-automatico')) ?>">Abrir Backup automático</a>
        </div>
    </aside>
</div>

<div class="operations-grid" style="margin-top:16px">
    <section class="card">
        <div class="section-heading"><div><span class="eyebrow">Variáveis</span><h2>Ambiente carregado</h2></div></div>
        <div class="version-env-grid">
            <?php foreach (($dashboard['environment'] ?? []) as $env): ?>
                <div class="version-env-item">
                    <span><?= View::e($env['label'] ?? '') ?></span>
                    <strong><?= View::e($env['value'] ?? '') ?></strong>
                </div>
            <?php endforeach; ?>
        </div>
        <p class="muted-text" style="margin-top:10px">Valores sensíveis são mascarados. Use esta tela para confirmar se o EasyPanel entregou as variáveis ao PHP após o redeploy.</p>
    </section>

    <section class="card">
        <div class="section-heading"><div><span class="eyebrow">Módulos</span><h2>Resumo operacional</h2></div></div>
        <div class="version-module-grid">
            <?php foreach (($dashboard['modules'] ?? []) as $module): ?>
                <a class="version-module-card" href="<?= View::e(Router::url($module['url'] ?? '/')) ?>">
                    <span><?= View::e($module['name'] ?? '') ?></span>
                    <strong><?= (int) ($module['count'] ?? 0) ?></strong>
                </a>
            <?php endforeach; ?>
        </div>
    </section>
</div>

<section class="card" style="margin-top:16px">
    <div class="section-heading"><div><span class="eyebrow">Próximas ações</span><h2>Fechamento da beta</h2></div></div>
    <div class="security-list" data-collapsible-list="3">
        <?php foreach (($dashboard['next_actions'] ?? []) as $action): ?>
            <div class="security-row">
                <div>
                    <strong><?= View::e($action['label'] ?? '') ?></strong>
                    <small><?= View::e($action['action'] ?? '') ?></small>
                </div>
                <span class="badge <?= $statusToBadge((string) ($action['status'] ?? 'warning')) ?>"><?= View::e($statusText((string) ($action['status'] ?? 'warning'))) ?></span>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<section class="card" style="margin-top:16px">
    <div class="section-heading"><div><span class="eyebrow">Escopo final validado</span><h2>Produto central</h2></div></div>
    <div class="docs-scope-grid">
        <div class="operations-alert is-success"><strong>Dentro da Beta 1.0</strong><p>Atendimento WhatsApp, IA, CRM, agenda, pré-agendamento, n8n por empresa, cobrança, LGPD, monitoramento, backup automático e onboarding.</p></div>
        <div class="operations-alert is-warning"><strong>Fora da Beta 1.0</strong><p>Campanhas e disparos em massa permanecem fora do escopo por decisão comercial.</p></div>
    </div>
</section>
