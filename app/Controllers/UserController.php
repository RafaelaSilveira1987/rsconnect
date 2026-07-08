<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Flash;
use App\Core\Router;
use App\Core\View;
use PDO;
use Throwable;

final class UserController
{
    public function index(): void
    {
        $pdo = Database::connection();

        if (Auth::isSuperAdmin()) {
            $users = $pdo->query(
                'SELECT u.*, t.name AS tenant_name
                 FROM users u
                 LEFT JOIN tenants t ON t.id = u.tenant_id
                 ORDER BY u.created_at DESC'
            )->fetchAll(PDO::FETCH_ASSOC);
            $tenants = $pdo->query(
                'SELECT id, name FROM tenants WHERE status = "active" ORDER BY name'
            )->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $statement = $pdo->prepare(
                'SELECT u.*, t.name AS tenant_name
                 FROM users u
                 INNER JOIN tenants t ON t.id = u.tenant_id
                 WHERE u.tenant_id = :tenant_id
                 ORDER BY u.created_at DESC'
            );
            $statement->execute(['tenant_id' => Auth::tenantId()]);
            $users = $statement->fetchAll(PDO::FETCH_ASSOC);
            $tenants = [];
        }

        View::render('users.index', [
            'title' => 'Usuários',
            'users' => $users,
            'tenants' => $tenants,
        ]);
    }

    public function store(): void
    {
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        $role = (string) ($_POST['role'] ?? 'client_user');
        $tenantId = Auth::isSuperAdmin()
            ? $this->normalizeTenantId($_POST['tenant_id'] ?? null)
            : Auth::tenantId();

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
            Flash::set('error', 'Informe nome, e-mail válido e senha com pelo menos 8 caracteres.');
            $this->redirect('/users');
        }

        if (!$this->validRoleForTenant($role, $tenantId)) {
            Flash::set('error', 'O perfil selecionado não é válido para essa empresa.');
            $this->redirect('/users');
        }

        try {
            $statement = Database::connection()->prepare(
                'INSERT INTO users (tenant_id, name, email, password_hash, role, status)
                 VALUES (:tenant_id, :name, :email, :password_hash, :role, "active")'
            );
            $statement->execute([
                'tenant_id' => $tenantId,
                'name' => $name,
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'role' => $role,
            ]);
            Audit::log('user.created', ['email' => $email, 'role' => $role], $tenantId);
            Flash::set('success', 'Usuário cadastrado com sucesso.');
        } catch (Throwable $exception) {
            Flash::set('error', 'Não foi possível cadastrar. Confira se o e-mail já está em uso.');
        }

        $this->redirect('/users');
    }

    public function update(): void
    {
        $userId = (int) ($_POST['user_id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
        $role = (string) ($_POST['role'] ?? 'client_user');
        $status = (string) ($_POST['status'] ?? 'active');
        $password = (string) ($_POST['password'] ?? '');

        if ($userId < 1 || $name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)
            || !in_array($status, ['active', 'inactive'], true)) {
            Flash::set('error', 'Dados do usuário inválidos.');
            $this->redirect('/users');
        }

        $pdo = Database::connection();
        $statement = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $userId]);
        $target = $statement->fetch(PDO::FETCH_ASSOC);

        if (!$target || (!$this->canManageTarget($target))) {
            Flash::set('error', 'Usuário não encontrado ou fora da sua empresa.');
            $this->redirect('/users');
        }

        $tenantId = $target['tenant_id'] === null ? null : (int) $target['tenant_id'];
        if (!$this->validRoleForTenant($role, $tenantId)) {
            Flash::set('error', 'O perfil selecionado não é válido para esse usuário.');
            $this->redirect('/users');
        }

        if ($userId === Auth::id() && ($status !== 'active' || $role !== Auth::role())) {
            Flash::set('warning', 'Você não pode inativar ou trocar o próprio perfil durante a sessão.');
            $this->redirect('/users');
        }

        if ($password !== '' && strlen($password) < 8) {
            Flash::set('error', 'A nova senha precisa ter pelo menos 8 caracteres.');
            $this->redirect('/users');
        }

        try {
            $sql = 'UPDATE users SET name = :name, email = :email, role = :role, status = :status';
            $params = [
                'name' => $name,
                'email' => $email,
                'role' => $role,
                'status' => $status,
                'id' => $userId,
            ];

            if ($password !== '') {
                $sql .= ', password_hash = :password_hash';
                $params['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            }
            $sql .= ' WHERE id = :id';

            $update = $pdo->prepare($sql);
            $update->execute($params);
            Audit::log('user.updated', ['user_id' => $userId, 'role' => $role, 'status' => $status], $tenantId);

            if ($userId === Auth::id()) {
                Auth::refreshUser();
            }

            Flash::set('success', 'Usuário atualizado.');
        } catch (Throwable $exception) {
            Flash::set('error', 'Não foi possível atualizar. Confira se o e-mail já está em uso.');
        }

        $this->redirect('/users');
    }

    private function canManageTarget(array $target): bool
    {
        if (Auth::isSuperAdmin()) {
            return true;
        }

        return $target['tenant_id'] !== null
            && (int) $target['tenant_id'] === (int) Auth::tenantId()
            && $target['role'] !== 'super_admin';
    }

    private function validRoleForTenant(string $role, ?int $tenantId): bool
    {
        if ($tenantId === null) {
            return Auth::isSuperAdmin() && $role === 'super_admin';
        }

        return in_array($role, ['client_admin', 'client_user'], true);
    }

    private function normalizeTenantId(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === 'global') {
            return null;
        }
        $tenantId = (int) $value;
        return $tenantId > 0 ? $tenantId : null;
    }

    private function redirect(string $path): never
    {
        header('Location: ' . Router::url($path));
        exit;
    }
}
