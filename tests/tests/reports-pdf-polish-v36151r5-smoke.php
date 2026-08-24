<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$pdf = (string) file_get_contents($root . '/app/Services/ExecutiveReportPdfService.php');
$simple = (string) file_get_contents($root . '/app/Services/SimplePdfDocument.php');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');

$checks = [
    'logo institucional incorporada ao PDF' => str_contains($pdf, 'rs-connect-report-mark.jpg')
        && str_contains($simple, 'public function jpeg(')
        && is_file($root . '/public/assets/img/rs-connect-report-mark.jpg'),
    'produto continua identificado como RS Connect' => str_contains($pdf, "'RS CONNECT'")
        && str_contains($pdf, "'RELATÓRIO EXECUTIVO'"),
    'zero de comparecimento usa linguagem natural' => str_contains($pdf, 'completedAppointmentsDetail(')
        && str_contains($pdf, 'Não há atividade no período')
        && !str_contains($pdf, "' concluído(s)'"),
    'zero de IA usa linguagem natural' => str_contains($pdf, 'Nenhuma resposta da IA no período'),
    'leitura rápida ganhou card executivo' => str_contains($pdf, "'#F8FBFF'")
        && str_contains($pdf, '$cardHeight'),
    'relatório curto equilibra agenda na página 2' => str_contains($pdf, '$pdf->pageCount() === 1 && $y > 470'),
    'rodapé estável sem caractere especial' => str_contains($simple, "'RS CONNECT  |  Página '"),
    'pacote r5 sem migration nova' => str_contains($version, 'RS Connect 36.15.1-r5')
        && str_contains($version, '075_scheduled_reports_and_deliveries.sql'),
];

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
if ($failed !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "OK - polimento final do PDF v36.15.1-r5 validado.\n";
