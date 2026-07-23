<?php

use App\Core\Auth;
use App\Core\Router;
use App\Core\View;

if (Auth::isSuperAdmin()) {
    require __DIR__ . '/admin.php';
    return;
}

$groups = [];
foreach ($permissions as $permission) {
    $groups[(string) $permission['category']][] = $permission;
}

$categoryLabels = [
    'Empresas' => 'Empresa e configurações',
    'Usuários' => 'Equipe e usuários',
    'Instâncias' => 'Conexão WhatsApp',
    'Conversas' => 'Conversas e atendimento',
    'CRM' => 'Contatos, CRM e atividades',
    'IA' => 'Assistentes de IA',
    'Automação' => 'Automações e integrações',
    'Agenda' => 'Agenda e compromissos',
    'Relatórios' => 'Relatórios',
    'Cobrança' => 'Assinatura e financeiro',
    'Notificações' => 'Notificações',
    'Segurança' => 'Segurança e privacidade',
    'LGPD' => 'Privacidade e dados',
    'Onboarding' => 'Primeiros passos',
    'Operações' => 'Operação do sistema',
];

$roleLabels = [
    'client_admin' => [
        'title' => 'Administrador da empresa',
        'description' => 'Pode configurar a conta, gerenciar a equipe e cuidar dos módulos liberados para a empresa.',
        'short' => 'Administrador',
    ],
    'client_user' => [
        'title' => 'Membro da equipe',
        'description' => 'Usa os recursos do dia a dia sem acesso às principais configurações administrativas.',
        'short' => 'Equipe',
    ],
];

$enabledCounts = ['client_admin' => 0, 'client_user' => 0];
foreach ($permissions as $permission) {
    foreach (array_keys($enabledCounts) as $role) {
        if (!empty($matrix[$role][$permission['permission_key']])) {
            $enabledCounts[$role]++;
        }
    }
}
$totalPermissions = count($permissions);
?>
<?php $accountSection = 'team'; require __DIR__ . '/../companies/_account_tabs.php'; ?>

<section class="client-permissions-hero card">
    <div>
        <span class="eyebrow">Acessos da equipe</span>
        <h2>Entenda o que cada perfil pode fazer.</h2>
        <p>Os perfis ajudam a proteger a conta e garantem que cada pessoa veja somente o que precisa para trabalhar.</p>
    </div>
    <a class="btn btn-primary" href="<?= View::e(Router::url('/users')) ?>">Gerenciar usuários</a>
</section>

<div class="client-role-summary-grid">
    <?php foreach ($roleLabels as $role => $roleInfo): ?>
        <article class="client-role-summary-card <?= $role === 'client_admin' ? 'is-admin' : 'is-team' ?>">
            <div class="client-role-summary-icon" aria-hidden="true">
                <?php if ($role === 'client_admin'): ?>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-4"/></svg>
                <?php else: ?>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M16 21v-2a4 4 0 0 0-8 0v2"/><circle cx="12" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.9"/></svg>
                <?php endif; ?>
            </div>
            <div class="client-role-summary-copy">
                <span><?= View::e($roleInfo['short']) ?></span>
                <h2><?= View::e($roleInfo['title']) ?></h2>
                <p><?= View::e($roleInfo['description']) ?></p>
            </div>
            <div class="client-role-summary-count"><strong><?= (int) $enabledCounts[$role] ?></strong><small>de <?= $totalPermissions ?> acessos disponíveis</small></div>
        </article>
    <?php endforeach; ?>
</div>

<div class="client-permissions-layout">
    <section class="card client-permissions-card">
        <div class="section-heading client-section-heading">
            <div><span class="eyebrow">Comparativo de acessos</span><h2>O que cada perfil consegue usar</h2><p>Consulte os módulos e ações liberados para administradores e membros da equipe.</p></div>
            <span class="badge">Somente consulta</span>
        </div>

        <div class="client-permission-legend">
            <span><i class="permission-status is-enabled">✓</i> Acesso liberado</span>
            <span><i class="permission-status is-disabled">—</i> Acesso não liberado</span>
        </div>

        <div class="client-permission-groups">
            <?php foreach ($groups as $category => $items): ?>
                <?php
                    $adminCategoryCount = 0;
                    $userCategoryCount = 0;
                    foreach ($items as $item) {
                        $adminCategoryCount += !empty($matrix['client_admin'][$item['permission_key']]) ? 1 : 0;
                        $userCategoryCount += !empty($matrix['client_user'][$item['permission_key']]) ? 1 : 0;
                    }
                ?>
                <details class="client-permission-group" open>
                    <summary>
                        <span><strong><?= View::e($categoryLabels[$category] ?? $category) ?></strong><small><?= count($items) ?> permissões neste grupo</small></span>
                        <span class="client-permission-group-counts"><b><?= $adminCategoryCount ?> admin</b><b><?= $userCategoryCount ?> equipe</b></span>
                    </summary>
                    <div class="client-permission-table">
                        <div class="client-permission-table-head"><span>Permissão</span><strong>Administrador</strong><strong>Equipe</strong></div>
                        <?php foreach ($items as $permission): ?>
                            <?php
                                $adminEnabled = !empty($matrix['client_admin'][$permission['permission_key']]);
                                $userEnabled = !empty($matrix['client_user'][$permission['permission_key']]);
                            ?>
                            <div class="client-permission-row">
                                <div><strong><?= View::e($permission['name']) ?></strong><small><?= View::e($permission['description']) ?></small></div>
                                <span class="permission-status <?= $adminEnabled ? 'is-enabled' : 'is-disabled' ?>" aria-label="<?= $adminEnabled ? 'Liberado' : 'Não liberado' ?>"><?= $adminEnabled ? '✓' : '—' ?></span>
                                <span class="permission-status <?= $userEnabled ? 'is-enabled' : 'is-disabled' ?>" aria-label="<?= $userEnabled ? 'Liberado' : 'Não liberado' ?>"><?= $userEnabled ? '✓' : '—' ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </details>
            <?php endforeach; ?>
        </div>
    </section>

    <aside class="client-permissions-side">
        <section class="card client-permissions-tip">
            <span class="client-permissions-tip-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg></span>
            <h2>Qual perfil escolher?</h2>
            <div class="client-role-guidance">
                <article><strong>Use Administrador</strong><p>Para sócios, gestores ou pessoas responsáveis pelas configurações da empresa.</p></article>
                <article><strong>Use Membro da equipe</strong><p>Para atendentes e colaboradores que trabalham na operação diária.</p></article>
            </div>
        </section>

        <section class="card client-permissions-support">
            <span class="eyebrow">Importante</span>
            <h2>Os acessos protegem sua conta.</h2>
            <p>Evite conceder perfil de administrador sem necessidade. Você pode alterar o perfil de cada pessoa na tela de usuários.</p>
            <a class="btn btn-outline btn-block" href="<?= View::e(Router::url('/users')) ?>">Abrir usuários</a>
        </section>
    </aside>
</div>
