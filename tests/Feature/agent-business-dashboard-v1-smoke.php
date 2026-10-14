<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/Core/Autoloader.php';
\App\Core\Autoloader::register(dirname(__DIR__, 2) . '/app');

$root = dirname(__DIR__, 2);
$read = static fn (string $p): string => (string) file_get_contents($root . '/' . $p);
$assert = static function (bool $ok, string $msg): void {
    if (!$ok) throw new RuntimeException($msg);
};
$routes = $read('routes/web.php');
$agents = $read('app/Controllers/AgentController.php');
$setup = $read('app/Controllers/ExpressSetupController.php');
$view = $read('app/Views/agents/business.php');
$js = $read('public/assets/js/agent-business.js');
$css = $read('public/assets/css/agent-business.css');
$simulation = $read('app/Controllers/AgentBusinessSimulationController.php');
$layout = $read('app/Views/layouts/app.php');
$express = $read('app/Controllers/ExpressOnboardingController.php');

$assert(str_contains($agents, 'businessDashboard()') && str_contains($agents, '!Auth::isSuperAdmin()'), 'Cliente deve ver o painel de negócios; admin mantém manutenção técnica.');
$assert(str_contains($setup, "'agents/business'") && str_contains($setup, "'agent_express/index'"), 'Wizard e painel devem compartilhar o mesmo payload.');
$assert(str_contains($view, "'primary_agent' => ") && str_contains($view, 'JSON_HEX_TAG'), 'Identidade principal e serialização segura exigidas.');
$assert(str_contains($view, 'role="tablist"') && str_contains($view, 'baReadinessList') && str_contains($view, 'baChatLog'), 'Abas, checklist e simulador precisam estar visíveis.');
$assert(!str_contains($view, 'System Prompt') && !str_contains($view, 'LLM') && !str_contains($view, 'Tokens'), 'Tela não pode expor linguagem técnica.');
$assert(str_contains($js, 'lastSaved') && str_contains($js, 'markDirty') && str_contains($js, 'beforeunload'), 'A tela precisa proteger alterações não salvas.');
$assert(str_contains($js, "!hasSaved || dirty || chatBusy") && str_contains($js, "await request(root.dataset.saveUrl, 'POST', snapshot)"), 'Simulação só deve usar regras persistidas.');
$assert(str_contains($js, "'simulate'" ) && str_contains($js, "'readiness'") && str_contains($js, "'autofix'"), 'Endpoints de teste e prontidão não foram integrados.');
$assert(str_contains($js, 'friendlyCheck') && str_contains($js, 'textContent') && !str_contains($js,'innerHTML') && !str_contains($js,'localStorage'), 'Texto do cliente deve ser sanitizado e não persistido em browser storage.');
$assert(str_contains($js, 'window.confirm(') && str_contains($js, 'auto_fix_action:item.auto_fix_action'), 'AutoFix precisa de autorização explícita por ação.');
$assert(str_contains($routes, "'/api/v1/agents/business-save'") && str_contains($routes, "'permission:agents.manage', 'csrf'"), 'Salvar com permissão e CSRF.');
$assert(str_contains($routes, "'/api/v1/agents/{id}/simulate'") && str_contains($routes, "'permission:agents.manage', 'csrf'"), 'Simulador protegido por sessão, permissão e CSRF.');
$assert(str_contains($simulation, "Auth::tenantId()") && str_contains($simulation, "PublicId::decode('agent'") && str_contains($simulation, '->turn($tenantId, $agentId') && !str_contains($simulation, "->sendMessage("), 'Simulação não deve permitir tenant no JSON ou disparar mensagens reais.');
$assert(str_contains($simulation, '32768') && str_contains($simulation, '1000') && str_contains($simulation, "'real'"), 'Simulador deve limitar payload, mensagem e modo.');
$assert(str_contains($layout, 'agent-business.css?v=1') && str_contains($layout, 'agent-business.js?v=1'), 'Assets devem estar ligados ao layout.');
$assert(str_contains($css, '@media(max-width:840px)') && str_contains($css, '.ba-layout'), 'Layout deve ser responsivo.');
$assert(str_contains($express, "Auth::can('onboarding.manage')") && str_contains($express, "Auth::can('agents.manage')"), 'Salvar deve validar ambas permissões aceitas pelas rotas.');

echo "OK: dashboard de negócios, isolamento por empresa, CSRF, prontidão e teste isolado (checagem estática).\n";
