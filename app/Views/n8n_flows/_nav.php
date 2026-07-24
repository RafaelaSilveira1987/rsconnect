<?php
use App\Core\Router;
use App\Core\View;
$currentN8nPath = rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/');
?>
<nav class="n8n-module-nav" aria-label="Navegação n8n">
    <a class="<?= str_ends_with($currentN8nPath, '/n8n') ? 'is-active' : '' ?>" href="<?= View::e(Router::url('/n8n')) ?>">Visão geral</a>
    <a class="<?= str_ends_with($currentN8nPath, '/n8n-flows') ? 'is-active' : '' ?>" href="<?= View::e(Router::url('/n8n-flows')) ?>">Fluxos</a>
    <a class="<?= str_ends_with($currentN8nPath, '/n8n-templates') ? 'is-active' : '' ?>" href="<?= View::e(Router::url('/n8n-templates')) ?>">Templates</a>
</nav>
