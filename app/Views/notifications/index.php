<?php

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;
use App\Services\OperationalLanguageService;

$formatDate = static function (?string $value): string {
    if (!$value) return '—';
    $timestamp = strtotime($value);
    return $timestamp ? date('d/m/Y H:i', $timestamp) : $value;
};

$isClientView = !Auth::isSuperAdmin();
$statusLabel = ['unread' => 'Nova', 'read' => 'Lida', 'archived' => 'Arquivada'];
?>

<section class="card hero-card notification-hero notification-hero-configurable">
    <div>
        <span class="eyebrow light">Central de notificações</span>
        <h2>Seus avisos em um só lugar</h2>
        <p>Veja o que aconteceu, o que pode ser afetado e qual é o próximo passo.</p>
    </div>
    <div class="hero-actions">
        <span class="badge <?= (int) $unreadCount > 0 ? 'badge-overdue' : 'badge-active' ?>"><?= (int) $unreadCount ?> nova(s)</span>
        <?php if (Auth::can('notifications.manage')): ?>
            <a class="btn btn-outline" href="<?= View::e(Router::url('/settings/notifications')) ?>">Configurar notificações</a>
        <?php endif; ?>
        <?php if ((int) $unreadCount > 0): ?>
            <form method="post" action="<?= View::e(Router::url('/notifications/read-all')) ?>">
                <?= Csrf::input() ?>
                <button class="btn btn-outline" type="submit">Marcar todas como lidas</button>
            </form>
        <?php endif; ?>
    </div>
</section>

<section class="card">
    <div class="section-heading">
        <div><span class="eyebrow">Histórico</span><h2>Últimos avisos</h2></div>
        <span class="badge"><?= count($notifications) ?> registro(s)</span>
    </div>

    <div class="notification-list">
        <?php foreach ($notifications as $notification): ?>
            <?php
            $actionUrl = (string) ($notification['action_url'] ?? '');
            $notice = OperationalLanguageService::notification($notification, $isClientView);
            $hasGuidance = ($notice['impact'] ?? '') !== '' || ($notice['action'] ?? '') !== '';
            ?>
            <article class="notification-item notification-<?= View::e($notification['severity'] ?? 'info') ?> <?= ($notification['status'] ?? '') === 'unread' ? 'is-unread' : '' ?>">
                <div class="notification-marker"></div>
                <div class="notification-main">
                    <div class="notification-title-row">
                        <strong><?= View::e((string) $notice['title']) ?></strong>
                        <span class="badge badge-<?= View::e($notification['severity'] ?? 'info') ?>"><?= View::e(OperationalLanguageService::severityLabel((string) ($notification['severity'] ?? 'info'))) ?></span>
                    </div>
                    <?php if ($hasGuidance): ?>
                        <p><strong>O que aconteceu:</strong> <?= View::e((string) $notice['summary']) ?></p>
                        <?php if (($notice['impact'] ?? '') !== ''): ?><p><strong>O que pode ser afetado:</strong> <?= View::e((string) $notice['impact']) ?></p><?php endif; ?>
                        <?php if (($notice['action'] ?? '') !== ''): ?><p><strong>O que fazer agora:</strong> <?= View::e((string) $notice['action']) ?></p><?php endif; ?>
                    <?php else: ?>
                        <p><?= nl2br(View::e((string) $notice['summary'])) ?></p>
                    <?php endif; ?>
                    <small><?= View::e($formatDate($notification['created_at'] ?? null)) ?> · <?= View::e($statusLabel[$notification['status'] ?? 'read'] ?? ($notification['status'] ?? '')) ?></small>
                    <?php if (!$isClientView && (($notice['technical_title'] ?? '') !== '' || ($notice['technical_message'] ?? '') !== '')): ?>
                        <details class="health-technical-details"><summary>Ver detalhes técnicos</summary><pre><?= View::e(trim((string) ($notice['technical_event'] ?? '') . "
" . (string) ($notice['technical_title'] ?? '') . "
" . (string) ($notice['technical_message'] ?? ''))) ?></pre></details>
                    <?php endif; ?>
                </div>
                <?php if ($actionUrl !== ''): ?>
                    <a class="btn btn-small btn-outline" href="<?= View::e(str_starts_with($actionUrl, 'http') ? $actionUrl : Router::url($actionUrl)) ?>"><?= $hasGuidance ? 'Abrir orientação' : 'Ver detalhes' ?></a>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
        <?php if (!$notifications): ?>
            <div class="empty-state">Nenhuma notificação encontrada.</div>
        <?php endif; ?>
    </div>
</section>
