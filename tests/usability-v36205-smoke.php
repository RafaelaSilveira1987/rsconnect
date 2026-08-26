<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);

$layout = $read('app/Views/layouts/app.php');
$css = $read('public/assets/css/app.css');
$js = $read('public/assets/js/app.js');
$service = $read('app/Services/PageHelpService.php');
$onboarding = $read('app/Services/OnboardingGuideService.php');
$docs = $read('app/Views/docs/index.php');
$version = $read('app/Services/AppVersionService.php');

$checks = [
    'serviço de ajuda contextual existe' => str_contains($service, 'final class PageHelpService')
        && str_contains($service, "'/conversations'")
        && str_contains($service, "'/instances'")
        && str_contains($service, "'/openai-usage'"),
    'layout possui ajuda e acessibilidade' => str_contains($layout, 'data-context-help-drawer')
        && str_contains($layout, 'Pular para o conteúdo principal')
        && str_contains($layout, 'data-app-live-region'),
    'atalho e foco do painel foram implementados' => str_contains($js, "event.key === '?'")
        && str_contains($js, 'focusable()')
        && str_contains($js, 'panelFocusOrigins')
        && str_contains($js, "panel.setAttribute('aria-hidden', 'false')")
        && str_contains($js, 'aria-current'),
    'preferências de leitura existem' => str_contains($js, 'rs-reading-comfort')
        && str_contains($js, 'rs-reduce-motion')
        && str_contains($css, 'html.reading-comfort')
        && str_contains($css, 'html.reduce-motion'),
    'primeiros passos usa nomes simples' => str_contains($onboarding, 'Criar o assistente virtual')
        && str_contains($onboarding, 'Conexões do WhatsApp')
        && !str_contains($onboarding, 'Nenhuma instância WhatsApp cadastrada.'),
    'central de ajuda explica atalhos' => str_contains($docs, 'Atalhos e recursos para facilitar o uso')
        && str_contains($docs, '<kbd>Ctrl K</kbd>')
        && str_contains($docs, '<kbd>?</kbd>'),
    'assets e versão foram atualizados' => str_contains($layout, 'app.css?v=36.20.5')
        && str_contains($layout, 'app.js?v=36.20.5')
        && str_contains($version, 'RS Connect 36.20.5'),
];

$failed = [];
foreach ($checks as $label => $passed) {
    echo ($passed ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$passed) $failed[] = $label;
}

exit($failed ? 1 : 0);
