<?php

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;

$roleLabels = [
    'super_admin' => 'Super Admin RS',
    'client_admin' => 'Administrador do cliente',
    'client_user' => 'Usuário do cliente',
];
?>
<div class="content-grid management-layout">
    <section class="card">
        <div class="section-heading">
            <div><span class="eyebrow">Controle de acesso</span><h2>Usuários cadastrados</h2></div>
            <span class="badge"><?= count($users) ?> usuário(s)</span>
        </div>

        <div class="table-wrap">
            <table>
                <thead><tr><th>Usuário</th><th>Empresa</th><th>Perfil</th><th>Status</th><th>Último acesso</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($users as $userItem): ?>
                    <tr>
                        <td><strong><?= View::e($userItem['name']) ?></strong><small><?= View::e($userItem['email']) ?></small></td>
                        <td><?= View::e($userItem['tenant_name'] ?? 'Equipe RS') ?></td>
                        <td><span class="badge"><?= View::e($roleLabels[$userItem['role']] ?? $userItem['role']) ?></span></td>
                        <td><span class="badge badge-<?= View::e($userItem['status']) ?>"><?= $userItem['status'] === 'active' ? 'Ativo' : 'Inativo' ?></span></td>
                        <td><?= $userItem['last_login_at'] ? View::e(date('d/m/Y H:i', strtotime($userItem['last_login_at']))) : 'Nunca' ?></td>
                        <td>
                            <?php if (Auth::can('users.manage')): ?>
                                <details class="table-details">
                                    <summary class="table-link">Editar</summary>
                                    <form class="edit-panel" method="post" action="<?= View::e(Router::url('/users/update')) ?>">
                                        <?= Csrf::input() ?>
                                        <input type="hidden" name="user_id" value="<?= (int) $userItem['id'] ?>">
                                        <label class="field"><span>Nome</span><input name="name" value="<?= View::e($userItem['name']) ?>" required></label>
                                        <label class="field"><span>E-mail</span><input type="email" name="email" value="<?= View::e($userItem['email']) ?>" required></label>
                                        <div class="form-grid two">
                                            <label class="field"><span>Perfil</span><select name="role">
                                                <?php if ($userItem['tenant_id'] === null): ?>
                                                    <option value="super_admin" selected>Super Admin RS</option>
                                                <?php else: ?>
                                                    <option value="client_admin" <?= $userItem['role'] === 'client_admin' ? 'selected' : '' ?>>Administrador</option>
                                                    <option value="client_user" <?= $userItem['role'] === 'client_user' ? 'selected' : '' ?>>Usuário</option>
                                                <?php endif; ?>
                                            </select></label>
                                            <label class="field"><span>Status</span><select name="status"><option value="active" <?= $userItem['status'] === 'active' ? 'selected' : '' ?>>Ativo</option><option value="inactive" <?= $userItem['status'] === 'inactive' ? 'selected' : '' ?>>Inativo</option></select></label>
                                        </div>
                                        <label class="field"><span>Nova senha</span><input type="password" name="password" minlength="8" placeholder="Deixe vazio para manter"></label>
                                        <button class="btn btn-primary btn-block" type="submit">Salvar usuário</button>
                                    </form>
                                </details>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$users): ?><tr><td colspan="6" class="empty-state">Nenhum usuário cadastrado.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <?php if (Auth::can('users.manage')): ?>
        <aside class="stack">
            <form class="card sticky-card" method="post" action="<?= View::e(Router::url('/users')) ?>">
                <?= Csrf::input() ?>
                <div class="section-heading"><div><span class="eyebrow">Novo acesso</span><h2>Adicionar usuário</h2></div></div>

                <?php if (Auth::isSuperAdmin()): ?>
                    <label class="field"><span>Empresa</span><select name="tenant_id"><option value="global">Equipe RS — acesso global</option><?php foreach ($tenants as $tenant): ?><option value="<?= (int) $tenant['id'] ?>"><?= View::e($tenant['name']) ?></option><?php endforeach; ?></select></label>
                    <label class="field"><span>Perfil</span><select name="role"><option value="super_admin">Super Admin RS</option><option value="client_admin">Administrador do cliente</option><option value="client_user">Usuário do cliente</option></select></label>
                    <p class="field-hint">Para perfis de cliente, selecione uma empresa. Super Admin deve usar “Equipe RS”.</p>
                <?php else: ?>
                    <label class="field"><span>Perfil</span><select name="role"><option value="client_user">Usuário</option><option value="client_admin">Administrador</option></select></label>
                <?php endif; ?>

                <label class="field"><span>Nome</span><input name="name" placeholder="Nome completo" required></label>
                <label class="field"><span>E-mail</span><input type="email" name="email" placeholder="usuario@empresa.com" required></label>
                <label class="field"><span>Senha inicial</span><input type="password" name="password" minlength="8" placeholder="Mínimo de 8 caracteres" required></label>
                <button class="btn btn-primary btn-block" type="submit">Cadastrar usuário</button>
            </form>
        </aside>
    <?php endif; ?>
</div>
