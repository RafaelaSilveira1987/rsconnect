<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);

$view = $read('app/Views/instances/index.php');
$css = $read('public/assets/css/app.css');
$js = $read('public/assets/js/app.js');
$layout = $read('app/Views/layouts/app.php');
$version = $read('app/Services/AppVersionService.php');
$instructions = $read('INSTRUCOES-v36.17.1.md');

$checks = [
    'drawer de criação identificado' => str_contains($view, 'instance-create-drawer')
        && str_contains($view, 'instance-create-form'),
    'etapas e cards de comportamento' => str_contains($view, 'instance-step-number')
        && str_contains($view, 'instance-choice-card')
        && str_contains($view, 'instance-advanced-section'),
    'mensagem de chamadas condicional' => str_contains($view, 'data-instance-reject-toggle')
        && str_contains($view, 'data-instance-reject-message-wrap')
        && str_contains($js, 'syncRejectMessage'),
    'contador de eventos' => str_contains($view, 'data-instance-event-count')
        && str_contains($js, 'syncEventCount'),
    'proteção contra duplo envio' => str_contains($js, "submit.classList.add('is-submitting')")
        && str_contains($js, "Criando conexão..."),
    'checkbox nativo corrigido' => str_contains($css, '.admin-form-drawer input[type="checkbox"]')
        && str_contains($css, '#instance-drawer .instance-choice-card > input'),
    'responsividade disponível' => str_contains($css, '@media (max-width: 760px)')
        && str_contains($css, '@media (max-width: 460px)'),
    'assets sem cache antigo' => preg_match('/app\.css\?v=36\.(17\.[12]|18\.[01234])/', $layout) === 1
        && preg_match('/app\.js\?v=36\.(17\.[12]|18\.[01234])/', $layout) === 1,
    'pacote identificado' => preg_match('/RS Connect 36\.(17\.[12]|18\.[01234])/', $version) === 1
        && str_contains($version, '077_ai_efficiency_foundation.sql'),
    'instruções incluídas' => str_contains($instructions, 'Não há migration nova nesta versão.'),
];

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
if ($failed !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "OK - layout de criação de instância v36.17.1 validado.\n";
