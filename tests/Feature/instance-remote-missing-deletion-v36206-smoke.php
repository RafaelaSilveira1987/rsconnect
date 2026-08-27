<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$controller = file_get_contents($root . '/app/Controllers/InstanceController.php') ?: '';
$view = file_get_contents($root . '/app/Views/instances/index.php') ?: '';
$javascript = file_get_contents($root . '/public/assets/js/app.js') ?: '';
$css = file_get_contents($root . '/public/assets/css/app.css') ?: '';
$layout = file_get_contents($root . '/app/Views/layouts/app.php') ?: '';
$version = file_get_contents($root . '/app/Services/AppVersionService.php') ?: '';
$instructions = file_get_contents($root . '/INSTRUCOES-v36.20.6.md') ?: '';

$checks = [
    'prévia consulta situação externa real' => str_contains($controller, 'inspectRemoteInstance($source, 12)')
        && str_contains($controller, '\'remote\' => $remote'),
    'conexão externa ausente é reconhecida' => str_contains($controller, 'isRemoteInstanceMissingException')
        && str_contains($controller, "'exists' => false")
        && str_contains($controller, 'remoteAlreadyMissing'),
    'exclusão remota é idempotente' => str_contains($controller, 'if ($this->isRemoteInstanceMissingException($remoteException))')
        && str_contains($controller, "'remote_already_missing'"),
    'interface explica situação externa' => str_contains($view, 'data-instance-delete-remote-state')
        && str_contains($view, 'Excluir também do serviço do WhatsApp'),
    'opção externa some quando não existe' => str_contains($javascript, 'remote.exists === false')
        && str_contains($javascript, 'deleteRemoteRow.hidden = true')
        && str_contains($javascript, "'Remover do RS Connect'"),
    'confirmação usa presença real' => str_contains($javascript, 'deletePreviewState?.requires_remote_ack')
        && str_contains($javascript, 'fallbackConnectedWithoutRemoteDelete'),
    'estilos do estado externo existem' => str_contains($css, 'RS Connect 36.20.6')
        && str_contains($css, '.instance-delete-remote-state.is-success'),
    'versão e cache atualizados' => str_contains($version, 'RS Connect 36.20.6')
        && str_contains($layout, 'app.css?v=36.20.6')
        && str_contains($layout, 'app.js?v=36.20.6'),
    'documentação acompanha a correção' => str_contains($instructions, 'conexão era removida diretamente'),
];

$failed = [];
foreach ($checks as $label => $passed) {
    echo ($passed ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$passed) $failed[] = $label;
}

exit($failed ? 1 : 0);
