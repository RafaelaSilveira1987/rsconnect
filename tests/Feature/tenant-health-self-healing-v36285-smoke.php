<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$selfHealing = $read('app/Services/TenantSelfHealingService.php');
$lifecycle = $read('app/Services/CalendarGoogleLifecycleService.php');
$health = $read('app/Services/TenantHealthService.php');
$controller = $read('app/Controllers/TenantHealthController.php');
$view = $read('app/Views/companies/health.php');
$routes = $read('routes/web.php');
$version = $read('app/Services/AppVersionService.php');

$assert(str_contains($selfHealing, "'calendar.integration' => 'Agenda'"), 'Agenda deve estar no catálogo central de auto-reparo.');
$assert(str_contains($selfHealing, 'runSafeRepairs'), 'Auto-reparo seguro deve ser reutilizável por diagnóstico/cron.');
$assert(str_contains($health, 'runSafeRepairs($tenantId, $source)'), 'Diagnóstico deve tentar normalizar estados seguros antes de medir.');
$assert(str_contains($health, 'calendarSourceSettings($tenantId)'), 'Diagnóstico da agenda deve respeitar a origem realmente selecionada.');
$assert(str_contains($health, "'Google/n8n' => 'Não utilizado nesta origem'"), 'Agenda interna não deve acusar dependência de Google/n8n.');
$assert(str_contains($lifecycle, 'releaseExpiredAvailabilitySlots'), 'Manutenção deve liberar holds vencidos presos na tabela de slots.');
$assert(str_contains($lifecycle, 'calendar_availability_slots') && str_contains($lifecycle, 'hold_expires_at <= NOW()'), 'Limpeza deve localizar holds vencidos reais.');
$assert(str_contains($controller, 'function repair()'), 'Painel deve possuir ação única de correção automática.');
$assert(str_contains($routes, "'/companies/health/repair'"), 'Rota de correção automática deve estar publicada.');
$assert(str_contains($view, 'Corrigir agora'), 'Tela deve oferecer Corrigir agora para problemas reparáveis.');
$assert(str_contains($view, 'Abrir configuração'), 'Problemas de configuração devem continuar levando à configuração, sem prometer auto-reparo impossível.');
$assert(str_contains($version, 'RS Connect 36.28.5') && str_contains($version, "Beta Comercial 1.8.3"), 'Identidade do pacote deve refletir a correção 36.28.5.');

if ($failures !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "OK - diagnóstico com auto-reparo seguro e agenda por origem real validados.\n");
