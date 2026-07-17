<?php

use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;

$money = static fn (float|int|string $value): string => 'R$ ' . number_format((float) $value, 2, ',', '.');
$date = static function (?string $value, string $format = 'd/m/Y H:i'): string {
    if (!$value) return 'Não definido';
    $timestamp = strtotime($value);
    return $timestamp ? date($format, $timestamp) : (string) $value;
};
$priorityLabels = ['low' => 'Baixa', 'medium' => 'Média', 'high' => 'Alta'];
$activityLabels = ['task' => 'Tarefa', 'follow_up' => 'Retorno', 'call' => 'Ligação', 'meeting' => 'Reunião', 'demo' => 'Demonstração', 'proposal' => 'Proposta'];
$baseQuery = array_filter([
    'q' => $filters['q'] ?? '',
    'stage_id' => (int) ($filters['stage_id'] ?? 0),
    'owner_id' => (int) ($filters['owner_id'] ?? 0),
    'priority' => $filters['priority'] ?? '',
], static fn ($value) => $value !== '' && $value !== 0);
$byStage = [];
foreach ($opportunities as $opportunity) {
    $byStage[(int) $opportunity['stage_id']][] = $opportunity;
}
?>

<section class="admin-executive-hero admin-crm-hero">
    <div class="admin-executive-hero-copy">
        <span class="eyebrow">Comercial RS Connect</span>
        <h2>CRM de vendas e relacionamento</h2>
        <p>Organize interessados, demonstrações, propostas, implantação e clientes em risco sem misturar os leads das empresas atendidas.</p>
    </div>
    <div class="admin-executive-hero-actions">
        <button class="btn btn-primary" type="button" data-toggle-panel="admin-crm-create-drawer">Nova oportunidade</button>
        <a class="btn btn-outline" href="<?= View::e(Router::url('/reports?section=commercial')) ?>">Ver resultados comerciais</a>
    </div>
</section>

<section class="admin-crm-kpis" aria-label="Indicadores comerciais">
    <article class="card"><small>Oportunidades abertas</small><strong><?= (int) ($metrics['open_count'] ?? 0) ?></strong><em><?= $money($metrics['open_value'] ?? 0) ?> em negociação</em></article>
    <article class="card"><small>Clientes conquistados</small><strong><?= (int) ($metrics['won_count'] ?? 0) ?></strong><em><?= $money($metrics['won_value'] ?? 0) ?> em contratos</em></article>
    <article class="card"><small>Conversão</small><strong><?= number_format((float) ($metrics['conversion_rate'] ?? 0), 1, ',', '.') ?>%</strong><em>ganhos entre oportunidades encerradas</em></article>
    <article class="card"><small>Atividades pendentes</small><strong><?= count($dueActivities) ?></strong><em>próximos contatos e compromissos</em></article>
</section>

<form class="card admin-crm-toolbar" method="get" action="<?= View::e(Router::url('/crm')) ?>">
    <label class="field admin-crm-search"><span>Buscar</span><input name="q" value="<?= View::e($filters['q'] ?? '') ?>" placeholder="Empresa, contato, telefone ou oportunidade"></label>
    <label class="field"><span>Etapa</span><select name="stage_id"><option value="">Todas</option><?php foreach ($stages as $stage): ?><option value="<?= (int) $stage['id'] ?>" <?= (int) ($filters['stage_id'] ?? 0) === (int) $stage['id'] ? 'selected' : '' ?>><?= View::e($stage['name']) ?></option><?php endforeach; ?></select></label>
    <label class="field"><span>Responsável RS</span><select name="owner_id"><option value="">Toda a equipe</option><?php foreach ($team as $member): ?><option value="<?= (int) $member['id'] ?>" <?= (int) ($filters['owner_id'] ?? 0) === (int) $member['id'] ? 'selected' : '' ?>><?= View::e($member['name']) ?></option><?php endforeach; ?></select></label>
    <label class="field"><span>Prioridade</span><select name="priority"><option value="">Todas</option><?php foreach ($priorityLabels as $key => $label): ?><option value="<?= View::e($key) ?>" <?= ($filters['priority'] ?? '') === $key ? 'selected' : '' ?>><?= View::e($label) ?></option><?php endforeach; ?></select></label>
    <div class="admin-crm-toolbar-actions"><button class="btn btn-primary" type="submit">Filtrar</button><a class="btn btn-quiet" href="<?= View::e(Router::url('/crm')) ?>">Limpar</a></div>
