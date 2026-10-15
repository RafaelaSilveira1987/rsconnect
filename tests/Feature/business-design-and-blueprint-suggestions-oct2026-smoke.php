<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/Services/VisualBlueprintEditorService.php';

use App\Services\VisualBlueprintEditorService;

function checkThis(bool $condition, string $what): void
{
    if (!$condition) throw new RuntimeException('Falha: ' . $what);
}

$root = dirname(__DIR__, 2);
$layout = file_get_contents($root . '/app/Views/layouts/app.php');
$view = file_get_contents($root . '/app/Views/agent_blueprints/index.php');
$styles = file_get_contents($root . '/public/assets/css/rs-business-unified.css');
checkThis(str_contains($layout, 'rs-business-unified.css'), 'stylesheet compartilhado');
checkThis(str_contains($layout, '$businessDesign'), 'escopo por rota');
checkThis(str_contains($styles, '.rs-business-unified .page-content'), 'estilo encapsulado');
checkThis(!str_contains($styles, 'body {'), 'não há override global de body');
checkThis(str_contains($view, 'suggested_questions[]'), 'opções guiadas no editor');

$compiler = new VisualBlueprintEditorService();
$fields = VisualBlueprintEditorService::suggestedQuestions();
checkThis(count($fields) >= 4, 'catálogo de perguntas disponível');
$input = [
    'interaction_mode' => 'form',
    'calendar_pre_schedule' => '1',
    'suggested_questions' => ['patient_age', 'appointment_modality'],
];
$model = $compiler->compile([], $input);
checkThis($model['capabilities']['calendar.confirm'] === false, 'confirmação automática segue bloqueada');
checkThis($model['capabilities']['calendar.human_approval'] === true, 'aprovação humana segue ativa');
$keys = array_column($model['triage_fields'], 'key');
checkThis(in_array('patient_age', $keys, true) && in_array('appointment_modality', $keys, true), 'perguntas adicionadas');
$flow = array_column($model['workflow'], 'key');
checkThis(array_search('collect_patient_age', $flow, true) < array_search('calendar', $flow, true), 'idade antes da agenda');
checkThis(array_search('collect_appointment_modality', $flow, true) < array_search('calendar', $flow, true), 'modalidade antes da agenda');
checkThis($flow[array_key_last($flow)] === 'human_approval', 'aprovação encerra fluxo');

$repeat = $compiler->compile($model, $input);
checkThis(count($repeat['triage_fields']) === count($model['triage_fields']), 'publicação idempotente sem duplicação');
checkThis(count($repeat['workflow']) === count($model['workflow']), 'não duplicar etapas');
$empty = $compiler->compile($model, ['interaction_mode'=>'hybrid', 'calendar_pre_schedule'=>'1', 'suggested_questions'=>[]]);
checkThis(count($empty['triage_fields']) === count($model['triage_fields']), 'desmarcar não remove dados existentes');
$invalidRejected = false;
try {
    $compiler->compile([], ['interaction_mode'=>'form', 'calendar_pre_schedule'=>'0', 'suggested_questions'=>['sql_free_form']]);
} catch (InvalidArgumentException) { $invalidRejected = true; }
checkThis($invalidRejected, 'identificadores arbitrários recusados');

$custom = $model;
$custom['workflow'][] = ['key'=>'custom_business_action', 'label'=>'Ação especial', 'type'=>'action', 'position'=>90];
$customBlocked = false;
try {
    $compiler->compile($custom, ['interaction_mode'=>'form', 'calendar_pre_schedule'=>'1', 'suggested_questions'=>['first_consultation']]);
} catch (InvalidArgumentException) { $customBlocked = true; }
checkThis($customBlocked, 'fluxos avançados são preservados pelo bloqueio');

echo "OK - tema visual e perguntas guiadas de blueprint\n";
