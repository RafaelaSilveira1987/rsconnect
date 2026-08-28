<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;
use Throwable;

final class MigrationService
{
    private const LOCK_NAME = 'rs_connect_schema_migrations_v1';
    private const REGISTRY_FILE = '089_schema_migrations_registry.sql';

    /** @var array<string,mixed> */
    private array $manifest;

    public function __construct(
        private readonly ?PDO $pdo,
        private readonly string $root,
    ) {
        $manifestFile = $this->root . '/database/migrations/manifest.php';
        if (!is_file($manifestFile)) {
            throw new RuntimeException('Manifesto de migrations não encontrado.');
        }

        $manifest = require $manifestFile;
        if (!is_array($manifest)) {
            throw new RuntimeException('Manifesto de migrations inválido.');
        }
        $this->manifest = $manifest;
    }

    /** @return array{files:int,statements:int,duplicate_numbers:array<string,list<string>>,rollbacks:list<string>} */
    public function verifyOffline(): array
    {
        $entries = $this->entries();
        $seenFiles = [];
        $seenSequences = [];
        $statements = 0;
        $numbers = [];

        foreach ($entries as $expectedSequence => $entry) {
            $sequence = (int) ($entry['sequence'] ?? 0);
            $file = (string) ($entry['file'] ?? '');
            if ($sequence !== $expectedSequence + 1) {
                throw new RuntimeException('Sequência não contínua no manifesto: ' . $file . '.');
            }
            if ($file === '' || isset($seenFiles[$file])) {
                throw new RuntimeException('Migration duplicada no manifesto: ' . $file . '.');
            }
            if (isset($seenSequences[$sequence])) {
                throw new RuntimeException('Número de sequência duplicado no manifesto: ' . $sequence . '.');
            }

            $path = $this->migrationPath($file);
            if (!is_file($path)) {
                throw new RuntimeException('Arquivo de migration ausente: ' . $file . '.');
            }
            $parsed = SqlScriptParser::parse((string) file_get_contents($path));
            if ($parsed === []) {
                throw new RuntimeException('Migration sem instruções executáveis: ' . $file . '.');
            }
            $statements += count($parsed);
            $seenFiles[$file] = true;
            $seenSequences[$sequence] = true;
            $number = preg_match('/^(\d+)_/', $file, $matches) === 1 ? $matches[1] : 'sem-numero';
            $numbers[$number][] = $file;
        }

        $rollbacks = array_values(array_map('strval', $this->manifest['rollbacks'] ?? []));
        foreach ($rollbacks as $rollback) {
            if (isset($seenFiles[$rollback])) {
                throw new RuntimeException('Rollback não pode fazer parte do fluxo de subida: ' . $rollback . '.');
            }
            if (!is_file($this->migrationPath($rollback))) {
                throw new RuntimeException('Rollback declarado não encontrado: ' . $rollback . '.');
            }
            SqlScriptParser::parse((string) file_get_contents($this->migrationPath($rollback)));
        }

        $known = array_fill_keys(array_merge(array_keys($seenFiles), $rollbacks), true);
        foreach (glob($this->root . '/database/migrations/*.sql') ?: [] as $path) {
            $file = basename($path);
            if (!isset($known[$file])) {
                throw new RuntimeException('Arquivo SQL sem classificação no manifesto: ' . $file . '.');
            }
        }

        $snapshot = (string) ($this->manifest['schema_snapshot']['file'] ?? '');
        $snapshotThrough = (string) ($this->manifest['schema_snapshot']['through'] ?? '');
        $bootstrapSeed = (string) ($this->manifest['bootstrap_seed'] ?? '');
        $seed = (string) ($this->manifest['seed'] ?? '');
        if ($snapshotThrough === '' || !str_contains(
            (string) file_get_contents($this->root . '/' . ltrim($snapshot, '/')),
            'SCHEMA_EXECUTION_BASELINE_THROUGH: ' . $snapshotThrough
        )) {
            throw new RuntimeException('O marcador do baseline em database/schema.sql não corresponde ao manifesto.');
        }
        $registryEntries = array_values(array_filter(
            $entries,
            static fn (array $entry): bool => (string) ($entry['file'] ?? '') === self::REGISTRY_FILE
        ));
        if (count($registryEntries) !== 1) {
            throw new RuntimeException('A migration de registro precisa existir uma única vez no manifesto.');
        }
        foreach ([$snapshot, $bootstrapSeed, $seed] as $relative) {
            $path = $this->root . '/' . ltrim($relative, '/');
            if (!is_file($path)) {
                throw new RuntimeException('Arquivo de instalação ausente: ' . $relative . '.');
            }
            $statements += count(SqlScriptParser::parse((string) file_get_contents($path)));
        }

        // O snapshot contém algumas tabelas já evoluídas para acelerar instalações.
        // Se uma migration posterior declarar a mesma tabela, o snapshot precisa ser
        // um superset para que CREATE TABLE IF NOT EXISTS não esconda colunas faltantes.
        $schemaTables = self::tableColumnsFromSql((string) file_get_contents($this->root . '/' . ltrim($snapshot, '/')));
        foreach ($entries as $entry) {
            $migrationTables = self::tableColumnsFromSql((string) file_get_contents($this->migrationPath((string) $entry['file'])));
            foreach ($migrationTables as $table => $columns) {
                if (!isset($schemaTables[$table])) {
                    continue;
                }
                $missingColumns = array_values(array_diff($columns, $schemaTables[$table]));
                if ($missingColumns !== []) {
                    throw new RuntimeException(
                        'Snapshot incompleto para a tabela ' . $table . ': faltam ' . implode(', ', $missingColumns) . '.'
                    );
                }
            }
        }

        $duplicates = [];
        foreach ($numbers as $number => $files) {
            if (count($files) > 1) {
                $duplicates[$number] = $files;
            }
        }

        return [
            'files' => count($entries),
            'statements' => $statements,
            'duplicate_numbers' => $duplicates,
            'rollbacks' => $rollbacks,
        ];
    }

