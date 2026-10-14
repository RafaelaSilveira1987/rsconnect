<?php

declare(strict_types=1);

/**
 * Testa o risco de COMMIT implicito de DDL no MySQL sem depender de PDO MySQL.
 * Em homologacao, complementar com uma gravacao e rollback em MySQL/InnoDB real.
 */
require_once dirname(__DIR__, 2) . '/app/Core/Autoloader.php';
\App\Core\Autoloader::register(dirname(__DIR__, 2) . '/app');

use App\Core\Database;
use App\Services\ImplementationChecklistService;
use App\Services\OnboardingGuideService;

final class TransactionSchemaPdo extends PDO
{
    public bool $transaction = false;
    public array $tables = [];
    public array $columns = [];
    public array $ddl = [];

    public function __construct() {}

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new TransactionSchemaStatement($this, $query);
    }

    public function exec(string $statement): int|false
    {
        if (!preg_match('/^\s*(?:CREATE\s+TABLE|ALTER\s+TABLE)/i', $statement)) {
            throw new RuntimeException('SQL inesperado no teste: ' . substr($statement, 0, 60));
        }
        // Simulacao da regra MySQL: DDL implica um COMMIT automatico.
        if ($this->transaction) {
            $this->transaction = false;
            throw new RuntimeException('DDL efetuou COMMIT implicito durante a transacao!');
        }
        $this->ddl[] = $statement;
        if (preg_match('/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+(\w+)/i', $statement, $m)) {
            $this->tables[$m[1]] = true;
            if (preg_match_all('/^\s*([a-z_]+)\s+(?:BIGINT|VARCHAR|ENUM|TEXT|SMALLINT|TINYINT|DATETIME|TIMESTAMP|JSON|LONGTEXT)/mi', $statement, $columns)) {
                foreach ($columns[1] as $column) $this->columns[$m[1]][$column] = true;
            }
        } elseif (preg_match('/ALTER\s+TABLE\s+(\w+)\s+ADD\s+COLUMN\s+(\w+)/i', $statement, $m)) {
            $this->columns[$m[1]][$m[2]] = true;
        }
        return 0;
    }

    public function beginTransaction(): bool
    {
        if ($this->transaction) throw new RuntimeException('Transacao ja aberta.');
        $this->transaction = true;
        return true;
    }

    public function inTransaction(): bool { return $this->transaction; }

    public function commit(): bool
    {
        if (!$this->transaction) throw new RuntimeException('There is no active transaction');
        $this->transaction = false;
        return true;
    }

    public function rollBack(): bool
    {
        if (!$this->transaction) throw new RuntimeException('There is no active transaction');
        $this->transaction = false;
        return true;
    }
}

final class TransactionSchemaStatement extends PDOStatement
{
    private array $args = [];

    public function __construct(private TransactionSchemaPdo $pdo, private string $sql) {}

    public function execute(?array $params = null): bool
    {
        $this->args = $params ?? [];
        return true;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        if (str_contains($this->sql, 'information_schema.TABLES')) {
            return (int) isset($this->pdo->tables[$this->args['table'] ?? '']);
        }
        if (str_contains($this->sql, 'information_schema.COLUMNS')) {
            return (int) isset($this->pdo->columns[$this->args['table'] ?? ''][$this->args['column'] ?? '']);
        }
        throw new RuntimeException('Query nao simulada: ' . $this->sql);
    }
}

function expectGuard(bool $assert, string $reason): void
{
    if (!$assert) throw new RuntimeException($reason);
}

function fakeService(TransactionSchemaPdo $pdo): OnboardingGuideService
{
    $prop = new ReflectionProperty(Database::class, 'connection');
    $prop->setValue(null, $pdo);
    return new OnboardingGuideService();
}

function invokeEnsure(OnboardingGuideService $service, string $method): void
{
    (new ReflectionMethod($service, $method))->invoke($service);
}

$pdo = new TransactionSchemaPdo();
$service = fakeService($pdo);
invokeEnsure($service, 'prepareBusinessOperationStorage');
foreach (['tenant_onboarding_progress', 'tenant_onboarding_events', 'tenant_onboarding_settings', 'tenant_pre_schedule_settings', 'tenant_implementation_checklist'] as $table) {
    expectGuard(isset($pdo->tables[$table]), "Preflight nao preparou $table");
}
expectGuard(count($pdo->ddl) >= 5, 'Nao foram feitas as preparacoes fora de transacao.');
$ddlCount = count($pdo->ddl);

$pdo->beginTransaction();
invokeEnsure($service, 'ensureTables');
invokeEnsure($service, 'ensureOnboardingSettingsTable');
invokeEnsure($service, 'ensurePreScheduleTable');
(new ImplementationChecklistService())->prepareStorageForWrite();
expectGuard($pdo->inTransaction(), 'A transacao foi encerrada durante a fase de gravacao.');
expectGuard(count($pdo->ddl) === $ddlCount, 'DDL rodou dentro da fase de gravacao.');
$pdo->commit();

// Uma tabela que ainda nao exista nao pode ser criada por um saveStep aninhado.
$pdoMissing = new TransactionSchemaPdo();
$missingService = fakeService($pdoMissing);
$pdoMissing->beginTransaction();
try {
    invokeEnsure($missingService, 'ensureTables');
    throw new RuntimeException('A guarda de tabela ausente nao bloqueou DDL.');
} catch (RuntimeException $e) {
    expectGuard(str_contains($e->getMessage(), 'migrations'), 'Erro inesperado para tabela ausente: ' . $e->getMessage());
}
expectGuard($pdoMissing->inTransaction() && $pdoMissing->ddl === [], 'Tentativa de DDL implicito com tabela ausente.');
$pdoMissing->rollBack();

// Coluna nova tambem deve ser preparada antes de abrir BEGIN.
$pdoMissing->tables['tenant_onboarding_settings'] = true;
$pdoMissing->beginTransaction();
try {
    invokeEnsure($missingService, 'ensureOnboardingSettingsTable');
    throw new RuntimeException('A guarda de coluna ausente nao bloqueou DDL.');
} catch (RuntimeException $e) {
    expectGuard(str_contains($e->getMessage(), 'migrations'), 'Erro inesperado para coluna ausente: ' . $e->getMessage());
}
expectGuard($pdoMissing->inTransaction() && $pdoMissing->ddl === [], 'Tentativa de ALTER implicito com coluna ausente.');
$pdoMissing->rollBack();

// Checklist opcional tambem nao pode criar tabelas dentro da transacao.
$pdoMissing->beginTransaction();
try {
    (new ImplementationChecklistService())->prepareStorageForWrite();
    throw new RuntimeException('A guarda do checklist nao bloqueou DDL.');
} catch (RuntimeException $e) {
    expectGuard(str_contains($e->getMessage(), 'migration'), 'Erro inesperado para checklist ausente: ' . $e->getMessage());
}
expectGuard($pdoMissing->inTransaction() && $pdoMissing->ddl === [], 'Checklist fechou uma transacao que nao era dele.');
$pdoMissing->rollBack();

echo "OK: preflight fora de BEGIN, sem DDL aninhado, guarda de CREATE/ALTER e transacao preservada.\n";
