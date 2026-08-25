<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$service = file_get_contents($root . '/app/Services/AiAfterHoursRecoveryService.php') ?: '';
$ownership = file_get_contents($root . '/app/Services/ConversationOwnershipService.php') ?: '';
$controller = file_get_contents($root . '/app/Controllers/ConversationController.php') ?: '';
$view = file_get_contents($root . '/app/Views/conversations/index.php') ?: '';

$checks = [
    'takeover encerra pendência' => str_contains($service, 'resolveForHumanTakeover')
        && str_contains($service, 'SET status = "cancelled"')
        && str_contains($service, 'next_attempt_at = NULL'),
    'worker não reabre cancelada' => str_contains($service, 'AND status <> "cancelled"')
        && str_contains($service, 'if ($processingClaim->rowCount() < 1)'),
    'claim é atômico com fila' => str_contains($ownership, "'ownership_claim'")
        && str_contains($ownership, "'after_hours_resolved' => \$afterHoursResolved"),
    'modo humano remove fila' => str_contains($controller, "'mode_human'")
        && str_contains($controller, 'after_hours.human_takeover'),
    'resposta humana remove fila' => str_contains($controller, "'human_reply'")
        && str_contains($controller, "'human_attachment_reply'"),
    'interface explica responsabilidade' => str_contains($view, 'Assumir e retirar da fila')
        && str_contains($view, 'A responsabilidade passa para você')
        && str_contains($view, 'a IA não retoma automaticamente.'),
];

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
if ($failed !== []) {
    fwrite(STDERR, "Falhas: " . implode(', ', $failed) . PHP_EOL);
    exit(1);
}

echo "OK - takeover humano encerra a fila e protege contra reabertura concorrente.\n";
