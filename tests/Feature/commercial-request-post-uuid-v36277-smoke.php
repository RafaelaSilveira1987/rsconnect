<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$controllerFile = $root . '/app/Controllers/ConversationController.php';
$publicIdFile = $root . '/app/Core/PublicId.php';
$isolationFile = $root . '/app/Services/TenantIsolationService.php';
$serviceFile = $root . '/app/Services/CommercialRequestService.php';
$viewFile = $root . '/app/Views/conversations/index.php';
$versionFile = $root . '/app/Services/AppVersionService.php';
$layoutFile = $root . '/app/Views/layouts/app.php';

foreach ([$controllerFile, $publicIdFile, $isolationFile, $serviceFile, $viewFile, $versionFile, $layoutFile] as $file) {
    if (!is_file($file)) {
        fwrite(STDERR, "FALHA: arquivo ausente: {$file}\n");
        exit(1);
    }
}

$controller = file_get_contents($controllerFile) ?: '';
$publicId = file_get_contents($publicIdFile) ?: '';
$isolation = file_get_contents($isolationFile) ?: '';
$service = file_get_contents($serviceFile) ?: '';
$view = file_get_contents($viewFile) ?: '';
$version = file_get_contents($versionFile) ?: '';
$layout = file_get_contents($layoutFile) ?: '';

$methodPos = strpos($controller, 'public function resolveCommercialRequest');
$method = $methodPos === false ? '' : substr($controller, $methodPos, 2200);

$checks = [
    'form envia commercial_request_uuid' => substr_count($view, 'name="commercial_request_uuid"') === 2,
    'controller lê o UUID comercial do POST' => str_contains($method, "\$_POST['commercial_request_uuid']"),
    'controller decodifica o UUID como commercial_request' => str_contains($method, "PublicId::decode('commercial_request', \$commercialRequestUuid)"),
    'controller mantém fallback numérico legado sem usar request_id LGPD' => str_contains($method, "\$_POST['commercial_request_id']") && !str_contains($method, "\$_POST['request_id']"),
    'PublicId mapeia commercial_request_uuid corretamente' => str_contains($publicId, "'commercial_request_id' => ['alias' => 'commercial_request_uuid', 'entity' => 'commercial_request']"),
    'aliases públicos também são expostos ao isolamento de POST' => str_contains($publicId, "\$entities[\$definition['alias']] = \$definition['entity'];"),
    'TenantIsolation valida commercial_request na tabela correta' => str_contains($isolation, "'commercial_request' => 'crm_commercial_requests'"),
    'service exige tenant + conversation + pending ao resolver' => str_contains($service, 'WHERE id = :id AND tenant_id = :tenant_id AND status = "pending"') && str_contains($service, 'AND conversation_id = :conversation_id'),
    'versão 36.27.7 identificada' => str_contains($version, 'RS Connect 36.27.7'),
    'cache visual acompanha 36.27.7' => str_contains($layout, 'app.css?v=36.27.7') && str_contains($layout, 'app.js?v=36.27.7'),
];

$failures = 0;
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[ERRO] ') . $label . PHP_EOL;
    if (!$ok) {
        $failures++;
    }
}

if ($failures > 0) {
    echo PHP_EOL . "FALHA - {$failures} verificação(ões) não passaram." . PHP_EOL;
    exit(1);
}

echo PHP_EOL . "OK - POST UUID das solicitações comerciais validado ponta a ponta no código." . PHP_EOL;
