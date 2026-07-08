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
    <title><?= View::e($title ?? 'Entrar') ?> — RS Connect</title>
    <link rel="stylesheet" href="<?= View::e(Router::url('/assets/css/app.css')) ?>">
</head>
<body class="guest-page">
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
