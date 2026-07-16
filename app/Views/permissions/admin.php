<?php

use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;

$groups = [];
foreach ($permissions as $permission) {
    $groups[$permission['category']][] = $permission;
}
?>
<div class="hero-card compact-hero hero-admin">
    <div>
        <span class="eyebrow light">Autorização por perfil</span>
        <h2>Matriz de permissões do RS Connect.</h2>
        <p>As rotas consultam essas permissões no banco. Super Admin possui acesso global; os perfis do cliente seguem a matriz abaixo.</p>
    </div>
    <span class="hero-badge"><?= $canEdit ? 'Modo de edição' : 'Somente leitura' ?></span>
</div>

<form class="card permission-card" method="post" action="<?= View::e(Router::url('/permissions')) ?>">
    <?php if ($canEdit): ?><?= Csrf::input() ?><?php endif; ?>
    <div class="permission-header">
        <div><span class="eyebrow">Perfis padrão</span><h2>Permissões disponíveis</h2></div>
        <div class="permission-role-head"><strong>Administrador</strong><strong>Usuário</strong></div>
    </div>

    <?php foreach ($groups as $category => $items): ?>
        <section class="permission-group">
            <h3><?= View::e($category) ?></h3>
            <?php foreach ($items as $permission): ?>
                <div class="permission-row">
                    <div><strong><?= View::e($permission['name']) ?></strong><small><?= View::e($permission['description']) ?><code><?= View::e($permission['permission_key']) ?></code></small></div>
                    <label class="permission-check"><input type="checkbox" name="permissions[client_admin][]" value="<?= View::e($permission['permission_key']) ?>" <?= !empty($matrix['client_admin'][$permission['permission_key']]) ? 'checked' : '' ?> <?= !$canEdit ? 'disabled' : '' ?>><span>Admin</span></label>
                    <label class="permission-check"><input type="checkbox" name="permissions[client_user][]" value="<?= View::e($permission['permission_key']) ?>" <?= !empty($matrix['client_user'][$permission['permission_key']]) ? 'checked' : '' ?> <?= !$canEdit ? 'disabled' : '' ?>><span>Usuário</span></label>
                </div>
            <?php endforeach; ?>
        </section>
    <?php endforeach; ?>

    <?php if ($canEdit): ?>
        <div class="form-actions"><button class="btn btn-primary" type="submit">Salvar matriz de permissões</button></div>
    <?php endif; ?>
</form>
