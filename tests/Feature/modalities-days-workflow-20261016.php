<?php

declare(strict_types=1);

if (!function_exists('mb_strtolower')) { function mb_strtolower(string $v): string { return strtolower(iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$v) ?: $v); } }
if (!function_exists('mb_strlen')) { function mb_strlen(string $v): int { return iconv_strlen($v,'UTF-8'); } }
if (!function_exists('mb_substr')) { function mb_substr(string $v,int $start,?int $length=null): string { return iconv_substr($v,$start,$length ?? iconv_strlen($v,'UTF-8'),'UTF-8'); } }
require_once dirname(__DIR__, 2) . '/app/Core/Autoloader.php';
\App\Core\Autoloader::register(dirname(__DIR__, 2) . '/app');

use App\Services\AgentConversationBehaviorService as Behavior;
use App\Services\ServiceModalityConfiguration as Modality;
use App\Validation\ExpressOnboardingRequest as Request;
use App\Validation\ExpressValidationException;

function verify(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}

$selection = [
    'mode' => 'both',
    'online_channel' => 'Google Meet',
    'online_days' => ['mon','wed','fri'],
    'online_message' => 'Link enviado somente após confirmação.',
    'presencial_location' => 'Rua Exemplo, 100, Centro',
    'presencial_days' => ['tue','thu'],
    'presencial_message' => 'Presencial em nossa unidade, terças e quintas.',
];
$original = ['payment' => ['enabled' => true,'value_text' => 'R$ 180,00'],
    'modalities'=>['online'=>['enabled'=>false], 'presencial'=>['enabled'=>false]]];
$written = Modality::applyToBehavior($original, $selection);
verify(($written['modalities']['online']['allowed_days'] ?? null) === ['mon','wed','fri'], 'Dias online não persistidos.');
verify(($written['modalities']['presencial']['allowed_days'] ?? null) === ['tue','thu'], 'Dias presenciais não persistidos.');
verify(Modality::fromBehavior($written) === $selection, 'Round trip de modalidades falhou.');
verify(($written['payment']['value_text'] ?? '') === 'R$ 180,00', 'Salvamento alterou preço.');

$behavior = new Behavior();
$slots = [
    ['starts_at'=>'2026-10-19 08:00:00','source'=>'internal_published','id'=>1], // segunda
    ['starts_at'=>'2026-10-20 08:00:00','source'=>'internal_published','id'=>2], // terça
];
$online = $behavior->filterSlotsWithSettings($written, ['appointment_modality'=>'online','timezone'=>'America/Sao_Paulo'], $slots);
$presencial = $behavior->filterSlotsWithSettings($written, ['appointment_modality'=>'presencial','timezone'=>'America/Sao_Paulo'], $slots);
verify(array_column($online,'id') === [1], 'Online ofereceu dia presencial (inclusive vaga publicada).');
verify(array_column($presencial,'id') === [2], 'Presencial ofereceu dia online.');

$payload = [
    'business_info'=>['agent_name'=>'Assistente Exemplo','segment'=>'psychology_clinic','tone_of_voice'=>'empathetic_and_welcoming'],
    'commercial_info'=>['pricing_strategy'=>'fixed_price','price_value'=>'R$ 180,00','payment_methods'=>['pix']],
    'triage_config'=>['collect_name'=>true,'collect_phone'=>true,'custom_questions'=>[]],
    'routing_and_schedule'=>['timezone'=>'America/Sao_Paulo','business_hours'=>[['day'=>'monday','start'=>'08:00','end'=>'17:00']],
        'out_of_hours_action'=>'capture_lead_and_promise_contact','default_meeting_link'=>null],
    'knowledge_base'=>['faq'=>[]],
    'capabilities'=>['allow_rescheduling'=>true,'escalate_to_human_on_error'=>true,'send_meeting_link_on_confirmation'=>false],
    'service_modalities'=>$selection,
];
verify(Request::validate($payload)->serviceModalities['online_days'] === ['mon','wed','fri'], 'DTO perdeu dias online.');
foreach ([['mon','mon'],['wrong'],['sun',15]] as $bad) {
    $copy = $payload;
    $copy['service_modalities']['online_days'] = $bad;
    try { Request::validate($copy); } catch (ExpressValidationException $e) {
        verify(count(array_filter(array_keys($e->errors), static fn (string $k): bool => str_starts_with($k, 'service_modalities.online_days'))) > 0, 'Erro errado para dias inválidos.');
        continue;
    }
    throw new RuntimeException('Dias online inválidos aceitos.');
}
// Payload legado opcional (antes de existir online_days) preserva comportamento.
$legacy = $payload;
unset($legacy['service_modalities']['online_days']);
verify(Request::validate($legacy)->serviceModalities['online_days'] === [], 'Compatibilidade da API quebrada.');

$guard = file_get_contents(dirname(__DIR__, 2) . '/app/Services/CalendarConversationService.php');
$fallback = file_get_contents(dirname(__DIR__, 2) . '/app/Services/AiResponseContractService.php');
$calendar = new \App\Services\CalendarConversationService();
$intentMethod = new ReflectionMethod($calendar, 'isNewSchedulingRequest');
verify($intentMethod->invoke($calendar, 'Gostaria de agendar uma consulta para o dia 17/10.'), 'Pedido por data deve seguir para busca.');
verify($intentMethod->invoke($calendar, 'Quero marcar amanhã pela manhã.'), 'Pedido por período deve seguir para busca.');
verify(!$intentMethod->invoke($calendar, 'Confirmo minha reserva às 10h.'), 'Confirmação não pode ser tratada como busca nova.');
verify(!$intentMethod->invoke($calendar, 'Sim'), 'Resposta afirmativa isolada não é busca nova.');
verify(str_contains($guard, "'new_scheduling_request'"), 'Pedido inicial de agenda deve passar pelo fluxo de consulta.');
verify(str_contains($guard, '$requiresModality && !in_array($modality'), 'A confirmação ainda exige modalidade independentemente da Ordem.');
verify(str_contains($fallback, '$requiresModality && !in_array($modality'), 'Fallback ainda exige modalidade desmarcada.');

echo "OK - dias por modalidade, contrato, escolha e proteções de perguntas.\n";
