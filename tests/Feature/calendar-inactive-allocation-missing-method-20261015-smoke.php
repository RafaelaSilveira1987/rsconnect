<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/app/Services/InternalCalendarSlotService.php';

$reflection = new ReflectionClass(App\Services\InternalCalendarSlotService::class);
$src = (string) file_get_contents($root . '/app/Services/InternalCalendarSlotService.php');
$checks = [
    'método de reconciliação existe' => $reflection->hasMethod('releaseInactiveAllocations'),
    'método não está exposto publicamente' => $reflection->hasMethod('releaseInactiveAllocations') && $reflection->getMethod('releaseInactiveAllocations')->isPrivate(),
    'invocado no cálculo de capacidade' => str_contains($src, 'return $this->releaseInactiveAllocations($tenantId, $appointmentId);'),
    'somente estados inativos são selecionados' => str_contains($src, 'a.status IN ("cancelled","rejected","rescheduled")'),
    'status revisto após lock' => str_contains($src, 'SELECT status FROM calendar_appointments'),
    'bloqueio transacional do compromisso' => str_contains($src, 'LIMIT 1 FOR UPDATE'),
    'bloqueio do horário antes de liberar' => str_contains($src, '$this->lockSlot($pdo, $tenantId, $slotId)'),
    'status recalculado na mesma transação' => str_contains($src, '$this->syncSlotStatus($tenantId, $slotId, $pdo)'),
];
$errors = 0;
foreach ($checks as $name => $success) {
    echo ($success ? '[OK] ' : '[FAIL] ') . $name . PHP_EOL;
    if (!$success) $errors++;
}
exit($errors === 0 ? 0 : 1);
