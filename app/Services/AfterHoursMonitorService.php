<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Database;
use App\Core\Env;
use App\Core\Router;
use PDO;
use RuntimeException;
use Throwable;

final class AfterHoursMonitorService
{
    private const LOCK_NAME = 'rs_after_hours_monitor';

    public function dashboard(): array
    {
        $defaults = $this->defaults();
        try {
            $pdo = Database::connection();
            if (!$this->hasTable($pdo, 'ai_after_hours_monitor_settings')) {
                return $defaults + ['ready' => false, 'migration' => '091_after_hours_monitor_and_quote_requests.sql'];
            }

            $settings = $this->settings($pdo);
            $settings['ready'] = true;
            $settings['cron_url'] = Router::url('/webhooks/ai-after-hours-recovery');
            $settings['token_configured'] = $this->configuredToken() !== '';
            $settings['pending'] = (new AiAfterHoursRecoveryService())->pendingCounts();
            $settings['last_summary'] = $this->decodeJson($settings['last_summary_json'] ?? null);
            return $settings;
        } catch (Throwable $exception) {
            return $defaults + [
                'ready' => false,
                'error' => $exception->getMessage(),
                'cron_url' => Router::url('/webhooks/ai-after-hours-recovery'),
                'token_configured' => $this->configuredToken() !== '',
            ];
        }
    }

    public function save(bool $enabled, int $intervalMinutes, int $maxItems, ?int $userId): array
    {
        $pdo = Database::connection();
        if (!$this->hasTable($pdo, 'ai_after_hours_monitor_settings')) {
            throw new RuntimeException('Execute a migration 091 para configurar o monitor pós-horário.');
        }

        $allowed = [5, 10, 15, 30, 60];
        if (!in_array($intervalMinutes, $allowed, true)) {
            $intervalMinutes = 15;
        }
        $maxItems = max(1, min(200, $maxItems));

        $statement = $pdo->prepare(
            'INSERT INTO ai_after_hours_monitor_settings
                (id, enabled, interval_minutes, max_items_per_run, updated_by_user_id)
             VALUES (1, :enabled, :interval_minutes, :max_items_per_run, :updated_by_user_id)
             ON DUPLICATE KEY UPDATE
                enabled = VALUES(enabled),
                interval_minutes = VALUES(interval_minutes),
                max_items_per_run = VALUES(max_items_per_run),
                updated_by_user_id = VALUES(updated_by_user_id),
                updated_at = CURRENT_TIMESTAMP'
        );
        $statement->execute([
            'enabled' => $enabled ? 1 : 0,
            'interval_minutes' => $intervalMinutes,
            'max_items_per_run' => $maxItems,
            'updated_by_user_id' => $userId && $userId > 0 ? $userId : null,
        ]);

        return $this->dashboard();
    }

    public function run(bool $force = false, string $source = 'cron'): array
    {
        $pdo = Database::connection();
        if (!$this->hasTable($pdo, 'ai_after_hours_monitor_settings')) {
            return ['status' => 'migration_pending', 'message' => 'Migration 091 pendente.'];
        }

        $settings = $this->settings($pdo);
        if ((int) ($settings['enabled'] ?? 0) !== 1 && !$force) {
            return ['status' => 'disabled', 'message' => 'Monitor pós-horário desativado.'];
        }

        $lastRunAt = strtotime((string) ($settings['last_run_at'] ?? '')) ?: 0;
        $intervalSeconds = max(300, ((int) ($settings['interval_minutes'] ?? 15)) * 60);
        if (!$force && $lastRunAt > 0 && $lastRunAt > time() - $intervalSeconds) {
            return [
                'status' => 'not_due',
                'message' => 'A última execução ainda está dentro do intervalo configurado.',
                'next_run_after' => date('Y-m-d H:i:s', $lastRunAt + $intervalSeconds),
            ];
        }

        if (!$this->acquireLock($pdo)) {
            return ['status' => 'busy', 'message' => 'Já existe uma recuperação pós-horário em andamento.'];
        }

        $started = microtime(true);
        try {
            $limit = max(1, min(200, (int) ($settings['max_items_per_run'] ?? 50)));
            $summary = (new AiAfterHoursRecoveryService())->recoverDue($limit, $source);
            $status = (int) ($summary['errors'] ?? 0) > 0 ? 'partial' : 'success';
            $result = [
                'status' => $status,
                'source' => $source,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'summary' => $summary,
            ];
            $this->record($pdo, $status, $result, null);
            return $result;
        } catch (Throwable $exception) {
            $this->record($pdo, 'error', [
                'status' => 'error',
                'source' => $source,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            ], $exception->getMessage());
            throw $exception;
        } finally {
            $this->releaseLock($pdo);
        }
    }

    public function validToken(string $token): bool
    {
        $expected = $this->configuredToken();
        return $expected !== '' && $token !== '' && hash_equals($expected, trim($token));
    }

    private function settings(PDO $pdo): array
    {
        $statement = $pdo->query('SELECT * FROM ai_after_hours_monitor_settings WHERE id = 1 LIMIT 1');
        $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        return $row ?: $this->defaults();
    }

    private function defaults(): array
    {
        return [
            'ready' => false,
            'enabled' => 1,
            'interval_minutes' => 15,
            'max_items_per_run' => 50,
            'last_run_at' => null,
            'last_run_status' => null,
            'last_summary_json' => null,
            'last_error' => null,
            'cron_url' => Router::url('/webhooks/ai-after-hours-recovery'),
            'token_configured' => $this->configuredToken() !== '',
            'pending' => ['total' => 0, 'blocked_plan' => 0, 'blocked_human' => 0, 'errors' => 0],
            'last_summary' => [],
        ];
    }

    private function record(PDO $pdo, string $status, array $summary, ?string $error): void
    {
        try {
            $statement = $pdo->prepare(
                'UPDATE ai_after_hours_monitor_settings
                 SET last_run_at = :last_run_at,
                     last_run_status = :last_run_status,
                     last_summary_json = :last_summary_json,
                     last_error = :last_error
                 WHERE id = 1'
            );
            $statement->execute([
                'last_run_at' => Clock::nowUtc(),
                'last_run_status' => $status,
                'last_summary_json' => json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'last_error' => $error,
            ]);
        } catch (Throwable) {
        }
    }

    private function acquireLock(PDO $pdo): bool
    {
        $statement = $pdo->prepare('SELECT GET_LOCK(:name, 0)');
        $statement->execute(['name' => self::LOCK_NAME]);
        return (int) $statement->fetchColumn() === 1;
    }

    private function releaseLock(PDO $pdo): void
    {
        try {
            $statement = $pdo->prepare('SELECT RELEASE_LOCK(:name)');
            $statement->execute(['name' => self::LOCK_NAME]);
            $statement->fetchColumn();
        } catch (Throwable) {
        }
    }

    private function configuredToken(): string
    {
        $token = trim((string) Env::get('AFTER_HOURS_MONITOR_TOKEN', ''));
        if ($token !== '') {
            return $token;
        }
        return trim((string) Env::get('AI_REPROCESS_CRON_TOKEN', ''));
    }

    private function hasTable(PDO $pdo, string $table): bool
    {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table');
        $statement->execute(['table' => $table]);
        return (int) $statement->fetchColumn() > 0;
    }

    private function decodeJson(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
