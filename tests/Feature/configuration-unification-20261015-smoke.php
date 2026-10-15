<?php

declare(strict_types=1);

if (!function_exists('mb_strtolower')) { function mb_strtolower(string $text): string { return strtolower($text); } }

require_once dirname(__DIR__, 2) . '/app/Core/Autoloader.php';
\App\Core\Autoloader::register(dirname(__DIR__, 2) . '/app');

use App\Services\BusinessHoursNormalizer as Hours;
use App\Services\AgentReadinessEvaluator;
use App\Services\ExpressConfigurationReconciler;
use App\Services\ConfigurationConsistencyAuditService;

function assertCase(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }

$compact = ['days'=>['mon','tue'],'start'=>'08:00','end'=>'18:00'];
$detailed = ['mon'=>[['08:00','18:00']], 'tue'=>[['08:00','18:00']]];
$api = [['day'=>'monday','start'=>'08:00','end'=>'18:00'], ['day'=>'tuesday','start'=>'08:00','end'=>'18:00']];
assertCase(Hours::normalize($compact) === Hours::normalize($detailed), 'Compacto e detalhado devem coincidir.');
assertCase(Hours::normalize($api) === Hours::normalize($detailed), 'Contrato API deve coincidir com runtime.');
assertCase(Hours::asApiList($compact) === $api, 'Conversão para UI deve preservar dias.');
assertCase(Hours::normalize(['mon'=>[['08:00','12:00'],['11:00','17:00']]]) === null, 'Intervalos sobrepostos são inválidos.');
assertCase(Hours::normalize(['days'=>['xyz'],'start'=>'08:00','end'=>'17:00']) === null, 'Dia inválido deve ser rejeitado.');
assertCase(Hours::normalize(['mon'=>[['18:00','09:00']]]) === null, 'Intervalo invertido deve ser rejeitado.');
$evaluateHours = new ReflectionMethod(AgentReadinessEvaluator::class, 'validBusinessHours');
foreach ([$compact,$detailed,$api] as $hours) {
    assertCase($evaluateHours->invoke(new AgentReadinessEvaluator(), [
        'business_hours_enabled'=>1,'business_timezone'=>'America/Sao_Paulo',
        'business_hours_json'=>json_encode($hours),
    ]) === true, 'Prontidão deve aceitar todas as representações operacionais válidas.');
}

$r = new ExpressConfigurationReconciler();
$current = [
    ['field_key'=>'brief_demand','label'=>'Demanda','field_type'=>'text','prompt_text'=>'Qual é a sua demanda?',
        'active'=>1,'position'=>10,'required_before_schedule'=>1,'required_for_completion'=>1],
    ['field_key'=>'patient_age','label'=>'Idade','field_type'=>'number','prompt_text'=>'Qual é sua idade?',
        'active'=>1,'position'=>20,'required_before_schedule'=>1,'required_for_completion'=>1],
    ['field_key'=>'requester_name','label'=>'Nome','field_type'=>'text','prompt_text'=>'Qual é seu nome?',
        'active'=>1,'position'=>30,'required_before_schedule'=>1,'required_for_completion'=>1],
    ['field_key'=>'express_deadbeef','label'=>'Antiga','field_type'=>'text','prompt_text'=>'Pergunta antiga?',
        'active'=>1,'position'=>40,'required_before_schedule'=>1,'required_for_completion'=>1],
];
$incoming = [
    ['key'=>'brief_demand','label'=>'Modelo padrão','prompt'=>'Novo prompt que NÃO deve substituir o cadastro real','position'=>10],
    ['key'=>'requester_name','label'=>'Contato','prompt'=>'Como posso chamar você?','position'=>20],
    ['key'=>'express_abcdef','label'=>'Novo','prompt'=>'Pergunta nova?','position'=>30],
];
$fields = $r->triage($current,$incoming);
$keys = array_column($fields['fields'],'key');
assertCase(in_array('brief_demand',$keys,true) && in_array('patient_age',$keys,true), 'Perguntas de outra tela não podem sumir.');
assertCase(!in_array('express_deadbeef',$keys,true) && in_array('express_deadbeef',$fields['delete_keys'],true), 'Só perguntas Express removidas devem ser excluídas.');
assertCase(!in_array('patient_age',$fields['delete_keys'],true), 'Não apagar pergunta externa.');
$byKey = array_column($fields['fields'], null, 'key');
assertCase($byKey['brief_demand']['prompt'] === 'Qual é a sua demanda?', 'Dados operacionais devem prevalecer sobre Blueprint.');
$oldWorkflow = [
    ['step_key'=>'assisted_intake','label'=>'Dados iniciais','step_type'=>'collect',
        'config_json'=>json_encode(['field_keys'=>['brief_demand','patient_age','requester_name','express_deadbeef']]),
        'active'=>1,'position'=>10],
    ['step_key'=>'calendar','label'=>'Agenda','step_type'=>'action',
        'config_json'=>json_encode(['action_key'=>'calendar.read']),'active'=>1,'position'=>30],
];
$newWorkflow = [
    ['key'=>'collect_brief_demand','label'=>'Demanda','type'=>'collect','config'=>['field_keys'=>['brief_demand']],'position'=>10],
    ['key'=>'collect_requester_name','label'=>'Nome','type'=>'collect','config'=>['field_keys'=>['requester_name']],'position'=>20],
    ['key'=>'collect_express_abcdef','label'=>'Novo','type'=>'collect','config'=>['field_keys'=>['express_abcdef']],'position'=>30],
    ['key'=>'calendar','label'=>'Agenda padrão','type'=>'action','config'=>['action_key'=>'calendar.read'],'position'=>40],
];
$plan = $r->workflow($oldWorkflow,$newWorkflow,$fields['delete_keys']);
$steps = array_column($plan['steps'], null,'key');
assertCase(isset($steps['assisted_intake']) && isset($steps['calendar']) && isset($steps['collect_express_abcdef']), 'Preservar fluxo original e inserir coleta nova.');
assertCase(!isset($steps['collect_brief_demand']) && !isset($steps['collect_requester_name']), 'Não duplicar perguntas em etapas coletoras.');
assertCase($steps['assisted_intake']['config']['field_keys'] === ['brief_demand','patient_age','requester_name'], 'Referência órfã deve ser removida de etapa compartilhada.');
assertCase($steps['collect_express_abcdef']['position'] < $steps['calendar']['position'], 'Coletar antes de consultar agenda.');

