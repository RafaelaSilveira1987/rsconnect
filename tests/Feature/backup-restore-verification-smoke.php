<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$script = $root . '/scripts/verify-backup-restore.sh';
$content = is_file($script) ? (string) file_get_contents($script) : '';

$checks = [
    'script exists' => is_file($script),
    'uses official backup routine' => str_contains($content, 'rsconnect-backup.sh'),
    'temporary database prefix is guarded' => str_contains($content, 'rsconnect_restore_verify_'),
    'creates temporary database' => str_contains($content, 'CREATE DATABASE'),
    'restores gzip dump' => str_contains($content, 'gzip -cd "$BACKUP_PATH"'),
    'does not restore over production argument' => str_contains($content, 'sh "$TEMP_DB"'),
    'compares production and restored table counts' => str_contains($content, 'PROD_TABLE_COUNT') && str_contains($content, 'TEMP_TABLE_COUNT'),
    'compares critical row counts' => str_contains($content, 'CRITICAL_TABLES=(tenants users conversations messages evolution_instances subscriptions)'),
    'drops only guarded temporary database' => str_contains($content, 'DROP DATABASE IF EXISTS') && str_contains($content, '[[ "$TEMP_DB" == rsconnect_restore_verify_* ]]'),
    'writes approval evidence' => str_contains($content, 'backup_restore_verified') && str_contains($content, '[APROVADO]'),
];

$failed = [];
foreach ($checks as $label => $ok) {
    if (!$ok) {
        $failed[] = $label;
    }
}

if ($failed !== []) {
    fwrite(STDERR, "FAIL - " . implode('; ', $failed) . PHP_EOL);
    exit(1);
}

echo 'OK - verificador seguro de backup + restore validado (' . count($checks) . ' checks).' . PHP_EOL;
