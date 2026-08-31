<?php

use App\Core\Flash;
use App\Core\Router;
use App\Core\View;
use App\Services\BrandingService;

$flashes = Flash::all();
$branding = is_array($branding ?? null) ? $branding : BrandingService::forCurrentRequest();
$brandName = (string) ($branding['app_name'] ?? 'RS Connect');
$brandPrimary = (string) ($branding['primary'] ?? '#146498');
$brandSecondary = (string) ($branding['secondary'] ?? '#631b7c');
$brandAccent = (string) ($branding['accent'] ?? '#01c5b6');
$brandLoginBg = (string) ($branding['login_bg'] ?? '#07111f');
$brandLoginText = (string) ($branding['login_text'] ?? '#ffffff');
$brandFaviconUrl = (string) ($branding['favicon_url'] ?? '');
$brandAssetHref = static fn (string $url): string => preg_match('~^https://~i', $url) === 1 ? $url : Router::url($url);
$brandCssVariables = '--rs-blue:' . $brandPrimary . ';--rs-purple:' . $brandSecondary . ';--rs-cyan:' . $brandAccent . ';--rs-teal:' . $brandAccent . ';--tenant-login-bg:' . $brandLoginBg . ';--tenant-login-text:' . $brandLoginText . ';';
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= View::e($title ?? 'Entrar') ?> — <?= View::e($brandName) ?></title>
    <?php if ($brandFaviconUrl !== ''): ?><link rel="icon" href="<?= View::e($brandAssetHref($brandFaviconUrl)) ?>"><?php endif; ?>
    <link rel="stylesheet" href="<?= View::e(Router::url('/assets/css/app.css?v=36.25.2')) ?>">
</head>
<body class="guest-page<?= !empty($branding['enabled']) ? ' has-tenant-branding' : '' ?>" style="<?= View::e($brandCssVariables) ?>">
    <main class="guest-shell">
        <?php if ($flashes): ?>
            <section class="flash-stack" aria-live="polite">
                <?php foreach ($flashes as $flash): ?>
                    <div class="flash flash-<?= View::e($flash['type']) ?>">
                        <span><?= View::e($flash['message']) ?></span>
                    </div>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>
        <?= $content ?>
    </main>
</body>
</html>
