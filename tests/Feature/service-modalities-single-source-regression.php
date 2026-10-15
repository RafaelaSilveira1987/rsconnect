<?php

declare(strict_types=1);

if (!function_exists('mb_strlen')) { function mb_strlen(string $text): int { return iconv_strlen($text, 'UTF-8'); } }
if (!function_exists('mb_substr')) { function mb_substr(string $text, int $start, ?int $length = null): string { return iconv_substr($text, $start, $length ?? iconv_strlen($text, 'UTF-8'), 'UTF-8'); } }
if (!function_exists('mb_strtolower')) { function mb_strtolower(string $text): string { return strtolower($text); } }
require_once dirname(__DIR__, 2) . '/app/Core/Autoloader.php';
\App\Core\Autoloader::register(dirname(__DIR__, 2) . '/app');

use App\Services\ServiceModalityConfiguration as Modalities;
use App\Services\AgentConversationBehaviorService as Behavior;
use App\Validation\ExpressOnboardingRequest as Request;
use App\Validation\ExpressValidationException;

function ensureModalities(bool $condition, string $reason): void {
    if (!$condition) throw new RuntimeException($reason);
}
function rejectModalities(array $payload, string $path): void {
    try { Request::validate($payload); }
    catch (ExpressValidationException $e) {
        ensureModalities(array_key_exists($path, $e->errors), "Erro esperado em $path");
        return;
    }
    throw new RuntimeException("Aceitou modalidade inválida em $path");
}

$payload = [
    'business_info'=>['agent_name'=>'Assistente Vida Plena','segment'=>'psychology_clinic','tone_of_voice'=>'empathetic_and_welcoming'],
    'commercial_info'=>['pricing_strategy'=>'fixed_price','price_value'=>'R$ 180,00','payment_methods'=>['pix']],
    'triage_config'=>['collect_name'=>true,'collect_phone'=>true,'custom_questions'=>[]],
    'routing_and_schedule'=>['timezone'=>'America/Sao_Paulo','business_hours'=>[['day'=>'monday','start'=>'08:00','end'=>'17:00']],
        'out_of_hours_action'=>'capture_lead_and_promise_contact','default_meeting_link'=>null],
    'knowledge_base'=>['faq'=>[]],
    'capabilities'=>['allow_rescheduling'=>true,'escalate_to_human_on_error'=>true,'send_meeting_link_on_confirmation'=>false],
];
// Contrato antigo não desliga a modalidade já salva.
ensureModalities(Request::validate($payload)->serviceModalities === null, 'API antiga deve preservar modalidade.');
$payload['service_modalities'] = [
    'mode'=>'both','online_channel'=>'Google Meet','online_message'=>'Link após a confirmação',
    'presencial_location'=>'Centro, Rua A','presencial_days'=>['mon','wed'],
    'presencial_message'=>'Atendemos presencialmente às segundas e quartas.',
];
$validated = Request::validate($payload);
ensureModalities($validated->serviceModalities['mode'] === 'both','Modo both inválido.');
$original = ['payment'=>['enabled'=>true,'value_text'=>'R$ 180,00','methods'=>['Pix']],
    'response_delivery'=>['mode'=>'blocks','max_blocks'=>3,'scope'=>'asked_only'],
    'modalities'=>['online'=>['enabled'=>false],'presencial'=>['enabled'=>false]]];
$saved = Modalities::applyToBehavior($original, $validated->serviceModalities);
ensureModalities(($saved['payment']['value_text'] ?? '') === 'R$ 180,00', 'Preço alterado indevidamente.');
ensureModalities(($saved['response_delivery']['mode'] ?? '') === 'blocks', 'Formato de resposta alterado.');
ensureModalities(($saved['modalities']['presencial']['allowed_days'] ?? []) === ['mon','wed'], 'Dias divergentes.');
ensureModalities(Modalities::fromBehavior($saved) === $validated->serviceModalities, 'Persistência/leitura não fecham o ciclo.');
$effective = (new Behavior())->settingsFromProfile(['config'=>['conversation_behavior'=>$saved]]);
ensureModalities(($effective['modalities']['online']['enabled'] ?? null) === true, 'Online não chegou ao runtime.');
ensureModalities(($effective['service_mode']['mode'] ?? '') === 'choice', 'Escolha entre modalidades não chegou ao runtime.');

$broken = $payload;
$broken['service_modalities']['presencial_days'] = [];
rejectModalities($broken, 'service_modalities.presencial_days');
$broken = $payload;
$broken['service_modalities']['presencial_location'] = '';
rejectModalities($broken, 'service_modalities.presencial_location');
$broken = $payload;
$broken['service_modalities']['online_channel'] = '';
rejectModalities($broken, 'service_modalities.online_channel');
$broken = $payload;
$broken['service_modalities']['presencial_days'] = ['mon','mon'];
rejectModalities($broken, 'service_modalities.presencial_days');
$broken = $payload;
$broken['service_modalities']['mode'] = true;
rejectModalities($broken, 'service_modalities.mode');
$broken = $payload;
$broken['service_modalities']['presencial_days'] = ['wrong'];
rejectModalities($broken, 'service_modalities.presencial_days.0');

$writer = file_get_contents(__DIR__.'/../../app/Services/AgentBlueprintService.php');
$view = file_get_contents(__DIR__.'/../../app/Views/agents/index.php');
$reader = file_get_contents(__DIR__.'/../../app/Services/ExpressBusinessConfigurationReader.php');
ensureModalities(str_contains($writer, "if (!empty(\$express['modalities_managed']))"), 'Admin não protege dados da empresa.');
ensureModalities(str_contains($view, 'Formas de atendimento controladas por Meu atendimento'), 'Admin deve ser somente leitura.');
ensureModalities(str_contains($reader, 'ServiceModalityConfiguration::fromBehavior'), 'GET deve ler da fonte operacional.');
echo "OK: modalidades, salvamento/leitura, dados legados, validação e autoridade operacional.\n";
