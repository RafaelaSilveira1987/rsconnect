<?php

declare(strict_types=1);

$root = dirname(__DIR__) . '/public/uploads';
if (!is_dir($root)) {
    fwrite(STDERR, "Pasta public/uploads não encontrada.\n");
    exit(2);
}

$allowedMime = [
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'webp' => 'image/webp',
];
$ignored = ['.htaccess', '.gitkeep'];
$problems = [];
$total = 0;

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);
$finfo = new finfo(FILEINFO_MIME_TYPE);

foreach ($iterator as $fileInfo) {
    if (!$fileInfo->isFile()) {
        continue;
    }

    $total++;
    $name = $fileInfo->getFilename();
    $relative = ltrim(str_replace($root, '', $fileInfo->getPathname()), DIRECTORY_SEPARATOR);
    if (in_array($name, $ignored, true)) {
        continue;
    }

    $extension = strtolower($fileInfo->getExtension());
    if (!isset($allowedMime[$extension])) {
        $problems[] = $relative . ' — extensão não permitida';
        continue;
    }

    $mime = strtolower((string) $finfo->file($fileInfo->getPathname()));
    $imageInfo = @getimagesize($fileInfo->getPathname());
    if ($mime !== $allowedMime[$extension] || !is_array($imageInfo) || strtolower((string) ($imageInfo['mime'] ?? '')) !== $mime) {
        $problems[] = $relative . ' — conteúdo não corresponde a uma imagem válida';
    }
}

echo "Arquivos verificados: {$total}\n";
if ($problems === []) {
    echo "[OK] Nenhum arquivo público inseguro foi encontrado.\n";
    exit(0);
}

fwrite(STDERR, "[ATENÇÃO] Arquivos que devem ser removidos ou convertidos:\n- " . implode("\n- ", $problems) . "\n");
exit(1);
