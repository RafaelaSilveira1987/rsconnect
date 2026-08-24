<?php

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;

$formatDate = static function (?string $date, string $format = 'd/m/Y H:i'): string {
    if (!$date) {
        return '—';
    }
    $timestamp = strtotime($date);
    return $timestamp ? date($format, $timestamp) : $date;
};
$currentTenantId = (int) ($filters['tenant_id'] ?? 0);
$statusClass = static fn (?string $status): string => preg_replace('/[^a-z0-9_-]/i', '', (string) $status);
?>

<section class="campaign-hero card">
    <div>
        <span class="eyebrow">Disparos controlados</span>
        <h2>Campanhas e mensagens em lote</h2>
        <p>Crie campanhas com aprovação, audiência revisável e envio por lotes. O objetivo é manter controle operacional, histórico e segurança antes de qualquer disparo.</p>
    </div>
    <a class="btn btn-primary" href="#campaign-form">Nova campanha</a>
</section>

<section class="metric-grid campaign-metrics">
    <article class="metric-card compact"><span>Total</span><strong><?= (int) ($metrics['total'] ?? 0) ?></strong><small>campanhas cadastradas</small></article>
    <article class="metric-card compact"><span>Ativas</span><strong><?= (int) ($metrics['active'] ?? 0) ?></strong><small>fila ou envio</small></article>
    <article class="metric-card compact"><span>Aguardando</span><strong><?= (int) ($metrics['pending_approval'] ?? 0) ?></strong><small>aprovação</small></article>
    <article class="metric-card compact"><span>Enviadas</span><strong><?= (int) ($metrics['sent'] ?? 0) ?></strong><small>mensagens registradas</small></article>
</section>

