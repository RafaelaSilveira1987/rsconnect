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
    <link rel="stylesheet" href="<?= View::e(Router::url('/assets/css/app.css?v=33.0')) ?>">
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
