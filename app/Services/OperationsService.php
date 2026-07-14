<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Env;
use PDO;
use Throwable;

final class OperationsService
{
    public function dashboard(): array
    {
        $checks = $this->latestChecks();
        $lastBackup = $this->lastBackup();
        $alerts = $this->activeAlerts($checks, $lastBackup);

        return [
            'summary' => [
                'healthy' => $this->countStatus($checks, 'ok'),
                'warning' => $this->countStatus($checks, 'warning'),
                'down' => $this->countStatus($checks, 'down'),
                'alerts' => count($alerts),
            ],
            'checks' => $checks,
            'last_backup' => $lastBackup,
            'backups' => $this->backups(),
            'alerts' => $alerts,
            'incidents' => $this->incidents(),
            'recovery' => $this->recoveryPlaybooks(),
            'settings' => [
                'backup_max_age_hours' => (int) Env::get('OPERATIONS_BACKUP_MAX_AGE_HOURS', 24),
                'evolution_url' => (string) Env::get('EVOLUTION_DEFAULT_URL', ''),
                'n8n_url' => (string) Env::get('N8N_BASE_URL', ''),
                'openai_url' => (string) Env::get('OPENAI_API_BASE_URL', 'https://api.openai.com/v1'),
                'strict_backup_token' => $this->backupTokenConfigured(),
            ],
        ];
    }

    public function runChecks(): void
    {
        $this->recordCheck('database', 'Banco de dados', $this->checkDatabase());
        $this->recordCheck('evolution', 'Evolution API', $this->checkHttpEndpoint((string) Env::get('EVOLUTION_DEFAULT_URL', ''), 'Evolution não configurada'));
        $this->recordCheck('n8n', 'n8n', $this->checkHttpEndpoint((string) Env::get('N8N_BASE_URL', (string) Env::get('N8N_WEBHOOK_URL', '')), 'n8n não configurado'));
        $this->recordCheck('openai', 'OpenAI/IA', $this->checkOpenAi());
        $this->recordCheck('webhooks', 'Webhooks recentes', $this->checkWebhooks());
        $this->recordCheck('payments', 'Pagamentos', $this->checkPayments());
        $this->recordCheck('billing_cron', 'Cron de cobrança', $this->checkBillingCron());
        $this->recordCheck('backup', 'Backup', $this->checkBackupAge());
    }

    public function registerManualBackup(
        string $type,
        string $storageType,
        string $fileName,
        string $location,
        ?int $sizeBytes,
        string $checksum,
        string $notes,
        bool $verified
    ): void {
        $normalizedStorage = $this->normalizeStorageType($storageType);
        $resolvedFileName = trim($fileName) !== ''
            ? trim($fileName)
            : ($location !== '' ? basename(str_replace('\\', '/', $location)) : 'backup-manual-' . date('Ymd-His'));

        $this->insertBackup([
            'backup_type' => $type !== '' ? $type : 'manual',
            'storage_type' => $normalizedStorage,
            'status' => 'success',
            'file_name' => $resolvedFileName,
            'location' => $location,
            'size_bytes' => $sizeBytes,
            'checksum' => $checksum !== '' ? $checksum : null,
            'notes' => $notes,
            'verified_at' => $verified ? date('Y-m-d H:i:s') : null,
            'verified_by' => $verified ? Auth::id() : null,
            'started_at' => date('Y-m-d H:i:s'),
            'finished_at' => date('Y-m-d H:i:s'),
        ]);

        $this->recordIncident('backup.manual_registered', 'info', 'Backup manual registrado no painel.', [
            'storage_type' => $normalizedStorage,
            'file_name' => $resolvedFileName,
            'location' => $location,
            'verified' => $verified,
        ]);
        $this->recordCheck('backup', 'Backup', $this->checkBackupAge());
    }

