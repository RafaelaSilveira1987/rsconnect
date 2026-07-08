<?php

use App\Core\Router;
use App\Core\View;
?>
<div class="hero-card hero-admin">
    <div>
        <span class="eyebrow light">Controle da plataforma</span>
        <h2>Operação multiempresa do RS Connect.</h2>
        <p>Cadastre clientes, acompanhe o onboarding e controle usuários, instâncias e agentes em uma única visão.</p>
    </div>
    <a class="btn btn-light" href="<?= View::e(Router::url('/companies')) ?>">Gerenciar empresas</a>
</div>

<div class="metric-grid metric-grid-six">
    <article class="metric-card"><span>Empresas</span><strong><?= (int) $metrics['tenants'] ?></strong><small>Total cadastrado</small></article>
    <article class="metric-card"><span>Empresas ativas</span><strong><?= (int) $metrics['activeTenants'] ?></strong><small>Em operação</small></article>
    <article class="metric-card"><span>Onboarding concluído</span><strong><?= (int) $metrics['onboarded'] ?></strong><small>Clientes preparados</small></article>
    <article class="metric-card"><span>Usuários ativos</span><strong><?= (int) $metrics['users'] ?></strong><small>Em todos os tenants</small></article>
    <article class="metric-card"><span>Instâncias</span><strong><?= (int) $metrics['instances'] ?></strong><small>Evolution API</small></article>
    <article class="metric-card"><span>Agentes ativos</span><strong><?= (int) $metrics['agents'] ?></strong><small>Configurações de IA</small></article>
    <article class="metric-card"><span>Conversas</span><strong><?= (int) $metrics['conversations'] ?></strong><small><?= (int) $metrics['unread'] ?> não lida(s)</small></article>
</div>

<article class="card">
    <div class="section-heading">
        <div><span class="eyebrow">Clientes</span><h2>Empresas recentes</h2></div>
        <a class="btn btn-primary" href="<?= View::e(Router::url('/companies')) ?>">Nova empresa</a>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Empresa</th><th>Plano</th><th>Usuários</th><th>Instâncias</th><th>Agentes</th><th>Conversas</th><th>Onboarding</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($tenants as $tenant): ?>
                <tr>
                    <td><strong><?= View::e($tenant['name']) ?></strong><small><?= View::e($tenant['slug']) ?></small></td>
                    <td><?= View::e(ucfirst($tenant['plan'])) ?></td>
                    <td><?= (int) $tenant['users_count'] ?></td>
                    <td><?= (int) $tenant['instances_count'] ?></td>
                    <td><?= (int) $tenant['agents_count'] ?></td>
                    <td><?= (int) $tenant['conversations_count'] ?></td>
                    <td>
                        <?php if ($tenant['onboarding_completed_at']): ?>
                            <span class="badge badge-active">Concluído</span>
                        <?php else: ?>
                            <span class="badge badge-pending">Etapa <?= (int) $tenant['onboarding_step'] ?>/3</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge badge-<?= View::e($tenant['status']) ?>"><?= View::e(ucfirst($tenant['status'])) ?></span></td>
                    <td><a class="table-link" href="<?= View::e(Router::url('/company-settings?id=' . (int) $tenant['id'])) ?>">Editar</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$tenants): ?><tr><td colspan="9" class="empty-state">Nenhuma empresa cadastrada.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</article>
