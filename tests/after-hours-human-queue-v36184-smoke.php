<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$webhook = file_get_contents($root . '/app/Controllers/EvolutionWebhookController.php') ?: '';
$service = file_get_contents($root . '/app/Services/AiAfterHoursRecoveryService.php') ?: '';
$conversation = file_get_contents($root . '/app/Views/conversations/index.php') ?: '';
$operations = file_get_contents($root . '/app/Views/operations/ai_reprocess.php') ?: '';
$javascript = file_get_contents($root . '/public/assets/js/app.js') ?: '';
$layout = file_get_contents($root . '/app/Views/layouts/app.php') ?: '';
$version = file_get_contents($root . '/app/Services/AppVersionService.php') ?: '';

$checks = [
    'webhook registra fila humana' => str_contains($webhook, "'blocked_human'")
        && str_contains($webhook, 'AiAfterHoursRecoveryService')
        && str_contains($webhook, "\$attendanceMode !== 'ai'"),
    'serviço aceita status inicial humano' => str_contains($service, "string \$initialStatus = 'pending'")
        && str_contains($service, "['pending', 'blocked_human']")
        && str_contains($service, ':initial_status'),
    'caixa usa linguagem para equipe' => str_contains($conversation, 'Aguardando equipe')
        && str_contains($conversation, 'A pendência será encerrada após uma resposta humana.'),
    'central operacional usa linguagem humana' => str_contains($operations, 'Aguardando equipe')
        && str_contains($operations, 'sob responsabilidade da equipe humana'),
    'polling mantém o estado humano' => str_contains($javascript, "blocked_human: { label: 'Aguardando equipe'"),
    'versão e cache atualizados' => str_contains($version, 'RS Connect 36.18.6')
        && str_contains($layout, 'app.css?v=36.19.2')
        && str_contains($layout, 'app.js?v=36.19.2'),
];

$failed = [];
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) $failed[] = $label;
}

exit($failed === [] ? 0 : 1);
