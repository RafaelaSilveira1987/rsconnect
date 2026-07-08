<?php

use App\Core\Router;
use App\Core\View;
?>
<div class="card empty-page">
    <span class="error-code">404</span>
    <h2>Página não encontrada</h2>
    <p>O endereço informado não existe nesta versão do RS Connect.</p>
    <a class="btn btn-primary" href="<?= View::e(Router::url('/')) ?>">Voltar ao dashboard</a>
</div>
