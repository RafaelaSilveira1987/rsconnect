<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Flash;
use App\Core\Router;
use App\Core\View;

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

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
            Flash::set('error', 'Informe e-mail e senha válidos.');
            header('Location: ' . Router::url('/login'));
            exit;
        }

        if (!Auth::attempt($email, $password)) {
            Flash::set('error', 'Credenciais inválidas ou usuário inativo.');
            header('Location: ' . Router::url('/login'));
            exit;
        }

        Flash::set('success', 'Bem-vinda ao RS Connect!');
        header('Location: ' . Router::url('/'));
        exit;
    }

    public function logout(): void
    {
        Auth::logout();
        Flash::set('success', 'Sessão encerrada com segurança.');
        header('Location: ' . Router::url('/login'));
        exit;
    }
}
