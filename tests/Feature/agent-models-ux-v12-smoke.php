<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$admin = (string) file_get_contents($root . '/app/Views/agent_blueprints/index.php');
$settings = (string) file_get_contents($root . '/app/Views/companies/settings.php');
$create = (string) file_get_contents($root . '/app/Views/companies/_create_form.php');
$layout = (string) file_get_contents($root . '/app/Views/layouts/app.php');
$css = (string) file_get_contents($root . '/public/assets/css/app.css');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');

$checks = [
    'menu usa linguagem simples' => str_contains($layout, 'Modelos por segmento'),
    'catálogo usa modelos de atendimento' => str_contains($admin, 'Modelos de atendimento') && str_contains($admin, 'Regras de segurança') && str_contains($admin, 'Ações permitidas'),
    'termos técnicos ficam em área avançada' => str_contains($admin, 'Configuração técnica') && str_contains($admin, 'Configuração avançada do modelo'),
    'empresa usa regras do assistente' => str_contains($settings, 'Como o assistente deve atender') && str_contains($settings, 'O que o assistente pode fazer'),
    'coleta usa linguagem operacional' => str_contains($settings, 'O que precisa ser perguntado') && str_contains($settings, 'Precisa estar preenchida antes de consultar a agenda'),
    'políticas usam linguagem comum' => str_contains($settings, 'Regras que o assistente deve respeitar') && str_contains($settings, 'O que fazer quando a regra for acionada'),
    'workflow é passo a passo' => str_contains($settings, 'Passo a passo que o assistente segue'),
    'histórico traduz decisões' => str_contains($settings, "'allow' => 'Permitido'") && str_contains($settings, "'block' => 'Bloqueado'"),
    'cadastro usa segmento e modelo' => str_contains($create, 'Segmento da empresa') && str_contains($create, 'Modelo de atendimento'),
    'estilo novo carregado' => str_contains($css, '.agent-models-hero') && str_contains($css, '.agent-rules-panel') && str_contains($css, '.agent-workflow-flow'),
    'pacote atualizado' => str_contains($version, 'RS Connect 36.28.2'),
];

$failed = [];
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FALHA] ') . $label . PHP_EOL;
    if (!$ok) $failed[] = $label;
}
if ($failed !== []) {
    fwrite(STDERR, "\nFALHAS:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "\nOK - interface dos modelos e regras do assistente validada em linguagem simples.\n";
