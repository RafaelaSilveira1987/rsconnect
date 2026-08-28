<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$failures = [];
$passes = 0;

$check = static function (bool $condition, string $label) use (&$failures, &$passes): void {
    if ($condition) {
        echo "[OK] {$label}\n";
        $passes++;
        return;
    }

    echo "[FAIL] {$label}\n";
    $failures[] = $label;
};

require_once $root . '/app/Core/Autoloader.php';
App\Core\Autoloader::register($root . '/app');
App\Core\Autoloader::register($root . '/app');

$index = (string) file_get_contents($root . '/public/index.php');
$bootstrap = (string) file_get_contents($root . '/bootstrap.php');
$dockerfile = (string) file_get_contents($root . '/Dockerfile');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');
$dockerIgnore = (string) file_get_contents($root . '/.dockerignore');

$check(class_exists(App\Core\Router::class), 'Router é carregado pelo autoloader do projeto');
$check(class_exists(App\Core\Env::class), 'outras classes Core continuam carregando');
$check(str_contains($index, 'Autoloader::register($root') && str_contains($index, "class_exists(Router::class)"), 'front controller registra e valida o autoloader antes de despachar');
$check(str_contains($bootstrap, "vendor/autoload.php") && str_contains($bootstrap, "app/Core/Autoloader.php"), 'bootstrap aceita Composer e fallback interno');
$check(str_contains($dockerfile, "class_exists('App\\\\Core\\\\Router')"), 'build Docker valida a disponibilidade do Router');
$check(str_contains($index, 'Ocorreu um erro interno ao iniciar a aplicação.') && !str_contains($index, 'echo $exception'), 'falha precoce não expõe caminho ou stack trace');
$check(str_contains($dockerIgnore, '.env') && str_contains($dockerIgnore, 'storage/logs/*'), 'contexto Docker exclui segredos e arquivos de execução');
$check(str_contains($version, 'RS Connect 36.20.15.3'), 'versão do hotfix foi atualizada');

if ($failures !== []) {
    fwrite(STDERR, "\nFALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "\nResumo do hotfix de autoload: {$passes} verificações aprovadas.\n";
