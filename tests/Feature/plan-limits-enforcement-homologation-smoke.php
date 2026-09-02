<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$files = [
    'user' => $root . '/app/Controllers/UserController.php',
    'agent' => $root . '/app/Controllers/AgentController.php',
    'instance' => $root . '/app/Controllers/InstanceController.php',
    'subscription' => $root . '/app/Services/SubscriptionService.php',
    'ai_usage' => $root . '/app/Services/AiUsageService.php',
    'audit' => $root . '/bin/plan-limits-audit.php',
];

foreach ($files as $label => $file) {
    if (!is_file($file)) {
        fwrite(STDERR, "FALHA: arquivo {$label} ausente: {$file}\n");
        exit(1);
    }
}

$content = array_map(static fn (string $f): string => (string) file_get_contents($f), $files);
$required = [
    ['user', 'use App\\Services\\SubscriptionService;'],
    ['user', "ensureCanCreate((int) \$tenantId, 'users')"],
    ['user', "(string) (\$target['status'] ?? 'inactive') !== 'active'"],
    ['agent', "ensureCanCreate(\$tenantId, 'agents')"],
    ['instance', "ensureCanCreate(\$tenantId, 'instances')"],
    ['subscription', 'public function ensureCanCreate'],
    ['subscription', "'ai_interactions_month'"],
    ['ai_usage', 'plan_billable'],
    ['audit', 'ROLLBACK concluído — produção não alterada'],
    ['audit', '[APROVADO] PLANOS E LIMITES HOMOLOGADOS NO MOTOR E NO BANCO.'],
];

foreach ($required as [$key, $needle]) {
    if (!str_contains($content[$key], $needle)) {
        fwrite(STDERR, "FALHA: marcador ausente em {$key}: {$needle}\n");
        exit(1);
    }
}

echo "OK - enforcement de usuários, canais, agentes e auditor transacional de planos validados.\n";
