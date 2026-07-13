<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Flash;
use App\Core\Router;
use App\Core\View;
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

        if ($security->tooManyFailedLoginAttempts($email)) {
            $security->recordEvent('auth.login_blocked_rate_limit', 'critical', ['email' => $email]);
            Flash::set('error', 'Muitas tentativas de login. Aguarde alguns minutos e tente novamente.');
            header('Location: ' . Router::url('/login'));
            exit;
        }

        if (!Auth::attempt($email, $password)) {
            $security->recordLoginAttempt($email, false, null, null, 'invalid_credentials');
            Flash::set('error', 'Credenciais inválidas ou usuário inativo.');
            header('Location: ' . Router::url('/login'));
            exit;
        }

        $security->recordLoginAttempt($email, true, Auth::id(), Auth::tenantId(), 'login_success');
        if (Auth::id()) {
            $security->registerSession((int) Auth::id());
        }

        Flash::set('success', 'Bem-vinda ao RS Connect!');
        header('Location: ' . Router::url('/'));
        exit;
    }

    public function logout(): void
    {
        (new SecurityService())->recordEvent('auth.logout', 'info');
        Auth::logout();
        Flash::set('success', 'Sessão encerrada com segurança.');
        header('Location: ' . Router::url('/login'));
        exit;
    }
}
