<?php

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;

$canManage = Auth::can('conversations.manage');
$formatDate = static function (?string $date, string $format = 'd/m/Y H:i'): string {
    if (!$date) {
        return '—';
    }
    $timestamp = strtotime($date);
    return $timestamp ? date($format, $timestamp) : $date;
};
$modeLabel = ['ai' => 'IA ativa', 'human' => 'Humano', 'paused' => 'IA pausada'];
$statusLabel = ['open' => 'Aberta', 'pending' => 'Pendente', 'closed' => 'Encerrada'];
$currentQuery = array_filter([
    'search' => $filters['search'] ?? '',
    'status' => $filters['status'] ?? '',
    'mode' => $filters['mode'] ?? '',
    'instance_id' => $filters['instance_id'] ?? 0,
    'tenant_id' => $filters['tenant_id'] ?? 0,
], static fn ($value) => $value !== '' && $value !== 0);
?>

<form class="conversation-filters card" method="get" action="<?= View::e(Router::url('/conversations')) ?>">
    <div class="filter-search">
        <span class="search-icon" aria-hidden="true"></span>
        <input name="search" value="<?= View::e($filters['search'] ?? '') ?>" placeholder="Buscar por nome, telefone ou mensagem">
    </div>

    <?php if (Auth::isSuperAdmin()): ?>
        <select name="tenant_id" aria-label="Filtrar por empresa">
            <option value="">Todas as empresas</option>
            <?php foreach ($tenants as $tenant): ?>
                <option value="<?= (int) $tenant['id'] ?>" <?= (int) ($filters['tenant_id'] ?? 0) === (int) $tenant['id'] ? 'selected' : '' ?>><?= View::e($tenant['name']) ?></option>
            <?php endforeach; ?>
        </select>
    <?php endif; ?>

    <select name="instance_id" aria-label="Filtrar por instância">
        <option value="">Todas as instâncias</option>
        <?php foreach ($instances as $instance): ?>
            <?php if (Auth::isSuperAdmin() && (int) ($filters['tenant_id'] ?? 0) > 0 && (int) $instance['tenant_id'] !== (int) $filters['tenant_id']) continue; ?>
            <option value="<?= (int) $instance['id'] ?>" <?= (int) ($filters['instance_id'] ?? 0) === (int) $instance['id'] ? 'selected' : '' ?>><?= View::e($instance['name']) ?></option>
        <?php endforeach; ?>
    </select>

    <select name="status" aria-label="Filtrar por status">
        <option value="">Todos os status</option>
        <?php foreach ($statusLabel as $value => $label): ?>
            <option value="<?= View::e($value) ?>" <?= ($filters['status'] ?? '') === $value ? 'selected' : '' ?>><?= View::e($label) ?></option>
        <?php endforeach; ?>
    </select>

    <select name="mode" aria-label="Filtrar por atendimento">
        <option value="">Todos os modos</option>
        <?php foreach ($modeLabel as $value => $label): ?>
            <option value="<?= View::e($value) ?>" <?= ($filters['mode'] ?? '') === $value ? 'selected' : '' ?>><?= View::e($label) ?></option>
        <?php endforeach; ?>
    </select>

    <button class="btn btn-secondary" type="submit">Filtrar</button>
    <a class="btn btn-outline" href="<?= View::e(Router::url('/conversations')) ?>">Limpar</a>
</form>

