<?php

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;

$roleLabels = [
    'client_admin' => 'Administrador',
    'client_user' => 'Membro da equipe',
];

$totalUsers = count($users);
$activeUsers = count(array_filter($users, static fn (array $user): bool => ($user['status'] ?? '') === 'active'));
$adminUsers = count(array_filter($users, static fn (array $user): bool => ($user['role'] ?? '') === 'client_admin'));
$currentUserId = Auth::id();
$canManageUsers = Auth::can('users.manage');
?>

<?php $accountSection = 'team'; require __DIR__ . '/../companies/_account_tabs.php'; ?>

<section class="company-account-intro client-team-intro">
    <div>
        <span class="eyebrow">Equipe e acessos</span>
        <h2>Gerencie quem entra na sua empresa</h2>
        <p>Cadastre pessoas, ajuste perfis e mantenha os acessos organizados em um só lugar.</p>
    </div>
    <div class="client-team-intro-actions">
        <?php if (Auth::can('permissions.view')): ?>
            <a class="btn btn-outline" href="<?= View::e(Router::url('/permissions')) ?>">Ver regras de acesso</a>
        <?php endif; ?>
        <?php if ($canManageUsers): ?>
            <button class="btn btn-primary" type="button" data-toggle-panel="client-user-drawer" data-client-user-open="new">+ Novo usuário</button>
        <?php endif; ?>
    </div>
</section>

<section class="client-team-summary" aria-label="Resumo da equipe">
    <article>
        <span>Equipe</span>
        <strong><?= $totalUsers ?></strong>
        <small>usuário<?= $totalUsers === 1 ? '' : 's' ?> cadastrado<?= $totalUsers === 1 ? '' : 's' ?></small>
    </article>
    <article class="is-success">
        <span>Acessos ativos</span>
        <strong><?= $activeUsers ?></strong>
        <small>podem entrar na plataforma</small>
    </article>
    <article class="is-admin">
        <span>Administradores</span>
        <strong><?= $adminUsers ?></strong>
        <small>com gestão da conta</small>
    </article>
</section>

<section class="card client-team-card">
    <div class="section-heading client-team-heading">
        <div>
            <span class="eyebrow">Equipe</span>
            <h2>Usuários cadastrados</h2>
            <p>Consulte perfil, situação e último acesso sem abrir formulários dentro da lista.</p>
        </div>
        <span class="badge"><?= $totalUsers ?> usuário(s)</span>
    </div>

    <div class="client-team-list">
        <?php foreach ($users as $userItem): ?>
            <?php
            $name = trim((string) ($userItem['name'] ?? ''));
            $initials = mb_strtoupper(mb_substr($name !== '' ? $name : (string) ($userItem['email'] ?? 'U'), 0, 2));
            $isCurrentUser = $currentUserId !== null && (int) $userItem['id'] === $currentUserId;
            ?>
            <article class="client-team-member">
                <div class="client-team-member-main">
                    <span class="client-team-avatar" aria-hidden="true"><?= View::e($initials) ?></span>
                    <div class="client-team-identity">
                        <div class="client-team-name-row">
                            <strong><?= View::e($userItem['name']) ?></strong>
                            <?php if ($isCurrentUser): ?><span class="client-team-you">Você</span><?php endif; ?>
                        </div>
                        <small><?= View::e($userItem['email']) ?></small>
                    </div>
                </div>

                <div class="client-team-meta">
                    <div>
                        <span>Perfil</span>
                        <strong><?= View::e($roleLabels[$userItem['role']] ?? $userItem['role']) ?></strong>
                    </div>
                    <div>
                        <span>Situação</span>
                        <strong class="client-team-status is-<?= View::e($userItem['status']) ?>">
                            <?= $userItem['status'] === 'active' ? 'Ativo' : 'Inativo' ?>
                        </strong>
                    </div>
                    <div>
                        <span>Último acesso</span>
                        <strong><?= $userItem['last_login_at'] ? View::e(date('d/m/Y H:i', strtotime($userItem['last_login_at']))) : 'Nunca' ?></strong>
                    </div>
                </div>

                <?php if ($canManageUsers): ?>
                    <div class="client-team-actions">
                        <button
                            class="btn btn-small btn-outline"
                            type="button"
                            data-toggle-panel="client-user-drawer"
                            data-client-user-open="edit"
                            data-id="<?= (int) $userItem['id'] ?>"
                            data-name="<?= View::e($userItem['name']) ?>"
                            data-email="<?= View::e($userItem['email']) ?>"
                            data-role="<?= View::e($userItem['role']) ?>"
                            data-status="<?= View::e($userItem['status']) ?>"
                            data-is-self="<?= $isCurrentUser ? '1' : '0' ?>"
                        >Editar</button>
                    </div>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>

        <?php if (!$users): ?>
            <div class="empty-state client-team-empty">
                <strong>Nenhum usuário cadastrado.</strong>
                <span>Use “Novo usuário” para adicionar a primeira pessoa da equipe.</span>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php if ($canManageUsers): ?>
