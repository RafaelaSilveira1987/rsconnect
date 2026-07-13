<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Env;
use PDO;
use Throwable;

final class SecurityService
{
    public function recordEvent(string $event, string $severity = 'info', array $context = [], ?int $tenantId = null, ?int $userId = null): void
    {
        try {
            $statement = Database::connection()->prepare(
                'INSERT INTO security_events (tenant_id, user_id, event, severity, context_json, ip_address, user_agent)
                 VALUES (:tenant_id, :user_id, :event, :severity, :context_json, :ip_address, :user_agent)'
            );
            $statement->execute([
                'tenant_id' => $tenantId ?? Auth::tenantId(),
                'user_id' => $userId ?? Auth::id(),
                'event' => $event,
                'severity' => $severity,
                'context_json' => $context === [] ? null : json_encode($this->sanitizeContext($context), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'ip_address' => $this->ipAddress(),
                'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
            ]);
        } catch (Throwable) {
            // Segurança não pode derrubar a aplicação caso a migration ainda não tenha sido executada.
        }
    }

    public function recordLoginAttempt(string $email, bool $success, ?int $userId = null, ?int $tenantId = null, string $reason = ''): void
    {
        try {
            $statement = Database::connection()->prepare(
                'INSERT INTO login_attempts (tenant_id, user_id, email, ip_address, user_agent, success, reason)
                 VALUES (:tenant_id, :user_id, :email, :ip_address, :user_agent, :success, :reason)'
            );
            $statement->execute([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'email' => mb_strtolower(trim($email)),
                'ip_address' => $this->ipAddress(),
                'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
                'success' => $success ? 1 : 0,
                'reason' => $reason !== '' ? $reason : ($success ? 'login_success' : 'login_failed'),
            ]);
        } catch (Throwable) {
            // Ignora se as tabelas ainda não existem.
        }

        $this->recordEvent($success ? 'auth.login_success' : 'auth.login_failed', $success ? 'info' : 'warning', [
            'email' => mb_strtolower(trim($email)),
            'reason' => $reason,
        ], $tenantId, $userId);
    }

    public function tooManyFailedLoginAttempts(string $email): bool
    {
        $limit = max(1, (int) Env::get('SECURITY_LOGIN_ATTEMPT_LIMIT', 6));
        $windowMinutes = max(1, (int) Env::get('SECURITY_LOGIN_ATTEMPT_WINDOW_MINUTES', 15));

        try {
            $statement = Database::connection()->prepare(
                'SELECT COUNT(*)
                 FROM login_attempts
                 WHERE success = 0
                   AND email = :email
                   AND ip_address = :ip
                   AND created_at >= (NOW() - INTERVAL ' . $windowMinutes . ' MINUTE)'
            );
            $statement->execute([
                'email' => mb_strtolower(trim($email)),
                'ip' => $this->ipAddress(),
            ]);
            return (int) $statement->fetchColumn() >= $limit;
        } catch (Throwable) {
            return false;
        }
    }

    public function registerSession(int $userId): void
    {
        $_SESSION['last_activity_at'] = time();
        $_SESSION['security_session_id'] = session_id();

        try {
            $statement = Database::connection()->prepare(
                'INSERT INTO user_sessions (user_id, session_id, ip_address, user_agent, last_seen_at, expires_at)
                 VALUES (:user_id, :session_id, :ip_address, :user_agent, NOW(), :expires_at)
                 ON DUPLICATE KEY UPDATE ip_address = VALUES(ip_address), user_agent = VALUES(user_agent), revoked_at = NULL, last_seen_at = NOW(), expires_at = VALUES(expires_at)'
            );
            $statement->execute([
                'user_id' => $userId,
                'session_id' => session_id(),
                'ip_address' => $this->ipAddress(),
                'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
                'expires_at' => date('Y-m-d H:i:s', time() + ((int) Env::get('SESSION_LIFETIME', 120) * 60)),
            ]);
        } catch (Throwable) {
            // Ignora se as tabelas ainda não existem.
        }
    }

    public function touchSession(): void
    {
        $_SESSION['last_activity_at'] = time();
        try {
            $statement = Database::connection()->prepare('UPDATE user_sessions SET last_seen_at = NOW() WHERE session_id = :session_id AND revoked_at IS NULL');
            $statement->execute(['session_id' => session_id()]);
        } catch (Throwable) {
            // Ignora se as tabelas ainda não existem.
        }
    }

    public function isCurrentSessionRevoked(): bool
    {
        try {
            $statement = Database::connection()->prepare('SELECT revoked_at FROM user_sessions WHERE session_id = :session_id LIMIT 1');
            $statement->execute(['session_id' => session_id()]);
            $revokedAt = $statement->fetchColumn();
            return $revokedAt !== false && $revokedAt !== null;
        } catch (Throwable) {
            return false;
        }
    }

    public function revokeSession(string $sessionId): void
    {
        try {
            $statement = Database::connection()->prepare('UPDATE user_sessions SET revoked_at = NOW() WHERE session_id = :session_id');
            $statement->execute(['session_id' => $sessionId]);
            $this->recordEvent('security.session_revoked', 'warning', ['session_id' => substr($sessionId, 0, 12) . '...']);
        } catch (Throwable) {
            // Ignora se as tabelas ainda não existem.
        }
    }

    public function enforceAuthenticatedSession(): bool
    {
        if (!Auth::check()) {
            return false;
        }

        if ($this->isCurrentSessionRevoked()) {
            Auth::logout();
            return false;
        }

        $idleMinutes = max(5, (int) Env::get('SECURITY_SESSION_IDLE_MINUTES', Env::get('SESSION_LIFETIME', 120)));
        $lastActivity = (int) ($_SESSION['last_activity_at'] ?? time());
        if ((time() - $lastActivity) > ($idleMinutes * 60)) {
            $this->recordEvent('auth.session_expired', 'warning');
            Auth::logout();
            return false;
        }

        $this->touchSession();
        return true;
    }

    public function dashboard(): array
    {
        $pdo = Database::connection();
        $today = date('Y-m-d');

        return [
            'failed_logins_24h' => $this->count('SELECT COUNT(*) FROM login_attempts WHERE success = 0 AND created_at >= (NOW() - INTERVAL 1 DAY)'),
            'successful_logins_24h' => $this->count('SELECT COUNT(*) FROM login_attempts WHERE success = 1 AND created_at >= (NOW() - INTERVAL 1 DAY)'),
            'active_sessions' => $this->count('SELECT COUNT(*) FROM user_sessions WHERE revoked_at IS NULL AND last_seen_at >= (NOW() - INTERVAL 2 HOUR)'),
            'critical_events_7d' => $this->count("SELECT COUNT(*) FROM security_events WHERE severity IN ('critical','error') AND created_at >= (NOW() - INTERVAL 7 DAY)"),
            'webhook_events_24h' => $this->count("SELECT COUNT(*) FROM security_events WHERE event LIKE 'webhook.%' AND created_at >= (NOW() - INTERVAL 1 DAY)"),
            'api_key_warnings' => $this->apiKeyWarnings(),
            'login_attempts' => $this->fetchAll('SELECT * FROM login_attempts ORDER BY id DESC LIMIT 20'),
            'events' => $this->fetchAll('SELECT se.*, u.name AS user_name, t.name AS tenant_name FROM security_events se LEFT JOIN users u ON u.id = se.user_id LEFT JOIN tenants t ON t.id = se.tenant_id ORDER BY se.id DESC LIMIT 40'),
            'sessions' => $this->fetchAll('SELECT us.*, u.name AS user_name, u.email FROM user_sessions us INNER JOIN users u ON u.id = us.user_id ORDER BY us.last_seen_at DESC LIMIT 30'),
            'settings' => [
                'attempt_limit' => (int) Env::get('SECURITY_LOGIN_ATTEMPT_LIMIT', 6),
                'attempt_window' => (int) Env::get('SECURITY_LOGIN_ATTEMPT_WINDOW_MINUTES', 15),
                'idle_minutes' => (int) Env::get('SECURITY_SESSION_IDLE_MINUTES', Env::get('SESSION_LIFETIME', 120)),
                'webhook_strict' => filter_var(Env::get('SECURITY_WEBHOOK_STRICT', false), FILTER_VALIDATE_BOOL),
                'headers_enabled' => filter_var(Env::get('SECURITY_HEADERS_ENABLED', true), FILTER_VALIDATE_BOOL),
            ],
        ];
    }

    public function verifyWebhookToken(string $type, ?string $providedToken, ?string $expectedToken): bool
    {
        $strict = filter_var(Env::get('SECURITY_WEBHOOK_STRICT', false), FILTER_VALIDATE_BOOL);
        if (!$strict) {
            return true;
        }
        if ($expectedToken === null || $expectedToken === '') {
            $this->recordEvent('webhook.token_missing_config', 'error', ['type' => $type]);
            return false;
        }
        $ok = is_string($providedToken) && hash_equals($expectedToken, $providedToken);
        $this->recordEvent($ok ? 'webhook.token_valid' : 'webhook.token_invalid', $ok ? 'info' : 'critical', ['type' => $type]);
        return $ok;
    }

    private function count(string $sql): int
    {
        try {
            return (int) Database::connection()->query($sql)->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }

    private function fetchAll(string $sql): array
    {
        try {
            return Database::connection()->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    private function apiKeyWarnings(): array
    {
        $warnings = [];
        foreach (['EVOLUTION_DEFAULT_API_KEY', 'OPENAI_API_KEY', 'N8N_CALLBACK_TOKEN', 'BILLING_CRON_TOKEN'] as $key) {
            $value = (string) Env::get($key, '');
            if ($value === '' || str_contains($value, 'troque') || str_contains($value, 'SUA_CHAVE') || strlen($value) < 12) {
                $warnings[] = $key;
            }
        }
        return $warnings;
    }

    private function sanitizeContext(array $context): array
    {
        $sensitive = ['api_key', 'key', 'token', 'password', 'secret', 'authorization'];
        foreach ($context as $key => $value) {
            foreach ($sensitive as $needle) {
                if (str_contains(mb_strtolower((string) $key), $needle)) {
                    $context[$key] = '[mascarado]';
                    continue 2;
                }
            }
            if (is_array($value)) {
                $context[$key] = $this->sanitizeContext($value);
            }
        }
        return $context;
    }

    private function ipAddress(): string
    {
        $forwarded = trim((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
        if ($forwarded !== '') {
            return trim(explode(',', $forwarded)[0]);
        }
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    }
}