<form class="filter-bar card" method="get" action="<?= View::e(Router::url('/campaigns')) ?>">
    <?php if (Auth::isSuperAdmin()): ?>
        <label class="field compact-field"><span>Empresa</span>
            <select name="tenant_id" onchange="this.form.submit()">
                <option value="">Todas</option>
                <?php foreach ($tenants as $tenant): ?>
                    <option value="<?= (int) $tenant['id'] ?>" <?= $currentTenantId === (int) $tenant['id'] ? 'selected' : '' ?>><?= View::e($tenant['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    <?php endif; ?>
    <label class="field compact-field"><span>Status</span>
        <select name="status">
            <option value="">Todos</option>
            <?php foreach ($statusLabels as $value => $label): ?>
                <option value="<?= View::e($value) ?>" <?= ($filters['status'] ?? '') === $value ? 'selected' : '' ?>><?= View::e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label class="field compact-field"><span>Busca</span><input name="search" value="<?= View::e($filters['search'] ?? '') ?>" placeholder="Nome ou descrição"></label>
    <button class="btn btn-secondary" type="submit">Filtrar</button>
    <a class="btn btn-outline" href="<?= View::e(Router::url('/campaigns')) ?>">Limpar</a>
</form>

<div class="campaign-layout">
    <section class="card campaign-list-panel">
        <div class="section-heading clean-heading">
            <div>
                <span class="eyebrow">Campanhas</span>
                <h2>Histórico e fila</h2>
            </div>
        </div>
        <div class="campaign-list">
            <?php foreach ($campaigns as $campaign): ?>
                <?php $progress = max(0, min(100, (int) (($campaign['recipients_total'] ?? 0) > 0 ? (($campaign['recipients_sent'] ?? 0) / max(1, (int) $campaign['recipients_total'])) * 100 : 0))); ?>
                <a class="campaign-card<?= $selected && (int) $selected['id'] === (int) $campaign['id'] ? ' is-selected' : '' ?>" href="<?= View::e(Router::url('/campaigns?campaign_id=' . (int) $campaign['id'] . ($currentTenantId ? '&tenant_id=' . $currentTenantId : ''))) ?>">
                    <div>
                        <strong><?= View::e($campaign['name']) ?></strong>
                        <small><?= View::e($campaign['tenant_name']) ?> · <?= View::e($campaign['instance_name'] ?: 'Sem instância') ?></small>
                    </div>
                    <span class="mini-badge campaign-status-<?= View::e($statusClass($campaign['status'])) ?>"><?= View::e($statusLabels[$campaign['status']] ?? $campaign['status']) ?></span>
                    <div class="campaign-progress"><i style="width: <?= $progress ?>%"></i></div>
                    <small><?= (int) ($campaign['recipients_sent'] ?? 0) ?> de <?= (int) ($campaign['recipients_total'] ?? 0) ?> enviados · <?= View::e($formatDate($campaign['created_at'], 'd/m H:i')) ?></small>
                </a>
            <?php endforeach; ?>
            <?php if (!$campaigns): ?>
                <div class="empty-state">Nenhuma campanha encontrada.</div>
            <?php endif; ?>
        </div>
    </section>

    <aside class="card campaign-form-panel" id="campaign-form">
        <div class="section-heading clean-heading">
            <div>
                <span class="eyebrow">Nova campanha</span>
                <h2>Mensagem controlada</h2>
            </div>
        </div>
        <?php if ($canManage): ?>
            <form method="post" action="<?= View::e(Router::url('/campaigns')) ?>">
                <?= Csrf::input() ?>
                <?php if (Auth::isSuperAdmin()): ?>
                    <label class="field"><span>Empresa</span>
                        <select name="tenant_id" required>
                            <option value="">Selecione</option>
                            <?php foreach ($tenants as $tenant): ?>
                                <option value="<?= (int) $tenant['id'] ?>" <?= $currentTenantId === (int) $tenant['id'] ? 'selected' : '' ?>><?= View::e($tenant['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                <?php endif; ?>
                <label class="field"><span>Nome da campanha</span><input name="name" required placeholder="Ex.: Retorno de leads da semana"></label>
                <label class="field"><span>Descrição</span><input name="description" placeholder="Uso interno"></label>
                <label class="field"><span>Instância WhatsApp</span>
                    <select name="evolution_instance_id" required>
                        <option value="">Selecione</option>
                        <?php foreach ($instances as $instance): ?>
                            <option value="<?= (int) $instance['id'] ?>"><?= View::e((Auth::isSuperAdmin() ? ($instance['tenant_name'] ?? '') . ' — ' : '') . $instance['name'] . ' · ' . $instance['status']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="field"><span>Audiência</span>
                    <select name="audience_filter">
                        <option value="all_leads">Leads ativos</option>
                        <option value="customers">Clientes</option>
                        <option value="tag">Contatos por tag</option>
                        <option value="manual">Lista manual</option>
                    </select>
                </label>
                <label class="field"><span>Tag, se usar audiência por tag</span><input name="tag_filter" placeholder="Ex.: orçamento"></label>
                <label class="field"><span>Lista manual, se necessário</span><textarea name="manual_numbers" rows="4" placeholder="5532999999999 | Nome do contato&#10;5532888888888 | Outro contato"></textarea></label>
                <label class="field"><span>Agendar para</span><input type="datetime-local" name="scheduled_at"></label>
                <label class="field"><span>Mensagem</span><textarea name="message_template" rows="7" required placeholder="Olá, {{nome}}. Passando para retomar nosso atendimento pela {{empresa}}."></textarea></label>
                <div class="campaign-help-box">
                    <strong>Variáveis disponíveis</strong>
                    <span>{{nome}}</span><span>{{telefone}}</span><span>{{empresa}}</span><span>{{data}}</span>
                </div>
                <button class="btn btn-primary btn-block" type="submit">Criar rascunho</button>
            </form>
        <?php else: ?>
            <p class="muted-text">Seu perfil pode visualizar campanhas, mas não pode criar disparos.</p>
        <?php endif; ?>
    </aside>
</div>

<?php if ($selected): ?>
    <section class="card campaign-detail-panel">
        <div class="section-heading clean-heading">
            <div>
                <span class="eyebrow">Detalhes da campanha</span>
                <h2><?= View::e($selected['name']) ?></h2>
                <p class="muted-text"><?= View::e($selected['description'] ?: 'Sem descrição') ?></p>
            </div>
            <div class="campaign-status-stack">
                <span class="mini-badge campaign-status-<?= View::e($statusClass($selected['status'])) ?>"><?= View::e($statusLabels[$selected['status']] ?? $selected['status']) ?></span>
                <span class="mini-badge approval-<?= View::e($statusClass($selected['approval_status'])) ?>"><?= View::e($approvalLabels[$selected['approval_status']] ?? $selected['approval_status']) ?></span>
            </div>
        </div>

        <div class="campaign-detail-grid">
            <article>
                <h3>Mensagem</h3>
                <div class="campaign-message-preview"><?= nl2br(View::e($selected['message_template'])) ?></div>
            </article>
            <article>
                <h3>Resumo</h3>
                <dl class="detail-list">
                    <dt>Empresa</dt><dd><?= View::e($selected['tenant_name']) ?></dd>
                    <dt>Instância</dt><dd><?= View::e($selected['instance_name']) ?></dd>
                    <dt>Audiência</dt><dd><?= View::e($selected['audience_filter']) ?><?= $selected['tag_filter'] ? ' · ' . View::e($selected['tag_filter']) : '' ?></dd>
                    <dt>Agendada para</dt><dd><?= View::e($formatDate($selected['scheduled_at'])) ?></dd>
                    <dt>Último disparo</dt><dd><?= View::e($formatDate($selected['last_dispatched_at'])) ?></dd>
                </dl>
            </article>
        </div>

        <?php if ($canManage): ?>
            <div class="campaign-actions">
                <form method="post" action="<?= View::e(Router::url('/campaigns/audience')) ?>">
                    <?= Csrf::input() ?><input type="hidden" name="campaign_id" value="<?= (int) $selected['id'] ?>">
                    <button class="btn btn-secondary" type="submit">Gerar audiência</button>
                </form>
                <form method="post" action="<?= View::e(Router::url('/campaigns/approve')) ?>">
                    <?= Csrf::input() ?><input type="hidden" name="campaign_id" value="<?= (int) $selected['id'] ?>">
                    <button class="btn btn-primary" type="submit">Aprovar campanha</button>
                </form>
                <form method="post" action="<?= View::e(Router::url('/campaigns/dispatch')) ?>" class="dispatch-form">
                    <?= Csrf::input() ?><input type="hidden" name="campaign_id" value="<?= (int) $selected['id'] ?>">
                    <label class="field compact-field"><span>Lote</span><input type="number" name="batch_size" min="1" max="50" value="10"></label>
                    <button class="btn btn-primary" type="submit">Disparar lote</button>
                </form>
                <form method="post" action="<?= View::e(Router::url('/campaigns/status')) ?>">
                    <?= Csrf::input() ?><input type="hidden" name="campaign_id" value="<?= (int) $selected['id'] ?>"><input type="hidden" name="status" value="paused">
                    <button class="btn btn-outline" type="submit">Pausar</button>
                </form>
                <form method="post" action="<?= View::e(Router::url('/campaigns/status')) ?>">
                    <?= Csrf::input() ?><input type="hidden" name="campaign_id" value="<?= (int) $selected['id'] ?>"><input type="hidden" name="status" value="cancelled">
                    <button class="btn btn-danger" type="submit">Cancelar</button>
                </form>
            </div>
        <?php endif; ?>

        <div class="table-wrap campaign-recipients-table">
            <table>
                <thead><tr><th>Contato</th><th>Telefone</th><th>Status</th><th>Mensagem</th><th>Envio</th><th>Erro</th></tr></thead>
                <tbody>
                <?php foreach ($recipients as $recipient): ?>
                    <tr>
                        <td><strong><?= View::e($recipient['name'] ?: $recipient['contact_name'] ?: 'Sem nome') ?></strong><small><?= View::e($recipient['contact_status'] ?: 'manual') ?></small></td>
                        <td><?= View::e($recipient['phone']) ?></td>
                        <td><span class="mini-badge recipient-<?= View::e($statusClass($recipient['status'])) ?>"><?= View::e($recipient['status']) ?></span></td>
                        <td class="recipient-message-cell"><?= View::e(mb_substr((string) $recipient['personalized_message'], 0, 120)) ?></td>
                        <td><?= View::e($formatDate($recipient['sent_at'], 'd/m H:i')) ?></td>
                        <td><?= View::e($recipient['error_message'] ?: '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$recipients): ?>
                    <tr><td colspan="6"><div class="empty-state">Gere a audiência para visualizar os destinatários.</div></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>
