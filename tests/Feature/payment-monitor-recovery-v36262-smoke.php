<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$service = (string) file_get_contents($root . '/app/Services/OperationsService.php');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');

$checks = [
    'eventos financeiros limitados a gateways ativos' => str_contains($service, "WHERE pg.status = 'active'")
        && str_contains($service, 'LEFT JOIN payment_gateway_events e'),
    'última falha comparada com última confirmação por id' => str_contains($service, 'last_error_id')
        && str_contains($service, 'last_success_id')
        && str_contains($service, '$lastErrorId > $lastSuccessId'),
    'falhas antigas recuperadas não geram warning' => str_contains($service, "'status' => 'ok'")
        && str_contains($service, 'falha(s) histórica(s)')
        && str_contains($service, 'Nenhuma falha ativa.'),
    'falha sem sucesso posterior continua alertando' => str_contains($service, 'falha sem confirmação posterior'),
    'resolução manual reavalia imediatamente pagamentos' => str_contains($service, '$event === \'operations.alert.payments\'')
        && str_contains($service, '$this->recordCheck(\'payments\', \'Gateways e pagamentos\', $this->checkPayments())'),
    'versão do pacote atualizada' => str_contains($version, 'RS Connect 36.26.2'),
];

$failures = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
if ($failures !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK - recuperação do monitor financeiro validada na v36.26.2.\n";
