<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$controller = file_get_contents($root . '/app/Controllers/ConversationController.php') ?: '';
$conversationView = file_get_contents($root . '/app/Views/conversations/index.php') ?: '';
$operationsView = file_get_contents($root . '/app/Views/operations/ai_reprocess.php') ?: '';
$service = file_get_contents($root . '/app/Services/AiAfterHoursRecoveryService.php') ?: '';
$javascript = file_get_contents($root . '/public/assets/js/app.js') ?: '';
$css = file_get_contents($root . '/public/assets/css/app.css') ?: '';
$layout = file_get_contents($root . '/app/Views/layouts/app.php') ?: '';
$version = file_get_contents($root . '/app/Services/AppVersionService.php') ?: '';

$checks = [
    'filtro dedicado da fila' => str_contains($controller, "'queue' => trim")
        && str_contains($controller, "ai_after_hours_pending ah_filter")
        && str_contains($conversationView, 'Aguardando horário'),
    'lista recebe estado e quantidade' => str_contains($controller, 'after_hours_message_count')
        && str_contains($controller, "'after_hours' => \$this->formatAfterHoursForJson")
        && str_contains($conversationView, 'data-after-hours-list-slot'),
    'banner detalhado na conversa' => str_contains($conversationView, 'after-hours-queue-banner')
        && str_contains($conversationView, 'Retomada prevista')
        && str_contains($conversationView, 'Assumir e retirar da fila'),
    'polling mantém estado da fila' => str_contains($javascript, 'afterHoursListMarkup')
        && str_contains($javascript, "row?.classList.toggle('has-after-hours-queue'")
        && str_contains($javascript, 'data-after-hours-queue-count'),
    'central operacional usa cards' => str_contains($operationsView, 'after-hours-operation-card')
        && str_contains($operationsView, 'Primeira mensagem')
        && str_contains($operationsView, 'Aviso de ausência'),
    'serviço calcula próxima abertura' => str_contains($service, 'business_hours_json')
        && str_contains($service, 'nextOpeningAt')
        && str_contains($service, "AS message_count"),
    'estilos responsivos incluídos' => str_contains($css, 'RS Connect 36.18.6')
        && str_contains($css, '.after-hours-queue-banner')
        && str_contains($css, '.after-hours-operation-card'),
    'versão e assets atualizados' => str_contains($version, 'RS Connect 36.18.6')
        && str_contains($layout, 'app.css?v=36.18.6')
        && str_contains($layout, 'app.js?v=36.18.6'),
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

echo "OK - fila fora do horário exibida com estado, quantidade, previsão e ações.\n";
