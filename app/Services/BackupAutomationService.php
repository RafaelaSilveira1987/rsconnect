<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Env;
use App\Core\Router;
use PDO;
use Throwable;

final class BackupAutomationService
{
    public function dashboard(): array
    {
        return [
            'summary' => $this->summary(),
            'routines' => $this->routines(),
            'jobs' => $this->jobs(),
            'settings' => [
                'callback_url' => Router::url('/webhooks/operations/backups'),
                'callback_url_sample' => Router::url('/webhooks/operations/backups') . '?token=SEU_TOKEN',
                'backup_token_configured' => $this->backupToken() !== '',
                'max_age_hours' => (int) Env::get('OPERATIONS_BACKUP_MAX_AGE_HOURS', 24),
                'n8n_base_url' => (string) Env::get('N8N_BASE_URL', ''),
                'template_url' => Router::url('/n8n-templates/download?template=backup-rsconnect'),
            ],
        ];
    }

    public function saveRoutine(array $input): void
    {
        $id = max(0, (int) ($input['id'] ?? 0));
        $name = trim((string) ($input['name'] ?? '')) ?: 'Backup automático RS Connect';
        $status = (string) ($input['status'] ?? 'active');
        $status = in_array($status, ['active', 'inactive'], true) ? $status : 'active';
        $webhookUrl = trim((string) ($input['n8n_webhook_url'] ?? ''));
        $secretToken = trim((string) ($input['secret_token'] ?? ''));
        $frequency = (string) ($input['frequency'] ?? 'daily');
        $frequency = in_array($frequency, ['daily', 'weekly', 'monthly', 'manual', 'custom'], true) ? $frequency : 'daily';
        $scheduleLabel = trim((string) ($input['schedule_label'] ?? ''));
        $preferredTime = trim((string) ($input['preferred_time'] ?? '03:00'));
        $timezone = trim((string) ($input['timezone'] ?? 'America/Sao_Paulo')) ?: 'America/Sao_Paulo';
        $storageType = $this->normalizeStorageType((string) ($input['storage_type'] ?? 'server'));
        $storagePath = trim((string) ($input['storage_path'] ?? ''));
        $retentionDays = max(1, min(365, (int) ($input['retention_days'] ?? 14)));
        $maxAgeHours = max(1, min(720, (int) ($input['max_age_hours'] ?? 24)));
        $notes = trim((string) ($input['notes'] ?? ''));

        if ($webhookUrl !== '' && !filter_var($webhookUrl, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('Informe uma URL válida do webhook n8n.');
        }

        $pdo = Database::connection();
        if ($id > 0) {
            $sql = 'UPDATE operations_backup_routines
                    SET name = :name,
                        status = :status,
                        frequency = :frequency,
                        schedule_label = :schedule_label,
                        preferred_time = :preferred_time,
                        timezone = :timezone,
                        storage_type = :storage_type,
                        storage_path = :storage_path,
                        retention_days = :retention_days,
                        max_age_hours = :max_age_hours,
                        notes = :notes';
            $params = [
                'name' => $name,
                'status' => $status,
                'frequency' => $frequency,
                'schedule_label' => $scheduleLabel !== '' ? $scheduleLabel : null,
                'preferred_time' => $preferredTime !== '' ? $preferredTime : null,
                'timezone' => $timezone,
                'storage_type' => $storageType,
                'storage_path' => $storagePath !== '' ? $storagePath : null,
                'retention_days' => $retentionDays,
                'max_age_hours' => $maxAgeHours,
                'notes' => $notes !== '' ? $notes : null,
                'id' => $id,
            ];
            if ($webhookUrl !== '') {
                $sql .= ', n8n_webhook_url_encrypted = :webhook_url';
                $params['webhook_url'] = Crypto::encrypt($webhookUrl);
            }
            if ($secretToken !== '') {
                $sql .= ', secret_token_encrypted = :secret_token';
                $params['secret_token'] = Crypto::encrypt($secretToken);
            }
            $sql .= ' WHERE id = :id';
            $pdo->prepare($sql)->execute($params);
            return;
        }

        if ($webhookUrl === '') {
            throw new \InvalidArgumentException('Informe a URL do webhook n8n para criar a rotina.');
        }

        $pdo->prepare(
            'INSERT INTO operations_backup_routines
                (name, status, n8n_webhook_url_encrypted, secret_token_encrypted, frequency, schedule_label, preferred_time, timezone,
                 storage_type, storage_path, retention_days, max_age_hours, notes, created_by)
             VALUES
                (:name, :status, :webhook_url, :secret_token, :frequency, :schedule_label, :preferred_time, :timezone,
                 :storage_type, :storage_path, :retention_days, :max_age_hours, :notes, :created_by)'
        )->execute([
            'name' => $name,
            'status' => $status,
            'webhook_url' => Crypto::encrypt($webhookUrl),
            'secret_token' => $secretToken !== '' ? Crypto::encrypt($secretToken) : null,
            'frequency' => $frequency,
            'schedule_label' => $scheduleLabel !== '' ? $scheduleLabel : null,
            'preferred_time' => $preferredTime !== '' ? $preferredTime : null,
            'timezone' => $timezone,
            'storage_type' => $storageType,
            'storage_path' => $storagePath !== '' ? $storagePath : null,
            'retention_days' => $retentionDays,
            'max_age_hours' => $maxAgeHours,
            'notes' => $notes !== '' ? $notes : null,
            'created_by' => Auth::id(),
        ]);
    }

    public function toggleRoutine(int $id, string $status): void
    {
        if ($id < 1) {
            return;
        }
        $status = $status === 'inactive' ? 'inactive' : 'active';
        Database::connection()->prepare('UPDATE operations_backup_routines SET status = :status WHERE id = :id')
            ->execute(['status' => $status, 'id' => $id]);
    }

    public function triggerRoutine(int $routineId, string $triggerType = 'manual'): array
    {
        $routine = $this->routine($routineId);
        if (!$routine) {
            return ['ok' => false, 'message' => 'Rotina de backup não encontrada.'];
        }

        $target = Crypto::decrypt((string) ($routine['n8n_webhook_url_encrypted'] ?? ''));
        if (!filter_var($target, FILTER_VALIDATE_URL)) {
            return ['ok' => false, 'message' => 'URL do webhook n8n inválida ou não configurada.'];
        }

        $jobId = $this->createJob($routineId, $triggerType, ['routine' => $this->safeRoutine($routine)]);
        $callbackToken = $this->backupToken();

        $payload = [
            'event' => 'operations.backup.requested',
            'source' => 'rs-connect',
            'routine_id' => $routineId,
            'backup_routine_id' => $routineId,
            'backup_job_id' => $jobId,
            'trigger_type' => $triggerType,
            'routine' => [
                'id' => $routineId,
                'name' => (string) ($routine['name'] ?? ''),
                'frequency' => (string) ($routine['frequency'] ?? 'daily'),
                'schedule_label' => (string) ($routine['schedule_label'] ?? ''),
                'preferred_time' => (string) ($routine['preferred_time'] ?? ''),
                'timezone' => (string) ($routine['timezone'] ?? 'America/Sao_Paulo'),
            ],
            'backup' => [
                'database' => (string) Env::get('DB_DATABASE', 'rs_connect'),
                'storage_type' => (string) ($routine['storage_type'] ?? 'server'),
                'storage_path' => (string) ($routine['storage_path'] ?? ''),
                'retention_days' => (int) ($routine['retention_days'] ?? 14),
                'max_age_hours' => (int) ($routine['max_age_hours'] ?? 24),
            ],
            'callback' => [
                'url' => Router::url('/webhooks/operations/backups'),
                'token' => $callbackToken !== '' ? $callbackToken : null,
            ],
            'requested_at' => date('c'),
        ];

        $secret = !empty($routine['secret_token_encrypted']) ? Crypto::decrypt((string) $routine['secret_token_encrypted']) : '';
        $result = $this->postJson($target, $payload, $secret);

        if (!empty($result['ok'])) {
            $this->markJob($jobId, 'running', $result['preview'] ?? 'Solicitação recebida pelo n8n.', null, null);
            $this->markRoutineRequested($routineId);
            return ['ok' => true, 'message' => 'Solicitação enviada ao n8n. Aguarde o callback do backup.'];
        }

        $message = (string) ($result['message'] ?? 'Falha ao acionar n8n.');
        $this->markJob($jobId, 'error', null, $message, null);
        $this->markRoutineError($routineId, $message);
        return ['ok' => false, 'message' => $message];
    }

    public function markBackupCallback(array $payload, ?int $backupId = null, string $status = 'success'): void
    {
        $routineId = (int) ($payload['routine_id'] ?? $payload['backup_routine_id'] ?? 0);
        $jobId = (int) ($payload['backup_job_id'] ?? $payload['job_id'] ?? 0);
        $message = (string) ($payload['notes'] ?? $payload['message'] ?? 'Callback de backup recebido.');

        try {
            if ($jobId > 0) {
                $this->markJob($jobId, $status === 'success' ? 'success' : 'error', $status === 'success' ? $message : null, $status !== 'success' ? $message : null, $backupId);
            }
            if ($routineId > 0) {
                if ($status === 'success') {
                    Database::connection()->prepare(
                        'UPDATE operations_backup_routines
                         SET last_success_at = NOW(), last_error_at = NULL, last_error = NULL
                         WHERE id = :id'
                    )->execute(['id' => $routineId]);
                } else {
                    $this->markRoutineError($routineId, $message);
                }
            }
        } catch (Throwable) {
            // Não deve impedir o webhook de backup já existente.
        }
    }

    private function summary(): array
    {
        $routines = $this->routines();
        $jobs = $this->jobs(20);
        return [
            'active' => count(array_filter($routines, static fn (array $row): bool => ($row['status'] ?? '') === 'active')),
            'inactive' => count(array_filter($routines, static fn (array $row): bool => ($row['status'] ?? '') !== 'active')),
            'jobs_success' => count(array_filter($jobs, static fn (array $row): bool => ($row['status'] ?? '') === 'success')),
            'jobs_error' => count(array_filter($jobs, static fn (array $row): bool => ($row['status'] ?? '') === 'error')),
        ];
    }

    private function routines(): array
    {
        try {
            $rows = Database::connection()->query('SELECT * FROM operations_backup_routines ORDER BY status, id DESC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as &$row) {
                $row['webhook_url_masked'] = $this->maskUrl(Crypto::decrypt((string) ($row['n8n_webhook_url_encrypted'] ?? '')));
                unset($row['n8n_webhook_url_encrypted'], $row['secret_token_encrypted']);
            }
            unset($row);
            return $rows;
        } catch (Throwable) {
            return [];
        }
    }

    private function jobs(int $limit = 60): array
    {
        try {
            $limit = max(1, min(200, $limit));
            return Database::connection()->query(
                'SELECT j.*, r.name AS routine_name
                 FROM operations_backup_jobs j
                 LEFT JOIN operations_backup_routines r ON r.id = j.routine_id
                 ORDER BY j.id DESC LIMIT ' . $limit
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    private function routine(int $id): ?array
    {
        $statement = Database::connection()->prepare('SELECT * FROM operations_backup_routines WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function createJob(int $routineId, string $triggerType, array $payload): int
    {
        try {
            Database::connection()->prepare(
                'INSERT INTO operations_backup_jobs (routine_id, status, trigger_type, request_payload_json, requested_at, created_by)
                 VALUES (:routine_id, :status, :trigger_type, :payload, NOW(), :created_by)'
            )->execute([
                'routine_id' => $routineId,
                'status' => 'requested',
                'trigger_type' => in_array($triggerType, ['manual', 'scheduled', 'test', 'webhook'], true) ? $triggerType : 'manual',
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_by' => Auth::id(),
            ]);
            return (int) Database::connection()->lastInsertId();
        } catch (Throwable) {
            return 0;
        }
    }

    private function markJob(int $jobId, string $status, ?string $preview, ?string $error, ?int $backupId): void
    {
        if ($jobId < 1) {
            return;
        }
        try {
            Database::connection()->prepare(
                'UPDATE operations_backup_jobs
                 SET status = :status,
                     response_preview = :preview,
                     error_message = :error,
                     backup_id = COALESCE(:backup_id, backup_id),
                     started_at = COALESCE(started_at, NOW()),
                     finished_at = CASE WHEN :finished IN ("success", "error", "skipped") THEN NOW() ELSE finished_at END
                 WHERE id = :id'
            )->execute([
                'status' => in_array($status, ['requested', 'running', 'success', 'error', 'skipped'], true) ? $status : 'running',
                'preview' => $preview !== null ? mb_substr($preview, 0, 1000) : null,
                'error' => $error !== null ? mb_substr($error, 0, 900) : null,
                'backup_id' => $backupId,
                'finished' => $status,
                'id' => $jobId,
            ]);
        } catch (Throwable) {
        }
    }

    private function markRoutineRequested(int $routineId): void
    {
        try {
            Database::connection()->prepare('UPDATE operations_backup_routines SET last_requested_at = NOW() WHERE id = :id')->execute(['id' => $routineId]);
        } catch (Throwable) {
        }
    }

    private function markRoutineError(int $routineId, string $message): void
    {
        try {
            Database::connection()->prepare('UPDATE operations_backup_routines SET last_error_at = NOW(), last_error = :error WHERE id = :id')
                ->execute(['id' => $routineId, 'error' => mb_substr($message, 0, 700)]);
        } catch (Throwable) {
        }
    }

    private function postJson(string $url, array $payload, string $secret = ''): array
    {
        $headers = ['Content-Type: application/json', 'Accept: application/json', 'X-RS-Connect-Event: operations.backup.requested'];
        if ($secret !== '') {
            $headers[] = 'Authorization: Bearer ' . $secret;
            $headers[] = 'X-RS-Connect-Token: ' . $secret;
        }

        try {
            $curl = curl_init($url);
            if ($curl === false) {
                throw new \RuntimeException('Não foi possível iniciar cURL.');
            }
            curl_setopt_array($curl, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 6,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ]);
            $response = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $error = curl_error($curl);
            curl_close($curl);

            if ($response === false || $status < 200 || $status >= 300) {
                return ['ok' => false, 'message' => $error !== '' ? $error : 'HTTP ' . $status . ': ' . mb_substr((string) $response, 0, 500)];
            }
            return ['ok' => true, 'status' => $status, 'preview' => mb_substr((string) $response, 0, 1000)];
        } catch (Throwable $exception) {
            return ['ok' => false, 'message' => $exception->getMessage()];
        }
    }


    private function backupToken(): string
    {
        $token = (string) Env::get('OPERATIONS_BACKUP_TOKEN', '');
        if (trim($token) === '') {
            $token = (string) Env::get('BACKUP_WEBHOOK_TOKEN', '');
        }
        return trim($token);
    }

    private function normalizeStorageType(string $storageType): string
    {
        $allowed = ['server', 'easypanel', 'google_drive', 's3_minio', 'dropbox', 'other'];
        return in_array($storageType, $allowed, true) ? $storageType : 'server';
    }

    private function safeRoutine(array $routine): array
    {
        unset($routine['n8n_webhook_url_encrypted'], $routine['secret_token_encrypted']);
        return $routine;
    }

    private function maskUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return mb_substr($url, 0, 500);
        }
        return mb_substr(($parts['scheme'] ?? 'https') . '://' . $parts['host'] . ($parts['path'] ?? ''), 0, 500);
    }
}
