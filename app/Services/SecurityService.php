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
                'attempt_window' => (int) Env::get('SECURITY_LOGIN_ATTEMPT_WINDOW_MINUTES', 15),
                'idle_minutes' => $idleMinutes,
                'webhook_strict' => filter_var(Env::get('SECURITY_WEBHOOK_STRICT', false), FILTER_VALIDATE_BOOL),
                'headers_enabled' => filter_var(Env::get('SECURITY_HEADERS_ENABLED', true), FILTER_VALIDATE_BOOL),
                'invoice_grace_days' => (int) ($access['invoice_grace_days'] ?? 5),
                'timezone' => date_default_timezone_get(),
                'database' => $this->databaseName(),
            ],
            'checked_at' => date('Y-m-d H:i:s'),
            'version' => '33.0-security-validation',
        ];
    }

    private function validationChecks(array $access): array
    {
        $tables = ['security_events', 'login_attempts', 'user_sessions', 'tenant_subscriptions', 'tenant_invoices'];
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
        $strict = filter_var(Env::get('SECURITY_WEBHOOK_STRICT', false), FILTER_VALIDATE_BOOL);
        $checks[] = ['label' => 'Headers de segurança', 'status' => $headers ? 'ok' : 'warning', 'detail' => $headers ? 'Configurados para todas as respostas.' : 'Desativados no ambiente.'];
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
            'status' => $n8nToken ? 'ok' : ($activeN8nFlows > 0 ? 'warning' : 'optional'),
            'detail' => $n8nToken
                ? 'Token global configurado para autenticar retornos do n8n ao RS Connect.'
                : ($activeN8nFlows > 0
                    ? 'Há fluxos n8n ativos e o callback global está sem token. Configure-o antes da produção.'
                    : 'Nenhum fluxo n8n ativo depende do callback global neste momento.'),
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
        $review[] = [
            'key' => 'AI_REPROCESS_CRON_TOKEN',
            'label' => 'Cron da fila da IA',
            'status' => $aiCronToken ? 'ok' : ($aiCronEnabled ? 'warning' : 'optional'),
            'detail' => $aiCronToken
                ? 'Token configurado para o reprocessamento automático da fila da IA.'
                : ($aiCronEnabled ? 'A rotina da fila está ativa, mas o endpoint externo não pode ser acionado com segurança sem este token.' : 'Rotina automática desativada; token opcional.'),
            'action' => '/central-operacao?tab=ai_reprocess',
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
        $forwarded = trim((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
        if ($forwarded !== '') {
            return trim(explode(',', $forwarded)[0]);
        }
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    }
}
