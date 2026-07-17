<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Csrf;
use App\Core\Flash;
use App\Core\Router;
use App\Core\View;
use App\Services\SecurityService;

final class SecurityController
{
    public function index(): void
    {
        $service = new SecurityService();
        View::render('security.index', [
            'title' => 'Segurança',
            'securityData' => $service->dashboard(),
        ]);
    }

    public function unlockUser(): void
    {
        if (!Csrf::validate($_POST['_token'] ?? null)) {
            Flash::set('error', 'Sessão expirada. Atualize a página e tente novamente.');
            header('Location: ' . Router::url('/security'));
            exit;
        }

        $userId = (int) ($_POST['user_id'] ?? 0);
        if ($userId < 1) {
            Flash::set('error', 'Usuário inválido.');
            header('Location: ' . Router::url('/security'));
            exit;
        }

        (new SecurityService())->unlockUser($userId);
        Flash::set('success', 'Bloqueio de login removido.');
        header('Location: ' . Router::url('/security'));
        exit;
    }

    public function revokeSession(): void
    {
        if (!Csrf::validate($_POST['_token'] ?? null)) {
            Flash::set('error', 'Sessão expirada. Atualize a página e tente novamente.');
            header('Location: ' . Router::url('/security'));
            exit;
        }

        $sessionId = (string) ($_POST['session_id'] ?? '');
        if ($sessionId === '') {
            Flash::set('error', 'Sessão inválida.');
            header('Location: ' . Router::url('/security'));
            exit;
        }

        (new SecurityService())->revokeSession($sessionId);
        Flash::set('success', 'Sessão revogada. O usuário será desconectado na próxima navegação.');
        header('Location: ' . Router::url('/security'));
        exit;
    }
}
