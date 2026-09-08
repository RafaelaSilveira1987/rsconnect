<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

$root = dirname(__DIR__, 2);
$pre = (string) file_get_contents($root . '/app/Services/PreSchedulingService.php');
$ai = (string) file_get_contents($root . '/app/Services/AiModelService.php');
$controller = (string) file_get_contents($root . '/app/Controllers/CompanyController.php');
$view = (string) file_get_contents($root . '/app/Views/companies/settings.php');
$migration = (string) file_get_contents($root . '/database/migrations/102_agenda_prompt_or_form_messages.sql');
$manifest = (string) file_get_contents($root . '/database/migrations/manifest.php');
$appVersion = (string) file_get_contents($root . '/app/Services/AppVersionService.php');

$checks = [
    'migration adiciona modo prompt/form' => str_contains($migration, "'message_mode'") && str_contains($migration, "enum('form','prompt')"),
    'migration adiciona mensagem inicial' => str_contains($migration, "'initial_collect_message'"),
    'manifest inclui migration 102' => str_contains($manifest, "102_agenda_prompt_or_form_messages.sql"),
    'versão exige migration 102' => str_contains($appVersion, "REQUIRED_MIGRATION = '102_agenda_prompt_or_form_messages.sql'"),
    'controller persiste origem das mensagens' => str_contains($controller, "'message_mode' => trim((string) (\$_POST['pre_schedule_message_mode']"),
    'controller persiste mensagem inicial' => str_contains($controller, "pre_schedule_initial_collect_message"),
    'tela oferece Prompt Studio' => str_contains($view, 'Prompt Studio — conversa natural'),
    'tela oferece formulário' => str_contains($view, 'Formulário — textos'),
    'tela permite configurar pergunta inicial' => str_contains($view, 'pre_schedule_initial_collect_message'),
    'fluxo delega coleta ao prompt quando selecionado' => str_contains($pre, "prompt_studio_message_needed") && str_contains($pre, "usesPromptStudioMessages"),
    'formulário usa pergunta inicial natural' => str_contains($pre, 'Qual o melhor dia e horário para você? E prefere atendimento online ou presencial?'),
    'ack antigo burocrático removido do serviço' => !str_contains($pre, 'Vou registrar sua preferência e encaminhar para confirmação da profissional.'),
    'prompt recebe regra específica de agenda' => str_contains($ai, 'As perguntas de coleta da agenda estão no modo Prompt Studio'),
    'prompt pode reunir duas perguntas relacionadas' => str_contains($ai, 'você pode reunir essas duas perguntas relacionadas em uma única mensagem curta'),
];

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
if ($failed !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "OK - agenda com Prompt Studio ou formulário configurável validada.\n";
