<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$view = (string) file_get_contents($root . '/app/Views/companies/settings.php');
$controller = (string) file_get_contents($root . '/app/Controllers/CompanyController.php');
$governance = (string) file_get_contents($root . '/app/Services/MessageGovernanceService.php');
$automation = (string) file_get_contents($root . '/app/Services/AiAutomationService.php');
$preScheduling = (string) file_get_contents($root . '/app/Services/PreSchedulingService.php');
$calendar = (string) file_get_contents($root . '/app/Services/CalendarConversationService.php');

$checks = [
    'cliente envia marcador de governança' => substr_count($view, 'name="message_governance_settings_submitted" value="1"') >= 2,
    'checkbox possui valor explícito 0 quando desmarcado' => substr_count($view, 'type="hidden" name="whatsapp_human_signature_enabled" value="0"') >= 2,
    'backend interpreta valor e não apenas presença do campo' => str_contains($controller, "(string) (\$_POST['whatsapp_human_signature_enabled'] ?? '0') === '1'"),
    'regra de identificação é centralizada' => str_contains($governance, 'public function whatsappSenderIdentificationEnabled(PDO $pdo, int $tenantId): bool'),
    'IA principal respeita a configuração da empresa' => str_contains($automation, 'whatsappSenderIdentificationEnabled($pdo, $tenantId)'),
    'pré-agendamento respeita a configuração da empresa' => substr_count($preScheduling, 'whatsappSenderIdentificationEnabled($pdo, $tenantId)') >= 2
        && str_contains($preScheduling, 'withAiWhatsappSignature($message, $senderDisplayName, $signatureEnabled)'),
    'agenda conversacional respeita a configuração da empresa' => str_contains($calendar, 'whatsappSenderIdentificationEnabled($guardPdo, $tenantId)')
        && str_contains($calendar, 'withAiWhatsappSignature($message, $senderDisplayName, $signatureEnabled)'),
    'pré-agendamento remove prefixo quando desativado' => str_contains($preScheduling, 'if (!$enabled)') && str_contains($preScheduling, "return trim(\$message);"),
    'agenda remove prefixo quando desativado' => str_contains($calendar, 'if (!$enabled)') && str_contains($calendar, "return trim(\$message);"),
];

$sample = "*IA - Rafa, Assistente da psicóloga Mariana Bernardes*\nAntes de consultar os horários, você prefere atendimento online ou presencial?";
$cleaned = preg_replace('/^\*?IA(?:\s+[^\n*-]+)?\s*-\s*[^\n*]+\*?\s*(?:\r?\n|$)/iu', '', trim($sample)) ?? $sample;
$checks['prefixo real do WhatsApp é removido quando desativado'] = trim($cleaned) === 'Antes de consultar os horários, você prefere atendimento online ou presencial?';

$failed = [];
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FALHA] ') . $label . "\n";
    if (!$ok) {
        $failed[] = $label;
    }
}

if ($failed !== []) {
    fwrite(STDERR, "\nFALHAS:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "\nOK - desativação da identificação do atendente validada em painel, IA e agenda.\n";
