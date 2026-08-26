<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$controller = file_get_contents($root . '/app/Controllers/InstanceController.php') ?: '';
$view = file_get_contents($root . '/app/Views/instances/index.php') ?: '';
$javascript = file_get_contents($root . '/public/assets/js/app.js') ?: '';
$css = file_get_contents($root . '/public/assets/css/app.css') ?: '';
$layout = file_get_contents($root . '/app/Views/layouts/app.php') ?: '';
$version = file_get_contents($root . '/app/Services/AppVersionService.php') ?: '';
$instructions = file_get_contents($root . '/INSTRUCOES-v36.20.7.md') ?: '';

$checks = [
    'prévia informa o modo de exclusão' => str_contains($controller, "'deletion_mode' => \$remoteMissing")
        && str_contains($controller, "'local_transfer'")
        && str_contains($controller, "'local_only'"),
    'auditoria registra o modo executado' => str_contains($controller, "'deletion_mode' => \$remoteAlreadyMissing"),
    'mensagem final diferencia exclusão local' => str_contains($controller, 'Cadastro local excluído do RS Connect')
        && str_contains($controller, 'Dados transferidos com segurança'),
    'modal possui elementos de apresentação dinâmica' => str_contains($view, 'data-instance-delete-title')
        && str_contains($view, 'data-instance-delete-destination-section')
        && str_contains($view, 'data-instance-delete-dependency-title'),
    'interface separa exclusão local e transferência' => str_contains($javascript, "'local-transfer'")
        && str_contains($javascript, "'local-only'")
        && str_contains($javascript, 'Transferir dados e excluir cadastro')
        && str_contains($javascript, 'Excluir cadastro do RS Connect'),
    'destino some somente quando não há vínculos' => (str_contains($javascript, 'deleteDestinationSection.hidden = !needsReplacement') || str_contains($javascript, 'deleteDestinationSection.hidden = !hasDependencies')),
    'opção externa continua oculta quando ausente' => str_contains($javascript, 'deleteRemoteRow.hidden = true')
        && str_contains($javascript, 'deleteRemote.disabled = true'),
    'estilo respeita o atributo hidden' => str_contains($css, 'RS Connect 36.20.7')
        && str_contains($css, '[data-instance-delete-destination-section][hidden]'),
    'versão e cache foram atualizados' => str_contains($version, 'RS Connect 36.20.7')
        && str_contains($layout, 'app.css?v=36.20.7')
        && str_contains($layout, 'app.js?v=36.20.7'),
    'instruções de implantação acompanham o pacote' => str_contains($instructions, 'Transferir dados e excluir cadastro')
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
