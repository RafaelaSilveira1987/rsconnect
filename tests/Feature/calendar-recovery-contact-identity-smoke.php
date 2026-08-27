<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$failures = [];
$assert = static function (bool $condition, string $label) use (&$failures): void {
    if (!$condition) $failures[] = $label;
};

$availability = (string) file_get_contents($root . '/app/Services/CalendarAvailabilityService.php');
$reprocess = (string) file_get_contents($root . '/app/Services/AiReprocessService.php');
$calendarConversation = (string) file_get_contents($root . '/app/Services/CalendarConversationService.php');
$webhook = (string) file_get_contents($root . '/app/Controllers/EvolutionWebhookController.php');
$contactController = (string) file_get_contents($root . '/app/Controllers/ContactController.php');
$conversationController = (string) file_get_contents($root . '/app/Controllers/ConversationController.php');
$migration = (string) file_get_contents($root . '/database/migrations/059_contact_identity_confidence.sql');

$assert(str_contains($availability, 'recoverConversationalAvailability'), 'Agenda possui recovery de disponibilidade.');
$assert(str_contains($availability, 'calendar.availability_request_retried'), 'Agenda audita retry do mesmo request.');
$assert(str_contains($availability, 'a.availability_request_id = r.id'), 'Recovery processa somente request atual do pré-agendamento.');
$assert(str_contains($availability, 'a.availability_options_sent_at IS NULL'), 'Recovery não repete disponibilidade já comunicada.');
$assert(str_contains($reprocess, 'recoverConversationalAvailability'), 'Fila rápida executa recovery da agenda.');
$assert(str_contains($calendarConversation, "return \$this->result(false, 'stale_request')"), 'Retorno antigo não pode comunicar disponibilidade antiga.');

$assert(str_contains($webhook, 'whatsapp_name_seen_count'), 'Webhook rastreia consistência do nome WhatsApp.');
$assert(str_contains($webhook, "\$fromMe ? '' : \$pushName"), 'Mensagens fromMe não contaminam nome do contato com o proprietário do WhatsApp.');
$assert(str_contains($webhook, '$seen >= 2'), 'Um único pushName não é promovido para nome definitivo.');
$assert(str_contains($webhook, 'whatsapp_name_candidate = :candidate OR (name_source = "whatsapp" AND name = :candidate)'), 'Webhook detecta colisão do mesmo nome entre números.');
$assert(str_contains($webhook, "in_array(\$source, ['manual', 'legacy'], true)"), 'Webhook preserva nomes manuais/legados.');
$assert(str_contains($contactController, "'name_source' => \$name !== '' ? 'manual' : 'unknown'"), 'Cadastro manual marca origem do nome.');
$assert(str_contains($conversationController, 'name_source = :name_source'), 'Drawer da conversa marca nome editado como manual.');
$assert(str_contains($migration, 'whatsapp_name_candidate'), 'Migration 059 cria candidato de nome.');
$assert(str_contains($migration, 'whatsapp_name_seen_count'), 'Migration 059 cria contador de observações.');

if ($failures !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK - retorno da agenda recuperável e identidade automática do contato protegida.\n";
