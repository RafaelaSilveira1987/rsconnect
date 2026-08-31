<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/app/Core/Autoloader.php';
App\Core\Autoloader::register($root . '/app');

use App\Services\MigrationService;
use App\Services\SqlScriptParser;

$failures = [];
$passes = 0;
$check = static function (bool $condition, string $label) use (&$failures, &$passes): void {
    if ($condition) {
        echo "[OK] {$label}\n";
        $passes++;
        return;
    }
    echo "[FAIL] {$label}\n";
    $failures[] = $label;
};

$service = new MigrationService(null, $root);
$result = $service->verifyOffline();
$manifest = require $root . '/database/migrations/manifest.php';
$compose = (string) file_get_contents($root . '/docker-compose.yml');
$dockerfile = (string) file_get_contents($root . '/Dockerfile');
$schema = (string) file_get_contents($root . '/database/schema.sql');
$registry = (string) file_get_contents($root . '/database/migrations/089_schema_migrations_registry.sql');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');
$runner = (string) file_get_contents($root . '/bin/migrate.php');
$operations = (string) file_get_contents($root . '/app/Services/OperationsService.php');
$health = (string) file_get_contents($root . '/app/Services/HealthCheckService.php');
$migrationService = (string) file_get_contents($root . '/app/Services/MigrationService.php');

$check(($result['files'] ?? 0) === 101, 'manifesto possui 101 migrations de subida');
$check(($result['rollbacks'] ?? []) === ['030_google_calendar_availability_modes_rollback.sql'], 'rollback fica isolado do fluxo de subida');
$check(isset($result['duplicate_numbers']['017'], $result['duplicate_numbers']['063']), 'numerações históricas duplicadas são inventariadas');
$check(($manifest['schema_snapshot']['through'] ?? '') === '004_crm.sql', 'snapshot declara o baseline executável até a migration 004');
$check(str_contains($registry, 'CREATE TABLE IF NOT EXISTS schema_migrations') && str_contains($registry, 'UNIQUE KEY uq_schema_migrations_migration'), 'registro de migrations é idempotente e único');
$check(str_contains($runner, "baseline --through=088 --yes") && str_contains($runner, "install --yes") && str_contains($runner, "up [--dry-run]") && str_contains($runner, "seed --yes"), 'CLI oferece install, baseline, status e execução incremental');
$check(str_contains($dockerfile, 'php /var/www/html/bin/migrate.php verify'), 'build Docker valida o manifesto sem acessar o banco');
$check(str_contains($compose, 'service_completed_successfully') && str_contains($compose, 'bin/migrate.php", "bootstrap"'), 'Docker aguarda o bootstrap do banco antes da aplicação');
$check(!str_contains($compose, '/docker-entrypoint-initdb.d/') && str_contains($compose, 'bin/migrate.php", "bootstrap"'), 'Docker delega toda instalação ao runner canônico');
$check(str_contains($schema, 'SCHEMA_EXECUTION_BASELINE_THROUGH: 004_crm.sql'), 'schema declara o baseline e deixa evoluções para o runner');
$bootstrapPos = strpos($migrationService, "executeSqlFile(\$bootstrapSeed, 'seed.sql')");
$migrationPos = is_int($bootstrapPos) ? strpos($migrationService, '$elapsed = $this->executeMigration($file)', $bootstrapPos) : false;
$finalSeedPos = is_int($migrationPos) ? strpos($migrationService, "executeSqlFile(\$seed, 'seed.reference.sql')", $migrationPos) : false;
$check(is_int($bootstrapPos) && is_int($migrationPos) && is_int($finalSeedPos) && $bootstrapPos < $migrationPos && $migrationPos < $finalSeedPos, 'instalação executa seed inicial, migrations e reconciliação final na ordem correta');
$check(str_contains($operations, 'schema_migrations') && str_contains($operations, 'checksum divergente'), 'monitoramento operacional usa o registro canônico');
$check(str_contains($health, "'name' => 'migrations'") && str_contains($health, '089_schema_migrations_registry.sql') && str_contains($health, '090_crm_conversation_automation.sql'), 'readiness bloqueia tráfego quando o schema está pendente');
$check(str_contains($version, 'RS Connect 36.20.16') && str_contains($version, 'RS Connect 36.21.0') && str_contains($version, '090_crm_conversation_automation.sql') && str_contains($version, '091_after_hours_monitor_and_quote_requests.sql'), 'versão e migrations obrigatórias foram atualizadas');

$sample = <<<'SQL'
CREATE TABLE test_parser (id INT);
DELIMITER $$
CREATE PROCEDURE test_proc()
BEGIN
    SELECT 'texto;interno';
    SELECT 2;
END$$
DELIMITER ;
INSERT INTO test_parser (id) VALUES (1);
SQL;
$parsed = SqlScriptParser::parse($sample);
$check(count($parsed) === 3 && str_contains($parsed[1], 'CREATE PROCEDURE') && str_contains($parsed[1], "texto;interno"), 'parser preserva rotinas e delimitadores internos');

if ($failures !== []) {
    fwrite(STDERR, "\nFALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "\nResumo ENT-027: {$passes} verificações aprovadas.\n";
