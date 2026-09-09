<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$health = (string) file_get_contents($root . '/app/Services/TenantHealthService.php');
$model = (string) file_get_contents($root . '/app/Services/AiModelService.php');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');

$checks = [
    'health reconhece chave OpenAI global' => str_contains($health, "Env::get('OPENAI_API_KEY'") || str_contains($health, "\\App\\Core\\Env::get('OPENAI_API_KEY'"),
    'health reconhece chave Gemini global' => str_contains($health, "GEMINI_API_KEY") && str_contains($health, "GOOGLE_GEMINI_API_KEY"),
    'health combina chave cadastrada e global' => str_contains($health, '$hasStoredCredential') && str_contains($health, '$hasGlobalCredential') && str_contains($health, '$hasCredential = $hasStoredCredential || $hasGlobalCredential'),
    'diagnóstico informa origem da chave' => str_contains($health, 'Origem do acesso à IA') && str_contains($health, 'Chave principal da RS Connect'),
    'modelo real mantém fallback global OpenAI' => str_contains($model, "Env::get('OPENAI_API_KEY', '')"),
    'versão do pacote atualizada' => str_contains($version, 'RS Connect 36.28.4'),
];

$failed = [];
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FALHA] ') . $label . PHP_EOL;
    if (!$ok) $failed[] = $label;
}

if ($failed !== []) {
    fwrite(STDERR, "\nFALHAS:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "\nOK - diagnóstico da chave global da RS Connect validado.\n";
