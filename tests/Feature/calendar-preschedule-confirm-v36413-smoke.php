<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$view = (string) file_get_contents($root . '/app/Views/calendar_availability/index.php');
$service = (string) file_get_contents($root . '/app/Services/CalendarAvailabilityService.php');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');
$manifest = json_decode((string) file_get_contents($root . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);

$checks = [
    'vaga escolhida é fonte persistida para confirmação' => str_contains($service, "chosen_availability_slot_id")
        && str_contains($service, 'O horário escolhido não pôde ser revalidado')
        && str_contains($service, '$this->applySlot($tenantId, $appointmentId, $chosenSlot)')
        && str_contains($service, 'restoreSelectedSlotMetadata'),
    'busca manual não sobrescreve seleção existente' => str_contains($service, "\$origin === 'manual_panel'")
        && str_contains($service, 'preserved_selection')
        && str_contains($service, 'Confirme o agendamento ou libere o horário antes de fazer outra busca.'),
    'liberação limpa a escolha sem excluir o pré-agendamento' => str_contains($service, 'availability_selected_at = NULL')
        && str_contains($service, 'availability_selected_by = NULL')
        && str_contains($service, 'availability_selection_expires_at = NULL')
        && str_contains($service, 'Horário liberado. O pré-agendamento foi mantido'),
    'tela prioriza confirmação quando há slot persistido' => str_contains($view, '$hasChosenSlot = (int) ($appointment[\'chosen_availability_slot_id\'] ?? 0) > 0')
        && str_contains($view, "\$statusText = \$hasChosenSlot")
        && str_contains($view, 'Liberar horário')
        && str_contains($view, 'Confirmar agendamento'),
    'busca só reaparece sem slot escolhido' => str_contains($view, '<?php if ($hasChosenSlot): ?>')
        && str_contains($view, '<?php else: ?>')
        && str_contains($view, 'Buscar disponibilidade'),
    'versão atual é 36.41.4' => str_contains($version, 'RS Connect 36.41.4')
        && str_contains($version, 'RS Connect 36.41.3')
        && ($manifest['package_version'] ?? '') === '36.41.4',
    'sem migration nova' => ($manifest['database']['required_migration'] ?? '') === '123_published_slots_min_notice_policy.sql',
];

$failed = [];
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) {
        $failed[] = $label;
    }
}

if ($failed !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "OK - 36.41.3 preserva e revalida a vaga escolhida antes de confirmar o pré-agendamento.\n";