</form>

<div class="admin-crm-layout">
    <section class="admin-crm-board-wrap">
        <div class="admin-crm-board" aria-label="Funil comercial da RS Connect">
            <?php foreach ($stages as $stage): ?>
                <?php $stageItems = $byStage[(int) $stage['id']] ?? []; ?>
                <section class="admin-crm-column stage-<?= View::e($stage['color_key']) ?>">
                    <header><div><span class="stage-dot"></span><strong><?= View::e($stage['name']) ?></strong></div><b><?= count($stageItems) ?></b></header>
                    <div class="admin-crm-column-list">
                        <?php foreach ($stageItems as $opportunity): ?>
                            <?php $url = '/crm?' . http_build_query($baseQuery + ['opportunity_id' => (int) $opportunity['id']]); ?>
                            <article class="admin-crm-deal <?= $opportunity['priority'] === 'high' ? 'is-high' : '' ?>">
                                <a class="admin-crm-deal-main" href="<?= View::e(Router::url($url)) ?>">
                                    <div class="admin-crm-deal-top"><span class="badge priority-<?= View::e($opportunity['priority']) ?>"><?= View::e($priorityLabels[$opportunity['priority']] ?? $opportunity['priority']) ?></span><?php if ($opportunity['tenant_id']): ?><span class="badge badge-success">Cliente cadastrado</span><?php endif; ?></div>
                                    <h3><?= View::e($opportunity['company_name']) ?></h3>
                                    <p><?= View::e($opportunity['title']) ?></p>
                                    <dl><div><dt>Contato</dt><dd><?= View::e($opportunity['contact_name']) ?></dd></div><div><dt>Valor</dt><dd><?= $money($opportunity['value']) ?></dd></div></dl>
                                    <footer><span><?= View::e($opportunity['owner_name'] ?: 'Sem responsável') ?></span><span><?= (int) $opportunity['pending_activities'] ?> atividade(s)</span></footer>
                                    <?php if ($opportunity['next_due_at']): ?><small class="admin-crm-next <?= strtotime($opportunity['next_due_at']) < time() ? 'is-late' : '' ?>">Próximo contato: <?= View::e($date($opportunity['next_due_at'], 'd/m H:i')) ?></small><?php endif; ?>
                                </a>
                                <form class="admin-crm-quick-move" method="post" action="<?= View::e(Router::url('/crm/admin/opportunities/move')) ?>">
                                    <?= Csrf::input() ?><input type="hidden" name="opportunity_id" value="<?= (int) $opportunity['id'] ?>">
                                    <select name="stage_id" aria-label="Mover oportunidade" onchange="this.form.submit()">
                                        <?php foreach ($stages as $target): ?><option value="<?= (int) $target['id'] ?>" <?= (int) $target['id'] === (int) $opportunity['stage_id'] ? 'selected' : '' ?>>Mover para: <?= View::e($target['name']) ?></option><?php endforeach; ?>
                                    </select>
                                </form>
                            </article>
                        <?php endforeach; ?>
                        <?php if (!$stageItems): ?><div class="admin-crm-column-empty">Nenhuma oportunidade nesta etapa.</div><?php endif; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>
    </section>

    <aside class="card admin-crm-agenda">
        <div class="section-heading"><div><span class="eyebrow">Agenda comercial</span><h2>Próximas atividades</h2></div></div>
        <div class="admin-crm-activity-list">
            <?php foreach ($dueActivities as $activity): ?>
                <a href="<?= View::e(Router::url('/crm?opportunity_id=' . (int) $activity['opportunity_id'])) ?>" class="admin-crm-activity <?= $activity['due_at'] && strtotime($activity['due_at']) < time() ? 'is-late' : '' ?>">
                    <span><?= View::e($activityLabels[$activity['activity_type']] ?? $activity['activity_type']) ?></span>
                    <strong><?= View::e($activity['title']) ?></strong>
                    <small><?= View::e($activity['company_name']) ?> · <?= View::e($date($activity['due_at'])) ?></small>
                </a>
            <?php endforeach; ?>
            <?php if (!$dueActivities): ?><div class="empty-state">Nenhuma atividade comercial pendente.</div><?php endif; ?>
        </div>
    </aside>