<div class="conversation-workspace">
    <aside class="conversation-inbox card">
        <div class="conversation-panel-heading">
            <div>
                <span class="eyebrow">Caixa de entrada</span>
                <h2>Conversas</h2>
            </div>
            <div class="conversation-heading-actions">
                <span class="badge"><?= count($conversations) ?></span>
                <?php if ($canManage): ?>
                    <details class="new-conversation-details">
                        <summary class="btn btn-primary btn-small">+ Nova</summary>
                        <form class="new-conversation-form" method="post" action="<?= View::e(Router::url('/conversations/start')) ?>">
                            <?= Csrf::input() ?>
                            <strong>Iniciar conversa</strong>
                            <label class="field"><span>Instância</span>
                                <select name="instance_id" required>
                                    <option value="">Selecione</option>
                                    <?php foreach ($instances as $instance): ?>
                                        <option value="<?= (int) $instance['id'] ?>"><?= View::e((Auth::isSuperAdmin() ? ($instance['tenant_name'] ?? '') . ' — ' : '') . $instance['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="field"><span>Telefone com DDI</span><input name="phone" inputmode="numeric" placeholder="5511999999999" required></label>
                            <label class="field"><span>Nome do contato</span><input name="name" placeholder="Opcional"></label>
                            <label class="field"><span>Primeira mensagem</span><textarea name="message" rows="3" required>Olá! Como podemos ajudar?</textarea></label>
                            <button class="btn btn-primary btn-block" type="submit" <?= !$instances ? 'disabled' : '' ?>>Enviar e abrir conversa</button>
                        </form>
                    </details>
                <?php endif; ?>
            </div>
        </div>

        <div class="conversation-list">
            <?php foreach ($conversations as $conversation): ?>
                <?php
                $query = $currentQuery;
                $query['conversation_id'] = (int) $conversation['id'];
                $isSelected = (int) ($selected['id'] ?? 0) === (int) $conversation['id'];
                $initial = mb_strtoupper(mb_substr($conversation['contact_name'] ?: $conversation['phone'], 0, 1));
                ?>
                <a class="conversation-list-item<?= $isSelected ? ' is-selected' : '' ?>" href="<?= View::e(Router::url('/conversations?' . http_build_query($query))) ?>">
                    <span class="conversation-avatar"><?= View::e($initial) ?></span>
                    <span class="conversation-summary">
                        <span class="conversation-title-row">
                            <strong><?= View::e($conversation['contact_name'] ?: $conversation['phone']) ?></strong>
                            <time><?= View::e($formatDate($conversation['last_message_at'], 'd/m H:i')) ?></time>
                        </span>
                        <span class="conversation-preview"><?= View::e($conversation['last_message_preview'] ?: 'Sem mensagens') ?></span>
                        <span class="conversation-meta-row">
                            <span class="mini-badge mode-<?= View::e($conversation['attendance_mode']) ?>"><?= View::e($modeLabel[$conversation['attendance_mode']] ?? $conversation['attendance_mode']) ?></span>
                            <?php if (Auth::isSuperAdmin()): ?><small><?= View::e($conversation['tenant_name']) ?></small><?php endif; ?>
                            <?php if ((int) $conversation['unread_count'] > 0): ?><b class="unread-count"><?= (int) $conversation['unread_count'] ?></b><?php endif; ?>
                        </span>
                    </span>
                </a>
            <?php endforeach; ?>
            <?php if (!$conversations): ?>
                <div class="empty-state conversation-empty">
                    <strong>Nenhuma conversa encontrada.</strong>
                    <span>Configure o webhook da Evolution para receber mensagens.</span>
                </div>
            <?php endif; ?>
        </div>
    </aside>

    <section class="conversation-chat card">
        <?php if ($selected): ?>
            <header class="chat-header">
                <div class="chat-contact-title">
                    <span class="conversation-avatar large"><?= View::e(mb_strtoupper(mb_substr($selected['contact_name'] ?: $selected['phone'], 0, 1))) ?></span>
                    <div>
                        <h2><?= View::e($selected['contact_name'] ?: $selected['phone']) ?></h2>
                        <p><?= View::e($selected['phone']) ?> · <?= View::e($selected['instance_label']) ?></p>
                    </div>
                </div>

                <?php if ($canManage): ?>
                    <div class="chat-actions">
                        <button class="btn btn-outline btn-small" type="button" data-toggle-panel="conversation-details">Dados do lead</button>
                        <?php if ($selected['attendance_mode'] !== 'human'): ?>
                            <form method="post" action="<?= View::e(Router::url('/conversations/mode')) ?>">
                                <?= Csrf::input() ?><input type="hidden" name="conversation_id" value="<?= (int) $selected['id'] ?>"><input type="hidden" name="mode" value="human">
                                <button class="btn btn-primary btn-small" type="submit">Assumir atendimento</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($selected['attendance_mode'] !== 'paused'): ?>
                            <form method="post" action="<?= View::e(Router::url('/conversations/mode')) ?>">
                                <?= Csrf::input() ?><input type="hidden" name="conversation_id" value="<?= (int) $selected['id'] ?>"><input type="hidden" name="mode" value="paused">
                                <button class="btn btn-outline btn-small" type="submit">Pausar IA</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($selected['attendance_mode'] !== 'ai'): ?>
                            <form method="post" action="<?= View::e(Router::url('/conversations/mode')) ?>">
                                <?= Csrf::input() ?><input type="hidden" name="conversation_id" value="<?= (int) $selected['id'] ?>"><input type="hidden" name="mode" value="ai">
                                <button class="btn btn-outline btn-small" type="submit">Devolver para IA</button>
                            </form>
                        <?php endif; ?>
                        <form method="post" action="<?= View::e(Router::url('/conversations/suggest')) ?>">
                            <?= Csrf::input() ?><input type="hidden" name="conversation_id" value="<?= (int) $selected['id'] ?>">
                            <button class="btn btn-outline btn-small" type="submit">Gerar sugestão</button>
                        </form>
                        <form method="post" action="<?= View::e(Router::url('/conversations/reprocess-ai')) ?>">
                            <?= Csrf::input() ?><input type="hidden" name="conversation_id" value="<?= (int) $selected['id'] ?>">
                            <button class="btn btn-ghost btn-small" type="submit">Reprocessar IA</button>
                        </form>
                    </div>
                <?php endif; ?>
            </header>

            <div class="chat-state-bar">
                <span class="badge badge-<?= View::e($selected['status']) ?>"><?= View::e($statusLabel[$selected['status']] ?? $selected['status']) ?></span>
                <span class="mini-badge mode-<?= View::e($selected['attendance_mode']) ?>"><?= View::e($modeLabel[$selected['attendance_mode']] ?? $selected['attendance_mode']) ?></span>
                <?php if ($selected['assigned_user_name']): ?><small>Responsável: <strong><?= View::e($selected['assigned_user_name']) ?></strong></small><?php endif; ?>
                <?php if (Auth::isSuperAdmin()): ?><small>Empresa: <strong><?= View::e($selected['tenant_name']) ?></strong></small><?php endif; ?>
                <a class="refresh-chat" href="<?= View::e(Router::url('/conversations?conversation_id=' . (int) $selected['id'])) ?>">Atualizar</a>
            </div>

            <?php if (!empty($selected['last_ai_suggestion'])): ?>
                <div class="ai-suggestion-card">
                    <div>
                        <span class="eyebrow">Sugestão da IA</span>
                        <p><?= nl2br(View::e($selected['last_ai_suggestion'])) ?></p>
                        <?php if (!empty($selected['last_ai_suggestion_at'])): ?><small>Gerada em <?= View::e($formatDate($selected['last_ai_suggestion_at'])) ?></small><?php endif; ?>
                    </div>
                    <?php if ($canManage): ?>
                        <form method="post" action="<?= View::e(Router::url('/conversations/send')) ?>">
                            <?= Csrf::input() ?>
                            <input type="hidden" name="conversation_id" value="<?= (int) $selected['id'] ?>">
                            <input type="hidden" name="message" value="<?= View::e($selected['last_ai_suggestion']) ?>">
                            <button class="btn btn-primary btn-small" type="submit">Enviar sugestão</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="chat-thread" data-chat-thread>
                <?php foreach ($messages as $message): ?>
                    <?php $outgoing = $message['direction'] === 'outgoing'; ?>
                    <article class="message-row <?= $outgoing ? 'is-outgoing' : 'is-incoming' ?>">
                        <div class="message-bubble <?= $message['status'] === 'failed' ? 'has-error' : '' ?>" data-sender="<?= View::e($message['sender_type']) ?>">
                            <?php if ($message['message_type'] !== 'text'): ?><span class="message-type"><?= View::e(ucfirst($message['message_type'])) ?></span><?php endif; ?>
                            <p><?= nl2br(View::e($message['content'] ?: '[Sem conteúdo]')) ?></p>
                            <?php if ($message['error_message']): ?><small class="message-error"><?= View::e($message['error_message']) ?></small><?php endif; ?>
                            <footer>
                                <?php if ($outgoing): ?><span><?= View::e($message['sender_type'] === 'ai' ? 'IA' : ($message['sender_user_name'] ?: 'Equipe')) ?></span><?php endif; ?>
                                <time><?= View::e($formatDate($message['sent_at'], 'd/m H:i')) ?></time>
                                <?php if ($outgoing): ?><span class="message-status"><?= View::e($message['status']) ?></span><?php endif; ?>
                            </footer>
                        </div>
                    </article>
                <?php endforeach; ?>
                <?php if (!$messages): ?>
                    <div class="chat-empty"><span class="empty-symbol"></span><strong>Histórico vazio</strong><p>As novas mensagens recebidas pelo webhook aparecerão aqui.</p></div>
                <?php endif; ?>
            </div>

            <?php if ($canManage): ?>
                <form class="chat-composer" method="post" action="<?= View::e(Router::url('/conversations/send')) ?>">
                    <?= Csrf::input() ?>
                    <input type="hidden" name="conversation_id" value="<?= (int) $selected['id'] ?>">
                    <textarea name="message" rows="2" maxlength="4000" placeholder="Digite uma mensagem..." required></textarea>
                    <button class="btn btn-primary" type="submit">Enviar</button>
                </form>
            <?php endif; ?>
        <?php else: ?>
            <div class="chat-empty workspace-empty"><span class="empty-symbol"></span><strong>Selecione uma conversa</strong><p>O histórico e as ações de atendimento aparecerão nesta área.</p></div>
        <?php endif; ?>
    </section>

    <aside class="conversation-details card" id="conversation-details" aria-label="Informações do lead">
        <?php if ($selected): ?>
            <div class="conversation-panel-heading">
                <div><span class="eyebrow">Lead</span><h2>Informações</h2></div>
                <button class="icon-button drawer-close" type="button" data-close-panel="conversation-details" aria-label="Fechar informações">×</button>
            </div>

            <div class="lead-summary">
                <span class="conversation-avatar large"><?= View::e(mb_strtoupper(mb_substr($selected['contact_name'] ?: $selected['phone'], 0, 1))) ?></span>
                <strong><?= View::e($selected['contact_name'] ?: 'Contato sem nome') ?></strong>
                <span><?= View::e($selected['phone']) ?></span>
            </div>

            <?php
            $tags = json_decode((string) ($selected['tags_json'] ?? ''), true);
            $tagText = is_array($tags) ? implode(', ', $tags) : '';
            $interestLabel = $selected['ai_interest_level'] ?? '';
            ?>
            <div class="crm-auto-card">
                <span class="eyebrow">CRM automático</span>
                <?php if (!empty($selected['lead_id'])): ?>
                    <strong><?= View::e($selected['lead_title'] ?: 'Lead do WhatsApp') ?></strong>
                    <span>Etapa: <?= View::e($selected['lead_stage_name'] ?: '—') ?></span>
                    <span>Prioridade: <?= View::e($selected['lead_priority'] ?: '—') ?></span>
                    <?php if ($interestLabel !== ''): ?><span>Interesse: <?= View::e($interestLabel) ?></span><?php endif; ?>
                    <?php if (!empty($selected['ai_next_action'])): ?><p><?= View::e($selected['ai_next_action']) ?></p><?php endif; ?>
                    <a class="btn btn-outline btn-small btn-block" href="<?= View::e(Router::url('/crm?tenant_id=' . (int) $selected['tenant_id'] . '&pipeline_id=' . (int) $selected['lead_pipeline_id'] . '&lead_id=' . (int) $selected['lead_id'])) ?>">Abrir no CRM</a>
                <?php else: ?>
                    <strong>Nenhum negócio vinculado</strong>
                    <span>Novas mensagens recebidas pelo WhatsApp criam uma oportunidade automaticamente.</span>
                <?php endif; ?>
            </div>

            <form class="lead-form" method="post" action="<?= View::e(Router::url('/conversations/contact')) ?>">
                <?= Csrf::input() ?>
                <input type="hidden" name="conversation_id" value="<?= (int) $selected['id'] ?>">
                <label class="field"><span>Nome</span><input name="name" value="<?= View::e($selected['contact_name']) ?>" <?= !$canManage ? 'readonly' : '' ?>></label>
                <label class="field"><span>E-mail</span><input type="email" name="email" value="<?= View::e($selected['email']) ?>" <?= !$canManage ? 'readonly' : '' ?>></label>
                <label class="field"><span>Empresa</span><input name="company" value="<?= View::e($selected['company']) ?>" <?= !$canManage ? 'readonly' : '' ?>></label>
                <label class="field"><span>Classificação</span>
                    <select name="contact_status" <?= !$canManage ? 'disabled' : '' ?>>
                        <option value="lead" <?= $selected['contact_status'] === 'lead' ? 'selected' : '' ?>>Lead</option>
                        <option value="customer" <?= $selected['contact_status'] === 'customer' ? 'selected' : '' ?>>Cliente</option>
                        <option value="inactive" <?= $selected['contact_status'] === 'inactive' ? 'selected' : '' ?>>Inativo</option>
                    </select>
                </label>
                <label class="field"><span>Tags separadas por vírgula</span><input name="tags" value="<?= View::e($tagText) ?>" <?= !$canManage ? 'readonly' : '' ?>></label>
                <label class="field"><span>Notas</span><textarea name="notes" rows="5" <?= !$canManage ? 'readonly' : '' ?>><?= View::e($selected['notes']) ?></textarea></label>
                <?php if ($canManage): ?><button class="btn btn-secondary btn-block" type="submit">Salvar contato</button><?php endif; ?>
            </form>

            <?php if ($canManage): ?>
                <div class="conversation-status-actions">
                    <span class="field-label">Status da conversa</span>
                    <div class="status-button-grid">
                        <?php foreach ($statusLabel as $value => $label): ?>
                            <form method="post" action="<?= View::e(Router::url('/conversations/status')) ?>">
                                <?= Csrf::input() ?><input type="hidden" name="conversation_id" value="<?= (int) $selected['id'] ?>"><input type="hidden" name="status" value="<?= View::e($value) ?>">
                                <button class="btn btn-small <?= $selected['status'] === $value ? 'btn-primary' : 'btn-ghost' ?>" type="submit"><?= View::e($label) ?></button>
                            </form>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="empty-state">Selecione uma conversa para visualizar o contato.</div>
        <?php endif; ?>
    </aside>
</div>
