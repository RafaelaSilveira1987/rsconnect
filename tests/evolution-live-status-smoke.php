<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$controller = (string) file_get_contents($root . '/app/Controllers/InstanceController.php');
$view = (string) file_get_contents($root . '/app/Views/instances/index.php');
$js = (string) file_get_contents($root . '/public/assets/js/app.js');
$service = (string) file_get_contents($root . '/app/Services/EvolutionService.php');

$checks = [
    'version_marker' => str_contains($controller, "'source_version' => '36.6.38-live-status'"),
    'null_state_forces_check' => str_contains($controller, '$hasRealState') && str_contains($controller, '$hasRealState && $isFresh'),
    'close_maps_disconnected' => str_contains($controller, <<<'TXT'
$mappedStatus = $connected ? 'connected' : ($pending ? 'pending' : 'disconnected')
TXT),
    'no_repeated_update_parameter' => !str_contains($controller, 'IF(:connected = 1') && !str_contains($controller, ':clear_qr_code = 1'),
    'manual_status_removed' => !str_contains($view, 'name="status" data-instance-field="status"'),
    'js_no_manual_status' => !str_contains($js, "field('status').value=button.dataset.status||'disconnected'"),
    'evolution_state_path' => str_contains($service, <<<'TXT'
$body['instance']['state']
TXT),
];

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
if ($failed !== []) {
    fwrite(STDERR, 'Falhas: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}

echo "OK - status Evolution é exclusivamente derivado da API.\n";
