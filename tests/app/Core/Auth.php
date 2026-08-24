<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

final class Auth
{
    private const SESSION_KEY = 'auth_user';
    private const DUMMY_PASSWORD_HASH = '$2y$12$X2VKb4/Q7o6LTV59qAHgUuaoT4NPhS/vVvAY.kamZ/qDDNtW1IbHi';
    private static array $permissionCache = [];

    public static function attempt(string $email, string $password): bool
    {
        $pdo = Database::connection();
        $statement = $pdo->prepare(
            'SELECT u.id, u.tenant_id, u.name, u.email, u.password_hash, u.role, u.status,
                    t.name AS tenant_name, t.status AS tenant_status
             FROM users u
             LEFT JOIN tenants t ON t.id = u.tenant_id
             WHERE u.email = :email
             LIMIT 1'
        );
        $statement->execute(['email' => mb_strtolower(trim($email))]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            // Reduz diferenças de tempo entre conta inexistente e senha incorreta.
            password_verify($password, self::DUMMY_PASSWORD_HASH);
            return false;
        }

        $passwordValid = password_verify($password, (string) $user['password_hash']);
        $userActive = ($user['status'] ?? null) === 'active';
        $tenantActive = $user['tenant_id'] === null || ($user['tenant_status'] ?? null) === 'active';
        if (!$passwordValid || !$userActive || !$tenantActive) {
            return false;
        }

        unset($user['password_hash'], $user['tenant_status'], $user['status']);
        session_regenerate_id(true);
        $_SESSION[self::SESSION_KEY] = $user;
        $_SESSION['auth_issued_at'] = time();
        self::$permissionCache = [];
        Csrf::regenerate();

        $update = $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id');
        $update->execute(['id' => $user['id']]);

        return true;
    }

    public static function check(): bool
    {
        return isset($_SESSION[self::SESSION_KEY]['id']);
    }

    public static function user(): ?array
    {
        return $_SESSION[self::SESSION_KEY] ?? null;
    }

    public static function id(): ?int
    {
        return isset($_SESSION[self::SESSION_KEY]['id'])
            ? (int) $_SESSION[self::SESSION_KEY]['id']
            : null;
    }

    public static function tenantId(): ?int
    {
        $tenantId = $_SESSION[self::SESSION_KEY]['tenant_id'] ?? null;
        return $tenantId === null ? null : (int) $tenantId;
    }

    public static function role(): ?string
    {
        return $_SESSION[self::SESSION_KEY]['role'] ?? null;
    }

    public static function isSuperAdmin(): bool
    {
        return self::role() === 'super_admin';
    }

    public static function can(string $permission): bool
    {
        if (!self::check()) {
            return false;
        }

        if (self::isSuperAdmin()) {
            return true;
        }

        if (array_key_exists($permission, self::$permissionCache)) {
            return self::$permissionCache[$permission];
        }

        $statement = Database::connection()->prepare(
            'SELECT rp.allowed
             FROM role_permissions rp
             INNER JOIN permissions p ON p.id = rp.permission_id
             WHERE p.permission_key = :permission
               AND rp.role = :role
               AND (rp.tenant_id = :tenant_id OR rp.tenant_id IS NULL)
             ORDER BY (rp.tenant_id IS NOT NULL) DESC
             LIMIT 1'
        );
        $statement->execute([
            'permission' => $permission,
            'role' => self::role(),
            'tenant_id' => self::tenantId(),
        ]);

        $allowed = (bool) $statement->fetchColumn();
        self::$permissionCache[$permission] = $allowed;
        return $allowed;
    }

    /**
     * Recarrega a identidade da sessão e invalida o acesso imediatamente quando
     * usuário ou empresa forem desativados durante uma sessão já aberta.
     */
    public static function refreshUser(): bool
    {
        if (!self::check()) {
            return false;
        }

        $statement = Database::connection()->prepare(
            'SELECT u.id, u.tenant_id, u.name, u.email, u.role, u.status,
                    t.name AS tenant_name, t.status AS tenant_status
             FROM users u
             LEFT JOIN tenants t ON t.id = u.tenant_id
             WHERE u.id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => self::id()]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            return false;
        }

        $userActive = ($user['status'] ?? null) === 'active';
        $tenantActive = $user['tenant_id'] === null || ($user['tenant_status'] ?? null) === 'active';
        if (!$userActive || !$tenantActive) {
            return false;
        }

        unset($user['status'], $user['tenant_status']);
        $_SESSION[self::SESSION_KEY] = $user;
        self::$permissionCache = [];
        return true;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        self::$permissionCache = [];
        session_regenerate_id(true);
        Csrf::regenerate();
    }
}