    public function registerExternalBackup(array $payload): array
    {
        $status = (string) ($payload['status'] ?? 'success');
        if (!in_array($status, ['success', 'error', 'running'], true)) {
            $status = 'success';
        }

        $storageType = $this->normalizeStorageType((string) ($payload['storage_type'] ?? $payload['storage'] ?? 'server'));
        $verified = filter_var($payload['verified'] ?? ($status === 'success'), FILTER_VALIDATE_BOOL);

        $backupId = $this->insertBackup([
            'backup_type' => (string) ($payload['backup_type'] ?? $payload['type'] ?? 'automatic'),
            'storage_type' => $storageType,
            'status' => $status,
            'file_name' => (string) ($payload['file_name'] ?? $payload['filename'] ?? 'backup-' . date('Ymd-His')),
            'location' => (string) ($payload['location'] ?? $payload['path'] ?? $payload['url'] ?? ''),
            'size_bytes' => isset($payload['size_bytes']) ? (int) $payload['size_bytes'] : null,
            'checksum' => (string) ($payload['checksum'] ?? ''),
            'notes' => (string) ($payload['notes'] ?? $payload['message'] ?? ''),
            'verified_at' => $verified ? (string) ($payload['verified_at'] ?? date('Y-m-d H:i:s')) : null,
            'verified_by' => null,
            'started_at' => (string) ($payload['started_at'] ?? date('Y-m-d H:i:s')),
            'finished_at' => (string) ($payload['finished_at'] ?? date('Y-m-d H:i:s')),
        ]);

        try {
            (new BackupAutomationService())->markBackupCallback($payload, $backupId, $status);
        } catch (Throwable) {
            // Mantém compatibilidade se o ZIP 24 ainda não estiver com migration aplicada.
        }

        $this->recordCheck('backup', 'Backup', $this->checkBackupAge());
        return ['ok' => true, 'message' => 'Backup registrado no RS Connect.'];
    }

    public function resolveIncident(int $id): void
    {
        if ($id <= 0) {
            return;
        }

        try {
            $statement = Database::connection()->prepare('UPDATE system_incidents SET resolved_at = NOW() WHERE id = :id AND resolved_at IS NULL');
            $statement->execute(['id' => $id]);
        } catch (Throwable) {
            // Mantém fluxo da tela mesmo se a migration ainda não foi aplicada.
        }
    }

    public function validBackupToken(string $token): bool
    {
        $expected = $this->backupToken();
        if ($expected === '') {
            return false;
        }
        return hash_equals($expected, $token);
    }

    private function checkDatabase(): array
    {
        try {
            Database::connection()->query('SELECT 1')->fetchColumn();
            return ['status' => 'ok', 'message' => 'Conexão ativa.', 'latency_ms' => 0];
        } catch (Throwable $exception) {
            return ['status' => 'down', 'message' => 'Falha ao conectar no banco: ' . $exception->getMessage(), 'latency_ms' => null];
        }
    }

