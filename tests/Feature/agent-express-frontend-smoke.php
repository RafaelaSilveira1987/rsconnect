<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/Core/Autoloader.php';
\App\Core\Autoloader::register(dirname(__DIR__, 2) . '/app');

use App\Validation\ExpressOnboardingRequest;

function ensure(bool $ok, string $label): void
{
    if (!$ok) throw new RuntimeException($label);
}

$root = dirname(__DIR__, 2);
$routes = file_get_contents($root . '/routes/web.php');
$controller = file_get_contents($root . '/app/Controllers/ExpressSetupController.php');
$reader = file_get_contents($root . '/app/Services/ExpressBusinessConfigurationReader.php');
$view = file_get_contents($root . '/app/Views/agent_express/index.php');
$js = file_get_contents($root . '/public/assets/js/agent-express.js');
$css = file_get_contents($root . '/public/assets/css/agent-express.css');
$layout = file_get_contents($root . '/app/Views/layouts/app.php');

ensure(str_contains($routes, "'/onboarding/express'") && str_contains($routes, "'permission:onboarding.manage'"), 'Rota Expressa deve exigir permissionamento de onboarding.');
ensure(str_contains($routes, "'/api/agents/express'") && str_contains($routes, "'permission:onboarding.manage', 'csrf'"), 'Gravação Expressa deve exigir permissão e CSRF.');
ensure(str_contains($controller, 'Auth::tenantId()') && str_contains($reader, 'WHERE tenant_id=:tenant'), 'Prefill deve ser isolado por empresa no serviço de leitura.');
ensure(str_contains($reader, "PublicId::encode('agent'") && !str_contains($reader, 'beginTransaction('), 'GET apenas consulta o banco e entrega UUID público.');
ensure(str_contains($view, 'rexWizard') && str_contains($view, 'rexReadiness') && str_contains($view, 'rexAgentSelect'), 'Wizard e painel devem compartilhar a página.');
ensure(str_contains($view, 'JSON_HEX_TAG') && str_contains($view, 'Csrf::token()'), 'Pré-carregamento deve escapar HTML e conter CSRF.');
ensure(str_contains($js, 'class AgentWizard') && str_contains($js, 'class ReadinessDashboard'), 'Componentes JS ausentes.');
ensure(str_contains($js, "'POST', this.data") && str_contains($js, "apiUrl(id, 'readiness')"), 'Requisições devem usar endpoints existentes.');
ensure(str_contains($js, 'auto_fix_action: check.auto_fix_action') && str_contains($js, 'window.confirm('), 'Auto-fix deve ser explícito, autorizado e enviado como comando.');
ensure(str_contains($js, "report.can_operate === true"), 'Ativação só deve usar can_operate vindo do backend.');
ensure(!str_contains($js, 'localStorage') && !str_contains($js, 'eval('), 'Wizard não deve executar expressões ou armazenar configuração sensível no navegador.');
ensure(str_contains($css, '.rex-segment-grid') && str_contains($css, '@media(max-width:650px)'), 'CSS precisa conter cartões responsivos.');
ensure(str_contains($layout, 'agent-express.js?v=1'), 'Assets devem ser carregados no layout existente.');
ensure(!str_contains($view, 'System Prompt') && !str_contains($view, 'LLM') && !str_contains($view, 'Tokens'), 'Tela não pode expor termos técnicos.');

$payload = [
    'business_info'=>['agent_name'=>'Assistente Vida Plena','segment'=>'psychology_clinic','tone_of_voice'=>'empathetic_and_welcoming'],
    'commercial_info'=>['pricing_strategy'=>'fixed_price','price_value'=>'R$ 250,00','payment_methods'=>['pix','credit_card']],
    'triage_config'=>['collect_name'=>true,'collect_phone'=>true,'custom_questions'=>['É a sua primeira consulta?']],
    'routing_and_schedule'=>['timezone'=>'America/Sao_Paulo',
        'business_hours'=>[['day'=>'monday','start'=>'08:00','end'=>'12:00'],['day'=>'monday','start'=>'13:00','end'=>'18:00']],
        'out_of_hours_action'=>'capture_lead_and_promise_contact','default_meeting_link'=>'https://meet.google.com/abc-defg-hij'],
    'knowledge_base'=>['faq'=>[['question'=>'Atendem convênio?','answer'=>'Não, somente consultas particulares.']]],
    'capabilities'=>['allow_rescheduling'=>true,'escalate_to_human_on_error'=>true,'send_meeting_link_on_confirmation'=>true],
];
if (!extension_loaded('mbstring')) {
    echo "INFO: ext-mbstring indisponível neste CLI; teste de contrato do DTO não executado.\n";
} else {
$dto = ExpressOnboardingRequest::validate($payload);
ensure(count($dto->businessHours) === 2, 'Contrato Fase 1 não aceita duas faixas de atendimento.');
$payload['commercial_info'] = ['pricing_strategy'=>'variable_price','price_value'=>null,'payment_methods'=>[]];
ensure(ExpressOnboardingRequest::validate($payload)->pricingStrategy === 'variable_price', 'Estratégia variável inválida no contrato.');
}

echo "OK: front express integrado às rotas seguras, componente de prontidão e contrato JSON da Fase 1.\n";
