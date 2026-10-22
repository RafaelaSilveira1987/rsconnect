<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$view = (string) file_get_contents($root . '/app/Views/onboarding/index.php');
$controller = (string) file_get_contents($root . '/app/Controllers/OnboardingController.php');
$guide = (string) file_get_contents($root . '/app/Services/OnboardingGuideService.php');
$routes = (string) file_get_contents($root . '/routes/web.php');
$css = (string) file_get_contents($root . '/public/assets/css/app.css');
$layout = (string) file_get_contents($root . '/app/Views/layouts/app.php');
$manifest = json_decode((string) file_get_contents($root . '/manifest.json'), true) ?: [];
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');

$checks = [];
$checks['cadastro não estica o preview recomendado e mantém ajustes opcionais no mesmo ritmo'] =
    str_contains($css, ".onboarding-segment-preview {\n  min-height: 0;")
    && str_contains($css, '.onboarding-company-setup > .onboarding-simple-details')
    && str_contains($css, '.onboarding-essential-card .onboarding-segment-preview');

$checks['SLA é opt-in e possui marcação explícita para ativar ou desativar'] =
    str_contains($view, 'name="sla_enabled" value="0"')
    && str_contains($view, 'name="sla_enabled" value="1" data-sla-enabled')
    && str_contains($view, 'Medir tempo da primeira resposta humana')
    && str_contains($view, 'data-sla-fields')
    && str_contains($view, "fields.hidden = !toggle.checked")
    && str_contains($view, "!empty(\$slaSettings['updated_at']) ? !empty(\$slaSettings['enabled']) : false");

$checks['assistente é preparado dentro do onboarding sem exigir a tela avançada'] =
    str_contains($view, 'action="<?= View::e(Router::url(\'/onboarding/agent\')) ?>"')
    && str_contains($view, 'Nome do assistente')
    && str_contains($view, 'Tom da conversa')
    && str_contains($view, 'WhatsApp usado pelo assistente')
    && str_contains($view, 'Configurações avançadas ficam para depois.')
    && str_contains($routes, "'/onboarding/agent'");

$checks['backend do onboarding preserva regras estruturadas e salva tom/canal'] =
    str_contains($controller, "profileForTenant(\$tenantId, false, \$pdo)")
    && str_contains($controller, "prompt_builder_json = :prompt_builder_json")
    && str_contains($controller, "'conversation_tone'")
    && str_contains($controller, 'Somente conexões ativas podem ser usadas nesta etapa.')
    && str_contains($controller, 'configurações estruturadas do RS Connect')
    && str_contains($controller, 'auto_reply_enabled)')
    && str_contains($controller, '"active", 1, 0)')
    && str_contains($controller, 'ai_agent_instance_bindings');

$checks['definição da etapa não orienta mais escrever prompt ou abrir Assistentes'] =
    str_contains($guide, "'title' => 'Preparar o assistente virtual'")
    && str_contains($guide, "'action_url' => '/onboarding?step=ai_agent'")
    && str_contains($guide, 'As regras e o fluxo já vêm das etapas anteriores.');

$checks['release 36.44.4 preserva migration atual e renova cache CSS'] =
    ($manifest['package_version'] ?? '') === '36.44.5'
    && ($manifest['database']['required_migration'] ?? '') === '126_appointment_triage_snapshot.sql'
    && ($manifest['compatibility']['previous_release']['package_version'] ?? '') === '36.44.4'
    && str_contains($version, 'RS Connect 36.44.4')
    && str_contains($layout, '/assets/css/app.css?v=36.44.5');

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FALHA] ') . $label . PHP_EOL;
}
if ($failed !== []) {
    fwrite(STDERR, "\nFALHAS:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "\nOK - refinamento da Ativação Assistida validado.\n";
