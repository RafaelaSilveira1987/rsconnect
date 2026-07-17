<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Router;
use App\Core\View;
use App\Services\AccessControlService;

final class AccessController
{
    public function restricted(): void
    {
        if (Auth::isSuperAdmin() || !Auth::tenantId()) {
            header('Location: ' . Router::url('/'));
            exit;
        }

        $service = new AccessControlService();
        $accessStatus = $service->statusForTenant((int) Auth::tenantId());
        if (!empty($accessStatus['allowed'])) {
            header('Location: ' . Router::url('/'));
            exit;
        }

        $service->recordBlockedAccess($accessStatus, 'restricted_page');
        View::render('access.restricted', [
            'title' => 'Acesso temporariamente limitado',
            'accessStatus' => $accessStatus,
        ], 'restricted');
    }
}