    private function checkHttpEndpoint(string $url, string $emptyMessage): array
    {
        $url = trim($url);
        if ($url === '') {
            return ['status' => 'warning', 'message' => $emptyMessage, 'latency_ms' => null];
        }

        if (!preg_match('#^https?://#i', $url)) {
            return ['status' => 'warning', 'message' => 'URL inválida ou incompleta: ' . $url, 'latency_ms' => null];
        }

        $start = microtime(true);
        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_NOBODY => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 6,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => filter_var(Env::get('EVOLUTION_SSL_VERIFY', true), FILTER_VALIDATE_BOOL),
            ]);
            curl_exec($ch);
            $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            $latency = (int) round((microtime(true) - $start) * 1000);

            if ($error !== '') {
                return ['status' => 'warning', 'message' => 'Resposta não confirmada: ' . $error, 'latency_ms' => $latency];
            }
            if ($statusCode >= 200 && $statusCode < 500) {
                return ['status' => 'ok', 'message' => 'Endpoint respondeu com HTTP ' . $statusCode . '.', 'latency_ms' => $latency];
            }
            return ['status' => 'down', 'message' => 'Endpoint respondeu HTTP ' . $statusCode . '.', 'latency_ms' => $latency];
        } catch (Throwable $exception) {
            return ['status' => 'warning', 'message' => 'Não foi possível consultar: ' . $exception->getMessage(), 'latency_ms' => null];
        }
    }

    private function checkOpenAi(): array
    {
        $key = (string) Env::get('OPENAI_API_KEY', '');
        $base = (string) Env::get('OPENAI_API_BASE_URL', 'https://api.openai.com/v1');
        if ($key === '') {
            return ['status' => 'warning', 'message' => 'OPENAI_API_KEY global não configurada. Clientes com chave própria continuam funcionando.', 'latency_ms' => null];
        }
        if (!str_starts_with($base, 'https://api.openai.com')) {
            return ['status' => 'warning', 'message' => 'Base URL da OpenAI parece diferente do endpoint oficial: ' . $base, 'latency_ms' => null];
        }
        return ['status' => 'ok', 'message' => 'Chave global preenchida e base URL conferida.', 'latency_ms' => null];
    }

    private function checkWebhooks(): array
    {
        $recentMessages = $this->count('SELECT COUNT(*) FROM conversation_messages WHERE created_at >= (NOW() - INTERVAL 24 HOUR)');
        $recentN8n = $this->count('SELECT COUNT(*) FROM n8n_flow_logs WHERE created_at >= (NOW() - INTERVAL 24 HOUR)');
        if ($recentMessages > 0 || $recentN8n > 0) {
            return ['status' => 'ok', 'message' => $recentMessages . ' mensagem(ns) e ' . $recentN8n . ' evento(s) n8n nas últimas 24h.', 'latency_ms' => null];
        }
        return ['status' => 'warning', 'message' => 'Nenhum evento recente de mensagens/n8n nas últimas 24h. Verifique se é esperado.', 'latency_ms' => null];
    }

    private function checkPayments(): array
    {
        $errors = $this->count("SELECT COUNT(*) FROM payment_gateway_events WHERE status IN ('error','failed') AND created_at >= (NOW() - INTERVAL 7 DAY)");
        if ($errors > 0) {
            return ['status' => 'warning', 'message' => $errors . ' falha(s) de pagamento nos últimos 7 dias.', 'latency_ms' => null];
        }
        $events = $this->count('SELECT COUNT(*) FROM payment_gateway_events WHERE created_at >= (NOW() - INTERVAL 7 DAY)');
        return ['status' => 'ok', 'message' => $events . ' evento(s) de pagamento nos últimos 7 dias.', 'latency_ms' => null];
    }

    private function checkBillingCron(): array
    {
        $last = $this->fetchOne("SELECT created_at FROM billing_reminder_logs ORDER BY id DESC LIMIT 1");
        if (!$last) {
            return ['status' => 'warning', 'message' => 'Nenhuma execução da régua de cobrança registrada ainda.', 'latency_ms' => null];
        }
        $createdAt = strtotime((string) ($last['created_at'] ?? '')) ?: 0;
        if ($createdAt < time() - 86400) {
            return ['status' => 'warning', 'message' => 'Última execução há mais de 24 horas: ' . ($last['created_at'] ?? ''), 'latency_ms' => null];
        }
        return ['status' => 'ok', 'message' => 'Régua executada recentemente: ' . ($last['created_at'] ?? ''), 'latency_ms' => null];
    }

    private function checkBackupAge(): array
    {
        $backup = $this->lastBackup();
        if (!$backup) {
            return ['status' => 'warning', 'message' => 'Nenhum backup registrado no RS Connect.', 'latency_ms' => null];
        }
        if (($backup['status'] ?? '') !== 'success') {
            return ['status' => 'down', 'message' => 'Último backup não finalizou com sucesso.', 'latency_ms' => null];
        }
        $finishedAt = strtotime((string) ($backup['finished_at'] ?? $backup['created_at'] ?? '')) ?: 0;
        $maxAgeHours = max(1, (int) Env::get('OPERATIONS_BACKUP_MAX_AGE_HOURS', 24));
        if ($finishedAt < time() - ($maxAgeHours * 3600)) {
            return ['status' => 'warning', 'message' => 'Último backup passou do limite de ' . $maxAgeHours . 'h.', 'latency_ms' => null];
        }
        return ['status' => 'ok', 'message' => 'Último backup registrado em ' . ($backup['finished_at'] ?? $backup['created_at'] ?? ''), 'latency_ms' => null];
    }

    private function recordCheck(string $key, string $label, array $result): void
    {
        $status = (string) ($result['status'] ?? 'warning');
        $message = (string) ($result['message'] ?? '');

        try {
            $statement = Database::connection()->prepare(
                'INSERT INTO system_health_checks (check_key, label, status, message, latency_ms, checked_at)
                 VALUES (:check_key, :label, :status, :message, :latency_ms, NOW())'
            );
            $statement->execute([
                'check_key' => $key,
                'label' => $label,
                'status' => $status,
                'message' => $message,
                'latency_ms' => $result['latency_ms'] ?? null,
            ]);

            $this->syncIncidentForCheck($key, $label, $status, $message);
        } catch (Throwable) {
            // Não derruba a aplicação caso a migration ainda não exista.
        }
    }

    private function syncIncidentForCheck(string $key, string $label, string $status, string $message): void
    {
        $event = 'operations.alert.' . $key;

        try {
            if ($status === 'ok') {
                $statement = Database::connection()->prepare('UPDATE system_incidents SET resolved_at = NOW() WHERE event = :event AND resolved_at IS NULL');
                $statement->execute(['event' => $event]);
                return;
            }

            $severity = $status === 'down' ? 'critical' : 'warning';
            $exists = $this->fetchOne("SELECT id FROM system_incidents WHERE event = '" . str_replace("'", "''", $event) . "' AND resolved_at IS NULL LIMIT 1");
            if ($exists) {
                return;
            }

            $this->recordIncident($event, $severity, $label . ': ' . $message, [
                'check_key' => $key,
                'status' => $status,
                'source' => 'health_check',
            ]);
        } catch (Throwable) {
            // Não impede os checks.
        }
    }

    private function insertBackup(array $data): ?int
    {
        try {
            $statement = Database::connection()->prepare(
                'INSERT INTO system_backups (backup_type, storage_type, status, file_name, location, size_bytes, checksum, notes, verified_at, verified_by, started_at, finished_at, created_by)
                 VALUES (:backup_type, :storage_type, :status, :file_name, :location, :size_bytes, :checksum, :notes, :verified_at, :verified_by, :started_at, :finished_at, :created_by)'
            );
            $statement->execute([
                'backup_type' => $data['backup_type'] ?? 'manual',
                'storage_type' => $data['storage_type'] ?? 'manual_local',
                'status' => $data['status'] ?? 'success',
                'file_name' => $data['file_name'] ?? '',
                'location' => $data['location'] ?? '',
                'size_bytes' => $data['size_bytes'] ?? null,
                'checksum' => $data['checksum'] ?? null,
                'notes' => $data['notes'] ?? null,
                'verified_at' => $data['verified_at'] ?? null,
                'verified_by' => $data['verified_by'] ?? null,
                'started_at' => $data['started_at'] ?? null,
                'finished_at' => $data['finished_at'] ?? null,
                'created_by' => Auth::id(),
            ]);
            return (int) Database::connection()->lastInsertId();
        } catch (Throwable) {
            try {
                // Fallback para banco antes da migration 024.
                $statement = Database::connection()->prepare(
                    'INSERT INTO system_backups (backup_type, status, file_name, location, size_bytes, checksum, notes, started_at, finished_at, created_by)
                     VALUES (:backup_type, :status, :file_name, :location, :size_bytes, :checksum, :notes, :started_at, :finished_at, :created_by)'
                );
                $statement->execute([
                    'backup_type' => $data['backup_type'] ?? 'manual',
                    'status' => $data['status'] ?? 'success',
                    'file_name' => $data['file_name'] ?? '',
                    'location' => $data['location'] ?? '',
                    'size_bytes' => $data['size_bytes'] ?? null,
                    'checksum' => $data['checksum'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'started_at' => $data['started_at'] ?? null,
                    'finished_at' => $data['finished_at'] ?? null,
                    'created_by' => Auth::id(),
                ]);
                return (int) Database::connection()->lastInsertId();
            } catch (Throwable) {
                // Ignora se a migration ainda não foi aplicada.
            }
        }

        return null;
    }

    private function recordIncident(string $event, string $severity, string $message, array $context = []): void
    {
        try {
            $statement = Database::connection()->prepare(
                'INSERT INTO system_incidents (event, severity, message, context_json, created_by)
                 VALUES (:event, :severity, :message, :context_json, :created_by)'
            );
            $statement->execute([
                'event' => $event,
                'severity' => $severity,
                'message' => $message,
                'context_json' => $context === [] ? null : json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_by' => Auth::id(),
            ]);
        } catch (Throwable) {
            // Ignora se a migration ainda não foi aplicada.
        }
    }

    private function latestChecks(): array
    {
        try {
            $rows = Database::connection()
                ->query('SELECT * FROM system_health_checks ORDER BY id DESC LIMIT 120')
                ->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $latestByKey = [];
            foreach ($rows as $row) {
                $key = (string) ($row['check_key'] ?? '');
                if ($key === '' || isset($latestByKey[$key])) {
                    continue;
                }
                $latestByKey[$key] = $row;
            }

            $checks = array_values($latestByKey);
            $weight = ['down' => 0, 'warning' => 1, 'ok' => 2];
            usort($checks, static function (array $a, array $b) use ($weight): int {
                $statusA = $weight[(string) ($a['status'] ?? 'warning')] ?? 1;
                $statusB = $weight[(string) ($b['status'] ?? 'warning')] ?? 1;
                if ($statusA !== $statusB) {
                    return $statusA <=> $statusB;
                }
                return strcasecmp((string) ($a['label'] ?? $a['check_key'] ?? ''), (string) ($b['label'] ?? $b['check_key'] ?? ''));
            });

            return $checks;
        } catch (Throwable) {
            return [];
        }
    }

    private function lastBackup(): ?array
    {
        return $this->fetchOne('SELECT * FROM system_backups ORDER BY id DESC LIMIT 1');
    }

    private function backups(): array
    {
        try {
            return Database::connection()->query('SELECT * FROM system_backups ORDER BY id DESC LIMIT 20')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    private function incidents(): array
    {
        try {
            return Database::connection()->query('SELECT * FROM system_incidents ORDER BY id DESC LIMIT 30')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    private function activeAlerts(array $checks, ?array $lastBackup): array
    {
        $alerts = [];

        try {
            $rows = Database::connection()
                ->query("SELECT * FROM system_incidents WHERE resolved_at IS NULL AND severity IN ('warning','error','critical') ORDER BY FIELD(severity, 'critical', 'error', 'warning'), id DESC LIMIT 20")
                ->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($rows as $row) {
                $alerts[] = [
                    'id' => $row['id'] ?? null,
                    'type' => $this->severityToStatus((string) ($row['severity'] ?? 'warning')),
                    'title' => $this->friendlyIncidentTitle((string) ($row['event'] ?? 'Alerta')),
                    'message' => (string) ($row['message'] ?? ''),
                    'created_at' => $row['created_at'] ?? '',
                    'event' => $row['event'] ?? '',
                ];
            }
        } catch (Throwable) {
            // Fallback abaixo.
        }

        foreach ($checks as $check) {
            if (($check['status'] ?? '') !== 'ok') {
                $alreadyListed = false;
                foreach ($alerts as $alert) {
                    if (str_contains((string) ($alert['event'] ?? ''), (string) ($check['check_key'] ?? ''))) {
                        $alreadyListed = true;
                        break;
                    }
                }
                if (!$alreadyListed) {
                    $alerts[] = [
                        'type' => $check['status'] ?? 'warning',
                        'title' => $check['label'] ?? $check['check_key'],
                        'message' => $check['message'] ?? 'Verificação requer atenção.',
                    ];
                }
            }
        }

        if (!$lastBackup) {
            $alerts[] = ['type' => 'warning', 'title' => 'Backup', 'message' => 'Nenhum backup registrado.'];
        }

        return $alerts;
    }

    private function countStatus(array $checks, string $status): int
    {
        return count(array_filter($checks, static fn (array $check): bool => ($check['status'] ?? '') === $status));
    }

    private function count(string $sql): int
    {
        try {
            return (int) Database::connection()->query($sql)->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }

    private function fetchOne(string $sql): ?array
    {
        try {
            $row = Database::connection()->query($sql)->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    private function backupToken(): string
    {
        foreach (['OPERATIONS_BACKUP_TOKEN', 'BACKUP_WEBHOOK_TOKEN', 'RS_CONNECT_BACKUP_TOKEN'] as $key) {
            $value = trim((string) Env::get($key, ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function backupTokenConfigured(): bool
    {
        return $this->backupToken() !== '';
    }

    private function normalizeStorageType(string $storageType): string
    {
        $storageType = trim($storageType) !== '' ? trim($storageType) : 'manual_local';
        $allowed = ['manual_local', 'server', 'easypanel', 'google_drive', 's3_minio', 'dropbox', 'other'];
        return in_array($storageType, $allowed, true) ? $storageType : 'other';
    }

    private function storageLabel(string $storageType): string
    {
        return match ($storageType) {
            'manual_local' => 'Local da minha máquina',
            'server' => 'Servidor/VPS',
            'easypanel' => 'EasyPanel/Provedor',
            'google_drive' => 'Google Drive',
            's3_minio' => 'S3/MinIO',
            'dropbox' => 'Dropbox',
            default => 'Outro',
        };
    }

    private function severityToStatus(string $severity): string
    {
        return match ($severity) {
            'critical', 'error' => 'down',
            default => 'warning',
        };
    }

    private function friendlyIncidentTitle(string $event): string
    {
        if (str_starts_with($event, 'operations.alert.')) {
            return 'Alerta: ' . str_replace('_', ' ', substr($event, strlen('operations.alert.')));
        }
        if (str_starts_with($event, 'backup.')) {
            return 'Backup';
        }
        return $event;
    }

    private function recoveryPlaybooks(): array
    {
        return [
            ['title' => 'Evolution não recebe mensagens', 'steps' => ['Conferir status da instância no RS Connect.', 'Revalidar webhook da instância na Evolution.', 'Enviar mensagem teste pelo WhatsApp e revisar logs de Conversas.']],
            ['title' => 'IA parou de responder', 'steps' => ['Verificar Logs de IA e automações.', 'Conferir chave/base URL em Credenciais de IA.', 'Testar com cooldown reduzido e revisar quota do provedor.']],
            ['title' => 'n8n não executa fluxo', 'steps' => ['Testar fluxo em Fluxos n8n.', 'Conferir URL do webhook, evento cadastrado e token de callback.', 'Abrir logs do n8n e logs de callback no RS Connect.']],
            ['title' => 'Pagamento não confirma', 'steps' => ['Conferir webhook do gateway.', 'Revisar logs em Gateways de pagamento.', 'Atualizar manualmente a cobrança se o gateway confirmou fora do sistema.']],
            ['title' => 'Backup atrasado', 'steps' => ['Executar backup no provedor/VPS.', 'Registrar backup manual no painel.', 'Configurar rotina externa usando /webhooks/operations/backups.']],
            ['title' => 'Backup local precisa ser conferido', 'steps' => ['Confirme se o arquivo existe no computador indicado.', 'Registre caminho completo ou observação que permita encontrar o arquivo.', 'Quando possível, use servidor/VPS, Google Drive ou S3/MinIO para validação futura.']],
        ];
    }
}
