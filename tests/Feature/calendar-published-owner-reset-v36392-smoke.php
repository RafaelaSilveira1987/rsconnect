<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$pre = (string) file_get_contents($root . '/app/Services/PreSchedulingService.php');
$availability = (string) file_get_contents($root . '/app/Services/CalendarAvailabilityService.php');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');

$checks = [
    'descoberta publicada não depende do owner automático' => str_contains($availability, "pre_schedule_source'] ?? '')) !== 'manual'")
        && str_contains($availability, '$publishedDiscoveryUnbound'),
    'fallback sem owner existe para dados históricos' => str_contains($availability, '$publishedOwnerFilter > 0')
        && str_contains($availability, 'Defesa contra dados históricos'),
    'compatibilidade usa slots publicados quando cálculo vazio' => str_contains($availability, 'published_legacy_compatibility')
        && str_contains($availability, 'compatibilidade para horários publicados antes da 36.39.1'),
    'nova preferência libera slot publicado anterior' => str_contains($pre, 'releaseForAppointment($tenantId, $appointmentId)')
        && str_contains($pre, 'chosen_availability_slot_id = NULL'),
    'ativação publicada funciona por upsert' => str_contains($availability, 'INSERT INTO tenant_calendar_availability_settings')
        && str_contains($availability, 'ON DUPLICATE KEY UPDATE'),
    'diagnóstico registra janela e owner' => str_contains($availability, "'search_start_at' =>")
        && str_contains($availability, "'published_owner_filter' =>"),
    'versão 36.39.2 sem migration nova' => str_contains($version, 'RS Connect 36.39.2')
        && str_contains($version, "REQUIRED_MIGRATION = '122_calendar_client_communications.sql'"),
];

$failed=[];
foreach ($checks as $label=>$ok) {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) $failed[]=$label;
}
if ($failed) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}
echo "OK - 36.39.2 elimina filtros residuais da descoberta publicada.\n";
