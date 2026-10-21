<?php
declare(strict_types=1);
if (!function_exists('mb_strtolower')) { function mb_strtolower(string $v, ?string $enc=null): string { return strtr(strtolower($v), ['É'=>'é']); } }
if (!function_exists('mb_substr')) { function mb_substr(string $v,int $s,?int $l=null,?string $enc=null): string { return $l===null?substr($v,$s):substr($v,$s,$l); } }
if (!function_exists('mb_strlen')) { function mb_strlen(string $v,?string $enc=null): int { return strlen($v); } }
require dirname(__DIR__, 2) . '/bootstrap.php';
use App\Services\AgentTriageService;
$s = new AgentTriageService();
$p = [
 'status'=>'active','interaction_mode'=>'hybrid','capabilities'=>['triage.enabled'=>true,'eligibility.enabled'=>false], 'policies'=>[],
 'triage_fields'=>[
  ['field_key'=>'is_for_self','label'=>'Atendimento para quem','field_type'=>'boolean','prompt_text'=>'É para você mesmo?','active'=>1,'required_before_schedule'=>1,'required_for_completion'=>1],
  ['field_key'=>'custom_contact_phone','label'=>'Telefone para contato','field_type'=>'text','prompt_text'=>'Qual é seu telefone para contato?','active'=>1,'required_before_schedule'=>1,'required_for_completion'=>1],
  ['field_key'=>'custom_first_consult','label'=>'É a sua primeira consulta','field_type'=>'text','prompt_text'=>'É a sua primeira consulta?','active'=>1,'required_before_schedule'=>1,'required_for_completion'=>1],
 ]
];
$checks=[];
$run = static function(string $label,bool $valid)use(&$checks):void {$checks[$label]=$valid;echo ($valid?'[OK] ':'[FAIL] ').$label."\n";};
$state=['collected'=>[],'current_field_key'=>'is_for_self','last_intent'=>'schedule','status'=>'collecting'];
$r=$s->simulateMessageBurst($p,$state,['Bom dia,','Sim seria pra mim']);
$run('Resposta com saudação indica atendimento para si', ($r['collected']['is_for_self']??null)===true);
$run('Saudação não preenche telefone', !isset($r['collected']['custom_contact_phone']));
$run('Saudação não preenche primeira consulta', !isset($r['collected']['custom_first_consult']));
$run('Cursor permanece no telefone', ($r['current_field_key']??'')==='custom_contact_phone');
$r2=$s->simulateMessageBurst($p,['collected'=>$r['collected'],'current_field_key'=>'custom_contact_phone'],['Gostaria de agendar uma consulta.']);
$run('Pedido de agendamento não vira telefone', !isset($r2['collected']['custom_contact_phone']));
$r3=$s->simulateMessageBurst($p,['collected'=>$r2['collected'],'current_field_key'=>'custom_contact_phone'],['(32) 98888-7777']);
$run('Telefone real preenche campo de contato', ($r3['collected']['custom_contact_phone']??null)==='32988887777');
$r4=$s->simulateMessageBurst($p,['collected'=>$r3['collected'],'current_field_key'=>'custom_first_consult'],['Bom dia, sim seria para mim']);
$run('Resposta de atendimento para si não vira primeira consulta', !isset($r4['collected']['custom_first_consult']));
$r5=$s->simulateMessageBurst($p,['collected'=>$r4['collected'],'current_field_key'=>'custom_first_consult'],['Sim, é minha primeira consulta']);
$run('Resposta à primeira consulta é padronizada', ($r5['collected']['custom_first_consult']??null)==='Sim');
$run('Etapa de primeira consulta foi concluída', !isset($r5['current_field_key']));
$old=$s->simulateTurn($p,['collected'=>['is_for_self'=>true,'custom_contact_phone'=>'Gostaria de agendar uma consulta.','custom_first_consult'=>'Bom dia, Sim seria pra mim'],'current_field_key'=>null,'last_intent'=>'schedule','status'=>'collecting'],'Olá');
$run('Telefone incorreto gravado antes é invalidado',!isset($old['collected']['custom_contact_phone']));
$run('Primeira consulta incorreta gravada antes é invalidada',!isset($old['collected']['custom_first_consult']));
exit(in_array(false,$checks,true)?1:0);
