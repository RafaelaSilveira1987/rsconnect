<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$passes = 0;
$failures = [];
$check = static function (bool $condition, string $label) use (&$passes, &$failures): void {
    if ($condition) {
        $passes++;
        echo "[OK] {$label}\n";
        return;
    }
    $failures[] = $label;
    echo "[FAIL] {$label}\n";
};

$service = file_get_contents($root . '/app/Services/OnboardingGuideService.php') ?: '';
$migration = file_get_contents($root . '/database/migrations/007_ai_commercial_rules.sql') ?: '';
$agent = file_get_contents($root . '/app/Controllers/AgentController.php') ?: '';
$version = file_get_contents($root . '/app/Services/AppVersionService.php') ?: '';
$manifest = file_get_contents($root . '/manifest.json') ?: '';

$check(!str_contains($service, 'handoff_action = "pause_ai"'), 'Onboarding não grava mais o valor legado pause_ai.');
$check(str_contains($service, 'handoff_action = "paused"'), 'Onboarding sincroniza handoff_action com paused.');
$check(str_contains($migration, 'ENUM("paused","human")'), 'Schema histórico mantém os valores válidos paused/human.');
$check(str_contains($agent, "['paused', 'human']"), 'Controller de agentes valida os mesmos valores do ENUM.');
$check(str_contains($version, "PACKAGE_LABEL = 'RS Connect 36.34.1 — Hotfix do salvamento das regras de atendimento'"), 'Versão do hotfix está atualizada.');
$check(str_contains($version, "REQUIRED_MIGRATION = '115_sla_operational_policy.sql'"), 'Hotfix não exige migration nova.');
$check(str_contains($manifest, '"package_version": "36.34.1"'), 'Manifest identifica a 36.34.1.');

if ($failures !== []) {
    fwrite(STDERR, "\nFalhas: " . implode('; ', $failures) . "\n");
    exit(1);
}

echo "\nResumo hotfix handoff 36.34.1: {$passes} verificações aprovadas.\n";
