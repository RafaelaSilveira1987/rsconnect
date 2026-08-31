<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$signup = file_get_contents($root . '/app/Services/PublicSignupService.php') ?: '';
$version = file_get_contents($root . '/app/Services/AppVersionService.php') ?: '';
$env = file_get_contents($root . '/.env.example') ?: '';

$checks = [
    'usa IP real atrás de proxy confiável' => str_contains($signup, 'RequestSecurity::clientIp()')
        && str_contains($signup, 'use App\\Core\\RequestSecurity;'),
    'falhas técnicas não entram no rate limit' => str_contains($signup, 'status IN ("started", "checkout_created", "checkout_completed")')
        && !str_contains($signup, 'SELECT COUNT(*) FROM public_signup_sessions'),
    'limites separados por e-mail e IP' => str_contains($signup, 'email_attempts')
        && str_contains($signup, 'ip_attempts')
        && str_contains($signup, 'PUBLIC_SIGNUP_EMAIL_LIMIT_PER_HOUR')
        && str_contains($signup, 'PUBLIC_SIGNUP_IP_LIMIT_PER_HOUR'),
    'mensagens distinguem e-mail e rede' => str_contains($signup, 'Muitas inscrições iniciadas para este e-mail')
        && str_contains($signup, 'Muitas inscrições iniciadas nesta rede'),
    'variáveis documentadas' => str_contains($env, 'PUBLIC_SIGNUP_EMAIL_LIMIT_PER_HOUR=5')
        && str_contains($env, 'PUBLIC_SIGNUP_IP_LIMIT_PER_HOUR=20'),
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

echo "OK - rate limit seguro do cadastro público v36.24.4 validado.\n";