    /** @return array{registry:bool,applied:int,pending:int,drift:list<string>,rows:list<array<string,mixed>>} */
    public function status(): array
    {
        $registry = $this->tableExists('schema_migrations');
        $applied = $registry ? $this->appliedMap() : [];
        $rows = [];
        $drift = [];

        foreach ($this->entries() as $entry) {
            $file = (string) $entry['file'];
            $checksum = $this->checksum($file);
            $record = $applied[$file] ?? null;
            $state = $record ? 'applied' : 'pending';
            if ($record && (
                !hash_equals((string) $record['checksum'], $checksum)
                || (int) ($record['sequence_no'] ?? 0) !== (int) $entry['sequence']
            )) {
                $state = 'drift';
                $drift[] = $file;
            }
            $rows[] = [
                'sequence' => (int) $entry['sequence'],
                'file' => $file,
                'state' => $state,
                'source' => $record['source'] ?? null,
                'batch' => isset($record['batch']) ? (int) $record['batch'] : null,
                'applied_at' => $record['applied_at'] ?? null,
            ];
        }

        return [
            'registry' => $registry,
            'applied' => count($applied),
            'pending' => count(array_filter($rows, static fn (array $row): bool => $row['state'] === 'pending')),
            'drift' => $drift,
            'rows' => $rows,
        ];
    }

    /** @return list<string> */
    public function up(bool $dryRun = false): array
    {
        return $this->withLock(function () use ($dryRun): array {
            $this->ensureRegistry();
            $applied = $this->appliedMap();
            if ($this->applicationTableCount() === 0) {
                throw new RuntimeException('Banco vazio. Use: php bin/migrate.php install --yes');
            }
            if ($applied === [] || (count($applied) === 1 && isset($applied[self::REGISTRY_FILE]))) {
                throw new RuntimeException('Banco existente sem histórico. Execute primeiro: php bin/migrate.php baseline --through=088 --yes');
            }
            $this->assertNoDrift($applied);

            $pending = array_values(array_filter(
                $this->entries(),
                static fn (array $entry): bool => !isset($applied[(string) $entry['file']])
            ));
            if ($dryRun) {
                return array_map(static fn (array $entry): string => (string) $entry['file'], $pending);
            }

            $batch = $this->nextBatch();
            $executed = [];
            foreach ($pending as $entry) {
                $file = (string) $entry['file'];
                $elapsed = $this->executeMigration($file);
                $this->record($entry, $batch, 'runner', $elapsed);
                $executed[] = $file;
            }
            return $executed;
        });
    }

