<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$controller = (string) file_get_contents($root . '/app/Controllers/CompanyController.php');
$view = (string) file_get_contents($root . '/app/Views/companies/overview.php');
$routes = (string) file_get_contents($root . '/routes/web.php');
$css = (string) file_get_contents($root . '/public/assets/css/app.css');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');
$layout = (string) file_get_contents($root . '/app/Views/layouts/app.php');

$checks = [
    'overview carrega SLA por empresa' => str_contains($controller, "'slaSettings' => (new SlaPolicyService())->settings(\$tenantId)"),
    'controller possui ação administrativa de SLA' => str_contains($controller, 'public function updateSla(): void') && str_contains($controller, 'company.sla_updated'),
    'salvamento reutiliza SlaPolicyService' => str_contains($controller, '$service = new SlaPolicyService($pdo);') && str_contains($controller, "'sla_target_minutes'") && str_contains($controller, "'sla_warning_percent'"),
    'rota SLA é exclusiva do super admin e protegida por csrf' => str_contains($routes, "'/companies/sla'") && str_contains($routes, "['auth', 'super_admin', 'csrf']"),
    'RS Admin exibe formulário de SLA' => str_contains($view, 'id="company-sla"') && str_contains($view, 'Salvar SLA da empresa'),
    'formulário permite meta alerta e relógio fora do expediente' => str_contains($view, 'name="sla_target_minutes"') && str_contains($view, 'name="sla_warning_percent"') && str_contains($view, 'name="sla_count_outside_hours"'),
    'tela mostra expediente e timezone utilizados' => str_contains($view, 'Expediente usado') && str_contains($view, '$slaBusinessHoursLabel') && str_contains($view, '$slaTimezone'),
    'atalho de SLA aparece na visão da empresa' => str_contains($view, 'href="#company-sla">SLA operacional</a>'),
    'layout SLA possui responsividade própria' => str_contains($css, '.admin-company-sla-summary') && str_contains($css, '@media (max-width: 680px)'),
    'pacote e cache identificam 36.36.13' => str_contains($version, 'RS Connect 36.36.13') && str_contains($layout, 'app.css?v=36.36.13'),
];

$failed = [];
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) $failed[] = $label;
}

if ($failed) {
    fwrite(STDERR, "\nFALHAS:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "\nOK - SLA por empresa disponível no RS Admin.\n";
