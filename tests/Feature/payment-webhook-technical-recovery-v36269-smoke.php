<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$service = (string) file_get_contents($root . '/app/Services/OperationsService.php');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');

$checks = [
    'webhook ignorado autenticado entra na confirmação técnica' => str_contains(
        $service,
        "e.event = CONCAT('payment.webhook.', pg.provider)"
    ) && str_contains($service, "AND e.status = 'ignored'"),
    'falhas continuam restritas a error e failed' => str_contains(
        $service,
        "e.status IN ('error','failed')"
    ),
    'comparação por ordem dos eventos foi preservada' => str_contains(
        $service,
        '$lastErrorId > 0 && $lastErrorId > $lastSuccessId'
    ),
    'mensagem diferencia comunicação autenticada de operação financeira' => str_contains(
        $service,
        'comunicação autenticada ou operação bem-sucedida'
    ),
    'pacote identifica a versão 36.26.9' => str_contains(
        $version,
        'RS Connect 36.26.9 — Recuperação técnica do webhook financeiro'
    ),
];

$failures = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
if ($failures !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK - recuperação técnica do webhook financeiro validada na v36.26.9.\n";
