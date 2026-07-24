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
        $checks = $this->withExpectedChecks($this->latestChecks());
        $lastBackup = $this->lastBackup();
        $alerts = $this->activeAlerts($checks, $lastBackup);
        $history = $this->checkHistory();
        $summary = [
            'healthy' => $this->countStatus($checks, 'ok'),
            'warning' => $this->countStatus($checks, 'warning'),
            'down' => $this->countStatus($checks, 'down'),
            'unknown' => $this->countStatus($checks, 'unknown'),
            'alerts' => count($alerts),
        ];

        $lastCheckedAt = null;
        foreach ($checks as $check) {
            $checkedAt = trim((string) ($check['checked_at'] ?? ''));
            if ($checkedAt !== '' && ($lastCheckedAt === null || strcmp($checkedAt, $lastCheckedAt) > 0)) {
                $lastCheckedAt = $checkedAt;
            }
        }

        $overallStatus = 'ok';
        $overallLabel = 'Operacional';
        if ($summary['down'] > 0) {
            $overallStatus = 'down';
            $overallLabel = 'Crítico';
        } elseif ($summary['warning'] > 0) {
            $overallStatus = 'warning';
            $overallLabel = 'Atenção';
        } elseif ($summary['unknown'] > 0) {
            $overallStatus = 'unknown';
            $overallLabel = 'Sem evidência';
        }

        return [
            'summary' => $summary,
            'overall' => [
                'status' => $overallStatus,
                'label' => $overallLabel,
                'last_checked_at' => $lastCheckedAt,
                'total' => count($checks),
            ],
            'checks' => $checks,
            'check_history' => $history,
            'last_backup' => $lastBackup,
            'active_backup_routine' => $this->activeBackupRoutine(),
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
        $this->recordCheck('migrations', 'Estrutura e migrations', $this->checkMigrations());
        $this->recordCheck('evolution', 'WhatsApp / Evolution', $this->checkEvolution());
        $this->recordCheck('n8n', 'n8n', $this->checkN8n());
        $this->recordCheck('openai', 'OpenAI / IA', $this->checkOpenAi());
        $this->recordCheck('webhooks', 'Webhooks e mensagens', $this->checkWebhooks());
        $this->recordCheck('calendar', 'Google Agenda', $this->checkCalendar());
        $this->recordCheck('payments', 'Gateways e pagamentos', $this->checkPayments());
        $this->refreshBillingCronCheck();
        $this->recordCheck('ai_reprocess', 'Rotina da fila da IA', $this->checkAiReprocess());
        $this->recordCheck('reporting', 'Agregação de relatórios', $this->checkReporting());
        $this->recordCheck('backup', 'Backup', $this->checkBackupAge());
    }

    public function refreshBillingCronCheck(): void
    {
        $this->recordCheck('billing_cron', 'Cron de cobrança', $this->checkBillingCron());
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
        $result = (new BackupAutomationService())->processCallback($payload);
        if (!empty($result['ok'])) {
            $this->recordCheck('backup', 'Backup', $this->checkBackupAge());
        }
        return $result;
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

    private function checkEvolution(): array
    {
        $instances = $this->count('SELECT COUNT(*) FROM evolution_instances');
        $connected = $this->count("SELECT COUNT(*) FROM evolution_instances WHERE status IN ('connected','open','active','online')");
        $incoming24 = $this->count("SELECT COUNT(*) FROM conversation_messages WHERE direction = 'incoming' AND created_at >= (NOW() - INTERVAL 24 HOUR)");

        if ($connected > 0) {
            return [
                'status' => 'ok',
                'message' => $connected . '/' . max($instances, $connected) . ' instância(s) conectada(s); ' . $incoming24 . ' mensagem(ns) recebida(s) nas últimas 24h.',
                'latency_ms' => null,
            ];
        }

        $url = trim((string) Env::get('EVOLUTION_DEFAULT_URL', ''));
        if ($url !== '') {
            $endpoint = $this->checkHttpEndpoint($url, 'Evolution não configurada');
            return [
                'status' => 'warning',
                'message' => 'A API está configurada, mas nenhuma instância aparece conectada. ' . ($endpoint['message'] ?? ''),
                'latency_ms' => $endpoint['latency_ms'] ?? null,
            ];
        }

        return ['status' => 'warning', 'message' => 'Nenhuma instância Evolution conectada ou URL padrão configurada.', 'latency_ms' => null];
    }

    private function checkN8n(): array
    {
        $activeFlows = $this->count("SELECT COUNT(*) FROM n8n_tenant_flows WHERE status = 'active'")
            + $this->count("SELECT COUNT(*) FROM n8n_flows WHERE status = 'active'");
        $success24 = $this->count("SELECT COUNT(*) FROM n8n_flow_logs WHERE status = 'success' AND created_at >= (NOW() - INTERVAL 24 HOUR)");
        $errors24 = $this->count("SELECT COUNT(*) FROM n8n_flow_logs WHERE status = 'error' AND created_at >= (NOW() - INTERVAL 24 HOUR)");
        $lastSuccess = $this->fetchOne("SELECT created_at FROM n8n_flow_logs WHERE status = 'success' ORDER BY id DESC LIMIT 1");
        $lastError = $this->fetchOne("SELECT created_at, error_message FROM n8n_flow_logs WHERE status = 'error' ORDER BY id DESC LIMIT 1");
        $successAt = strtotime((string) ($lastSuccess['created_at'] ?? '')) ?: 0;
        $errorAt = strtotime((string) ($lastError['created_at'] ?? '')) ?: 0;

        if ($activeFlows < 1) {
            return ['status' => 'warning', 'message' => 'Nenhum fluxo n8n ativo foi encontrado no RS Connect.', 'latency_ms' => null];
        }
        if ($errorAt > $successAt && $errorAt >= time() - 86400) {
            return [
                'status' => 'warning',
                'message' => $activeFlows . ' fluxo(s) ativo(s), porém a execução mais recente com evidência foi uma falha: ' . trim((string) ($lastError['error_message'] ?? 'erro sem detalhe')),
                'latency_ms' => null,
            ];
        }
        if ($success24 > 0) {
            return ['status' => 'ok', 'message' => $activeFlows . ' fluxo(s) ativo(s); ' . $success24 . ' sucesso(s) e ' . $errors24 . ' erro(s) nas últimas 24h. O último sucesso é posterior às falhas registradas.', 'latency_ms' => null];
        }

        return ['status' => 'warning', 'message' => $activeFlows . ' fluxo(s) ativo(s), mas não há sucesso registrado nas últimas 24h para comprovar execução recente.', 'latency_ms' => null];
    }

    private function checkOpenAi(): array
    {
        $key = trim((string) Env::get('OPENAI_API_KEY', ''));
        $base = trim((string) Env::get('OPENAI_API_BASE_URL', 'https://api.openai.com/v1'));
        $activeCredentials = $this->count("SELECT COUNT(*) FROM ai_provider_credentials WHERE status = 'active' AND api_key_encrypted IS NOT NULL AND api_key_encrypted <> ''");

        if ($key === '' && $activeCredentials < 1) {
            return [
                'status' => 'warning',
                'message' => 'Nenhuma credencial ativa de IA foi encontrada. Cadastre uma chave por empresa/assistente ou configure a chave global.',
                'latency_ms' => null,
            ];
        }

        if ($base !== '' && !preg_match('#^https?://#i', $base)) {
            return ['status' => 'warning', 'message' => 'A URL base da IA está inválida ou incompleta: ' . $base, 'latency_ms' => null];
        }

        $lastSuccess = $this->fetchOne("SELECT created_at FROM ai_automation_logs WHERE event = 'ai.replied' AND status = 'success' ORDER BY id DESC LIMIT 1");
        $lastError = $this->fetchOne("SELECT created_at, error_message FROM ai_automation_logs WHERE (event = 'ai.failed' OR status = 'error') ORDER BY id DESC LIMIT 1");
        $successAt = strtotime((string) ($lastSuccess['created_at'] ?? '')) ?: 0;
        $errorAt = strtotime((string) ($lastError['created_at'] ?? '')) ?: 0;
        $credentialText = $activeCredentials > 0 ? $activeCredentials . ' credencial(is) por empresa/assistente' : 'chave global configurada';

        if ($errorAt > $successAt && $errorAt >= time() - 86400) {
            return [
                'status' => 'warning',
                'message' => ucfirst($credentialText) . ', mas a evidência mais recente é uma falha da IA: ' . trim((string) ($lastError['error_message'] ?? 'erro sem detalhe')),
                'latency_ms' => null,
            ];
        }
        if ($successAt > 0) {
            return ['status' => 'ok', 'message' => ucfirst($credentialText) . '; última resposta de IA concluída em ' . ($lastSuccess['created_at'] ?? '') . '.', 'latency_ms' => null];
        }

        return ['status' => 'warning', 'message' => ucfirst($credentialText) . ', mas ainda não há resposta bem-sucedida registrada para comprovar o funcionamento.', 'latency_ms' => null];
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
        $gateways = $this->count("SELECT COUNT(*) FROM payment_gateways WHERE status = 'active'");
        $errors = $this->count("SELECT COUNT(*) FROM payment_gateway_events WHERE status IN ('error','failed') AND created_at >= (NOW() - INTERVAL 7 DAY)");
        if ($errors > 0) {
            return ['status' => 'warning', 'message' => $gateways . ' gateway(s) ativo(s); ' . $errors . ' falha(s) de pagamento nos últimos 7 dias.', 'latency_ms' => null];
        }
        $events = $this->count('SELECT COUNT(*) FROM payment_gateway_events WHERE created_at >= (NOW() - INTERVAL 7 DAY)');
        if ($gateways < 1) {
            return ['status' => 'warning', 'message' => 'Nenhum gateway de pagamento ativo foi encontrado.', 'latency_ms' => null];
        }
        if ($events < 1) {
            return ['status' => 'warning', 'message' => $gateways . ' gateway(s) ativo(s), mas não há evento de pagamento nos últimos 7 dias para comprovar o fluxo.', 'latency_ms' => null];
        }
        return ['status' => 'ok', 'message' => $gateways . ' gateway(s) ativo(s); ' . $events . ' evento(s) de pagamento nos últimos 7 dias sem falha registrada.', 'latency_ms' => null];
    }

    private function checkCalendar(): array
    {
        $enabled = $this->count('SELECT COUNT(*) FROM tenant_calendar_availability_settings WHERE enabled = 1');
        if ($enabled < 1) {
            return ['status' => 'warning', 'message' => 'Nenhuma empresa possui a integração de disponibilidade da agenda ativa.', 'latency_ms' => null];
        }

        $last = $this->fetchOne('SELECT status, operation, error_message, created_at FROM calendar_google_sync_logs ORDER BY id DESC LIMIT 1');
        if (!$last) {
            return ['status' => 'warning', 'message' => $enabled . ' configuração(ões) ativa(s), mas ainda não existe sincronização com Google registrada.', 'latency_ms' => null];
        }
        $status = strtolower((string) ($last['status'] ?? ''));
        if (in_array($status, ['failed', 'error'], true)) {
            return ['status' => 'warning', 'message' => 'A última sincronização Google falhou em ' . ($last['created_at'] ?? '') . ': ' . trim((string) ($last['error_message'] ?? 'sem detalhe')), 'latency_ms' => null];
        }
        return ['status' => 'ok', 'message' => $enabled . ' configuração(ões) ativa(s); última operação Google “' . ($last['operation'] ?? 'sincronização') . '” registrada em ' . ($last['created_at'] ?? '') . '.', 'latency_ms' => null];
    }

    private function checkAiReprocess(): array
    {
        $settings = $this->fetchOne('SELECT enabled, run_time, timezone, last_run_at, last_run_status, last_error FROM ai_reprocess_settings WHERE id = 1 LIMIT 1');
        if (!$settings) {
            return ['status' => 'warning', 'message' => 'A rotina de reprocessamento da IA ainda não foi configurada.', 'latency_ms' => null];
        }
        if ((int) ($settings['enabled'] ?? 0) !== 1) {
            return ['status' => 'warning', 'message' => 'A rotina automática da fila da IA está desativada.', 'latency_ms' => null];
        }

        $lastAt = strtotime((string) ($settings['last_run_at'] ?? '')) ?: 0;
        $lastStatus = (string) ($settings['last_run_status'] ?? '');
        if ($lastAt === 0) {
            return ['status' => 'warning', 'message' => 'Rotina ativa para ' . substr((string) ($settings['run_time'] ?? '03:00'), 0, 5) . ', mas nenhuma execução foi registrada.', 'latency_ms' => null];
        }
        if ($lastStatus === 'error') {
            return ['status' => 'warning', 'message' => 'A última execução da fila da IA falhou em ' . ($settings['last_run_at'] ?? '') . ': ' . trim((string) ($settings['last_error'] ?? 'consulte os detalhes da fila')), 'latency_ms' => null];
        }
        if ($lastAt < time() - 129600) {
            return ['status' => 'warning', 'message' => 'Rotina ativa, porém a última execução registrada ocorreu há mais de 36 horas: ' . ($settings['last_run_at'] ?? '') . '.', 'latency_ms' => null];
        }

        return ['status' => 'ok', 'message' => 'Rotina ativa; última execução ' . ($lastStatus !== '' ? $lastStatus : 'concluída') . ' em ' . ($settings['last_run_at'] ?? '') . '.', 'latency_ms' => null];
    }

    private function checkReporting(): array
    {
        $tableReady = $this->count("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'report_daily_metrics'") > 0;
        if (!$tableReady) {
            return ['status' => 'down', 'message' => 'A tabela report_daily_metrics não existe. A migration 048 precisa ser aplicada.', 'latency_ms' => null];
        }
        $last = $this->fetchOne('SELECT MAX(refreshed_at) AS refreshed_at, COUNT(*) AS rows_count FROM report_daily_metrics');
        $rows = (int) ($last['rows_count'] ?? 0);
        $lastAt = strtotime((string) ($last['refreshed_at'] ?? '')) ?: 0;
        if ($rows < 1 || $lastAt === 0) {
            return ['status' => 'warning', 'message' => 'Fundação de relatórios instalada, mas ainda não há métricas agregadas para comprovar a atualização.', 'latency_ms' => null];
        }
        if ($lastAt < time() - 172800) {
            return ['status' => 'warning', 'message' => 'A última agregação dos relatórios ocorreu há mais de 48 horas: ' . ($last['refreshed_at'] ?? '') . '.', 'latency_ms' => null];
        }
        return ['status' => 'ok', 'message' => $rows . ' linha(s) agregada(s); atualização mais recente em ' . ($last['refreshed_at'] ?? '') . '.', 'latency_ms' => null];
    }

    private function checkMigrations(): array
    {
        $requiredTables = ['conversation_flow_states', 'calendar_google_sync_logs', 'operations_backup_jobs', 'report_daily_metrics'];
        $missing = [];
        foreach ($requiredTables as $table) {
            if ($this->count("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . str_replace("'", "''", $table) . "'") < 1) {
                $missing[] = $table;
            }
        }
        if ($missing !== []) {
            return ['status' => 'down', 'message' => 'Estruturas obrigatórias ausentes: ' . implode(', ', $missing) . '.', 'latency_ms' => null];
        }
        return ['status' => 'ok', 'message' => 'Estruturas principais até a fundação de relatórios foram localizadas no banco.', 'latency_ms' => null];
    }

    private function checkBillingCron(): array
    {
        $heartbeat = $this->fetchOne(
            "SELECT status, message, checked_at FROM system_health_checks WHERE check_key = 'billing_cron_heartbeat' ORDER BY id DESC LIMIT 1"
        );

        if ($heartbeat) {
            $message = (string) ($heartbeat['message'] ?? '');
            // A partir da 36.5.6 somente o endpoint de cron grava o marcador “Régua (cron)”.
            // Heartbeats antigos também eram gravados por execução manual e não comprovam automação.
            $trustedCronHeartbeat = str_contains($message, 'Régua (cron)');
            if ($trustedCronHeartbeat) {
                $checkedAt = strtotime((string) ($heartbeat['checked_at'] ?? '')) ?: 0;
                $status = (string) ($heartbeat['status'] ?? 'warning');
                if ($checkedAt >= time() - 86400) {
                    return [
                        'status' => $status === 'down' ? 'warning' : $status,
                        'message' => $message !== '' ? $message : ('Régua executada em ' . ($heartbeat['checked_at'] ?? '')),
                        'latency_ms' => null,
                    ];
                }

                return [
                    'status' => 'warning',
                    'message' => 'A última execução automática da régua ocorreu há mais de 24 horas: ' . ($heartbeat['checked_at'] ?? ''),
                    'latency_ms' => null,
                ];
            }
        }

        $last = $this->fetchOne("SELECT created_at FROM billing_reminder_logs ORDER BY id DESC LIMIT 1");
        if (!$last) {
            return [
                'status' => 'warning',
                'message' => 'Nenhuma execução automática do cron foi registrada. Importe e ative o template “Cron da régua de cobrança” no n8n e valide a URL do webhook.',
                'latency_ms' => null,
            ];
        }

        return [
            'status' => 'warning',
            'message' => 'Há processamento da régua registrado em ' . ($last['created_at'] ?? '') . ', mas ainda não existe um heartbeat comprovando execução automática. Importe/ative o cron n8n e execute-o uma vez.',
            'latency_ms' => null,
        ];
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
        if (empty($backup['verified_at'])) {
            return ['status' => 'warning', 'message' => 'Existe backup com sucesso, mas o último arquivo ainda não foi marcado como verificado.', 'latency_ms' => null];
        }
        if (isset($backup['size_bytes']) && $backup['size_bytes'] !== null && (int) $backup['size_bytes'] < 1024) {
            return ['status' => 'warning', 'message' => 'O último backup foi registrado, porém o tamanho do arquivo é menor que 1 KB e precisa ser conferido.', 'latency_ms' => null];
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

    private function checkDefinitions(): array
    {
        return [
            'database' => ['label' => 'Banco de dados', 'category' => 'infrastructure', 'category_label' => 'Infraestrutura e aplicação', 'route' => '/status-sistema'],
            'migrations' => ['label' => 'Estrutura e migrations', 'category' => 'infrastructure', 'category_label' => 'Infraestrutura e aplicação', 'route' => '/status-sistema'],
            'evolution' => ['label' => 'WhatsApp / Evolution', 'category' => 'integration', 'category_label' => 'Integrações', 'route' => '/instances'],
            'n8n' => ['label' => 'n8n', 'category' => 'integration', 'category_label' => 'Integrações', 'route' => '/n8n'],
            'openai' => ['label' => 'OpenAI / IA', 'category' => 'integration', 'category_label' => 'Integrações', 'route' => '/ai-credentials'],
            'webhooks' => ['label' => 'Webhooks e mensagens', 'category' => 'integration', 'category_label' => 'Integrações', 'route' => '/conversations'],
            'calendar' => ['label' => 'Google Agenda', 'category' => 'integration', 'category_label' => 'Integrações', 'route' => '/calendar/availability'],
            'payments' => ['label' => 'Gateways e pagamentos', 'category' => 'integration', 'category_label' => 'Integrações', 'route' => '/payment-gateways'],
            'billing_cron' => ['label' => 'Cron de cobrança', 'category' => 'routine', 'category_label' => 'Rotinas automáticas', 'route' => '/billing-reminders'],
            'ai_reprocess' => ['label' => 'Rotina da fila da IA', 'category' => 'routine', 'category_label' => 'Rotinas automáticas', 'route' => '/central-operacao?tab=ai_reprocess'],
            'reporting' => ['label' => 'Agregação de relatórios', 'category' => 'routine', 'category_label' => 'Rotinas automáticas', 'route' => '/reports'],
            'backup' => ['label' => 'Backup', 'category' => 'routine', 'category_label' => 'Rotinas automáticas', 'route' => '/central-operacao?tab=backups'],
        ];
    }

    private function withExpectedChecks(array $checks): array
    {
        $definitions = $this->checkDefinitions();
        $byKey = [];
        foreach ($checks as $check) {
            $key = (string) ($check['check_key'] ?? '');
            if ($key !== '') {
                $byKey[$key] = $check;
            }
        }

        $result = [];
        foreach ($definitions as $key => $definition) {
            $check = $byKey[$key] ?? [
                'check_key' => $key,
                'label' => $definition['label'],
                'status' => 'unknown',
                'message' => 'Nenhuma verificação recente foi registrada para esta ferramenta.',
                'latency_ms' => null,
                'checked_at' => null,
            ];
            $check['label'] = $definition['label'];
            $check['category'] = $definition['category'];
            $check['category_label'] = $definition['category_label'];
            $check['route'] = $definition['route'];
            $result[] = $check;
        }

        $weight = ['down' => 0, 'warning' => 1, 'unknown' => 2, 'ok' => 3];
        usort($result, static function (array $a, array $b) use ($weight): int {
            $statusA = $weight[(string) ($a['status'] ?? 'unknown')] ?? 2;
            $statusB = $weight[(string) ($b['status'] ?? 'unknown')] ?? 2;
            if ($statusA !== $statusB) return $statusA <=> $statusB;
            if (($a['category'] ?? '') !== ($b['category'] ?? '')) return strcmp((string) ($a['category'] ?? ''), (string) ($b['category'] ?? ''));
            return strcasecmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
        });
        return $result;
    }

    private function checkHistory(): array
    {
        $history = [];
        try {
            $rows = Database::connection()->query(
                "SELECT * FROM system_health_checks WHERE check_key <> 'billing_cron_heartbeat' ORDER BY id DESC LIMIT 240"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $row) {
                $key = (string) ($row['check_key'] ?? '');
                if ($key === '' || count($history[$key] ?? []) >= 5) continue;
                $history[$key][] = $row;
            }
        } catch (Throwable) {
            return [];
        }
        return $history;
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
                if ($key === '' || $key === 'billing_cron_heartbeat' || isset($latestByKey[$key])) {
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

    private function activeBackupRoutine(): ?array
    {
        try {
            return $this->fetchOne(
                "SELECT id, name, status, last_success_at, last_error FROM operations_backup_routines WHERE status = 'active' ORDER BY id DESC LIMIT 1"
            );
        } catch (Throwable) {
            return null;
        }
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
            if (in_array((string) ($check['status'] ?? ''), ['warning', 'down'], true)) {
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

            $serverValue = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);
            if (is_string($serverValue) && trim($serverValue) !== '') {
                return trim($serverValue);
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
            ['title' => 'IA parou de responder', 'steps' => ['Verificar Respostas e integrações.', 'Conferir chave/base URL em Credenciais de IA.', 'Revisar horário, modo da conversa e intervalo mínimo; use Reprocessar IA quando houver mensagem pendente.']],
            ['title' => 'n8n não executa fluxo', 'steps' => ['Testar fluxo em Fluxos n8n.', 'Conferir URL do webhook, evento cadastrado e token de callback.', 'Abrir logs do n8n e logs de callback no RS Connect.']],
            ['title' => 'Pagamento não confirma', 'steps' => ['Conferir webhook do gateway.', 'Revisar logs em Gateways de pagamento.', 'Atualizar manualmente a cobrança se o gateway confirmou fora do sistema.']],
            ['title' => 'Backup atrasado', 'steps' => ['Executar backup no provedor/VPS.', 'Registrar backup manual no painel.', 'Configurar rotina externa usando /webhooks/operations/backups.']],
            ['title' => 'Backup local precisa ser conferido', 'steps' => ['Confirme se o arquivo existe no computador indicado.', 'Registre caminho completo ou observação que permita encontrar o arquivo.', 'Quando possível, use servidor/VPS, Google Drive ou S3/MinIO para validação futura.']],
        ];
    }
}
