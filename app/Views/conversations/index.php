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
$contactLabel = static function (array $row): string {
    $name = trim((string) ($row['contact_name'] ?? ''));
    $phone = trim((string) ($row['phone'] ?? ''));
    return $name !== '' ? $name : ($phone !== '' ? $phone : 'Contato sem identificação');
};
$contactInitial = static function (array $row) use ($contactLabel): string {
    $label = $contactLabel($row);
    $initial = mb_substr($label, 0, 1);
    return $initial !== '' ? mb_strtoupper($initial) : '?';
};
$currentQuery = array_filter([
    'search' => $filters['search'] ?? '',
    'status' => $filters['status'] ?? '',
    'mode' => $filters['mode'] ?? '',
    'instance_id' => $filters['instance_id'] ?? 0,
    'tenant_id' => $filters['tenant_id'] ?? 0,
    'intent' => $filters['intent'] ?? '',
], static fn ($value) => $value !== '' && $value !== 0 && $value !== 'tenant');
$lastMessageId = 0;
foreach ($messages as $message) {
    $lastMessageId = max($lastMessageId, (int) ($message['id'] ?? 0));
}
$pollQuery = $currentQuery;
if ($selected) {
    $pollQuery['conversation_id'] = (int) $selected['id'];
}
?>

<form class="conversation-filters card" method="get" action="<?= View::e(Router::url('/conversations')) ?>">
    <?php if (($filters['intent'] ?? '') === 'agenda'): ?><input type="hidden" name="intent" value="agenda"><?php endif; ?>
    <div class="filter-search">
        <span class="search-icon" aria-hidden="true"></span>
        <input name="search" value="<?= View::e($filters['search'] ?? '') ?>" placeholder="Buscar por nome, telefone ou mensagem">
    </div>

    <?php if (Auth::isSuperAdmin()): ?>
        <select name="tenant_id" aria-label="Filtrar por empresa">
            <option value="">Selecione uma empresa</option>
            <?php foreach ($tenants as $tenant): ?>
                <option value="<?= (int) $tenant['id'] ?>" <?= (int) ($filters['tenant_id'] ?? 0) === (int) $tenant['id'] ? 'selected' : '' ?>><?= View::e($tenant['name']) ?></option>
            <?php endforeach; ?>
        </select>
    <?php endif; ?>

    <select name="instance_id" aria-label="Filtrar por instância">
        <option value="">Todas as instâncias</option>
        <?php foreach ($instances as $instance): ?>
            <?php
            if (Auth::isSuperAdmin() && (int) ($filters['tenant_id'] ?? 0) < 1) continue;
            if (Auth::isSuperAdmin() && (int) $instance['tenant_id'] !== (int) ($filters['tenant_id'] ?? 0)) continue;
            ?>
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

    <?php if (($filters['intent'] ?? '') === 'agenda'): ?><span class="badge badge-info">Intenção de agenda</span><?php endif; ?>
    <button class="btn btn-secondary" type="submit">Filtrar</button>
    <a class="btn btn-outline" href="<?= View::e(Router::url('/conversations')) ?>">Limpar</a>
</form>

