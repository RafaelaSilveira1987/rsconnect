<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Env;
use App\Core\Router;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

final class BackupAutomationService
{
    public function dashboard(): array
    {
        $this->expireTimedOutJobs();
        $routines = $this->routines();
        $jobs = $this->jobs();
        $primaryRoutine = $this->primaryRoutine($routines);

        return [
            'summary' => $this->summary($routines, $jobs, $primaryRoutine),
            'routines' => $routines,
            'primary_routine' => $primaryRoutine,
            'jobs' => $jobs,
            'backups' => $this->recentBackups(),
            'settings' => [
                'callback_url' => Router::url('/webhooks/operations/backups'),
                'dispatch_url' => Router::url('/webhooks/operations/backups/dispatch'),
                'backup_token_configured' => $this->backupToken() !== '',
                'backup_token_source' => $this->backupTokenSource(),
                'max_age_hours' => (int) Env::get('OPERATIONS_BACKUP_MAX_AGE_HOURS', 24),
                'job_timeout_minutes' => $this->jobTimeoutMinutes(),
                'n8n_base_url' => (string) Env::get('N8N_BASE_URL', ''),
                'template_url' => Router::url('/n8n-templates/download?template=backup-rsconnect'),
            ],
        ];
    }

    public function saveRoutine(array $input): void
    {
        $id = max(0, (int) ($input['id'] ?? 0));
        $name = trim((string) ($input['name'] ?? '')) ?: 'Backup diário RS Connect';
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
        $storagePath = trim((string) ($input['storage_path'] ?? '/backups/rs-connect'));
        $retentionDays = max(1, min(365, (int) ($input['retention_days'] ?? 5)));
        $maxAgeHours = max(1, min(720, (int) ($input['max_age_hours'] ?? 24)));
        $notes = trim((string) ($input['notes'] ?? ''));

        if ($webhookUrl !== '' && !filter_var($webhookUrl, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('Informe uma URL válida do webhook n8n.');
        }
        if ($preferredTime !== '' && !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $preferredTime)) {
            throw new \InvalidArgumentException('Informe o horário no formato HH:MM.');
        }
        try {
            new DateTimeZone($timezone);
        } catch (Throwable) {
            throw new \InvalidArgumentException('Informe um fuso horário válido, por exemplo America/Sao_Paulo.');
        }
        if ($storageType === 'server' && ($storagePath === '' || !str_starts_with($storagePath, '/'))) {
            throw new \InvalidArgumentException('O caminho no servidor deve ser absoluto, por exemplo /backups/rs-connect.');
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
                        notes = :notes,
                        archived_at = NULL';
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
        Database::connection()->prepare(
            'UPDATE operations_backup_routines SET status = :status, archived_at = NULL WHERE id = :id'
        )->execute(['status' => $status, 'id' => $id]);
    }

    public function triggerRoutine(int $routineId, string $triggerType = 'manual'): array
    {
        $this->expireTimedOutJobs();
        $routine = $this->routine($routineId);
        if (!$routine || !empty($routine['archived_at'])) {
            return ['ok' => false, 'message' => 'Rotina de backup não encontrada.'];
        }
        if (($routine['status'] ?? '') !== 'active') {
            return ['ok' => false, 'message' => 'Ative a rotina antes de executar o backup.'];
        }
        if ($this->backupToken() === '') {
            return ['ok' => false, 'message' => 'Configure OPERATIONS_BACKUP_TOKEN e faça o redeploy antes de executar.'];
        }

        $running = $this->activeJobForRoutine($routineId);
        if ($running) {
            return [
                'ok' => false,
                'message' => 'Já existe um backup em andamento para esta rotina (job #' . (int) $running['id'] . ').',
                'job_id' => (int) $running['id'],
            ];
        }

        $target = $this->decryptSafe((string) ($routine['n8n_webhook_url_encrypted'] ?? ''));
        if (!filter_var($target, FILTER_VALIDATE_URL)) {
            return ['ok' => false, 'message' => 'URL do webhook n8n inválida ou não configurada.'];
        }

        $executionUuid = $this->uuidV4();
        $jobId = $this->createJob($routineId, $triggerType, $executionUuid);
        if ($jobId < 1) {
            return ['ok' => false, 'message' => 'Não foi possível criar o job de backup. Confira a migration 047.'];
        }

        $payload = $this->requestPayload($routine, $jobId, $executionUuid, $triggerType);
        $this->storeJobRequest($jobId, $payload);

        // Usa o token global de backup ponta a ponta para que o workflow n8n não dependa de $env.
        $secret = $this->backupToken();
        $result = $this->postJson($target, $payload, $secret);

        if (!empty($result['ok'])) {
            $this->markJobRunning($jobId, (string) ($result['preview'] ?? 'Solicitação aceita pelo n8n.'));
            $this->markRoutineRequested($routineId);
            return [
                'ok' => true,
                'message' => 'Backup iniciado. O painel atualizará quando o n8n enviar o resultado.',
                'job_id' => $jobId,
                'execution_uuid' => $executionUuid,
            ];
        }

        $message = (string) ($result['message'] ?? 'Falha ao acionar n8n.');
        $this->markJobTerminal($jobId, 'error', null, $message, null, []);
        $this->markRoutineError($routineId, $message);
        return ['ok' => false, 'message' => $message, 'job_id' => $jobId];
    }

    public function testConnection(int $routineId): array
    {
        $routine = $this->routine($routineId);
        if (!$routine) {
            return ['ok' => false, 'message' => 'Rotina de backup não encontrada.'];
        }
        $target = $this->decryptSafe((string) ($routine['n8n_webhook_url_encrypted'] ?? ''));
        if (!filter_var($target, FILTER_VALIDATE_URL)) {
            return ['ok' => false, 'message' => 'URL do webhook n8n inválida ou não configurada.'];
        }

        $secret = $this->backupToken();
        $result = $this->postJson($target, [
            'event' => 'operations.backup.ping',
            'source' => 'rs-connect',
            'routine_id' => $routineId,
            'sent_at' => date('c'),
        ], $secret);

        if (!empty($result['ok'])) {
            return ['ok' => true, 'message' => 'Conexão com o webhook n8n confirmada. Nenhum backup foi criado.'];
        }
        return ['ok' => false, 'message' => (string) ($result['message'] ?? 'O webhook n8n não respondeu corretamente.')];
    }

    public function dispatchDueRoutines(): array
    {
        $this->expireTimedOutJobs();
        $results = [];
        foreach ($this->activeRoutinesRaw() as $routine) {
            if (!$this->isRoutineDue($routine)) {
                continue;
            }
            $results[] = [
                'routine_id' => (int) $routine['id'],
                'result' => $this->triggerRoutine((int) $routine['id'], 'scheduled'),
            ];
        }

        return [
            'ok' => true,
            'checked_at' => date('c'),
            'dispatched' => count(array_filter($results, static fn (array $row): bool => !empty($row['result']['ok']))),
            'results' => $results,
        ];
    }

    public function processCallback(array $payload): array
    {
        $jobId = (int) ($payload['backup_job_id'] ?? $payload['job_id'] ?? 0);
        if ($jobId < 1) {
            return ['ok' => false, 'error' => 'backup_job_id é obrigatório.', 'http_status' => 422];
        }

        $status = strtolower(trim((string) ($payload['status'] ?? 'error')));
        $status = in_array($status, ['success', 'ok', 'completed'], true) ? 'success' : 'error';
        $executionUuid = trim((string) ($payload['execution_uuid'] ?? ''));
        $pdo = Database::connection();

        try {
            $pdo->beginTransaction();
            $statement = $pdo->prepare(
                'SELECT j.*, r.status AS routine_status
                 FROM operations_backup_jobs j
                 LEFT JOIN operations_backup_routines r ON r.id = j.routine_id
                 WHERE j.id = :id
                 FOR UPDATE'
            );
            $statement->execute(['id' => $jobId]);
            $job = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$job) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'Job de backup não encontrado.', 'http_status' => 404];
            }

