<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Services\ConversationalSlotGridService;
use App\Services\CalendarConversationService;

$grid = new ConversationalSlotGridService();
$date = (new DateTimeImmutable('today', new DateTimeZone('America/Sao_Paulo')))->modify('+7 days')->format('Y-m-d');
$published = [
    ['id'=>11, 'starts_at'=>$date.' 08:00:00', 'ends_at'=>$date.' 08:50:00', 'status'=>'available', 'capacity_total'=>4, 'occupied_count'=>1, 'booked_count'=>1, 'held_count'=>0, 'modality'=>'presencial'],
    ['id'=>12, 'starts_at'=>$date.' 09:00:00', 'ends_at'=>$date.' 09:50:00', 'status'=>'booked', 'capacity_total'=>4, 'occupied_count'=>4, 'booked_count'=>4, 'held_count'=>0, 'modality'=>'presencial'],
    ['id'=>13, 'starts_at'=>$date.' 10:00:00', 'ends_at'=>$date.' 10:50:00', 'status'=>'blocked', 'capacity_total'=>4, 'occupied_count'=>0, 'booked_count'=>0, 'held_count'=>0, 'modality'=>'presencial'],
    ['id'=>14, 'starts_at'=>$date.' 11:00:00', 'ends_at'=>$date.' 11:50:00', 'status'=>'available', 'capacity_total'=>4, 'occupied_count'=>1, 'booked_count'=>1, 'held_count'=>0, 'modality'=>'presencial', 'unlinked_appointment_count'=>1],
    ['id'=>15, 'starts_at'=>$date.' 12:00:00', 'ends_at'=>$date.' 12:50:00', 'status'=>'available', 'capacity_total'=>4, 'occupied_count'=>0, 'booked_count'=>0, 'held_count'=>0, 'modality'=>'online'],
];
$eligible = [
    ['starts_at'=>$date.' 08:00:00', 'modality'=>'presencial', 'suggestion_position'=>1, 'raw_json'=>json_encode(['internal_slot_id'=>11,'remaining_capacity'=>3])],
];
$msg = $grid->format($published,$eligible,$date,true);
$checks = [
    'enumera somente oferta real' => str_contains($msg ?? '', '1. 08:00 — 3/4 vagas livres'),
    'lotado comprovado' => str_contains($msg ?? '', '09:00 — Lotado'),
    'bloqueado nao anunciado livre' => str_contains($msg ?? '', '10:00 — Bloqueado'),
    'conflito nao anunciado lotado' => str_contains($msg ?? '', '11:00 — Indisponível (em verificação)'),
    'modalidade diferente nao exibida' => !str_contains($msg ?? '', '12:00'),
    'nao exibe dados de pacientes' => !str_contains($msg ?? '', 'appointment_id'),
    'avisos sobre escolha' => str_contains($msg ?? '', 'Somente opções numeradas'),
];
$empty = $grid->format($published, [], $date, true);
$checks['sem ofertas nao permite escolha'] = str_contains($empty ?? '', 'Nenhuma opção desta consulta pode ser reservada agora.');
$checks['sem ofertas nao inventa vaga'] = !str_contains($empty ?? '', '1. 08:00');
$allBooked = $grid->format([ $published[1] ],[], $date,true);
$checks['sem ofertas ainda exibe lotacao comprovada'] = str_contains($allBooked ?? '', '09:00 — Lotado');
$otherModality = $grid->format($published, [], $date, true, 'presencial');
$checks['sem ofertas respeita modalidade solicitada'] = !str_contains($otherModality ?? '', '12:00');
$orphan = $grid->format($published, [['starts_at'=>$date.' 08:00:00','raw_json'=>'{}']], $date, true);
$checks['grade cai no formato legado se faltar id do slot'] = $orphan === null;
$many = [];
for ($i=0; $i<125; $i++) {
    $many[] = ['id'=>200+$i,'starts_at'=>$date.' '.sprintf('%02d:%02d', intdiv($i,12),($i%12)*5).':00','ends_at'=>$date.' 23:59:00','status'=>'booked','capacity_total'=>1,'occupied_count'=>1,'booked_count'=>1,'held_count'=>0, 'modality'=>'presencial'];
}
$truncated = $grid->format($many, [], $date, true);
$checks['grade longa nao finge estar completa'] = str_contains($truncated ?? '', 'Lista parcial: há mais horários.');
$checks['grade longa limita tamanho da mensagem'] = strlen((string) $truncated) < 3800;
$conversation = file_get_contents(dirname(__DIR__,2).'/app/Services/CalendarConversationService.php');
$checks['integrado nas respostas de alternativas'] = str_contains($conversation,'optionsMessage($settings, $appointment, $slots, $tenantId)');
$checks['integrado na indisponibilidade'] = str_contains($conversation,'render($tenantId, $appointment, [])');
foreach ($checks as $description => $passed) {
    if (!$passed) { fwrite(STDERR, "FALHOU: {$description}\n"); exit(1); }
}
echo 'OK - ' . count($checks) . " verificações de grade conversacional.\n";
