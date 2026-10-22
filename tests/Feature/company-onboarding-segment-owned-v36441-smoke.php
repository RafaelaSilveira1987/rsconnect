<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$createForm = (string) file_get_contents($root . '/app/Views/companies/_create_form.php');
$companyController = (string) file_get_contents($root . '/app/Controllers/CompanyController.php');
$onboardingView = (string) file_get_contents($root . '/app/Views/onboarding/index.php');
$onboardingController = (string) file_get_contents($root . '/app/Controllers/OnboardingController.php');
$guide = (string) file_get_contents($root . '/app/Services/OnboardingGuideService.php');
$css = (string) file_get_contents($root . '/public/assets/css/app.css');
$manifest = (string) file_get_contents($root . '/manifest.json');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');
$layout = (string) file_get_contents($root . '/app/Views/layouts/app.php');

$checks = [];
$checks['RS Admin não escolhe segmento ou blueprint no cadastro inicial'] =
    !str_contains($createForm, 'name="business_niche_id"')
    && !str_contains($createForm, 'name="agent_blueprint_id"')
    && str_contains($createForm, 'Segmento definido pelo cliente');

$checks['tenant nasce sem segmento/modelo para decisão consciente da empresa'] =
    str_contains($companyController, 'NULL, NULL, NULL, :plan')
    && str_contains($companyController, 'O cliente definirá o segmento obrigatório no primeiro acesso.');

$checks['onboarding exige segmento estruturado e modelo inicial'] =
    str_contains($onboardingView, 'name="business_niche_id" required')
    && str_contains($onboardingView, 'name="agent_blueprint_id" required')
    && str_contains($onboardingView, 'Sem segmento não é possível avançar');

$checks['dados oficiais continuam protegidos e segmento saiu do bloco somente leitura'] =
    str_contains($onboardingView, 'Razão social')
    && str_contains($onboardingView, 'CNPJ/CPF')
    && !preg_match('/master-data-summary[\s\S]{0,1200}<span>Segmento<\/span>/', $onboardingView);

$checks['salvamento valida contato, nicho e compatibilidade do blueprint'] =
    str_contains($onboardingController, 'Informe pelo menos um contato da empresa')
    && str_contains($onboardingController, 'Escolha o segmento da empresa')
    && str_contains($onboardingController, 'não pertence ao segmento selecionado');

$checks['sem escolha manual o primeiro blueprint ativo do segmento é aplicado'] =
    str_contains($onboardingController, '$availableBlueprints[0]')
    && str_contains($onboardingController, 'applyBlueprint($tenantId, $blueprintId, $pdo, false)');

$checks['modelo existente não é reaplicado em salvamento simples'] =
    str_contains($onboardingController, '$currentBlueprintId !== $blueprintId || empty($profile[\'id\'])');

$checks['status cadastral não pode avançar sem nicho e blueprint'] =
    str_contains($guide, "(int) (\$tenant['business_niche_id'] ?? 0) < 1")
    && str_contains($guide, "(int) (\$tenant['agent_blueprint_id'] ?? 0) < 1")
    && str_contains($guide, "['company_profile']");

$checks['interface filtra modelos pelo segmento e escolhe recomendação'] =
    str_contains($onboardingView, 'data-onboarding-niche')
    && str_contains($onboardingView, 'data-onboarding-blueprint')
    && str_contains($onboardingView, 'syncBlueprints(true)')
    && str_contains($onboardingView, 'firstVisible');

$checks['layout da nova etapa possui tratamento responsivo'] =
    str_contains($css, 'RS Connect 36.44.1 — cadastro da empresa orientado por segmento')
    && str_contains($css, '.onboarding-segment-preview')
    && str_contains($css, '@media (max-width: 760px)')
    && str_contains($layout, '/assets/css/app.css?v=36.44.5');

$checks['release 36.44.1 publicada sem nova migration'] =
    str_contains($manifest, '"package_version": "36.44.5"')
    && str_contains($manifest, '"required_migration": "126_appointment_triage_snapshot.sql"')
    && str_contains($version, 'RS Connect 36.44.1 — Cadastro orientado por segmento')
    && str_contains($version, "REQUIRED_MIGRATION = '126_appointment_triage_snapshot.sql'");

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FALHA] ') . $label . PHP_EOL;
}
if ($failed !== []) {
    fwrite(STDERR, "\nFALHAS:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "\nOK - cadastro orientado por segmento validado.\n";