            $routineId = (int) ($job['routine_id'] ?? 0);
            $payloadRoutineId = (int) ($payload['routine_id'] ?? $payload['backup_routine_id'] ?? 0);
            if ($payloadRoutineId > 0 && $payloadRoutineId !== $routineId) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'O job não pertence à rotina informada.', 'http_status' => 409];
            }
            if ($executionUuid !== '' && !hash_equals((string) ($job['execution_uuid'] ?? ''), $executionUuid)) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'Identificador da execução não confere.', 'http_status' => 409];
            }

            if (($job['status'] ?? '') === 'success' && (int) ($job['backup_id'] ?? 0) > 0) {
                $pdo->commit();
                return [
                    'ok' => true,
                    'idempotent' => true,
                    'message' => 'Este callback já havia sido processado.',
                    'backup_id' => (int) $job['backup_id'],
                    'job_id' => $jobId,
                ];
            }

            $message = mb_substr(trim((string) ($payload['notes'] ?? $payload['message'] ?? 'Resultado recebido do n8n.')), 0, 900);
            if ($status !== 'success') {
                $message = $message !== '' ? $message : 'O n8n informou falha durante o backup.';
                $this->updateJobTerminalInTransaction($pdo, $jobId, 'error', null, $message, null, $payload);
                if ($routineId > 0) {
                    $pdo->prepare(
                        'UPDATE operations_backup_routines SET last_error_at = NOW(), last_error = :error WHERE id = :id'
                    )->execute(['error' => $message, 'id' => $routineId]);
                }
                $pdo->commit();
                return ['ok' => true, 'message' => 'Falha do backup registrada.', 'job_id' => $jobId, 'status' => 'error'];
            }

            $validation = $this->validateSuccessPayload($payload);
            if (!$validation['ok']) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => $validation['error'], 'http_status' => 422];
            }

            $startedAt = $this->mysqlDate((string) ($payload['started_at'] ?? $job['started_at'] ?? $job['requested_at'] ?? ''));
            $finishedAt = $this->mysqlDate((string) ($payload['finished_at'] ?? ''));
            $finishedAt = $finishedAt ?: date('Y-m-d H:i:s');
            $verified = filter_var($payload['verified'] ?? false, FILTER_VALIDATE_BOOL);

            $insert = $pdo->prepare(
                'INSERT INTO system_backups
                    (backup_type, storage_type, status, routine_id, backup_job_id, execution_uuid, file_name, location,
                     size_bytes, checksum, notes, verified_at, verified_by, started_at, finished_at, created_by)
                 VALUES
                    (:backup_type, :storage_type, "success", :routine_id, :backup_job_id, :execution_uuid, :file_name, :location,
                     :size_bytes, :checksum, :notes, :verified_at, NULL, :started_at, :finished_at, NULL)'
            );
            $insert->execute([
                'backup_type' => trim((string) ($payload['backup_type'] ?? 'automatic')) ?: 'automatic',
                'storage_type' => $this->normalizeStorageType((string) ($payload['storage_type'] ?? 'server')),
                'routine_id' => $routineId > 0 ? $routineId : null,
                'backup_job_id' => $jobId,
                'execution_uuid' => (string) ($job['execution_uuid'] ?? $executionUuid),
                'file_name' => trim((string) $payload['file_name']),
                'location' => trim((string) $payload['location']),
                'size_bytes' => (int) $payload['size_bytes'],
                'checksum' => strtolower(trim((string) $payload['checksum'])),
                'notes' => $message !== '' ? $message : 'Backup automático concluído pelo n8n.',
                'verified_at' => $verified ? $finishedAt : null,
                'started_at' => $startedAt ?: (string) ($job['started_at'] ?? $job['requested_at'] ?? $finishedAt),
                'finished_at' => $finishedAt,
            ]);
            $backupId = (int) $pdo->lastInsertId();

            $this->updateJobTerminalInTransaction($pdo, $jobId, 'success', $message, null, $backupId, $payload);
            if ($routineId > 0) {
                $pdo->prepare(
                    'UPDATE operations_backup_routines
                     SET last_success_at = :finished_at,
                         last_error_at = NULL,
                         last_error = NULL
                     WHERE id = :id'
                )->execute(['finished_at' => $finishedAt, 'id' => $routineId]);
            }
            $pdo->commit();

            return [
                'ok' => true,
                'message' => 'Backup registrado e job finalizado.',
                'backup_id' => $backupId,
                'job_id' => $jobId,
                'status' => 'success',
            ];
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ((string) $exception->getCode() === '23000') {
                $existing = $this->job($jobId);
                if ($existing && (int) ($existing['backup_id'] ?? 0) > 0) {
                    return [
                        'ok' => true,
                        'idempotent' => true,
                        'message' => 'Este callback já havia sido processado.',
                        'backup_id' => (int) $existing['backup_id'],
                        'job_id' => $jobId,
                    ];
                }
            }
            return ['ok' => false, 'error' => 'Não foi possível registrar o callback: ' . $exception->getMessage(), 'http_status' => 500];
        }
    }

    public function expireTimedOutJobs(): int
    {
        $minutes = $this->jobTimeoutMinutes();
        try {
            $pdo = Database::connection();
            $statement = $pdo->prepare(
                'SELECT id, routine_id
                 FROM operations_backup_jobs
                 WHERE status IN ("requested", "running")
                   AND COALESCE(started_at, requested_at) < DATE_SUB(NOW(), INTERVAL ' . $minutes . ' MINUTE)'
            );
            $statement->execute();
            $jobs = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if (!$jobs) {
                return 0;
            }

            $message = 'Tempo limite excedido sem callback de resultado confirmado.';
            $ids = array_map(static fn (array $row): int => (int) $row['id'], $jobs);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $update = $pdo->prepare(
                'UPDATE operations_backup_jobs
                 SET status = "timeout",
                     finished_at = COALESCE(finished_at, NOW()),
                     error_message = ?,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id IN (' . $placeholders . ')'
            );
            $update->execute(array_merge([$message], $ids));

            $routineIds = array_values(array_unique(array_filter(array_map(
                static fn (array $row): int => (int) ($row['routine_id'] ?? 0),
                $jobs
            ))));
            foreach ($routineIds as $routineId) {
                $this->markRoutineError($routineId, $message);
            }
            return count($ids);
        } catch (Throwable) {
            return 0;
        }
    }

    private function requestPayload(array $routine, int $jobId, string $executionUuid, string $triggerType): array
    {
        return [
            'event' => 'operations.backup.requested',
            'source' => 'rs-connect',
            'routine_id' => (int) $routine['id'],
            'backup_routine_id' => (int) $routine['id'],
            'backup_job_id' => $jobId,
            'execution_uuid' => $executionUuid,
            'trigger_type' => $triggerType,
            'routine' => [
                'id' => (int) $routine['id'],
                'name' => (string) ($routine['name'] ?? ''),
                'frequency' => (string) ($routine['frequency'] ?? 'daily'),
                'schedule_label' => (string) ($routine['schedule_label'] ?? ''),
                'preferred_time' => (string) ($routine['preferred_time'] ?? ''),
                'timezone' => (string) ($routine['timezone'] ?? 'America/Sao_Paulo'),
            ],
            'backup' => [
                'database' => (string) Env::get('DB_DATABASE', 'rs_connect'),
                'storage_type' => (string) ($routine['storage_type'] ?? 'server'),
                'storage_path' => (string) ($routine['storage_path'] ?? '/backups/rs-connect'),
                'retention_days' => (int) ($routine['retention_days'] ?? 5),
                'max_age_hours' => (int) ($routine['max_age_hours'] ?? 24),
                'script_path' => trim((string) Env::get('OPERATIONS_BACKUP_SCRIPT_PATH', '/etc/easypanel/projects/sites/rsconnect/code/scripts/rsconnect-backup.sh')),
            ],
            'callback' => [
                'url' => Router::url('/webhooks/operations/backups'),
                'token' => $this->backupToken(),
            ],
            'requested_at' => date('c'),
        ];
    }

    private function validateSuccessPayload(array $payload): array
    {
        $fileName = trim((string) ($payload['file_name'] ?? ''));
        $location = trim((string) ($payload['location'] ?? ''));
        $size = (int) ($payload['size_bytes'] ?? 0);
        $checksum = strtolower(trim((string) ($payload['checksum'] ?? '')));
        $verified = filter_var($payload['verified'] ?? false, FILTER_VALIDATE_BOOL);

        if ($fileName === '' || basename($fileName) !== $fileName) {
            return ['ok' => false, 'error' => 'file_name é obrigatório e deve conter apenas o nome do arquivo.'];
        }
        if ($location === '') {
            return ['ok' => false, 'error' => 'location é obrigatório.'];
        }
        if ($size < 1024) {
            return ['ok' => false, 'error' => 'O arquivo informado é pequeno demais para ser considerado um backup válido.'];
        }
        if (!preg_match('/^[a-f0-9]{64}$/', $checksum)) {
            return ['ok' => false, 'error' => 'checksum SHA-256 inválido.'];
        }
        if (!$verified) {
            return ['ok' => false, 'error' => 'O callback de sucesso precisa confirmar verified=true.'];
        }
        return ['ok' => true];
    }

    private function updateJobTerminalInTransaction(
        PDO $pdo,
        int $jobId,
        string $status,
        ?string $preview,
        ?string $error,
        ?int $backupId,
        array $payload
    ): void {
        $startedAt = $this->mysqlDate((string) ($payload['started_at'] ?? ''));
        $finishedAt = $this->mysqlDate((string) ($payload['finished_at'] ?? '')) ?: date('Y-m-d H:i:s');
        $duration = null;
        if ($startedAt) {
            $duration = max(0, strtotime($finishedAt) - strtotime($startedAt));
        }

        $pdo->prepare(
            'UPDATE operations_backup_jobs
             SET status = :status,
                 response_preview = :preview,
                 error_message = :error,
                 backup_id = :backup_id,
                 started_at = COALESCE(started_at, :started_at, requested_at),
                 finished_at = :finished_at,
                 callback_received_at = NOW(),
                 duration_seconds = :duration_seconds,
                 file_name = :file_name,
                 file_size_bytes = :file_size_bytes,
                 verified = :verified,
                 result_payload_json = :result_payload_json
             WHERE id = :id'
        )->execute([
            'status' => $status,
            'preview' => $preview !== null ? mb_substr($preview, 0, 1000) : null,
            'error' => $error !== null ? mb_substr($error, 0, 900) : null,
            'backup_id' => $backupId,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'duration_seconds' => $duration,
            'file_name' => trim((string) ($payload['file_name'] ?? '')) ?: null,
            'file_size_bytes' => isset($payload['size_bytes']) ? max(0, (int) $payload['size_bytes']) : null,
            'verified' => filter_var($payload['verified'] ?? false, FILTER_VALIDATE_BOOL) ? 1 : 0,
            'result_payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'id' => $jobId,
        ]);
    }

    private function summary(array $routines, array $jobs, ?array $primaryRoutine): array
    {
        $running = count(array_filter($jobs, static fn (array $row): bool => in_array((string) ($row['status'] ?? ''), ['requested', 'running'], true)));
        $errors = count(array_filter($jobs, static fn (array $row): bool => in_array((string) ($row['status'] ?? ''), ['error', 'timeout'], true)));
        $lastBackup = $this->lastValidBackup();

        return [
            'active' => count(array_filter($routines, static fn (array $row): bool => ($row['status'] ?? '') === 'active')),
            'inactive' => count(array_filter($routines, static fn (array $row): bool => ($row['status'] ?? '') !== 'active')),
            'running' => $running,
            'jobs_error' => $errors,
            'last_valid_backup' => $lastBackup,
            'next_execution' => $primaryRoutine['next_execution_at'] ?? null,
        ];
    }

    private function routines(): array
    {
        try {
            $rows = Database::connection()->query(
                'SELECT * FROM operations_backup_routines WHERE archived_at IS NULL ORDER BY status = "active" DESC, id DESC'
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            try {
                $rows = Database::connection()->query(
                    'SELECT * FROM operations_backup_routines ORDER BY status = "active" DESC, id DESC'
                )->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable) {
                return [];
            }
        }

        foreach ($rows as &$row) {
            $webhookUrl = $this->decryptSafe((string) ($row['n8n_webhook_url_encrypted'] ?? ''));
            $row['n8n_webhook_url'] = $webhookUrl;
            $row['webhook_url_masked'] = $webhookUrl !== '' ? $this->maskUrl($webhookUrl) : 'Webhook não configurado';
            $row['secret_token_configured'] = !empty($row['secret_token_encrypted']);
            $row['next_execution_at'] = $this->nextExecution($row);
            unset($row['n8n_webhook_url_encrypted'], $row['secret_token_encrypted']);
        }
        unset($row);
        return $rows;
    }

    private function jobs(int $limit = 60): array
    {
        try {
            $limit = max(1, min(200, $limit));
            return Database::connection()->query(
                'SELECT j.*, r.name AS routine_name, b.location AS backup_location, b.checksum AS backup_checksum,
                        COALESCE(j.duration_seconds,
                            CASE WHEN j.started_at IS NOT NULL AND j.finished_at IS NOT NULL
                                 THEN TIMESTAMPDIFF(SECOND, j.started_at, j.finished_at) ELSE NULL END) AS duration_seconds_calculated
                 FROM operations_backup_jobs j
                 LEFT JOIN operations_backup_routines r ON r.id = j.routine_id
                 LEFT JOIN system_backups b ON b.id = j.backup_id
                 ORDER BY j.id DESC LIMIT ' . $limit
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    private function recentBackups(int $limit = 20): array
    {
        try {
            $limit = max(1, min(100, $limit));
            return Database::connection()->query(
                'SELECT * FROM system_backups ORDER BY id DESC LIMIT ' . $limit
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    private function lastValidBackup(): ?array
    {
        try {
            $row = Database::connection()->query(
                'SELECT * FROM system_backups
                 WHERE status = "success" AND verified_at IS NOT NULL AND size_bytes >= 1024
                 ORDER BY COALESCE(finished_at, created_at) DESC, id DESC LIMIT 1'
            )->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    private function primaryRoutine(array $routines): ?array
    {
        foreach ($routines as $routine) {
            if (($routine['status'] ?? '') === 'active') {
                return $routine;
            }
        }
        return $routines[0] ?? null;
    }

    private function routine(int $id): ?array
    {
        try {
            $statement = Database::connection()->prepare('SELECT * FROM operations_backup_routines WHERE id = :id LIMIT 1');
            $statement->execute(['id' => $id]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    private function activeRoutinesRaw(): array
    {
        try {
            return Database::connection()->query(
                'SELECT * FROM operations_backup_routines
                 WHERE status = "active" AND archived_at IS NULL
                 ORDER BY id ASC'
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    private function activeJobForRoutine(int $routineId): ?array
    {
        try {
            $statement = Database::connection()->prepare(
                'SELECT * FROM operations_backup_jobs
                 WHERE routine_id = :routine_id AND status IN ("requested", "running")
                 ORDER BY id DESC LIMIT 1'
            );
            $statement->execute(['routine_id' => $routineId]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    private function job(int $jobId): ?array
    {
        try {
            $statement = Database::connection()->prepare('SELECT * FROM operations_backup_jobs WHERE id = :id LIMIT 1');
            $statement->execute(['id' => $jobId]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    private function createJob(int $routineId, string $triggerType, string $executionUuid): int
    {
        try {
            Database::connection()->prepare(
                'INSERT INTO operations_backup_jobs
                    (routine_id, execution_uuid, status, trigger_type, requested_at, created_by)
                 VALUES
                    (:routine_id, :execution_uuid, "requested", :trigger_type, NOW(), :created_by)'
            )->execute([
                'routine_id' => $routineId,
                'execution_uuid' => $executionUuid,
                'trigger_type' => in_array($triggerType, ['manual', 'scheduled', 'test', 'webhook'], true) ? $triggerType : 'manual',
                'created_by' => Auth::id(),
            ]);
            return (int) Database::connection()->lastInsertId();
        } catch (Throwable) {
            return 0;
        }
    }

    private function storeJobRequest(int $jobId, array $payload): void
    {
        try {
            Database::connection()->prepare(
                'UPDATE operations_backup_jobs SET request_payload_json = :payload WHERE id = :id'
            )->execute([
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'id' => $jobId,
            ]);
        } catch (Throwable) {
        }
    }

    private function markJobRunning(int $jobId, string $preview): void
    {
        try {
            Database::connection()->prepare(
                'UPDATE operations_backup_jobs
                 SET status = "running",
                     response_preview = :preview,
                     error_message = NULL,
                     acknowledged_at = NOW(),
                     started_at = COALESCE(started_at, NOW()),
                     finished_at = NULL
                 WHERE id = :id AND status = "requested"'
            )->execute(['preview' => mb_substr($preview, 0, 1000), 'id' => $jobId]);
        } catch (Throwable) {
        }
    }

    private function markJobTerminal(int $jobId, string $status, ?string $preview, ?string $error, ?int $backupId, array $payload): void
    {
        try {
            $this->updateJobTerminalInTransaction(Database::connection(), $jobId, $status, $preview, $error, $backupId, $payload);
        } catch (Throwable) {
        }
    }

    private function markRoutineRequested(int $routineId): void
    {
        try {
            Database::connection()->prepare(
                'UPDATE operations_backup_routines SET last_requested_at = NOW() WHERE id = :id'
            )->execute(['id' => $routineId]);
        } catch (Throwable) {
        }
    }

    private function markRoutineError(int $routineId, string $message): void
    {
        try {
            Database::connection()->prepare(
                'UPDATE operations_backup_routines SET last_error_at = NOW(), last_error = :error WHERE id = :id'
            )->execute(['id' => $routineId, 'error' => mb_substr($message, 0, 700)]);
        } catch (Throwable) {
        }
    }

    private function isRoutineDue(array $routine): bool
    {
        $frequency = (string) ($routine['frequency'] ?? 'daily');
        if (in_array($frequency, ['manual', 'custom'], true)) {
            return false;
        }
        if ($this->activeJobForRoutine((int) $routine['id'])) {
            return false;
        }

        try {
            $timezone = new DateTimeZone((string) ($routine['timezone'] ?? 'America/Sao_Paulo'));
            $now = new DateTimeImmutable('now', $timezone);
            $preferred = (string) ($routine['preferred_time'] ?? '03:00');
            [$hour, $minute] = array_map('intval', explode(':', preg_match('/^\d{2}:\d{2}$/', $preferred) ? $preferred : '03:00'));
            $todaySchedule = $now->setTime($hour, $minute, 0);
            if ($now < $todaySchedule) {
                return false;
            }

            $lastRequestedRaw = trim((string) ($routine['last_requested_at'] ?? ''));
            if ($lastRequestedRaw === '') {
                return true;
            }
            $last = new DateTimeImmutable($lastRequestedRaw, new DateTimeZone((string) Env::get('APP_TIMEZONE', 'America/Sao_Paulo')));
            $last = $last->setTimezone($timezone);

            return match ($frequency) {
                'weekly' => $last <= $now->modify('-7 days'),
                'monthly' => $last <= $now->modify('-1 month'),
                default => $last->format('Y-m-d') < $now->format('Y-m-d'),
            };
        } catch (Throwable) {
            return false;
        }
    }

    private function nextExecution(array $routine): ?string
    {
        if (($routine['status'] ?? '') !== 'active' || in_array((string) ($routine['frequency'] ?? ''), ['manual', 'custom'], true)) {
            return null;
        }
        if ($this->activeJobForRoutine((int) ($routine['id'] ?? 0))) {
            return 'Em execução';
        }
        if ($this->isRoutineDue($routine)) {
            return 'Pendente agora';
        }

        try {
            $timezone = new DateTimeZone((string) ($routine['timezone'] ?? 'America/Sao_Paulo'));
            $now = new DateTimeImmutable('now', $timezone);
            $preferred = (string) ($routine['preferred_time'] ?? '03:00');
            [$hour, $minute] = array_map('intval', explode(':', preg_match('/^\d{2}:\d{2}$/', $preferred) ? $preferred : '03:00'));
            $frequency = (string) ($routine['frequency'] ?? 'daily');
            $lastRequestedRaw = trim((string) ($routine['last_requested_at'] ?? ''));

            if ($lastRequestedRaw === '') {
                $candidate = $now->setTime($hour, $minute, 0);
                if ($candidate <= $now) {
                    $candidate = $candidate->modify('+1 day');
                }
                return $candidate->format('Y-m-d H:i:s T');
            }

            $last = new DateTimeImmutable($lastRequestedRaw, new DateTimeZone((string) Env::get('APP_TIMEZONE', 'America/Sao_Paulo')));
            $last = $last->setTimezone($timezone)->setTime($hour, $minute, 0);
            $candidate = match ($frequency) {
                'weekly' => $last->modify('+7 days'),
                'monthly' => $last->modify('+1 month'),
                default => $last->modify('+1 day'),
            };
            while ($candidate <= $now) {
                $candidate = match ($frequency) {
                    'weekly' => $candidate->modify('+7 days'),
                    'monthly' => $candidate->modify('+1 month'),
                    default => $candidate->modify('+1 day'),
                };
            }
            return $candidate->format('Y-m-d H:i:s T');
        } catch (Throwable) {
            return null;
        }
    }

    private function postJson(string $url, array $payload, string $secret = ''): array
    {
        $headers = ['Content-Type: application/json', 'Accept: application/json', 'X-RS-Connect-Event: ' . (string) ($payload['event'] ?? 'operations.backup.requested')];
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
        foreach (['OPERATIONS_BACKUP_TOKEN', 'BACKUP_WEBHOOK_TOKEN'] as $key) {
            $token = trim((string) Env::get($key, ''));
            if ($token !== '') {
                return $token;
            }
            $serverValue = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);
            if (is_string($serverValue) && trim($serverValue) !== '') {
                return trim($serverValue);
            }
        }
        return '';
    }

    private function backupTokenSource(): string
    {
        foreach (['OPERATIONS_BACKUP_TOKEN', 'BACKUP_WEBHOOK_TOKEN'] as $key) {
            $token = trim((string) Env::get($key, ''));
            if ($token !== '') {
                return $key;
            }
            $serverValue = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);
            if (is_string($serverValue) && trim($serverValue) !== '') {
                return $key;
            }
        }
        return '';
    }

    private function jobTimeoutMinutes(): int
    {
        return max(5, min(1440, (int) Env::get('OPERATIONS_BACKUP_JOB_TIMEOUT_MINUTES', 30)));
    }

    private function decryptSafe(string $value): string
    {
        if (trim($value) === '') {
            return '';
        }
        try {
            return trim(Crypto::decrypt($value));
        } catch (Throwable) {
            return '';
        }
    }

    private function normalizeStorageType(string $storageType): string
    {
        $allowed = ['server', 'easypanel', 'google_drive', 's3_minio', 'dropbox', 'other'];
        return in_array($storageType, $allowed, true) ? $storageType : 'server';
    }

    private function maskUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return mb_substr($url, 0, 500);
        }
        return mb_substr(($parts['scheme'] ?? 'https') . '://' . $parts['host'] . ($parts['path'] ?? ''), 0, 500);
    }

    private function uuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function mysqlDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable($value))->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }
}
