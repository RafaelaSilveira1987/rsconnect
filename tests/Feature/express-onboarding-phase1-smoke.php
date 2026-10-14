<?php

declare(strict_types=1);

// This CI sandbox does not provide ext-mbstring (required in production by composer.json).
// Test-only polyfills let the deterministic compiler be verified here.
if (!function_exists('mb_strlen')) { function mb_strlen(string $s): int { return iconv_strlen($s, 'UTF-8'); } }
if (!function_exists('mb_strtolower')) { function mb_strtolower(string $s): string { return strtolower($s); } }
if (!function_exists('mb_substr')) { function mb_substr(string $s, int $start, ?int $len = null): string { return iconv_substr($s,$start,$len ?? iconv_strlen($s,'UTF-8'), 'UTF-8'); } }

require_once dirname(__DIR__, 2) . '/app/Core/Autoloader.php';
\App\Core\Autoloader::register(dirname(__DIR__, 2) . '/app');

use App\Validation\ExpressOnboardingRequest;
use App\Validation\ExpressValidationException;
use App\Services\AgentBlueprintService;
use App\Services\AgentRuntimeConfigurationService;

function check(bool $condition, string $reason): void {
    if (!$condition) throw new RuntimeException($reason);
}
function mustFail(array $p, string $path): void {
    try { ExpressOnboardingRequest::validate($p); }
    catch (ExpressValidationException $e) {
        check(array_key_exists($path, $e->errors), "Expected validation failure: $path, got " . json_encode($e->errors));
        return;
    }
    throw new RuntimeException("Expected failure: $path");
}

$p = [
    'business_info'=>['agent_name'=>'Assistente Vida Plena','segment'=>'psychology_clinic','tone_of_voice'=>'empathetic_and_welcoming'],
    'commercial_info'=>['pricing_strategy'=>'fixed_price','price_value'=>'R$ 250,00','payment_methods'=>['pix','credit_card']],
    'triage_config'=>['collect_name'=>true,'collect_phone'=>true,'custom_questions'=>['É a sua primeira consulta?', 'Para qual especialidade você busca atendimento?']],
    'routing_and_schedule'=>['timezone'=>'America/Sao_Paulo','business_hours'=>[
        ['day'=>'monday','start'=>'08:00','end'=>'18:00'],['day'=>'tuesday','start'=>'08:00','end'=>'18:00'],
    ],'out_of_hours_action'=>'capture_lead_and_promise_contact','default_meeting_link'=>'https://meet.google.com/abc-defg-hij'],
    'knowledge_base'=>['faq'=>[['question'=>'Aceitam planos?','answer'=>'Somente particular.']]],
    'capabilities'=>['allow_rescheduling'=>true,'escalate_to_human_on_error'=>true,'send_meeting_link_on_confirmation'=>true],
];
$dto = ExpressOnboardingRequest::validate($p);
$base = ['capabilities'=>['calendar.human_approval'=>true,'calendar.confirm'=>true,'calendar.read'=>true],
    'triage_fields'=>[['key'=>'brief_demand','label'=>'Demanda','type'=>'textarea','prompt'=>'Qual é a demanda?','required_before_schedule'=>true]],
    'policies'=>[]];
$result = (new AgentBlueprintService())->compileExpress($dto, $base);
check($result['capabilities']['calendar.confirm'] === false, 'Aprovação humana deve vencer confirmação automática.');
check(in_array('brief_demand', array_column($result['triage_fields'],'key'), true), 'Não remover coleta de demanda existente.');
check(str_contains($result['system_prompt'], 'rigorosamente R$ 250,00'), 'Guardrail de preço ausente.');
check(!str_contains($result['system_prompt'], 'meet.google.com'), 'Prompt não deve divulgar fallback do link.');
check(str_contains($result['hours_json'], 'mon'), 'Conversão de horário incompatível com runtime.');
check(count($result['triage_fields']) === 5, 'Campos esperados: demanda, nome, telefone, 2 custom.');
check($result === (new AgentBlueprintService())->compileExpress($dto, $base), 'Compilação não determinística.');
$audit = (new AgentRuntimeConfigurationService())->audit([
    'triage_fields'=>array_map(static fn ($f) => ['field_key'=>$f['key'], 'label'=>$f['label'],
        'prompt_text'=>$f['prompt'], 'active'=>1,'required_before_schedule'=>$f['required_before_schedule'] ?? false], $result['triage_fields']),
    'workflow'=>array_map(static fn ($step) => ['step_key'=>$step['key'],'label'=>$step['label'],
        'step_type'=>$step['type'],'config'=>$step['config'] ?? [],'active'=>1,'position'=>$step['position']], $result['workflow']),
    'policies'=>[],
]);
check($audit['ok'] === true, 'Fluxo com coleta obrigatória após agenda: '.json_encode($audit['errors']));

$broken = $p;
$broken['business_info']['segment'] = 'invalid_segment';
mustFail($broken, 'business_info.segment');
$broken = $p;
$broken['capabilities']['allow_rescheduling'] = 'true';
mustFail($broken, 'capabilities.allow_rescheduling');
$broken = $p;
$broken['routing_and_schedule']['business_hours'][] = ['day'=>'monday','start'=>'09:00','end'=>'12:00'];
mustFail($broken, 'routing_and_schedule.business_hours');
$broken = $p;
$broken['routing_and_schedule']['default_meeting_link'] = null;
mustFail($broken, 'routing_and_schedule.default_meeting_link');
$broken = $p;
$broken['commercial_info']['price_value'] = 'promoção indefinida';
mustFail($broken, 'commercial_info.price_value');
$broken = $p;
$broken['routing_and_schedule']['timezone'] = 'BRT';
mustFail($broken, 'routing_and_schedule.timezone');

$realEstate = $p;
$realEstate['business_info']['segment'] = 'real_estate';
check(ExpressOnboardingRequest::validate($realEstate)->segment->nicheCode() === 'services', 'Fallback do catálogo real estate mudou.');

echo "OK: DTO, strict validation, template compiler, meeting-link guardrails and workflow audit.\n";