</div>

<aside class="conversation-details conversation-drawer admin-form-drawer admin-crm-create-drawer" id="admin-crm-create-drawer" aria-label="Nova oportunidade" role="dialog" aria-modal="true">
    <div class="conversation-drawer-header"><div><span class="eyebrow">Novo contato comercial</span><h2>Criar oportunidade</h2><p>Registre somente as informações essenciais. Os detalhes podem ser complementados depois.</p></div><button class="icon-button drawer-close" type="button" data-close-panel="admin-crm-create-drawer" aria-label="Fechar">×</button></div>
    <div class="conversation-drawer-body">
        <form class="drawer-form" method="post" action="<?= View::e(Router::url('/crm/admin/opportunities')) ?>">
            <?= Csrf::input() ?>
            <section class="drawer-section"><div class="drawer-section-title"><div><span class="eyebrow">1. Contato</span><h3>Quem demonstrou interesse?</h3></div></div><div class="drawer-form-grid">
                <label class="field drawer-span"><span>Empresa ou negócio</span><input name="company_name" placeholder="Nome da empresa" required></label>
                <label class="field"><span>Nome do contato</span><input name="contact_name" placeholder="Pessoa responsável" required></label>
                <label class="field"><span>Segmento</span><input name="segment" placeholder="Clínica, loja, serviços..."></label>
                <label class="field"><span>E-mail</span><input type="email" name="email" placeholder="contato@empresa.com"></label>
                <label class="field"><span>WhatsApp</span><input name="phone" placeholder="(32) 99999-9999"></label>
                <label class="field drawer-span"><span>Origem do contato</span><input name="source" placeholder="Indicação, Instagram, anúncio, site..."></label>
            </div></section>
            <section class="drawer-section"><div class="drawer-section-title"><div><span class="eyebrow">2. Oportunidade</span><h3>O que está sendo negociado?</h3></div></div><div class="drawer-form-grid">
                <label class="field drawer-span"><span>Título da oportunidade</span><input name="title" placeholder="Implantação RS Connect + automação" required></label>
                <label class="field"><span>Etapa inicial</span><select name="stage_id" required><?php foreach ($stages as $stage): ?><option value="<?= (int) $stage['id'] ?>"><?= View::e($stage['name']) ?></option><?php endforeach; ?></select></label>
                <label class="field"><span>Valor estimado</span><input name="value" inputmode="decimal" placeholder="0,00"></label>
                <label class="field"><span>Responsável RS</span><select name="owner_user_id"><option value="">Sem responsável</option><?php foreach ($team as $member): ?><option value="<?= (int) $member['id'] ?>"><?= View::e($member['name']) ?></option><?php endforeach; ?></select></label>
                <label class="field"><span>Prioridade</span><select name="priority"><option value="low">Baixa</option><option value="medium" selected>Média</option><option value="high">Alta</option></select></label>
                <label class="field"><span>Previsão de fechamento</span><input type="date" name="expected_close_at"></label>
                <label class="field"><span>Próximo contato</span><input type="datetime-local" name="next_activity_at"></label>
            </div></section>
            <div class="drawer-savebar"><button class="btn btn-quiet" type="button" data-close-panel="admin-crm-create-drawer">Cancelar</button><button class="btn btn-primary" type="submit">Criar oportunidade</button></div>
        </form>
    </div>
</aside>

