<?php

declare(strict_types=1);

if (!function_exists('mb_substr')) { function mb_substr(string $v,int $s,?int $l=null,?string $e=null): string { return $l===null?substr($v,$s):substr($v,$s,$l); } }
if (!function_exists('mb_strtolower')) { function mb_strtolower(string $v,?string $e=null): string { return strtr(strtolower($v), ['É'=>'é','Á'=>'á','À'=>'à','Ê'=>'ê','Í'=>'í','Ó'=>'ó','Ú'=>'ú','Ç'=>'ç']); } }
if (!function_exists('mb_strlen')) { function mb_strlen(string $v,?string $e=null): int { return strlen($v); } }
require_once dirname(__DIR__, 2) . '/bootstrap.php';

use App\Services\AgentTriageService;
use App\Services\VisualBlueprintEditorService;

$service = new AgentTriageService();
$profile = [
    'status'=>'active', 'interaction_mode'=>'hybrid',
    'capabilities'=>['triage.enabled'=>true, 'eligibility.enabled'=>false],
    'policies'=>[],
    'triage_fields'=>[
        ['field_key'=>'is_for_self','label'=>'Atendimento para quem','field_type'=>'boolean','prompt_text'=>'É para você?','active'=>1,'required_before_schedule'=>1,'required_for_completion'=>1],
        ['field_key'=>'custom_first_consultation','label'=>'Primeira consulta','field_type'=>'text','prompt_text'=>'É a sua primeira consulta?','active'=>1,'required_before_schedule'=>1,'required_for_completion'=>1],
        ['field_key'=>'custom_demand','label'=>'Demanda','field_type'=>'text','prompt_text'=>'Qual a demanda?','active'=>1,'required_before_schedule'=>1,'required_for_completion'=>1],
    ],
];
$checks=[];
$catalog = VisualBlueprintEditorService::suggestedQuestions();
$checks['novo blueprint sugere campo booleano'] = ($catalog['first_consultation']['type'] ?? null) === 'boolean';
$initial=['collected'=>['is_for_self'=>true], 'current_field_key'=>'custom_first_consultation', 'last_intent'=>'schedule', 'status'=>'collecting'];
$yes=$service->simulateTurn($profile,$initial,'Sim');
$checks['sim é gravado na pergunta fechada legada'] = ($yes['collected']['custom_first_consultation']??null)==='Sim';
$checks['fluxo avança para demanda após sim'] = ($yes['state']['current_field_key']??null)==='custom_demand';
$checks['a primeira consulta não é repetida'] = !in_array('custom_first_consultation',$yes['missing_before_schedule'],true);
$no=$service->simulateTurn($profile,$initial,'Não, já consultei');
$checks['não é gravado sem virar vazio'] = ($no['collected']['custom_first_consultation']??null)==='Não';
$checks['negação também avança'] = ($no['state']['current_field_key']??null)==='custom_demand';
$afterHours=$service->simulateTurn($profile,[...$yes['state'],'last_intent'=>'schedule'],'Sim');
$checks['sim solto não preenche demanda'] = !isset($afterHours['collected']['custom_demand']);
$checks['retomada preserva o campo já respondido'] = ($afterHours['collected']['custom_first_consultation']??null)==='Sim';
$checks['retomada mantém próxima pergunta'] = ($afterHours['state']['current_field_key']??null)==='custom_demand';
$binary=$profile;
$binary['triage_fields'][1]['field_type']='boolean';
$booleanNo=$service->simulateTurn($binary,$initial,'Não');
$checks['boolean false é armazenado como false'] = array_key_exists('custom_first_consultation',$booleanNo['collected']) && $booleanNo['collected']['custom_first_consultation'] === false;
$checks['boolean false não é considerado ausente'] = !in_array('custom_first_consultation',$booleanNo['missing_before_schedule'],true);
$open=$profile;
$open['triage_fields'][1]['prompt_text']='Qual o motivo da consulta?';
$openNo=$service->simulateTurn($open,$initial,'Sim');
$checks['pergunta aberta não absorve sim'] = !isset($openNo['collected']['custom_first_consultation']);
foreach($checks as $name=>$ok){ echo ($ok?'[OK] ':'[FALHA] ').$name.PHP_EOL; }
if(in_array(false,$checks,true))exit(1);
echo "OK — respostas binárias e retomada de triagem.\n";
