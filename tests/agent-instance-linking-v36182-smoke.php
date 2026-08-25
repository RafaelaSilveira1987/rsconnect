<?php

declare(strict_types=1);

$failures = [];
$assert = static function (bool $condition, string $label) use (&$failures): void {
    if (!$condition) {
        $failures[] = $label;
    }
};

$agentController = (string) file_get_contents(__DIR__ . '/../app/Controllers/AgentController.php');
$instanceController = (string) file_get_contents(__DIR__ . '/../app/Controllers/InstanceController.php');
$agentView = (string) file_get_contents(__DIR__ . '/../app/Views/agents/index.php');
$css = (string) file_get_contents(__DIR__ . '/../public/assets/css/app.css');
$js = (string) file_get_contents(__DIR__ . '/../public/assets/js/app.js');
$layout = (string) file_get_contents(__DIR__ . '/../app/Views/layouts/app.php');

$assert(str_contains($agentView, 'name="instance_ids[]"'), 'edição do assistente deve permitir selecionar canais');
$assert(str_contains($agentView, 'name="primary_instance_ids[]"'), 'edição deve permitir marcar principal por canal');
$assert(str_contains($agentView, 'name="channels_present"'), 'formulário deve sinalizar presença da nova seleção para preservar compatibilidade');
$assert(str_contains($agentView, 'Onde este assistente deve atuar?'), 'interface deve explicar o vínculo de canais');
$assert(str_contains($agentController, 'syncAgentChannels('), 'controller do assistente deve sincronizar vínculos');
$assert(str_contains($agentController, 'assertInstancesBelongToTenant'), 'controller deve validar isolamento por empresa');
$assert(str_contains($agentController, 'UPDATE ai_agents SET instance_id = :instance_id'), 'vínculo legado deve ser mantido');
$assert(str_contains($instanceController, 'autoLinkAgentToNewInstance'), 'criação de instância deve tentar vínculo automático');
$assert(str_contains($instanceController, 'count($agents) === 1'), 'um único assistente ativo deve ser vinculado automaticamente');
$assert(str_contains($instanceController, 'count($defaults) === 1'), 'fallback geral único deve ser vinculado automaticamente');
$assert(str_contains($css, 'agent-channel-selection-grid'), 'layout dos canais deve possuir estilos próprios');
$assert(str_contains($js, 'primary_instance_ids[]') && str_contains($js, 'instance_ids[]'), 'editor deve manter principal e vínculo coerentes');
$assert(str_contains($layout, 'app.css?v=36.18.5'), 'cache busting do CSS deve usar 36.18.5');
$assert(str_contains($layout, 'app.js?v=36.18.5'), 'cache busting do JS deve usar 36.18.5');

if ($failures !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK - vínculo automático e edição manual de canais por assistente validados.\n";
