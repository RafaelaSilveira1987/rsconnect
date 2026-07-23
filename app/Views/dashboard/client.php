<?php

use App\Core\Auth;
use App\Core\Router;
use App\Core\View;

$step = (int) ($company['onboarding_step'] ?? 1);
$completed = !empty($company['onboarding_completed_at']);
$open = (int) ($conversations['open_count'] ?? 0);
$unread = (int) ($conversations['unread_count'] ?? 0);
$human = (int) ($conversations['human_count'] ?? 0);
$pendingAgenda = (int) ($agendaIntent['pending_pre_schedules'] ?? 0);
$connected = (int) ($instances['connected'] ?? 0);
$totalInstances = (int) ($instances['total'] ?? 0);
?>

<?php if (!$completed): ?>
<section class="client-dashboard-welcome">
    <div>
        <span class="eyebrow">Configuração inicial</span>
        <h2>Vamos preparar sua operação.</h2>
        <p>Conclua os passos iniciais para configurar a empresa, o WhatsApp e o primeiro agente de IA.</p>
    </div>
    <?php if (Auth::can('onboarding.manage')): ?><a class="btn btn-primary" href="<?= View::e(Router::url('/onboarding')) ?>">Continuar configuração</a><?php endif; ?>
</section>
<?php endif; ?>

<section class="client-dashboard-header">
    <div>
        <span class="eyebrow">Visão do dia</span>
        <h2>O que precisa da sua atenção agora</h2>
        <p>Acompanhe atendimento, agenda e operação sem precisar abrir vários módulos.</p>
    </div>
    <div class="client-dashboard-header-actions">
        <?php if (Auth::can('conversations.view')): ?><a class="btn btn-primary" href="<?= View::e(Router::url('/conversations')) ?>">Abrir conversas</a><?php endif; ?>
        <?php if (Auth::can('reports.view')): ?><a class="btn btn-outline" href="<?= View::e(Router::url('/reports')) ?>">Ver relatórios</a><?php endif; ?>
    </div>
</section>

<div class="client-dashboard-kpis">
    <a class="client-dashboard-kpi is-primary" href="<?= View::e(Router::url('/conversations?filter=unread')) ?>">
        <span>Não lidas</span><strong><?= $unread ?></strong><small>mensagens aguardando leitura</small>
    </a>
    <a class="client-dashboard-kpi" href="<?= View::e(Router::url('/conversations')) ?>">
        <span>Conversas abertas</span><strong><?= $open ?></strong><small>atendimentos em andamento</small>
    </a>
    <a class="client-dashboard-kpi" href="<?= View::e(Router::url('/calendar')) ?>">
        <span>Agenda pendente</span><strong><?= $pendingAgenda ?></strong><small>pré-agendamentos para revisar</small>
    </a>
    <a class="client-dashboard-kpi" href="<?= View::e(Router::url('/conversations?attendance_mode=human')) ?>">
        <span>Com a equipe</span><strong><?= $human ?></strong><small>conversas em atendimento humano</small>
    </a>
</div>

