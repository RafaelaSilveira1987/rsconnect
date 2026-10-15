<?php

declare(strict_types=1);

require_once dirname(__DIR__,2).'/app/Core/Autoloader.php';
\App\Core\Autoloader::register(dirname(__DIR__,2).'/app');

use App\Services\WorkflowTriageIntegrityService;
use App\Services\BusinessHoursCompactEditorService;
use App\Services\AgentReadinessEvaluator;

function insist(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
$fields = [];
foreach (['requester_name','self_or_other','schedule_preference','contact_phone','patient_age','brief_demand','preferred_period'] as $i=>$key) {
    $fields[] = ['field_key'=>$key, 'active'=>1,'required_before_schedule'=>1,
        'required_for_completion'=>1,'prompt_text'=>'Pergunta '.($i+1),'label'=>'Item '.($i+1)];
}
$action = ['step_key'=>'calendar','active'=>1,'step_type'=>'action','position'=>20,'config'=>['action_key'=>'calendar.read']];
$service = new WorkflowTriageIntegrityService();
$allMissing = $service->plan($fields, [['step_key'=>'eligibility','step_type'=>'policy','position'=>10,'active'=>1],$action]);
insist(count($allMissing['missing']) === 7 && $allMissing['late'] === [], 'Sete campos sem coletor devem ser diagnosticados.');
$early = ['step_key'=>'collect_contact','step_type'=>'collect','position'=>10,'active'=>1,
    'config'=>['field_keys'=>array_column(array_slice($fields,0,6),'field_key')]];
$late = ['step_key'=>'collect_preference','step_type'=>'collect','position'=>30,'active'=>1,
    'config'=>['field_key'=>'preferred_period']];
$plan = $service->plan($fields, [$early,$action,$late]);
insist($plan['missing'] === [] && $plan['late'] === ['preferred_period'], 'Coleta após agenda permanece bloqueada.');
$late['position'] = 15;
$complete = $service->plan($fields, [$early,$late,$action]);
insist($complete['missing'] === [] && $complete['late'] === [], 'Coleta completa antes da agenda aprovada.');
$fields[3]['prompt_text']='';
insist($service->plan($fields,[$action])['unrepairable'] === ['contact_phone'], 'Não gerar etapa para campo sem pergunta.');

$uniform = BusinessHoursCompactEditorService::describe(['mon'=>[['08:00','17:00']],'tue'=>[['08:00','17:00']]]);
insist($uniform['complex'] === false && $uniform['days'] === ['mon','tue'] && $uniform['end']==='17:00', 'Editor reduzido precisa mostrar horário real.');
$complex = BusinessHoursCompactEditorService::describe(['mon'=>[['08:00','12:00'],['13:00','17:00']],'tue'=>[['09:00','18:00']]]);
insist($complex['complex'] === true, 'Editor simplificado não pode sobrescrever múltiplas faixas.');
$diff = BusinessHoursCompactEditorService::describe(['mon'=>[['08:00','17:00']],'tue'=>[['08:00','18:00']]]);
insist($diff['complex'] === true, 'Faixas diferentes por dia não podem virar uma faixa única.');

$fields[3]['prompt_text']='Pergunta 4';
$report = (new AgentReadinessEvaluator())->evaluate([
    'agent'=>['id'=>1,'name'=>'Assistente teste','business_hours_enabled'=>1,
        'business_timezone'=>'America/Sao_Paulo','business_hours_json'=>'{"mon":[["08:00","17:00"]]}'],
    'profile'=>['id'=>1,'blueprint_version_id'=>1,'status'=>'active'],
    'triage_fields'=>$fields,'workflow'=>[$action],
    'capabilities'=>[],'channels'=>[],'policies'=>[],
]);
$flowChecks=array_values(array_filter($report['checklist'],static fn($c)=>$c['category']==='workflow'));
insist(count($flowChecks) === 1 && ($flowChecks[0]['auto_fix_action'] ?? '') === 'restore_required_triage_steps', 'Readiness precisa oferecer reparo da coleta.');
insist($report['can_operate'] === false, 'Não autorizar agente com campos obrigatórios sem coleta.');

$advancedSource = file_get_contents(dirname(__DIR__,2).'/app/Services/AgentBlueprintService.php');
$syncStart = strpos($advancedSource, 'private function syncWorkflowScheduleRequirements');
$syncEnd = strpos($advancedSource, 'private function addExistingFieldToWorkflow', $syncStart);
$syncSource = substr($advancedSource, $syncStart, $syncEnd-$syncStart);
insist(!str_contains($syncSource, 'SET required_before_schedule = 0,'), 'Editor avançado não pode apagar campos de outras telas em massa.');
$onboardingSource = file_get_contents(dirname(__DIR__,2).'/app/Services/OnboardingGuideService.php');
insist(str_contains($onboardingSource, 'WorkflowTriageIntegrityService())->repair'), 'A Ativação Assistida precisa reconciliar coletas.');
echo "OK: 7 campos ausentes, coleta tardia, controle de prontidão e horários complexos.\n";
