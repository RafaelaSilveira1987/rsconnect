<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/Core/Autoloader.php';
\App\Core\Autoloader::register(dirname(__DIR__, 2) . '/app');

use App\Services\AgentReadinessEvaluator;
use App\Services\AgentAutoFixService;
use App\Core\Router;

function must(bool $ok, string $description): void {
    if (!$ok) throw new RuntimeException($description);
}
function item(array $report, string $name): array {
    foreach ($report['checklist'] as $c) {
        if ($c['item'] === $name) return $c;
    }
    throw new RuntimeException('Item não encontrado: ' . $name);
}
$baseline = [
    'agent' => [
        'id'=>51,'name'=>'Vida Plena','status'=>'active','auto_reply_enabled'=>0,
        'business_hours_enabled'=>1,'business_timezone'=>'America/Sao_Paulo',
        'business_hours_json'=>'{"mon":[["08:00","18:00"]]}',
        'after_hours_message'=>'Registraremos seu pedido.',
    ],
    'profile'=>[
        'id'=>3,'blueprint_version_id'=>14,'status'=>'active',
        'config'=>['express'=>[
            'commercial'=>['pricing_strategy'=>'fixed_price','price_value'=>'R$ 250,00','payment_methods'=>['pix','credit_card']],
            'routing'=>['out_of_hours_action'=>'capture_lead_and_promise_contact',
                'default_meeting_link'=>'https://meet.google.com/abc-defg-hij'],
            'tools'=>['calendar.confirmed.meeting_link'=>['enabled'=>true]],
        ]],
    ],
    'channels'=>[['binding_status'=>'active','status'=>'connected','receive_messages'=>1,
        'webhook_enabled'=>1,'identity_status'=>'verified','authorized_phone'=>'5511999990000']],
    'credentials'=>123,
    'capabilities'=>['calendar.read'=>true,'calendar.human_approval'=>true,'calendar.confirmation_link'=>true],
    'triage_fields'=>[['field_key'=>'demand','label'=>'Demanda','prompt_text'=>'Qual é a demanda?',
        'active'=>1,'required_before_schedule'=>1,'required_for_completion'=>1]],
    'workflow'=>[
        ['step_key'=>'demand','label'=>'Coletar demanda','step_type'=>'collect','config'=>['field_keys'=>['demand']], 'active'=>1,'position'=>10],
        ['step_key'=>'calendar','label'=>'Consultar agenda','step_type'=>'action','config'=>['action_key'=>'calendar.read'], 'active'=>1,'position'=>20],
    ],
    'policies'=>[
        ['policy_key'=>'express.out_of_hours','policy_type'=>'string','value'=>'capture_lead_and_promise_contact','enabled'=>1,'action_key'=>'warn','customer_message'=>'Fora do horário'],
        ['policy_key'=>'express.fixed_price','policy_type'=>'string','value'=>'R$ 250,00','enabled'=>1,'action_key'=>'warn','customer_message'=>'O valor é rigorosamente R$ 250,00. Não invente preços nem negocie.'],
        ['policy_key'=>'express.payment_methods','policy_type'=>'string','value'=>'pix,credit_card','enabled'=>1,'action_key'=>'warn','customer_message'=>'Meios aprovados'],
    ],
    'pre_schedule_settings'=>['enabled'=>1,'require_human_approval'=>1,'ai_can_confirm'=>0],
    'calendar_availability_settings'=>['enabled'=>1],
];
$engine = new AgentReadinessEvaluator();
$report = $engine->evaluate($baseline);
must($report['can_operate'] && $report['readiness_score'] === 100, 'Caso íntegro deveria pontuar 100.');
must($report['status'] === 'READY_TO_OPERATE', 'Prontidão não pode ligar o agente.');
must(count($report['checklist']) === 10, 'Checklist deve ter dez itens estáveis.');

$broken = $baseline;
$broken['profile']['config']['express']['routing']['out_of_hours_action'] = null;
$result = $engine->evaluate($broken);
must(!$result['can_operate'] && $result['readiness_score'] === 88, 'Erro crítico deve bloquear mesmo com score 88.');
must(item($result,'Ação fora do expediente')['auto_fix_action'] === 'set_default_out_of_hours_action', 'Auto-fix fora de expediente não oferecido.');
$broken['profile']['config']['express'] = [];
must(item($engine->evaluate($broken),'Ação fora do expediente')['auto_fix_available'] === false,
    'Não oferecer correção que não pode ser aplicada.');

$broken = $baseline;
$broken['profile']['config']['express']['routing']['default_meeting_link'] = '';
$result = $engine->evaluate($broken);
must($result['can_operate'] && $result['readiness_score'] === 96, 'Aviso alto não deve bloquear operação.');
must(item($result,'Link de reunião')['auto_fix_action'] === 'disable_meeting_link_capability', 'Auto-fix link ausente.');
$broken['capabilities']['calendar.confirmation_link'] = false;
$broken['profile']['config']['express']['tools']['calendar.confirmed.meeting_link']['enabled'] = false;
must($engine->evaluate($broken)['readiness_score'] === 100, 'Capability desativada não deve exigir link.');

$broken = $baseline;
$broken['channels'][0]['status'] = 'disconnected';
must(!$engine->evaluate($broken)['can_operate'], 'WhatsApp desconectado deve bloquear.');
$broken = $baseline;
$broken['pre_schedule_settings']['ai_can_confirm'] = 1;
must(item($engine->evaluate($broken), 'Agenda e aprovação humana')['status'] === 'error',
    'Aprovação humana não deve coexistir com confirmação automática.');
$broken = $baseline;
$broken['workflow'][0]['position'] = 30;
must(item($engine->evaluate($broken), 'Execução do fluxo e políticas')['status'] === 'error',
    'Coleta obrigatória após agenda deve bloquear.');
$broken = $baseline;
$broken['agent']['business_hours_json'] = '{"mon":[["18:00","08:00"]]}';
must(item($engine->evaluate($broken), 'Horário e fuso operacional')['status'] === 'error',
    'Intervalo de expediente invertido deve bloquear.');
$broken = $baseline;
$broken['profile']['config']['express']['commercial']['price_value'] = 'grátis';
must(!$engine->evaluate($broken)['can_operate'] && item($engine->evaluate($broken), 'Política comercial')['status'] === 'error',
    'Preço fixo divergente deve bloquear a operação.');
$broken = $baseline;
$broken['agent']['auto_reply_enabled'] = 1;
must($engine->evaluate($broken)['status'] === 'ACTIVE', 'Status ativo só para auto_reply_enabled.');

try { (new AgentAutoFixService())->apply(1, 1, 'drop_database'); }
catch (RuntimeException $exception) { must($exception->getCode() === 422, 'Ação desconhecida deve retornar 422.'); $rejected = true; }
must(!empty($rejected), 'Ação não autorizada jamais chega ao banco.');

$router = new Router();
$router->get('/api/v1/agents/{id}/readiness', static fn () => null);
$reflection = new ReflectionMethod(Router::class, 'matchParameterizedRoute');
$matched = $reflection->invoke($router, 'GET', '/api/v1/agents/1234-uuid/readiness');
must(is_array($matched) && Router::routeParam('id') === '1234-uuid', 'Rota parametrizada não reconhecida.');
must($reflection->invoke($router, 'GET', '/api/v1/agents/1234-uuid/autofix') === null,
    'Rota não deve reconhecer outro endpoint.');

echo "OK: fase 2 pontuação, bloqueios críticos, warnings, auto-fix allowlist e roteamento.\n";
