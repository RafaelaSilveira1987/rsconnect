<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$pdf = (string) file_get_contents($root . '/app/Services/ExecutiveReportPdfService.php');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');

$checks = [
    'logo RS Connect oficial usada no PDF' => str_contains($pdf, 'rs-connect-report-mark.jpg')
        && is_file($root . '/public/assets/img/rs-connect-report-mark.jpg'),
    'logo escura anterior não é mais referenciada' => !str_contains($pdf, 'rs-digital-lab-report.jpg'),
    'cabeçalho mantém relatório executivo' => str_contains($pdf, "'RELATÓRIO EXECUTIVO'"),
    'release r6 sem migration nova' => str_contains($version, 'RS Connect 36.15.1-r6')
        && str_contains($version, '075_scheduled_reports_and_deliveries.sql'),
];

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
if ($failed !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "OK - logo RS Connect do PDF v36.15.1-r6 validada.\n";
