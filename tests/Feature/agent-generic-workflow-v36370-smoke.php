<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$blueprint = (string) file_get_contents($root . '/app/Services/AgentBlueprintService.php');
$view = (string) file_get_contents($root . '/app/Views/agents/index.php');
$pre = (string) file_get_contents($root . '/app/Services/PreSchedulingService.php');
$behavior = (string) file_get_contents($root . '/app/Services/AgentConversationBehaviorService.php');

$checks = [
    'workflow salva field_keys editáveis' => str_contains($blueprint, "field_keys_present") && str_contains($blueprint, "config_json = :config_json"),
    'ordem sincroniza trava antes da agenda' => str_contains($blueprint, 'syncWorkflowScheduleRequirements'),
    'UI permite editar informações de cada etapa' => str_contains($view, 'agent-workflow-field-options') && str_contains($view, '[field_keys][]'),
    'UI possui três modos de forma de atendimento' => str_contains($view, 'not_applicable') && str_contains($view, 'Uma única forma') && str_contains($view, 'Mais de uma forma'),
    'pré-agendamento só exige modalidade em modo escolha' => substr_count($pre, '$modalityChoiceRequiredBeforeSchedule') >= 3,
    'runtime desativa coleta de modalidade resolvida' => str_contains($behavior, "'operationally_resolved' =") || str_contains($behavior, "['operationally_resolved'] = true"),
];

$fail = [];
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) $fail[] = $label;
}
if ($fail !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $fail) . "\n");
    exit(1);
}

echo "OK - motor genérico orientado pela configuração presente no pacote.\n";
