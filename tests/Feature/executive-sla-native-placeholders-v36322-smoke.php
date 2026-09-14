<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$policy = (string) file_get_contents($root . '/app/Services/ExecutiveMetricsPolicyService.php');
$database = (string) file_get_contents($root . '/app/Core/Database.php');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');
$manifest = (string) file_get_contents($root . '/manifest.json');
$guide = (string) file_get_contents($root . '/TESTE_DA_VERSAO.md');

$passes = 0;
$failures = 0;
$check = static function (bool $ok, string $message) use (&$passes, &$failures): void {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $message . PHP_EOL;
    $ok ? $passes++ : $failures++;
};

$check(
    str_contains($database, 'PDO::ATTR_EMULATE_PREPARES => false'),
    'Banco usa PDO MySQL com prepared statements nativos.'
);

$start = strpos($policy, '$sla = $this->pdo->prepare(');
$end = $start !== false ? strpos($policy, '$sla->execute($slaParams);', $start) : false;
$slaBlock = ($start !== false && $end !== false) ? substr($policy, $start, $end - $start) : '';

preg_match_all('/(?<!:):([A-Za-z_][A-Za-z0-9_]*)/', $slaBlock, $matches);
$names = $matches[1] ?? [];
$duplicates = array_filter(array_count_values($names), static fn (int $count): bool => $count > 1);

$check($slaBlock !== '', 'Bloco SQL do SLA foi localizado.');
$check($duplicates === [], 'Query do SLA não reutiliza placeholders nomeados em PDO nativo.');
$check(
    str_contains($slaBlock, ':sla_met_seconds')
    && str_contains($slaBlock, ':sla_breached_seconds')
    && !str_contains($slaBlock, ':sla_seconds'),
    'Limites de SLA usam placeholders exclusivos.'
);
$check(
    str_contains($policy, "'sla_met_seconds' => \$slaSeconds")
    && str_contains($policy, "'sla_breached_seconds' => \$slaSeconds"),
    'Parâmetros exclusivos são enviados com a mesma meta configurada.'
);
$check(
    str_contains($policy, '[reports.executive.service-cycle.closed]')
    && str_contains($policy, '[reports.executive.service-cycle.sla]')
    && str_contains($policy, '[reports.executive.service-cycle.waiting]'),
    'Duração, SLA e espera possuem falhas isoladas e logs próprios.'
);
$check(
    str_contains($version, "PACKAGE_LABEL = 'RS Connect 36.32.2 — Hotfix do cálculo de SLA'")
    && str_contains($version, "REQUIRED_MIGRATION = '113_human_first_response_report_consistency.sql'"),
    'Versão 36.32.2 mantém a migration 113 como requisito.'
);
$check(
    str_contains($manifest, '"package_version": "36.32.2"')
    && str_contains($manifest, '"required_migration": "113_human_first_response_report_consistency.sql"'),
    'Manifesto identifica o hotfix sem migration nova.'
);
$check(
    str_contains($guide, '1/1 em até 30 min')
    && str_contains($guide, 'HY093'),
    'Roteiro cobre o cenário real e o erro PDO diagnosticado.'
);

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} falha(s); {$passes} verificação(ões) aprovada(s).\n");
    exit(1);
}

echo "\nResumo hotfix SLA 36.32.2: {$passes} verificações aprovadas.\n";
