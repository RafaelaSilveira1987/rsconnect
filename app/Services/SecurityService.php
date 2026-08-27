<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Env;
use App\Core\RequestSecurity;
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


    public function loginLockState(string $email): array
    {
        $normalized = mb_strtolower(trim($email));
        $state = [
            'locked' => false,
            'locked_until' => null,
            'remaining_seconds' => 0,
            'failed_login_count' => 0,
            'user_id' => null,
        ];

        if ($normalized === '') {
            return $state;
        }

        try {
            $statement = Database::connection()->prepare(
                'SELECT id, failed_login_count, locked_until
                 FROM users
                 WHERE email = :email
                 LIMIT 1'
            );
            $statement->execute(['email' => $normalized]);
            $user = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$user) {
                return $state;
            }

            $state['user_id'] = (int) $user['id'];
            $state['failed_login_count'] = (int) ($user['failed_login_count'] ?? 0);
            $lockedUntil = $user['locked_until'] ?? null;
            if ($lockedUntil && strtotime((string) $lockedUntil) > time()) {
                $state['locked'] = true;
                $state['locked_until'] = (string) $lockedUntil;
                $state['remaining_seconds'] = max(1, strtotime((string) $lockedUntil) - time());
                return $state;
            }

            if ($lockedUntil) {
                Database::connection()->prepare(
                    'UPDATE users
                     SET failed_login_count = 0, locked_until = NULL, lock_reason = NULL
                     WHERE id = :id'
                )->execute(['id' => $user['id']]);
                $state['failed_login_count'] = 0;
            }
        } catch (Throwable) {
            // Migration ainda não aplicada: mantém comportamento anterior.
        }

        return $state;
    }

    public function applyFailedLoginLock(string $email): array
    {
        $normalized = mb_strtolower(trim($email));
        $limit = max(1, (int) Env::get('SECURITY_LOGIN_ATTEMPT_LIMIT', 6));
        $windowMinutes = max(1, (int) Env::get('SECURITY_LOGIN_ATTEMPT_WINDOW_MINUTES', 15));
        $state = $this->loginLockState($normalized);

        if (!empty($state['locked']) || empty($state['user_id'])) {
            return $state;
        }

        try {
            $pdo = Database::connection();
            $pdo->beginTransaction();
            $statement = $pdo->prepare(
                'SELECT id, failed_login_count, last_failed_login_at
                 FROM users
                 WHERE id = :id
                 FOR UPDATE'
            );
            $statement->execute(['id' => $state['user_id']]);
            $user = $statement->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                $pdo->rollBack();
                return $state;
            }

            $lastFailed = $user['last_failed_login_at'] ?? null;
            $count = (int) ($user['failed_login_count'] ?? 0);
            if (!$lastFailed || strtotime((string) $lastFailed) < time() - ($windowMinutes * 60)) {
                $count = 0;
            }
            $count++;
            $lockedUntil = $count >= $limit ? date('Y-m-d H:i:s', time() + ($windowMinutes * 60)) : null;

            $update = $pdo->prepare(
                'UPDATE users
                 SET failed_login_count = :failed_login_count,
                     last_failed_login_at = NOW(),
                     locked_until = :locked_until,
                     lock_reason = :lock_reason
                 WHERE id = :id'
            );
            $update->execute([
                'failed_login_count' => $count,
                'locked_until' => $lockedUntil,
                'lock_reason' => $lockedUntil ? 'too_many_failed_logins' : null,
                'id' => $user['id'],
            ]);
            $pdo->commit();

            $state['failed_login_count'] = $count;
            $state['locked'] = $lockedUntil !== null;
            $state['locked_until'] = $lockedUntil;
            $state['remaining_seconds'] = $lockedUntil ? $windowMinutes * 60 : 0;

            if ($lockedUntil) {
                $this->recordEvent('auth.user_temporarily_locked', 'critical', [
                    'email' => $normalized,
                    'failed_login_count' => $count,
                    'locked_until' => $lockedUntil,
                ], null, (int) $user['id']);
            }
        } catch (Throwable) {
            if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }

        return $state;
    }

    public function resetLoginFailures(string $email): void
    {
        try {
            Database::connection()->prepare(
                'UPDATE users
                 SET failed_login_count = 0,
                     last_failed_login_at = NULL,
                     locked_until = NULL,
                     lock_reason = NULL
                 WHERE email = :email'
            )->execute(['email' => mb_strtolower(trim($email))]);
        } catch (Throwable) {
            // Migration ainda não aplicada.
        }
    }

    public function unlockUser(int $userId): void
    {
        try {
            Database::connection()->prepare(
                'UPDATE users
                 SET failed_login_count = 0,
                     last_failed_login_at = NULL,
                     locked_until = NULL,
                     lock_reason = NULL
                 WHERE id = :id'
            )->execute(['id' => $userId]);
            $this->recordEvent('auth.user_unlocked_by_admin', 'warning', ['unlocked_user_id' => $userId]);
        } catch (Throwable) {
            // Migration ainda não aplicada.
        }
    }

    public function lockMessage(array $state): string
    {
        $seconds = max(1, (int) ($state['remaining_seconds'] ?? 0));
        $minutes = max(1, (int) ceil($seconds / 60));
        return 'Acesso temporariamente bloqueado após várias tentativas incorretas. Tente novamente em cerca de ' . $minutes . ' minuto(s).';
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

    public function tooManyFailedLoginAttemptsFromIp(): bool
    {
        $limit = max(5, (int) Env::get('SECURITY_LOGIN_IP_LIMIT', 30));
        $windowMinutes = max(1, (int) Env::get('SECURITY_LOGIN_ATTEMPT_WINDOW_MINUTES', 15));

        try {
            $statement = Database::connection()->prepare(
                'SELECT COUNT(*)
                 FROM login_attempts
                 WHERE success = 0
                   AND ip_address = :ip
                   AND created_at >= (NOW() - INTERVAL ' . $windowMinutes . ' MINUTE)'
            );
            $statement->execute(['ip' => $this->ipAddress()]);
            return (int) $statement->fetchColumn() >= $limit;
        } catch (Throwable) {
            return false;
        }
    }

    public function registerSession(int $userId): void
    {
        $now = time();
        $_SESSION['last_activity_at'] = $now;
        $_SESSION['security_session_id'] = session_id();
        $_SESSION['security_session_created_at'] = (int) ($_SESSION['security_session_created_at'] ?? $now);
        $_SESSION['security_session_rotated_at'] = $now;
        $_SESSION['security_user_agent_hash'] = $this->userAgentHash();

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
                'expires_at' => date('Y-m-d H:i:s', $now + ((int) Env::get('SESSION_LIFETIME', 120) * 60)),
            ]);
        } catch (Throwable) {
            // Ignora se as tabelas ainda não existem.
        }
    }

    public function touchSession(): void
    {
        $_SESSION['last_activity_at'] = time();
        try {
            $statement = Database::connection()->prepare(
                'UPDATE user_sessions
                 SET last_seen_at = NOW(), expires_at = :expires_at
                 WHERE session_id = :session_id AND revoked_at IS NULL'
            );
            $statement->execute([
                'session_id' => session_id(),
                'expires_at' => date('Y-m-d H:i:s', time() + ((int) Env::get('SESSION_LIFETIME', 120) * 60)),
            ]);
        } catch (Throwable) {
            // Ignora se as tabelas ainda não existem.
        }
    }

    public function isCurrentSessionRevoked(): bool
    {
        try {
            $statement = Database::connection()->prepare('SELECT revoked_at, expires_at FROM user_sessions WHERE session_id = :session_id LIMIT 1');
            $statement->execute(['session_id' => session_id()]);
            $session = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$session) {
                return false;
            }
            if (!empty($session['revoked_at'])) {
                return true;
            }
            return !empty($session['expires_at']) && strtotime((string) $session['expires_at']) <= time();
        } catch (Throwable) {
            return false;
        }
    }

    public function closeCurrentSession(): void
    {
        try {
            Database::connection()->prepare(
                'UPDATE user_sessions SET revoked_at = NOW(), last_seen_at = NOW() WHERE session_id = :session_id'
            )->execute(['session_id' => session_id()]);
            $this->recordEvent('auth.session_closed', 'info');
        } catch (Throwable) {
            // Ignora se as tabelas ainda não existem.
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

        if (!Auth::refreshUser()) {
            $this->recordEvent('auth.session_principal_disabled', 'critical');
            $this->closeCurrentSession();
            Auth::logout();
            return false;
        }

        if (filter_var(Env::get('SECURITY_SESSION_BIND_USER_AGENT', true), FILTER_VALIDATE_BOOL)) {
            $storedHash = (string) ($_SESSION['security_user_agent_hash'] ?? '');
            $currentHash = $this->userAgentHash();
            if ($storedHash !== '' && !hash_equals($storedHash, $currentHash)) {
                $this->recordEvent('auth.session_user_agent_mismatch', 'critical');
                $this->closeCurrentSession();
                Auth::logout();
                return false;
            }
            $_SESSION['security_user_agent_hash'] = $currentHash;
        }

        $idleMinutes = max(5, (int) Env::get('SECURITY_SESSION_IDLE_MINUTES', Env::get('SESSION_LIFETIME', 120)));
        $lastActivity = (int) ($_SESSION['last_activity_at'] ?? time());
        if ((time() - $lastActivity) > ($idleMinutes * 60)) {
            $this->recordEvent('auth.session_idle_expired', 'warning');
            $this->closeCurrentSession();
            Auth::logout();
            return false;
        }

        $absoluteMinutes = max($idleMinutes, (int) Env::get('SECURITY_SESSION_ABSOLUTE_MINUTES', 720));
        $createdAt = (int) ($_SESSION['security_session_created_at'] ?? time());
        $_SESSION['security_session_created_at'] = $createdAt;
        if ((time() - $createdAt) > ($absoluteMinutes * 60)) {
            $this->recordEvent('auth.session_absolute_expired', 'warning');
            $this->closeCurrentSession();
            Auth::logout();
            return false;
        }

        $rotateMinutes = max(5, (int) Env::get('SECURITY_SESSION_ROTATE_MINUTES', 30));
        $rotatedAt = (int) ($_SESSION['security_session_rotated_at'] ?? $createdAt);
        if ((time() - $rotatedAt) > ($rotateMinutes * 60)) {
            $this->rotateCurrentSession();
        }

        $this->touchSession();
        return true;
    }

    /**
     * Limita tamanho e frequência de webhooks antes do controller processar JSON,
     * anexos ou integrações externas.
     *
     * @return array{allowed:bool,status?:int,message?:string,retry_after?:int}
     */
    public function guardWebhookRequest(string $path): array
    {
        $maxBytes = max(65536, (int) Env::get('SECURITY_WEBHOOK_MAX_BYTES', 5242880));
        $contentLength = max(0, (int) ($_SERVER['CONTENT_LENGTH'] ?? 0));
        if ($contentLength > $maxBytes) {
            $this->recordEvent('webhook.payload_too_large', 'critical', [
                'path' => $path,
                'content_length' => $contentLength,
                'max_bytes' => $maxBytes,
            ]);
            return ['allowed' => false, 'status' => 413, 'message' => 'Payload acima do limite permitido.'];
        }

        $isCron = str_contains($path, '/run') || str_contains($path, '/dispatch');
        $limit = $isCron
            ? max(5, (int) Env::get('SECURITY_CRON_RATE_LIMIT_PER_MINUTE', 60))
            : max(30, (int) Env::get('SECURITY_WEBHOOK_RATE_LIMIT_PER_MINUTE', 600));
        $scope = ($isCron ? 'cron:' : 'webhook:') . $path;
        $rate = $this->consumeRateLimit($scope, $limit, 60);
        if (!$rate['allowed']) {
            $this->recordEvent('webhook.rate_limited', 'critical', [
                'path' => $path,
                'limit' => $limit,
                'retry_after' => $rate['retry_after'],
            ]);
            return [
                'allowed' => false,
                'status' => 429,
                'message' => 'Muitas requisições. Tente novamente em instantes.',
                'retry_after' => $rate['retry_after'],
            ];
        }

        return ['allowed' => true];
    }

    private function rotateCurrentSession(): void
    {
        $oldSessionId = session_id();
        try {
            Database::connection()->prepare(
                'UPDATE user_sessions SET revoked_at = NOW(), last_seen_at = NOW() WHERE session_id = :session_id'
            )->execute(['session_id' => $oldSessionId]);
        } catch (Throwable) {
            // A rotação local continua mesmo se o registro histórico estiver indisponível.
        }

        session_regenerate_id(true);
        $_SESSION['security_session_rotated_at'] = time();
        $_SESSION['security_session_id'] = session_id();
        if (Auth::id()) {
            $this->registerSession((int) Auth::id());
        }
        $this->recordEvent('auth.session_rotated', 'info', [
            'previous_session_id' => substr($oldSessionId, 0, 12) . '...',
        ]);
    }

    /** @return array{allowed:bool,retry_after:int} */
    private function consumeRateLimit(string $scope, int $limit, int $windowSeconds): array
    {
        $bucketKey = hash('sha256', $scope . '|' . $this->ipAddress());
        $now = time();
        $retryAfter = $windowSeconds;

        try {
            $pdo = Database::connection();
            $pdo->beginTransaction();
            $statement = $pdo->prepare(
                'SELECT bucket_key, window_started_at, hits, blocked_until
                 FROM security_rate_limits
                 WHERE bucket_key = :bucket_key
                 FOR UPDATE'
            );
            $statement->execute(['bucket_key' => $bucketKey]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                $insert = $pdo->prepare(
                    'INSERT INTO security_rate_limits
                        (bucket_key, scope, ip_address, window_started_at, hits, blocked_until, updated_at)
                     VALUES
                        (:bucket_key, :scope, :ip_address, NOW(), 1, NULL, NOW())'
                );
                $insert->execute([
                    'bucket_key' => $bucketKey,
                    'scope' => mb_substr($scope, 0, 190),
                    'ip_address' => $this->ipAddress(),
                ]);
                $pdo->commit();
                return ['allowed' => true, 'retry_after' => 0];
            }

            $blockedUntil = !empty($row['blocked_until']) ? strtotime((string) $row['blocked_until']) : 0;
            if ($blockedUntil > $now) {
                $pdo->commit();
                return ['allowed' => false, 'retry_after' => max(1, $blockedUntil - $now)];
            }

            $windowStartedAt = strtotime((string) ($row['window_started_at'] ?? '')) ?: 0;
            $hits = (int) ($row['hits'] ?? 0);
            if ($windowStartedAt <= 0 || ($now - $windowStartedAt) >= $windowSeconds) {
                $hits = 1;
                $windowStartedAt = $now;
                $blockedUntilSql = null;
            } else {
                $hits++;
                $blockedUntilSql = $hits > $limit ? date('Y-m-d H:i:s', $windowStartedAt + $windowSeconds) : null;
                $retryAfter = max(1, ($windowStartedAt + $windowSeconds) - $now);
            }

            $update = $pdo->prepare(
                'UPDATE security_rate_limits
                 SET scope = :scope,
                     ip_address = :ip_address,
                     window_started_at = :window_started_at,
                     hits = :hits,
                     blocked_until = :blocked_until,
                     updated_at = NOW()
                 WHERE bucket_key = :bucket_key'
            );
            $update->execute([
                'scope' => mb_substr($scope, 0, 190),
                'ip_address' => $this->ipAddress(),
                'window_started_at' => date('Y-m-d H:i:s', $windowStartedAt),
                'hits' => $hits,
                'blocked_until' => $blockedUntilSql,
                'bucket_key' => $bucketKey,
            ]);
            $pdo->commit();

            return ['allowed' => $blockedUntilSql === null, 'retry_after' => $blockedUntilSql === null ? 0 : $retryAfter];
        } catch (Throwable) {
            if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // Fail-open para não derrubar webhooks caso a migration ainda não tenha sido aplicada.
            return ['allowed' => true, 'retry_after' => 0];
        }
    }

    private function userAgentHash(): string
    {
        return hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    }

    public function dashboard(): array
    {
        $idleMinutes = max(5, (int) Env::get('SECURITY_SESSION_IDLE_MINUTES', Env::get('SESSION_LIFETIME', 120)));
        $access = (new AccessControlService())->securitySummary();
        $credentialReview = $this->credentialReview();

        return [
            'failed_logins_24h' => $this->count('SELECT COUNT(*) FROM login_attempts WHERE success = 0 AND created_at >= (NOW() - INTERVAL 1 DAY)'),
            'successful_logins_24h' => $this->count('SELECT COUNT(*) FROM login_attempts WHERE success = 1 AND created_at >= (NOW() - INTERVAL 1 DAY)'),
            'active_sessions' => $this->count(
                'SELECT COUNT(*) FROM user_sessions
                 WHERE revoked_at IS NULL
                   AND (expires_at IS NULL OR expires_at > NOW())
                   AND last_seen_at >= (NOW() - INTERVAL ' . $idleMinutes . ' MINUTE)'
            ),
            'expired_sessions' => $this->count(
                'SELECT COUNT(*) FROM user_sessions
                 WHERE revoked_at IS NULL
                   AND ((expires_at IS NOT NULL AND expires_at <= NOW())
                        OR last_seen_at < (NOW() - INTERVAL ' . $idleMinutes . ' MINUTE))'
            ),
            'critical_events_7d' => $this->count("SELECT COUNT(*) FROM security_events WHERE severity IN ('critical','error') AND created_at >= (NOW() - INTERVAL 7 DAY)"),
            'webhook_events_24h' => $this->count("SELECT COUNT(*) FROM security_events WHERE event LIKE 'webhook.%' AND created_at >= (NOW() - INTERVAL 1 DAY)"),
            'api_key_warnings' => array_values(array_map(
                static fn (array $item): string => (string) ($item['key'] ?? ''),
                array_filter($credentialReview, static fn (array $item): bool => in_array((string) ($item['status'] ?? ''), ['warning', 'critical'], true))
            )),
            'credential_review' => $credentialReview,
            'login_attempts' => $this->fetchAll(
                'SELECT la.*, u.locked_until, u.failed_login_count
                 FROM login_attempts la
                 LEFT JOIN users u ON u.id = la.user_id
                 ORDER BY la.id DESC
                 LIMIT 30'
            ),
            'events' => $this->fetchAll('SELECT se.*, u.name AS user_name, t.name AS tenant_name FROM security_events se LEFT JOIN users u ON u.id = se.user_id LEFT JOIN tenants t ON t.id = se.tenant_id ORDER BY se.id DESC LIMIT 50'),
            'sessions' => $this->fetchAll(
                'SELECT us.*, u.name AS user_name, u.email,
                        CASE
                            WHEN us.revoked_at IS NOT NULL THEN "revoked"
                            WHEN us.expires_at IS NOT NULL AND us.expires_at <= NOW() THEN "expired"
                            WHEN us.last_seen_at < (NOW() - INTERVAL ' . $idleMinutes . ' MINUTE) THEN "idle_expired"
                            ELSE "active"
                        END AS session_status
                 FROM user_sessions us
                 INNER JOIN users u ON u.id = us.user_id
                 ORDER BY us.last_seen_at DESC
                 LIMIT 40'
            ),
            'locked_users' => $this->fetchAll(
                'SELECT id, tenant_id, name, email, failed_login_count, locked_until, lock_reason
                 FROM users
                 WHERE locked_until IS NOT NULL AND locked_until > NOW()
                 ORDER BY locked_until DESC'
            ),
            'access' => $access,
            'checks' => $this->validationChecks($access),
            'settings' => [
                'attempt_limit' => (int) Env::get('SECURITY_LOGIN_ATTEMPT_LIMIT', 6),
                'attempt_ip_limit' => (int) Env::get('SECURITY_LOGIN_IP_LIMIT', 30),
                'attempt_window' => (int) Env::get('SECURITY_LOGIN_ATTEMPT_WINDOW_MINUTES', 15),
                'idle_minutes' => $idleMinutes,
                'absolute_minutes' => (int) Env::get('SECURITY_SESSION_ABSOLUTE_MINUTES', 720),
                'rotate_minutes' => (int) Env::get('SECURITY_SESSION_ROTATE_MINUTES', 30),
                'csrf_ttl_minutes' => (int) Env::get('SECURITY_CSRF_TTL_MINUTES', 120),
                'webhook_strict' => filter_var(Env::get('SECURITY_WEBHOOK_STRICT', false), FILTER_VALIDATE_BOOL),
                'webhook_max_bytes' => (int) Env::get('SECURITY_WEBHOOK_MAX_BYTES', 5242880),
                'webhook_rate_limit' => (int) Env::get('SECURITY_WEBHOOK_RATE_LIMIT_PER_MINUTE', 600),
                'headers_enabled' => filter_var(Env::get('SECURITY_HEADERS_ENABLED', true), FILTER_VALIDATE_BOOL),
                'invoice_grace_days' => (int) ($access['invoice_grace_days'] ?? 5),
                'timezone' => date_default_timezone_get(),
                'database' => $this->databaseName(),
            ],
            'checked_at' => \App\Core\Clock::nowUtc(),
            'version' => '36.11.1-security-hardening',
        ];
    }

    private function validationChecks(array $access): array
    {
        $tables = ['security_events', 'login_attempts', 'user_sessions', 'security_rate_limits', 'tenant_subscriptions', 'tenant_invoices'];
        $checks = [];
        foreach ($tables as $table) {
            $exists = $this->tableExists($table);
            $checks[] = [
                'label' => 'Tabela ' . $table,
                'status' => $exists ? 'ok' : 'error',
                'detail' => $exists ? 'Disponível no banco.' : 'Não encontrada. Execute a migration correspondente.',
            ];
        }

        $headers = filter_var(Env::get('SECURITY_HEADERS_ENABLED', true), FILTER_VALIDATE_BOOL);
        $strict = (new WebhookSecurityService())->strictMode();
        $checks[] = ['label' => 'Headers de segurança', 'status' => $headers ? 'ok' : 'warning', 'detail' => $headers ? 'Configurados para todas as respostas.' : 'Desativados no ambiente.'];
        $checks[] = ['label' => 'Sessão PHP em modo estrito', 'status' => ini_get('session.use_strict_mode') === '1' ? 'ok' : 'error', 'detail' => ini_get('session.use_strict_mode') === '1' ? 'IDs de sessão não inicializados são recusados.' : 'Ative session.use_strict_mode.'];
        $checks[] = ['label' => 'Cookie de sessão protegido', 'status' => (ini_get('session.cookie_httponly') === '1' && ini_get('session.use_only_cookies') === '1') ? 'ok' : 'error', 'detail' => 'HttpOnly e uso exclusivo de cookies devem permanecer ativos.'];
        $checks[] = ['label' => 'Tokens obrigatórios em webhooks', 'status' => $strict ? 'ok' : 'warning', 'detail' => $strict ? 'Validação estrita ativada.' : 'Validação estrita desativada; revisar antes de produção.'];
        $checks[] = ['label' => 'Bloqueio por vigência', 'status' => 'ok', 'detail' => (int) ($access['expired_subscriptions'] ?? 0) . ' assinatura(s) vencida(s) identificada(s).'];
        $checks[] = ['label' => 'Bloqueio por inadimplência', 'status' => 'ok', 'detail' => 'Tolerância configurada em ' . (int) ($access['invoice_grace_days'] ?? 5) . ' dia(s).'];
        $checks[] = ['label' => 'Bloqueio de login', 'status' => 'ok', 'detail' => (int) ($access['locked_users'] ?? 0) . ' usuário(s) bloqueado(s) neste momento.'];
        return $checks;
    }

    private function tableExists(string $table): bool
    {
        try {
            $statement = Database::connection()->prepare(
                'SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = :table'
            );
            $statement->execute(['table' => $table]);
            return (int) $statement->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    private function databaseName(): string
    {
        try {
            return (string) Database::connection()->query('SELECT DATABASE()')->fetchColumn();
        } catch (Throwable) {
            return 'indisponível';
        }
    }

    public function verifyWebhookToken(string $type, ?string $providedToken, ?string $expectedToken): bool
    {
        $strict = (new WebhookSecurityService())->strictMode();
        $expected = trim((string) $expectedToken);
        if ($expected === '') {
            $this->recordEvent('webhook.token_missing_config', 'error', ['type' => $type]);
            return !$strict && (new WebhookSecurityService())->allowInsecureLocal();
        }

        $provided = trim((string) $providedToken);
        $ok = $provided !== '' && hash_equals($expected, $provided);
        if (!$ok) {
            $this->recordEvent('webhook.token_invalid', 'critical', ['type' => $type]);
        }
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

    private function credentialReview(): array
    {
        $review = [];

        $openAiGlobal = $this->secretConfigured('OPENAI_API_KEY');
        $activeAiCredentials = $this->tableExists('ai_provider_credentials')
            ? $this->count("SELECT COUNT(*) FROM ai_provider_credentials WHERE status = 'active' AND api_key_encrypted IS NOT NULL AND api_key_encrypted <> ''")
            : 0;
        $review[] = [
            'key' => 'OPENAI_API_KEY',
            'label' => 'OpenAI / IA',
            'status' => ($openAiGlobal || $activeAiCredentials > 0) ? 'ok' : 'warning',
            'detail' => $openAiGlobal
                ? 'Chave global configurada no ambiente.'
                : ($activeAiCredentials > 0
                    ? $activeAiCredentials . ' credencial(is) ativa(s) por empresa/assistente. A chave global é opcional.'
                    : 'Nenhuma chave global nem credencial ativa por empresa foi encontrada.'),
            'action' => '/ai-credentials',
        ];

        $n8nToken = $this->secretConfigured('N8N_CALLBACK_TOKEN');
        $activeN8nFlows = ($this->tableExists('n8n_tenant_flows') ? $this->count("SELECT COUNT(*) FROM n8n_tenant_flows WHERE status = 'active'") : 0)
            + ($this->tableExists('n8n_flows') ? $this->count("SELECT COUNT(*) FROM n8n_flows WHERE status = 'active'") : 0);
        $review[] = [
            'key' => 'N8N_CALLBACK_TOKEN',
            'label' => 'Callback global do n8n',
            'status' => $n8nToken ? 'ok' : ($activeN8nFlows > 0 ? 'recommended' : 'optional'),
            'detail' => $n8nToken
                ? 'Token global configurado para autenticar retornos do n8n ao RS Connect.'
                : ($activeN8nFlows > 0
                    ? $activeN8nFlows . ' fluxo(s) n8n ativo(s). O callback continua disponível, mas sem autenticação global; configure o token como reforço de segurança para validar os retornos.'
                    : 'Nenhum fluxo n8n ativo utiliza o callback global neste momento. O token é opcional até esse recurso ser usado.'),
            'action' => '/n8n',
        ];

        $calendarToken = $this->secretConfigured('CALENDAR_MAINTENANCE_TOKEN');
        $calendarEnabled = $this->tableExists('tenant_calendar_availability_settings')
            ? $this->count('SELECT COUNT(*) FROM tenant_calendar_availability_settings WHERE enabled = 1')
            : 0;
        $review[] = [
            'key' => 'CALENDAR_MAINTENANCE_TOKEN',
            'label' => 'Manutenção automática da agenda',
            'status' => $calendarToken ? 'ok' : ($calendarEnabled > 0 ? 'recommended' : 'optional'),
            'detail' => $calendarToken
                ? 'Token configurado para o endpoint de manutenção automática da agenda.'
                : ($calendarEnabled > 0
                    ? 'A agenda está ativa. O token só é necessário para manutenção via cron; a manutenção manual continua protegida pelo login.'
                    : 'Token opcional enquanto não houver manutenção automática da agenda por cron.'),
            'action' => '/calendar/availability',
        ];

        $billingToken = $this->secretConfigured('BILLING_CRON_TOKEN');
        $activeBillingRules = $this->tableExists('billing_reminder_rules')
            ? $this->count("SELECT COUNT(*) FROM billing_reminder_rules WHERE status = 'active'")
            : 0;
        $review[] = [
            'key' => 'BILLING_CRON_TOKEN',
            'label' => 'Cron da régua de cobrança',
            'status' => $billingToken ? 'ok' : ($activeBillingRules > 0 ? 'warning' : 'optional'),
            'detail' => $billingToken
                ? 'Token configurado para o acionamento automático da régua de cobrança.'
                : ($activeBillingRules > 0 ? 'Há regras de cobrança ativas, mas o cron externo não pode executar sem este token.' : 'Sem regras ativas; token opcional neste momento.'),
            'action' => '/billing-reminders',
        ];

        $aiCronToken = $this->secretConfigured('AI_REPROCESS_CRON_TOKEN');
        $aiCronEnabled = false;
        if ($this->tableExists('ai_reprocess_settings')) {
            $aiCronEnabled = $this->count('SELECT COUNT(*) FROM ai_reprocess_settings WHERE id = 1 AND enabled = 1') > 0;
        }
        $agentsWithCooldown = $this->tableExists('ai_agents')
            ? $this->count('SELECT COUNT(*) FROM ai_agents WHERE status = "active" AND auto_reply_enabled = 1 AND COALESCE(cooldown_seconds, 0) > 0')
            : 0;
        $review[] = [
            'key' => 'AI_REPROCESS_CRON_TOKEN',
            'label' => 'Fila rápida da IA',
            'status' => $aiCronToken ? 'ok' : ($agentsWithCooldown > 0 ? 'warning' : ($aiCronEnabled ? 'recommended' : 'optional')),
            'detail' => $aiCronToken
                ? 'Token configurado para a fila rápida e para a contingência agendada da IA.'
                : ($agentsWithCooldown > 0
                    ? $agentsWithCooldown . ' assistente(s) ativo(s) usam intervalo mínimo. Sem este token o n8n não consegue reavaliar automaticamente as mensagens quando a espera termina.'
                    : ($aiCronEnabled
                        ? 'A contingência diária está habilitada. Configure o token para permitir acionamento externo seguro pelo n8n.'
                        : 'Nenhum assistente ativo depende de fila rápida neste momento; token opcional até habilitar cooldown/acionamento externo.')),
            'action' => '/central-operacao?tab=ai_reprocess',
        ];

        $webhookStrict = filter_var(Env::get('SECURITY_WEBHOOK_STRICT', false), FILTER_VALIDATE_BOOL);
        $evolutionWebhookToken = $this->secretConfigured('EVOLUTION_WEBHOOK_TOKEN');
        $review[] = [
            'key' => 'EVOLUTION_WEBHOOK_TOKEN',
            'label' => 'Autenticação do webhook Evolution',
            'status' => $evolutionWebhookToken ? 'ok' : ($webhookStrict ? 'critical' : 'recommended'),
            'detail' => $evolutionWebhookToken
                ? 'Token configurado para validar mensagens recebidas da Evolution.'
                : ($webhookStrict
                    ? 'Modo estrito ativo sem token: mensagens recebidas da Evolution serão recusadas.'
                    : 'Configure o token e atualize o webhook da Evolution antes de ativar o modo estrito.'),
            'action' => '/instances',
        ];

        $activePaymentGateways = $this->tableExists('payment_gateways')
            ? $this->count("SELECT COUNT(*) FROM payment_gateways WHERE status = 'active' AND provider <> 'manual'")
            : 0;
        $paymentGatewaysWithSecret = $this->tableExists('payment_gateways')
            ? $this->count("SELECT COUNT(*) FROM payment_gateways WHERE status = 'active' AND provider <> 'manual' AND webhook_secret_encrypted IS NOT NULL AND webhook_secret_encrypted <> ''")
            : 0;
        $missingPaymentSecrets = max(0, $activePaymentGateways - $paymentGatewaysWithSecret);
        $review[] = [
            'key' => 'PAYMENT_WEBHOOK_SECRETS',
            'label' => 'Segredos dos webhooks de pagamento',
            'status' => $missingPaymentSecrets === 0 ? 'ok' : ($webhookStrict ? 'critical' : 'recommended'),
            'detail' => $activePaymentGateways === 0
                ? 'Nenhum gateway automático ativo.'
                : ($missingPaymentSecrets === 0
                    ? 'Todos os gateways automáticos ativos possuem segredo de webhook.'
                    : $missingPaymentSecrets . ' gateway(s) ativo(s) ainda não possuem segredo de webhook.'),
            'action' => '/payment-gateways',
        ];

        $globalEvolution = $this->secretConfigured('EVOLUTION_DEFAULT_API_KEY');
        $instanceCount = $this->tableExists('evolution_instances') ? $this->count('SELECT COUNT(*) FROM evolution_instances') : 0;
        $instancesWithKey = $this->tableExists('evolution_instances')
            ? $this->count("SELECT COUNT(*) FROM evolution_instances WHERE api_key_encrypted IS NOT NULL AND api_key_encrypted <> ''")
            : 0;
        $review[] = [
            'key' => 'EVOLUTION_DEFAULT_API_KEY',
            'label' => 'Evolution / WhatsApp',
            'status' => ($globalEvolution || ($instanceCount > 0 && $instancesWithKey >= $instanceCount)) ? 'ok' : ($instanceCount > 0 ? 'warning' : 'optional'),
            'detail' => $globalEvolution
                ? 'Chave padrão da Evolution configurada no ambiente.'
                : ($instanceCount > 0 && $instancesWithKey >= $instanceCount
                    ? 'Todas as instâncias possuem chave própria protegida; a chave global é opcional.'
                    : ($instanceCount > 0 ? 'Existem instâncias sem chave própria e não há chave global de fallback.' : 'Nenhuma instância cadastrada; chave global opcional.')),
            'action' => '/instances',
        ];

        return $review;
    }

    private function secretConfigured(string $key): bool
    {
        $value = trim((string) Env::get($key, ''));
        if ($value === '') {
            return false;
        }
        $normalized = mb_strtolower($value);
        foreach (['troque', 'sua_chave', 'seu_token', 'cole_aqui', 'change_me'] as $placeholder) {
            if (str_contains($normalized, $placeholder)) {
                return false;
            }
        }
        return mb_strlen($value) >= 12;
    }

    private function apiKeyWarnings(): array
    {
        return array_values(array_map(
            static fn (array $item): string => (string) ($item['key'] ?? ''),
            array_filter($this->credentialReview(), static fn (array $item): bool => in_array((string) ($item['status'] ?? ''), ['warning', 'critical'], true))
        ));
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
        return RequestSecurity::clientIp();
    }
}