<aside id="client-user-drawer" class="conversation-details conversation-drawer client-user-drawer" aria-label="Configurar usuário" aria-modal="true" role="dialog">
    <div class="conversation-drawer-header">
        <div>
            <span class="eyebrow" data-client-user-eyebrow>Novo usuário</span>
            <h2 data-client-user-title>Adicionar acesso</h2>
            <p data-client-user-description>Preencha os dados essenciais para liberar o acesso à sua equipe.</p>
        </div>
        <button class="icon-button drawer-close" type="button" data-close-panel="client-user-drawer" aria-label="Fechar">×</button>
    </div>

    <form
        class="client-user-drawer-form"
        method="post"
        action="<?= View::e(Router::url('/users')) ?>"
        data-client-user-form
        data-store-action="<?= View::e(Router::url('/users')) ?>"
        data-update-action="<?= View::e(Router::url('/users/update')) ?>"
    >
        <?= Csrf::input() ?>
        <input type="hidden" name="user_id" value="0" data-client-user-field="id">

        <div class="conversation-drawer-body client-user-drawer-body">
            <section class="drawer-section client-user-drawer-section">
                <div class="drawer-section-title">
                    <div>
                        <span class="eyebrow">Acesso</span>
                        <h3>Perfil e situação</h3>
                    </div>
                </div>
                <div class="drawer-form-grid client-user-drawer-grid">
                    <label class="field drawer-span">
                        <span>Perfil</span>
                        <select name="role" data-client-user-field="role" required>
                            <option value="client_user">Membro da equipe</option>
                            <option value="client_admin">Administrador</option>
                        </select>
                        <small class="field-hint">Administradores podem gerenciar configurações e acessos conforme as permissões da empresa.</small>
                    </label>
                    <label class="field drawer-span" data-client-user-status-field hidden>
                        <span>Situação</span>
                        <select name="status" data-client-user-field="status">
                            <option value="active">Ativo</option>
                            <option value="inactive">Inativo</option>
                        </select>
                    </label>
                </div>
                <div class="client-user-self-note" data-client-user-self-note hidden>
                    Este é o seu próprio acesso. O sistema protege alterações de perfil ou inativação durante a sessão atual.
                </div>
            </section>

            <section class="drawer-section client-user-drawer-section">
                <div class="drawer-section-title">
                    <div>
                        <span class="eyebrow">Identificação</span>
                        <h3>Dados do usuário</h3>
                    </div>
                </div>
                <div class="drawer-form-grid client-user-drawer-grid">
                    <label class="field drawer-span">
                        <span>Nome</span>
                        <input name="name" data-client-user-field="name" placeholder="Nome completo" autocomplete="name" required>
                    </label>
                    <label class="field drawer-span">
                        <span>E-mail</span>
                        <input type="email" name="email" data-client-user-field="email" placeholder="usuario@empresa.com" autocomplete="email" required>
                    </label>
                </div>
            </section>

            <section class="drawer-section client-user-drawer-section client-user-security-section">
                <div class="drawer-section-title">
                    <div>
                        <span class="eyebrow">Segurança</span>
                        <h3 data-client-user-password-title>Senha inicial</h3>
                    </div>
                </div>
                <label class="field">
                    <span data-client-user-password-label>Senha inicial</span>
                    <input type="password" name="password" minlength="8" autocomplete="new-password" data-client-user-field="password" placeholder="Mínimo de 8 caracteres" required>
                    <small class="field-hint" data-client-user-password-hint>Obrigatória no primeiro cadastro.</small>
                </label>
                <div class="client-user-security-tip">Use pelo menos 8 caracteres e evite senhas já utilizadas em outros serviços.</div>
            </section>
        </div>

        <div class="client-user-drawer-footer">
            <button class="btn btn-quiet" type="button" data-close-panel="client-user-drawer">Cancelar</button>
            <button class="btn btn-primary" type="submit" data-client-user-submit>Cadastrar usuário</button>
        </div>
    </form>
</aside>
<?php endif; ?>
