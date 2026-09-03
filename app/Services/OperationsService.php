<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Env;
use PDO;
use RuntimeException;
use Throwable;

final class OperationsService
{
    public function dashboard(): array
    {
        $checks = $this->withExpectedChecks($this->latestChecks());
        $lastBackup = $this->lastBackup();
        $alerts = $this->activeAlerts($checks, $lastBackup);
        $incidents = $this->incidents();
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
            'incidents' => $incidents,
            'analytics' => $this->monitoringAnalytics($checks),
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

    public function runChecks(bool $processAfterHoursRecovery = false, string $triggerSource = 'manual'): void
    {
        $started = microtime(true);
        $runId = $this->startMonitorRun($triggerSource);
        try {
            $this->recordCheck('database', 'Banco de dados', $this->checkDatabase());
            $this->recordCheck('migrations', 'Estrutura e migrations', $this->checkMigrations());
            $this->recordCheck('disk', 'Espaço em disco', $this->checkDisk());
            $this->recordCheck('evolution', 'WhatsApp / Evolution', $this->checkEvolution());
            $this->recordCheck('n8n', 'n8n', $this->checkN8n());
            $this->recordCheck('openai', 'OpenAI / IA', $this->checkOpenAi());
            $this->recordCheck('webhooks', 'Webhooks e mensagens', $this->checkWebhooks());
            $this->recordCheck('message_queue', 'Fila de mensagens', $this->checkMessageQueue());
            $this->recordCheck('calendar', 'Google Agenda', $this->checkCalendar());
            $this->recordCheck('payments', 'Gateways e pagamentos', $this->checkPayments());
            $this->refreshBillingCronCheck();
            $this->recordCheck('ai_reprocess', 'Rotina da fila da IA', $this->checkAiReprocess());
            $this->recordCheck('after_hours_recovery', 'Recuperação pós-horário', $this->checkAfterHoursRecovery($processAfterHoursRecovery));
            $this->recordCheck('reporting', 'Agregação de relatórios', $this->checkReporting());
            $this->recordCheck('backup', 'Backup', $this->checkBackupAge());
            $this->syncBlockedEvolutionIncidents();
            $this->syncSubscriptionAccessIncidents();
            $this->finishMonitorRun($runId, $started, null);
            $this->dispatchDailyHealthDigest();
        } catch (Throwable $exception) {
            $this->finishMonitorRun($runId, $started, $exception->getMessage());
            throw $exception;
        }
    }

    public function refreshBillingCronCheck(): void
    {
        $this->recordCheck('billing_cron', 'Cron de cobrança', $this->checkBillingCron());
        $this->syncSubscriptionAccessIncidents();
    }

    public function refreshMessagingChecks(): void
    {
        $this->recordCheck('evolution', 'WhatsApp / Evolution', $this->checkEvolution());
        $this->recordCheck('message_queue', 'Fila de mensagens', $this->checkMessageQueue());
        $this->recordCheck('ai_reprocess', 'Rotina da fila da IA', $this->checkAiReprocess());
        $this->syncBlockedEvolutionIncidents();
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
            'verified_at' => $verified ? \App\Core\Clock::nowUtc() : null,
            'verified_by' => $verified ? Auth::id() : null,
            'started_at' => \App\Core\Clock::nowUtc(),
            'finished_at' => \App\Core\Clock::nowUtc(),
        ]);

