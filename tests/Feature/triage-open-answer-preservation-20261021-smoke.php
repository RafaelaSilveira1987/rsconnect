<?php

declare(strict_types=1);

if (!function_exists('mb_strtolower')) { function mb_strtolower(string $v, ?string $e=null): string { return strtolower(strtr($v, ['É'=>'é', 'Á'=>'á', 'Í'=>'í', 'Ó'=>'ó', 'Ú'=>'ú', 'Ç'=>'ç'])); } }
if (!function_exists('mb_strlen')) { function mb_strlen(string $v, ?string $e=null): int { return strlen($v); } }
if (!function_exists('mb_substr')) { function mb_substr(string $v,int $s,?int $l=null,?string $e=null): string { return $l===null ? substr($v,$s) : substr($v,$s,$l); } }
require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Services\AgentTriageService;

$profile = [
    'status' => 'active', 'interaction_mode' => 'hybrid',
    'capabilities' => ['triage.enabled' => true], 'policies' => [],
    'triage_fields' => [
        ['field_key'=>'custom_demanda','label'=>'Demanda','field_type'=>'text',
         'prompt_text'=>'Tem acontecido alguma coisa que fez você perceber que seria importante iniciar a psicoterapia?',
         'active'=>1, 'required_for_completion'=>1],
        ['field_key'=>'patient_age','label'=>'Idade','field_type'=>'number',
         'prompt_text'=>'Qual é a idade?','active'=>1,'required_for_completion'=>1],
        ['field_key'=>'custom_primeira_consulta','label'=>'Primeira consulta','field_type'=>'text',
         'prompt_text'=>'É a sua primeira consulta?','active'=>1,'required_for_completion'=>1],
    ],
];
$t = new AgentTriageService();
$check = static function (bool $pass, string $what): void {
    echo ($pass ? '[OK] ' : '[FALHA] '), $what, "\n";
    if (!$pass) exit(1);
};
$first = $t->simulateTurn($profile,
    ['collected'=>[], 'current_field_key'=>'custom_demanda','status'=>'collecting'],
    'Sim, tenho estado muito ansiosa desde que perdi uma pessoa.');
$check(str_contains((string) ($first['collected']['custom_demanda'] ?? ''), 'perdi uma pessoa'),
    'relato aberto é preservado integralmente, sem colapsar para Sim');
$check(($first['state']['current_field_key'] ?? '') === 'patient_age', 'cursor avança para idade');
$second = $t->simulateTurn($profile, $first['state'], '30');
$check(($second['collected']['patient_age'] ?? null) === 30, 'idade exata capturada');
$check(($second['collected']['custom_demanda'] ?? '') === $first['collected']['custom_demanda'],
    'idade não altera relato coletado');
$third = $t->simulateTurn($profile, $second['state'], 'Sim');
$check(($third['collected']['custom_primeira_consulta'] ?? '') === 'Sim',
    'resposta binária da pergunta fechada continua capturada');
$check(($third['collected']['custom_demanda'] ?? '') === $first['collected']['custom_demanda'],
    'pergunta fechada não sobrescreve relato');
$invalid = $t->simulateTurn($profile,
    ['collected'=>['custom_demanda'=>'Sim'], 'current_field_key'=>'patient_age', 'status'=>'collecting'], '23');
$check(empty($invalid['collected']['custom_demanda'] ?? null)
    && ($invalid['state']['current_field_key'] ?? '') === 'custom_demanda',
    'valor legado genérico inválido é reaberto sem avançar indevidamente');

echo "OK - regressão de pergunta aberta/fechada e retomada de coleta.\n";
