<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$appRoot = $projectRoot . '/app';
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($appRoot));
$failures = [];
$checked = 0;

foreach ($iterator as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
        continue;
    }

    $contents = file_get_contents($file->getPathname());
    if (!is_string($contents)) {
        continue;
    }

    preg_match_all("/->prepare\\(\\s*'((?:\\\\.|[^'\\\\])*)'\\s*\\)/s", $contents, $matches, PREG_OFFSET_CAPTURE);
    foreach ($matches[1] ?? [] as $match) {
        $sql = stripcslashes((string) ($match[0] ?? ''));
        preg_match_all('/(?<!:):([A-Za-z_][A-Za-z0-9_]*)/', $sql, $placeholderMatches);
        $placeholders = $placeholderMatches[1] ?? [];
        $duplicates = [];
        foreach (array_count_values($placeholders) as $placeholder => $count) {
            if ($count > 1) {
                $duplicates[] = $placeholder;
            }
        }
        $checked++;
        if ($duplicates === []) {
            continue;
        }

        $offset = (int) ($match[1] ?? 0);
        $line = substr_count(substr($contents, 0, $offset), "\n") + 1;
        $failures[] = sprintf(
            '%s:%d reutiliza placeholder nomeado em PDO nativo: %s',
            str_replace($projectRoot . '/', '', $file->getPathname()),
            $line,
            implode(', ', $duplicates)
        );
    }
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

$webhook = file_get_contents($projectRoot . '/app/Controllers/EvolutionWebhookController.php') ?: '';
if (!str_contains($webhook, ':candidate_observed') || !str_contains($webhook, ':candidate_promoted')) {
    fwrite(STDERR, "A correção dos placeholders do nome do contato não foi encontrada.\n");
    exit(1);
}

printf("[OK] %d consultas PDO estáticas sem placeholders nomeados reutilizados.\n", $checked);