        $this->recordIncident('backup.manual_registered', 'info', 'Backup manual registrado no painel.', [
            'storage_type' => $normalizedStorage,
            'file_name' => $resolvedFileName,
            'location' => $location,
            'verified' => $verified,
        ]);
        $this->recordCheck('backup', 'Backup', $this->checkBackupAge());
        $this->syncBlockedEvolutionIncidents();
    }

    public function registerExternalBackup(array $payload): array
    {
        $result = (new BackupAutomationService())->processCallback($payload);
        if (!empty($result['ok'])) {
            $this->recordCheck('backup', 'Backup', $this->checkBackupAge());
        }
        return $result;
    }

    /**
     * Encerra um incidente e, para falhas de WhatsApp/fila, pode silenciar a conexão
     * e cancelar as pendências preservadas para impedir reabertura e novos lembretes.
     *
     * @return array{resolved:bool,messaging_incident:bool,instances_paused:int,cancelled_messages:int,cancelled_ai_pending:int,cancelled_after_hours:int}
     */
    public function resolveIncident(int $id, bool $releaseQueue = false): array
    {
        if ($id <= 0) {
            throw new RuntimeException('Situação operacional inválida.');
        }

        $pdo = Database::connection();
        $incidentStatement = $pdo->prepare('SELECT * FROM system_incidents WHERE id = :id LIMIT 1');
        $incidentStatement->execute(['id' => $id]);
        $incident = $incidentStatement->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$incident) {
            throw new RuntimeException('Situação operacional não encontrada.');
        }
        if (!empty($incident['resolved_at'])) {
            throw new RuntimeException('Esta situação já foi normalizada.');
        }

        $event = trim((string) ($incident['event'] ?? ''));
        $messagingIncident = $this->isMessagingIncidentEvent($event);
        $summary = [
            'resolved' => false,
            'messaging_incident' => $messagingIncident,
            'instances_paused' => 0,
            'cancelled_messages' => 0,
            'cancelled_ai_pending' => 0,
            'cancelled_after_hours' => 0,
        ];

        $instanceIds = $messagingIncident ? $this->incidentInstanceIds($incident) : [];
        $reason = 'Cancelada manualmente ao normalizar a situação operacional #' . $id . '.';

        $pdo->beginTransaction();
        try {
            if ($messagingIncident && $instanceIds !== []) {
                $summary['instances_paused'] = $this->pauseDisconnectedOperationalAlerts($pdo, $instanceIds);
            }

            if ($messagingIncident && $releaseQueue) {
                if (!$this->conversationMessageCancellationSupported($pdo)) {
                    throw new RuntimeException('A migration 098 precisa ser aplicada antes de liberar a fila.');
                }

                if ($instanceIds === []) {
                    $instanceIds = $this->queuedInstanceIds((int) ($incident['tenant_id'] ?? 0));
                    if ($instanceIds !== []) {
                        $summary['instances_paused'] += $this->pauseDisconnectedOperationalAlerts($pdo, $instanceIds);
                    }
                }

                if ($instanceIds !== []) {
                    // A fila da IA é marcada como cancelada antes das mensagens de saída,
                    // pois a seleção usa as falhas de entrega como uma das evidências da pendência.
                    $cancelledAi = (new AiReprocessService())->cancelPendingForInstances(
                        $instanceIds,
                        $reason,
                        Auth::id()
                    );
                    $summary['cancelled_ai_pending'] = (int) ($cancelledAi['pending_cancelled'] ?? 0);
                    $summary['cancelled_after_hours'] = (int) ($cancelledAi['after_hours_cancelled'] ?? 0);
                    $summary['cancelled_messages'] = $this->cancelOutgoingQueue($pdo, $instanceIds, $reason);
                }
            }

            $statement = $pdo->prepare(
                'UPDATE system_incidents SET resolved_at = NOW(), last_seen_at = NOW() '
                . 'WHERE id = :id AND resolved_at IS NULL'
            );
            $statement->execute(['id' => $id]);
            if ($statement->rowCount() < 1) {
                throw new RuntimeException('A situação já foi normalizada por outra execução.');
            }

            $pdo->commit();
            $summary['resolved'] = true;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        try {
            (new OperationalAlertService())->dispatchRecovered($id);
        } catch (Throwable) {
            // A normalização já foi persistida; uma falha no canal de aviso não deve revertê-la.
        }

        // Recalcula imediatamente os checks relacionados. Como as conexões afetadas
        // foram pausadas, a mesma causa deixa de reabrir o incidente a cada monitoramento.
        try {
            if ($messagingIncident) {
                $this->refreshMessagingChecks();
            } elseif ($event === 'operations.alert.payments') {
                $this->recordCheck('payments', 'Gateways e pagamentos', $this->checkPayments());
            }
        } catch (Throwable) {
            // O próximo ciclo do monitor fará a reconciliação caso a atualização imediata falhe.
        }

        return $summary;
    }

    public function validBackupToken(string $token): bool
    {
        $token = trim($token);
        if ($token === '') {
            return false;
        }

        // 36.6.20: aceita qualquer alias de token de backup explicitamente configurado.
        // Isso evita quebrar um callback já em voo quando OPERATIONS_BACKUP_TOKEN é
        // rotacionado mas BACKUP_WEBHOOK_TOKEN ainda mantém o valor anterior.
        foreach (['OPERATIONS_BACKUP_TOKEN', 'BACKUP_WEBHOOK_TOKEN', 'RS_CONNECT_BACKUP_TOKEN'] as $key) {
            $expected = trim((string) Env::get($key, ''));
            if ($expected === '') {
                $serverValue = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);
                $expected = is_string($serverValue) ? trim($serverValue) : '';
            }
            if ($expected !== '' && hash_equals($expected, $token)) {
                return true;
            }
        }

        return false;
    }

    public function validMonitorToken(string $token): bool
    {
        $expected = trim((string) Env::get('OPERATIONS_MONITOR_TOKEN', ''));
        if ($expected === '') {
            $expected = $this->backupToken();
        }
        return $expected !== '' && $token !== '' && hash_equals($expected, $token);
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

    private function supportsEvolutionAlertSuppression(): bool
    {
        static $supported = null;
        if ($supported !== null) {
            return $supported;
        }

        try {
            $statement = Database::connection()->query(
                'SELECT COUNT(*)
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = "evolution_instances"
                   AND COLUMN_NAME = "operational_alerts_enabled"'
            );
            return $supported = (int) $statement->fetchColumn() > 0;
        } catch (Throwable) {
            return $supported = false;
        }
    }

    private function evolutionAlertsEnabledSql(string $alias = ''): string
    {
        if (!$this->supportsEvolutionAlertSuppression()) {
            return '1 = 1';
        }

        $prefix = $alias !== '' ? rtrim($alias, '.') . '.' : '';
        return 'COALESCE(' . $prefix . 'operational_alerts_enabled, 1) = 1';
    }

    private function evolutionAlertsPausedSql(string $alias = ''): string
    {
        if (!$this->supportsEvolutionAlertSuppression()) {
            return '1 = 0';
        }

        $prefix = $alias !== '' ? rtrim($alias, '.') . '.' : '';
        return 'COALESCE(' . $prefix . 'operational_alerts_enabled, 1) = 0';
    }

    private function checkEvolution(): array
    {
        $alertsEnabled = $this->evolutionAlertsEnabledSql();
        $alertsPaused = $this->evolutionAlertsPausedSql();
        $instances = $this->count('SELECT COUNT(*) FROM evolution_instances WHERE ' . $alertsEnabled);
        $paused = $this->count('SELECT COUNT(*) FROM evolution_instances WHERE ' . $alertsPaused);
        $connected = $this->count(
            "SELECT COUNT(*) FROM evolution_instances
             WHERE " . $alertsEnabled . "
               AND LOWER(COALESCE(NULLIF(connection_state, ''), status)) IN ('connected','open','active','online')"
        );
        $incoming24 = $this->count(
            "SELECT COUNT(*)
             FROM conversation_messages cm
             LEFT JOIN conversations c ON c.id = cm.conversation_id AND c.tenant_id = cm.tenant_id
             LEFT JOIN evolution_instances i ON i.id = c.evolution_instance_id AND i.tenant_id = cm.tenant_id
             WHERE cm.direction = 'incoming'
               AND cm.created_at >= (NOW() - INTERVAL 24 HOUR)
               AND (i.id IS NULL OR " . $this->evolutionAlertsEnabledSql('i') . ")"
        );

        $pausedText = $paused > 0
            ? '; ' . $paused . ' conexão(ões) pausada(s) intencionalmente, sem alertas operacionais'
            : '';

        if ($instances < 1 && $paused > 0) {
            return [
                'status' => 'ok',
                'message' => 'Nenhuma conexão está sendo monitorada no momento; ' . $paused . ' conexão(ões) foi(ram) pausada(s) intencionalmente. As filas vinculadas permanecem preservadas sem gerar notificações.',
                'latency_ms' => null,
            ];
        }

        if ($connected > 0) {
            $lastEvolutionFailure = $this->latestEvolutionFailure();
            $lastSuccess = $this->latestEvolutionSuccess();
            $failureAt = strtotime((string) ($lastEvolutionFailure['created_at'] ?? '')) ?: 0;
            $successAt = strtotime((string) ($lastSuccess['created_at'] ?? '')) ?: 0;
            if ($failureAt > $successAt && $failureAt >= time() - 86400) {
                return [
                    'status' => 'warning',
                    'message' => $connected . '/' . max($instances, $connected) . ' instância(s) monitorada(s) conectada(s), porém o envio mais recente falhou: ' . trim((string) ($lastEvolutionFailure['error_message'] ?? 'erro sem detalhe')) . '.' . $pausedText . ' Abra Fila da IA/WhatsApp para validar o estado ao vivo.',
                    'latency_ms' => null,
                ];
            }
            return [
                'status' => $connected === $instances ? 'ok' : 'warning',
                'message' => $connected . '/' . max($instances, $connected) . ' instância(s) monitorada(s) conectada(s); ' . $incoming24 . ' mensagem(ns) recebida(s) nas últimas 24h' . $pausedText . '.',
                'latency_ms' => null,
            ];
        }

        $url = trim((string) Env::get('EVOLUTION_DEFAULT_URL', ''));
        if ($instances > 0 && $url !== '') {
            $endpoint = $this->checkHttpEndpoint($url, 'Evolution não configurada');
            return [
                'status' => 'warning',
                'message' => 'Existem ' . $instances . ' conexão(ões) monitorada(s), mas nenhuma aparece conectada. ' . ($endpoint['message'] ?? '') . $pausedText,
                'latency_ms' => $endpoint['latency_ms'] ?? null,
            ];
        }

        if ($instances > 0) {
            return ['status' => 'warning', 'message' => 'Nenhuma instância monitorada está conectada ou a URL padrão não foi configurada.' . $pausedText, 'latency_ms' => null];
        }

        return ['status' => 'warning', 'message' => 'Nenhuma instância Evolution conectada ou configurada para monitoramento.', 'latency_ms' => null];
    }

    private function checkN8n(): array
    {
        $localActiveFlows = $this->count("SELECT COUNT(*) FROM n8n_tenant_flows WHERE status = 'active'")
            + $this->count("SELECT COUNT(*) FROM n8n_flows WHERE status = 'active'");
        $success24 = $this->count("SELECT COUNT(*) FROM n8n_flow_logs WHERE status = 'success' AND created_at >= (NOW() - INTERVAL 24 HOUR)");
        $errors24 = $this->count("SELECT COUNT(*) FROM n8n_flow_logs WHERE status = 'error' AND created_at >= (NOW() - INTERVAL 24 HOUR)");
        $lastSuccess = $this->fetchOne("SELECT created_at FROM n8n_flow_logs WHERE status = 'success' ORDER BY id DESC LIMIT 1");
        $lastError = $this->fetchOne("SELECT created_at, error_message FROM n8n_flow_logs WHERE status = 'error' ORDER BY id DESC LIMIT 1");
        $successAt = strtotime((string) ($lastSuccess['created_at'] ?? '')) ?: 0;
        $errorAt = strtotime((string) ($lastError['created_at'] ?? '')) ?: 0;
        $consecutiveErrors = $this->consecutiveN8nErrors();
        $criticalAfter = max(2, (int) Env::get('OPERATIONS_N8N_CONSECUTIVE_ERRORS_CRITICAL', 3));

        $live = (new N8nLiveMetricsService())->snapshot();
        $liveAvailable = !empty($live['available']);
        $latency = isset($live['latency_ms']) && $live['latency_ms'] !== null ? (int) $live['latency_ms'] : null;

        if ($liveAvailable) {
            $activeFlows = max(0, (int) ($live['active'] ?? 0));
            $totalFlows = max(0, (int) ($live['total'] ?? 0));
            $inactiveFlows = max(0, (int) ($live['inactive'] ?? 0));
            $archivedFlows = max(0, (int) ($live['archived'] ?? 0));
            $sourceText = $activeFlows . ' workflow(s) ativo(s) confirmado(s) diretamente no n8n'
                . ' de ' . $totalFlows . ' encontrado(s)';
            if ($inactiveFlows > 0) {
                $sourceText .= '; ' . $inactiveFlows . ' inativo(s)';
            }
            if ($archivedFlows > 0) {
                $sourceText .= '; ' . $archivedFlows . ' arquivado(s)';
            }
            if ($localActiveFlows !== $activeFlows) {
                $sourceText .= '. RS Connect possui ' . $localActiveFlows . ' cadastro(s) marcado(s) como ativo(s), portanto há divergência de origem';
            }

            if ($activeFlows < 1) {
                return [
                    'status' => 'warning',
                    'message' => 'A API do n8n respondeu, mas nenhum workflow ativo foi confirmado. ' . $sourceText . '.',
                    'latency_ms' => $latency,
                ];
            }

            if ($consecutiveErrors >= $criticalAfter) {
                return [
                    'status' => 'down',
                    'message' => $sourceText . '. Há ' . $consecutiveErrors
                        . ' falha(s) consecutiva(s) registrada(s) pelo RS Connect. Último erro: '
                        . trim((string) ($lastError['error_message'] ?? 'erro sem detalhe')),
                    'latency_ms' => $latency,
                ];
            }

            if ($errorAt > $successAt && $errorAt >= time() - 86400) {
                return [
                    'status' => 'warning',
                    'message' => $sourceText . '. A execução mais recente registrada pelo RS Connect foi uma falha: '
                        . trim((string) ($lastError['error_message'] ?? 'erro sem detalhe')),
                    'latency_ms' => $latency,
                ];
            }

            if ($success24 > 0) {
                return [
                    'status' => 'ok',
                    'message' => $sourceText . '. Registros RS Connect nas últimas 24h: '
                        . $success24 . ' sucesso(s) e ' . $errors24 . ' erro(s).',
                    'latency_ms' => $latency,
                ];
            }

            return [
                'status' => 'warning',
                'message' => $sourceText . ', mas não há sucesso registrado nas últimas 24h para comprovar execução recente.',
                'latency_ms' => $latency,
            ];
        }

        $liveError = trim((string) ($live['error'] ?? 'consulta em tempo real indisponível'));
        $localText = $localActiveFlows . ' fluxo(s) cadastrado(s) como ativo(s) no RS Connect';

        if ($localActiveFlows < 1) {
            return [
                'status' => 'warning',
                'message' => 'Não foi possível confirmar os workflows diretamente no n8n (' . $liveError . ') e não há fluxo ativo no cadastro local.',
                'latency_ms' => $latency,
            ];
        }

        return [
            'status' => 'warning',
            'message' => 'Estado real dos workflows não confirmado: ' . $liveError . '. ' . $localText
                . '; esse número é apenas referência local. Configure N8N_API_KEY para validar o n8n em tempo real.',
            'latency_ms' => $latency,
        ];
    }

    private function consecutiveN8nErrors(): int
    {
        try {
            $rows = Database::connection()->query(
                'SELECT status FROM n8n_flow_logs ORDER BY id DESC LIMIT 20'
            )->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $count = 0;
            foreach ($rows as $status) {
                if ((string) $status !== 'error') {
                    break;
                }
                $count++;
            }
            return $count;
        } catch (Throwable) {
            return 0;
        }
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
        $lastError = $this->latestAiProviderFailure();
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

    private function latestAiProviderFailure(): ?array
    {
        try {
            $rows = Database::connection()->query(
                "SELECT created_at, error_message, raw_json FROM ai_automation_logs WHERE (event = 'ai.failed' OR status = 'error') ORDER BY id DESC LIMIT 40"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $row) {
                $error = trim((string) ($row['error_message'] ?? ''));
                $normalized = mb_strtolower($error);
                $raw = json_decode((string) ($row['raw_json'] ?? ''), true);
                $phase = is_array($raw) ? strtolower(trim((string) ($raw['failure_phase'] ?? ''))) : '';
                $isEvolution = str_starts_with($normalized, 'evolution ')
                    || preg_match('/^http\s+\d+/i', $error) === 1
                    || str_contains($normalized, 'sendtext')
                    || str_contains($normalized, 'whatsapp')
                    || str_starts_with($phase, 'evolution.');
                if ($isEvolution) {
                    continue;
                }
                $isAi = str_starts_with($normalized, 'ia http')
                    || str_contains($normalized, 'openai')
                    || str_contains($normalized, 'gemini')
                    || str_contains($normalized, 'model')
                    || str_contains($normalized, 'api key')
                    || str_contains($normalized, 'credencial')
                    || str_starts_with($phase, 'ai.');
                if ($isAi || $phase === '') {
                    return $row;
                }
            }
        } catch (Throwable) {
        }
        return null;
    }

    private function latestEvolutionFailure(): ?array
    {
        try {
            $enabled = $this->evolutionAlertsEnabledSql('i');
            $rows = Database::connection()->query(
                "SELECT al.created_at, al.error_message, al.raw_json
                 FROM ai_automation_logs al
                 LEFT JOIN conversations c
                    ON c.id = al.conversation_id
                   AND c.tenant_id = al.tenant_id
                 LEFT JOIN evolution_instances i
                    ON i.id = c.evolution_instance_id
                   AND i.tenant_id = al.tenant_id
                 WHERE (al.event = 'ai.failed' OR al.status = 'error')
                   AND (i.id IS NULL OR " . $enabled . ")
                 ORDER BY al.id DESC
                 LIMIT 40"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $row) {
                $error = trim((string) ($row['error_message'] ?? ''));
                $normalized = mb_strtolower($error);
                $raw = json_decode((string) ($row['raw_json'] ?? ''), true);
                $phase = is_array($raw) ? strtolower(trim((string) ($raw['failure_phase'] ?? ''))) : '';
                if (str_starts_with($normalized, 'evolution ')
                    || preg_match('/^http\s+\d+/i', $error) === 1
                    || str_contains($normalized, 'sendtext')
                    || str_contains($normalized, 'whatsapp')
                    || str_starts_with($phase, 'evolution.')) {
                    return $row;
                }
            }
        } catch (Throwable) {
        }
        return null;
    }

    private function latestEvolutionSuccess(): ?array
    {
        try {
            $enabled = $this->evolutionAlertsEnabledSql('i');
            return $this->fetchOne(
                "SELECT al.created_at
                 FROM ai_automation_logs al
                 LEFT JOIN conversations c
                    ON c.id = al.conversation_id
                   AND c.tenant_id = al.tenant_id
                 LEFT JOIN evolution_instances i
                    ON i.id = c.evolution_instance_id
                   AND i.tenant_id = al.tenant_id
                 WHERE al.event = 'ai.replied'
                   AND al.status = 'success'
                   AND (i.id IS NULL OR " . $enabled . ")
                 ORDER BY al.id DESC
                 LIMIT 1"
            );
        } catch (Throwable) {
            return null;
        }
    }

    private function checkWebhooks(): array
    {
        $windowHours = max(1, min(168, (int) Env::get('OPERATIONS_WEBHOOK_INACTIVITY_HOURS', 24)));
        $recentMessages = $this->count(
            'SELECT COUNT(*) FROM conversation_messages WHERE created_at >= (NOW() - INTERVAL ' . $windowHours . ' HOUR)'
        );
        $recentN8n = $this->count(
            'SELECT COUNT(*) FROM n8n_flow_logs WHERE created_at >= (NOW() - INTERVAL ' . $windowHours . ' HOUR)'
        );
        $connectedInstances = $this->count(
            "SELECT COUNT(*) FROM evolution_instances WHERE status IN ('connected','open','active','online')"
        );
        $activeFlows = $this->count("SELECT COUNT(*) FROM n8n_tenant_flows WHERE status = 'active'")
            + $this->count("SELECT COUNT(*) FROM n8n_flows WHERE status = 'active'");

        if ($recentMessages > 0 || $recentN8n > 0) {
            return [
                'status' => 'ok',
                'message' => $recentMessages . ' mensagem(ns) e ' . $recentN8n . ' evento(s) n8n nas últimas '
                    . $windowHours . 'h.',
                'latency_ms' => null,
            ];
        }
        if ($connectedInstances < 1 && $activeFlows < 1) {
            return [
                'status' => 'unknown',
                'message' => 'Não há instância conectada nem fluxo n8n ativo para exigir heartbeat de webhook.',
                'latency_ms' => null,
            ];
        }
        return [
            'status' => 'warning',
            'message' => 'Nenhum evento de mensagens ou n8n foi registrado nas últimas ' . $windowHours
                . 'h, apesar de existirem integrações ativas. Revise os webhooks.',
            'latency_ms' => null,
        ];
    }

    private function checkPayments(): array
    {
        $gateways = $this->count("SELECT COUNT(*) FROM payment_gateways WHERE status = 'active'");
        if ($gateways < 1) {
            return ['status' => 'warning', 'message' => 'Nenhum gateway de pagamento ativo foi encontrado.', 'latency_ms' => null];
        }

        try {
            $rows = Database::connection()->query(
                "SELECT pg.id,
                        pg.label,
                        pg.environment,
                        COUNT(e.id) AS event_count,
                        SUM(CASE WHEN e.status IN ('error','failed') THEN 1 ELSE 0 END) AS error_count,
                        MAX(CASE WHEN e.status IN ('error','failed') THEN e.id END) AS last_error_id,
                        MAX(CASE
                            WHEN e.status = 'success'
                              OR (
                                  e.event = CONCAT('payment.webhook.', pg.provider)
                                  AND e.status = 'ignored'
                              )
                            THEN e.id
                        END) AS last_success_id,
                        MAX(CASE WHEN e.status IN ('error','failed') THEN e.created_at END) AS last_error_at,
                        MAX(CASE
                            WHEN e.status = 'success'
                              OR (
                                  e.event = CONCAT('payment.webhook.', pg.provider)
                                  AND e.status = 'ignored'
                              )
                            THEN e.created_at
                        END) AS last_success_at
                 FROM payment_gateways pg
                 LEFT JOIN payment_gateway_events e
                   ON e.gateway_id = pg.id
                  AND e.created_at >= (NOW() - INTERVAL 7 DAY)
                 WHERE pg.status = 'active'
                 GROUP BY pg.id, pg.label, pg.environment
                 ORDER BY pg.id"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $exception) {
            return [
                'status' => 'unknown',
                'message' => 'Não foi possível comparar as últimas confirmações e falhas dos meios de pagamento.',
                'latency_ms' => null,
            ];
        }

        $events = 0;
        $historicalErrors = 0;
        $activeFailures = [];
        $lastSuccessAt = '';

        foreach ($rows as $row) {
            $events += (int) ($row['event_count'] ?? 0);
            $historicalErrors += (int) ($row['error_count'] ?? 0);

            $gatewayLastSuccess = trim((string) ($row['last_success_at'] ?? ''));
            if ($gatewayLastSuccess !== '' && ($lastSuccessAt === '' || strcmp($gatewayLastSuccess, $lastSuccessAt) > 0)) {
                $lastSuccessAt = $gatewayLastSuccess;
            }

            $lastErrorId = (int) ($row['last_error_id'] ?? 0);
            $lastSuccessId = (int) ($row['last_success_id'] ?? 0);
            if ($lastErrorId > 0 && $lastErrorId > $lastSuccessId) {
                $activeFailures[] = [
                    'label' => trim((string) ($row['label'] ?? 'Gateway')) ?: 'Gateway',
                    'environment' => trim((string) ($row['environment'] ?? '')),
                    'last_error_at' => trim((string) ($row['last_error_at'] ?? '')),
                ];
            }
        }

        if ($activeFailures !== []) {
            $failure = $activeFailures[0];
            $environment = ($failure['environment'] ?? '') === 'sandbox' ? 'Sandbox' : 'Produção';
            $when = trim((string) ($failure['last_error_at'] ?? ''));
            $detail = $when !== '' ? ' Última falha em ' . $when . '.' : '';

            return [
                'status' => 'warning',
                'message' => count($activeFailures) . ' meio(s) de pagamento possui(em) falha sem confirmação posterior de comunicação autenticada ou operação bem-sucedida. '
                    . 'Isso não confirma que o serviço continua indisponível; significa que ainda não houve webhook autenticado nem nova atualização que comprove a recuperação. '
                    . ($failure['label'] ?? 'Gateway') . ' (' . $environment . ').' . $detail,
                'latency_ms' => null,
            ];
        }

        if ($events < 1) {
            return [
                'status' => 'unknown',
                'message' => $gateways . ' gateway(s) ativo(s). Não foram identificados eventos de pagamento nos últimos 7 dias para validar o fluxo automaticamente; isso não indica falha por si só.',
                'latency_ms' => null,
            ];
        }

        if ($historicalErrors > 0) {
            $confirmed = $lastSuccessAt !== '' ? ' Última confirmação bem-sucedida em ' . $lastSuccessAt . '.' : '';
            return [
                'status' => 'ok',
                'message' => $gateways . ' gateway(s) ativo(s); ' . $historicalErrors
                    . ' falha(s) histórica(s) foi(ram) recuperada(s).' . $confirmed . ' Nenhuma falha ativa.',
                'latency_ms' => null,
            ];
        }

        return [
            'status' => 'ok',
            'message' => $gateways . ' gateway(s) ativo(s); ' . $events . ' evento(s) de pagamento nos últimos 7 dias sem falha ativa.',
            'latency_ms' => null,
        ];
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
        $settings = $this->fetchOne('SELECT enabled, run_time, timezone, last_run_at, last_run_status, last_summary_json, last_error FROM ai_reprocess_settings WHERE id = 1 LIMIT 1');
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

        $dashboard = (new AiReprocessService())->dashboard();
        $activeBlocked = (int) ($dashboard['pending_blocked_total'] ?? 0);
        $pausedPending = (int) ($dashboard['pending_paused_total'] ?? 0);
        $actionablePending = (int) ($dashboard['pending_actionable_total'] ?? $dashboard['pending_total'] ?? 0);

        if ($lastStatus === 'error') {
            return ['status' => 'warning', 'message' => 'A última execução da fila da IA falhou em ' . ($settings['last_run_at'] ?? '') . ': ' . trim((string) ($settings['last_error'] ?? 'consulte os detalhes da fila')), 'latency_ms' => null];
        }
        if ($activeBlocked > 0) {
            return ['status' => 'warning', 'message' => $activeBlocked . ' grupo(s) de pendência aguardam reconexão de uma conexão monitorada. A fila foi preservada sem repetir tentativas enquanto a instância estiver desconectada.', 'latency_ms' => null];
        }
        if ($actionablePending === 0 && $pausedPending > 0) {
            return ['status' => 'ok', 'message' => $pausedPending . ' pendência(s) estão vinculadas a conexões pausadas intencionalmente. A fila continua preservada e não gera alertas nem novas tentativas até a reconexão.', 'latency_ms' => null];
        }
        if ($lastAt < time() - 129600) {
            return ['status' => 'warning', 'message' => 'Rotina ativa, porém a última execução registrada ocorreu há mais de 36 horas: ' . ($settings['last_run_at'] ?? '') . '.', 'latency_ms' => null];
        }

        return ['status' => 'ok', 'message' => 'Rotina ativa; última execução ' . ($lastStatus !== '' ? $lastStatus : 'concluída') . ' em ' . ($settings['last_run_at'] ?? '') . '.', 'latency_ms' => null];
    }

    private function checkAfterHoursRecovery(bool $executeRecovery = false): array
    {
        try {
            $limit = max(1, min(200, (int) Env::get('OPERATIONS_AFTER_HOURS_RECOVERY_LIMIT', 25)));
            $service = new AiAfterHoursRecoveryService();
            $summary = $executeRecovery
                ? $service->recoverDue($limit, 'operations_monitor')
                : ['recovered' => 0, 'waiting_hours' => 0, 'errors' => 0];
            $counts = $service->pendingCounts();

            $errors = (int) ($summary['errors'] ?? 0) + (int) ($counts['errors'] ?? 0);
            if ($errors > 0) {
                return [
                    'status' => 'warning',
                    'message' => 'A recuperação pós-horário encontrou ' . $errors . ' pendência(s) com erro. '
                        . (int) ($counts['total'] ?? 0) . ' conversa(s) continuam preservadas para nova tentativa.',
                    'latency_ms' => null,
                ];
            }

            $parts = [];
            $recovered = (int) ($summary['recovered'] ?? 0);
            if ($recovered > 0) {
                $parts[] = $recovered . ' conversa(s) recuperada(s) nesta verificação';
            }
            $total = (int) ($counts['total'] ?? 0);
            if ($total > 0) {
                $parts[] = $total . ' pendência(s) preservada(s)';
            }
            $blockedPlan = (int) ($counts['blocked_plan'] ?? 0);
            if ($blockedPlan > 0) {
                $parts[] = $blockedPlan . ' aguardando franquia de IA';
            }
            $blockedHuman = (int) ($counts['blocked_human'] ?? 0);
            if ($blockedHuman > 0) {
                $parts[] = $blockedHuman . ' respeitando atendimento humano/assistente pausado';
            }
            $pausedConnections = (int) ($counts['paused'] ?? 0);
            if ($pausedConnections > 0) {
                $parts[] = $pausedConnections . ' vinculada(s) a WhatsApp pausado, sem alertas ou reprocessamento';
            }
            $waiting = (int) ($summary['waiting_hours'] ?? 0);
            if ($waiting > 0) {
                $parts[] = $waiting . ' ainda fora do horário válido';
            }

            return [
                'status' => 'ok',
                'message' => $parts ? implode('; ', $parts) . '.' : ($executeRecovery
                    ? 'Nenhuma conversa pós-horário pendente; rotina pronta para a próxima janela de atendimento.'
                    : 'Nenhuma conversa pós-horário pendente. O processamento automático ocorre pelo Monitor operacional ou pela ação de reprocessar a fila.'),
                'latency_ms' => null,
            ];
        } catch (Throwable $exception) {
            return [
                'status' => 'warning',
                'message' => 'Não foi possível executar a recuperação pós-horário: ' . $exception->getMessage(),
                'latency_ms' => null,
            ];
        }
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
        try {
            $service = new MigrationService(Database::connection(), dirname(__DIR__, 2));
            $status = $service->status();

            if (!$status['registry']) {
                return [
                    'status' => 'down',
                    'message' => 'O registro schema_migrations ainda não foi criado. Execute o baseline seguro da ENT-027.',
                    'latency_ms' => null,
                ];
            }

            if ($status['drift'] !== []) {
                return [
                    'status' => 'down',
                    'message' => 'Há migrations aplicadas com checksum divergente: ' . implode(', ', $status['drift']) . '.',
                    'latency_ms' => null,
                ];
            }

            if ((int) $status['pending'] > 0) {
                return [
                    'status' => 'warning',
                    'message' => $status['pending'] . ' migration(s) pendente(s). Execute php bin/migrate.php up.',
                    'latency_ms' => null,
                ];
            }

            return [
                'status' => 'ok',
                'message' => $status['applied'] . ' migrations registradas, sem pendências ou alterações históricas.',
                'latency_ms' => null,
            ];
        } catch (Throwable $exception) {
            return [
                'status' => 'down',
                'message' => 'Não foi possível validar o histórico de migrations.',
                'latency_ms' => null,
            ];
        }
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


    private function checkDisk(): array
    {
        $path = trim((string) Env::get('OPERATIONS_DISK_PATH', dirname(__DIR__, 2)));
        if ($path === '' || !is_dir($path)) {
            return ['status' => 'warning', 'message' => 'Caminho de disco inválido: ' . $path, 'latency_ms' => null];
        }
        $total = @disk_total_space($path);
        $free = @disk_free_space($path);
        if (!is_float($total) && !is_int($total) || !is_float($free) && !is_int($free) || $total <= 0) {
            return ['status' => 'warning', 'message' => 'Não foi possível medir o espaço disponível em ' . $path . '.', 'latency_ms' => null];
        }
        $freePercent = ($free / $total) * 100;
        $freeGb = $free / 1073741824;
        $warningPercent = max(1.0, min(90.0, (float) Env::get('OPERATIONS_DISK_WARNING_PERCENT', 20)));
        $criticalPercent = max(1.0, min($warningPercent, (float) Env::get('OPERATIONS_DISK_CRITICAL_PERCENT', 10)));
        $minimumFreeGb = max(0.1, (float) Env::get('OPERATIONS_DISK_MIN_FREE_GB', 2));
        $message = number_format($freeGb, 1, ',', '.') . ' GB livres ('
            . number_format($freePercent, 1, ',', '.') . '%) em ' . $path . '.';
        if ($freePercent <= $criticalPercent || $freeGb <= max(0.1, $minimumFreeGb / 2)) {
            return ['status' => 'down', 'message' => $message . ' Libere espaço imediatamente.', 'latency_ms' => null];
        }
        if ($freePercent <= $warningPercent || $freeGb <= $minimumFreeGb) {
            return ['status' => 'warning', 'message' => $message . ' O limite de atenção foi atingido.', 'latency_ms' => null];
        }
        return ['status' => 'ok', 'message' => $message, 'latency_ms' => null];
    }

    private function checkMessageQueue(): array
    {
        $pendingMinutes = max(5, (int) Env::get('OPERATIONS_MESSAGE_PENDING_MINUTES', 15));
        $warningCount = max(1, (int) Env::get('OPERATIONS_MESSAGE_QUEUE_WARNING', 10));
        $criticalCount = max($warningCount + 1, (int) Env::get('OPERATIONS_MESSAGE_QUEUE_CRITICAL', 50));
        $enabled = $this->evolutionAlertsEnabledSql('i');
        $paused = $this->evolutionAlertsPausedSql('i');
        $joins = ' FROM conversation_messages cm
                   LEFT JOIN conversations c
                     ON c.id = cm.conversation_id
                    AND c.tenant_id = cm.tenant_id
                   LEFT JOIN evolution_instances i
                     ON i.id = c.evolution_instance_id
                    AND i.tenant_id = cm.tenant_id ';

        $pending = $this->count(
            'SELECT COUNT(*)' . $joins .
            "WHERE cm.direction = 'outgoing'
               AND cm.status = 'pending'
               AND cm.created_at <= (NOW() - INTERVAL " . $pendingMinutes . " MINUTE)
               AND (i.id IS NULL OR " . $enabled . ')'
        );
        $failed24 = $this->count(
            'SELECT COUNT(*)' . $joins .
            "WHERE cm.direction = 'outgoing'
               AND cm.status = 'failed'
               AND cm.created_at >= (NOW() - INTERVAL 24 HOUR)
               AND (i.id IS NULL OR " . $enabled . ')'
        );
        $pausedPending = $this->count(
            'SELECT COUNT(*)' . $joins .
            "WHERE cm.direction = 'outgoing'
               AND cm.status = 'pending'
               AND cm.created_at <= (NOW() - INTERVAL " . $pendingMinutes . " MINUTE)
               AND i.id IS NOT NULL
               AND " . $paused
        );
        $oldest = $this->fetchOne(
            'SELECT cm.created_at' . $joins .
            "WHERE cm.direction = 'outgoing'
               AND cm.status = 'pending'
               AND cm.created_at <= (NOW() - INTERVAL " . $pendingMinutes . " MINUTE)
               AND (i.id IS NULL OR " . $enabled . ')
             ORDER BY cm.created_at ASC LIMIT 1'
        );
        $details = $pending . ' mensagem(ns) monitorada(s) pendente(s) há mais de ' . $pendingMinutes . ' min; '
            . $failed24 . ' falha(s) monitorada(s) nas últimas 24h.';
        if ($pausedPending > 0) {
            $details .= ' ' . $pausedPending . ' mensagem(ns) preservada(s) em conexão(ões) pausada(s) intencionalmente, sem notificação.';
        }
        if (!empty($oldest['created_at'])) {
            $details .= ' Mais antiga monitorada: ' . $oldest['created_at'] . '.';
        }
        if ($pending >= $criticalCount) {
            return ['status' => 'down', 'message' => $details, 'latency_ms' => null];
        }
        if ($pending >= $warningCount || $failed24 > 0) {
            return ['status' => 'warning', 'message' => $details, 'latency_ms' => null];
        }
        return ['status' => 'ok', 'message' => $details, 'latency_ms' => null];
    }

    private function startMonitorRun(string $triggerSource): ?int
    {
        try {
            $triggerSource = mb_substr(trim($triggerSource) ?: 'manual', 0, 60);
            $statement = Database::connection()->prepare(
                'INSERT INTO operational_monitor_runs (trigger_source, status, started_at)
                 VALUES (:source, "running", NOW())'
            );
            $statement->execute(['source' => $triggerSource]);
            return (int) Database::connection()->lastInsertId();
        } catch (Throwable) {
            return null;
        }
    }

    private function finishMonitorRun(?int $runId, float $started, ?string $error): void
    {
        if (!$runId) {
            return;
        }
        try {
            $checks = $this->withExpectedChecks($this->latestChecks());
            $healthy = $this->countStatus($checks, 'ok');
            $warning = $this->countStatus($checks, 'warning');
            $down = $this->countStatus($checks, 'down');
            $status = $error !== null ? 'error' : ($down > 0 || $warning > 0 ? 'partial' : 'success');
            $run = $this->fetchOne('SELECT started_at FROM operational_monitor_runs WHERE id = ' . (int) $runId);
            $startedAt = trim((string) ($run['started_at'] ?? ''));
            $incidentsOpened = 0;
            $incidentsRecovered = 0;
            if ($startedAt !== '') {
                $statementOpened = Database::connection()->prepare(
                    "SELECT COUNT(*) FROM system_incidents WHERE event LIKE 'operations.alert.%' AND created_at >= :started_at"
                );
                $statementOpened->execute(['started_at' => $startedAt]);
                $incidentsOpened = (int) $statementOpened->fetchColumn();
                $statementRecovered = Database::connection()->prepare(
                    "SELECT COUNT(*) FROM system_incidents WHERE event LIKE 'operations.alert.%' AND resolved_at >= :started_at"
                );
                $statementRecovered->execute(['started_at' => $startedAt]);
                $incidentsRecovered = (int) $statementRecovered->fetchColumn();
            }
            $summary = [
                'unknown' => $this->countStatus($checks, 'unknown'),
                'checked_at' => \App\Core\Clock::nowUtc(),
            ];
            Database::connection()->prepare(
                'UPDATE operational_monitor_runs
                 SET status = :status, checks_total = :total, healthy_total = :healthy,
                     warning_total = :warning, down_total = :down,
                     incidents_opened = :incidents_opened, incidents_recovered = :incidents_recovered,
                     duration_ms = :duration, summary_json = :summary,
                     error_message = :error, finished_at = NOW()
                 WHERE id = :id'
            )->execute([
                'status' => $status,
                'total' => count($checks),
                'healthy' => $healthy,
                'warning' => $warning,
                'down' => $down,
                'incidents_opened' => $incidentsOpened,
                'incidents_recovered' => $incidentsRecovered,
                'duration' => max(0, (int) round((microtime(true) - $started) * 1000)),
                'summary' => json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'error' => $error ? mb_substr($error, 0, 1000) : null,
                'id' => $runId,
            ]);
        } catch (Throwable) {
        }
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
            $active = $this->fetchOne("SELECT id FROM system_incidents WHERE event = '" . str_replace("'", "''", $event) . "' AND resolved_at IS NULL LIMIT 1");

            if ($status === 'unknown') {
                // Ausência de evidência não comprova recuperação e não deve encerrar o incidente.
                return;
            }
            if ($status === 'ok') {
                if ($active && (int) ($active['id'] ?? 0) > 0) {
                    $incidentId = (int) $active['id'];
                    $statement = Database::connection()->prepare('UPDATE system_incidents SET resolved_at = NOW(), last_seen_at = NOW() WHERE id = :id AND resolved_at IS NULL');
                    $statement->execute(['id' => $incidentId]);
                    if ($statement->rowCount() > 0) {
                        (new OperationalAlertService())->dispatchRecovered($incidentId);
                    }
                }
                return;
            }

            $severity = $status === 'down' ? 'critical' : 'warning';
            if ($active && (int) ($active['id'] ?? 0) > 0) {
                $incidentId = (int) $active['id'];
                $statement = Database::connection()->prepare('UPDATE system_incidents SET severity = :severity, message = :message, last_seen_at = NOW() WHERE id = :id');
                $statement->execute(['severity' => $severity, 'message' => $label . ': ' . $message, 'id' => $incidentId]);
                (new OperationalAlertService())->dispatchReminderIfDue($incidentId);
                return;
            }

            $incidentId = $this->recordIncident($event, $severity, $label . ': ' . $message, [
                'check_key' => $key,
                'status' => $status,
                'source' => 'health_check',
            ]);
            if ($incidentId) {
                (new OperationalAlertService())->dispatchOpened($incidentId);
            }
        } catch (Throwable) {
            // Não impede os checks.
        }
    }

    private function syncBlockedEvolutionIncidents(): void
    {
        try {
            $ai = (new AiReprocessService())->dashboard();
            $activeEvents = [];
            foreach (($ai['pending_instances'] ?? []) as $item) {
                $pending = (int) ($item['pending_count'] ?? 0);
                if ($pending < 1) continue;
                if (!empty($item['monitoring_suppressed']) || (int) ($item['operational_alerts_enabled'] ?? 1) !== 1) continue;
                $state = strtolower(trim((string) (($item['connection_state'] ?? '') ?: ($item['instance_status'] ?? ''))));
                if (in_array($state, ['open', 'connected', 'active', 'online'], true)) continue;

                $tenantId = (int) ($item['tenant_id'] ?? 0);
                $instanceId = (int) ($item['instance_id'] ?? 0);
                if ($tenantId < 1 || $instanceId < 1) continue;
                $event = 'operations.alert.evolution.tenant.' . $tenantId . '.instance.' . $instanceId;
                $activeEvents[] = $event;
                $tenantName = trim((string) ($item['tenant_name'] ?? 'Empresa')) ?: 'Empresa';
                $instanceName = trim((string) (($item['instance_label'] ?? '') ?: ($item['instance_name'] ?? 'WhatsApp')));
                $message = 'WhatsApp de ' . $tenantName . ' desconectado: ' . $pending . ' conversa(s) preservadas aguardando reconexão. Instância: ' . $instanceName . '.';

                $existing = $this->fetchOne("SELECT id FROM system_incidents WHERE event = '" . str_replace("'", "''", $event) . "' AND resolved_at IS NULL LIMIT 1");
                if ($existing) {
                    $id = (int) ($existing['id'] ?? 0);
                    Database::connection()->prepare('UPDATE system_incidents SET severity = "warning", message = :message, last_seen_at = NOW(), tenant_id = :tenant_id WHERE id = :id')
                        ->execute(['message' => $message, 'tenant_id' => $tenantId, 'id' => $id]);
                    (new OperationalAlertService())->dispatchReminderIfDue($id);
                    continue;
                }

                $id = $this->recordIncident($event, 'warning', $message, [
                    'check_key' => 'evolution',
                    'source' => 'ai_pending_instance',
                    'instance_id' => $instanceId,
                    'pending_count' => $pending,
                ], $tenantId);
                if ($id) (new OperationalAlertService())->dispatchOpened($id);
            }

            $rows = Database::connection()->query("SELECT id, event FROM system_incidents WHERE event LIKE 'operations.alert.evolution.tenant.%' AND resolved_at IS NULL")->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $row) {
                $event = (string) ($row['event'] ?? '');
                if (in_array($event, $activeEvents, true)) continue;
                $id = (int) ($row['id'] ?? 0);
                if ($id < 1) continue;
                Database::connection()->prepare('UPDATE system_incidents SET resolved_at = NOW(), last_seen_at = NOW() WHERE id = :id')->execute(['id' => $id]);
                (new OperationalAlertService())->dispatchRecovered($id);
            }
        } catch (Throwable) {
            // A leitura por empresa não pode derrubar o ciclo principal de monitoramento.
        }
    }

    /**
     * Mantém incidentes por empresa quando o próprio controle de acesso confirma
     * bloqueio financeiro, fim de teste/vigência ou suspensão/cancelamento.
     * A origem da verdade continua sendo AccessControlService.
     */
    private function syncSubscriptionAccessIncidents(): void
    {
        try {
            $access = new AccessControlService();
            $summary = $access->securitySummary();
            if (($summary['blocked_tenants_available'] ?? true) !== true) {
                // Não transforme indisponibilidade da fotografia comercial em falsa recuperação.
                return;
            }
            $activeEvents = [];

            foreach (($summary['blocked_tenants'] ?? []) as $tenant) {
                $tenantId = (int) ($tenant['id'] ?? 0);
                if ($tenantId < 1) {
                    continue;
                }

                $status = $access->statusForTenant($tenantId);
                if (!empty($status['allowed'])) {
                    // Ex.: teste encerrado, porém ainda dentro da tolerância comercial.
                    continue;
                }

                $event = 'operations.alert.access.tenant.' . $tenantId;
                $activeEvents[] = $event;
                $tenantName = trim((string) ($status['tenant_name'] ?? $tenant['name'] ?? 'Empresa')) ?: 'Empresa';
                $code = trim((string) ($status['code'] ?? 'blocked')) ?: 'blocked';
                $title = trim((string) ($status['title'] ?? 'Acesso bloqueado')) ?: 'Acesso bloqueado';
                $detail = trim((string) ($status['message'] ?? 'A empresa está com o acesso bloqueado.'));
                $message = 'Acesso de ' . $tenantName . ' bloqueado — ' . $title . '. ' . $detail;

                $existing = $this->fetchOne(
                    "SELECT id FROM system_incidents WHERE event = '" . str_replace("'", "''", $event) . "' AND resolved_at IS NULL LIMIT 1"
                );

                if ($existing && (int) ($existing['id'] ?? 0) > 0) {
                    $incidentId = (int) $existing['id'];
                    Database::connection()->prepare(
                        'UPDATE system_incidents
                         SET severity = "warning", message = :message, last_seen_at = NOW(), tenant_id = :tenant_id,
                             context_json = :context_json
                         WHERE id = :id'
                    )->execute([
                        'message' => $message,
                        'tenant_id' => $tenantId,
                        'context_json' => json_encode([
                            'check_key' => 'access',
                            'source' => 'access_control',
                            'access_code' => $code,
                            'subscription_id' => $status['subscription']['id'] ?? null,
                            'invoice_id' => $status['invoice']['id'] ?? null,
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'id' => $incidentId,
                    ]);
                    (new OperationalAlertService())->dispatchReminderIfDue($incidentId);
                    continue;
                }

                $incidentId = $this->recordIncident($event, 'warning', $message, [
                    'check_key' => 'access',
                    'source' => 'access_control',
                    'access_code' => $code,
                    'subscription_id' => $status['subscription']['id'] ?? null,
                    'invoice_id' => $status['invoice']['id'] ?? null,
                ], $tenantId);
                if ($incidentId) {
                    (new OperationalAlertService())->dispatchOpened($incidentId);
                }
            }

            $rows = Database::connection()->query(
                "SELECT id, event FROM system_incidents
                 WHERE event LIKE 'operations.alert.access.tenant.%'
                   AND resolved_at IS NULL"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($rows as $row) {
                $event = (string) ($row['event'] ?? '');
                if (in_array($event, $activeEvents, true)) {
                    continue;
                }

                $incidentId = (int) ($row['id'] ?? 0);
                if ($incidentId < 1) {
                    continue;
                }

                $statement = Database::connection()->prepare(
                    'UPDATE system_incidents SET resolved_at = NOW(), last_seen_at = NOW()
                     WHERE id = :id AND resolved_at IS NULL'
                );
                $statement->execute(['id' => $incidentId]);
                if ($statement->rowCount() > 0) {
                    (new OperationalAlertService())->dispatchRecovered($incidentId);
                }
            }
        } catch (Throwable) {
            // O monitor continua mesmo quando a fotografia comercial não está disponível.
        }
    }

    /**
     * O monitor roda a cada poucos minutos, mas o resumo saudável não pode virar spam.
     * OperationalAlertService controla janela, horário e deduplicação por usuário/dia.
     */
    private function dispatchDailyHealthDigest(): void
    {
        try {
            $health = (new OperationalHealthService())->dashboard();
            $access = (new AccessControlService())->securitySummary();
            (new OperationalAlertService())->dispatchHealthDigest(
                is_array($health['summary'] ?? null) ? $health['summary'] : [],
                is_array($access) ? $access : []
            );
        } catch (Throwable) {
            // Um aviso de resumo nunca derruba a rotina principal de monitoramento.
        }
    }

    private function isMessagingIncidentEvent(string $event): bool
    {
        return str_starts_with($event, 'operations.alert.evolution')
            || $event === 'operations.alert.message_queue';
    }

    /** @param array<string,mixed> $incident @return list<int> */
    private function incidentInstanceIds(array $incident): array
    {
        $event = trim((string) ($incident['event'] ?? ''));
        $tenantId = (int) ($incident['tenant_id'] ?? 0);
        $context = json_decode((string) ($incident['context_json'] ?? ''), true);
        $contextInstanceId = is_array($context) ? (int) ($context['instance_id'] ?? 0) : 0;
        if ($contextInstanceId > 0) {
            return [$contextInstanceId];
        }

        if (preg_match('/^operations\.alert\.evolution\.tenant\.(\d+)\.instance\.(\d+)$/', $event, $matches) === 1) {
            return [(int) $matches[2]];
        }

        if ($event === 'operations.alert.message_queue') {
            return $this->queuedInstanceIds($tenantId);
        }

        if (str_starts_with($event, 'operations.alert.evolution')) {
            return $this->disconnectedOperationalInstanceIds($tenantId);
        }

        return [];
    }

    /** @return list<int> */
    private function disconnectedOperationalInstanceIds(int $tenantId = 0): array
    {
        try {
            $sql = 'SELECT id FROM evolution_instances
                    WHERE LOWER(COALESCE(NULLIF(connection_state, ""), NULLIF(status, ""), "disconnected")) NOT IN ("connected","open","active","online")';
            $params = [];
            if ($tenantId > 0) {
                $sql .= ' AND tenant_id = :tenant_id';
                $params['tenant_id'] = $tenantId;
            }
            if ($this->supportsEvolutionAlertSuppression()) {
                $sql .= ' AND COALESCE(operational_alerts_enabled, 1) = 1';
            }
            $statement = Database::connection()->prepare($sql . ' ORDER BY id');
            $statement->execute($params);
            return array_values(array_unique(array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN) ?: [])));
        } catch (Throwable) {
            return [];
        }
    }

    /** @return list<int> */
    private function queuedInstanceIds(int $tenantId = 0): array
    {
        try {
            $sql = 'SELECT DISTINCT i.id
                    FROM evolution_instances i
                    INNER JOIN conversations c
                       ON c.evolution_instance_id = i.id
                      AND c.tenant_id = i.tenant_id
                    LEFT JOIN conversation_messages cm
                       ON cm.conversation_id = c.id
                      AND cm.tenant_id = c.tenant_id
                      AND cm.direction = "outgoing"
                      AND cm.status IN ("pending","failed")
                    LEFT JOIN ai_after_hours_pending ah
                       ON ah.conversation_id = c.id
                      AND ah.tenant_id = c.tenant_id
                      AND ah.status IN ("pending","processing","blocked_plan","blocked_human","error")
                    WHERE (cm.id IS NOT NULL OR ah.id IS NOT NULL)';
            $params = [];
            if ($tenantId > 0) {
                $sql .= ' AND i.tenant_id = :tenant_id';
                $params['tenant_id'] = $tenantId;
            }
            $statement = Database::connection()->prepare($sql . ' ORDER BY i.id');
            $statement->execute($params);
            return array_values(array_unique(array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN) ?: [])));
        } catch (Throwable) {
            return [];
        }
    }

    /** @param list<int> $instanceIds */
    private function pauseDisconnectedOperationalAlerts(PDO $pdo, array $instanceIds): int
    {
        if (!$this->supportsEvolutionAlertSuppression()) {
            throw new RuntimeException('A migration 097 precisa ser aplicada antes de silenciar os alertas da conexão.');
        }

        $instanceIds = array_values(array_unique(array_filter(array_map('intval', $instanceIds), static fn (int $id): bool => $id > 0)));
        if ($instanceIds === []) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($instanceIds), '?'));
        $statement = $pdo->prepare(
            'UPDATE evolution_instances
             SET operational_alerts_enabled = 0,
                 operational_alerts_paused_at = NOW(),
                 operational_alerts_pause_reason = "incident_resolved"
             WHERE id IN (' . $placeholders . ')
               AND COALESCE(operational_alerts_enabled, 1) = 1
               AND LOWER(COALESCE(NULLIF(connection_state, ""), NULLIF(status, ""), "disconnected")) NOT IN ("connected","open","active","online")'
        );
        $statement->execute($instanceIds);
        return $statement->rowCount();
    }

    /** @param list<int> $instanceIds */
    private function cancelOutgoingQueue(PDO $pdo, array $instanceIds, string $reason): int
    {
        $instanceIds = array_values(array_unique(array_filter(array_map('intval', $instanceIds), static fn (int $id): bool => $id > 0)));
        if ($instanceIds === []) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($instanceIds), '?'));
        $statement = $pdo->prepare(
            'UPDATE conversation_messages cm
             INNER JOIN conversations c
                ON c.id = cm.conversation_id
               AND c.tenant_id = cm.tenant_id
             SET cm.status = "cancelled",
                 cm.error_message = CONCAT_WS(" | ", NULLIF(cm.error_message, ""), ?)
             WHERE c.evolution_instance_id IN (' . $placeholders . ')
               AND cm.direction = "outgoing"
               AND cm.status IN ("pending","failed")'
        );
        $statement->execute(array_merge([mb_substr($reason, 0, 500)], $instanceIds));
        return $statement->rowCount();
    }

    private function conversationMessageCancellationSupported(PDO $pdo): bool
    {
        try {
            $statement = $pdo->query(
                'SELECT COLUMN_TYPE
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = "conversation_messages"
                   AND COLUMN_NAME = "status"
                 LIMIT 1'
            );
            return str_contains(strtolower((string) $statement->fetchColumn()), "'cancelled'");
        } catch (Throwable) {
            return false;
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

    private function recordIncident(string $event, string $severity, string $message, array $context = [], ?int $tenantId = null): ?int
    {
        try {
            $statement = Database::connection()->prepare(
                'INSERT INTO system_incidents (event, tenant_id, severity, message, context_json, last_seen_at, created_by)
                 VALUES (:event, :tenant_id, :severity, :message, :context_json, NOW(), :created_by)'
            );
            $statement->execute([
                'event' => $event,
                'tenant_id' => $tenantId && $tenantId > 0 ? $tenantId : null,
                'severity' => $severity,
                'message' => $message,
                'context_json' => $context === [] ? null : json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_by' => Auth::id(),
            ]);
            return (int) Database::connection()->lastInsertId();
        } catch (Throwable) {
            // Fallback para bancos antes da migration 049.
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
                return (int) Database::connection()->lastInsertId();
            } catch (Throwable) {
                return null;
            }
        }
    }

    private function checkDefinitions(): array
    {
        return [
            'database' => ['label' => 'Banco de dados', 'category' => 'infrastructure', 'category_label' => 'Infraestrutura e aplicação', 'route' => '/central-operacao?tab=status'],
            'migrations' => ['label' => 'Estrutura e migrations', 'category' => 'infrastructure', 'category_label' => 'Infraestrutura e aplicação', 'route' => '/central-operacao?tab=status'],
            'disk' => ['label' => 'Espaço em disco', 'category' => 'infrastructure', 'category_label' => 'Infraestrutura e aplicação', 'route' => '/central-operacao?tab=status'],
            'evolution' => ['label' => 'WhatsApp / Evolution', 'category' => 'integration', 'category_label' => 'Integrações', 'route' => '/instances'],
            'n8n' => ['label' => 'n8n', 'category' => 'integration', 'category_label' => 'Integrações', 'route' => '/n8n'],
            'openai' => ['label' => 'OpenAI / IA', 'category' => 'integration', 'category_label' => 'Integrações', 'route' => '/ai-credentials'],
            'webhooks' => ['label' => 'Webhooks e mensagens', 'category' => 'integration', 'category_label' => 'Integrações', 'route' => '/conversations'],
            'message_queue' => ['label' => 'Fila de mensagens', 'category' => 'integration', 'category_label' => 'Integrações', 'route' => '/conversations'],
            'calendar' => ['label' => 'Google Agenda', 'category' => 'integration', 'category_label' => 'Integrações', 'route' => '/calendar/availability'],
            'payments' => ['label' => 'Gateways e pagamentos', 'category' => 'integration', 'category_label' => 'Integrações', 'route' => '/payment-gateways'],
            'billing_cron' => ['label' => 'Cron de cobrança', 'category' => 'routine', 'category_label' => 'Rotinas automáticas', 'route' => '/billing-reminders'],
            'ai_reprocess' => ['label' => 'Rotina da fila da IA', 'category' => 'routine', 'category_label' => 'Rotinas automáticas', 'route' => '/central-operacao?tab=ai_reprocess'],
            'after_hours_recovery' => ['label' => 'Recuperação pós-horário', 'category' => 'routine', 'category_label' => 'Rotinas automáticas', 'route' => '/central-operacao?tab=ai_reprocess'],
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
                if ($key === '' || count($history[$key] ?? []) >= 3) continue;
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
            return Database::connection()->query(
                "SELECT * FROM system_incidents
                 WHERE event LIKE 'operations.alert.%'
                 ORDER BY id DESC
                 LIMIT 60"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Dados compactos para a visão geral da Central de Monitoramento.
     * Usa somente tabelas existentes e retorna valores seguros quando o banco
     * ainda não possui o histórico operacional completo.
     *
     * @param list<array<string,mixed>> $checks
     * @return array<string,mixed>
     */
    private function monitoringAnalytics(array $checks): array
    {
        $healthy = $this->countStatus($checks, 'ok');
        $warning = $this->countStatus($checks, 'warning');
        $down = $this->countStatus($checks, 'down');
        $unknown = $this->countStatus($checks, 'unknown');
        $total = count($checks);

        $areas = [];
        foreach ($checks as $check) {
            $status = (string) ($check['status'] ?? 'unknown');
            if ($status === 'ok') {
                continue;
            }
            $area = trim((string) ($check['category_label'] ?? 'Outras áreas')) ?: 'Outras áreas';
            $areas[$area] = ($areas[$area] ?? 0) + 1;
        }
        arsort($areas);

        return [
            'health_score' => $total > 0 ? (int) round(($healthy / $total) * 100) : 0,
            'attention_total' => $warning + $down + $unknown,
            'open_incidents' => $this->count(
                "SELECT COUNT(*) FROM system_incidents
                 WHERE event LIKE 'operations.alert.%'
                   AND resolved_at IS NULL
                   AND severity IN ('warning','error','critical')"
            ),
            'resolved_7d' => $this->count(
                "SELECT COUNT(*) FROM system_incidents
                 WHERE event LIKE 'operations.alert.%'
                   AND resolved_at >= (NOW() - INTERVAL 7 DAY)"
            ),
            'resolved_total' => $this->count(
                "SELECT COUNT(*) FROM system_incidents
                 WHERE event LIKE 'operations.alert.%'
                   AND resolved_at IS NOT NULL"
            ),
            'history_total' => $this->count(
                "SELECT COUNT(*) FROM system_incidents
                 WHERE event LIKE 'operations.alert.%'"
            ),
            'status_distribution' => [
                'healthy' => $healthy,
                'warning' => $warning,
                'down' => $down,
                'unknown' => $unknown,
                'total' => $total,
            ],
            'attention_by_area' => $areas,
            'trend_7d' => $this->incidentTrend(7),
        ];
    }

    /** @return list<array{date:string,label:string,opened:int,resolved:int}> */
    private function incidentTrend(int $days): array
    {
        $days = max(2, min(30, $days));
        $series = [];
        for ($offset = $days - 1; $offset >= 0; $offset--) {
            $date = date('Y-m-d', strtotime('-' . $offset . ' day'));
            $series[$date] = [
                'date' => $date,
                'label' => date('d/m', strtotime($date)),
                'opened' => 0,
                'resolved' => 0,
            ];
        }

        try {
            $opened = Database::connection()->query(
                "SELECT DATE(created_at) AS day_key, COUNT(*) AS total
                 FROM system_incidents
                 WHERE event LIKE 'operations.alert.%'
                   AND created_at >= (CURDATE() - INTERVAL " . ($days - 1) . " DAY)
                 GROUP BY DATE(created_at)"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($opened as $row) {
                $key = (string) ($row['day_key'] ?? '');
                if (isset($series[$key])) {
                    $series[$key]['opened'] = (int) ($row['total'] ?? 0);
                }
            }

            $resolved = Database::connection()->query(
                "SELECT DATE(resolved_at) AS day_key, COUNT(*) AS total
                 FROM system_incidents
                 WHERE event LIKE 'operations.alert.%'
                   AND resolved_at >= (CURDATE() - INTERVAL " . ($days - 1) . " DAY)
                 GROUP BY DATE(resolved_at)"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($resolved as $row) {
                $key = (string) ($row['day_key'] ?? '');
                if (isset($series[$key])) {
                    $series[$key]['resolved'] = (int) ($row['total'] ?? 0);
                }
            }
        } catch (Throwable) {
            // Mantém a série zerada quando o histórico ainda não está disponível.
        }

        return array_values($series);
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
        if (str_starts_with($event, 'operations.alert.evolution.tenant.')) {
            return 'Alerta: WhatsApp / Evolution';
        }
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
            ['title' => 'Pagamento não confirma', 'steps' => ['Conferir se o meio de pagamento está enviando atualizações.', 'Revisar os registros em Meios de pagamento.', 'Atualizar manualmente a cobrança se o serviço de pagamento confirmou fora do sistema.']],
            ['title' => 'Backup atrasado', 'steps' => ['Executar backup no provedor/VPS.', 'Registrar backup manual no painel.', 'Configurar rotina externa usando /webhooks/operations/backups.']],
            ['title' => 'Backup local precisa ser conferido', 'steps' => ['Confirme se o arquivo existe no computador indicado.', 'Registre caminho completo ou observação que permita encontrar o arquivo.', 'Quando possível, use servidor/VPS, Google Drive ou S3/MinIO para validação futura.']],
        ];
    }
}
