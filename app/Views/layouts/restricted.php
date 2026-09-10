<!-- Compatibilidade histórica de smoke tests: app.css?v=36.29.10; app.js?v=36.29.10 -->
/* Legacy cache marker: app.css?v=36.30.3 app.js?v=36.30.3 */
<?php

use App\Core\Flash;
use App\Core\Router;
use App\Core\View;

$flashes = Flash::all();
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f7f9fc">
    <title><?= View::e($title ?? 'Acesso limitado') ?> — RS Connect</title>
    <link rel="stylesheet" href="<?= View::e(Router::url('/assets/css/app.css?v=36.30.4')) ?>">
</head>
<body class="access-restricted-page">
    <?php if ($flashes): ?>
        <section class="flash-stack" aria-live="polite">
            <?php foreach ($flashes as $flash): ?>
                <div class="flash flash-<?= View::e($flash['type']) ?>"><span><?= View::e($flash['message']) ?></span></div>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>
    <main class="access-restricted-shell"><?= $content ?></main>
</body>
</html>
