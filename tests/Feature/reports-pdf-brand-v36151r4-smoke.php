<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$pdf = (string) file_get_contents($root . '/app/Services/ExecutiveReportPdfService.php');
$simple = (string) file_get_contents($root . '/app/Services/SimplePdfDocument.php');
$tenant = (string) file_get_contents($root . '/app/Services/TenantExecutiveReportService.php');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');

$checks = [
    'identidade RS Connect aplicada' => str_contains($pdf, "\$primary = '#2F80FF'")
        && str_contains($pdf, "\$secondary = '#7B3FF2'")
        && str_contains($pdf, "'CONNECT'")
        && str_contains($pdf, "'RELATÓRIO EXECUTIVO'"),
    'grade executiva 4x2' => str_contains($pdf, '$columns = 4')
        && str_contains($pdf, '$height = 76.0'),
    'primeira resposta usa linguagem natural' => str_contains($pdf, "'Não mensurado'")
        && str_contains($pdf, 'Nenhum ciclo com tempo disponível'),
    'atenção explica atendimento humano' => str_contains($tenant, "'attention_human_conversations'")
        && str_contains($pdf, 'attentionDetail('),
    'paginação depende da quantidade real de linhas' => str_contains($pdf, 'count($teamRows)')
        && str_contains($pdf, 'count($attentionRows)')
        && str_contains($pdf, 'count($source)'),
    'situação ganhou largura suficiente' => str_contains($pdf, '[185, 115, 60, 151]'),
    'rodapé usa azul e roxo da plataforma' => str_contains($simple, "'#2F80FF'")
        && str_contains($simple, "'#7B3FF2'"),
    'identidade r4 preservada em release posterior sem migration nova' => str_contains($version, 'RS Connect 36.15.1-r')
        && str_contains($version, '075_scheduled_reports_and_deliveries.sql'),
];

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
if ($failed !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "OK - identidade, textos e paginação do PDF v36.15.1-r4 validados.\n";
