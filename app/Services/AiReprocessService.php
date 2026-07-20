<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Env;
use App\Core\Router;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Throwable;

final class AiReprocessService
{
    private const GLOBAL_LOCK = 'rs_ai_reprocess_all';

    public function __construct(
        private readonly AiAutomationService $automation = new AiAutomationService(),
    ) {
    }

    public function dashboard(): array
    {
        try {
            $settings = $this->settings();

            return [
                'migration_required' => false,
                'settings' => $settings,
                'pending' => $this->pendingByTenant(),
                'pending_total' => $this->pendingTotal(),
                'history' => $this->history(),
                'cron_url' => Router::url('/webhooks/ai-reprocess/run'),
                'cron_token_configured' => trim((string) Env::get('AI_REPROCESS_CRON_TOKEN', '')) !== '',
            ];
        } catch (Throwable $exception) {
            return [
                'migration_required' => true,
                'migration' => 'database/migrations/043_ai_reprocess_schedule.sql',
                'error' => $exception->getMessage(),
                'settings' => $this->defaultSettings(),
                'pending' => [],
                'pending_total' => 0,
                'history' => [],
                'cron_url' => Router::url('/webhooks/ai-reprocess/run'),
                'cron_token_configured' => trim((string) Env::get('AI_REPROCESS_CRON_TOKEN', '')) !== '',
            ];
        }
    }

    public function settings(): array
    {
        $pdo = Database::connection();
        $statement = $pdo->query('SELECT * FROM ai_reprocess_settings WHERE id = 1 LIMIT 1');
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $defaults = $this->defaultSettings();
            $insert = $pdo->prepare(
                'INSERT INTO ai_reprocess_settings
                    (id, enabled, run_time, timezone, max_messages_per_run)
                 VALUES
                    (1, :enabled, :run_time, :timezone, :max_messages_per_run)'
            );
            $insert->execute([
                'enabled' => $defaults['enabled'],
                'run_time' => $defaults['run_time'],
                'timezone' => $defaults['timezone'],
                'max_messages_per_run' => $defaults['max_messages_per_run'],
            ]);
            return $defaults;
        }

        $row['enabled'] = (int) ($row['enabled'] ?? 0);
        $row['max_messages_per_run'] = (int) ($row['max_messages_per_run'] ?? 100);
        $row['run_time'] = substr((string) ($row['run_time'] ?? '03:00:00'), 0, 5);
        $row['timezone'] = (string) ($row['timezone'] ?? 'America/Sao_Paulo');
        $row['last_summary'] = $this->decodeJson($row['last_summary_json'] ?? null);

