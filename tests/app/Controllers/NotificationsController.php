<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
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
            'preferences' => $service->preferences($tenantId),
            'canManagePreferences' => Auth::can('notifications.manage'),
        ]);
    }

    public function savePreferences(): void
    {
        $tenantId = Auth::tenantId();
        if (!$tenantId) {
            Flash::set('error', 'Empresa não identificada.');
            $this->redirect('/notifications');
        }

        try {
            (new NotificationService())->savePreferences($tenantId, $_POST, Auth::id());
            Audit::log('notifications.preferences_updated', [
                'messages_enabled' => !empty($_POST['messages_enabled']),
                'ai_errors_enabled' => !empty($_POST['ai_errors_enabled']),
                'automation_errors_enabled' => !empty($_POST['automation_errors_enabled']),
                'calendar_enabled' => !empty($_POST['calendar_enabled']),
                'billing_enabled' => !empty($_POST['billing_enabled']),
                'system_enabled' => !empty($_POST['system_enabled']),
            ], $tenantId);
            Flash::set('success', 'Preferências de notificações atualizadas.');
        } catch (Throwable $exception) {
            Flash::set('error', 'Não foi possível salvar as preferências: ' . $exception->getMessage());
        }

        $this->redirect('/notifications');
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

    public function count(): void
    {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');

        $tenantId = Auth::tenantId();
        if (!$tenantId) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'count' => 0], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        $service = new NotificationService();
        echo json_encode([
            'ok' => true,
            'count' => $service->unreadCount($tenantId),
            'latest' => $service->latestUnreadForTenant($tenantId),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    private function redirect(string $path): never
    {
        header('Location: ' . Router::url($path));
        exit;
    }
}
