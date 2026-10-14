<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/Core/Autoloader.php';
\App\Core\Autoloader::register(dirname(__DIR__, 2) . '/app');

use App\Core\Crypto;
use App\Services\AgentCredentialAvailabilityService;
use App\Services\AgentReadinessEvaluator;

function expectCredential(bool $condition, string $description): void
{
    if (!$condition) throw new RuntimeException($description);
}

$vars = ['OPENAI_API_KEY', 'GEMINI_API_KEY', 'GOOGLE_GEMINI_API_KEY', 'APP_KEY'];
$backup = [];
foreach ($vars as $key) {
    $backup[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
    unset($_ENV[$key], $_SERVER[$key]);
    putenv($key);
}
try {
    $validator = new AgentCredentialAvailabilityService();
    $agent = ['model_provider' => 'openai'];
    $missing = $validator->evaluate($agent, null);
    expectCredential(!$missing['available'], 'Sem chave ativa: bloqueio obrigatório.');
    expectCredential($missing['provider'] === 'openai', 'Provedor deve seguir agente.');

    $_ENV['GEMINI_API_KEY'] = 'gemini-test-placeholder';
    expectCredential(!$validator->evaluate($agent, null)['available'],
        'Chave de provedor diferente não pode liberar agente OpenAI.');
    $_ENV['OPENAI_API_KEY'] = 'openai-test-placeholder';
    $global = $validator->evaluate($agent, null);
    expectCredential($global['available'] && $global['source'] === 'environment',
        'Fallback OPENAI_API_KEY deve ser reconhecido como no runtime.');
    expectCredential(!str_contains($global['message'], 'openai-test-placeholder'),
        'Chaves nunca devem ser exibidas no diagnóstico.');

    unset($_ENV['OPENAI_API_KEY']);
    $_ENV['APP_KEY'] = 'readiness-test-key-not-production';
    $encrypted = Crypto::encrypt('db-test-key');
    $db = $validator->evaluate($agent, [
        'agent_id' => 12, 'provider' => 'openai', 'api_key_encrypted' => $encrypted,
    ]);
    expectCredential($db['available'] && $db['source'] === 'agent', 'Chave específica do agente deve funcionar.');
    $tenant = $validator->evaluate($agent, [
        'agent_id' => null, 'provider' => 'openai', 'api_key_encrypted' => $encrypted,
    ]);
    expectCredential($tenant['available'] && $tenant['source'] === 'tenant', 'Chave compartilhada deve funcionar.');

    $_ENV['APP_KEY'] = 'wrong-key';
    $_ENV['OPENAI_API_KEY'] = 'openai-fallback-must-not-be-used';
    $corrupt = $validator->evaluate($agent, [
        'agent_id' => 12, 'provider' => 'openai', 'api_key_encrypted' => $encrypted,
    ]);
    expectCredential(!$corrupt['available'], 'APP_KEY errada deve bloquear mesmo com fallback ambiente.');

    $unsupported = $validator->evaluate($agent, [
        'agent_id' => 12, 'provider' => 'custom', 'api_key_encrypted' => '',
    ]);
    expectCredential(!$unsupported['available'], 'Runtime não aceita provedor customizado.');

    unset($_ENV['OPENAI_API_KEY']);
    $google = $validator->evaluate(['model_provider' => 'google'], null);
    expectCredential($google['available'] && $google['provider'] === 'google',
        'GEMINI_API_KEY disponível deve desbloquear o provedor Google.');

    $snapshot = [
        'agent' => ['id' => 12, 'name' => 'Teste', 'status' => 'active'],
        'credentials' => $missing,
        'profile' => [], 'channels' => [], 'capabilities' => [],
        'triage_fields' => [], 'workflow' => [], 'policies' => [],
    ];
    $report = (new AgentReadinessEvaluator())->evaluate($snapshot);
    $items = array_values(array_filter($report['checklist'],
        static fn(array $item): bool => $item['item'] === 'Serviço de respostas'));
    expectCredential(count($items) === 1 && $items[0]['status'] === 'error'
        && $items[0]['severity'] === 'critical', 'Ausência de chave precisa bloquear operação.');
    $snapshot['credentials'] = $global;
    $report = (new AgentReadinessEvaluator())->evaluate($snapshot);
    $items = array_values(array_filter($report['checklist'],
        static fn(array $item): bool => $item['item'] === 'Serviço de respostas'));
    expectCredential(count($items) === 1 && $items[0]['status'] === 'ok',
        'Fallback válido precisa passar na verificação de acesso.');
    expectCredential(!$report['can_operate'],
        'Acesso configurado não pode ignorar falhas críticas em outras categorias.');

    echo "OK: fonte de credencial, segurança, bloqueios e pontuação.\n";
} finally {
    foreach ($backup as $key => [$env, $server, $getenv]) {
        if ($env === null) unset($_ENV[$key]); else $_ENV[$key] = $env;
        if ($server === null) unset($_SERVER[$key]); else $_SERVER[$key] = $server;
        if ($getenv === false) putenv($key); else putenv($key . '=' . $getenv);
    }
}
