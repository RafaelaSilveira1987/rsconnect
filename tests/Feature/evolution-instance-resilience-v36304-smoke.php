<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static fn (string $path): string => is_file($root . '/' . $path) ? (string) file_get_contents($root . '/' . $path) : '';
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$migration = $read('database/migrations/108_evolution_instance_resilience.sql');
$manifest = $read('database/migrations/manifest.php');
$safety = $read('app/Services/EvolutionInstanceSafetyService.php');
$evolution = $read('app/Services/EvolutionService.php');
$instanceController = $read('app/Controllers/InstanceController.php');
$webhookController = $read('app/Controllers/EvolutionWebhookController.php');
$operations = $read('app/Services/OperationsService.php');
$view = $read('app/Views/instances/index.php');
$js = $read('public/assets/js/app.js');
$css = $read('public/assets/css/app.css');
$version = $read('app/Services/AppVersionService.php');
$layout = $read('app/Views/layouts/app.php');
$docs = $read('docs/ATUALIZACAO-v36.30.4.md');

$check(str_contains($migration, 'authorized_phone') && str_contains($migration, 'auto_recovery_enabled') && str_contains($migration, 'identity_status'), 'Migration 108 não cria os campos de identidade/resiliência.');
$check(str_contains($manifest, "['sequence' => 115, 'file' => '108_evolution_instance_resilience.sql']"), 'Migration 108 não foi adicionada ao manifest oficial.');
$check(str_contains($safety, 'function phonesEquivalent') && str_contains($safety, "identity_status") && str_contains($safety, 'assertOutboundAllowedByConnection'), 'Serviço central de segurança da identidade está incompleto.');
$check(substr_count($evolution, 'EvolutionInstanceSafetyService::assertOutboundAllowedByConnection') >= 2, 'Texto e mídia não passam pelo bloqueio central de saída.');
$check(str_contains($webhookController, 'EvolutionInstanceSafetyService::assertInboundAllowed') && str_contains($webhookController, 'instance_identity_mismatch'), 'Webhook não bloqueia recepção quando há divergência de número.');
$check(str_contains($webhookController, 'persistObservedIdentity') && str_contains($webhookController, 'identity_mismatch'), 'Webhook não reconcilia a identidade do número conectado.');
$check(str_contains($instanceController, "'diagnose', 'recover'") && str_contains($instanceController, 'function diagnoseInstance') && str_contains($instanceController, 'function recoverInstance'), 'Ações Diagnosticar/Recuperar não foram implementadas.');
$check(str_contains($instanceController, 'authorized_phone') && str_contains($instanceController, 'auto_recovery_enabled'), 'Configuração da instância não persiste número autorizado/recuperação automática.');
$check(str_contains($operations, 'function recoverEvolutionInstances') && str_contains($operations, 'identity_mismatch') && str_contains($operations, 'logged_out') && str_contains($operations, 'qrcode'), 'Monitor não possui recuperação automática com exclusões de segurança.');
$check(str_contains($view, 'Número autorizado') && str_contains($view, 'Número conectado') && str_contains($view, 'Recuperar conexão') && str_contains($view, 'Diagnosticar'), 'Frontend de Instâncias não expõe os controles novos.');
$check(!str_contains($view, '<dt>Campanhas</dt>'), 'Métrica de Campanhas ainda aparece no card de Instâncias.');
$check(str_contains($js, "'auto_recovery_enabled'") && str_contains($js, "settingsField('authorized_phone')"), 'JavaScript não carrega os novos campos do drawer.');
$check(str_contains($css, 'RS Connect 36.30.4 — identidade e resiliência das conexões WhatsApp') && str_contains($css, '.instance-identity-grid'), 'CSS da identidade/resiliência não foi adicionado.');
$check(str_contains($version, "PACKAGE_LABEL = 'RS Connect 36.30.4 — Instâncias resilientes'") && str_contains($version, "REQUIRED_MIGRATION = '108_evolution_instance_resilience.sql'"), 'Versionamento/migration obrigatória incorretos.');
$check(str_contains($layout, 'app.css?v=36.30.4') && str_contains($layout, 'app.js?v=36.30.4'), 'Cache principal não foi atualizado para 36.30.4.');
$check(str_contains($docs, 'bloqueia novas mensagens recebidas') && str_contains($docs, 'bloqueia texto/mídia de saída'), 'Documentação não descreve o bloqueio bidirecional por divergência.');

if ($failures !== []) {
    fwrite(STDERR, "FAIL evolution-instance-resilience-v36304-smoke\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK evolution-instance-resilience-v36304-smoke\n";
