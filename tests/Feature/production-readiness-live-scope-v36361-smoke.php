<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static fn (string $path): string => is_file($root . '/' . $path) ? (string) file_get_contents($root . '/' . $path) : '';
$passes = 0;
$failures = 0;
$check = static function (bool $ok, string $message) use (&$passes, &$failures): void {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $message . PHP_EOL;
    $ok ? $passes++ : $failures++;
};

$version = $read('app/Services/AppVersionService.php');
$manifest = $read('manifest.json');
$preflight = $read('bin/production-readiness.php');
$test = $read('TESTE_DA_VERSAO.md');

$check(str_contains($version, "PACKAGE_LABEL = 'RS Connect 36.36.1 — Hotfix do Production Readiness'"), 'Pacote identifica o hotfix 36.36.1.');
$check(str_contains($manifest, '"package_version": "36.36.1"') && str_contains($manifest, '"required_migration": "116_sla_trigger_mysql_compat.sql"'), 'Manifesto identifica 36.36.1 sem migration nova.');
$check(str_contains($preflight, "t.lifecycle_status='live'") && str_contains($preflight, 'Evolution / WhatsApp LIVE'), 'Evolution bloqueante está limitada a tenants LIVE.');
$check(str_contains($preflight, "t.lifecycle_status <> 'live'") && str_contains($preflight, 'Evolution fora de produção'), 'Evolution não LIVE aparece separadamente como informação.');
$check(str_contains($preflight, 'Carga operacional LIVE') && str_contains($preflight, 'Carga fora de produção'), 'Carga operacional separa produção e ambientes não LIVE.');
$check(str_contains($preflight, "\$push('INFO'"), 'Preflight suporta linhas informativas sem gerar atenção ou bloqueio.');
$check(str_contains($test, '1` receptora, `0` pendentes') && str_contains($test, 'PRONTO PARA HOMOLOGAÇÃO FINAL'), 'Roteiro define o resultado esperado do cenário homologado.');

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} falha(s); {$passes} verificação(ões) aprovada(s).\n");
    exit(1);
}

echo "\nResumo Production Readiness 36.36.1: {$passes} verificações aprovadas.\n";
