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

final class PermissionController
{
    public function index(): void
    {
        $pdo = Database::connection();
        $permissions = $pdo->query(
            'SELECT id, permission_key, name, description, category
             FROM permissions
             ORDER BY category, name'
        )->fetchAll(PDO::FETCH_ASSOC);

        $rolePermissions = $pdo->query(
            'SELECT rp.role, p.permission_key, rp.allowed
             FROM role_permissions rp
             INNER JOIN permissions p ON p.id = rp.permission_id
             WHERE rp.tenant_id IS NULL'
        )->fetchAll(PDO::FETCH_ASSOC);

        $matrix = ['client_admin' => [], 'client_user' => []];
        foreach ($rolePermissions as $item) {
            $matrix[$item['role']][$item['permission_key']] = (bool) $item['allowed'];
        }

        View::render('permissions.index', [
            'title' => Auth::isSuperAdmin() ? 'Permissões' : 'Equipe e acessos',
            'permissions' => $permissions,
            'matrix' => $matrix,
            'canEdit' => Auth::isSuperAdmin(),
        ]);
    }

    public function update(): void
    {
        $pdo = Database::connection();
        $selected = $_POST['permissions'] ?? [];
        $roles = ['client_admin', 'client_user'];

        try {
            $pdo->beginTransaction();
            $delete = $pdo->prepare('DELETE FROM role_permissions WHERE tenant_id IS NULL AND role = :role');
            $insert = $pdo->prepare(
                'INSERT INTO role_permissions (tenant_id, role, permission_id, allowed)
                 SELECT NULL, :role, id, 1 FROM permissions WHERE permission_key = :permission_key'
            );

            foreach ($roles as $role) {
                $delete->execute(['role' => $role]);
                $keys = is_array($selected[$role] ?? null) ? $selected[$role] : [];
                foreach (array_unique(array_map('strval', $keys)) as $key) {
                    $insert->execute(['role' => $role, 'permission_key' => $key]);
                }
            }

            $pdo->commit();
            Audit::log('permissions.updated', ['roles' => $roles], null);
            Flash::set('success', 'Matriz padrão de permissões atualizada. Os usuários devem entrar novamente para renovar a sessão.');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Flash::set('error', 'Não foi possível atualizar as permissões.');
        }

        $this->redirect('/permissions');
    }

    private function redirect(string $path): never
    {
        header('Location: ' . Router::url($path));
        exit;
    }
}
