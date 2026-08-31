<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$signup = file_get_contents($root . '/app/Services/PublicSignupService.php') ?: '';
$version = file_get_contents($root . '/app/Services/AppVersionService.php') ?: '';

$checks = [
    'nome do item sem caractere tipográfico de risco' => str_contains($signup, "'name' => 'RS Connect Plano Inicial'")
        && !str_contains($signup, "'name' => 'RS Connect — Plano Inicial'"),
    'nome do pagador normalizado' => str_contains($signup, 'private function asaasSafePersonName(string $name): string')
        && str_contains($signup, "preg_replace('/[^\\p{L}\\s]/u'"),
    'nome simples não é enviado ao Asaas' => str_contains($signup, "return count(\$parts) >= 2 ? \$name : '';"),
    'customerData é opcional e condicionado ao nome válido' => str_contains($signup, "if (\$payerName !== '')")
        && str_contains($signup, "\$payload['customerData'] = ["),
    'retry remove somente customerData no erro de name' => str_contains($signup, "str_contains(\$message, 'campo name')")
        && str_contains($signup, "unset(\$payload['customerData']);"),
    'versão atualizada' => str_contains($signup, "public const VERSION = '36.24.4';")
        && str_contains($version, 'RS Connect 36.24.4'),
];

$failed = [];
foreach ($checks as $label => $ok) {
    if (!$ok) {
        $failed[] = $label;
    }
}
if ($failed !== []) {
    fwrite(STDERR, 'Falhas: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}
echo "OK - compatibilidade do campo name no Checkout Asaas v36.24.4 validada.\n";
