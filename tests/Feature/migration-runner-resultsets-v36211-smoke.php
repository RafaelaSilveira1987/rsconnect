<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$service = (string) file_get_contents($root . '/app/Services/MigrationService.php');
$migration = (string) file_get_contents($root . '/database/migrations/090_crm_conversation_automation.sql');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');

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

$check(str_contains($service, '$this->db()->query($statement)'), 'runner mantém um cursor acessível para cada instrução SQL');
$check(str_contains($service, '$cursor->fetchAll()'), 'runner consome resultados retornados por SQL dinâmico');
$check(str_contains($service, '$cursor->nextRowset()'), 'runner drena todos os result sets da instrução');
$check(str_contains($service, '$cursor->closeCursor()'), 'runner fecha o cursor antes da próxima consulta');
$check(!str_contains($migration, "'SELECT 1'") && substr_count($migration, "'DO 0'") === 3, 'migration 090 usa no-op sem conjunto de resultados');
$check(str_contains($version, 'RS Connect 36.21.1'), 'pacote identifica o hotfix v36.21.1');

if ($failures !== []) {
    fwrite(STDERR, "\nFALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "\nResumo v36.21.1: {$passes} verificações aprovadas.\n";
