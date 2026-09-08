<?php

declare(strict_types=1);

if (!function_exists('mb_strtolower')) {
    function mb_strtolower(string $value, ?string $encoding = null): string { return strtolower($value); }
}
if (!function_exists('mb_substr')) {
    function mb_substr(string $value, int $start, ?int $length = null, ?string $encoding = null): string
    {
        return $length === null ? substr($value, $start) : substr($value, $start, $length);
    }
}

require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Services\PreSchedulingService;

$root = dirname(__DIR__, 2);
$availability = (string) file_get_contents($root . '/app/Services/CalendarAvailabilityService.php');
$pre = (string) file_get_contents($root . '/app/Services/PreSchedulingService.php');
$view = (string) file_get_contents($root . '/app/Views/calendar_availability/index.php');

$checks = [
    'modo interno força auto request ao salvar' => str_contains($availability, 'auto_request_on_pre_schedule = 1'),
    'modo interno ignora flag legado desligado' => str_contains($pre, "if (\$calendarSource !== 'internal' && empty(\$settings['auto_request_on_pre_schedule']))"),
    'busca interna sem vaga não vira falha técnica' => str_contains($availability, "'ok' => true") && str_contains($availability, "'available' => \$slots !== []"),
    'slot interno preserva modalidade solicitada' => str_contains($availability, "'modality' => \$this->normalizeModality(\$requestedModality)"),
    'tela religa consulta automática ao selecionar interno' => str_contains($view, "source === 'internal' && autoRequestToggle"),
];

$service = new PreSchedulingService();
$intent = $service->detectIntent('Pode ser na sexta as 10h', true);
$checks['continuação sexta às 10h mantém intenção'] = !empty($intent['has_intent']);
$checks['continuação sexta às 10h captura dia'] = str_contains(strtolower((string) ($intent['preferred_day'] ?? '')), 'sexta');
$checks['continuação sexta às 10h captura horário'] = (string) ($intent['preferred_time'] ?? '') !== '';

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
if ($failed !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

fwrite(STDOUT, "OK - agenda interna consulta automaticamente e preserva sexta às 10h/modalidade.\n");