$p = $r->policies([
    ['policy_key'=>'assisted.discount_request','policy_type'=>'json','value_json'=>'{}','action_key'=>'handoff','customer_message'=>'Falar com equipe','enabled'=>1,'priority'=>10],
], [['key'=>'express.fixed_price','type'=>'string','value'=>'R$ 180,00','action'=>'warn','message'=>'Não invente preços.']]);
assertCase(count($p) === 2, 'Política antiga e comercial devem coexistir.');
$caps = $r->capabilities(['calendar.human_approval'=>true,'calendar.confirm'=>true,'calendar.read'=>true],
    ['calendar.confirm'=>true,'calendar.reschedule'=>false]);
assertCase($caps['calendar.confirm'] === false && $caps['calendar.human_approval'] === true, 'Aprovação humana nunca pode ser relaxada.');

$base = ['agent'=>['business_hours_enabled'=>1,'business_hours_json'=>json_encode($compact)],
    'profile'=>['blueprint_version_id'=>1,'config'=>['express'=>['routing'=>['business_hours'=>$detailed]]]],
    'onboarding_settings'=>['business_hours_json'=>json_encode($detailed)],
    'pre_schedule_settings'=>['require_human_approval'=>1,'ai_can_confirm'=>0],
    'calendar_availability_settings'=>['enabled'=>1],
    'capabilities'=>['calendar.human_approval'=>true,'calendar.confirm'=>false],
    'triage_fields'=>[]];
$audit = (new ConfigurationConsistencyAuditService())->inspect($base);
assertCase($audit['issues'] === [], 'Mesmos horários em formatos diferentes não são divergência.');
$base['capabilities']['calendar.confirm'] = true;
$base['onboarding_settings']['business_hours_json'] = json_encode(['days'=>['fri'],'start'=>'09:00','end'=>'17:00']);
$audit = (new ConfigurationConsistencyAuditService())->inspect($base);
$issueKeys = array_column($audit['issues'],'key');
assertCase(in_array('hours_mismatch_onboarding',$issueKeys,true), 'Diferença operacional de horário precisa ser reportada.');
assertCase(in_array('human_approval_conflict',$issueKeys,true) && $audit['ok'] === false, 'Autorização conflitante precisa ser visível.');

// Garante que a correção elimine o DELETE global introduzido na Fase 1.
$source = file_get_contents(dirname(__DIR__,2).'/app/Services/ExpressOnboardingService.php');
assertCase(!str_contains($source,'DELETE FROM tenant_triage_fields'), 'Expressa não pode excluir toda triagem.');
assertCase(!str_contains($source,'DELETE FROM tenant_agent_workflow_steps'), 'Expressa não pode excluir todo workflow.');
assertCase(!str_contains($source,'DELETE FROM tenant_agent_policies'), 'Expressa não pode excluir todas as políticas.');

echo "OK: normalização única, reconciliação sem perdas, conflitos e auditoria não-mutável.\n";
