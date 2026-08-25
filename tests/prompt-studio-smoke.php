<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Services\PromptStudioService;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

$root = dirname(__DIR__);
$migration = (string) file_get_contents($root . '/database/migrations/062_prompt_studio_and_versions.sql');
$view = (string) file_get_contents($root . '/app/Views/agents/index.php');
$controller = (string) file_get_contents($root . '/app/Controllers/PromptStudioController.php');
$agentController = (string) file_get_contents($root . '/app/Controllers/AgentController.php');
$appVersion = (string) file_get_contents($root . '/app/Services/AppVersionService.php');

$service = new PromptStudioService();
$result = $service->generate([
    'agent_name' => 'Digi',
    'role' => 'atendimento comercial',
    'objective' => 'Entender a necessidade e encaminhar oportunidades.',
    'audience' => 'clientes e interessados',
    'tone' => 'profissional e acolhedor',
    'services' => 'Automação de WhatsApp e criação de sites.',
    'lead_rules' => 'Pergunte a demanda principal e o melhor canal para retorno.',
    'customer_rules' => 'Use o histórico e não repita a triagem.',
    'handoff_rules' => 'Encaminhe quando o contato pedir uma pessoa.',
], ['name' => 'RS Automação Digital'], ['calendar_mode' => 'none', 'business_hours_enabled' => 1, 'ai_can_confirm' => 0]);

$assert(str_contains($result['prompt'], '# Identidade e papel'), 'Prompt deve ser estruturado por seções.');
$assert(str_contains($result['prompt'], 'RS Automação Digital'), 'Prompt deve usar o nome da empresa.');
$assert(str_contains($result['prompt'], 'não utiliza agenda'), 'Modo sem agenda deve impedir ofertas de horários.');
$assert(str_contains($result['prompt'], 'Use o histórico e não repita a triagem'), 'Clientes atuais devem preservar continuidade.');
$assert(is_array($result['warnings']), 'Geração deve retornar validações.');
$assert(str_contains($migration, 'ai_agent_prompt_versions'), 'Migration deve criar histórico de versões.');
$assert(str_contains($migration, 'ai_prompt_studio_drafts'), 'Migration deve criar rascunhos do Prompt Studio.');
$assert(str_contains($view, 'Criar instruções organizadas'), 'Tela deve oferecer geração guiada.');
$assert(str_contains($view, 'Histórico das instruções'), 'Tela deve mostrar histórico restaurável.');
$assert(str_contains($controller, 'prompt_studio.generated'), 'Geração deve ser auditada.');
$assert(str_contains($agentController, 'createVersion'), 'Criação e edição do agente devem versionar o prompt.');
$assert(str_contains($appVersion, '062_prompt_studio_and_versions.sql'), 'Painel deve exigir migration 062.');

if ($failures !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK - Prompt Studio, validação de conflitos e versionamento validados.\n";
