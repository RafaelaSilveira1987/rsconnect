<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/app/Core/Autoloader.php';
App\Core\Autoloader::register($root . '/app');

use App\Services\OpenAiOrganizationUsageService;

$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);

$range = [
    'start_time' => strtotime('2026-08-01 00:00:00 UTC'),
    'end_time' => strtotime('2026-08-03 00:00:00 UTC'),
    'start_date' => '2026-08-01',
    'end_date' => '2026-08-02',
    'days' => 2,
    'label' => 'Teste',
];
$completions = [
    'data' => [
        [
            'start_time' => strtotime('2026-08-01 00:00:00 UTC'),
            'results' => [
                ['model' => 'gpt-5-mini', 'input_tokens' => 1000, 'output_tokens' => 250, 'input_cached_tokens' => 300, 'num_model_requests' => 4],
                ['model' => 'gpt-5', 'input_tokens' => 500, 'output_tokens' => 100, 'input_cached_tokens' => 0, 'num_model_requests' => 1],
            ],
        ],
        [
            'start_time' => strtotime('2026-08-02 00:00:00 UTC'),
            'results' => [
                ['model' => 'gpt-5-mini', 'input_tokens' => 800, 'output_tokens' => 200, 'input_cached_tokens' => 100, 'num_model_requests' => 3],
            ],
        ],
    ],
];
$costs = [
    'data' => [
        [
            'start_time' => strtotime('2026-08-01 00:00:00 UTC'),
            'results' => [
                ['line_item' => 'Responses API', 'amount' => ['value' => 0.0123, 'currency' => 'usd'], 'quantity' => 1],
            ],
        ],
        [
            'start_time' => strtotime('2026-08-02 00:00:00 UTC'),
            'results' => [
                ['line_item' => 'Responses API', 'amount' => ['value' => 0.0077, 'currency' => 'usd'], 'quantity' => 1],
            ],
        ],
    ],
];

$summary = (new OpenAiOrganizationUsageService())->summarize('month', $range, $completions, $costs);
$controller = $read('app/Controllers/OpenAiUsageController.php');
$view = $read('app/Views/openai_usage/index.php');
$env = $read('.env.example');
$version = $read('app/Services/AppVersionService.php');

$checks = [
    'soma tokens de entrada' => (int) ($summary['totals']['input_tokens'] ?? 0) === 2300,
    'soma tokens de saída' => (int) ($summary['totals']['output_tokens'] ?? 0) === 550,
    'soma tokens totais' => (int) ($summary['totals']['total_tokens'] ?? 0) === 2850,
    'soma tokens em cache' => (int) ($summary['totals']['cached_tokens'] ?? 0) === 400,
    'soma chamadas' => (int) ($summary['totals']['requests'] ?? 0) === 8,
    'soma custo oficial' => abs((float) ($summary['totals']['cost'] ?? 0) - 0.02) < 0.000001,
    'agrupa por modelo' => ($summary['models'][0]['model'] ?? '') === 'gpt-5-mini'
        && (int) ($summary['models'][0]['total_tokens'] ?? 0) === 2250,
    'mantém duas barras diárias' => count($summary['daily'] ?? []) === 2,
    'controller consulta serviço oficial' => str_contains($controller, 'OpenAiOrganizationUsageService')
        && str_contains($controller, 'refresh_usage'),
    'tela exibe consumo oficial' => str_contains($view, 'Consumo direto da OpenAI')
        && str_contains($view, 'Tokens totais')
        && str_contains($view, 'Custo oficial'),
    'admin key separada da chave de inferência' => str_contains($env, 'OPENAI_ADMIN_API_KEY=')
        && str_contains($env, 'OPENAI_USAGE_PROJECT_IDS='),
    'pacote atualizado' => (str_contains($version, 'RS Connect 36.16.2') || str_contains($version, 'RS Connect 36.16.3')),
];

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
if ($failed !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "OK - consumo oficial da OpenAI agregado e exibido no painel administrativo.\n";
