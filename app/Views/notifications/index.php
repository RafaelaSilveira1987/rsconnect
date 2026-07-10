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
?>

<section class="card hero-card notification-hero">
    <div>
        <span class="eyebrow light">Central do cliente</span>
        <h2>Notificações da sua conta</h2>
        <p>Acompanhe avisos financeiros, atualizações de assinatura e alertas importantes enviados pelo RS Connect.</p>
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
