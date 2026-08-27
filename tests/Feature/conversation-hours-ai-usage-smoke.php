<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$checks = [
    'routes/web.php' => ["/conversations/contact-lookup"],
    'app/Controllers/ConversationController.php' => ['public function contactLookup()', 'conversation_id'],
    'app/Views/conversations/index.php' => ['data-new-conversation-search', 'data-contact-lookup-url'],
    'app/Controllers/AgentController.php' => ['business_day_enabled', 'business_day_start', 'business_day_end'],
    'app/Views/agents/index.php' => ['business-hours-editor', 'business_day_enabled['],
    'app/Services/SubscriptionService.php' => ['usage_type = "auto_reply" AND plan_billable = 1 AND status = "success" AND delivery_status = "delivered"'],
    'app/Views/billing/subscription.php' => ['Como o uso da IA é contado', 'Informações processadas', 'Chamadas ao serviço de IA'],
    'app/Views/privacy/accept.php' => ['privacy-accept-checkbox'],
    'app/Views/contacts/index.php' => ['<svg viewBox="0 0 24 24"'],
];

foreach ($checks as $file => $needles) {
    $content = file_get_contents($root . '/' . $file);
    if ($content === false) {
        fwrite(STDERR, "Falha ao ler {$file}\n");
        exit(1);
    }
    foreach ($needles as $needle) {
        if (!str_contains($content, $needle)) {
            fwrite(STDERR, "Ausente em {$file}: {$needle}\n");
            exit(1);
        }
    }
}

$css = file_get_contents($root . '/public/assets/css/app.css');
foreach (['.business-hours-editor', '.new-conversation-search-results', '.client-ai-telemetry-details'] as $selector) {
    if ($css === false || !str_contains($css, $selector)) {
        fwrite(STDERR, "CSS ausente: {$selector}\n");
        exit(1);
    }
}

echo "OK - busca inicial, horários por dia e contabilização de IA alinhados.\n";
