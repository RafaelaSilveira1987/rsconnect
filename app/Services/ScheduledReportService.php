<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Env;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Throwable;

final class ScheduledReportService
{
    private PDO $pdo;
    private ExecutiveReportPdfService $pdf;
    private string $storagePath;

    /** @var list<string> */
    private const TENANT_SECTIONS = ['overview', 'conversations', 'team', 'agenda', 'ai', 'attention'];

    /** @var list<string> */
    private const ADMIN_SECTIONS = ['overview', 'companies', 'usage', 'health', 'automation', 'agenda', 'commercial'];

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connection();
        $this->pdf = new ExecutiveReportPdfService();
        $this->storagePath = rtrim(
            (string) Env::get('SCHEDULED_REPORTS_PATH', dirname(__DIR__, 2) . '/storage/generated-reports'),
            '/\\'
        );
    }

    public function dashboard(?int $tenantId, bool $superAdmin): array
    {
        $scope = $superAdmin ? '' : ' WHERE sr.tenant_id = :tenant_id AND sr.report_scope = "tenant"';
        $params = $superAdmin ? [] : ['tenant_id' => (int) $tenantId];

        $schedules = $this->fetchAll(
            'SELECT sr.*, t.name AS tenant_name, u.name AS creator_name, ei.name AS instance_name,
                    COUNT(DISTINCT rr.id) AS recipient_count
             FROM scheduled_reports sr
             LEFT JOIN tenants t ON t.id = sr.tenant_id
             LEFT JOIN users u ON u.id = sr.created_by_user_id
             LEFT JOIN evolution_instances ei ON ei.id = sr.evolution_instance_id
             LEFT JOIN scheduled_report_recipients rr
                    ON rr.scheduled_report_id = sr.id AND rr.enabled = 1' .
             $scope . '
             GROUP BY sr.id
             ORDER BY sr.status = "active" DESC, sr.updated_at DESC
             LIMIT 100',
            $params
        );

        $generatedScope = $superAdmin ? '' : ' WHERE gr.tenant_id = :tenant_id AND gr.report_scope = "tenant"';
        $generated = $this->fetchAll(
            'SELECT gr.*, t.name AS tenant_name, sr.name AS schedule_name,
                    COUNT(DISTINCT d.id) AS delivery_count,
                    SUM(d.status = "sent") AS sent_count,
                    SUM(d.status = "failed") AS failed_count,
                    SUM(d.status = "pending") AS pending_count
             FROM generated_reports gr
             LEFT JOIN tenants t ON t.id = gr.tenant_id
             LEFT JOIN scheduled_reports sr ON sr.id = gr.scheduled_report_id
             LEFT JOIN scheduled_report_deliveries d ON d.generated_report_id = gr.id' .
             $generatedScope . '
             GROUP BY gr.id
             ORDER BY gr.id DESC
             LIMIT 100',
            $params
        );

        $tenants = $superAdmin
            ? $this->fetchAll('SELECT id, name, status FROM tenants ORDER BY status = "active" DESC, name')
            : [];

        $instanceScope = $superAdmin ? '' : ' WHERE tenant_id = :tenant_id';
        $instances = $this->fetchAll(
            'SELECT id, tenant_id, name, instance_name, status, is_default
             FROM evolution_instances' . $instanceScope . '
             ORDER BY status = "connected" DESC, is_default DESC, name',
            $params
        );

        return [
            'schedules' => array_map(fn (array $row): array => $this->decorateSchedule($row), $schedules),
            'generated' => array_map(fn (array $row): array => $this->decorateGenerated($row), $generated),
            'tenants' => $tenants,
            'instances' => $instances,
            'section_options' => [
                'tenant' => $this->sectionOptions('tenant'),
                'admin' => $this->sectionOptions('admin'),
            ],
            'storage_path' => $this->storagePath,
            'cron_token_configured' => trim((string) Env::get('SCHEDULED_REPORTS_CRON_TOKEN', '')) !== '',
        ];
    }

    public function saveSchedule(array $input, int $userId, bool $superAdmin, ?int $authTenantId): int
    {
        $scope = $superAdmin && (string) ($input['report_scope'] ?? '') === 'admin' ? 'admin' : 'tenant';
        $tenantId = $superAdmin ? (int) ($input['tenant_id'] ?? 0) : (int) $authTenantId;
        if ($scope === 'tenant' && $tenantId < 1) {
            throw new RuntimeException('Selecione a empresa do relatório.');
        }
        if (!$superAdmin && $tenantId !== (int) $authTenantId) {
            throw new RuntimeException('Empresa inválida para este usuário.');
        }

        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('Informe um nome para o relatório automático.');
        }

        $frequency = $this->choice((string) ($input['frequency'] ?? 'weekly'), ['manual', 'daily', 'weekly', 'monthly'], 'weekly');
        $periodMode = $this->choice(
            (string) ($input['period_mode'] ?? 'previous_week'),
            ['previous_day', 'previous_week', 'previous_month', 'last_7_days', 'last_30_days', 'current_month'],
            'previous_week'
        );
        $timezone = Clock::safeTimezone((string) ($input['timezone'] ?? $this->tenantTimezone($tenantId)));
        $time = preg_match('/^\d{2}:\d{2}$/', (string) ($input['time_of_day'] ?? ''))
            ? (string) $input['time_of_day'] . ':00'
            : '08:00:00';
        $weekday = $frequency === 'weekly' ? max(1, min(7, (int) ($input['weekday'] ?? 1))) : null;
        $monthDay = $frequency === 'monthly' ? max(1, min(28, (int) ($input['month_day'] ?? 1))) : null;
        $sections = $this->normalizeSections($scope, $input['sections'] ?? []);
        $whatsapp = !empty($input['whatsapp_enabled']) ? 1 : 0;
        $instanceId = (int) ($input['evolution_instance_id'] ?? 0);
        if ($instanceId > 0) {
            $this->assertInstanceAllowed($instanceId, $tenantId, $superAdmin);
        } else {
            $instanceId = 0;
        }

        $now = Clock::nowUtc();
        $nextRun = $this->nextRunUtc($frequency, $time, $weekday, $monthDay, $timezone);
        $uuid = trim((string) ($input['schedule_uuid'] ?? ''));
        $existing = $uuid !== '' ? $this->scheduleByUuid($uuid) : null;
        if ($existing) {
            $this->assertScheduleAccess($existing, $superAdmin, $authTenantId);
            $statement = $this->pdo->prepare(
                'UPDATE scheduled_reports
                 SET tenant_id = :tenant_id,
                     report_scope = :report_scope,
                     name = :name,
                     frequency = :frequency,
                     time_of_day = :time_of_day,
                     weekday = :weekday,
                     month_day = :month_day,
                     timezone = :timezone,
                     period_mode = :period_mode,
                     sections_json = :sections_json,
                     whatsapp_enabled = :whatsapp_enabled,
                     evolution_instance_id = :evolution_instance_id,
                     next_run_at = :next_run_at,
                     updated_at = :updated_at
                 WHERE id = :id'
            );
            $statement->execute([
                'tenant_id' => $tenantId > 0 ? $tenantId : null,
                'report_scope' => $scope,
                'name' => mb_substr($name, 0, 150),
                'frequency' => $frequency,
                'time_of_day' => $time,
                'weekday' => $weekday,
                'month_day' => $monthDay,
                'timezone' => $timezone,
                'period_mode' => $periodMode,
                'sections_json' => json_encode($sections, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'whatsapp_enabled' => $whatsapp,
                'evolution_instance_id' => $instanceId > 0 ? $instanceId : null,
                'next_run_at' => $nextRun,
                'updated_at' => $now,
                'id' => (int) $existing['id'],
            ]);
            $scheduleId = (int) $existing['id'];
        } else {
            $uuid = $this->uuidV4();
            $statement = $this->pdo->prepare(
                'INSERT INTO scheduled_reports
                    (uuid, tenant_id, created_by_user_id, report_scope, name, status, frequency,
                     time_of_day, weekday, month_day, timezone, period_mode, sections_json,
                     whatsapp_enabled, evolution_instance_id, next_run_at, created_at, updated_at)
                 VALUES
                    (:uuid, :tenant_id, :created_by_user_id, :report_scope, :name, "active", :frequency,
                     :time_of_day, :weekday, :month_day, :timezone, :period_mode, :sections_json,
                     :whatsapp_enabled, :evolution_instance_id, :next_run_at, :created_at, :updated_at)'
            );
            $statement->execute([
                'uuid' => $uuid,
                'tenant_id' => $tenantId > 0 ? $tenantId : null,
                'created_by_user_id' => $userId > 0 ? $userId : null,
                'report_scope' => $scope,
                'name' => mb_substr($name, 0, 150),
                'frequency' => $frequency,
                'time_of_day' => $time,
                'weekday' => $weekday,
                'month_day' => $monthDay,
                'timezone' => $timezone,
                'period_mode' => $periodMode,
                'sections_json' => json_encode($sections, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'whatsapp_enabled' => $whatsapp,
                'evolution_instance_id' => $instanceId > 0 ? $instanceId : null,
                'next_run_at' => $nextRun,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $scheduleId = (int) $this->pdo->lastInsertId();
        }

        $this->replaceRecipients($scheduleId, (string) ($input['recipients'] ?? ''));
        return $scheduleId;
    }

    /**
     * @return array<string,mixed>
     */
    public function generateManual(array $input, int $userId, bool $superAdmin, ?int $authTenantId): array
    {
        $scope = $superAdmin && (string) ($input['report_scope'] ?? '') === 'admin' ? 'admin' : 'tenant';
        $tenantId = $superAdmin ? (int) ($input['tenant_id'] ?? 0) : (int) $authTenantId;
        if ($scope === 'tenant' && $tenantId < 1) {
            throw new RuntimeException('Selecione a empresa do relatório.');
        }

        $start = $this->date((string) ($input['start'] ?? date('Y-m-01')), date('Y-m-01'));
        $end = $this->date((string) ($input['end'] ?? date('Y-m-d')), date('Y-m-d'));
        if ($start > $end) {
            [$start, $end] = [$end, $start];
        }
        $name = trim((string) ($input['name'] ?? 'Relatório executivo'));
        $sections = $this->normalizeSections($scope, $input['sections'] ?? []);
        $instanceId = (int) ($input['evolution_instance_id'] ?? 0);
        if ($instanceId > 0) {
            $this->assertInstanceAllowed($instanceId, $tenantId, $superAdmin);
        }
        $destinations = $this->parseRecipients((string) ($input['recipients'] ?? ''));
        $sendNow = !empty($input['send_now']);

        return $this->generateReport(
            schedule: null,
            scope: $scope,
            tenantId: $tenantId > 0 ? $tenantId : null,
            userId: $userId,
            name: $name !== '' ? $name : 'Relatório executivo',
            start: $start,
            end: $end,
            sections: $sections,
            runKey: 'manual:' . $this->uuidV4(),
            instanceId: $instanceId > 0 ? $instanceId : null,
            destinations: $destinations,
            sendNow: $sendNow
        );
    }

    public function generateScheduleNow(string $uuid, int $userId, bool $superAdmin, ?int $authTenantId): array
    {
        $schedule = $this->scheduleByUuid($uuid);
        if (!$schedule) {
            throw new RuntimeException('Programação não encontrada.');
        }
        $this->assertScheduleAccess($schedule, $superAdmin, $authTenantId);
        return $this->runSchedule($schedule, true, $userId);
    }

    public function toggleSchedule(string $uuid, bool $superAdmin, ?int $authTenantId): string
    {
        $schedule = $this->scheduleByUuid($uuid);
        if (!$schedule) {
            throw new RuntimeException('Programação não encontrada.');
        }
        $this->assertScheduleAccess($schedule, $superAdmin, $authTenantId);
        $nextStatus = (string) $schedule['status'] === 'active' ? 'paused' : 'active';
        $nextRun = $nextStatus === 'active'
            ? $this->nextRunUtc(
                (string) $schedule['frequency'],
                (string) $schedule['time_of_day'],
                $schedule['weekday'] !== null ? (int) $schedule['weekday'] : null,
                $schedule['month_day'] !== null ? (int) $schedule['month_day'] : null,
                (string) $schedule['timezone']
            )
            : null;
        $statement = $this->pdo->prepare(
            'UPDATE scheduled_reports
             SET status = :status, next_run_at = :next_run_at, updated_at = :updated_at
             WHERE id = :id'
        );
        $statement->execute([
            'status' => $nextStatus,
            'next_run_at' => $nextRun,
            'updated_at' => Clock::nowUtc(),
            'id' => (int) $schedule['id'],
        ]);
        return $nextStatus;
    }

    public function resend(string $reportUuid, bool $superAdmin, ?int $authTenantId): array
    {
        $report = $this->generatedByUuid($reportUuid);
        if (!$report) {
            throw new RuntimeException('Relatório não encontrado.');
        }
        $this->assertGeneratedAccess($report, $superAdmin, $authTenantId);

        $this->pdo->prepare(
            'UPDATE scheduled_report_deliveries
             SET status = "pending", error_message = NULL, updated_at = :updated_at
             WHERE generated_report_id = :report_id'
        )->execute([
            'updated_at' => Clock::nowUtc(),
            'report_id' => (int) $report['id'],
        ]);

        return $this->deliverGenerated((int) $report['id']);
    }

    /**
     * @return array{path:string,filename:string,mime:string,size:int}
     */
    public function downloadable(string $uuid, bool $superAdmin, ?int $authTenantId): array
    {
        $report = $this->generatedByUuid($uuid);
        if (!$report) {
            throw new RuntimeException('Relatório não encontrado.');
        }
        $this->assertGeneratedAccess($report, $superAdmin, $authTenantId);
        if (!empty($report['expires_at']) && (string) $report['expires_at'] < Clock::nowUtc()) {
            $this->pdo->prepare('UPDATE generated_reports SET status = "expired", updated_at = :now WHERE id = :id')
                ->execute(['now' => Clock::nowUtc(), 'id' => (int) $report['id']]);
            throw new RuntimeException('O acesso a este relatório expirou. Gere uma nova versão.');
        }
        $path = $this->absolutePath((string) ($report['storage_path'] ?? ''));
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('O arquivo do relatório não está disponível.');
        }
        return [
            'path' => $path,
            'filename' => (string) ($report['original_filename'] ?? 'relatorio.pdf'),
            'mime' => (string) ($report['mime_type'] ?? 'application/pdf'),
            'size' => (int) filesize($path),
        ];
    }

    /**
     * Executa programações vencidas. Retorna resumo para cron/n8n.
     *
     * @return array{checked:int,generated:int,sent:int,failed:int,items:list<array<string,mixed>>}
     */
    public function runDue(int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        $schedules = $this->fetchAll(
            'SELECT *
             FROM scheduled_reports
             WHERE status = "active"
               AND frequency <> "manual"
               AND next_run_at IS NOT NULL
               AND next_run_at <= :now
             ORDER BY next_run_at ASC
             LIMIT ' . $limit,
            ['now' => Clock::nowUtc()]
        );

        $result = ['checked' => count($schedules), 'generated' => 0, 'sent' => 0, 'failed' => 0, 'items' => []];
        foreach ($schedules as $schedule) {
            try {
                $item = $this->runSchedule($schedule, false, 0);
                $result['generated']++;
                if ((string) ($item['status'] ?? '') === 'sent') {
                    $result['sent']++;
                } elseif ((string) ($item['status'] ?? '') === 'failed') {
                    $result['failed']++;
                }
                $result['items'][] = $item;
            } catch (Throwable $exception) {
                $result['failed']++;
                $result['items'][] = [
                    'schedule_uuid' => (string) ($schedule['uuid'] ?? ''),
                    'ok' => false,
                    'error' => $exception->getMessage(),
                ];
                $this->pdo->prepare(
                    'UPDATE scheduled_reports
                     SET last_run_at = :now, last_error = :error, next_run_at = :next_run_at, updated_at = :now
                     WHERE id = :id'
                )->execute([
                    'now' => Clock::nowUtc(),
                    'error' => mb_substr($exception->getMessage(), 0, 1000),
                    'next_run_at' => $this->nextRunUtc(
                        (string) $schedule['frequency'],
                        (string) $schedule['time_of_day'],
                        $schedule['weekday'] !== null ? (int) $schedule['weekday'] : null,
                        $schedule['month_day'] !== null ? (int) $schedule['month_day'] : null,
                        (string) $schedule['timezone']
                    ),
                    'id' => (int) $schedule['id'],
                ]);
            }
        }
        return $result;
    }

    /**
     * @param array<string,mixed> $schedule
     * @return array<string,mixed>
     */
    private function runSchedule(array $schedule, bool $force, int $userId): array
    {
        $period = $this->periodForMode((string) $schedule['period_mode'], (string) $schedule['timezone']);
        $runKey = sprintf(
            'schedule:%d:%s:%s',
            (int) $schedule['id'],
            $period['start'],
            $period['end']
        );
        if ($force) {
            $runKey .= ':manual:' . date('YmdHis') . ':' . bin2hex(random_bytes(3));
        }

        $sections = json_decode((string) ($schedule['sections_json'] ?? '[]'), true);
        $sections = is_array($sections) ? array_values(array_filter($sections, 'is_string')) : [];
        $recipients = $this->fetchAll(
            'SELECT id, name, phone
             FROM scheduled_report_recipients
             WHERE scheduled_report_id = :schedule_id AND enabled = 1
             ORDER BY id',
            ['schedule_id' => (int) $schedule['id']]
        );
        $destinations = array_map(static fn (array $row): array => [
            'recipient_id' => (int) $row['id'],
            'name' => (string) ($row['name'] ?? ''),
            'phone' => (string) ($row['phone'] ?? ''),
        ], $recipients);

        $report = $this->generateReport(
            schedule: $schedule,
            scope: (string) $schedule['report_scope'],
            tenantId: !empty($schedule['tenant_id']) ? (int) $schedule['tenant_id'] : null,
            userId: $userId > 0 ? $userId : (int) ($schedule['created_by_user_id'] ?? 0),
            name: (string) $schedule['name'],
            start: $period['start'],
            end: $period['end'],
            sections: $sections,
            runKey: $runKey,
            instanceId: !empty($schedule['evolution_instance_id']) ? (int) $schedule['evolution_instance_id'] : null,
            destinations: $destinations,
            sendNow: (int) ($schedule['whatsapp_enabled'] ?? 0) === 1
        );

        $now = Clock::nowUtc();
        $this->pdo->prepare(
            'UPDATE scheduled_reports
             SET last_run_at = :now,
                 last_success_at = :success_at,
                 last_error = :last_error,
                 next_run_at = :next_run_at,
                 updated_at = :now
             WHERE id = :id'
        )->execute([
            'now' => $now,
            'success_at' => in_array((string) ($report['status'] ?? ''), ['ready', 'sent', 'partial'], true) ? $now : null,
            'last_error' => (string) ($report['status'] ?? '') === 'failed' ? (string) ($report['error_message'] ?? 'Não foi possível concluir o relatório.') : null,
            'next_run_at' => $this->nextRunUtc(
                (string) $schedule['frequency'],
                (string) $schedule['time_of_day'],
                $schedule['weekday'] !== null ? (int) $schedule['weekday'] : null,
                $schedule['month_day'] !== null ? (int) $schedule['month_day'] : null,
                (string) $schedule['timezone']
            ),
            'id' => (int) $schedule['id'],
        ]);
        return $report;
    }

    /**
     * @param array<string,mixed>|null $schedule
     * @param list<string> $sections
     * @param list<array<string,mixed>> $destinations
     * @return array<string,mixed>
     */
    private function generateReport(
        ?array $schedule,
        string $scope,
        ?int $tenantId,
        int $userId,
        string $name,
        string $start,
        string $end,
        array $sections,
        string $runKey,
        ?int $instanceId,
        array $destinations,
        bool $sendNow
    ): array {
        $existing = $this->fetchOne(
            'SELECT * FROM generated_reports WHERE run_key = :run_key LIMIT 1',
            ['run_key' => $runKey]
        );
        if ($existing) {
            if ($sendNow) {
                return $this->deliverGenerated((int) $existing['id']);
            }
            return $this->decorateGenerated($existing);
        }

        $scope = $scope === 'admin' ? 'admin' : 'tenant';
        if ($scope === 'tenant' && (!$tenantId || $tenantId < 1)) {
            throw new RuntimeException('Empresa obrigatória para gerar o relatório.');
        }

        $filters = [
            'start' => $start,
            'end' => $end,
            'tenant_id' => (int) ($tenantId ?? 0),
        ];
        $data = $scope === 'admin'
            ? (new AdminExecutiveReportService($this->pdo))->build($filters)
            : (new TenantExecutiveReportService($this->pdo))->build($filters);
        $identity = $this->identity($scope, $tenantId, $name);
        $generated = $this->pdf->generate($scope, $filters, $data, $identity, $sections);
        $bytes = (string) $generated['bytes'];
        if (!str_starts_with($bytes, '%PDF-')) {
            throw new RuntimeException('O arquivo PDF não foi gerado corretamente.');
        }

        $uuid = $this->uuidV4();
        $filename = $this->safeFilename($name . '-' . $start . '-' . $end . '.pdf');
        $relative = ($tenantId ? 'tenant-' . $tenantId : 'admin-global')
            . '/' . date('Y/m') . '/' . $uuid . '.pdf';
        $absolute = $this->absolutePath($relative);
        $this->ensureDirectory(dirname($absolute));
        if (file_put_contents($absolute, $bytes, LOCK_EX) === false) {
            throw new RuntimeException('Não foi possível salvar o PDF no armazenamento privado.');
        }

        $now = Clock::nowUtc();
        $statement = $this->pdo->prepare(
            'INSERT INTO generated_reports
                (uuid, run_key, scheduled_report_id, tenant_id, created_by_user_id, report_scope,
                 report_name, period_start, period_end, status, original_filename, mime_type,
                 storage_path, size_bytes, sha256, summary_json, generated_at, expires_at, created_at, updated_at)
             VALUES
                (:uuid, :run_key, :scheduled_report_id, :tenant_id, :created_by_user_id, :report_scope,
                 :report_name, :period_start, :period_end, "ready", :original_filename, "application/pdf",
                 :storage_path, :size_bytes, :sha256, :summary_json, :generated_at, :expires_at, :created_at, :updated_at)'
        );
        $statement->execute([
            'uuid' => $uuid,
            'run_key' => mb_substr($runKey, 0, 190),
            'scheduled_report_id' => $schedule ? (int) $schedule['id'] : null,
            'tenant_id' => $tenantId,
            'created_by_user_id' => $userId > 0 ? $userId : null,
            'report_scope' => $scope,
            'report_name' => mb_substr($name, 0, 150),
            'period_start' => $start,
            'period_end' => $end,
            'original_filename' => $filename,
            'storage_path' => $relative,
            'size_bytes' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'summary_json' => json_encode($generated['summary'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'generated_at' => $now,
            'expires_at' => (new DateTimeImmutable($now, new DateTimeZone('UTC')))->add(new DateInterval('P365D'))->format('Y-m-d H:i:s'),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $reportId = (int) $this->pdo->lastInsertId();

        foreach ($destinations as $destination) {
            $phone = $this->normalizePhone((string) ($destination['phone'] ?? ''));
            if ($phone === '') {
                continue;
            }
            $this->pdo->prepare(
                'INSERT IGNORE INTO scheduled_report_deliveries
                    (generated_report_id, recipient_id, channel, destination, status, attempt_count, created_at, updated_at)
                 VALUES
                    (:generated_report_id, :recipient_id, "whatsapp", :destination, "pending", 0, :created_at, :updated_at)'
            )->execute([
                'generated_report_id' => $reportId,
                'recipient_id' => !empty($destination['recipient_id']) ? (int) $destination['recipient_id'] : null,
                'destination' => $phone,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if ($instanceId !== null && $instanceId > 0 && $schedule === null) {
            // Programação manual não tem coluna própria para a instância.
            // O identificador é preservado no resumo para a tentativa imediata.
            $summary = is_array($generated['summary'] ?? null) ? $generated['summary'] : [];
            $summary['manual_evolution_instance_id'] = $instanceId;
            $this->pdo->prepare(
                'UPDATE generated_reports SET summary_json = :summary_json WHERE id = :id'
            )->execute([
                'summary_json' => json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'id' => $reportId,
            ]);
        }

        if ($sendNow && $destinations !== []) {
            return $this->deliverGenerated($reportId);
        }
        return $this->decorateGenerated($this->generatedById($reportId) ?: []);
    }

    /**
     * @return array<string,mixed>
     */
    private function deliverGenerated(int $reportId): array
    {
        $report = $this->generatedById($reportId);
        if (!$report) {
            throw new RuntimeException('Relatório não encontrado para envio.');
        }
        $deliveries = $this->fetchAll(
            'SELECT * FROM scheduled_report_deliveries
             WHERE generated_report_id = :report_id AND status IN ("pending","failed")
             ORDER BY id',
            ['report_id' => $reportId]
        );
        if ($deliveries === []) {
            return $this->decorateGenerated($report);
        }

        $instanceId = !empty($report['evolution_instance_id']) ? (int) $report['evolution_instance_id'] : 0;
        if ($instanceId < 1) {
            $summary = json_decode((string) ($report['summary_json'] ?? '{}'), true);
            if (is_array($summary) && !empty($summary['manual_evolution_instance_id'])) {
                $instanceId = (int) $summary['manual_evolution_instance_id'];
            }
        }
        if ($instanceId < 1 && !empty($report['tenant_id'])) {
            $instance = $this->fetchOne(
                'SELECT id FROM evolution_instances
                 WHERE tenant_id = :tenant_id
                 ORDER BY status = "connected" DESC, is_default DESC, id
                 LIMIT 1',
                ['tenant_id' => (int) $report['tenant_id']]
            );
            $instanceId = (int) ($instance['id'] ?? 0);
        }
        if ($instanceId < 1) {
            $this->failDeliveries($reportId, 'Nenhuma conexão de WhatsApp foi selecionada para o envio.');
            return $this->refreshGeneratedStatus($reportId);
        }

        $instance = $this->fetchOne(
            'SELECT id, tenant_id, name, instance_name, base_url, api_key_encrypted, status
             FROM evolution_instances WHERE id = :id LIMIT 1',
            ['id' => $instanceId]
        );
        if (!$instance) {
            $this->failDeliveries($reportId, 'A conexão de WhatsApp configurada não existe.');
            return $this->refreshGeneratedStatus($reportId);
        }

        $path = $this->absolutePath((string) ($report['storage_path'] ?? ''));
        $bytes = is_file($path) ? file_get_contents($path) : false;
        if ($bytes === false) {
            $this->failDeliveries($reportId, 'O PDF não está disponível no armazenamento privado.');
            return $this->refreshGeneratedStatus($reportId);
        }

        try {
            $evolution = new EvolutionService(
                (string) $instance['base_url'],
                Crypto::decrypt((string) $instance['api_key_encrypted']),
                (string) $instance['instance_name'],
                max(10, (int) Env::get('SCHEDULED_REPORTS_WHATSAPP_TIMEOUT', 45)),
                filter_var(Env::get('EVOLUTION_SSL_VERIFY', true), FILTER_VALIDATE_BOOL),
                trim((string) Env::get('EVOLUTION_CA_BUNDLE', '')) ?: null
            );
        } catch (Throwable $exception) {
            $this->failDeliveries($reportId, $exception->getMessage());
            return $this->refreshGeneratedStatus($reportId);
        }

        $this->pdo->prepare(
            'UPDATE generated_reports SET status = "sending", updated_at = :updated_at WHERE id = :id'
        )->execute(['updated_at' => Clock::nowUtc(), 'id' => $reportId]);

        foreach ($deliveries as $delivery) {
            $now = Clock::nowUtc();
            try {
                $response = $evolution->sendMedia(
                    (string) $delivery['destination'],
                    'document',
                    'application/pdf',
                    (string) $report['original_filename'],
                    base64_encode($bytes),
                    'Relatório ' . (string) $report['report_name']
                        . ' · ' . $this->dateBr((string) $report['period_start'])
                        . ' a ' . $this->dateBr((string) $report['period_end'])
                );
                $providerId = $this->providerMessageId($response);
                $this->pdo->prepare(
                    'UPDATE scheduled_report_deliveries
                     SET status = "sent",
                         attempt_count = attempt_count + 1,
                         provider_message_id = :provider_message_id,
                         error_message = NULL,
                         last_attempt_at = :now,
                         sent_at = :now,
                         updated_at = :now
                     WHERE id = :id'
                )->execute([
                    'provider_message_id' => $providerId,
                    'now' => $now,
                    'id' => (int) $delivery['id'],
                ]);
            } catch (Throwable $exception) {
                $this->pdo->prepare(
                    'UPDATE scheduled_report_deliveries
                     SET status = "failed",
                         attempt_count = attempt_count + 1,
                         error_message = :error_message,
                         last_attempt_at = :now,
                         updated_at = :now
                     WHERE id = :id'
                )->execute([
                    'error_message' => mb_substr($exception->getMessage(), 0, 1000),
                    'now' => $now,
                    'id' => (int) $delivery['id'],
                ]);
            }
        }

        return $this->refreshGeneratedStatus($reportId);
    }

    private function refreshGeneratedStatus(int $reportId): array
    {
        $counts = $this->fetchOne(
            'SELECT COUNT(*) AS total,
                    SUM(status = "sent") AS sent,
                    SUM(status = "failed") AS failed,
                    SUM(status = "pending") AS pending
             FROM scheduled_report_deliveries
             WHERE generated_report_id = :report_id',
            ['report_id' => $reportId]
        ) ?: [];
        $total = (int) ($counts['total'] ?? 0);
        $sent = (int) ($counts['sent'] ?? 0);
        $failed = (int) ($counts['failed'] ?? 0);
        $status = $total === 0 ? 'ready' : ($sent === $total ? 'sent' : ($sent > 0 ? 'partial' : ($failed > 0 ? 'failed' : 'ready')));
        $now = Clock::nowUtc();
        $this->pdo->prepare(
            'UPDATE generated_reports
             SET status = :status,
                 sent_at = :sent_at,
                 error_message = :error_message,
                 updated_at = :updated_at
             WHERE id = :id'
        )->execute([
            'status' => $status,
            'sent_at' => $sent > 0 ? $now : null,
            'error_message' => $status === 'failed' ? 'Nenhum destinatário recebeu o relatório.' : null,
            'updated_at' => $now,
            'id' => $reportId,
        ]);
        return $this->decorateGenerated($this->generatedById($reportId) ?: []);
    }

    private function failDeliveries(int $reportId, string $message): void
    {
        $now = Clock::nowUtc();
        $this->pdo->prepare(
            'UPDATE scheduled_report_deliveries
             SET status = "failed",
                 attempt_count = attempt_count + 1,
                 error_message = :error_message,
                 last_attempt_at = :now,
                 updated_at = :now
             WHERE generated_report_id = :report_id AND status IN ("pending","failed")'
        )->execute([
            'error_message' => mb_substr($message, 0, 1000),
            'now' => $now,
            'report_id' => $reportId,
        ]);
    }

    /**
     * @return array{name:string,primary:string,secondary:string,accent:string,report_title:string}
     */
    private function identity(string $scope, ?int $tenantId, string $reportName): array
    {
        if ($scope === 'tenant' && $tenantId) {
            $branding = BrandingService::forTenantId($tenantId);
            return [
                'name' => (string) ($branding['app_name'] ?? 'Empresa'),
                'primary' => (string) ($branding['primary'] ?? '#146498'),
                'secondary' => (string) ($branding['secondary'] ?? '#631b7c'),
                'accent' => (string) ($branding['accent'] ?? '#01c5b6'),
                'report_title' => $reportName,
            ];
        }

        if ($tenantId) {
            $tenant = $this->fetchOne('SELECT name FROM tenants WHERE id = :id LIMIT 1', ['id' => $tenantId]);
            $suffix = trim((string) ($tenant['name'] ?? ''));
            return [
                'name' => $suffix !== '' ? 'RS Connect · ' . $suffix : 'RS Connect',
                'primary' => '#146498',
                'secondary' => '#631b7c',
                'accent' => '#01c5b6',
                'report_title' => $reportName,
            ];
        }

        return [
            'name' => 'RS Connect',
            'primary' => '#146498',
            'secondary' => '#631b7c',
            'accent' => '#01c5b6',
            'report_title' => $reportName,
        ];
    }

    private function replaceRecipients(int $scheduleId, string $raw): void
    {
        $recipients = $this->parseRecipients($raw);
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('DELETE FROM scheduled_report_recipients WHERE scheduled_report_id = :id')
                ->execute(['id' => $scheduleId]);
            $insert = $this->pdo->prepare(
                'INSERT INTO scheduled_report_recipients
                    (scheduled_report_id, name, phone, enabled, created_at, updated_at)
                 VALUES
                    (:scheduled_report_id, :name, :phone, 1, :created_at, :updated_at)'
            );
            $now = Clock::nowUtc();
            foreach ($recipients as $recipient) {
                $insert->execute([
                    'scheduled_report_id' => $scheduleId,
                    'name' => trim((string) ($recipient['name'] ?? '')) ?: null,
                    'phone' => (string) $recipient['phone'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * @return list<array{name:string,phone:string}>
     */
    private function parseRecipients(string $raw): array
    {
        $rows = preg_split('/[\r\n;,]+/', $raw) ?: [];
        $result = [];
        $seen = [];
        foreach ($rows as $row) {
            $row = trim($row);
            if ($row === '') {
                continue;
            }
            $name = '';
            $phonePart = $row;
            if (str_contains($row, '|')) {
                [$name, $phonePart] = array_map('trim', explode('|', $row, 2));
            }
            $phone = $this->normalizePhone($phonePart);
            if ($phone === '' || isset($seen[$phone])) {
                continue;
            }
            $seen[$phone] = true;
            $result[] = ['name' => mb_substr($name, 0, 150), 'phone' => $phone];
        }
        return $result;
    }

    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?: '';
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        if (in_array(strlen($digits), [10, 11], true)) {
            $digits = '55' . $digits;
        }
        return strlen($digits) >= 12 && strlen($digits) <= 15 ? $digits : '';
    }

    /**
     * @return array{start:string,end:string}
     */
    private function periodForMode(string $mode, string $timezone): array
    {
        $tz = new DateTimeZone(Clock::safeTimezone($timezone));
        $today = new DateTimeImmutable('today', $tz);
        return match ($mode) {
            'previous_day' => [
                'start' => $today->modify('-1 day')->format('Y-m-d'),
                'end' => $today->modify('-1 day')->format('Y-m-d'),
            ],
            'previous_month' => [
                'start' => $today->modify('first day of previous month')->format('Y-m-d'),
                'end' => $today->modify('last day of previous month')->format('Y-m-d'),
            ],
            'last_7_days' => [
                'start' => $today->modify('-6 days')->format('Y-m-d'),
                'end' => $today->format('Y-m-d'),
            ],
            'last_30_days' => [
                'start' => $today->modify('-29 days')->format('Y-m-d'),
                'end' => $today->format('Y-m-d'),
            ],
            'current_month' => [
                'start' => $today->modify('first day of this month')->format('Y-m-d'),
                'end' => $today->format('Y-m-d'),
            ],
            default => [
                'start' => $today->modify('monday previous week')->format('Y-m-d'),
                'end' => $today->modify('sunday previous week')->format('Y-m-d'),
            ],
        };
    }

    private function nextRunUtc(
        string $frequency,
        string $time,
        ?int $weekday,
        ?int $monthDay,
        string $timezone
    ): ?string {
        if ($frequency === 'manual') {
            return null;
        }
        $tz = new DateTimeZone(Clock::safeTimezone($timezone));
        $now = new DateTimeImmutable('now', $tz);
        [$hour, $minute] = array_map('intval', array_slice(explode(':', $time), 0, 2));
        $candidate = $now->setTime($hour, $minute, 0);

        if ($frequency === 'daily') {
            if ($candidate <= $now) {
                $candidate = $candidate->modify('+1 day');
            }
        } elseif ($frequency === 'weekly') {
            $target = max(1, min(7, (int) ($weekday ?? 1)));
            $current = (int) $now->format('N');
            $days = ($target - $current + 7) % 7;
            $candidate = $now->modify('+' . $days . ' days')->setTime($hour, $minute, 0);
            if ($candidate <= $now) {
                $candidate = $candidate->modify('+7 days');
            }
        } else {
            $day = max(1, min(28, (int) ($monthDay ?? 1)));
            $candidate = $now->setDate((int) $now->format('Y'), (int) $now->format('m'), $day)->setTime($hour, $minute, 0);
            if ($candidate <= $now) {
                $next = $now->modify('first day of next month');
                $candidate = $next->setDate((int) $next->format('Y'), (int) $next->format('m'), $day)->setTime($hour, $minute, 0);
            }
        }

        return $candidate->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function tenantTimezone(int $tenantId): string
    {
        if ($tenantId < 1) {
            return Clock::appTimezone();
        }
        $row = $this->fetchOne(
            'SELECT COALESCE(NULLIF(os.business_timezone, ""), NULLIF(cas.timezone, ""), :fallback) AS timezone
             FROM tenants t
             LEFT JOIN tenant_onboarding_settings os ON os.tenant_id = t.id
             LEFT JOIN calendar_availability_settings cas ON cas.tenant_id = t.id
             WHERE t.id = :tenant_id
             LIMIT 1',
            ['fallback' => Clock::appTimezone(), 'tenant_id' => $tenantId]
        );
        return Clock::safeTimezone((string) ($row['timezone'] ?? Clock::appTimezone()));
    }

    /**
     * @return list<string>
     */
    private function normalizeSections(string $scope, mixed $sections): array
    {
        $sections = is_array($sections) ? $sections : [];
        $allowed = $scope === 'admin' ? self::ADMIN_SECTIONS : self::TENANT_SECTIONS;
        $normalized = array_values(array_intersect($allowed, array_map('strval', $sections)));
        return $normalized !== [] ? $normalized : $allowed;
    }

    /**
     * @return list<array{key:string,label:string}>
     */
    private function sectionOptions(string $scope): array
    {
        $labels = [
            'overview' => 'Indicadores principais',
            'companies' => 'Desempenho por empresa',
            'usage' => 'Atendimentos ao longo do tempo',
            'health' => 'Saúde da operação',
            'automation' => 'IA e automações',
            'commercial' => 'Comercial RS',
            'conversations' => 'Conversas e evolução',
            'team' => 'Equipe',
            'agenda' => 'Agenda',
            'ai' => 'Uso da IA',
            'attention' => 'Itens que precisam de atenção',
        ];
        $keys = $scope === 'admin' ? self::ADMIN_SECTIONS : self::TENANT_SECTIONS;
        return array_map(static fn (string $key): array => ['key' => $key, 'label' => $labels[$key] ?? $key], $keys);
    }

    private function assertInstanceAllowed(int $instanceId, int $tenantId, bool $superAdmin): void
    {
        $instance = $this->fetchOne(
            'SELECT id, tenant_id FROM evolution_instances WHERE id = :id LIMIT 1',
            ['id' => $instanceId]
        );
        if (!$instance) {
            throw new RuntimeException('Conexão de WhatsApp não encontrada.');
        }
        if (!$superAdmin && (int) $instance['tenant_id'] !== $tenantId) {
            throw new RuntimeException('A conexão de WhatsApp não pertence à sua empresa.');
        }
    }

    private function assertScheduleAccess(array $schedule, bool $superAdmin, ?int $tenantId): void
    {
        if (!$superAdmin && ((int) ($schedule['tenant_id'] ?? 0) !== (int) $tenantId || (string) $schedule['report_scope'] !== 'tenant')) {
            throw new RuntimeException('Programação não encontrada.');
        }
    }

    private function assertGeneratedAccess(array $report, bool $superAdmin, ?int $tenantId): void
    {
        if (!$superAdmin && ((int) ($report['tenant_id'] ?? 0) !== (int) $tenantId || (string) $report['report_scope'] !== 'tenant')) {
            throw new RuntimeException('Relatório não encontrado.');
        }
    }

    private function scheduleByUuid(string $uuid): ?array
    {
        return $this->fetchOne('SELECT * FROM scheduled_reports WHERE uuid = :uuid LIMIT 1', ['uuid' => $uuid]);
    }

    private function generatedByUuid(string $uuid): ?array
    {
        return $this->fetchOne(
            'SELECT gr.*, sr.evolution_instance_id
             FROM generated_reports gr
             LEFT JOIN scheduled_reports sr ON sr.id = gr.scheduled_report_id
             WHERE gr.uuid = :uuid
             LIMIT 1',
            ['uuid' => $uuid]
        );
    }

    private function generatedById(int $id): ?array
    {
        return $this->fetchOne(
            'SELECT gr.*, sr.evolution_instance_id
             FROM generated_reports gr
             LEFT JOIN scheduled_reports sr ON sr.id = gr.scheduled_report_id
             WHERE gr.id = :id
             LIMIT 1',
            ['id' => $id]
        );
    }

    private function decorateSchedule(array $row): array
    {
        $row['sections'] = json_decode((string) ($row['sections_json'] ?? '[]'), true) ?: [];
        $row['next_run_local'] = !empty($row['next_run_at'])
            ? Clock::utcToLocal((string) $row['next_run_at'], (string) ($row['timezone'] ?? Clock::appTimezone()), 'd/m/Y H:i')
            : '';
        $row['last_run_local'] = !empty($row['last_run_at'])
            ? Clock::utcToLocal((string) $row['last_run_at'], (string) ($row['timezone'] ?? Clock::appTimezone()), 'd/m/Y H:i')
            : '';
        $row['frequency_label'] = match ((string) ($row['frequency'] ?? '')) {
            'daily' => 'Diário',
            'weekly' => 'Semanal',
            'monthly' => 'Mensal',
            default => 'Manual',
        };
        return $row;
    }

    private function decorateGenerated(array $row): array
    {
        $row['size_label'] = $this->formatBytes((int) ($row['size_bytes'] ?? 0));
        $row['created_local'] = !empty($row['created_at'])
            ? Clock::utcToLocal((string) $row['created_at'], Clock::appTimezone(), 'd/m/Y H:i')
            : '';
        $row['status_label'] = match ((string) ($row['status'] ?? '')) {
            'ready' => (int) ($row['pending_count'] ?? 0) > 0 ? 'Aguardando envio' : 'PDF pronto',
            'sending' => 'Enviando',
            'sent' => 'Enviado',
            'partial' => 'Enviado parcialmente',
            'failed' => 'Não concluído',
            'expired' => 'Expirado',
            default => 'Gerando',
        };
        return $row;
    }

    private function providerMessageId(array $response): string
    {
        $body = is_array($response['body'] ?? null) ? $response['body'] : [];
        $value = $body['key']['id'] ?? $body['messageId'] ?? $body['id'] ?? '';
        return mb_substr(trim((string) $value), 0, 190);
    }

    private function absolutePath(string $relative): string
    {
        $relative = str_replace('\\', '/', trim($relative));
        $relative = ltrim($relative, '/');
        if ($relative === '' || str_contains($relative, '..')) {
            return $this->storagePath . '/invalid';
        }
        return $this->storagePath . '/' . $relative;
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('Não foi possível preparar o armazenamento de relatórios.');
        }
        if (!is_writable($directory)) {
            throw new RuntimeException('O armazenamento de relatórios não possui permissão de escrita.');
        }
    }

    private function safeFilename(string $value): string
    {
        $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
        $value = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $value) ?: 'relatorio.pdf';
        $value = trim($value, '-.');
        if (!str_ends_with(strtolower($value), '.pdf')) {
            $value .= '.pdf';
        }
        return mb_substr($value, 0, 190);
    }

    private function date(string $value, string $fallback): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value)) ? trim($value) : $fallback;
    }

    private function choice(string $value, array $allowed, string $fallback): string
    {
        return in_array($value, $allowed, true) ? $value : $fallback;
    }

    private function dateBr(string $date): string
    {
        $timestamp = strtotime($date);
        return $timestamp ? date('d/m/Y', $timestamp) : $date;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1048576) {
            return number_format($bytes / 1024, 1, ',', '.') . ' KB';
        }
        return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
    }

    private function uuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function fetchOne(string $sql, array $params = []): ?array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function fetchAll(string $sql, array $params = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
