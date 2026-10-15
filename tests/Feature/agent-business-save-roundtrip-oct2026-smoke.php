<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/Core/Autoloader.php';
\App\Core\Autoloader::register(dirname(__DIR__, 2) . '/app');

use App\Services\ExpressBusinessConfigurationReader;
use App\Services\AgentReadinessEvaluator;

function ensure(bool $good, string $label): void {
    if (!$good) throw new RuntimeException($label);
}

$reader = new ExpressBusinessConfigurationReader();
$compiled = ['mon'=>[['08:00','12:00'],['13:00','18:00']], 'tue'=>[['09:00','17:30']]];
$list = ExpressBusinessConfigurationReader::normalizeHours($compiled);
ensure($list === [
    ['day'=>'monday','start'=>'08:00','end'=>'12:00'],
    ['day'=>'monday','start'=>'13:00','end'=>'18:00'],
    ['day'=>'tuesday','start'=>'09:00','end'=>'17:30'],
], 'O formato compilado de expediente deve retornar como lista do contrato HTTP.');
ensure(ExpressBusinessConfigurationReader::normalizeHours($list) === $list, 'Uma lista existente não pode ser perdida.');
ensure(ExpressBusinessConfigurationReader::normalizeHours(null) === [], 'Horários inválidos não podem ser inventados.');

// Snapshot gerado pelo banco: conteúdo operacional salvo e confirmado no reload.
$faq = [['question'=>'Aceitam convênio?','answer'=>'Atendimento particular.'],
        ['question'=>'Pagamento via Pix?','answer'=>'Sim, aceitamos Pix.'],
        ['question'=>'Atende online?','answer'=>'Sim, via Meet.']];
$profile = json_encode(['express'=>['agent_name'=>'Atendimento VP','segment'=>'psychology_clinic',
    'tone_of_voice'=>'empathetic_and_welcoming','commercial'=>['pricing_strategy'=>'fixed_price','price_value'=>'R$ 180,00','payment_methods'=>['pix']],
    'routing'=>['timezone'=>'America/Sao_Paulo','business_hours'=>$compiled,'out_of_hours_action'=>'capture_lead_and_promise_contact',
    'default_meeting_link'=>null],'faq'=>$faq], 'capabilities'=>[]], JSON_THROW_ON_ERROR);

class FakeBusinessStatement extends PDOStatement {
    public function __construct(private array $rows) {}
    public function execute(?array $params = null): bool {return true;}
    public function fetchColumn(int $column = 0): mixed {return $this->rows[0][$column] ?? false;}
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array {return $this->rows;}
}
class FakeBusinessPDO extends PDO {
    public function __construct(private string $profile, private array $fields) {}
    public function prepare(string $query, array $options = []): PDOStatement|false {
        if (str_contains($query,'SELECT config_json FROM tenant_agent_profiles')) return new FakeBusinessStatement([[$this->profile]]);
        if (str_contains($query,'SELECT id,name,is_default,business_hours_enabled,business_hours_json')) return new FakeBusinessStatement([]);
        if (str_contains($query,'SELECT name FROM tenants')) return new FakeBusinessStatement([['Vida Plena']]);
        if (str_contains($query,'SELECT field_key,prompt_text,active FROM tenant_triage_fields')) return new FakeBusinessStatement($this->fields);
        if (str_contains($query,'SELECT capability_key,enabled FROM tenant_agent_capabilities')) return new FakeBusinessStatement([]);
        throw new RuntimeException('Consulta inesperada no teste: ' . $query);
    }
}
$testDb = new FakeBusinessPDO($profile, [
    ['field_key'=>'requester_name','prompt_text'=>'Como posso chamar você?', 'active'=>1],
    ['field_key'=>'contact_phone','prompt_text'=>'Qual seu telefone?', 'active'=>1],
    ['field_key'=>'express_a','prompt_text'=>'É sua primeira consulta?', 'active'=>1],
    ['field_key'=>'express_b','prompt_text'=>'Para qual especialidade?', 'active'=>1],
]);
$loaded = $reader->load($testDb, 42);
ensure($loaded['has_saved'], 'Perfil salvo deve ser reconhecido.');
ensure($loaded['payload']['routing_and_schedule']['business_hours'] === $list, 'Horários persistidos devem voltar no formato do frontend.');
ensure($loaded['payload']['triage_config']['custom_questions'] === ['É sua primeira consulta?','Para qual especialidade?'], 'Perguntas reais de triagem devem reaparecer.');
ensure($loaded['payload']['knowledge_base']['faq'] === $faq, 'FAQ persistido não pode desaparecer.');
ensure($loaded['payload']['triage_config']['collect_name'] && $loaded['payload']['triage_config']['collect_phone'], 'Toggles devem ler registros reais.');

// WhatsApp conectado na empresa sem vínculo do agente: informar a diferença sem liberar operação.
$fixture = [
    'agent'=>['id'=>1,'name'=>'Vida Plena','business_hours_enabled'=>0,'status'=>'inactive'],
    'profile'=>['id'=>1,'blueprint_version_id'=>1,'status'=>'active','config'=>['express'=>[]]],
    'channels'=>[],
    'whatsapp_instances'=>[['status'=>'connected','receive_messages'=>1,'webhook_enabled'=>1,'identity_status'=>'verified','authorized_phone'=>'5511999']],
];
$readiness = (new AgentReadinessEvaluator())->evaluate($fixture);
ensure($readiness['channel_status']['connected'] === true && $readiness['channel_status']['ready_for_agent'] === false,
    'Conexão física não equivale ao vínculo do agente.');
ensure(!$readiness['can_operate'], 'Uma conexão sem vínculo não autoriza operar.');
ensure(str_contains($readiness['channel_status']['message'], 'conectado à empresa'), 'Diagnóstico precisa explicar a diferença.');

$root = dirname(__DIR__, 2);
$js = file_get_contents($root . '/public/assets/js/agent-business.js');
$controller = file_get_contents($root . '/app/Controllers/ExpressSetupController.php');
$view = file_get_contents($root . '/app/Views/agents/business.php');
ensure(str_contains($js, "await request(root.dataset.readUrl)") && str_contains($js, 'mismatchAreas'), 'Salvar deve conferir os dados com o servidor.');
ensure(str_contains($controller, 'savedConfiguration()') && str_contains($view, 'data-read-url'), 'GET autenticado deve alimentar a conferência.');
echo "OK: horários compilados, perguntas, 3 FAQs, canal conectado sem vínculo e conferência pós-salvamento.\n";
