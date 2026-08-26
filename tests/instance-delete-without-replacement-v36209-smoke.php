<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$controller = file_get_contents($root . '/app/Controllers/InstanceController.php') ?: '';
$view = file_get_contents($root . '/app/Views/instances/index.php') ?: '';
$javascript = file_get_contents($root . '/public/assets/js/app.js') ?: '';
$css = file_get_contents($root . '/public/assets/css/app.css') ?: '';
$layout = file_get_contents($root . '/app/Views/layouts/app.php') ?: '';
$version = file_get_contents($root . '/app/Services/AppVersionService.php') ?: '';
$instructions = file_get_contents($root . '/INSTRUCOES-v36.20.9.md') ?: '';

$checks = [
    'formulário oferece remoção sem substituta' => str_contains($view, 'name="discard_dependencies"')
        && str_contains($view, 'Não tenho outra conexão')
        && str_contains($view, 'name="acknowledge_discard"'),
    'backend aceita estratégia destrutiva explícita' => str_contains($controller, "isset(\$_POST['discard_dependencies'])")
        && str_contains($controller, "isset(\$_POST['acknowledge_discard'])")
        && str_contains($controller, 'discardInstanceDependencies'),
    'backend preserva cadastros reutilizáveis' => str_contains($controller, 'UPDATE ai_agents SET instance_id = NULL')
        && str_contains($controller, 'UPDATE contacts SET evolution_instance_id = NULL')
        && str_contains($controller, 'UPDATE scheduled_reports SET evolution_instance_id = NULL'),
    'backend remove dados dependentes' => str_contains($controller, 'DELETE FROM conversations WHERE evolution_instance_id')
        && str_contains($controller, 'DELETE FROM message_campaigns WHERE evolution_instance_id')
        && str_contains($controller, 'DELETE FROM ai_agent_instance_bindings WHERE instance_id'),
    'auditoria diferencia descarte local e assistido' => str_contains($controller, "'local_discard'")
        && str_contains($controller, "'assisted_discard'")
        && str_contains($controller, "'discard_stats'"),
    'interface exige confirmação extra' => str_contains($javascript, 'acknowledge_discard')
        && str_contains($javascript, 'Excluir cadastro e dados vinculados')
        && str_contains($javascript, 'Não há outra conexão disponível'),
    'estilo diferencia ação destrutiva' => str_contains($css, 'RS Connect 36.20.9')
        && str_contains($css, '.instance-delete-discard-option')
        && str_contains($css, '.is-local-discard'),
    'cache e versão atualizados' => str_contains($layout, 'app.css?v=36.20.9')
        && str_contains($layout, 'app.js?v=36.20.9')
        && str_contains($version, 'RS Connect 36.20.9'),
    'instruções acompanham o pacote' => str_contains($instructions, 'Não tenho outra conexão')
        && str_contains($instructions, 'Não há migration nova'),
];

$failed = [];
foreach ($checks as $label => $passed) {
    echo ($passed ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$passed) {
        $failed[] = $label;
    }
}

exit($failed ? 1 : 0);
