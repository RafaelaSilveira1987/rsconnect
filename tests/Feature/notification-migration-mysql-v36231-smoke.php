<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$sql = (string) file_get_contents($root . '/database/migrations/092_notification_orchestration.sql');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');
$failures = [];
$check = static function (bool $ok, string $label) use (&$failures): void {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) $failures[] = $label;
};

$check(str_contains($sql, ") e\nWHERE 1 = 1\nON DUPLICATE KEY UPDATE"), 'migration elimina ambiguidade INSERT SELECT ON DUPLICATE');
$check(substr_count($sql, 'CREATE TABLE IF NOT EXISTS') >= 2, 'migration continua segura após criação parcial das tabelas');
$check(str_contains($version, 'RS Connect 36.23.1'), 'versão registra o hotfix 36.23.1');

if ($failures !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK - compatibilidade MySQL da migration 092 validada.\n";
