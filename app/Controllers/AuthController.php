<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Flash;
use App\Core\Router;
use App\Core\View;
use App\Services\AccessControlService;
use App\Services\SecurityService;

final class AuthController
{
    public function showLogin(): void
    {
        View::render('auth.login', ['title' => 'Entrar'], 'guest');
    }

    public function login(): void
    {
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        $security = new SecurityService();

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
            $security->recordLoginAttempt($email, false, null, null, 'invalid_input');
            Flash::set('error', 'Informe e-mail e senha válidos.');
            header('Location: ' . Router::url('/login'));
            exit;
        }

        $lockState = $security->loginLockState($email);
        if (!empty($lockState['locked'])) {
            $security->recordEvent('auth.login_blocked_user_lock', 'critical', ['email' => $email, 'locked_until' => $lockState['locked_until'] ?? null]);
            Flash::set('error', $security->lockMessage($lockState));
            header('Location: ' . Router::url('/login'));
            exit;
        }

        if ($security->tooManyFailedLoginAttempts($email)) {
            $security->recordEvent('auth.login_blocked_rate_limit', 'critical', ['email' => $email]);
            Flash::set('error', 'Muitas tentativas incorretas neste dispositivo. Aguarde alguns minutos e tente novamente.');
            header('Location: ' . Router::url('/login'));
            exit;
        }

        if (!Auth::attempt($email, $password)) {
            $lockState = $security->applyFailedLoginLock($email);
            $security->recordLoginAttempt($email, false, isset($lockState['user_id']) ? (int) $lockState['user_id'] : null, null, 'invalid_credentials');
            Flash::set('error', !empty($lockState['locked'])
                ? $security->lockMessage($lockState)
                : 'E-mail ou senha incorretos. Confira os dados e tente novamente.');
            header('Location: ' . Router::url('/login'));
            exit;
        }

        $security->resetLoginFailures($email);
        $security->recordLoginAttempt($email, true, Auth::id(), Auth::tenantId(), 'login_success');
        if (Auth::id()) {
            $security->registerSession((int) Auth::id());
        }

        if (!Auth::isSuperAdmin() && Auth::tenantId()) {
            $access = (new AccessControlService())->statusForTenant((int) Auth::tenantId());
            if (empty($access['allowed'])) {
                (new AccessControlService())->recordBlockedAccess($access, 'login');
                header('Location: ' . Router::url('/access-restricted'));
                exit;
            }
        }

        Flash::set('success', 'Bem-vinda ao RS Connect!');
        header('Location: ' . Router::url('/'));
        exit;
    }

    public function logout(): void
    {
        $security = new SecurityService();
        $security->recordEvent('auth.logout', 'info');
        $security->closeCurrentSession();
        Auth::logout();
        Flash::set('success', 'Sessão encerrada com segurança.');
        header('Location: ' . Router::url('/login'));
        exit;
    }
}
