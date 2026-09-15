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
$guide = $read('docs/HOMOLOGACAO-FINAL-v36.36.0.md');
$test = $read('TESTE_DA_VERSAO.md');
$layout = $read('app/Views/layouts/app.php');

$check(str_contains($version, "PACKAGE_LABEL = 'RS Connect 36.36.0 — Production Readiness: Homologação final'"), 'Pacote identifica a Fase E 36.36.0.');
$check(str_contains($version, "REQUIRED_MIGRATION = '116_sla_trigger_mysql_compat.sql'"), 'Fase E não adiciona migration.');
$check(str_contains($manifest, '"package_version": "36.36.0"') && str_contains($manifest, '"required_migration": "116_sla_trigger_mysql_compat.sql"'), 'Manifesto identifica a release candidate e preserva migration 116.');
$check(str_contains($preflight, 'HealthCheckService') && str_contains($preflight, 'schema_migrations'), 'Preflight valida readiness e migration obrigatória.');
$check(str_contains($preflight, 'tenant_sla_settings') && str_contains($preflight, 'lifecycle_status'), 'Preflight valida Go-Live e política de SLA.');
$check(str_contains($preflight, 'evolution_instances') && str_contains($preflight, 'identity_status') && str_contains($preflight, 'reconciliation_status'), 'Preflight valida conexão, identidade e reconciliação Evolution.');
$check(str_contains($preflight, 'webhook_security_events') && str_contains($preflight, "status='failed'"), 'Preflight verifica falhas recentes de webhook.');
$check(str_contains($preflight, "status IN ('open','pending')") && str_contains($preflight, 'assigned_user_id IS NULL'), 'Preflight resume carga operacional sem exigir filas.');
$check(str_contains($guide, 'E1 — Lead novo') && str_contains($guide, 'E12 — Go-Live / suspensão'), 'Matriz final cobre E1 a E12.');
$check(str_contains($guide, 'Evolution: idempotência') && str_contains($guide, 'Relatório e PDF') && str_contains($guide, 'Carga Operacional'), 'Matriz final inclui integrações, relatório e supervisão.');
$check(str_contains($test, 'preflight sem bloqueios') && str_contains($test, 'E1–E12'), 'Roteiro da versão define critério objetivo de aprovação.');
$check(str_contains($layout, 'app.css?v=36.36.0'), 'Cache-busting do frontend foi atualizado para 36.36.0.');

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} falha(s); {$passes} verificação(ões) aprovada(s).\n");
    exit(1);
}

echo "\nResumo homologação final 36.36.0: {$passes} verificações aprovadas.\n";
