<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$service = (string) file_get_contents($root . '/app/Services/ConversationOwnershipService.php');
$conversationController = (string) file_get_contents($root . '/app/Controllers/ConversationController.php');
$contactController = (string) file_get_contents($root . '/app/Controllers/ContactController.php');
$webhook = (string) file_get_contents($root . '/app/Controllers/EvolutionWebhookController.php');
$settingsView = (string) file_get_contents($root . '/app/Views/companies/settings.php');
$conversationView = (string) file_get_contents($root . '/app/Views/conversations/index.php');
$migration = (string) file_get_contents($root . '/database/migrations/064_professional_conversation_assignment_compat.sql');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');

$checks = [
    'recurso desativado por padrão' => str_contains($service, "'enabled' => false"),
    'atribuição automática desativada por padrão' => str_contains($service, "'auto_assign_enabled' => false")
        && str_contains($migration, 'professional_auto_assign_enabled TINYINT(1) NOT NULL DEFAULT 0'),
    'atribuição automática depende de opção explícita' => str_contains($service, '!$settings[\'auto_assign_enabled\']')
        && str_contains($webhook, 'autoAssignPreferred'),
    'bloqueio está no backend' => str_contains($conversationController, 'claimForHumanAction')
        && str_contains($conversationController, 'requireConversationInteraction'),
    'concorrência usa bloqueio de linha' => str_contains($service, 'FOR UPDATE'),
    'encerramento libera responsável' => str_contains($conversationController, 'releaseWhenClosed'),
    'contato aceita profissional preferido' => str_contains($contactController, 'preferred_user_id'),
    'interface permite assumir e transferir' => str_contains($conversationView, 'Assumir atendimento')
        && str_contains($conversationView, 'Transferir atendimento'),
    'configuração deixa automático opcional' => str_contains($settingsView, 'Opcional e desativado por padrão')
        || str_contains($settingsView, 'Opcional. Deixe desligado'),
    'versão e migration atualizadas' => str_contains($version, '36.12.1')
        && str_contains($version, '064_professional_conversation_assignment_compat.sql'),
];

$failures = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
if ($failures !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK - atendimento por profissional é opcional, bloqueado no backend e sem atribuição automática obrigatória.\n";
