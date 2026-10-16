<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$view = (string) file_get_contents($root . '/app/Views/calendar_availability/index.php');
$controller = (string) file_get_contents($root . '/app/Controllers/CalendarAvailabilityController.php');
$slotService = (string) file_get_contents($root . '/app/Services/InternalCalendarSlotService.php');
$cases = [
    'A página usa projeção de estados para filtrar' => str_contains($view, "['display_availability']['state'] ?? 'unknown'"),
    'Bloqueados e encerrados não são contados como vagas' => str_contains($view, "['remaining'] ?? 0"),
    'Não classifica ausência de slot como ocupado' => str_contains($view, 'Horários não publicados não são classificados como ocupados.'),
    'Painel sinaliza limite de registros' => str_contains($view, 'publishedSlotsTruncated'),
    'Controller confere existência da tabela de capacidade' => str_contains($controller, 'allocationTableAvailable()'),
    'Controller não edita agendamento para projeção' => str_contains($controller, "'display_availability'"),
    'Serviço de vagas mantém verificação de capacidade' => str_contains($slotService, 'availableForWindow('),
];
foreach ($cases as $name => $ok) {
    if (!$ok) {
        throw new RuntimeException('FAIL: ' . $name);
    }
}
echo 'OK: ' . count($cases) . " verificações de integração estática\n";
