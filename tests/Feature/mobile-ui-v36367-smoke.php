<?php
$root = dirname(__DIR__, 2);
$errors = [];
$files = [
    'public/mobile-app/index.html',
    'public/mobile-app/ui-0.5.0.css',
    'public/mobile-app/ui-0.5.0.js',
    'public/mobile-app/icon.png',
    'public/mobile-app/splash.png',
];
foreach ($files as $file) {
    if (!is_file($root . '/' . $file)) {
        $errors[] = 'Arquivo ausente: ' . $file;
    }
}
$js = @file_get_contents($root . '/public/mobile-app/ui-0.5.0.js') ?: '';
$css = @file_get_contents($root . '/public/mobile-app/ui-0.5.0.css') ?: '';
$version = @file_get_contents($root . '/app/Services/AppVersionService.php') ?: '';
if (strpos($js, 'Mobile 0.5.0') === false) $errors[] = 'Versao mobile 0.5.0 nao encontrada no JS.';
if (strpos($css, '.svgico') === false || strpos($css, '.startup') === false) $errors[] = 'Identidade visual mobile incompleta no CSS.';
if (strpos($version, '36.36.7') === false && strpos($version, '36.36.9') === false && strpos($version, '36.36.10') === false && strpos($version, '36.36.11') === false) $errors[] = 'PACKAGE_LABEL mobile 0.5.0 ou hotfix compativel nao encontrado.';
if ($errors) {
    fwrite(STDERR, "FAIL mobile-ui-v36367-smoke\n - " . implode("\n - ", $errors) . "\n");
    exit(1);
}
echo "OK mobile-ui-v36367-smoke\n";
