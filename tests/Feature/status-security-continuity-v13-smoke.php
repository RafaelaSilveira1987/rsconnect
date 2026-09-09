<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');
$status = (string) file_get_contents($root . '/app/Views/docs/status.php');
$flow = (string) file_get_contents($root . '/app/Services/ConversationFlowService.php');
$agents = (string) file_get_contents($root . '/app/Views/agents/index.php');
$migration = (string) file_get_contents($root . '/database/migrations/104_customer_patient_continuity_guard.sql');
$manifest = (string) file_get_contents($root . '/database/migrations/manifest.php');

$checks = [
    'segredos não usam máscara parcial' => !str_contains($version, 'private function masked(')
        && str_contains($version, "'value' => \$configured ? 'Configurado' : 'Não configurado'"),
    'painel explica proteção dos valores' => str_contains($status, 'chaves, senhas e tokens nunca são exibidos'),
    'linguagem operacional no ambiente' => str_contains($version, 'Chave principal da IA')
        && str_contains($version, 'Rotina automática de cobrança')
        && str_contains($version, 'Rotina automática da fila da IA'),
    'cliente e paciente são protegidos no backend' => str_contains($flow, "in_array(\$group, ['customer', 'patient'], true)")
        && str_contains($flow, "'require_demand' =>"),
    'paciente atual não exige demanda na interface' => str_contains($agents, "'patient' => ['allow' => 1, 'require' => 0")
        && str_contains($agents, 'Triagem já conhecida'),
    'migration 104 corrige regras históricas' => str_contains($migration, "contact_group IN ('customer', 'patient')")
        && str_contains($migration, "fs.demand_status = 'pending' THEN 'not_required'"),
    'manifest inclui migration 104' => str_contains($manifest, "'sequence' => 111, 'file' => '104_customer_patient_continuity_guard.sql'"),
    'status não manda reexecutar migration 050' => !str_contains($version, "Executar database/migrations/050_human_takeover_customer_context.sql."),
    'versão atualizada' => str_contains($version, 'RS Connect 36.28.2')
        && str_contains($version, "REQUIRED_MIGRATION = '104_customer_patient_continuity_guard.sql'"),
];

$failed = [];
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FALHA] ') . $label . PHP_EOL;
    if (!$ok) $failed[] = $label;
}
if ($failed !== []) {
    fwrite(STDERR, "\nFALHAS:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "\nOK - segurança do painel e continuidade de clientes/pacientes validadas.\n";
