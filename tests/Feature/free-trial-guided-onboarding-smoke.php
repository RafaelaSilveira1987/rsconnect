<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$migration = (string) file_get_contents($root . '/database/migrations/060_free_trial_guided_first_access.sql');
$billing = (string) file_get_contents($root . '/app/Controllers/BillingController.php');
$access = (string) file_get_contents($root . '/app/Services/AccessControlService.php');
$trial = (string) file_get_contents($root . '/app/Services/FreeTrialService.php');
$onboarding = (string) file_get_contents($root . '/app/Services/OnboardingGuideService.php');
$router = (string) file_get_contents($root . '/app/Core/Router.php');
$view = (string) file_get_contents($root . '/app/Views/onboarding/index.php');
$layout = (string) file_get_contents($root . '/app/Views/layouts/app.php');

$assert(str_contains($migration, 'trial_end_behavior'), 'Migration deve criar comportamento pós-teste.');
$assert(str_contains($migration, 'tenant_onboarding_settings'), 'Migration deve criar configuração operacional pré-agente.');
$assert(str_contains($migration, 'onboarding_completed_at = COALESCE'), 'Migration deve preservar empresas que já operavam.');
$assert(str_contains($billing, "modify('+' . (\$trialDays - 1) . ' days')"), 'Fim do teste deve ser calculado pela duração.');
$assert(str_contains($billing, "modify('+1 day')->format('Y-m-d')"), 'Primeira cobrança deve ser posterior ao último dia gratuito.');
$assert(str_contains($billing, 'A empresa ainda está no período de teste gratuito'), 'Cobrança manual deve ser bloqueada no teste ativo.');
$assert(str_contains($access, 'trial_in_grace'), 'Acesso deve reconhecer tolerância pós-teste.');
$assert(str_contains($access, 'Durante o teste e a tolerância comercial'), 'Faturas anteriores não devem interromper a avaliação.');
$assert(str_contains($trial, "if (\$behavior === 'activate')"), 'Transição automática para ativo deve existir.');
$assert(str_contains($trial, "if (\$behavior === 'suspend')"), 'Suspensão pós-teste deve existir.');

$order = ['company_profile', 'lgpd_acceptance', 'attendance_rules', 'agenda_setup', 'whatsapp_connection', 'ai_agent', 'final_test'];
$last = -1;
foreach ($order as $step) {
    $position = strpos($onboarding, "['key' => '" . $step . "'");
    $assert($position !== false && $position > $last, 'Etapa fora da sequência: ' . $step);
    $last = $position === false ? $last : $position;
}
$assert(str_contains($onboarding, 'applyStoredAttendanceToAgent'), 'Regras operacionais devem ser aplicadas ao agente criado depois.');
$assert(str_contains($router, 'pathAllowedDuringOnboarding'), 'Router deve liberar telas progressivamente.');
$assert(str_contains($view, 'Etapa ainda bloqueada'), 'Interface deve explicar etapas ainda bloqueadas.');
$assert(str_contains($layout, 'Teste gratuito em andamento'), 'Cliente deve visualizar o período de avaliação.');

$start = new DateTimeImmutable('2026-07-28');
$end = $start->modify('+6 days');
$firstBilling = $end->modify('+1 day');
$assert($end->format('Y-m-d') === '2026-08-03', 'Sete dias devem terminar em 03/08/2026.');
$assert($firstBilling->format('Y-m-d') === '2026-08-04', 'Primeira cobrança deve ocorrer em 04/08/2026.');

if ($failures !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK - teste gratuito e primeiro acesso guiado validados.\n";