    /** @return list<string> */
    public function baseline(string $through): array
    {
        return $this->withLock(function () use ($through): array {
            if ($this->applicationTableCount() === 0) {
                throw new RuntimeException('O banco está vazio. Use o comando install em vez de baseline.');
            }
            $this->ensureRegistry();
            $target = $this->resolveTarget($through);
            $this->verifyBaselineStructure((string) $target['file']);

            $existing = $this->appliedMap();
            if ($existing !== []) {
                $unexpected = array_diff(array_keys($existing), [self::REGISTRY_FILE]);
                if ($unexpected !== []) {
                    throw new RuntimeException('O banco já possui histórico de migrations. Use status ou up.');
                }
            }

            $recorded = [];
            foreach ($this->entries() as $entry) {
                if ((int) $entry['sequence'] <= (int) $target['sequence'] || (string) $entry['file'] === self::REGISTRY_FILE) {
                    if (!isset($existing[(string) $entry['file']])) {
                        $this->record($entry, 0, (string) $entry['file'] === self::REGISTRY_FILE ? 'bootstrap' : 'baseline', 0);
                        $recorded[] = (string) $entry['file'];
                    }
                }
            }
            return $recorded;
        });
    }

    /** @return list<string> */
    public function install(): array
    {
        return $this->withLock(function (): array {
            if ($this->applicationTableCount() > 0) {
                throw new RuntimeException('Instalação recusada: o banco já contém tabelas da aplicação.');
            }

            $snapshot = $this->root . '/' . ltrim((string) $this->manifest['schema_snapshot']['file'], '/');
            $bootstrapSeed = $this->root . '/' . ltrim((string) $this->manifest['bootstrap_seed'], '/');
            $seed = $this->root . '/' . ltrim((string) $this->manifest['seed'], '/');

            // O snapshot contém o núcleo histórico até a migration 004. O seed
            // inicial cria a empresa de demonstração antes dos backfills por tenant.
            $this->executeSqlFile($snapshot, 'schema.sql');
            $this->executeSqlFile($bootstrapSeed, 'seed.sql');
            $this->ensureRegistry();

            $baseline = $this->resolveTarget((string) $this->manifest['schema_snapshot']['through']);
            $recorded = [];
            foreach ($this->entries() as $entry) {
                if ((int) $entry['sequence'] <= (int) $baseline['sequence'] || (string) $entry['file'] === self::REGISTRY_FILE) {
                    $this->record(
                        $entry,
                        0,
                        (string) $entry['file'] === self::REGISTRY_FILE ? 'bootstrap' : 'install',
                        0
                    );
                    $recorded[] = (string) $entry['file'];
                }
            }

            // Executa toda evolução posterior ao snapshot na ordem canônica.
            $batch = 1;
            foreach ($this->entries() as $entry) {
                $file = (string) $entry['file'];
                if ((int) $entry['sequence'] <= (int) $baseline['sequence'] || $file === self::REGISTRY_FILE) {
                    continue;
                }
                $elapsed = $this->executeMigration($file);
                $this->record($entry, $batch, 'install', $elapsed);
                $recorded[] = $file;
            }

            // Reconcilia dados de referência somente depois que todas as tabelas
            // e colunas da versão atual estiverem disponíveis.
            $this->executeSqlFile($seed, 'seed.reference.sql');
            return $recorded;
        });
    }

    public function seed(): void
    {
        $this->withLock(function (): void {
            $this->ensureRegistry();
            $status = $this->status();
            if ($status['drift'] !== []) {
                throw new RuntimeException('Seed recusado: existem migrations com checksum ou sequência divergente.');
            }
            if ((int) $status['pending'] > 0) {
                throw new RuntimeException('Seed recusado: aplique primeiro todas as migrations pendentes.');
            }
            $seed = $this->root . '/' . ltrim((string) $this->manifest['seed'], '/');
            $this->executeSqlFile($seed, 'seed.reference.sql');
        });
    }

