<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$viewFile = $root . '/app/Views/agents/index.php';
$controllerFile = $root . '/app/Controllers/AgentController.php';
$serviceFile = $root . '/app/Services/AgentRoutingService.php';
$jsFile = $root . '/public/assets/js/app.js';
$cssFile = $root . '/public/assets/css/app.css';
$versionFile = $root . '/app/Services/AppVersionService.php';
$layoutFile = $root . '/app/Views/layouts/app.php';

foreach ([$viewFile, $controllerFile, $serviceFile, $jsFile, $cssFile, $versionFile, $layoutFile] as $file) {
    if (!is_file($file)) {
        fwrite(STDERR, "FALHA: arquivo ausente: {$file}\n");
        exit(1);
    }
}

$view = (string) file_get_contents($viewFile);
$controller = (string) file_get_contents($controllerFile);
$service = (string) file_get_contents($serviceFile);
$js = (string) file_get_contents($jsFile);
$css = (string) file_get_contents($cssFile);
$version = (string) file_get_contents($versionFile);
$layout = (string) file_get_contents($layoutFile);

$checks = [
    'tela exibe guia de multiagente' => str_contains($view, 'Defina o papel de cada assistente no próprio canal'),
    'tela oferece os três papéis' => str_contains($view, 'Principal / recepção')
        && str_contains($view, 'Especialista por assunto')
        && str_contains($view, 'Distribuição automática'),
    'tela permite palavras de direcionamento' => str_contains($view, 'Intenções / palavras de direcionamento')
        && str_contains($view, 'routing_keywords['),
    'cards mostram resumo de roteamento' => str_contains($view, 'Roteamento multiagente')
        && str_contains($view, 'agent-routing-badge'),
    'ação rápida de configuração está visível' => str_contains($view, 'Configurar multiagente')
        && str_contains($view, 'agent-routing-jump'),
    'criação também permite escolher papel' => substr_count($view, 'name="routing_mode"') >= 2
        && substr_count($view, 'name="routing_keywords"') >= 2,
    'backend persiste modo e keywords por canal' => str_contains($controller, 'routingModesFromPost(')
        && str_contains($controller, 'routingKeywordsFromPost(')
        && str_contains($controller, 'routing_keywords = VALUES(routing_keywords)'),
    'backend exige intenção para especialista' => str_contains($controller, 'Informe ao menos uma intenção ou palavra para o assistente especialista.'),
    'principal limpa especialista e especialista não vira genérico' => str_contains($controller, "\$mode === 'specialist'")
        && str_contains($service, 'private function genericBindings('),
    'interface ativa campo conforme papel' => str_contains($js, 'data-routing-mode')
        && str_contains($js, 'data-routing-keywords')
        && str_contains($js, "mode === 'specialist'"),
    'estilo responsivo do roteamento existe' => str_contains($css, 'RS Connect 36.27.2 — configuração visual de multiagentes')
        && str_contains($css, '.agent-routing-guide')
        && str_contains($css, '.agent-channel-routing-config'),
    'pacote identifica versão 36.27.4' => str_contains($version, 'RS Connect 36.27.4')
        && str_contains($version, 'Identificação do agente no WhatsApp'),
    'cache de CSS e JS foi renovado' => str_contains($layout, 'app.css?v=36.27.4')
        && str_contains($layout, 'app.js?v=36.27.4'),
];

$failures = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FALHA] ') . $label . "\n";
}

if ($failures !== []) {
    fwrite(STDERR, "\nFALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "\nOK - configuração visual e persistência do multiagente validadas.\n";
