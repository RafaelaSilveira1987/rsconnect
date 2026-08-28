<?php

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Router;
use App\Core\PublicId;
use App\Core\View;

$canManage = Auth::can('conversations.manage');
$professionalAssignmentSettings = is_array($professionalAssignmentSettings ?? null) ? $professionalAssignmentSettings : ['enabled' => false, 'lock_enabled' => true, 'auto_assign_enabled' => false];
$ownershipSnapshot = is_array($ownershipSnapshot ?? null) ? $ownershipSnapshot : ['enabled' => false, 'can_interact' => true, 'locked_by_other' => false];
$canOperateSelected = $canManage && !empty($ownershipSnapshot['can_interact']);
$conversationAgents = is_array($conversationAgents ?? null) ? $conversationAgents : [];
$commercialRequestSettings = is_array($commercialRequestSettings ?? null) ? $commercialRequestSettings : ['ready' => false, 'enabled' => false, 'show_conversation_alert' => false];
$selectedCommercialRequest = is_array($selectedCommercialRequest ?? null) ? $selectedCommercialRequest : null;
$formatDate = static function (?string $date, string $format = 'd/m/Y H:i'): string {
    if (!$date) {
        return '—';
    }
    return \App\Core\Clock::formatUtc($date, $format);
};
$modeLabel = ['ai' => 'IA ativa', 'human' => 'Humano', 'paused' => 'IA pausada'];
$statusLabel = ['open' => 'Aberta', 'pending' => 'Pendente', 'closed' => 'Encerrada'];
$afterHoursStatusLabels = [
    'pending' => 'Aguardando horário',
    'processing' => 'Retomando agora',
    'blocked_plan' => 'Aguardando franquia',
    'blocked_human' => 'Aguardando equipe',
    'error' => 'Nova tentativa programada',
];
$afterHoursStatusClasses = [
    'pending' => 'is-waiting',
    'processing' => 'is-processing',
    'blocked_plan' => 'is-blocked',
    'blocked_human' => 'is-human',
    'error' => 'is-error',
];
$normalizeStatus = static fn (?string $status): string => in_array($status, ['open', 'pending', 'closed'], true) ? (string) $status : 'open';
$contactGroupLabels = \App\Services\ConversationFlowService::GROUPS;
$flowStageLabels = \App\Services\ConversationFlowService::STAGES;
$demandStatusLabels = \App\Services\ConversationFlowService::DEMAND_STATUSES;
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
$contactAvatarUrl = static function (array $row): string {
    $url = trim((string) ($row['avatar_url'] ?? ''));
    return $url !== '' && preg_match('#^https?://#i', $url) ? $url : '';
};
$currentQuery = array_filter([
    'search' => $filters['search'] ?? '',
    'status' => $filters['status'] ?? '',
    'mode' => $filters['mode'] ?? '',
    'instance_id' => $filters['instance_id'] ?? 0,
    'tenant_id' => $filters['tenant_id'] ?? 0,
    'intent' => $filters['intent'] ?? '',
    'queue' => $filters['queue'] ?? '',
], static fn ($value) => $value !== '' && $value !== 0 && $value !== 'tenant');
$lastMessageId = 0;
foreach ($messages as $message) {
    $lastMessageId = max($lastMessageId, (int) ($message['id'] ?? 0));
}
$pollQuery = $currentQuery;
if ($selected) {
    $pollQuery['conversation_id'] = (int) $selected['id'];
}
$returnQuery = http_build_query($pollQuery);
$publicPollQuery = (string) (parse_url(Router::url('/conversations?' . http_build_query($pollQuery)), PHP_URL_QUERY) ?? '');
$selectedConversationPublicId = $selected ? PublicId::encode('conversation', (int) $selected['id']) : '';
$afterHoursQueueCount = count(array_filter($conversations, static fn (array $conversation): bool => trim((string) ($conversation['after_hours_status'] ?? '')) !== ''));
$quotePendingQueueCount = count(array_filter($conversations, static fn (array $conversation): bool => (int) ($conversation['commercial_request_id'] ?? 0) > 0));
?>

<form class="conversation-filters card" method="get" action="<?= View::e(Router::url('/conversations')) ?>">
    <?php if (($filters['intent'] ?? '') === 'agenda'): ?><input type="hidden" name="intent" value="agenda"><?php endif; ?>
    <div class="filter-search">
        <span class="search-icon" aria-hidden="true"></span>
        <input name="search" data-conversation-search autocomplete="off" value="<?= View::e($filters['search'] ?? '') ?>" placeholder="Buscar por nome, telefone ou mensagem">
    </div>

    <?php if (Auth::isSuperAdmin()): ?>
        <select name="tenant_id" aria-label="Filtrar por empresa">
            <option value="">Selecione uma empresa</option>
            <?php foreach ($tenants as $tenant): ?>
                <option value="<?= (int) $tenant['id'] ?>" <?= (int) ($filters['tenant_id'] ?? 0) === (int) $tenant['id'] ? 'selected' : '' ?>><?= View::e($tenant['name']) ?></option>
            <?php endforeach; ?>
        </select>
    <?php endif; ?>

    <select name="instance_id" aria-label="Filtrar por conexão do WhatsApp">
        <option value="">Todas as conexões</option>
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

    <select name="queue" aria-label="Filtrar por fila operacional">
        <option value="">Todas as filas</option>
        <option value="after_hours" <?= ($filters['queue'] ?? '') === 'after_hours' ? 'selected' : '' ?>>Aguardando horário<?= $afterHoursQueueCount > 0 ? ' (' . $afterHoursQueueCount . ')' : '' ?></option>
        <option value="quote_pending" <?= ($filters['queue'] ?? '') === 'quote_pending' ? 'selected' : '' ?>>Orçamentos pendentes<?= $quotePendingQueueCount > 0 ? ' (' . $quotePendingQueueCount . ')' : '' ?></option>
    </select>

    <?php if (($filters['intent'] ?? '') === 'agenda'): ?><span class="badge badge-info">Intenção de agenda</span><?php endif; ?>
    <button class="btn btn-secondary" type="submit">Filtrar</button>
    <a class="btn btn-outline" href="<?= View::e(Router::url('/conversations')) ?>">Limpar</a>
</form>

