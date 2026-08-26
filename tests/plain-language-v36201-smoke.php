<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static fn (string $file): string => (string) file_get_contents($root . '/' . $file);

$profitability = $read('app/Views/ai_profitability/index.php');
$usage = $read('app/Views/openai_usage/index.php');
$layout = $read('app/Views/layouts/app.php');
$javascript = $read('public/assets/js/app.js');
$language = $read('app/Services/OperationalLanguageService.php');
$version = $read('app/Services/AppVersionService.php');
$docs = $read('docs/guias/guia-linguagem-simples.md');

$checks = [
    'status financeiros são traduzidos no servidor' => str_contains($profitability, "'healthy' => 'Dentro do esperado'")
        && str_contains($profitability, "'attention' => 'Precisa de atenção'")
        && !str_contains($profitability, "View::e((string) (\$row['status']"),
    'metodologia não expõe nomes internos como explicação principal' => !str_contains($profitability, 'telemetria ai_usage_events')
        && !str_contains($profitability, 'snapshots mensais preservam'),
    'menus usam nomes simples' => str_contains($layout, 'Visão geral')
        && str_contains($layout, 'Uso e custo da IA')
        && str_contains($layout, 'Resultados por cliente')
        && str_contains($layout, 'Meios de pagamento'),
    'painel de consumo explica os números' => str_contains($usage, 'Quanto a inteligência artificial está sendo usada')
        && str_contains($usage, 'Dados registrados pelo RS Connect')
        && str_contains($usage, 'Limite e proteção de gasto'),
    'camada dinâmica traduz textos e atributos' => str_contains($javascript, 'RS Connect 36.20.1 — camada de linguagem simples')
        && str_contains($javascript, "['attention', 'Precisa de atenção']")
        && str_contains($javascript, "['healthy', 'Dentro do esperado']")
        && str_contains($javascript, 'translateAttributes')
        && str_contains($javascript, "'[placeholder], [title], [aria-label]'"),
    'erros operacionais recebem explicação amigável' => str_contains($language, 'os dados enviados não puderam ser processados')
        && str_contains($language, 'o WhatsApp está desconectado')
        && str_contains($language, 'dados de uso'),
    'versão e documentação foram atualizadas' => str_contains($version, 'RS Connect 36.20.1')
        && str_contains($version, 'linguagem simples e acessível')
        && str_contains($version, 'RS Connect 36.20.2')
        && str_contains($docs, 'Regra das três respostas')
        && str_contains($docs, 'pessoa adolescente'),
];

$failed = [];
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) $failed[] = $label;
}

exit($failed === [] ? 0 : 1);
