<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static fn (string $path): string => is_file($root . '/' . $path) ? (string) file_get_contents($root . '/' . $path) : '';
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

$css = $read('public/assets/css/app.css');
$js = $read('public/assets/js/app.js');
$view = $read('app/Views/instances/index.php');
$safety = $read('app/Services/EvolutionInstanceSafetyService.php');
$controller = $read('app/Controllers/InstanceController.php');
$version = $read('app/Services/AppVersionService.php');
$appLayout = $read('app/Views/layouts/app.php');
$guestLayout = $read('app/Views/layouts/guest.php');
$restrictedLayout = $read('app/Views/layouts/restricted.php');
$docs = $read('docs/ATUALIZACAO-v36.30.6.md');

$check(str_contains($css, '.message-error[hidden], .instance-identity-warning[hidden] { display: none !important; }'), 'Aviso hidden ainda pode ser exibido pelo CSS.');
$check(str_contains($js, 'numbersMatchExactly') && str_contains($js, '!healthyConnected'), 'Polling não reconcilia visualmente identidade/status saudável.');
$check(str_contains($view, 'EvolutionInstanceSafetyService::assess($instance)') && str_contains($view, 'data-instance-identity-warning'), 'View não deriva o estado visual da avaliação atual.');
$check(substr_count($safety, "\$assessment['status'] !== 'verified' && \$storedStatus === 'mismatch'") >= 2, 'Proteção não reconcilia mismatch armazenado quando a identidade atual já foi verificada.');
$check(str_contains($controller, '$storedIdentityStatus') && str_contains($controller, "(\$assessment['status'] ?? '') !== 'verified'"), 'Recuperação ainda pode bloquear por mismatch histórico já reconciliado.');
$check(str_contains($version, "PACKAGE_LABEL = 'RS Connect 36.30.6 — Correção do alerta de identidade'") && str_contains($version, "REQUIRED_MIGRATION = '109_evolution_instance_identity_cleanup.sql'"), 'Versionamento 36.30.6 incorreto.');
$check(str_contains($appLayout, 'app.css?v=36.30.6') && str_contains($appLayout, 'app.js?v=36.30.6'), 'Cache principal não foi atualizado.');
$check(str_contains($guestLayout, 'app.css?v=36.30.6') && str_contains($restrictedLayout, 'app.css?v=36.30.6'), 'Cache dos layouts secundários não foi atualizado.');
$check(str_contains($docs, 'Número verificado') && str_contains($docs, 'Nenhuma migration nova'), 'Documentação do hotfix está incompleta.');

require_once $root . '/app/Services/EvolutionInstanceSafetyService.php';
$svc = \App\Services\EvolutionInstanceSafetyService::class;
$assessment = $svc::assess(['authorized_phone' => '553287073537', 'profile_phone' => '553287073537']);
$check(($assessment['status'] ?? '') === 'verified', 'Números idênticos não foram avaliados como verified.');

if ($failures !== []) {
    fwrite(STDERR, "FAIL evolution-instance-identity-warning-v36306-smoke\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK evolution-instance-identity-warning-v36306-smoke\n";
