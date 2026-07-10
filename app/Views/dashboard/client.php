<?php

use App\Core\Auth;
use App\Core\Router;
use App\Core\View;

$step = (int) ($company['onboarding_step'] ?? 1);
$completed = !empty($company['onboarding_completed_at']);
?>
<div class="hero-card">
    <div>
        <span class="eyebrow light">Visão geral da operação</span>
        <h2><?= $completed ? 'Atendimento e relacionamento em um só lugar.' : 'Vamos preparar sua operação.' ?></h2>
        <p><?= $completed
            ? 'Acompanhe conversas, organize contatos e mova oportunidades pelo funil comercial sem perder o próximo passo.'
            : 'Conclua o assistente inicial para configurar a empresa, a instância da Evolution e o primeiro agente de IA.' ?></p>
    </div>
    <?php if ($completed && Auth::can('conversations.view')): ?>
        <a class="btn btn-primary" href="<?= View::e(Router::url('/conversations')) ?>">Abrir conversas</a>
    <?php elseif (Auth::can('onboarding.manage')): ?>
        <a class="btn btn-primary" href="<?= View::e(Router::url('/onboarding')) ?>">Continuar configuração</a>
    <?php endif; ?>
</div>

<?php if (!empty($notifications)): ?>
<section class="card client-notification-panel">
    <div class="section-heading">
        <div><span class="eyebrow">Avisos recentes</span><h2>Notificações da conta</h2></div>
        <a class="btn btn-small btn-outline" href="<?= View::e(Router::url('/notifications')) ?>">Ver todas</a>
    </div>
    <div class="compact-notifications">
        <?php foreach (array_slice($notifications, 0, 3) as $notification): ?>
            <a class="compact-notification <?= ($notification['status'] ?? '') === 'unread' ? 'is-unread' : '' ?>" href="<?= View::e(Router::url('/notifications')) ?>">
                <span class="notification-dot"></span>
                <span><strong><?= View::e($notification['title'] ?? '') ?></strong><small><?= View::e(mb_strimwidth((string) ($notification['message'] ?? ''), 0, 110, '...')) ?></small></span>
            </a>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<div class="metric-grid metric-grid-six">
    <article class="metric-card"><span>Conversas abertas</span><strong><?= (int) ($conversations['open_count'] ?? 0) ?></strong><small>Em andamento</small></article>
    <article class="metric-card"><span>Não lidas</span><strong><?= (int) ($conversations['unread_count'] ?? 0) ?></strong><small>Mensagens aguardando</small></article>
    <article class="metric-card"><span>Atendimento humano</span><strong><?= (int) ($conversations['human_count'] ?? 0) ?></strong><small>Assumidas pela equipe</small></article>
    <article class="metric-card"><span>Instâncias</span><strong><?= (int) ($instances['total'] ?? 0) ?></strong><small><?= (int) ($instances['connected'] ?? 0) ?> conectada(s)</small></article>
    <article class="metric-card"><span>Agentes ativos</span><strong><?= (int) $activeAgents ?></strong><small>Perfis configurados</small></article>
    <article class="metric-card"><span>Usuários ativos</span><strong><?= (int) $activeUsers ?></strong><small>Na sua empresa</small></article>
</div>

<div class="content-grid two-columns">
    <article class="card">
        <div class="section-heading"><div><span class="eyebrow">Acesso rápido</span><h2>Fluxo da equipe</h2></div></div>
        <ol class="steps">
            <?php if (Auth::can('conversations.view')): ?><li><span>1</span><div><strong><a href="<?= View::e(Router::url('/conversations')) ?>">Responder conversas</a></strong><small>Mensagens, atendimento humano e IA.</small></div></li><?php endif; ?>
            <?php if (Auth::can('contacts.view')): ?><li><span>2</span><div><strong><a href="<?= View::e(Router::url('/contacts')) ?>">Organizar contatos</a></strong><small>Leads, clientes, tags e observações.</small></div></li><?php endif; ?>
            <?php if (Auth::can('crm.view')): ?><li><span>3</span><div><strong><a href="<?= View::e(Router::url('/crm')) ?>">Atualizar o funil</a></strong><small>Oportunidades, valores e responsáveis.</small></div></li><?php endif; ?>
            <?php if (Auth::can('tasks.view')): ?><li><span>4</span><div><strong><a href="<?= View::e(Router::url('/tasks')) ?>">Executar follow-ups</a></strong><small>Tarefas, ligações e reuniões.</small></div></li><?php endif; ?>
        </ol>
    </article>

    <article class="card accent-card">
        <span class="eyebrow">ZIP 04 instalado</span>
        <h2>CRM integrado ao atendimento.</h2>
        <p>Transforme contatos das conversas em oportunidades, mova cada negócio pelo Kanban e registre notas e próximos passos.</p>
        <div class="tag-row"><span>Contatos</span><span>Kanban</span><span>Notas</span><span>Follow-ups</span></div>
    </article>
</div>