    /** @return list<string> */
    public function bootstrap(): array
    {
        if ($this->applicationTableCount() === 0) {
            return $this->install();
        }
        if (!$this->tableExists('schema_migrations') || $this->appliedMap() === []) {
            $this->baseline('088');
        }
        return $this->up();
    }

    /** @return list<array{sequence:int,file:string}> */
    private function entries(): array
    {
        /** @var list<array{sequence:int,file:string}> $entries */
        $entries = $this->manifest['migrations'] ?? [];
        return $entries;
    }

    private function migrationPath(string $file): string
    {
        return $this->root . '/database/migrations/' . basename($file);
    }

    private function checksum(string $file): string
    {
        $checksum = hash_file('sha256', $this->migrationPath($file));
        if (!is_string($checksum)) {
            throw new RuntimeException('Não foi possível calcular checksum de ' . $file . '.');
        }
        return $checksum;
    }

    private function ensureRegistry(): void
    {
        if (!$this->tableExists('schema_migrations')) {
            $this->executeSqlFile($this->migrationPath(self::REGISTRY_FILE), self::REGISTRY_FILE);
        }
    }

    private function executeMigration(string $file): int
    {
        return $this->executeSqlFile($this->migrationPath($file), $file);
    }

    private function executeSqlFile(string $path, string $label): int
    {
        if (!is_file($path)) {
            throw new RuntimeException('Arquivo SQL não encontrado: ' . $label . '.');
        }
        $statements = SqlScriptParser::parse((string) file_get_contents($path));
        $started = microtime(true);
        foreach ($statements as $position => $statement) {
            $normalized = strtoupper(ltrim(self::withoutLeadingComments($statement)));
            if (str_starts_with($normalized, 'USE ') || str_starts_with($normalized, 'CREATE DATABASE ')) {
                continue;
            }
            try {
                $this->db()->exec($statement);
            } catch (Throwable $exception) {
                throw new RuntimeException(
                    sprintf('Falha em %s, instrução %d. Revise o banco antes de repetir.', $label, $position + 1),
                    0,
                    $exception
                );
            }
        }
        return (int) round((microtime(true) - $started) * 1000);
    }

    /** @param array{sequence:int,file:string} $entry */
    private function record(array $entry, int $batch, string $source, int $executionMs): void
    {
        $statement = $this->db()->prepare(
            'INSERT INTO schema_migrations
                (sequence_no, migration, checksum, batch, source, execution_ms, applied_by, applied_at)
             VALUES
                (:sequence_no, :migration, :checksum, :batch, :source, :execution_ms, :applied_by, UTC_TIMESTAMP())'
        );
        $statement->execute([
            'sequence_no' => (int) $entry['sequence'],
            'migration' => (string) $entry['file'],
            'checksum' => $this->checksum((string) $entry['file']),
            'batch' => $batch,
            'source' => $source,
            'execution_ms' => max(0, $executionMs),
            'applied_by' => substr((string) (getenv('MIGRATION_ACTOR') ?: gethostname() ?: 'cli'), 0, 190),
        ]);
    }

