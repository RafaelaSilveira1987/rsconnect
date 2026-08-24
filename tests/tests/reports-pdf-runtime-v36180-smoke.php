<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/app/Services/SimplePdfDocument.php';
require_once $root . '/app/Services/ExecutiveReportPdfService.php';

use App\Services\ExecutiveReportPdfService;

$result = (new ExecutiveReportPdfService())->generate(
    'tenant',
    ['start' => '2026-08-01', 'end' => '2026-08-21'],
    [
        'metrics' => [
            'active_conversations' => 12,
            'conversations' => 8,
            'responded_conversations' => 11,
            'human_conversations' => 3,
            'human_replies' => 7,
            'first_responses_measured' => 10,
            'avg_first_response_seconds' => 85,
            'appointments' => 2,
            'appointment_success_rate' => 50,
            'attendance_rate' => 100,
            'appointments_completed' => 2,
            'ai_replies' => 19,
            'ai_share' => 73,
            'attention_conversations' => 1,
            'attention_human_conversations' => 1,
            'unread' => 2,
        ],
        'comparisons' => [],
        'byDay' => [['label' => '2026-08-21', 'total' => 12]],
        'teamPerformance' => [['label' => 'Equipe', 'conversations' => 3, 'total' => 7]],
    ],
    ['name' => 'Empresa Teste', 'report_title' => 'Relatório executivo'],
    ['overview', 'conversations', 'team']
);

$bytes = (string) ($result['bytes'] ?? '');
$summary = is_array($result['summary'] ?? null) ? $result['summary'] : [];
$checks = [
    'arquivo PDF válido' => str_starts_with($bytes, '%PDF-'),
    'conteúdo não vazio' => strlen($bytes) > 20000,
    'páginas registradas' => (int) ($summary['pages'] ?? 0) >= 1,
    'identidade preservada' => ($summary['identity'] ?? '') === 'Empresa Teste',
    'compatibilidade sem mbstring obrigatório' => str_contains((string) file_get_contents($root . '/app/Services/ExecutiveReportPdfService.php'), "function_exists('mb_strtoupper')")
        && str_contains((string) file_get_contents($root . '/app/Services/SimplePdfDocument.php'), "function_exists('mb_strlen')"),
];

$failures = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
if ($failures !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK - geração real do relatório PDF v36.18.0 validada.\n";
