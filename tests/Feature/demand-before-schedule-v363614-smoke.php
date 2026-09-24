<?php

declare(strict_types=1);


if (!function_exists('mb_substr')) {
    function mb_substr(string $text, int $start, ?int $length = null): string { return substr($text, $start, $length); }
}
if (!function_exists('mb_strlen')) {
    function mb_strlen(string $text): int { return strlen($text); }
}
if (!function_exists('mb_strtolower')) {
    function mb_strtolower(string $text): string { return strtolower($text); }
}

$root = dirname(__DIR__, 2);
require $root . '/app/Services/AgentConversationBehaviorService.php';

use App\Services\AgentConversationBehaviorService;

$flow = (string) file_get_contents($root . '/app/Services/ConversationFlowService.php');
$triage = (string) file_get_contents($root . '/app/Services/AgentTriageService.php');
$ai = (string) file_get_contents($root . '/app/Services/AiModelService.php');
$view = (string) file_get_contents($root . '/app/Views/agents/index.php');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');

$failures = [];
$passes = 0;
$check = static function (bool $ok, string $label) use (&$failures, &$passes): void {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if ($ok) {
        $passes++;
    } else {
        $failures[] = $label;
    }
};

$normalized = AgentConversationBehaviorService::normalizeConfiguration([
    'demand' => [
        'enabled' => 0,
        'required_before_schedule' => 1,
        'prompt' => 'Qual é a demanda?',
    ],
]);
$check(
    !empty($normalized['demand']['enabled']) && !empty($normalized['demand']['required_before_schedule']),
    'exigir demanda ativa automaticamente a coleta'
);

$check(
    str_contains($flow, '$behaviorDemandRequired = $this->behaviorRequiresDemandBeforeSchedule')
    && str_contains($flow, '$demandRequired = $behaviorDemandRequired || !empty($rule[\'require_demand_before_pre_schedule\'])'),
    'regra estruturada global tem precedência sobre a regra do grupo'
);

$check(
    str_contains($flow, '&& !$behaviorDemandRequired')
    && str_contains($flow, 'isAutomaticExistingCustomerDemandExemption'),
    'cliente/paciente só recebe dispensa automática quando a exigência global estiver desligada'
);

$check(
    str_contains($flow, "in_array(\$intent, ['schedule', 'reschedule'], true)")
    && str_contains($flow, "\$demandStatus === 'not_required'")
    && str_contains($flow, "\$demandStatus = 'pending';"),
    'estado not_required automático é reaberto ao receber agenda com demanda obrigatória'
);

$check(
    str_contains($triage, "str_starts_with(\$currentDemand, '[continuidade:'")
    && str_contains($triage, "unset(\$collected['brief_demand'])")
    && str_contains($triage, '&& !$behaviorDemandRequired'),
    'placeholder antigo não satisfaz artificialmente a demanda obrigatória'
);

$check(
    str_contains($ai, 'Se a regra estruturada exigir demanda antes da agenda')
    && !str_contains($ai, 'NÃO peça motivo do atendimento, principal queixa ou nova qualificação como pré-condição para responder uma dúvida, consultar agenda, marcar ou remarcar horário'),
    'prompt da IA respeita a exigência estruturada'
);

$check(
    str_contains($view, 'Quando “Exigir a demanda” estiver marcado, a agenda só é consultada depois que a demanda estiver registrada nesta conversa.'),
    'interface explica a precedência da regra de demanda'
);

$check(
    str_contains($version, 'RS Connect 36.36.14 — Demanda obrigatória antes da agenda'),
    'pacote identificado como 36.36.14'
);

if ($failures !== []) {
    fwrite(STDERR, "\nFALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "\nResumo: {$passes} verificações aprovadas.\n";
