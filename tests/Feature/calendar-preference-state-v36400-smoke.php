<?php

declare(strict_types=1);

if (!function_exists('mb_strtolower')) { function mb_strtolower(string $value, ?string $encoding = null): string { return strtolower($value); } }
if (!function_exists('mb_substr')) { function mb_substr(string $value, int $start, ?int $length = null, ?string $encoding = null): string { return $length === null ? substr($value, $start) : substr($value, $start, $length); } }
if (!function_exists('mb_strlen')) { function mb_strlen(string $value, ?string $encoding = null): int { return strlen($value); } }

require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Services\CalendarConversationService;
use App\Services\PreSchedulingService;
use App\Services\ExistingAppointmentConversationService;

$root = dirname(__DIR__, 2);
$pre = new PreSchedulingService();
$calendar = new CalendarConversationService();
$failures = [];
$check = static function (bool $ok, string $label) use (&$failures): void {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) $failures[] = $label;
};

$initial = $pre->detectIntent('Gostaria de saber se tem vaga para quinta pela manhã', false);
$check(!empty($initial['has_intent']), 'pedido com "vaga" abre intenção de agenda sem depender do nicho');
$check(($initial['preferred_day'] ?? '') === 'quinta-feira' && ($initial['preferred_time'] ?? '') === 'manhã', 'quinta pela manhã vira preferência canônica');

$age = $pre->detectIntent('Tenho 20 anos', true);
$check(($age['preferred_time'] ?? '') === '', 'idade numérica não é confundida com horário');
$check(empty($age['has_intent']), 'idade isolada não reabre agenda apenas por existir contexto de continuação');

$change = $pre->detectIntent('Quero trocar a modalidade', true);
$check(!empty($change['has_intent']) && !empty($change['modality_change_requested']), 'pedido de troca de modalidade é reconhecido');
$check(($change['location_type'] ?? '') === 'indefinida', 'troca sem destino aguarda nova escolha em vez de inventar modalidade');

$changeWithTarget = $pre->detectIntent('Na verdade prefiro presencial às 14h', true);
$check(($changeWithTarget['location_type'] ?? '') === 'presencial' && ($changeWithTarget['preferred_time'] ?? '') === '14:00', 'correção conjunta de modalidade e horário é reconhecida');

$directional = $pre->detectIntent('Quero trocar de presencial para online', true);
$check(($directional['location_type'] ?? '') === 'online', 'frase com modalidade de origem e destino usa a modalidade depois de para');

$resolve = new ReflectionMethod($calendar, 'resolveSelection');
$slots = [[
    'id' => 10,
    'starts_at' => '2026-10-01 14:00:00',
    'ends_at' => '2026-10-01 14:50:00',
    'modality' => 'online',
    'suggestion_position' => 1,
]];
$selection = $resolve->invoke($calendar, 'Na verdade prefiro presencial às 14h', $slots, ['appointment_modality' => 'online']);
$check(!empty($selection['new_preference']) && empty($selection['slot']), 'troca de modalidade nunca seleciona slot da modalidade antiga');

$selectionSame = $resolve->invoke($calendar, '14h', $slots, ['appointment_modality' => 'online']);
$check((int) ($selectionSame['slot']['id'] ?? 0) === 10, 'horário simples ainda seleciona opção quando modalidade não mudou');

$dateFromDay = new ReflectionMethod($pre, 'dateFromDay');
$tz = new DateTimeZone('America/Sao_Paulo');
$now = new DateTimeImmutable('2026-09-29 10:00:00', $tz); // terça-feira
$sameDay = $dateFromDay->invoke($pre, 'terça-feira', $now);
$check($sameDay->format('Y-m-d') === '2026-09-29', 'mesmo dia da semana considera hoje antes de pular sete dias');
$thursday = $dateFromDay->invoke($pre, 'quinta-feira', $now);
$check($thursday->format('Y-m-d') === '2026-10-01', 'quinta pedida na terça resolve para a quinta seguinte, sem reutilizar vagas da quarta');

$existing = new ExistingAppointmentConversationService();
$check($existing->detectIntent('Quero trocar de presencial para online') === 'modality_change', 'compromisso existente reconhece troca de modalidade como ajuste do próprio agendamento');

$preSource = (string) file_get_contents($root . '/app/Services/PreSchedulingService.php');
$triageSource = (string) file_get_contents($root . '/app/Services/AgentTriageService.php');
$check(str_contains($preSource, "serviceMode === 'not_applicable'") && str_contains($preSource, "serviceMode === 'single'"), 'configuração da empresa prevalece sobre modalidade digitada pelo contato');
$check(str_contains($triageSource, "serviceModeName === 'not_applicable'") && str_contains($triageSource, "serviceModeName === 'single'"), 'triagem usa a mesma política de modalidade da agenda');
$check(str_contains($triageSource, '$collected[\'modality\'] = $requestedModality'), 'destino explícito da troca de modalidade prevalece sobre palavras antigas citadas na frase');
$check(str_contains($preSource, 'syncTriageSchedulingState'), 'correções de agenda sincronizam o estado estruturado da conversa');

if ($failures !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK - 36.40.0 normaliza preferência e modalidade sem regras por nicho.\n";
