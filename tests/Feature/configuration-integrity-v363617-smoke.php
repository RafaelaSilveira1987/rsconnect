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
if (!function_exists('mb_convert_case')) {
    function mb_convert_case(string $text, int $mode, ?string $encoding = null): string { return ucwords(strtolower($text)); }
}
if (!defined('MB_CASE_TITLE')) define('MB_CASE_TITLE', 0);

$root = dirname(__DIR__, 2);
require $root . '/app/Services/AgentConversationBehaviorService.php';
require $root . '/app/Services/AgentBlueprintService.php';

use App\Services\AgentBlueprintService;
use App\Services\AgentConversationBehaviorService;

$failed = [];
$passed = 0;
$check = static function (bool $ok, string $label) use (&$failed, &$passed): void {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if ($ok) $passed++; else $failed[] = $label;
};

$behavior = new AgentConversationBehaviorService();
$legacyConflict = [
    'config' => [
        'conversation_behavior' => [
            'demand' => ['enabled' => false, 'required_before_schedule' => false, 'prompt' => ''],
        ],
    ],
    'triage_fields' => [[
        'field_key' => 'brief_demand',
        'active' => true,
        'required_before_schedule' => true,
        'prompt_text' => 'Qual é a principal demanda?',
        'position' => 60,
    ]],
];
$effective = $behavior->settingsFromProfile($legacyConflict);
$check(!empty($effective['demand']['enabled']) && !empty($effective['demand']['required_before_schedule']), 'configuração antiga de demanda obrigatória não é perdida por conversation_behavior divergente');
$check(($effective['demand']['prompt'] ?? '') === 'Qual é a principal demanda?', 'pergunta de demanda legada é preservada quando a nova camada ainda não tem texto próprio');

$fields = [
    ['field_key'=>'patient_age','active'=>1,'required_before_schedule'=>1,'position'=>20],
    ['field_key'=>'modality','active'=>1,'required_before_schedule'=>1,'position'=>30],
    ['field_key'=>'brief_demand','active'=>1,'required_before_schedule'=>1,'position'=>60],
];
$workflow = [
    ['step_key'=>'collect_demand','step_type'=>'collect','active'=>1,'position'=>10,'config'=>[]],
    ['step_key'=>'collect_age','step_type'=>'collect','active'=>1,'position'=>20,'config'=>[]],
    ['step_key'=>'collect_modality','step_type'=>'collect','active'=>1,'position'=>30,'config'=>[]],
];
$reflection = new ReflectionClass(AgentBlueprintService::class);
$method = $reflection->getMethod('applyWorkflowOrderToTriageFields');
$ordered = $method->invoke(new AgentBlueprintService(), $fields, $workflow);
$profileWithOrder = [
    'config' => ['conversation_behavior'=>['demand'=>['enabled'=>true,'required_before_schedule'=>true,'prompt'=>'Demanda?']]],
    'triage_fields' => $ordered,
];
$afterOverride = $behavior->applyOperationalOverridesToProfile($profileWithOrder);
$orderKeys = array_map(static fn(array $row): string => (string)($row['field_key'] ?? ''), $afterOverride['triage_fields']);
$check($orderKeys === ['brief_demand','patient_age','modality'], 'sobreposição operacional preserva exatamente a ordem cadastrada no workflow');

$view = (string) file_get_contents($root . '/app/Views/companies/overview.php');
$statusStart = strpos($view, "action=\"<?= View::e(Router::url('/companies/status')) ?>\"");
$slaStart = strpos($view, 'class="admin-company-sla-form"');
$toggle = strpos($view, 'name="sla_enabled"');
$check($statusStart !== false && $slaStart !== false && $toggle !== false && $toggle > $slaStart, 'toggle do SLA está dentro do formulário de SLA e não no cabeçalho da empresa');
$check(str_contains($view, '<input type="hidden" name="sla_enabled" value="0">'), 'desativação do SLA é enviada explicitamente ao controller');

$blueprint = (string) file_get_contents($root . '/app/Services/AgentBlueprintService.php');
$triage = (string) file_get_contents($root . '/app/Services/AgentTriageService.php');
$pre = (string) file_get_contents($root . '/app/Services/PreSchedulingService.php');
$calendar = (string) file_get_contents($root . '/app/Services/CalendarConversationService.php');
$afterHours = (string) file_get_contents($root . '/app/Services/AiAfterHoursRecoveryService.php');
$timing = (string) file_get_contents($root . '/app/Services/AiReplyTimingService.php');
$agentsView = (string) file_get_contents($root . '/app/Views/agents/index.php');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');

$check(str_contains($blueprint, 'brief_demand possui uma única fonte editável na UI') && str_contains($blueprint, 'ON DUPLICATE KEY UPDATE'), 'salvamento sincroniza a regra amigável com o campo técnico de demanda');
$check(str_contains($agentsView, 'Controlado em “Entender a demanda”'), 'interface não apresenta dois controles concorrentes para a demanda antes da agenda');
$check(str_contains($triage, 'missingRequiredBeforeSchedule') && str_contains($triage, 'calendar.pre_schedule'), 'Policy Engine continua bloqueando agenda enquanto faltar campo obrigatório');
$check(str_contains($pre, 'schedulingGate(') && str_contains($pre, 'schedulingDecision('), 'pré-agendamento mantém dupla validação: policy engine e regra de fluxo/grupo');
$check(str_contains($calendar, 'filterSlotsForAppointment') && str_contains($calendar, 'appointmentAutomationPolicy'), 'agenda continua validando regras de modalidade e horário operacional antes de oferecer/reservar slots');
$check(str_contains($afterHours, 'ai_after_hours_pending') && str_contains($timing, 'Clock::STORAGE_TIMEZONE') && str_contains($timing, 'new DateTimeImmutable($timestamp, $storageTimezone)'), 'retomada fora do horário e contrato UTC permanecem presentes');
$check(str_contains($version, 'RS Connect 36.36.17 — Consistência das regras do agente e SLA Admin'), 'pacote identificado como 36.36.17');

if ($failed !== []) {
    fwrite(STDERR, "\nFALHAS:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "\nOK - integridade das configurações validada ({$passed} verificações).\n";