<div class="conversation-workspace" data-conversation-realtime data-poll-url="<?= View::e(Router::url('/conversations/poll')) ?>" data-avatar-url="<?= View::e(Router::url('/conversations/avatar')) ?>" data-current-query="<?= View::e($publicPollQuery) ?>" data-conversation-id="<?= (int) ($selected['id'] ?? 0) ?>" data-conversation-public-id="<?= View::e($selectedConversationPublicId) ?>" data-last-message-id="<?= (int) $lastMessageId ?>" data-base-title="<?= View::e($title ?? 'Conversas') ?>">
    <div class="realtime-toast" data-realtime-toast hidden></div>
    <?php if ($canManage): ?>
        <div class="new-conversation-shell" data-new-conversation-shell hidden>
            <button class="new-conversation-backdrop" type="button" data-new-conversation-close aria-label="Fechar novo atendimento"></button>
            <section class="new-conversation-drawer" id="new-conversation-drawer" role="dialog" aria-modal="true" aria-labelledby="new-conversation-title">
                <form class="new-conversation-form" method="post" action="<?= View::e(Router::url('/conversations/start')) ?>" data-new-conversation-form data-contact-lookup-url="<?= View::e(Router::url('/conversations/contact-lookup')) ?>">
                    <?= Csrf::input() ?>
                    <div class="new-conversation-form-head">
                        <div>
                            <span><span class="eyebrow">Novo atendimento</span><strong id="new-conversation-title">Iniciar conversa</strong></span>
                            <button class="drawer-close new-conversation-close" type="button" data-new-conversation-close aria-label="Fechar novo atendimento" title="Fechar">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg>
                            </button>
                        </div>
                        <small>Pesquise um contato já cadastrado ou informe um novo número. A Caixa de Entrada permanece no mesmo ponto enquanto você inicia o atendimento.</small>
                    </div>
                    <div class="new-conversation-form-body">
                        <label class="field"><span>Conexão de WhatsApp</span>
                            <select name="instance_id" required data-new-conversation-instance>
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
                        <label class="field new-conversation-contact-search"><span>Buscar contato</span><input type="search" autocomplete="off" placeholder="Nome ou telefone" data-new-conversation-search><small class="field-hint-inline">A busca consulta os contatos da empresa antes de iniciar um novo atendimento.</small></label>
                        <div class="new-conversation-search-results" data-new-conversation-results hidden></div>
                        <div class="new-conversation-field-grid">
                            <label class="field"><span>Telefone com DDI</span><input name="phone" inputmode="tel" autocomplete="off" placeholder="5511999999999" required data-new-conversation-phone></label>
                            <label class="field"><span>Nome do contato</span><input name="name" autocomplete="off" placeholder="Opcional" data-new-conversation-name></label>
                        </div>
                        <div class="new-conversation-existing" data-new-conversation-existing hidden></div>
                        <label class="field"><span>Primeira mensagem</span><textarea name="message" rows="4" required>Olá! Como podemos ajudar?</textarea></label>
                    </div>
                    <div class="new-conversation-actions">
                        <button class="btn btn-outline" type="button" data-new-conversation-close>Cancelar</button>
                        <button class="btn btn-primary" type="submit" <?= !$instances ? 'disabled' : '' ?>>Enviar e abrir conversa</button>
                    </div>
                </form>
            </section>
        </div>
    <?php endif; ?>
    <aside class="conversation-inbox card">
        <div class="conversation-panel-heading">
            <div>
                <span class="eyebrow">Caixa de entrada</span>
                <h2>Conversas</h2>
            </div>
            <div class="conversation-heading-actions">
                <?php $afterHoursFilterQuery = $currentQuery; $afterHoursFilterQuery['queue'] = 'after_hours'; unset($afterHoursFilterQuery['conversation_id']); ?>
                <a class="conversation-queue-filter<?= ($filters['queue'] ?? '') === 'after_hours' ? ' is-active' : '' ?>" data-after-hours-queue-count href="<?= View::e(Router::url('/conversations?' . http_build_query($afterHoursFilterQuery))) ?>" title="Mostrar somente mensagens aguardando o próximo horário" <?= $afterHoursQueueCount > 0 ? '' : 'hidden' ?>>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                    <span><?= (int) $afterHoursQueueCount ?></span>
                </a>
                <?php $quoteFilterQuery = $currentQuery; $quoteFilterQuery['queue'] = 'quote_pending'; unset($quoteFilterQuery['conversation_id']); ?>
                <a class="conversation-queue-filter is-quote<?= ($filters['queue'] ?? '') === 'quote_pending' ? ' is-active' : '' ?>" data-quote-pending-count href="<?= View::e(Router::url('/conversations?' . http_build_query($quoteFilterQuery))) ?>" title="Mostrar somente solicitações de orçamento pendentes" <?= $quotePendingQueueCount > 0 ? '' : 'hidden' ?>>
                    <span class="quote-pending-icon" aria-hidden="true">$</span>
                    <span><?= (int) $quotePendingQueueCount ?></span>
                </a>
                <span class="badge" data-conversation-count><?= count($conversations) ?></span>
                <?php if ($canManage && $conversations): ?>
                    <button class="btn btn-outline btn-small conversation-select-toggle" type="button" data-toggle-bulk-read aria-expanded="false" aria-controls="conversation-bulk-read-form">
                        <svg class="button-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="4" width="6" height="6" rx="1"/><rect x="14" y="4" width="6" height="6" rx="1"/><rect x="4" y="14" width="6" height="6" rx="1"/><path d="m14.5 17 2 2 4-5"/></svg>
                        <span data-bulk-toggle-label>Selecionar</span>
                    </button>
                <?php endif; ?>
                <?php if ($canManage): ?>
                    <button class="btn btn-primary btn-small" type="button" data-new-conversation-open aria-haspopup="dialog" aria-controls="new-conversation-drawer">+ Nova</button>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($canManage && $conversations): ?>
            <form id="conversation-bulk-read-form" class="conversation-bulk-toolbar" method="post" action="<?= View::e(Router::url('/conversations/mark-read')) ?>" data-bulk-read-form hidden>
                <?= Csrf::input() ?>
                <input type="hidden" name="tenant_id" value="<?= (int) ($filters['tenant_id'] ?? Auth::tenantId() ?? 0) ?>">
                <input type="hidden" name="return_query" value="<?= View::e($returnQuery) ?>">

                <div class="conversation-bulk-summary">
                    <label class="conversation-select-all">
                        <input type="checkbox" data-select-all-conversations>
                        <span class="conversation-checkbox-ui" aria-hidden="true"></span>
                        <span>Selecionar todas</span>
                    </label>
                    <span class="conversation-selection-count" data-selection-count role="status" aria-live="polite">0 selecionadas</span>
                </div>

                <div class="conversation-bulk-actions">
                    <button class="btn btn-primary btn-small conversation-bulk-action" type="submit" data-mark-read-button disabled>
                        <svg class="button-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m4 12 5 5L20 6"/></svg>
                        <span class="label-full">Marcar como lidas</span><span class="label-compact">Marcar lidas</span>
                    </button>
                    <button class="btn btn-danger btn-small conversation-bulk-action" type="submit" formaction="<?= View::e(Router::url('/conversations/delete')) ?>" data-delete-conversations-button disabled>
                        <svg class="button-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16M9 7V4h6v3M7 7l1 13h8l1-13M10 11v5M14 11v5"/></svg>
                        <span class="label-full">Excluir selecionadas</span><span class="label-compact">Excluir</span>
                    </button>
                    <button class="conversation-bulk-cancel" type="button" data-cancel-bulk-select aria-label="Cancelar seleção" title="Cancelar seleção">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg>
                    </button>
                </div>
                <small class="conversation-bulk-help">As ações serão aplicadas somente às conversas selecionadas.</small>
            </form>
        <?php endif; ?>

        <div class="conversation-list" data-conversation-list>
            <?php foreach ($conversations as $conversation): ?>
                <?php
                $query = $currentQuery;
                $query['conversation_id'] = (int) $conversation['id'];
                $isSelected = (int) ($selected['id'] ?? 0) === (int) $conversation['id'];
                $displayName = $contactLabel($conversation);
                $initial = $contactInitial($conversation);
                $avatarUrl = $contactAvatarUrl($conversation);
                $conversationPublicId = PublicId::encode('conversation', (int) $conversation['id']);
                $conversationStatus = $normalizeStatus((string) ($conversation['status'] ?? 'open'));
                $afterHoursStatus = trim((string) ($conversation['after_hours_status'] ?? ''));
                $afterHoursCount = max(1, (int) ($conversation['after_hours_message_count'] ?? 0));
                $afterHoursClass = $afterHoursStatusClasses[$afterHoursStatus] ?? 'is-waiting';
                $afterHoursLabel = $afterHoursStatusLabels[$afterHoursStatus] ?? 'Aguardando horário';
                $hasQuotePending = (int) ($conversation['commercial_request_id'] ?? 0) > 0;
                $quoteDueAt = trim((string) ($conversation['commercial_request_due_at'] ?? ''));
                ?>
                <div class="conversation-list-row status-<?= View::e($conversationStatus) ?><?= (int) $conversation['unread_count'] > 0 ? ' has-unread' : '' ?><?= $afterHoursStatus !== '' ? ' has-after-hours-queue' : '' ?><?= $hasQuotePending ? ' has-quote-pending' : '' ?>" data-conversation-row data-conversation-id="<?= (int) $conversation['id'] ?>" data-conversation-public-id="<?= View::e($conversationPublicId) ?>" data-conversation-status="<?= View::e($conversationStatus) ?>" data-after-hours-status="<?= View::e($afterHoursStatus) ?>">
                    <?php if ($canManage): ?>
                        <label class="conversation-select-control" title="Selecionar <?= View::e($displayName) ?>">
                            <input type="checkbox" name="conversation_ids[]" value="<?= (int) $conversation['id'] ?>" form="conversation-bulk-read-form" data-conversation-select aria-label="Selecionar conversa de <?= View::e($displayName) ?>">
                            <span aria-hidden="true"></span>
                        </label>
                    <?php endif; ?>
                    <a class="conversation-list-item<?= $isSelected ? ' is-selected' : '' ?>" data-conversation-item data-conversation-id="<?= (int) $conversation['id'] ?>" data-conversation-public-id="<?= View::e($conversationPublicId) ?>" href="<?= View::e(Router::url('/conversations?' . http_build_query($query))) ?>">
                    <span class="conversation-avatar" data-contact-avatar-container data-avatar-resolved="<?= array_key_exists('avatar_url', $conversation) && $conversation['avatar_url'] !== null ? '1' : '0' ?>">
                        <span class="conversation-avatar-fallback" data-avatar-fallback><?= View::e($initial) ?></span>
                        <?php if ($avatarUrl !== ''): ?><img class="conversation-avatar-image" data-contact-avatar src="<?= View::e($avatarUrl) ?>" alt="" loading="lazy" referrerpolicy="no-referrer"><?php endif; ?>
                    </span>
                    <span class="conversation-summary">
                        <span class="conversation-title-row">
                            <strong data-conversation-name><?= View::e($displayName) ?></strong>
                            <time data-conversation-time><?= View::e($formatDate($conversation['last_message_at'], 'd/m H:i')) ?></time>
                        </span>
                        <span class="conversation-preview" data-conversation-preview><?= View::e($conversation['last_message_preview'] ?: 'Sem mensagens') ?></span>
                        <span class="conversation-queue-slot" data-after-hours-list-slot>
                            <?php if ($afterHoursStatus !== ''): ?>
                                <span class="conversation-queue-state <?= View::e($afterHoursClass) ?>" data-after-hours-list-state>
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                                    <span><strong><?= View::e($afterHoursLabel) ?></strong><small><?= $afterHoursCount ?> <?= $afterHoursCount === 1 ? 'mensagem preservada' : 'mensagens preservadas' ?></small></span>
                                </span>
                            <?php endif; ?>
                            <?php if ($hasQuotePending): ?>
                                <span class="conversation-queue-state is-quote-pending">
                                    <span class="quote-pending-icon" aria-hidden="true">$</span>
                                    <span><strong>Orçamento pendente</strong><small><?= $quoteDueAt !== '' ? 'Retorno até ' . View::e($formatDate($quoteDueAt, 'd/m H:i')) : 'Equipe precisa retornar' ?></small></span>
                                </span>
                            <?php endif; ?>
                        </span>
                        <span class="conversation-meta-row">
                            <span class="mini-badge mode-<?= View::e($conversation['attendance_mode']) ?>"><?= View::e($modeLabel[$conversation['attendance_mode']] ?? $conversation['attendance_mode']) ?></span>
                            <span class="mini-badge conversation-status-badge status-<?= View::e($conversationStatus) ?>" data-conversation-list-status><?= View::e($statusLabel[$conversationStatus]) ?></span>
                            <?php if (Auth::isSuperAdmin()): ?><small><?= View::e($conversation['tenant_name']) ?></small><?php endif; ?>
                            <b class="unread-count" data-unread-count <?= (int) $conversation['unread_count'] > 0 ? '' : 'hidden' ?>><?= (int) $conversation['unread_count'] ?></b>
                        </span>
                    </span>
                    </a>
                </div>
            <?php endforeach; ?>
            <?php if (!$conversations): ?>
                <div class="empty-state conversation-empty">
                    <?php if (Auth::isSuperAdmin() && (int) ($filters['tenant_id'] ?? 0) < 1): ?>
                        <strong>Selecione uma empresa.</strong>
                        <span>Por segurança, o Super Admin não carrega conversas de todos os clientes automaticamente.</span>
                    <?php else: ?>
                        <strong>Nenhuma conversa encontrada.</strong>
                        <span>Ative o recebimento automático da conexão do WhatsApp para que as mensagens apareçam aqui.</span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </aside>

    <?php $selectedStatus = $selected ? $normalizeStatus((string) ($selected['status'] ?? 'open')) : ''; ?>
    <section class="conversation-chat card<?= $selectedStatus !== '' ? ' conversation-status-' . View::e($selectedStatus) : '' ?>" data-selected-conversation-panel data-conversation-status="<?= View::e($selectedStatus) ?>">
        <?php if ($selected): ?>
            <header class="chat-header">
                <div class="chat-contact-title">
                    <?php $selectedAvatarUrl = $contactAvatarUrl($selected); ?>
                    <span class="conversation-avatar large" data-contact-avatar-container data-avatar-resolved="<?= array_key_exists('avatar_url', $selected) && $selected['avatar_url'] !== null ? '1' : '0' ?>">
                        <span class="conversation-avatar-fallback" data-avatar-fallback><?= View::e($contactInitial($selected)) ?></span>
                        <?php if ($selectedAvatarUrl !== ''): ?><img class="conversation-avatar-image" data-contact-avatar src="<?= View::e($selectedAvatarUrl) ?>" alt="" referrerpolicy="no-referrer"><?php endif; ?>
                    </span>
                    <div>
                        <h2><?= View::e($contactLabel($selected)) ?></h2>
                        <p><?= View::e($selected['phone']) ?> · <?= View::e($selected['instance_label']) ?></p>
                    </div>
                </div>

                <?php if ($canOperateSelected): ?>
                    <div class="chat-actions">
                        <button class="btn btn-outline btn-small" type="button" data-toggle-panel="conversation-details">Dados do lead</button>
                        <form method="post" action="<?= View::e(Router::url('/conversations/mode')) ?>" data-mode-action="human" <?= $selected['attendance_mode'] === 'human' ? 'hidden' : '' ?>>
                            <?= Csrf::input() ?><input type="hidden" name="conversation_id" value="<?= (int) $selected['id'] ?>"><input type="hidden" name="mode" value="human">
                            <button class="btn btn-primary btn-small" type="submit">Assumir atendimento</button>
                        </form>
                        <form method="post" action="<?= View::e(Router::url('/conversations/mode')) ?>" data-mode-action="paused" <?= $selected['attendance_mode'] === 'ai' ? '' : 'hidden' ?>>
                            <?= Csrf::input() ?><input type="hidden" name="conversation_id" value="<?= (int) $selected['id'] ?>"><input type="hidden" name="mode" value="paused">
                            <button class="btn btn-outline btn-small" type="submit">Pausar IA</button>
                        </form>
                        <form method="post" action="<?= View::e(Router::url('/conversations/mode')) ?>" data-mode-action="ai" <?= $selected['attendance_mode'] !== 'ai' ? '' : 'hidden' ?>>
                            <?= Csrf::input() ?><input type="hidden" name="conversation_id" value="<?= (int) $selected['id'] ?>"><input type="hidden" name="mode" value="ai">
                            <button class="btn btn-outline btn-small" type="submit">Devolver para IA</button>
                        </form>
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
                <span class="badge badge-<?= View::e($selectedStatus) ?>" data-conversation-status-badge><?= View::e($statusLabel[$selectedStatus]) ?></span>
                <span class="mini-badge mode-<?= View::e($selected['attendance_mode']) ?>"><?= View::e($modeLabel[$selected['attendance_mode']] ?? $selected['attendance_mode']) ?></span>
                <?php if ($selected['assigned_user_name']): ?><small>Responsável: <strong><?= View::e($selected['assigned_user_name']) ?></strong></small><?php endif; ?>
            <?php if (!empty($professionalAssignmentSettings['enabled'])): ?>
                    <small class="ownership-state <?= !empty($ownershipSnapshot['locked_by_other']) ? 'is-locked' : 'is-available' ?>">
                        <?= !empty($ownershipSnapshot['locked_by_other'])
                            ? 'Atendimento exclusivo em andamento'
                            : ((int) ($selected['assigned_user_id'] ?? 0) > 0 ? 'Responsável definido' : 'Disponível para assumir') ?>
                    </small>
                <?php endif; ?>
                <?php if (Auth::isSuperAdmin()): ?><small>Empresa: <strong><?= View::e($selected['tenant_name']) ?></strong></small><?php endif; ?>
                <span class="realtime-status" data-realtime-status>Atualização automática ativa</span>
                <?php
                $ruleHoursState = is_array($selectedRuleSnapshot['hours'] ?? null) ? $selectedRuleSnapshot['hours'] : [];
                $isOutsideConfiguredHours = !empty($ruleHoursState['enforced']) && empty($ruleHoursState['inside']);
                $hasAfterHoursPending = is_array($selectedAfterHoursPending ?? null);
                $nextOpeningRaw = trim((string) ($selectedRuleSnapshot['next_opening_at'] ?? ''));
                $selectedAfterHoursStatus = trim((string) ($selectedAfterHoursPending['status'] ?? ''));
                $selectedAfterHoursCount = max(1, (int) ($selectedAfterHoursPending['message_count'] ?? 0));
                $selectedAfterHoursClass = $afterHoursStatusClasses[$selectedAfterHoursStatus] ?? 'is-waiting';
                $selectedAfterHoursLabel = $afterHoursStatusLabels[$selectedAfterHoursStatus] ?? 'Aguardando horário';
                ?>
                <?php if ($hasAfterHoursPending): ?>
                    <span class="after-hours-state <?= View::e($selectedAfterHoursClass) ?>" title="A demanda está preservada e não será perdida.">
                        <?= View::e($selectedAfterHoursLabel) ?> · <?= $selectedAfterHoursCount ?> <?= $selectedAfterHoursCount === 1 ? 'mensagem' : 'mensagens' ?>
                    </span>
                <?php elseif ($isOutsideConfiguredHours): ?>
                    <span class="after-hours-state is-neutral">Fora do horário configurado</span>
                <?php endif; ?>
                <?php $refreshQuery = $currentQuery; $refreshQuery['conversation_id'] = (int) $selected['id']; ?>
                <a class="refresh-chat" href="<?= View::e(Router::url('/conversations?' . http_build_query($refreshQuery))) ?>">Atualizar</a>
            </div>

            <?php if ($hasAfterHoursPending): ?>
                <?php
                $receivedAt = trim((string) ($selectedAfterHoursPending['first_received_at'] ?? $selectedAfterHoursPending['last_received_at'] ?? ''));
                $lastReceivedAt = trim((string) ($selectedAfterHoursPending['last_received_at'] ?? ''));
                $ackSent = !empty($selectedAfterHoursPending['ack_sent_at']);
                $queueDescription = match ($selectedAfterHoursStatus) {
                    'processing' => 'A automação já iniciou a retomada desta conversa.',
                    'blocked_plan' => 'A mensagem está segura, mas a retomada automática depende da franquia de IA.',
                    'blocked_human' => (string) ($selected['attendance_mode'] ?? '') === 'human'
                        ? 'A mensagem chegou fora do horário e permanece destacada para a equipe responsável.'
                        : 'A mensagem está segura e a automação respeitará o atendimento humano ou a IA pausada.',
                    'error' => 'A mensagem está segura. O sistema fará uma nova tentativa sem duplicar respostas.',
                    default => $isOutsideConfiguredHours
                        ? 'A conversa será retomada automaticamente quando o expediente começar.'
                        : 'O expediente já abriu e esta conversa está na fila de retomada automática.',
                };
                ?>
                <section class="after-hours-queue-banner <?= View::e($selectedAfterHoursClass) ?>" data-after-hours-banner>
                    <div class="after-hours-queue-banner-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                    </div>
                    <div class="after-hours-queue-banner-content">
                        <div class="after-hours-queue-banner-head">
                            <div>
                                <span class="eyebrow">Fila fora do horário</span>
                                <h3><?= View::e($selectedAfterHoursLabel) ?></h3>
                                <p><?= View::e($queueDescription) ?></p>
                            </div>
                            <span class="after-hours-queue-count"><strong><?= $selectedAfterHoursCount ?></strong><?= $selectedAfterHoursCount === 1 ? ' mensagem' : ' mensagens' ?></span>
                        </div>
                        <div class="after-hours-queue-details">
                            <span><small>Primeiro contato</small><strong><?= View::e($receivedAt !== '' ? $formatDate($receivedAt, 'd/m H:i') : '—') ?></strong></span>
                            <span><small>Última mensagem</small><strong><?= View::e($lastReceivedAt !== '' ? $formatDate($lastReceivedAt, 'd/m H:i') : '—') ?></strong></span>
                            <span><small>Aviso de ausência</small><strong><?= $ackSent ? 'Enviado' : 'Não enviado' ?></strong></span>
                            <span><small><?= (string) ($selected['attendance_mode'] ?? '') === 'human' ? 'Próxima abertura' : 'Retomada prevista' ?></small><strong><?= View::e($nextOpeningRaw !== '' ? $formatDate($nextOpeningRaw, 'd/m H:i') : ($isOutsideConfiguredHours ? 'Próximo expediente' : 'Em processamento')) ?></strong></span>
                        </div>
                        <?php if (!empty($selectedAfterHoursPending['last_error'])): ?>
                            <div class="after-hours-queue-note is-error"><strong>Última tentativa:</strong> <?= View::e((string) $selectedAfterHoursPending['last_error']) ?></div>
                        <?php elseif ($selectedAfterHoursStatus === 'blocked_human'): ?>
                            <div class="after-hours-queue-note"><?= (string) ($selected['attendance_mode'] ?? '') === 'human'
                                ? 'A conversa já está com a equipe. A pendência será encerrada após uma resposta humana.'
                                : 'A automação só voltará a responder quando a conversa retornar ao modo IA.' ?></div>
                        <?php else: ?>
                            <div class="after-hours-queue-note">As mensagens ficam reunidas em um único atendimento e não geram custo de IA enquanto aguardam.</div>
                        <?php endif; ?>
                    </div>
                    <?php if ($canOperateSelected && (string) ($selected['attendance_mode'] ?? '') !== 'human'): ?>
                        <form class="after-hours-queue-action" method="post" action="<?= View::e(Router::url('/conversations/mode')) ?>">
                            <?= Csrf::input() ?><input type="hidden" name="conversation_id" value="<?= (int) $selected['id'] ?>"><input type="hidden" name="mode" value="human">
                            <button class="btn btn-outline btn-small" type="submit">Assumir e retirar da fila</button>
                            <small>A responsabilidade passa para você e a retomada automática é cancelada.</small>
                        </form>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

        <?php if ($selectedCommercialRequest && !empty($commercialRequestSettings['enabled']) && !empty($commercialRequestSettings['show_conversation_alert'])): ?>
        <?php
            $quoteDueAt = trim((string) ($selectedCommercialRequest['due_at'] ?? ''));
            $quoteOverdue = $quoteDueAt !== '' && (strtotime($quoteDueAt) ?: PHP_INT_MAX) < time();
            $quoteLeadId = (int) ($selectedCommercialRequest['lead_id'] ?? 0);
            ?>
            <section class="commercial-request-banner<?= $quoteOverdue ? ' is-overdue' : '' ?>">
                <div class="commercial-request-banner-icon" aria-hidden="true">$</div>
                <div class="commercial-request-banner-content">
                    <span class="eyebrow">Solicitação comercial pendente</span>
                    <h3>O cliente pediu um orçamento</h3>
                    <p><?= View::e((string) ($selectedCommercialRequest['reason'] ?? 'A conversa indicou solicitação de orçamento ou proposta.')) ?></p>
                    <div class="commercial-request-banner-meta">
                        <span><small>Prazo</small><strong><?= View::e($quoteDueAt !== '' ? $formatDate($quoteDueAt, 'd/m H:i') : 'Sem prazo') ?></strong></span>
                        <span><small>Responsável</small><strong><?= View::e((string) (($selectedCommercialRequest['assigned_name'] ?? '') ?: 'Equipe comercial')) ?></strong></span>
                        <span><small>Confiança</small><strong><?= (int) round(((float) ($selectedCommercialRequest['confidence'] ?? 0)) * 100) ?>%</strong></span>
                    </div>
                    <?php if (!empty($selectedCommercialRequest['excerpt'])): ?><blockquote>“<?= View::e((string) $selectedCommercialRequest['excerpt']) ?>”</blockquote><?php endif; ?>
                </div>
                <div class="commercial-request-banner-actions">
                    <?php if ($quoteLeadId > 0 && Auth::can('crm.view')): ?><a class="btn btn-outline btn-small" href="<?= View::e(Router::url('/crm?lead_id=' . $quoteLeadId)) ?>">Abrir no Comercial</a><?php endif; ?>
                    <?php if ($canOperateSelected): ?>
                        <form method="post" action="<?= View::e(Router::url('/conversations/commercial-request/resolve')) ?>">
                            <?= Csrf::input() ?><input type="hidden" name="conversation_id" value="<?= (int) $selected['id'] ?>"><input type="hidden" name="request_id" value="<?= (int) $selectedCommercialRequest['id'] ?>"><input type="hidden" name="decision" value="resolved">
                            <button class="btn btn-primary btn-small" type="submit">Marcar orçamento atendido</button>
                        </form>
                        <form method="post" action="<?= View::e(Router::url('/conversations/commercial-request/resolve')) ?>" data-confirm="Dispensar este alerta sem marcar o orçamento como atendido?">
                            <?= Csrf::input() ?><input type="hidden" name="conversation_id" value="<?= (int) $selected['id'] ?>"><input type="hidden" name="request_id" value="<?= (int) $selectedCommercialRequest['id'] ?>"><input type="hidden" name="decision" value="dismissed">
                            <button class="btn btn-quiet btn-small" type="submit">Dispensar alerta</button>
                        </form>
                    <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>

            <?php if (!empty($professionalAssignmentSettings['enabled'])): ?>
                <?php if (!empty($ownershipSnapshot['locked_by_other'])): ?>
                    <div class="conversation-ownership-banner is-locked" data-ownership-banner>
                        <strong>Atendimento em andamento com <?= View::e($selected['assigned_user_name'] ?: 'outro profissional') ?></strong>
                        <span>Você pode acompanhar a conversa, mas não pode responder nem alterar o atendimento enquanto ela estiver aberta.</span>
                    </div>
                <?php elseif ((int) ($selected['assigned_user_id'] ?? 0) < 1 && (string) ($selected['status'] ?? '') !== 'closed'): ?>
                    <div class="conversation-ownership-banner is-available" data-ownership-banner>
                        <strong>Conversa disponível</strong>
                        <span>Ao enviar uma mensagem ou clicar em Assumir atendimento, você se torna o responsável.</span>
                    </div>
                <?php elseif ((int) ($selected['assigned_user_id'] ?? 0) === (int) (Auth::id() ?? 0)): ?>
                    <div class="conversation-ownership-banner is-mine" data-ownership-banner>
                        <strong>Você está responsável por este atendimento</strong>
                        <span>O bloqueio exclusivo está ativo. Outros usuários só podem acompanhar até a transferência, liberação ou encerramento.</span>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php if (!empty($selected['last_ai_suggestion'])): ?>
                <div class="ai-suggestion-card">
                    <div>
                        <span class="eyebrow">Sugestão da IA</span>
                        <p><?= nl2br(View::e($selected['last_ai_suggestion'])) ?></p>
                        <?php if (!empty($selected['last_ai_suggestion_at'])): ?><small>Gerada em <?= View::e($formatDate($selected['last_ai_suggestion_at'])) ?></small><?php endif; ?>
                    </div>
                    <?php if ($canOperateSelected): ?>
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
                            <?php
                            $messageTypeLabel = match ((string) ($message['message_type'] ?? 'text')) {
                                'image' => 'Imagem',
                                'audio' => 'Áudio',
                                'document' => 'Documento',
                                default => ucfirst((string) ($message['message_type'] ?? 'text')),
                            };
                            ?>
                            <?php if ($message['message_type'] !== 'text'): ?><span class="message-type"><?= View::e($messageTypeLabel) ?></span><?php endif; ?>
                            <?php foreach (($message['attachments'] ?? []) as $attachment): ?>
                                <?php $attachmentStatus = (string) ($attachment['status'] ?? 'pending'); ?>
                                <div class="message-attachment kind-<?= View::e((string) ($attachment['kind'] ?? 'other')) ?> status-<?= View::e($attachmentStatus) ?>">
                                    <?php if ($attachmentStatus === 'ready' && ($attachment['kind'] ?? '') === 'image'): ?>
                                        <a class="message-attachment-image" href="<?= View::e((string) $attachment['view_url']) ?>" target="_blank" rel="noopener">
                                            <img src="<?= View::e((string) $attachment['view_url']) ?>" alt="<?= View::e((string) $attachment['name']) ?>" loading="lazy">
                                        </a>
                                    <?php elseif ($attachmentStatus === 'ready' && ($attachment['kind'] ?? '') === 'audio'): ?>
                                        <div class="message-attachment-audio">
                                            <audio controls preload="metadata" src="<?= View::e((string) $attachment['view_url']) ?>"></audio>
                                            <label>Velocidade
                                                <select data-audio-speed>
                                                    <option value="1">1x</option>
                                                    <option value="1.5">1,5x</option>
                                                    <option value="2">2x</option>
                                                </select>
                                            </label>
                                        </div>
                                    <?php endif; ?>

                                    <div class="message-attachment-info">
                                        <span class="message-attachment-icon" aria-hidden="true"><?= ($attachment['kind'] ?? '') === 'image' ? '🖼️' : (($attachment['kind'] ?? '') === 'audio' ? '🎵' : '📄') ?></span>
                                        <span><strong><?= View::e((string) $attachment['name']) ?></strong><small><?= View::e((string) $attachment['size_label']) ?></small></span>
                                        <?php if ($attachmentStatus === 'ready'): ?>
                                            <span class="message-attachment-actions">
                                                <?php if (($attachment['kind'] ?? '') === 'document'): ?><a href="<?= View::e((string) $attachment['view_url']) ?>" target="_blank" rel="noopener">Visualizar</a><?php endif; ?>
                                                <a href="<?= View::e((string) $attachment['download_url']) ?>">Baixar</a>
                                            </span>
                                        <?php else: ?>
                                            <small class="message-attachment-error"><?= View::e((string) ($attachment['error_message'] ?: 'Arquivo indisponível.')) ?></small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <?php
                            $messageContent = !empty($message['content_purged_at']) ? 'Conteúdo removido pela política de retenção.' : trim((string) ($message['content'] ?? ''));
                            $attachmentNames = array_map(static fn (array $item): string => trim((string) ($item['name'] ?? '')), (array) ($message['attachments'] ?? []));
                            $mediaPlaceholders = ['[Imagem]', '[Áudio]', '[Documento]', '[Arquivo]', ...$attachmentNames];
                            $showMessageContent = $messageContent !== '' && (!empty($message['content_purged_at']) || !in_array($messageContent, $mediaPlaceholders, true));
                            ?>
                            <?php if ($showMessageContent): ?><p><?= nl2br(View::e($messageContent)) ?></p><?php endif; ?>
                            <?php if (!empty($message['error_message'])): ?><small class="message-error"><?= View::e($message['error_message']) ?></small><?php endif; ?>
                            <footer>
                                <?php if ($outgoing): ?>
                                    <?php $senderLabel = $message['sender_type'] === 'ai' ? 'IA' : ($message['sender_user_name'] ?: 'Equipe'); ?>
                                    <?php if ($message['sender_type'] === 'user' && !empty($message['sender_user_role_label'])): $senderLabel .= ' — ' . $message['sender_user_role_label']; endif; ?>
                                    <span><?= View::e($senderLabel) ?></span>
                                <?php endif; ?>
                                <time><?= View::e($formatDate($message['sent_at'], 'd/m H:i')) ?></time>
                                <?php if ($outgoing): ?><span class="message-status"><?= View::e($message['status']) ?></span><?php endif; ?>
                            </footer>
                        </div>
                    </article>
                <?php endforeach; ?>
                <?php if (!$messages): ?>
                    <div class="chat-empty"><span class="empty-symbol"></span><strong>Histórico vazio</strong><p>As novas mensagens recebidas automaticamente aparecerão aqui.</p></div>
                <?php endif; ?>
            </div>

            <?php if ($canOperateSelected): ?>
                <form class="chat-composer" id="conversation-composer" data-chat-composer method="post" enctype="multipart/form-data" action="<?= View::e(Router::url('/conversations/send')) ?>" data-attachment-action="<?= View::e(Router::url('/conversations/attachments/send')) ?>">
                    <?= Csrf::input() ?>
                    <input type="hidden" name="conversation_id" value="<?= (int) $selected['id'] ?>">
                    <input class="chat-attachment-input" type="file" name="attachment" data-attachment-input accept="image/jpeg,image/png,image/webp,application/pdf,audio/mpeg,audio/ogg,audio/opus,audio/mp4,.m4a" hidden>
                    <button class="chat-attachment-button" type="button" data-attachment-open aria-label="Anexar imagem, PDF ou áudio" title="Anexar imagem, PDF ou áudio">📎</button>
                    <div class="chat-composer-main">
                        <div class="chat-attachment-preview" data-attachment-preview hidden>
                            <span class="chat-attachment-preview-icon" data-attachment-preview-icon>📄</span>
                            <span><strong data-attachment-preview-name></strong><small data-attachment-preview-size></small></span>
                            <button type="button" data-attachment-remove aria-label="Remover anexo">×</button>
                        </div>
                        <textarea name="message" rows="2" maxlength="4000" placeholder="Digite uma mensagem..."></textarea>
                        <small class="chat-composer-help">Envie texto, imagem, PDF ou áudio. Limite configurado: <?= View::e((string) ($attachmentMaxLabel ?? '20 MB')) ?>.</small>
                    </div>
                    <button class="btn btn-primary" type="submit">Enviar</button>
                </form>
            <?php elseif ($canManage && !empty($ownershipSnapshot['locked_by_other'])): ?>
                <div class="chat-composer chat-composer-locked" data-chat-composer-locked>
                    <span>Conversa bloqueada para envio</span>
                    <strong><?= View::e($selected['assigned_user_name'] ?: 'Outro profissional') ?> está atendendo este cliente.</strong>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="chat-empty workspace-empty"><span class="empty-symbol"></span><strong>Selecione uma conversa</strong><p>Clique em um contato da lista para abrir o histórico. Nenhuma conversa é aberta automaticamente.</p></div>
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
                    <p><?= View::e($contactLabel($selected)) ?></p>
                </div>
                <button class="icon-button drawer-close" type="button" data-close-panel="conversation-details" aria-label="Fechar painel">×</button>
            </div>

            <div class="conversation-drawer-body">
                <?php if (!empty($professionalAssignmentSettings['enabled'])): ?>
                    <section class="drawer-section conversation-ownership-card">
                        <div class="drawer-section-title">
                            <div>
                                <span class="eyebrow">Responsabilidade</span>
                                <h3>Profissional da conversa</h3>
                                <small>A atribuição automática está <?= !empty($professionalAssignmentSettings['auto_assign_enabled']) ? 'ativada' : 'desativada' ?> para esta empresa.</small>
                            </div>
                        </div>
                        <div class="ownership-summary-grid">
                            <div><span>Profissional preferido</span><strong><?= View::e($selected['preferred_user_name'] ?: 'Sem preferência') ?></strong></div>
                            <div><span>Responsável atual</span><strong><?= View::e($selected['assigned_user_name'] ?: 'Conversa disponível') ?></strong></div>
                        </div>

                        <?php if (!empty($ownershipSnapshot['can_claim'])): ?>
                            <form method="post" action="<?= View::e(Router::url('/conversations/assignment')) ?>">
                                <?= Csrf::input() ?>
                                <input type="hidden" name="conversation_id" value="<?= (int) $selected['id'] ?>">
                                <input type="hidden" name="tenant_id" value="<?= (int) $selected['tenant_id'] ?>">
                                <input type="hidden" name="action" value="claim">
                                <button class="btn btn-primary btn-block" type="submit">Assumir atendimento</button>
                            </form>
                        <?php endif; ?>

                        <?php if (!empty($ownershipSnapshot['can_assign'])): ?>
                            <form method="post" action="<?= View::e(Router::url('/conversations/assignment')) ?>" class="ownership-transfer-form">
                                <?= Csrf::input() ?>
                                <input type="hidden" name="conversation_id" value="<?= (int) $selected['id'] ?>">
                                <input type="hidden" name="tenant_id" value="<?= (int) $selected['tenant_id'] ?>">
                                <input type="hidden" name="action" value="assign">
                                <label class="field"><span>Definir responsável</span><select name="assigned_user_id" required>
                                    <option value="">Escolha um profissional</option>
                                    <?php foreach ($team as $member): ?>
                                        <option value="<?= (int) $member['id'] ?>"><?= View::e($member['name']) ?></option>
                                    <?php endforeach; ?>
                                </select></label>
                                <button class="btn btn-outline btn-block" type="submit">Atribuir conversa</button>
                            </form>
                        <?php endif; ?>

                        <?php if (!empty($ownershipSnapshot['can_transfer'])): ?>
                            <form method="post" action="<?= View::e(Router::url('/conversations/assignment')) ?>" class="ownership-transfer-form">
                                <?= Csrf::input() ?>
                                <input type="hidden" name="conversation_id" value="<?= (int) $selected['id'] ?>">
                                <input type="hidden" name="tenant_id" value="<?= (int) $selected['tenant_id'] ?>">
                                <input type="hidden" name="action" value="transfer">
                                <label class="field"><span>Transferir para</span><select name="assigned_user_id" required>
                                    <option value="">Escolha um profissional</option>
                                    <?php foreach ($team as $member): ?>
                                        <?php if ((int) $member['id'] === (int) ($selected['assigned_user_id'] ?? 0)) continue; ?>
                                        <option value="<?= (int) $member['id'] ?>"><?= View::e($member['name']) ?></option>
                                    <?php endforeach; ?>
                                </select></label>
                                <button class="btn btn-outline btn-block" type="submit">Transferir atendimento</button>
                            </form>
                        <?php endif; ?>

                        <?php if (!empty($ownershipSnapshot['can_release'])): ?>
                            <form method="post" action="<?= View::e(Router::url('/conversations/assignment')) ?>" data-confirm="Liberar esta conversa para que outro profissional possa assumir?">
                                <?= Csrf::input() ?>
                                <input type="hidden" name="conversation_id" value="<?= (int) $selected['id'] ?>">
                                <input type="hidden" name="tenant_id" value="<?= (int) $selected['tenant_id'] ?>">
                                <input type="hidden" name="action" value="release">
                                <button class="btn btn-quiet btn-block" type="submit">Liberar para a equipe</button>
                                <small class="field-hint">A conversa permanece no atendimento humano; a IA não retoma automaticamente.</small>
                            </form>
                        <?php endif; ?>

                        <?php if (!empty($ownershipSnapshot['locked_by_other'])): ?>
                            <div class="message-warning">Somente <?= View::e($selected['assigned_user_name'] ?: 'o responsável atual') ?> ou um administrador após a transferência pode alterar este atendimento.</div>
                        <?php endif; ?>
                    </section>
                <?php endif; ?>

                <?php if ($canOperateSelected): ?>
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

                <section class="drawer-section conversation-agent-route-card">
                    <div class="drawer-section-title">
                        <div>
                            <span class="eyebrow">Roteamento da IA</span>
                            <h3>Assistente desta conversa</h3>
                            <small>O agente escolhido permanece responsável por esta conversa até uma troca manual.</small>
                        </div>
                    </div>
                    <?php if ($conversationAgents): ?>
                        <?php if ($canOperateSelected): ?>
                            <form method="post" action="<?= View::e(Router::url('/conversations/agent')) ?>" class="conversation-agent-route-form">
                                <?= Csrf::input() ?>
                                <input type="hidden" name="conversation_id" value="<?= (int) $selected['id'] ?>">
                                <label class="field">
                                    <span>Agente vinculado</span>
                                    <select name="agent_id" required>
                                        <?php foreach ($conversationAgents as $routeAgent): ?>
                                            <option value="<?= (int) $routeAgent['agent_id'] ?>" <?= (int) ($selected['ai_agent_id'] ?? 0) === (int) $routeAgent['agent_id'] ? 'selected' : '' ?>>
                                                <?= View::e((string) $routeAgent['name']) ?> · <?= View::e((string) $routeAgent['segment']) ?><?= (int) ($routeAgent['is_primary'] ?? 0) === 1 ? ' · Principal' : '' ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <button class="btn btn-outline btn-small" type="submit">Trocar assistente</button>
                            </form>
                        <?php else: ?>
                            <?php
                            $currentAgentLabel = 'Não definido';
                            foreach ($conversationAgents as $routeAgent) {
                                if ((int) ($selected['ai_agent_id'] ?? 0) === (int) $routeAgent['agent_id']) {
                                    $currentAgentLabel = (string) $routeAgent['name'];
                                    break;
                                }
                            }
                            ?>
                            <strong><?= View::e($currentAgentLabel) ?></strong>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="message-warning">Nenhum assistente está vinculado a este WhatsApp. Configure em WhatsApp → Agentes e roteamento.</div>
                    <?php endif; ?>
                </section>

                <?php if (is_array($selectedRuleSnapshot ?? null)): ?>
                    <?php
                    $ruleStatusLabels = ['lead' => 'Lead', 'customer' => 'Cliente', 'inactive' => 'Inativo'];
                    $ruleModeLabels = ['ai' => 'IA', 'human' => 'Humano', 'paused' => 'IA pausada'];
                    $ruleHours = is_array($selectedRuleSnapshot['hours'] ?? null) ? $selectedRuleSnapshot['hours'] : [];
                    $hoursEnforced = !empty($ruleHours['enforced']);
                    $insideHours = !empty($ruleHours['inside']);
                    $ruleGroup = (string) ($selectedRuleSnapshot['contact_group'] ?? 'unclassified');
                    $ruleStage = (string) ($selectedRuleSnapshot['flow_stage'] ?? 'identifying_contact');
                    ?>
                    <section class="drawer-section conversation-flow-card">
                        <div class="drawer-section-title">
                            <div>
                                <span class="eyebrow">Validação efetiva</span>
                                <h3>Regras aplicadas agora</h3>
                                <small>Este resumo mostra as regras que têm prioridade sobre as instruções livres do assistente.</small>
                            </div>
                        </div>
                        <div class="drawer-form-grid">
                            <div class="field"><span>Agente efetivo</span><strong><?= View::e((string) ($selectedRuleSnapshot['agent_name'] ?? 'Não definido')) ?></strong><?php if (!empty($selectedRuleSnapshot['agent_id'])): ?><small class="field-hint">ID <?= (int) $selectedRuleSnapshot['agent_id'] ?> · agente realmente usado pelas regras abaixo</small><?php endif; ?></div>
                            <div class="field"><span>Modo da conversa</span><strong><?= View::e($ruleModeLabels[(string) ($selectedRuleSnapshot['attendance_mode'] ?? '')] ?? (string) ($selectedRuleSnapshot['attendance_mode'] ?? '—')) ?></strong></div>
                            <div class="field"><span>Horário operacional</span><strong><?= !$hoursEnforced ? 'Livre / 24h' : ($insideHours ? 'Dentro do expediente' : 'Fora do expediente') ?></strong><?php if ($hoursEnforced && !empty($ruleHours['current'])): ?><small class="field-hint">Agora: <?= View::e((string) $ruleHours['current']) ?> · <?= View::e((string) ($ruleHours['timezone'] ?? '')) ?></small><?php endif; ?><?php if ($hoursEnforced && !empty($ruleHours['ranges']) && is_array($ruleHours['ranges'])): ?><small class="field-hint">Faixa aplicada: <?= View::e(implode(' / ', array_map(static fn($r) => is_array($r) ? (($r[0] ?? '') . '–' . ($r[1] ?? '')) : '', $ruleHours['ranges']))) ?></small><?php endif; ?><?php if ($hoursEnforced && !$insideHours && !empty($selectedRuleSnapshot['next_opening_at'])): ?><small class="field-hint">Próxima janela: <?= View::e($formatDate((string) $selectedRuleSnapshot['next_opening_at'], 'd/m H:i')) ?></small><?php endif; ?></div>
                            <div class="field"><span>Classificação</span><strong><?= View::e($ruleStatusLabels[(string) ($selectedRuleSnapshot['contact_status'] ?? '')] ?? (string) ($selectedRuleSnapshot['contact_status'] ?? 'Não informada')) ?></strong></div>
                            <div class="field"><span>Grupo</span><strong><?= View::e($contactGroupLabels[$ruleGroup] ?? $ruleGroup) ?></strong></div>
                            <div class="field"><span>Última intenção</span><strong><?= View::e((string) ($selectedRuleSnapshot['last_intent'] ?? 'conversation')) ?></strong></div>
                        </div>
                        <div class="<?= !empty($selectedRuleSnapshot['agenda_context']) ? 'message-warning' : 'message-success' ?>">
                            <?= !empty($selectedRuleSnapshot['agenda_context'])
                                ? 'Contexto de agenda ativo: somente agora as regras específicas de pré-agendamento entram no contexto da IA.'
                                : 'Conversa geral: a agenda não deve ser iniciada apenas por menções casuais de data ou horário.' ?>
                        </div>
                        <?php if (!empty($selectedRuleSnapshot['tags'])): ?>
                            <p class="field-hint"><strong>Tags consideradas:</strong> <?= View::e(implode(', ', $selectedRuleSnapshot['tags'])) ?></p>
                        <?php endif; ?>
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
                            <label class="field"><span>Nome</span><input name="name" value="<?= View::e($selected['contact_name']) ?>" <?= !$canOperateSelected ? 'readonly' : '' ?>></label>
                            <label class="field"><span>E-mail</span><input type="email" name="email" value="<?= View::e($selected['email']) ?>" <?= !$canOperateSelected ? 'readonly' : '' ?>></label>
                            <label class="field"><span>Empresa</span><input name="company" value="<?= View::e($selected['company']) ?>" <?= !$canOperateSelected ? 'readonly' : '' ?>></label>
                            <label class="field"><span>Classificação</span>
                                <select name="contact_status" <?= !$canOperateSelected ? 'disabled' : '' ?>>
                                    <option value="lead" <?= $selected['contact_status'] === 'lead' ? 'selected' : '' ?>>Lead</option>
                                    <option value="customer" <?= $selected['contact_status'] === 'customer' ? 'selected' : '' ?>>Cliente</option>
                                    <option value="inactive" <?= $selected['contact_status'] === 'inactive' ? 'selected' : '' ?>>Inativo</option>
                                </select>
                            </label>
                            <label class="field"><span>Grupo de atendimento</span>
                                <select name="contact_group" <?= !$canOperateSelected ? 'disabled' : '' ?>>
                                    <?php foreach ($contactGroupLabels as $value => $label): ?>
                                        <option value="<?= View::e($value) ?>" <?= ($selected['contact_group'] ?? 'unclassified') === $value ? 'selected' : '' ?>><?= View::e($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="field-hint">O assistente recebe esse grupo junto com as tags e aplica as regras específicas.</small>
                            </label>
                        </div>
                        <label class="field drawer-span"><span>Tags separadas por vírgula</span><input name="tags" value="<?= View::e($tagText) ?>" <?= !$canOperateSelected ? 'readonly' : '' ?>></label>
                        <label class="field drawer-span"><span>Notas internas</span><textarea name="notes" rows="7" <?= !$canOperateSelected ? 'readonly' : '' ?>><?= View::e($selected['notes']) ?></textarea></label>
                    </section>

                    <section class="drawer-section conversation-flow-card">
                        <div class="drawer-section-title">
                            <div>
                                <span class="eyebrow">Fluxo do atendimento</span>
                                <h3>Etapa e demanda</h3>
                                <small>Esses dados impedem que a agenda seja aberta antes da hora e são enviados ao assistente.</small>
                            </div>
                        </div>
                        <div class="drawer-form-grid">
                            <label class="field"><span>Etapa atual</span><select name="flow_stage" <?= !$canOperateSelected ? 'disabled' : '' ?>>
                                <?php foreach ($flowStageLabels as $value => $label): ?><option value="<?= View::e($value) ?>" <?= ($selected['flow_stage'] ?? 'identifying_contact') === $value ? 'selected' : '' ?>><?= View::e($label) ?></option><?php endforeach; ?>
                            </select></label>
                            <label class="field"><span>Situação da demanda</span><select name="demand_status" <?= !$canOperateSelected ? 'disabled' : '' ?>>
                                <?php foreach ($demandStatusLabels as $value => $label): ?><option value="<?= View::e($value) ?>" <?= ($selected['demand_status'] ?? 'pending') === $value ? 'selected' : '' ?>><?= View::e($label) ?></option><?php endforeach; ?>
                            </select></label>
                        </div>
                        <label class="field drawer-span"><span>Resumo da demanda</span><textarea name="demand_summary" rows="5" <?= !$canOperateSelected ? 'readonly' : '' ?> placeholder="Registre a queixa, necessidade ou a recusa em informar."><?= View::e($selected['demand_summary'] ?? '') ?></textarea></label>
                        <?php if (($selected['demand_status'] ?? 'pending') === 'pending'): ?>
                            <div class="message-warning">O pré-agendamento automático ficará bloqueado até a demanda ser coletada, recusada ou dispensada pela regra do grupo.</div>
                        <?php else: ?>
                            <div class="message-success">Etapa da demanda concluída. A agenda poderá seguir quando houver intenção real de agendamento.</div>
                        <?php endif; ?>
                    </section>

                    <?php if (!empty($selected['ai_memory_summary'])): ?>
                        <?php $memoryFacts = json_decode((string) ($selected['ai_memory_facts_json'] ?? ''), true); $memoryFacts = is_array($memoryFacts) ? $memoryFacts : []; ?>
                        <details class="drawer-section drawer-collapsed-card conversation-ai-memory-card">
                            <summary><span><span class="eyebrow">Memória da IA</span><strong><?= ($selected['ai_memory_scope'] ?? '') === 'contact' ? 'Memória preservada do contato' : 'Resumo progressivo da conversa' ?></strong><small><?= (int) ($selected['ai_memory_refresh_count'] ?? 0) ?> atualização(ões)<?= !empty($selected['ai_memory_refreshed_at']) ? ' · ' . View::e($formatDate($selected['ai_memory_refreshed_at'], 'd/m H:i')) : '' ?><?= ($selected['ai_memory_scope'] ?? '') === 'contact' ? ' · conversa anterior' : '' ?></small></span><span class="drawer-chevron"></span></summary>
                            <div class="conversation-ai-memory-body">
                                <p><?= nl2br(View::e((string) $selected['ai_memory_summary'])) ?></p>
                                <?php $memoryList = []; foreach (['interests' => 'Interesses','preferences' => 'Preferências','important_facts' => 'Fatos importantes','pending_items' => 'Pendências','commitments' => 'Compromissos','restrictions' => 'Restrições'] as $key => $label) { $values = is_array($memoryFacts[$key] ?? null) ? array_filter(array_map('strval', $memoryFacts[$key])) : []; if ($values) $memoryList[$label] = $values; } ?>
                                <?php if ($memoryList): ?><div class="conversation-ai-memory-facts"><?php foreach ($memoryList as $label => $values): ?><div><strong><?= View::e($label) ?></strong><span><?= View::e(implode(' · ', $values)) ?></span></div><?php endforeach; ?></div><?php endif; ?>
                                <?php if (!empty($memoryFacts['next_action'])): ?><div class="message-success"><strong>Próximo passo:</strong> <?= View::e((string) $memoryFacts['next_action']) ?></div><?php endif; ?>
                                <small class="field-hint">A memória é usada para continuidade e economia de contexto. Mensagens recentes prevalecem se houver divergência.</small>
                            </div>
                        </details>
                    <?php endif; ?>

                    <?php if ($canOperateSelected): ?>
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