<?php if ($selected): ?>
<aside class="conversation-details conversation-drawer admin-form-drawer admin-crm-detail-drawer is-open" id="admin-crm-detail-drawer" aria-label="Detalhes da oportunidade" role="dialog" aria-modal="true">
    <div class="conversation-drawer-header"><div><span class="eyebrow">Oportunidade #<?= (int) $selected['id'] ?></span><h2><?= View::e($selected['company_name']) ?></h2><p><?= View::e($selected['title']) ?></p></div><a class="icon-button drawer-close" href="<?= View::e(Router::url('/crm?' . http_build_query($baseQuery))) ?>" aria-label="Fechar">×</a></div>
    <div class="conversation-drawer-body">
        <div class="admin-crm-detail-summary"><span class="badge stage-<?= View::e($selected['color_key']) ?>"><?= View::e($selected['stage_name']) ?></span><strong><?= $money($selected['value']) ?></strong><small><?= View::e($selected['contact_name']) ?> · <?= View::e($selected['phone'] ?: $selected['email'] ?: 'Contato sem telefone/e-mail') ?></small></div>

        <details class="drawer-accordion" open><summary>Dados da oportunidade</summary><form class="drawer-form" method="post" action="<?= View::e(Router::url('/crm/admin/opportunities/update')) ?>"><?= Csrf::input() ?><input type="hidden" name="opportunity_id" value="<?= (int) $selected['id'] ?>"><div class="drawer-form-grid">
            <label class="field drawer-span"><span>Empresa</span><input name="company_name" value="<?= View::e($selected['company_name']) ?>" required></label>
            <label class="field"><span>Contato</span><input name="contact_name" value="<?= View::e($selected['contact_name']) ?>" required></label>
            <label class="field"><span>Segmento</span><input name="segment" value="<?= View::e($selected['segment'] ?? '') ?>"></label>
            <label class="field"><span>E-mail</span><input type="email" name="email" value="<?= View::e($selected['email'] ?? '') ?>"></label>
            <label class="field"><span>WhatsApp</span><input name="phone" value="<?= View::e($selected['phone'] ?? '') ?>"></label>
            <label class="field drawer-span"><span>Origem</span><input name="source" value="<?= View::e($selected['source'] ?? '') ?>"></label>
            <label class="field drawer-span"><span>Oportunidade</span><input name="title" value="<?= View::e($selected['title']) ?>" required></label>
            <label class="field"><span>Valor</span><input name="value" value="<?= View::e(number_format((float) $selected['value'], 2, ',', '.')) ?>"></label>
            <label class="field"><span>Responsável RS</span><select name="owner_user_id"><option value="">Sem responsável</option><?php foreach ($team as $member): ?><option value="<?= (int) $member['id'] ?>" <?= (int) $selected['owner_user_id'] === (int) $member['id'] ? 'selected' : '' ?>><?= View::e($member['name']) ?></option><?php endforeach; ?></select></label>
            <label class="field"><span>Prioridade</span><select name="priority"><?php foreach ($priorityLabels as $key => $label): ?><option value="<?= View::e($key) ?>" <?= $selected['priority'] === $key ? 'selected' : '' ?>><?= View::e($label) ?></option><?php endforeach; ?></select></label>
            <label class="field"><span>Fechamento previsto</span><input type="date" name="expected_close_at" value="<?= View::e($selected['expected_close_at'] ?? '') ?>"></label>
            <label class="field"><span>Próximo contato</span><input type="datetime-local" name="next_activity_at" value="<?= $selected['next_activity_at'] ? View::e(date('Y-m-d\TH:i', strtotime($selected['next_activity_at']))) : '' ?>"></label>
            <label class="field drawer-span"><span>Motivo de perda/cancelamento</span><textarea name="lost_reason" rows="2"><?= View::e($selected['lost_reason'] ?? '') ?></textarea></label>
        </div><button class="btn btn-primary" type="submit">Salvar alterações</button></form></details>

        <section class="drawer-section"><div class="section-heading"><div><span class="eyebrow">Próximos passos</span><h3>Atividades comerciais</h3></div></div><form class="drawer-form admin-crm-activity-form" method="post" action="<?= View::e(Router::url('/crm/admin/activities')) ?>"><?= Csrf::input() ?><input type="hidden" name="opportunity_id" value="<?= (int) $selected['id'] ?>"><div class="drawer-form-grid">
            <label class="field drawer-span"><span>Atividade</span><input name="title" placeholder="Ex.: apresentar proposta revisada" required></label>
            <label class="field"><span>Tipo</span><select name="activity_type"><?php foreach ($activityLabels as $key => $label): ?><option value="<?= View::e($key) ?>"><?= View::e($label) ?></option><?php endforeach; ?></select></label>
            <label class="field"><span>Responsável</span><select name="assigned_user_id"><option value="">Sem responsável</option><?php foreach ($team as $member): ?><option value="<?= (int) $member['id'] ?>"><?= View::e($member['name']) ?></option><?php endforeach; ?></select></label>
            <label class="field drawer-span"><span>Quando</span><input type="datetime-local" name="due_at"></label>
            <label class="field drawer-span"><span>Detalhes</span><textarea name="description" rows="2" placeholder="Informações importantes para o próximo contato"></textarea></label>
        </div><button class="btn btn-outline" type="submit">Adicionar atividade</button></form>
        <div class="admin-crm-detail-activities"><?php foreach ($activities as $activity): ?><article class="<?= $activity['status'] === 'completed' ? 'is-completed' : '' ?>"><div><span><?= View::e($activityLabels[$activity['activity_type']] ?? $activity['activity_type']) ?></span><strong><?= View::e($activity['title']) ?></strong><small><?= View::e($activity['assigned_name'] ?: 'Sem responsável') ?> · <?= View::e($date($activity['due_at'])) ?></small></div><?php if ($activity['status'] === 'pending'): ?><form method="post" action="<?= View::e(Router::url('/crm/admin/activities/status')) ?>"><?= Csrf::input() ?><input type="hidden" name="activity_id" value="<?= (int) $activity['id'] ?>"><input type="hidden" name="status" value="completed"><button class="btn btn-small btn-outline" type="submit">Concluir</button></form><?php endif; ?></article><?php endforeach; ?><?php if (!$activities): ?><div class="empty-state">Nenhuma atividade registrada.</div><?php endif; ?></div></section>

        <section class="drawer-section"><div class="section-heading"><div><span class="eyebrow">Histórico</span><h3>Observações comerciais</h3></div></div><form class="note-composer" method="post" action="<?= View::e(Router::url('/crm/admin/notes')) ?>"><?= Csrf::input() ?><input type="hidden" name="opportunity_id" value="<?= (int) $selected['id'] ?>"><textarea name="note" rows="3" placeholder="Registre contexto da negociação, objeções e decisões..." required></textarea><button class="btn btn-primary btn-small" type="submit">Adicionar observação</button></form><div class="admin-crm-notes"><?php foreach ($notes as $note): ?><article><strong><?= View::e($note['user_name'] ?: 'Equipe RS') ?></strong><p><?= nl2br(View::e($note['note'])) ?></p><small><?= View::e($date($note['created_at'])) ?></small></article><?php endforeach; ?><?php if (!$notes): ?><div class="empty-state">Nenhuma observação registrada.</div><?php endif; ?></div></section>

        <?php if (!$selected['tenant_id']): ?><details class="drawer-accordion admin-crm-convert"><summary>Converter em cliente ativo</summary><p>Cria a empresa, o administrador inicial, a assinatura e o funil do cliente.</p><form class="drawer-form" method="post" action="<?= View::e(Router::url('/crm/admin/convert')) ?>"><?= Csrf::input() ?><input type="hidden" name="opportunity_id" value="<?= (int) $selected['id'] ?>"><div class="drawer-form-grid"><label class="field drawer-span"><span>Responsável</span><input name="owner_name" value="<?= View::e($selected['contact_name']) ?>" required></label><label class="field drawer-span"><span>E-mail de acesso</span><input type="email" name="owner_email" value="<?= View::e($selected['email'] ?? '') ?>" required></label><label class="field"><span>Plano</span><select name="plan"><option value="starter">Starter</option><option value="pro">Profissional</option><option value="business">Business</option><option value="custom">Personalizado</option></select></label><label class="field"><span>Senha inicial</span><input type="password" name="owner_password" minlength="8" required></label></div><button class="btn btn-primary" type="submit">Criar empresa e primeiro acesso</button></form></details><?php else: ?><a class="btn btn-primary btn-block" href="<?= View::e(Router::url('/companies/overview?id=' . (int) $selected['tenant_id'])) ?>">Abrir empresa vinculada</a><?php endif; ?>
    </div>
</aside>
<?php endif; ?>
