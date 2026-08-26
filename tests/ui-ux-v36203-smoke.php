<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static fn (string $file): string => (string) file_get_contents($root . '/' . $file);

$attention = $read('app/Views/ai_attention/index.php');
$credentials = $read('app/Views/ai_credentials/index.php');
$css = $read('public/assets/css/app.css');
$layout = $read('app/Views/layouts/app.php');
$version = $read('app/Services/AppVersionService.php');
$docs = $read('docs/guias/relatorio-ui-ux-v36.20.3.md');

$checks = [
    'acompanhamento possui grade e ações separadas' => str_contains($attention, 'attention-followup-grid')
        && str_contains($attention, 'attention-followup-actions')
        && str_contains($attention, 'Salvar próxima ação'),
    'credencial possui opção principal em card' => str_contains($credentials, 'ai-credential-access-grid')
        && str_contains($credentials, 'ai-credential-default-option')
        && str_contains($credentials, 'Usar como chave principal desta empresa'),
    'checkboxes têm tamanho protegido' => str_contains($css, 'input[type="checkbox"]')
        && str_contains($css, 'width: 18px !important')
        && str_contains($css, 'check-card'),
    'gavetas e barras de ação foram padronizadas' => str_contains($css, 'RS Connect 36.20.3')
        && str_contains($css, 'bottom: 0 !important')
        && str_contains($css, 'scrollbar-gutter: stable'),
    'formulários são responsivos' => str_contains($css, '@media (max-width: 900px)')
        && str_contains($css, 'grid-template-columns: 1fr !important'),
    'foco visível foi incluído' => str_contains($css, ':focus-visible')
        && str_contains($css, 'outline-offset: 2px'),
    'assets e versão foram atualizados' => str_contains($layout, 'app.css?v=36.20.3')
        && str_contains($layout, 'app.js?v=36.20.3')
        && str_contains($version, 'RS Connect 36.20.3'),
    'relatório de auditoria existe' => str_contains($docs, 'Problemas encontrados')
        && str_contains($docs, 'Regra para novas telas'),
];

$failed = [];
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) $failed[] = $label;
}
exit($failed === [] ? 0 : 1);
