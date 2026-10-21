<?php

declare(strict_types=1);

if (!function_exists('mb_strtolower')) {
    function mb_strtolower(string $value, ?string $encoding = null): string {
        return strtolower(strtr($value, ['É'=>'é','Á'=>'á','Í'=>'í','Ó'=>'ó','Ú'=>'ú','Ç'=>'ç','Ã'=>'ã','Õ'=>'õ']));
    }
}
require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Services\ExistingAppointmentConversationService;
use App\Services\ConversationalSlotGridService;

$service = new ExistingAppointmentConversationService();
$checks = [
    'resposta positiva da triagem não consulta compromissos existentes' => $service->detectIntent('sim, minha primeira consulta') === '',
    'resposta sem pontuação não consulta compromissos existentes' => $service->detectIntent('Sim minha primeira consulta') === '',
    'resposta por extenso não consulta compromissos existentes' => $service->detectIntent('É minha primeira consulta') === '',
    'resposta negativa da triagem não abre compromissos' => $service->detectIntent('Não, não é minha primeira consulta') === '',
    'consulta confirmada explicitamente continua consultável' => $service->detectIntent('Minha primeira consulta está confirmada?', false) === 'status',
    'consulta de status existente continua permitida' => $service->detectIntent('Gostaria de confirmar meu atendimento', false) === 'status',
    'cancelamento existente continua permitido' => $service->detectIntent('Quero cancelar minha primeira consulta') === 'cancel',
    'remarcação existente continua permitida' => $service->detectIntent('Preciso remarcar minha consulta') === 'reschedule',
];

$method = new ReflectionMethod($service, 'appointmentDisambiguationOptions');
$options = $method->invoke($service, [
    ['starts_at' => '2026-10-17 10:00:00', 'owner_name' => 'NomePrivado', 'contact_name' => 'OutroPaciente', 'location_type' => 'presencial'],
    ['starts_at' => '2026-10-17 09:00:00', 'owner_name' => 'NomePrivado', 'contact_name' => 'OutroPaciente', 'location_type' => 'online'],
]);
$checks['opções existentes não exibem nomes'] = !str_contains($options, 'NomePrivado') && !str_contains($options, 'OutroPaciente');
$checks['opções existentes preservam data e hora'] = str_contains($options, '10:00') && str_contains($options, '09:00');

$grid = new ConversationalSlotGridService();
$date = (new DateTimeImmutable('today', new DateTimeZone('America/Sao_Paulo')))->modify('+7 days')->format('Y-m-d');
$published = [];
$eligible = [];
foreach (['08:00', '09:00', '10:00', '11:00'] as $index => $time) {
    $id = $index + 1;
    $occupied = $time === '10:00' ? 1 : 0;
    $published[] = [
        'id' => $id, 'starts_at' => "$date $time:00", 'ends_at' => "$date 11:50:00",
        'status' => 'available', 'capacity_total' => 4, 'occupied_count' => $occupied,
        'booked_count' => $occupied, 'held_count' => 0, 'modality' => 'presencial',
        'contact_name' => 'NomePrivado', 'owner_name' => 'OutroPaciente',
    ];
    $eligible[] = [
        'starts_at' => "$date $time:00", 'modality' => 'presencial', 'suggestion_position' => $id,
        'raw_json' => json_encode(['internal_slot_id' => $id, 'remaining_capacity' => 4 - $occupied]),
    ];
}
$output = $grid->format($published, $eligible, $date, true, 'presencial');
$checks['grade mostra quatro horários quando quatro são elegíveis'] = $output !== null &&
    str_contains($output, '1. 08:00') && str_contains($output, '2. 09:00') &&
    str_contains($output, '3. 10:00 — 3/4 vagas livres') && str_contains($output, '4. 11:00');
$checks['grade não exibe dados pessoais'] = !str_contains($output ?? '', 'NomePrivado') && !str_contains($output ?? '', 'OutroPaciente');

$source = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Services/ExistingAppointmentConversationService.php');
$checks['seleção anterior é resolvida ao iniciar novo fluxo'] = str_contains($source, "markDisambiguationResolved(\$pdo, \$tenantId, \$conversationId, 0, 'new_booking')");
$checks['consulta restringe vínculo ao contato da conversa'] = str_contains($source, 'a.contact_id = :contact_id') && str_contains($source, 'c.contact_id = :conversation_contact_id');

foreach ($checks as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, 'FAIL: ' . $label . PHP_EOL);
        exit(1);
    }
    echo 'OK: ' . $label . PHP_EOL;
}