        return $row;
    }

    public function saveSettings(bool $enabled, string $runTime, string $timezone, int $maxMessages, ?int $userId): array
    {
        $runTime = trim($runTime);
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $runTime)) {
            throw new RuntimeException('Informe um horário válido no formato HH:MM.');
        }

        try {
            new DateTimeZone($timezone);
        } catch (Throwable) {
            throw new RuntimeException('O fuso horário informado é inválido.');
        }

        $maxMessages = max(1, min(1000, $maxMessages));
        $pdo = Database::connection();
        $statement = $pdo->prepare(
            'INSERT INTO ai_reprocess_settings
                (id, enabled, run_time, timezone, max_messages_per_run, updated_by)
             VALUES
                (1, :enabled, :run_time, :timezone, :max_messages_per_run, :updated_by)
             ON DUPLICATE KEY UPDATE
                enabled = VALUES(enabled),
                run_time = VALUES(run_time),
                timezone = VALUES(timezone),
                max_messages_per_run = VALUES(max_messages_per_run),
                updated_by = VALUES(updated_by),
                updated_at = CURRENT_TIMESTAMP'
        );
        $statement->execute([
            'enabled' => $enabled ? 1 : 0,
            'run_time' => $runTime . ':00',
            'timezone' => $timezone,
            'max_messages_per_run' => $maxMessages,
            'updated_by' => $userId,
        ]);

        return $this->settings();
    }

    public function runAll(string $source = 'manual', ?int $userId = null, ?int $limit = null): array
    {
        $pdo = Database::connection();
        if (!$this->acquireLock($pdo, self::GLOBAL_LOCK)) {
            return [
                'status' => 'busy',
                'message' => 'Já existe uma execução de reprocessamento em andamento.',
                'attempted' => 0,
                'replied' => 0,
                'evaluated' => 0,
                'errors' => 0,
                'pending_after' => $this->safePendingTotal(),
            ];
        }

        $runId = 0;
        $startedAt = microtime(true);
        $summary = [
            'status' => 'error',
            'source' => $source,
            'attempted' => 0,
            'replied' => 0,
            'evaluated' => 0,
            'errors' => 0,
            'agents_checked' => 0,
            'companies_checked' => 0,
            'limit' => 0,
            'pending_before' => 0,
            'pending_after' => 0,
        ];

        try {
            $settings = $this->settings();
            $limit = max(1, min(1000, $limit ?? (int) ($settings['max_messages_per_run'] ?? 100)));
            $summary['limit'] = $limit;
            $summary['pending_before'] = $this->pendingTotal();
            $runId = $this->startRun($source, $userId);

            $agents = $this->activeAgents();
            $summary['agents_checked'] = count($agents);
            $summary['companies_checked'] = count(array_unique(array_map(
                static fn (array $agent): int => (int) $agent['tenant_id'],
                $agents
            )));

            $blockedAgents = [];
            do {
                $progress = false;

                foreach ($agents as $agent) {
                    if ($summary['attempted'] >= $limit) {
                        break 2;
                    }

                    $agentId = (int) $agent['id'];
                    if (isset($blockedAgents[$agentId])) {
                        continue;
                    }

                    $result = $this->automation->reprocessLatestPendingForAgent(
                        (int) $agent['tenant_id'],
                        $agentId,
                        $source
                    );
                    $status = (string) ($result['status'] ?? 'error');

                    if ($status === 'none' || $status === 'busy') {
                        continue;
                    }

                    $progress = true;
                    $summary['attempted']++;

                    if ($status === 'replied') {
                        $summary['replied']++;
                    } elseif ($status === 'evaluated') {
                        $summary['evaluated']++;
                    } else {
                        $summary['errors']++;
                        $blockedAgents[$agentId] = true;
                    }
                }
            } while ($progress && $summary['attempted'] < $limit);

            $summary['pending_after'] = $this->pendingTotal();
            $summary['status'] = 'success';
            if ($summary['errors'] > 0) {
                $summary['status'] = $summary['replied'] > 0 || $summary['evaluated'] > 0 ? 'partial' : 'error';
            } elseif ($summary['attempted'] >= $limit && $summary['pending_after'] > 0) {
                $summary['status'] = 'partial';
            }

            $summary['duration_ms'] = (int) round((microtime(true) - $startedAt) * 1000);
            $this->finishRun($runId, $summary, null);
            $this->updateLastRun($summary, $source);

            return $summary;
        } catch (Throwable $exception) {
            $summary['status'] = 'error';
            $summary['pending_after'] = $this->safePendingTotal();
            $summary['duration_ms'] = (int) round((microtime(true) - $startedAt) * 1000);
            if ($runId > 0) {
                $this->finishRun($runId, $summary, $exception->getMessage());
            }
            try {
                $this->updateLastRun($summary, $source, $exception->getMessage());
            } catch (Throwable) {
                // Preserva a exceção original quando a própria auditoria falhar.
            }
            throw $exception;
        } finally {
            $this->releaseLock($pdo, self::GLOBAL_LOCK);
        }
    }

    public function runScheduledIfDue(?DateTimeImmutable $now = null): array
    {
        $settings = $this->settings();
        if ((int) ($settings['enabled'] ?? 0) !== 1) {
            return ['status' => 'disabled', 'message' => 'Rotina automática desativada.'];
        }

        $timezone = new DateTimeZone((string) ($settings['timezone'] ?? 'America/Sao_Paulo'));
        $now = ($now ?? new DateTimeImmutable('now', $timezone))->setTimezone($timezone);
        $today = $now->format('Y-m-d');
        $target = new DateTimeImmutable($today . ' ' . ($settings['run_time'] ?? '03:00'), $timezone);

        if ($now < $target) {
            return [
                'status' => 'not_due',
                'message' => 'Ainda não chegou o horário configurado.',
                'scheduled_for' => $target->format(DATE_ATOM),
            ];
        }

        if ((string) ($settings['last_scheduled_run_on'] ?? '') === $today) {
            return ['status' => 'already_ran', 'message' => 'A rotina de hoje já foi executada.'];
        }

        $pdo = Database::connection();
        $claimLock = 'rs_ai_reprocess_schedule';
        if (!$this->acquireLock($pdo, $claimLock)) {
            return ['status' => 'busy', 'message' => 'Outra verificação de agenda está em andamento.'];
        }

        try {
            $pdo->beginTransaction();
            $statement = $pdo->query('SELECT enabled, last_scheduled_run_on FROM ai_reprocess_settings WHERE id = 1 FOR UPDATE');
            $current = $statement->fetch(PDO::FETCH_ASSOC) ?: [];

            if ((int) ($current['enabled'] ?? 0) !== 1) {
                $pdo->rollBack();
                return ['status' => 'disabled', 'message' => 'Rotina automática desativada.'];
            }
            if ((string) ($current['last_scheduled_run_on'] ?? '') === $today) {
                $pdo->rollBack();
                return ['status' => 'already_ran', 'message' => 'A rotina de hoje já foi executada.'];
            }

            $pdo->prepare(
                'UPDATE ai_reprocess_settings
                 SET last_scheduled_run_on = :run_on,
                     last_scheduled_claimed_at = NOW()
                 WHERE id = 1'
            )->execute(['run_on' => $today]);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        } finally {
            $this->releaseLock($pdo, $claimLock);
        }

        $result = $this->runAll('scheduled', null, (int) ($settings['max_messages_per_run'] ?? 100));
        if (($result['status'] ?? '') === 'busy') {
            Database::connection()->prepare(
                'UPDATE ai_reprocess_settings
                 SET last_scheduled_run_on = NULL
                 WHERE id = 1 AND last_scheduled_run_on = :run_on'
            )->execute(['run_on' => $today]);
        }

        return $result;
    }

    public function validCronToken(string $token): bool
    {
        $expected = trim((string) Env::get('AI_REPROCESS_CRON_TOKEN', ''));
        return $expected !== '' && $token !== '' && hash_equals($expected, $token);
    }

    public function pendingTotal(): int
    {
        $statement = Database::connection()->query(
            'SELECT COUNT(DISTINCT cm.conversation_id)
             FROM conversation_messages cm
             INNER JOIN conversations c
                ON c.id = cm.conversation_id
               AND c.tenant_id = cm.tenant_id
             INNER JOIN ai_agents a
                ON a.tenant_id = cm.tenant_id
               AND a.instance_id = c.evolution_instance_id
               AND a.status = "active"
               AND a.auto_reply_enabled = 1
             INNER JOIN tenants t ON t.id = cm.tenant_id AND t.status = "active"
             WHERE c.attendance_mode = "ai"
               AND c.status <> "closed"
               AND cm.direction = "incoming"
               AND (COALESCE(a.reply_to_reactions, 0) = 1 OR cm.message_type <> "reaction")
               AND (
                    SELECT al.event
                    FROM ai_automation_logs al
                    WHERE al.tenant_id = cm.tenant_id
                      AND al.conversation_id = cm.conversation_id
                      AND al.agent_id = a.id
                    ORDER BY al.id DESC
                    LIMIT 1
               ) = "ai.cooldown"
               AND NOT EXISTS (
                    SELECT 1
                    FROM conversation_messages outgoing
                    WHERE outgoing.conversation_id = cm.conversation_id
                      AND outgoing.direction = "outgoing"
                      AND (
                            outgoing.sent_at > cm.sent_at
                            OR (outgoing.sent_at = cm.sent_at AND outgoing.id > cm.id)
                      )
               )'
        );

        return (int) $statement->fetchColumn();
    }

    private function pendingByTenant(): array
    {
        $statement = Database::connection()->query(
            'SELECT t.id AS tenant_id,
                    t.name AS tenant_name,
                    COUNT(DISTINCT cm.conversation_id) AS pending_count,
                    MAX(cm.sent_at) AS oldest_or_latest_pending_at
             FROM conversation_messages cm
             INNER JOIN conversations c
                ON c.id = cm.conversation_id
               AND c.tenant_id = cm.tenant_id
             INNER JOIN ai_agents a
                ON a.tenant_id = cm.tenant_id
               AND a.instance_id = c.evolution_instance_id
               AND a.status = "active"
               AND a.auto_reply_enabled = 1
             INNER JOIN tenants t ON t.id = cm.tenant_id AND t.status = "active"
             WHERE c.attendance_mode = "ai"
               AND c.status <> "closed"
               AND cm.direction = "incoming"
               AND (COALESCE(a.reply_to_reactions, 0) = 1 OR cm.message_type <> "reaction")
               AND (
                    SELECT al.event
                    FROM ai_automation_logs al
                    WHERE al.tenant_id = cm.tenant_id
                      AND al.conversation_id = cm.conversation_id
                      AND al.agent_id = a.id
                    ORDER BY al.id DESC
                    LIMIT 1
               ) = "ai.cooldown"
               AND NOT EXISTS (
                    SELECT 1
                    FROM conversation_messages outgoing
                    WHERE outgoing.conversation_id = cm.conversation_id
                      AND outgoing.direction = "outgoing"
                      AND (
                            outgoing.sent_at > cm.sent_at
                            OR (outgoing.sent_at = cm.sent_at AND outgoing.id > cm.id)
                      )
               )
             GROUP BY t.id, t.name
             ORDER BY pending_count DESC, t.name'
        );

        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function activeAgents(): array
    {
        $statement = Database::connection()->query(
            'SELECT a.id, a.tenant_id
             FROM ai_agents a
             INNER JOIN tenants t ON t.id = a.tenant_id AND t.status = "active"
             INNER JOIN evolution_instances i
                ON i.id = a.instance_id
               AND i.tenant_id = a.tenant_id
             WHERE a.status = "active"
               AND a.auto_reply_enabled = 1
             ORDER BY a.tenant_id, a.id'
        );

        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function history(): array
    {
        $statement = Database::connection()->query(
            'SELECT r.*, u.name AS created_by_name
             FROM ai_reprocess_runs r
             LEFT JOIN users u ON u.id = r.created_by
             ORDER BY r.id DESC
             LIMIT 30'
        );
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as &$row) {
            $row['summary'] = $this->decodeJson($row['summary_json'] ?? null);
        }
        unset($row);

        return $rows;
    }

    private function startRun(string $source, ?int $userId): int
    {
        $allowed = ['manual', 'scheduled', 'webhook', 'cli'];
        if (!in_array($source, $allowed, true)) {
            $source = 'manual';
        }

        $statement = Database::connection()->prepare(
            'INSERT INTO ai_reprocess_runs (source, status, started_at, created_by)
             VALUES (:source, "running", NOW(), :created_by)'
        );
        $statement->execute(['source' => $source, 'created_by' => $userId]);
        return (int) Database::connection()->lastInsertId();
    }

    private function finishRun(int $runId, array $summary, ?string $error): void
    {
        Database::connection()->prepare(
            'UPDATE ai_reprocess_runs
             SET status = :status,
                 attempted_count = :attempted,
                 replied_count = :replied,
                 evaluated_count = :evaluated,
                 error_count = :errors,
                 pending_before = :pending_before,
                 pending_after = :pending_after,
                 summary_json = :summary_json,
                 error_message = :error_message,
                 finished_at = NOW()
             WHERE id = :id'
        )->execute([
            'status' => in_array($summary['status'] ?? '', ['success', 'partial', 'error', 'skipped'], true)
                ? $summary['status']
                : 'error',
            'attempted' => (int) ($summary['attempted'] ?? 0),
            'replied' => (int) ($summary['replied'] ?? 0),
            'evaluated' => (int) ($summary['evaluated'] ?? 0),
            'errors' => (int) ($summary['errors'] ?? 0),
            'pending_before' => (int) ($summary['pending_before'] ?? 0),
            'pending_after' => (int) ($summary['pending_after'] ?? 0),
            'summary_json' => json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'error_message' => $error !== null ? mb_substr($error, 0, 1000) : null,
            'id' => $runId,
        ]);
    }

    private function updateLastRun(array $summary, string $source, ?string $error = null): void
    {
        Database::connection()->prepare(
            'UPDATE ai_reprocess_settings
             SET last_run_at = NOW(),
                 last_run_source = :source,
                 last_run_status = :status,
                 last_summary_json = :summary_json,
                 last_error = :last_error
             WHERE id = 1'
        )->execute([
            'source' => $source,
            'status' => (string) ($summary['status'] ?? 'error'),
            'summary_json' => json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'last_error' => $error !== null ? mb_substr($error, 0, 1000) : null,
        ]);
    }

    private function acquireLock(PDO $pdo, string $name): bool
    {
        $statement = $pdo->prepare('SELECT GET_LOCK(:lock_name, 0)');
        $statement->execute(['lock_name' => mb_substr($name, 0, 64)]);
        return (int) $statement->fetchColumn() === 1;
    }

    private function releaseLock(PDO $pdo, string $name): void
    {
        try {
            $statement = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
            $statement->execute(['lock_name' => mb_substr($name, 0, 64)]);
        } catch (Throwable) {
            // A conexão encerrada libera o lock automaticamente.
        }
    }

    private function safePendingTotal(): int
    {
        try {
            return $this->pendingTotal();
        } catch (Throwable) {
            return 0;
        }
    }

    private function defaultSettings(): array
    {
        return [
            'id' => 1,
            'enabled' => 0,
            'run_time' => '03:00',
            'timezone' => (string) Env::get('APP_TIMEZONE', 'America/Sao_Paulo'),
            'max_messages_per_run' => 100,
            'last_scheduled_run_on' => null,
            'last_run_at' => null,
            'last_run_source' => null,
            'last_run_status' => null,
            'last_summary' => [],
            'last_error' => null,
        ];
    }

    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
