<?php

use App\Core\Auth;
use App\Core\Router;
use App\Core\View;
use App\Services\TenantModuleService;

if (Auth::isSuperAdmin()) {
    return;
}

$accountSection = (string) ($accountSection ?? 'company');
$tabs = [];
$tenantId = (int) (Auth::tenantId() ?? 0);
$moduleService = new TenantModuleService();
$moduleEnabled = static fn (string $key): bool => $tenantId > 0 ? $moduleService->enabled($tenantId, $key) : false;

if (Auth::can('company.view')) {
    $tabs[] = ['key' => 'company', 'label' => 'Dados da empresa', 'url' => '/company-settings'];
}
if ((Auth::can('users.view') && $moduleEnabled('users')) || (Auth::can('permissions.view') && $moduleEnabled('permissions'))) {
    $tabs[] = [
        'key' => 'team',
        'label' => 'Equipe e acessos',
        'url' => Auth::can('users.view') && $moduleEnabled('users') ? '/users' : '/permissions',
    ];
}
if (Auth::can('billing.view') && $moduleEnabled('subscription')) {
    $tabs[] = ['key' => 'subscription', 'label' => 'Assinatura', 'url' => '/subscription'];
}
if (Auth::can('privacy.view') && $moduleEnabled('privacy')) {
    $tabs[] = ['key' => 'privacy', 'label' => 'Privacidade', 'url' => '/privacy'];
}
?>
<nav class="company-account-tabs" aria-label="Configurações da empresa">
    <?php foreach ($tabs as $tab): ?>
        <a class="company-account-tab <?= $accountSection === $tab['key'] ? 'is-active' : '' ?>"
           href="<?= View::e(Router::url($tab['url'])) ?>">
            <?= View::e($tab['label']) ?>
        </a>
    <?php endforeach; ?>
</nav>
