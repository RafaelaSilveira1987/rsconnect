<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Flash;
use App\Core\Router;
use App\Core\View;
use App\Services\NotificationService;
use Throwable;

final class NotificationsController
{
    public function index(): void
    {
        $tenantId = Auth::tenantId();
        if (!$tenantId) {
            Flash::set('warning', 'Notificações disponíveis apenas para empresas clientes.');
            $this->redirect('/');
        }

        $service = new NotificationService();
        View::render('notifications.index', [
            'title' => 'Notificações',
            'notifications' => $service->latestForTenant($tenantId, 80),
            'unreadCount' => $service->unreadCount($tenantId),
        ]);
    }

    public function markAllRead(): void
    {
        $tenantId = Auth::tenantId();
        if ($tenantId) {
            try {
                (new NotificationService())->markAllRead($tenantId);
                Flash::set('success', 'Notificações marcadas como lidas.');
            } catch (Throwable $exception) {
                Flash::set('error', 'Não foi possível atualizar notificações: ' . $exception->getMessage());
            }
        }
        $this->redirect('/notifications');
    }

    private function redirect(string $path): never
    {
        header('Location: ' . Router::url($path));
        exit;
    }
}
