<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$serviceFile = $root . '/app/Services/CrmDealValueService.php';
$crmAutoFile = $root . '/app/Services/CrmAutoService.php';
$aiAutomationFile = $root . '/app/Services/AiAutomationService.php';
$publicIdFile = $root . '/app/Core/PublicId.php';
$isolationFile = $root . '/app/Services/TenantIsolationService.php';
$conversationControllerFile = $root . '/app/Controllers/ConversationController.php';
$conversationViewFile = $root . '/app/Views/conversations/index.php';
$routesFile = $root . '/routes/web.php';
$versionFile = $root . '/app/Services/AppVersionService.php';
$layoutFile = $root . '/app/Views/layouts/app.php';

foreach ([$serviceFile, $crmAutoFile, $aiAutomationFile, $publicIdFile, $isolationFile, $conversationControllerFile, $conversationViewFile, $routesFile, $versionFile, $layoutFile] as $file) {
    if (!is_file($file)) {
        fwrite(STDERR, "FALHA: arquivo ausente: {$file}\n");
        exit(1);
    }
}

require_once $serviceFile;

$service = new App\Services\CrmDealValueService();
$single = $service->candidateFromText('O plano fica R$ 179 por mês.');
$ambiguous = $service->candidateFromText('Temos Inicial R$ 99, Profissional R$ 179 e Empresarial R$ 349.');
$selected = $service->candidateFromText('Gostei. Quero o de 129.');
$quantity = $service->candidateFromText('Hoje usamos 2 números de WhatsApp no atendimento.');
$brValue = $service->candidateFromText('Valor final R$ 1.234,56.');

$crmAuto = file_get_contents($crmAutoFile) ?: '';
$aiAutomation = file_get_contents($aiAutomationFile) ?: '';
$publicId = file_get_contents($publicIdFile) ?: '';
$isolation = file_get_contents($isolationFile) ?: '';
$controller = file_get_contents($conversationControllerFile) ?: '';
$view = file_get_contents($conversationViewFile) ?: '';
$routes = file_get_contents($routesFile) ?: '';
$version = file_get_contents($versionFile) ?: '';
$layout = file_get_contents($layoutFile) ?: '';

$checks = [
    'valor monetário único é identificado' => is_array($single) && abs((float) ($single['value'] ?? 0) - 179.0) < 0.001,
    'lista com múltiplos preços não escolhe valor arbitrário' => $ambiguous === null,
    'escolha explícita sem R$ é reconhecida' => is_array($selected) && abs((float) ($selected['value'] ?? 0) - 129.0) < 0.001 && !empty($selected['strong']),
    'quantidade de canais não vira valor comercial' => $quantity === null,
    'formato brasileiro com milhar e centavos é normalizado' => is_array($brValue) && abs((float) ($brValue['value'] ?? 0) - 1234.56) < 0.001,
    'entrada do cliente sincroniza valor no lead' => str_contains($crmAuto, 'CrmDealValueService())->captureForLead') && str_contains($crmAuto, "'customer'"),
    'resposta efetivamente enviada pela IA sincroniza valor' => str_contains($aiAutomation, 'CrmDealValueService())->captureFromConversation') && str_contains($aiAutomation, "'ai'"),
    'retry entregue também sincroniza valor' => substr_count($aiAutomation, 'CrmDealValueService())->captureFromConversation') >= 2,
    'commercial request possui tipo de PublicId próprio' => str_contains($publicId, "'commercial_request' => 35") && str_contains($publicId, "'commercial_request_id' => ['alias' => 'commercial_request_uuid', 'entity' => 'commercial_request']"),
    'isolamento valida solicitação comercial na tabela correta' => str_contains($isolation, "'commercial_request' => 'crm_commercial_requests'"),
    'aliases UUID de POST entram no isolamento tenant-aware' => str_contains($publicId, "\$entities[\$definition['alias']] = \$definition['entity'];"),
    'controller decodifica commercial_request_uuid no POST e mantém fallback legado' => str_contains($controller, "\$_POST['commercial_request_uuid']") && str_contains($controller, "PublicId::decode('commercial_request', \$commercialRequestUuid)") && str_contains($controller, "\$_POST['commercial_request_id']") && !str_contains(substr($controller, strpos($controller, 'public function resolveCommercialRequest'), 1400), "\$_POST['request_id']"),
    'formulários usam UUID comercial e preservam as duas ações' => substr_count($view, 'name="commercial_request_uuid"') === 2 && str_contains($view, 'decision" value="resolved"') && str_contains($view, 'decision" value="dismissed"'),
    'rota POST de resolução continua registrada' => str_contains($routes, "post('/conversations/commercial-request/resolve'"),
    'pacote identifica versão 36.27.7' => str_contains($version, 'RS Connect 36.27.7'),
    'cache visual acompanha versão atual' => str_contains($layout, 'app.css?v=36.27.9') && str_contains($layout, 'app.js?v=36.27.9'),
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

echo PHP_EOL . "OK - valor comercial automático e resolução segura de alertas validados." . PHP_EOL;
