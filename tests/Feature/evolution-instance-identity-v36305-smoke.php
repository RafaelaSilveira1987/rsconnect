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

$layouts = [
    'app' => $read('app/Views/layouts/app.php'),
    'guest' => $read('app/Views/layouts/guest.php'),
    'restricted' => $read('app/Views/layouts/restricted.php'),
];
foreach ($layouts as $name => $layout) {
    $check(str_starts_with(ltrim($layout), '<?php'), "Layout {$name} ainda produz marcador antes do PHP.");
    $check(!str_starts_with(ltrim($layout), '/*'), "Layout {$name} ainda inicia com comentário CSS visível.");
}

$safety = $read('app/Services/EvolutionInstanceSafetyService.php');
$evolution = $read('app/Services/EvolutionService.php');
$controller = $read('app/Controllers/InstanceController.php');
$webhook = $read('app/Controllers/EvolutionWebhookController.php');
$view = $read('app/Views/instances/index.php');
$js = $read('public/assets/js/app.js');
$migration = $read('database/migrations/109_evolution_instance_identity_cleanup.sql');
$manifest = $read('database/migrations/manifest.php');
$version = $read('app/Services/AppVersionService.php');
$docs = $read('docs/ATUALIZACAO-v36.30.5.md');

$check(str_contains($safety, 'function normalizeObservedPhone') && str_contains($safety, 'function isPlausiblePhone'), 'Validação de telefone plausível não foi implementada.');
$check(str_contains($safety, "'ownerjid'") && str_contains($safety, "'connected_phone'"), 'Extração robusta dos campos de identidade está incompleta.');
$check(str_contains($evolution, 'function instanceDetails') && str_contains($evolution, '/instance/fetchInstances?instanceName='), 'Fallback fetchInstances não foi implementado.');
$check(str_contains($controller, 'resolveObservedConnectedPhone') && str_contains($controller, 'instanceDetails()'), 'Status/diagnóstico não usam o fallback de identidade.');
$check(str_contains($webhook, 'EvolutionInstanceSafetyService::extractConnectedPhone($data)'), 'Webhook ainda aceita número sem validação central.');
$check(str_contains($view, 'data-instance-authorized-phone') && str_contains($view, 'data-instance-connected-phone'), 'Frontend não expõe os campos para atualização em tempo real.');
$check(str_contains($js, 'authorizedValid') && str_contains($js, 'connectedValid') && str_contains($js, 'phone.length >= 10'), 'Frontend ainda pode exibir metadado curto como telefone.');
$check(str_contains($migration, "NOT REGEXP '^[0-9]{10,15}$'") && str_contains($migration, 'profile_phone = NULL'), 'Migration 109 não limpa identidades inválidas.');
$check(str_contains($manifest, "['sequence' => 116, 'file' => '109_evolution_instance_identity_cleanup.sql']"), 'Migration 109 não foi registrada no manifest.');
$check(str_contains($version, "PACKAGE_LABEL = 'RS Connect 36.30.5 — Correção de identidade das instâncias'") && str_contains($version, "REQUIRED_MIGRATION = '109_evolution_instance_identity_cleanup.sql'"), 'Versionamento 36.30.5 incorreto.');
$check(str_contains($layouts['app'], 'app.css?v=36.30.5') && str_contains($layouts['app'], 'app.js?v=36.30.5'), 'Cache principal não foi atualizado para 36.30.5.');
$check(str_contains($docs, 'fetchInstances') && str_contains($docs, 'status HTTP `200`'), 'Documentação 36.30.5 incompleta.');

// Teste comportamental sem banco: 200 não pode virar telefone e listas do fetchInstances devem ser aceitas.
require_once $root . '/app/Services/EvolutionInstanceSafetyService.php';
$service = \App\Services\EvolutionInstanceSafetyService::class;
$check($service::extractConnectedPhone(['status' => 200, 'state' => 'open']) === '', 'HTTP 200 foi interpretado como telefone.');
$check($service::normalizeObservedPhone('200') === '', 'Valor curto 200 foi aceito como telefone plausível.');
$check($service::extractConnectedPhone([['ownerJid' => '5532987654321@s.whatsapp.net']]) === '5532987654321', 'Lista de fetchInstances não teve ownerJid reconhecido.');
$check($service::extractConnectedPhone(['data' => ['instance' => ['number' => '5532999999999']]]) === '5532999999999', 'Número aninhado válido não foi reconhecido.');

if ($failures !== []) {
    fwrite(STDERR, "FAIL evolution-instance-identity-v36305-smoke\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK evolution-instance-identity-v36305-smoke\n";
