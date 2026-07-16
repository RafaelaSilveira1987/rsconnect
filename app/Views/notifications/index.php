<?php

use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;

$formatDate = static function (?string $value): string {
    if (!$value) return '—';
    $timestamp = strtotime($value);
    return $timestamp ? date('d/m/Y H:i', $timestamp) : $value;
};

$severityLabel = ['info' => 'Informação', 'success' => 'Sucesso', 'warning' => 'Atenção', 'danger' => 'Crítico'];
$statusLabel = ['unread' => 'Nova', 'read' => 'Lida', 'archived' => 'Arquivada'];
$preferences = is_array($preferences ?? null) ? $preferences : [];
$canManagePreferences = !empty($canManagePreferences);

$preferenceCards = [
    [
        'field' => 'messages_enabled',
        'title' => 'Novas mensagens',
        'description' => 'Avise no sininho quando um contato enviar uma nova mensagem pelo WhatsApp.',
        'icon' => '<path d="M5 6h14v9H8l-3 3V6Z"/>',
    ],
    [
        'field' => 'ai_errors_enabled',
        'title' => 'Assistente virtual',
        'description' => 'Avise quando o assistente não conseguir responder ou precisar de atenção.',
        'icon' => '<path d="M12 3l2.4 5 5.6.8-4 3.9.9 5.5L12 15.6 7.1 18.2l.9-5.5-4-3.9 5.6-.8L12 3Z"/>',
    ],
    [
        'field' => 'automation_errors_enabled',
        'title' => 'Integrações e automações',
        'description' => 'Avise quando um fluxo, webhook ou integração externa apresentar falha.',
        'icon' => '<path d="M6 7h4v4H6zM14 13h4v4h-4zM10 9h2a2 2 0 0 1 2 2v2"/>',
    ],
    [
        'field' => 'calendar_enabled',
        'title' => 'Agenda',
        'description' => 'Avise sobre novos pré-agendamentos e mudanças importantes nos compromissos.',
        'icon' => '<path d="M7 3v4M17 3v4M4 9h16M5 5h14v16H5z"/>',
    ],
    [
        'field' => 'billing_enabled',
        'title' => 'Financeiro e assinatura',
        'description' => 'Avise sobre vencimentos, atrasos, pagamentos e alterações da assinatura.',
        'icon' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M7 15h3"/>',
    ],
    [
        'field' => 'system_enabled',
        'title' => 'Avisos importantes',
        'description' => 'Receba comunicados essenciais sobre a conta e o funcionamento do RS Connect.',
        'icon' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-4"/>',
    ],
];
?>

<section class="card hero-card notification-hero notification-hero-configurable">
    <div>
        <span class="eyebrow light">Central de notificações</span>
        <h2>Escolha o que deseja acompanhar</h2>
        <p>Ative os avisos que fazem sentido para sua rotina. As notificações aparecem no sininho e ficam registradas no histórico abaixo.</p>
    </div>
    <div class="hero-actions">
        <span class="badge <?= (int) $unreadCount > 0 ? 'badge-overdue' : 'badge-active' ?>"><?= (int) $unreadCount ?> nova(s)</span>
        <?php if ((int) $unreadCount > 0): ?>
            <form method="post" action="<?= View::e(Router::url('/notifications/read-all')) ?>">
                <?= Csrf::input() ?>
                <button class="btn btn-outline" type="submit">Marcar todas como lidas</button>
            </form>
        <?php endif; ?>
    </div>
</section>

<section class="card notification-preferences-card">
    <div class="section-heading notification-preferences-heading">
        <div>
            <span class="eyebrow">Preferências</span>
            <h2>Quais avisos devem aparecer?</h2>
            <p class="muted-text">Você pode alterar essas opções a qualquer momento.</p>
        </div>
        <?php if (!$canManagePreferences): ?>
            <span class="badge badge-info">Somente o administrador da empresa pode alterar</span>
        <?php endif; ?>
    </div>

    <form method="post" action="<?= View::e(Router::url('/notifications/preferences')) ?>" class="notification-preferences-form">
        <?= Csrf::input() ?>
        <div class="notification-preference-grid">
            <?php foreach ($preferenceCards as $card): ?>
                <?php $enabled = (int) ($preferences[$card['field']] ?? 1) === 1; ?>
                <label class="notification-preference-option <?= $enabled ? 'is-enabled' : '' ?> <?= !$canManagePreferences ? 'is-readonly' : '' ?>">
                    <span class="notification-preference-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?= $card['icon'] ?></svg>
                    </span>
                    <span class="notification-preference-copy">
                        <strong><?= View::e($card['title']) ?></strong>
                        <small><?= View::e($card['description']) ?></small>
                    </span>
                    <span class="notification-switch">
                        <input
                            type="checkbox"
                            name="<?= View::e($card['field']) ?>"
                            value="1"
                            <?= $enabled ? 'checked' : '' ?>
                            <?= !$canManagePreferences ? 'disabled' : '' ?>
                            aria-label="Ativar <?= View::e($card['title']) ?>"
                        >
                        <span aria-hidden="true"></span>
                    </span>
                </label>
            <?php endforeach; ?>
        </div>

        <?php if ($canManagePreferences): ?>
            <div class="notification-preferences-actions">
                <span>As mudanças valem para toda a equipe desta empresa.</span>
                <button class="btn btn-primary" type="submit">Salvar preferências</button>
            </div>
        <?php endif; ?>
    </form>
</section>

<section class="card">
    <div class="section-heading">
        <div><span class="eyebrow">Histórico</span><h2>Últimos avisos</h2></div>
        <span class="badge"><?= count($notifications) ?> registro(s)</span>
    </div>

    <div class="notification-list">
        <?php foreach ($notifications as $notification): ?>
            <?php $actionUrl = (string) ($notification['action_url'] ?? ''); ?>
            <article class="notification-item notification-<?= View::e($notification['severity'] ?? 'info') ?> <?= ($notification['status'] ?? '') === 'unread' ? 'is-unread' : '' ?>">
                <div class="notification-marker"></div>
                <div class="notification-main">
                    <div class="notification-title-row">
                        <strong><?= View::e($notification['title'] ?? '') ?></strong>
                        <span class="badge badge-<?= View::e($notification['severity'] ?? 'info') ?>"><?= View::e($severityLabel[$notification['severity'] ?? 'info'] ?? 'Informação') ?></span>
                    </div>
                    <p><?= nl2br(View::e($notification['message'] ?? '')) ?></p>
                    <small><?= View::e($formatDate($notification['created_at'] ?? null)) ?> · <?= View::e($statusLabel[$notification['status'] ?? 'read'] ?? ($notification['status'] ?? '')) ?></small>
                </div>
                <?php if ($actionUrl !== ''): ?>
                    <a class="btn btn-small btn-outline" href="<?= View::e(str_starts_with($actionUrl, 'http') ? $actionUrl : Router::url($actionUrl)) ?>">Ver detalhes</a>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
        <?php if (!$notifications): ?>
            <div class="empty-state">Nenhuma notificação encontrada.</div>
        <?php endif; ?>
    </div>
</section>
