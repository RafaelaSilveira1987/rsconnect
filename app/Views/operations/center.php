<?php

use App\Core\View;

$selectedTab = in_array(($selectedTab ?? 'monitoring'), ['monitoring', 'ai_reprocess', 'security', 'backups', 'beta', 'status'], true)
    ? (string) $selectedTab
    : 'monitoring';
$renderPartial = static function (string $file, array $variables): void {
    extract($variables, EXTR_SKIP);
    require $file;
};
$viewBase = dirname(__DIR__);
?>
<section class="admin-module-hero operations-center-hero">
    <div>
        <span class="eyebrow">Administração RS</span>
        <h2>Central de operação</h2>
        <p>Segurança, monitoramento, backups e informações da versão reunidos em um único módulo.</p>
    </div>
</section>

<div class="admin-tab-shell operations-center-shell" data-tabs-shell>
    <div class="admin-tab-bar operations-center-tabs" data-tabs>
        <button class="<?= $selectedTab === 'monitoring' ? 'is-active' : '' ?>" type="button" data-tab-target="monitoring">Monitoramento</button>
        <button class="<?= $selectedTab === 'ai_reprocess' ? 'is-active' : '' ?>" type="button" data-tab-target="ai_reprocess">Fila da IA</button>
        <button class="<?= $selectedTab === 'security' ? 'is-active' : '' ?>" type="button" data-tab-target="security">Segurança</button>
        <button class="<?= $selectedTab === 'backups' ? 'is-active' : '' ?>" type="button" data-tab-target="backups">Backups</button>
        <button class="<?= $selectedTab === 'status' ? 'is-active' : '' ?>" type="button" data-tab-target="status">Status do sistema</button>
        <button class="<?= $selectedTab === 'beta' ? 'is-active' : '' ?>" type="button" data-tab-target="beta">Beta e evolução</button>
    </div>

    <section class="operations-center-panel" data-tab-panel="monitoring" <?= $selectedTab !== 'monitoring' ? 'hidden' : '' ?>>
        <?php $renderPartial($viewBase . '/operations/index.php', ['data' => $operationsData ?? []]); ?>
    </section>
    <section class="operations-center-panel" data-tab-panel="ai_reprocess" <?= $selectedTab !== 'ai_reprocess' ? 'hidden' : '' ?>>
        <?php $renderPartial($viewBase . '/operations/ai_reprocess.php', ['aiReprocessData' => $aiReprocessData ?? []]); ?>
    </section>
    <section class="operations-center-panel" data-tab-panel="security" <?= $selectedTab !== 'security' ? 'hidden' : '' ?>>
        <?php $renderPartial($viewBase . '/security/index.php', ['securityData' => $securityData ?? []]); ?>
    </section>
    <section class="operations-center-panel" data-tab-panel="backups" <?= $selectedTab !== 'backups' ? 'hidden' : '' ?>>
        <?php $renderPartial($viewBase . '/operations/backup_automation.php', ['data' => $backupData ?? []]); ?>
    </section>
    <section class="operations-center-panel" data-tab-panel="status" <?= $selectedTab !== 'status' ? 'hidden' : '' ?>>
        <?php $renderPartial($viewBase . '/docs/status.php', ['data' => $versionData ?? []]); ?>
    </section>
    <section class="operations-center-panel" data-tab-panel="beta" <?= $selectedTab !== 'beta' ? 'hidden' : '' ?>>
        <?php $renderPartial($viewBase . '/docs/beta.php', ['data' => $betaData ?? []]); ?>
    </section>
</div>