<div class="conversation-workspace" data-conversation-realtime data-poll-url="<?= View::e(Router::url('/conversations/poll')) ?>" data-current-query="<?= View::e(http_build_query($pollQuery)) ?>" data-conversation-id="<?= (int) ($selected['id'] ?? 0) ?>" data-last-message-id="<?= (int) $lastMessageId ?>" data-base-title="<?= View::e($title ?? 'Conversas') ?>">
    <div class="realtime-toast" data-realtime-toast hidden></div>
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
                                        <?php
                                        if (Auth::isSuperAdmin() && (int) ($filters['tenant_id'] ?? 0) < 1) continue;
                                        if (Auth::isSuperAdmin() && (int) $instance['tenant_id'] !== (int) ($filters['tenant_id'] ?? 0)) continue;
                                        ?>
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

        <div class="conversation-list" data-conversation-list>
            <?php foreach ($conversations as $conversation): ?>
                <?php
                $query = $currentQuery;
                $query['conversation_id'] = (int) $conversation['id'];
                $isSelected = (int) ($selected['id'] ?? 0) === (int) $conversation['id'];
                $displayName = $contactLabel($conversation);
                $initial = $contactInitial($conversation);
                ?>
                <a class="conversation-list-item<?= $isSelected ? ' is-selected' : '' ?>" data-conversation-item data-conversation-id="<?= (int) $conversation['id'] ?>" href="<?= View::e(Router::url('/conversations?' . http_build_query($query))) ?>">
                    <span class="conversation-avatar"><?= View::e($initial) ?></span>
                    <span class="conversation-summary">
                        <span class="conversation-title-row">
                            <strong data-conversation-name><?= View::e($displayName) ?></strong>
                            <time data-conversation-time><?= View::e($formatDate($conversation['last_message_at'], 'd/m H:i')) ?></time>
                        </span>
                        <span class="conversation-preview" data-conversation-preview><?= View::e($conversation['last_message_preview'] ?: 'Sem mensagens') ?></span>
                        <span class="conversation-meta-row">
                            <span class="mini-badge mode-<?= View::e($conversation['attendance_mode']) ?>"><?= View::e($modeLabel[$conversation['attendance_mode']] ?? $conversation['attendance_mode']) ?></span>
                            <?php if (Auth::isSuperAdmin()): ?><small><?= View::e($conversation['tenant_name']) ?></small><?php endif; ?>
                            <b class="unread-count" data-unread-count <?= (int) $conversation['unread_count'] > 0 ? '' : 'hidden' ?>><?= (int) $conversation['unread_count'] ?></b>
                        </span>
                    </span>
                </a>
            <?php endforeach; ?>
            <?php if (!$conversations): ?>
                <div class="empty-state conversation-empty">
                    <?php if (Auth::isSuperAdmin() && (int) ($filters['tenant_id'] ?? 0) < 1): ?>
                        <strong>Selecione uma empresa.</strong>
                        <span>Por segurança, o Super Admin não carrega conversas de todos os clientes automaticamente.</span>
                    <?php else: ?>
                        <strong>Nenhuma conversa encontrada.</strong>
                        <span>Configure o webhook da Evolution para receber mensagens.</span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </aside>

    <section class="conversation-chat card">
        <?php if ($selected): ?>
            <header class="chat-header">
                <div class="chat-contact-title">
                    <span class="conversation-avatar large"><?= View::e($contactInitial($selected)) ?></span>
                    <div>
                        <h2><?= View::e($contactLabel($selected)) ?></h2>
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
                <span class="realtime-status" data-realtime-status>Atualização automática ativa</span>
                <?php $refreshQuery = $currentQuery; $refreshQuery['conversation_id'] = (int) $selected['id']; ?>
                <a class="refresh-chat" href="<?= View::e(Router::url('/conversations?' . http_build_query($refreshQuery))) ?>">Atualizar</a>
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

            <div class="chat-thread" data-chat-thread data-last-message-id="<?= (int) $lastMessageId ?>">
                <?php foreach ($messages as $message): ?>
                    <?php $outgoing = $message['direction'] === 'outgoing'; ?>
                    <article class="message-row <?= $outgoing ? 'is-outgoing' : 'is-incoming' ?>" data-message-id="<?= (int) $message['id'] ?>">
                        <div class="message-bubble <?= $message['status'] === 'failed' ? 'has-error' : '' ?>" data-sender="<?= View::e($message['sender_type']) ?>">
                            <?php if ($message['message_type'] !== 'text'): ?><span class="message-type"><?= View::e(ucfirst($message['message_type'])) ?></span><?php endif; ?>
                            <p><?= nl2br(View::e($message['content'] ?: '[Sem conteúdo]')) ?></p>
                            <?php if (!empty($message['error_message'])): ?><small class="message-error"><?= View::e($message['error_message']) ?></small><?php endif; ?>
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

    <aside class="conversation-details conversation-drawer card" id="conversation-details" aria-label="Dados do atendimento">
        <?php if ($selected): ?>
            <?php
            $tags = json_decode((string) ($selected['tags_json'] ?? ''), true);
            $tagText = is_array($tags) ? implode(', ', $tags) : '';
            $interestLabel = $selected['ai_interest_level'] ?? '';
            ?>
            <div class="conversation-drawer-header">
                <div>
                    <span class="eyebrow">Atendimento</span>
                    <h2>Dados da conversa</h2>
                    <p><?= View::e($selected['contact_name'] ?: 'Contato sem nome') ?> · <?= View::e($selected['phone']) ?></p>
                </div>
                <button class="icon-button drawer-close" type="button" data-close-panel="conversation-details" aria-label="Fechar painel">×</button>
            </div>

            <div class="conversation-drawer-body">
                <?php if ($canManage): ?>
                    <section class="drawer-section drawer-status-card">
                        <div class="drawer-section-title">
                            <div>
                                <span class="eyebrow">Status</span>
                                <h3>Controle da conversa</h3>
                            </div>
                            <span class="mini-badge mode-<?= View::e($selected['attendance_mode']) ?>"><?= View::e($modeLabel[$selected['attendance_mode']] ?? $selected['attendance_mode']) ?></span>
                        </div>
                        <div class="status-button-grid pro-status-grid">
                            <?php foreach ($statusLabel as $value => $label): ?>
                                <form method="post" action="<?= View::e(Router::url('/conversations/status')) ?>">
                                    <?= Csrf::input() ?><input type="hidden" name="conversation_id" value="<?= (int) $selected['id'] ?>"><input type="hidden" name="status" value="<?= View::e($value) ?>">
                                    <button class="btn btn-small <?= $selected['status'] === $value ? 'btn-primary' : 'btn-outline' ?>" type="submit"><?= View::e($label) ?></button>
                                </form>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endif; ?>

                <form class="lead-form drawer-form" method="post" action="<?= View::e(Router::url('/conversations/contact')) ?>">
                    <?= Csrf::input() ?>
                    <input type="hidden" name="conversation_id" value="<?= (int) $selected['id'] ?>">

                    <section class="drawer-section">
                        <div class="drawer-section-title">
                            <div>
                                <span class="eyebrow">Contato</span>
                                <h3>Informações principais</h3>
                            </div>
                        </div>
                        <div class="drawer-form-grid">
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
                        </div>
                        <label class="field drawer-span"><span>Tags separadas por vírgula</span><input name="tags" value="<?= View::e($tagText) ?>" <?= !$canManage ? 'readonly' : '' ?>></label>
                        <label class="field drawer-span"><span>Notas internas</span><textarea name="notes" rows="7" <?= !$canManage ? 'readonly' : '' ?>><?= View::e($selected['notes']) ?></textarea></label>
                    </section>

                    <?php if ($canManage): ?>
                        <div class="drawer-savebar">
                            <button class="btn btn-primary btn-block" type="submit">Salvar alterações</button>
                        </div>
                    <?php endif; ?>
                </form>

                <details class="drawer-section drawer-collapsed-card">
                    <summary>
                        <span>
                            <span class="eyebrow">CRM automático</span>
                            <strong><?= !empty($selected['lead_id']) ? View::e($selected['lead_title'] ?: 'Lead do WhatsApp') : 'Nenhum negócio vinculado' ?></strong>
                        </span>
                        <span class="drawer-chevron"></span>
                    </summary>
                    <div class="drawer-crm-content">
                        <?php if (!empty($selected['lead_id'])): ?>
                            <span>Etapa: <?= View::e($selected['lead_stage_name'] ?: '—') ?></span>
                            <span>Prioridade: <?= View::e($selected['lead_priority'] ?: '—') ?></span>
                            <?php if ($interestLabel !== ''): ?><span>Interesse: <?= View::e($interestLabel) ?></span><?php endif; ?>
                            <?php if (!empty($selected['ai_next_action'])): ?><p><?= View::e($selected['ai_next_action']) ?></p><?php endif; ?>
                            <a class="btn btn-outline btn-small btn-block" href="<?= View::e(Router::url('/crm?tenant_id=' . (int) $selected['tenant_id'] . '&pipeline_id=' . (int) $selected['lead_pipeline_id'] . '&lead_id=' . (int) $selected['lead_id'])) ?>">Abrir no CRM</a>
                        <?php else: ?>
                            <p>Novas mensagens recebidas pelo WhatsApp criam uma oportunidade automaticamente.</p>
                        <?php endif; ?>
                    </div>
                </details>
            </div>
        <?php else: ?>
            <div class="empty-state">Selecione uma conversa para visualizar o contato.</div>
        <?php endif; ?>
    </aside>
</div>