    /** @return array<string,array<string,mixed>> */
    private function appliedMap(): array
    {
        if (!$this->tableExists('schema_migrations')) {
            return [];
        }
        $rows = $this->db()->query('SELECT sequence_no, migration, checksum, batch, source, applied_at FROM schema_migrations ORDER BY sequence_no')->fetchAll();
        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row['migration']] = $row;
        }
        return $map;
    }

    /** @param array<string,array<string,mixed>> $applied */
    private function assertNoDrift(array $applied): void
    {
        foreach ($this->entries() as $entry) {
            $file = (string) $entry['file'];
            if (!isset($applied[$file])) {
                continue;
            }
            if (!hash_equals((string) $applied[$file]['checksum'], $this->checksum($file))) {
                throw new RuntimeException('Checksum divergente em migration aplicada: ' . $file . '. Não edite migrations históricas.');
            }
            if ((int) ($applied[$file]['sequence_no'] ?? 0) !== (int) $entry['sequence']) {
                throw new RuntimeException('Sequência divergente em migration aplicada: ' . $file . '. Não reordene o manifesto histórico.');
            }
        }
    }

    private function nextBatch(): int
    {
        return (int) $this->db()->query('SELECT COALESCE(MAX(batch), 0) + 1 FROM schema_migrations')->fetchColumn();
    }

    /** @return array{sequence:int,file:string} */
    private function resolveTarget(string $through): array
    {
        $through = trim($through);
        if ($through === '' || strtolower($through) === 'latest') {
            return $this->entries()[array_key_last($this->entries())];
        }

        $matches = [];
        foreach ($this->entries() as $entry) {
            $file = (string) $entry['file'];
            if ($file === $through || preg_match('/^' . preg_quote(str_pad($through, 3, '0', STR_PAD_LEFT), '/') . '_/', $file) === 1) {
                $matches[] = $entry;
            }
        }
        if ($matches === []) {
            throw new RuntimeException('Migration alvo não encontrada: ' . $through . '.');
        }
        if (count($matches) > 1) {
            throw new RuntimeException('Número ambíguo. Informe o nome completo da migration: ' . implode(', ', array_column($matches, 'file')) . '.');
        }
        return $matches[0];
    }

    private function verifyBaselineStructure(string $targetFile): void
    {
        $target = $this->resolveTarget($targetFile);
        if ((int) $target['sequence'] < (int) $this->resolveTarget('085')['sequence']) {
            throw new RuntimeException('Baseline automático é suportado somente para bancos na migration 085 ou superior.');
        }

        $requiredTables = [
            'tenants', 'users', 'evolution_instances', 'permissions', 'role_permissions',
            'ai_agents', 'contacts', 'conversations', 'conversation_messages',
            'crm_pipelines', 'crm_stages', 'crm_leads', 'crm_tasks',
            'saas_plans', 'tenant_subscriptions', 'tenant_invoices', 'payment_gateways',
            'tenant_implementation_status', 'tenant_implementation_checklist', 'tenant_implementation_checklists',
            'tenant_onboarding_progress', 'tenant_onboarding_settings',
            'operations_backup_routines', 'operations_backup_jobs', 'system_backups',
            'system_health_checks', 'tenant_calendar_availability_settings',
            'calendar_availability_requests', 'calendar_availability_slots',
            'calendar_google_sync_logs', 'tenant_notification_preferences',
            'tenant_admin_tracking', 'admin_crm_stages', 'admin_crm_opportunities',
            'admin_crm_activities', 'tenant_health_snapshots', 'tenant_health_checks',
            'tenant_health_incidents', 'tenant_health_incident_events',
            'conversation_flow_states', 'ai_agent_group_rules', 'report_daily_metrics',
            'operational_alert_preferences', 'admin_operational_notifications',
            'operational_alert_deliveries', 'client_communications',
            'client_communication_recipients', 'ai_usage_events',
            'ai_usage_threshold_events', 'ai_after_hours_pending',
            'ai_agent_instance_bindings', 'client_communication_replies',
            'ai_prompt_studio_drafts', 'ai_agent_prompt_versions',
            'evolution_connection_events', 'message_retention_runs',
            'conversation_assignment_history', 'conversation_status_history',
            'calendar_appointment_history', 'conversation_service_cycles',
            'rs_datetime_contract', 'security_rate_limits', 'operational_monitor_runs',
            'conversation_message_attachments', 'conversation_ai_memory',
            'contact_ai_memory', 'tenant_ai_commercial_attention_tracking',
        ];
        foreach ($requiredTables as $table) {
            if (!$this->tableExists($table)) {
                throw new RuntimeException('Baseline recusado: tabela obrigatória ausente: ' . $table . '.');
            }
        }

        if ((int) $target['sequence'] >= (int) $this->resolveTarget('086')['sequence']) {
            foreach ([
                ['saas_plans', 'own_ai_monthly_price'],
                ['saas_plans', 'rs_ai_monthly_price'],
                ['saas_plans', 'commitment_discounts_json'],
                ['tenant_subscriptions', 'ai_billing_mode'],
                ['tenant_subscriptions', 'commitment_months'],
                ['tenant_subscriptions', 'commitment_ends_at'],
            ] as [$table, $column]) {
                if (!$this->columnExists($table, $column)) {
                    throw new RuntimeException('Baseline recusado: coluna ausente ' . $table . '.' . $column . '.');
                }
            }
        }

        if ((int) $target['sequence'] >= (int) $this->resolveTarget('087')['sequence'] && !$this->tableExists('webhook_security_events')) {
            throw new RuntimeException('Baseline recusado: tabela webhook_security_events ausente.');
        }

        if ((int) $target['sequence'] >= (int) $this->resolveTarget('088')['sequence']) {
            foreach (['external_imported_at', 'payment_status_checked_at', 'access_released_at'] as $column) {
                if (!$this->columnExists('tenant_invoices', $column)) {
                    throw new RuntimeException('Baseline recusado: coluna tenant_invoices.' . $column . ' ausente.');
                }
            }
            if (!$this->indexExists('tenant_invoices', 'idx_invoice_external_payment')) {
                throw new RuntimeException('Baseline recusado: índice idx_invoice_external_payment ausente.');
            }
        }
    }

    private function tableExists(string $table): bool
    {
        $statement = $this->db()->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
        );
        $statement->execute(['table' => $table]);
        return (int) $statement->fetchColumn() > 0;
    }

    private function columnExists(string $table, string $column): bool
    {
        $statement = $this->db()->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column'
        );
        $statement->execute(['table' => $table, 'column' => $column]);
        return (int) $statement->fetchColumn() > 0;
    }

    private function indexExists(string $table, string $index): bool
    {
        $statement = $this->db()->prepare(
            'SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND INDEX_NAME = :index_name'
        );
        $statement->execute(['table' => $table, 'index_name' => $index]);
        return (int) $statement->fetchColumn() > 0;
    }

    private function applicationTableCount(): int
    {
        $statement = $this->db()->query(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME <> 'schema_migrations'"
        );
        return (int) $statement->fetchColumn();
    }

    /** @template T @param callable():T $callback @return T */
    private function withLock(callable $callback): mixed
    {
        $timeout = max(1, min(120, (int) (getenv('MIGRATIONS_LOCK_TIMEOUT') ?: 30)));
        $statement = $this->db()->prepare('SELECT GET_LOCK(:lock_name, :timeout)');
        $statement->execute(['lock_name' => self::LOCK_NAME, 'timeout' => $timeout]);
        if ((int) $statement->fetchColumn() !== 1) {
            throw new RuntimeException('Outra execução de migrations está em andamento.');
        }

        try {
            return $callback();
        } finally {
            $release = $this->db()->prepare('SELECT RELEASE_LOCK(:lock_name)');
            $release->execute(['lock_name' => self::LOCK_NAME]);
        }
    }

    /** @return array<string,list<string>> */
    private static function tableColumnsFromSql(string $sql): array
    {
        $tables = [];
        if (preg_match_all(
            '/CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+`?([A-Za-z0-9_]+)`?\s*\((.*?)\)\s*ENGINE/is',
            $sql,
            $matches,
            PREG_SET_ORDER
        ) !== false) {
            foreach ($matches as $match) {
                $table = (string) $match[1];
                $columns = [];
                foreach (preg_split('/\R/', (string) $match[2]) ?: [] as $line) {
                    $line = trim($line, " \t\n\r\0\x0B,");
                    if (preg_match('/^`?([A-Za-z0-9_]+)`?\s+/', $line, $columnMatch) !== 1) {
                        continue;
                    }
                    $column = (string) $columnMatch[1];
                    if (in_array(strtoupper($column), ['PRIMARY', 'UNIQUE', 'KEY', 'INDEX', 'CONSTRAINT', 'FOREIGN', 'CHECK'], true)) {
                        continue;
                    }
                    $columns[] = $column;
                }
                $tables[$table] = array_values(array_unique($columns));
            }
        }
        return $tables;
    }

    private function db(): PDO
    {
        if (!$this->pdo instanceof PDO) {
            throw new RuntimeException('Conexão com o banco não foi inicializada para este comando.');
        }
        return $this->pdo;
    }

    private static function withoutLeadingComments(string $statement): string
    {
        do {
            $before = $statement;
            $statement = preg_replace('/^\s*(?:--(?=\s)[^\n]*(?:\n|$)|#[^\n]*(?:\n|$)|\/\*.*?\*\/)/s', '', $statement) ?? $statement;
        } while ($statement !== $before);
        return ltrim($statement);
    }
}