<div class="client-dashboard-grid">
    <section class="card client-dashboard-attention">
        <div class="section-heading">
            <div><span class="eyebrow">Prioridades</span><h2>Precisa de atenção</h2></div>
            <?php if (Auth::can('notifications.view')): ?><a class="btn btn-small btn-quiet" href="<?= View::e(Router::url('/notifications')) ?>">Ver avisos</a><?php endif; ?>
        </div>
        <div class="client-action-list">
            <a href="<?= View::e(Router::url('/conversations?filter=unread')) ?>" class="client-action-item <?= $unread > 0 ? 'is-warning' : 'is-ok' ?>">
                <span class="client-action-dot"></span><div><strong><?= $unread > 0 ? $unread . ' mensagem(ns) não lida(s)' : 'Caixa de entrada em dia' ?></strong><small><?= $unread > 0 ? 'Abra as conversas e priorize quem está aguardando.' : 'Nenhuma mensagem aguardando leitura.' ?></small></div><b>›</b>
            </a>
            <a href="<?= View::e(Router::url('/calendar?section=availability')) ?>" class="client-action-item <?= $pendingAgenda > 0 ? 'is-warning' : 'is-ok' ?>">
                <span class="client-action-dot"></span><div><strong><?= $pendingAgenda > 0 ? $pendingAgenda . ' pré-agendamento(s) pendente(s)' : 'Agenda sem pendências' ?></strong><small><?= $pendingAgenda > 0 ? 'Valide horários e confirme os próximos compromissos.' : 'Nenhuma validação de horário aguardando.' ?></small></div><b>›</b>
            </a>
            <a href="<?= View::e(Router::url('/instances')) ?>" class="client-action-item <?= ($totalInstances > 0 && $connected === $totalInstances) ? 'is-ok' : 'is-warning' ?>">
                <span class="client-action-dot"></span><div><strong><?= $connected ?> de <?= $totalInstances ?> conexão(ões) ativa(s)</strong><small><?= ($totalInstances > 0 && $connected === $totalInstances) ? 'WhatsApp conectado e pronto para atender.' : 'Confira as conexões que precisam ser restabelecidas.' ?></small></div><b>›</b>
            </a>
        </div>
    </section>

    <section class="card client-dashboard-operation">
        <div class="section-heading"><div><span class="eyebrow">Operação</span><h2>Situação atual</h2></div></div>
        <div class="client-status-list">
            <div><span>WhatsApp</span><strong class="<?= $connected > 0 ? 'is-ok' : 'is-warning' ?>"><?= $connected > 0 ? 'Conectado' : 'Atenção' ?></strong></div>
            <div><span>Agentes de IA</span><strong class="<?= (int) $activeAgents > 0 ? 'is-ok' : 'is-warning' ?>"><?= (int) $activeAgents ?> ativo(s)</strong></div>
            <div><span>Equipe</span><strong><?= (int) $activeUsers ?> usuário(s)</strong></div>
            <div><span>Agenda automática</span><strong class="<?= $pendingAgenda > 0 ? 'is-warning' : 'is-ok' ?>"><?= $pendingAgenda > 0 ? 'Revisar' : 'Em dia' ?></strong></div>
        </div>
    </section>
</div>

<section class="card client-dashboard-shortcuts">
    <div class="section-heading"><div><span class="eyebrow">Atalhos</span><h2>Ações rápidas</h2></div></div>
    <div class="client-shortcut-grid">
        <?php if (Auth::can('conversations.view')): ?><a href="<?= View::e(Router::url('/conversations')) ?>"><strong>Conversas</strong><small>Responder e acompanhar atendimentos</small></a><?php endif; ?>
        <?php if (Auth::can('contacts.view')): ?><a href="<?= View::e(Router::url('/contacts')) ?>"><strong>Contatos</strong><small>Organizar clientes, leads e tags</small></a><?php endif; ?>
        <?php if (Auth::can('crm.view')): ?><a href="<?= View::e(Router::url('/crm')) ?>"><strong>Comercial</strong><small>Acompanhar oportunidades e negociações</small></a><?php endif; ?>
        <?php if (Auth::can('calendar.view')): ?><a href="<?= View::e(Router::url('/calendar')) ?>"><strong>Agenda</strong><small>Compromissos e disponibilidade</small></a><?php endif; ?>
    </div>
</section>

<?php if (!empty($notifications)): ?>
<section class="card client-notification-panel client-dashboard-notifications">
    <div class="section-heading">
        <div><span class="eyebrow">Atividade recente</span><h2>Últimos avisos</h2></div>
        <a class="btn btn-small btn-outline" href="<?= View::e(Router::url('/notifications')) ?>">Ver todos</a>
    </div>
    <div class="compact-notifications">
        <?php foreach (array_slice($notifications, 0, 3) as $notification): ?>
            <a class="compact-notification <?= ($notification['status'] ?? '') === 'unread' ? 'is-unread' : '' ?>" href="<?= View::e(Router::url('/notifications')) ?>">
                <span class="notification-dot"></span>
                <span><strong><?= View::e($notification['title'] ?? '') ?></strong><small><?= View::e(mb_strimwidth((string) ($notification['message'] ?? ''), 0, 120, '...')) ?></small></span>
            </a>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>
