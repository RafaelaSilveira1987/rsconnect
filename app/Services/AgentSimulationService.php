<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Clock;
use PDO;
use RuntimeException;
use Throwable;

final class AgentSimulationService
{
    /**
     * Executa um turno isolado do laboratório.
     * Nenhuma mensagem é enviada ao WhatsApp e nenhuma agenda é gravada.
     *
     * @param array<int,array<string,mixed>> $history
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    public function turn(int $tenantId, int $agentId, string $message, array $history = [], array $state = [], string $mode = 'quick'): array
    {
        $message = trim($message);
        $mode = strtolower(trim($mode));
        if (!in_array($mode, ['quick', 'real'], true)) {
            $mode = 'quick';
        }
        if ($tenantId < 1 || $agentId < 1 || $message === '') {
            throw new RuntimeException('Empresa, assistente e mensagem são obrigatórios para o teste.');
        }

        $pdo = Database::connection();
        $agent = $this->agent($pdo, $tenantId, $agentId);
        if (!$agent) {
            throw new RuntimeException('Assistente não encontrado para a empresa selecionada.');
        }

        $profile = (new AgentBlueprintService())->profileForTenant($tenantId, true, $pdo);
        $triage = (new AgentTriageService())->simulateTurn($profile, $state, $message);
        $decision = is_array($triage['decision'] ?? null) ? $triage['decision'] : [];
        $response = '';
        $responseKind = 'diagnostic';
        $providerUsage = [];
        $aiCalled = false;
        $error = null;

        $shouldUseRuleMessage = !empty($triage['should_use_rule_message']);
        $decisionMessage = trim((string) ($decision['message'] ?? ''));
        $decisionCode = trim((string) ($decision['code'] ?? ''));

        if ($shouldUseRuleMessage && $decisionMessage !== '') {
            $response = $decisionMessage;
            $responseKind = 'rule';
        } elseif ($decisionCode === 'triage_incomplete' && empty($triage['should_use_ai']) && $decisionMessage !== '') {
            $response = $decisionMessage;
            $responseKind = 'triage';
        } elseif ($mode === 'quick') {
            $response = 'Fluxo liberado para a IA. O modo rápido não chama o provedor e valida apenas coleta, regras e permissões.';
            $responseKind = 'diagnostic';
        } else {
            try {
                $ai = new AiModelService();
                $messages = $this->messageHistory($history, $message);
                $contact = [
                    'tenant_id' => $tenantId,
                    'name' => trim((string) (($triage['state']['collected']['requester_name'] ?? null) ?: 'Contato de teste')),
                    'phone' => '5511999999999',
                    'status' => 'lead',
                    'contact_status' => 'lead',
                    'contact_group' => 'lead',
                ];
                $simulationContext = is_array($triage['state'] ?? null) ? $triage['state'] : [];
                $simulationContext['collected'] = $triage['state']['collected'] ?? [];
                $simulationContext['missing'] = $triage['state']['missing'] ?? [];
                $conversation = [
                    'id' => 0,
                    'conversation_id' => 0,
                    'tenant_id' => $tenantId,
                    'contact_name' => $contact['name'],
                    'phone' => $contact['phone'],
                    'contact_group' => 'lead',
                    'contact_status' => 'lead',
                    'status' => 'open',
                    'attendance_mode' => 'ai',
                    'flow_stage' => !empty($triage['scheduling_intent']) ? 'scheduling' : 'identifying_contact',
                    'demand_status' => 'pending',
                    'last_intent' => !empty($triage['scheduling_intent']) ? 'schedule' : 'conversation',
                    '_simulation_triage_context' => $simulationContext,
                ];
                $response = trim($ai->generateReply($agent, $messages, $contact, $conversation));
                $providerUsage = $ai->lastUsage();
                $responseKind = 'ai';
                $aiCalled = true;
            } catch (Throwable $exception) {
                $error = $exception->getMessage();
                $response = '';
                $responseKind = 'error';
            }
        }

        $findings = $this->safetyFindings($triage, $response);
        $safe = $error === null && $findings === [];

        $nextHistory = $history;
        $nextHistory[] = ['role' => 'user', 'content' => $message];
        if ($response !== '') {
            $nextHistory[] = ['role' => 'assistant', 'content' => $response];
        }

        return [
            'ok' => $error === null,
            'mode' => $mode,
            'agent' => [
                'id' => (int) $agent['id'],
                'name' => (string) ($agent['name'] ?? ''),
                'provider' => (string) ($agent['credential_provider'] ?? $agent['model_provider'] ?? ''),
                'model' => (string) ($agent['credential_default_model'] ?? $agent['model_name'] ?? ''),
            ],
            'response' => $response,
            'response_kind' => $responseKind,
            'ai_called' => $aiCalled,
            'provider_usage' => $providerUsage,
            'triage' => $triage,
            'state' => $triage['state'] ?? $state,
            'history' => $nextHistory,
            'safety_findings' => $findings,
            'safe' => $safe,
            'error' => $error,
        ];
    }

    /** @return array<string,mixed> */
    public function runScenario(int $scenarioId, int $agentId, string $mode, ?int $createdBy = null): array
    {
        $pdo = Database::connection();
        $scenario = $this->scenario($pdo, $scenarioId);
        if (!$scenario) {
            throw new RuntimeException('Cenário de teste não encontrado.');
        }
        $tenantId = (int) $scenario['tenant_id'];
        $agentId = $agentId > 0 ? $agentId : (int) ($scenario['agent_id'] ?? 0);
        if ($agentId < 1) {
            throw new RuntimeException('Selecione um assistente para executar o cenário.');
        }
        $mode = in_array($mode, ['quick', 'real'], true) ? $mode : (string) ($scenario['mode'] ?? 'quick');
        $steps = $this->decodeArray($scenario['steps_json'] ?? null);
        if ($steps === []) {
            throw new RuntimeException('O cenário não possui mensagens configuradas.');
        }

        $runId = $this->startRun($pdo, $tenantId, $scenarioId, $agentId, $mode, $createdBy);
        $state = [];
        $history = [];
        $passed = 0;
        $failed = 0;
        $stepResults = [];
        $provider = null;
        $model = null;

        try {
            foreach ($steps as $index => $step) {
                if (!is_array($step)) {
                    continue;
                }
                $userMessage = trim((string) ($step['message'] ?? $step['user'] ?? ''));
                if ($userMessage === '') {
                    continue;
                }
                $result = $this->turn($tenantId, $agentId, $userMessage, $history, $state, $mode);
                $state = is_array($result['state'] ?? null) ? $result['state'] : $state;
                $history = is_array($result['history'] ?? null) ? $result['history'] : $history;
                $expect = is_array($step['expect'] ?? null) ? $step['expect'] : [];
                $assertions = $this->assertions($result, $expect);
                $stepPassed = !empty($result['ok']) && $assertions['failed'] === 0 && !empty($result['safe']);
                $stepStatus = $stepPassed ? 'passed' : (!empty($result['ok']) ? 'failed' : 'error');
                $stepPassed ? $passed++ : $failed++;

                $provider = $result['agent']['provider'] ?? $provider;
                $model = $result['agent']['model'] ?? $model;
                $this->storeRunStep($pdo, $runId, $index + 1, $userMessage, $result, $assertions, $stepStatus);
                $stepResults[] = [
                    'order' => $index + 1,
                    'message' => $userMessage,
                    'status' => $stepStatus,
                    'response' => (string) ($result['response'] ?? ''),
                    'assertions' => $assertions,
                    'triage' => $result['triage'] ?? [],
                    'safety_findings' => $result['safety_findings'] ?? [],
                ];
            }
            $status = $failed === 0 ? 'passed' : 'failed';
            $summary = [
                'scenario' => (string) ($scenario['name'] ?? ''),
                'passed' => $passed,
                'failed' => $failed,
                'steps' => count($stepResults),
            ];
            $this->finishRun($pdo, $runId, $status, $passed, $failed, $provider, $model, $summary);
        } catch (Throwable $exception) {
            $this->finishRun($pdo, $runId, 'error', $passed, $failed + 1, $provider, $model, ['error' => $exception->getMessage()]);
            throw $exception;
        }

        return [
            'run_id' => $runId,
            'status' => $failed === 0 ? 'passed' : 'failed',
            'passed' => $passed,
            'failed' => $failed,
            'steps' => $stepResults,
        ];
    }

    public function createPsychologyDefaults(int $tenantId, int $agentId, ?int $createdBy = null): int
    {
        $pdo = Database::connection();
        $scenarios = [
            [
                'slug' => 'psi-menor-indicacao-regressao',
                'name' => 'Menor de idade pede indicação depois da restrição',
                'description' => 'Garante que a idade bloqueia somente agenda, mantém a conversa ativa e permite responder ao pedido de indicação.',
                'steps' => [
                    ['message' => 'Quero marcar psicólogo para minha filha', 'expect' => ['conversation_continues' => true]],
                    ['message' => 'Ela tem 8 anos', 'expect' => ['decision_code' => 'minimum_age', 'calendar_allowed' => false, 'conversation_continues' => true, 'rule_message' => true]],
                    ['message' => 'Quero uma indicação', 'expect' => ['scheduling_intent' => false, 'calendar_allowed' => false, 'conversation_continues' => true, 'ai_allowed' => true]],
                    ['message' => 'Então pode marcar quinta às 10h?', 'expect' => ['decision_code' => 'minimum_age', 'calendar_allowed' => false, 'conversation_continues' => true]],
                ],
            ],
            [
                'slug' => 'psi-idade-obrigatoria-antes-agenda',
                'name' => 'Agenda exige idade quando configurada como obrigatória',
                'description' => 'Não libera calendário enquanto faltar idade da pessoa que será atendida.',
                'steps' => [
                    ['message' => 'Quero agendar para minha filha', 'expect' => ['conversation_continues' => true]],
                    ['message' => 'Pode ser quinta às 15h?', 'expect' => ['decision_code' => 'triage_incomplete', 'calendar_allowed' => false]],
                ],
            ],
            [
                'slug' => 'psi-casal-restrito',
                'name' => 'Terapia de casal respeita a regra do consultório',
                'description' => 'Valida a política de casal sem permitir consulta de agenda quando o serviço estiver desativado.',
                'steps' => [
                    ['message' => 'Quero marcar terapia de casal', 'expect' => ['decision_code' => 'couple_service_not_allowed', 'calendar_allowed' => false, 'conversation_continues' => true]],
                ],
            ],
            [
                'slug' => 'psi-adulto-pode-continuar',
                'name' => 'Pessoa elegível continua a triagem',
                'description' => 'Idade acima do mínimo não aciona a trava de idade.',
                'steps' => [
                    ['message' => 'Quero marcar uma consulta para mim', 'expect' => ['conversation_continues' => true]],
                    ['message' => 'Tenho 30 anos', 'expect' => ['not_decision_code' => 'minimum_age', 'conversation_continues' => true]],
                ],
            ],
        ];

        $count = 0;
        foreach ($scenarios as $scenario) {
            $stmt = $pdo->prepare(
                'INSERT INTO agent_test_scenarios
                    (tenant_id, agent_id, name, slug, description, mode, status, steps_json, expectations_json, created_by)
                 VALUES
                    (:tenant_id, :agent_id, :name, :slug, :description, "quick", "active", :steps_json, NULL, :created_by)
                 ON DUPLICATE KEY UPDATE
                    agent_id = VALUES(agent_id), name = VALUES(name), description = VALUES(description),
                    steps_json = VALUES(steps_json), status = "active", updated_at = CURRENT_TIMESTAMP'
            );
            $stmt->execute([
                'tenant_id' => $tenantId,
                'agent_id' => $agentId > 0 ? $agentId : null,
                'name' => $scenario['name'],
                'slug' => $scenario['slug'],
                'description' => $scenario['description'],
                'steps_json' => json_encode($scenario['steps'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_by' => $createdBy,
            ]);
            $count++;
        }
        return $count;
    }

    /** @return array<int,array<string,mixed>> */
    public function scenarios(int $tenantId): array
    {
        $pdo = Database::connection();
        if (!$this->tableExists($pdo, 'agent_test_scenarios')) {
            return [];
        }
        $stmt = $pdo->prepare(
            'SELECT s.*, a.name AS agent_name,
                    (SELECT r.status FROM agent_test_runs r WHERE r.scenario_id = s.id ORDER BY r.id DESC LIMIT 1) AS last_status,
                    (SELECT r.created_at FROM agent_test_runs r WHERE r.scenario_id = s.id ORDER BY r.id DESC LIMIT 1) AS last_run_at
             FROM agent_test_scenarios s
             LEFT JOIN ai_agents a ON a.id = s.agent_id
             WHERE s.tenant_id = :tenant_id AND s.status = "active"
             ORDER BY s.updated_at DESC, s.id DESC'
        );
        $stmt->execute(['tenant_id' => $tenantId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<int,array<string,mixed>> */
    public function recentRuns(int $tenantId, int $limit = 20): array
    {
        $pdo = Database::connection();
        if (!$this->tableExists($pdo, 'agent_test_runs')) {
            return [];
        }
        $limit = max(1, min(100, $limit));
        $stmt = $pdo->prepare(
            'SELECT r.*, s.name AS scenario_name, a.name AS agent_name
             FROM agent_test_runs r
             LEFT JOIN agent_test_scenarios s ON s.id = r.scenario_id
             LEFT JOIN ai_agents a ON a.id = r.agent_id
             WHERE r.tenant_id = :tenant_id
             ORDER BY r.id DESC LIMIT ' . $limit
        );
        $stmt->execute(['tenant_id' => $tenantId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<int,array<string,mixed>> */
    public function agents(int $tenantId): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT id, name, segment, model_provider, model_name, status FROM ai_agents WHERE tenant_id = :tenant_id ORDER BY status = "active" DESC, name');
        $stmt->execute(['tenant_id' => $tenantId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<int,array<string,mixed>> */
    public function tenants(): array
    {
        $pdo = Database::connection();
        return $pdo->query('SELECT id, name, status FROM tenants ORDER BY status = "active" DESC, name')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function importConversation(int $tenantId, int $conversationId, int $agentId, ?int $createdBy = null): int
    {
        $pdo = Database::connection();
        $check = $pdo->prepare('SELECT c.id, COALESCE(ct.name, ct.phone) AS contact_name FROM conversations c INNER JOIN contacts ct ON ct.id = c.contact_id WHERE c.id = :id AND c.tenant_id = :tenant_id LIMIT 1');
        $check->execute(['id' => $conversationId, 'tenant_id' => $tenantId]);
        $conversation = $check->fetch(PDO::FETCH_ASSOC);
        if (!$conversation) {
            throw new RuntimeException('Conversa não encontrada para a empresa selecionada.');
        }
        $messages = $pdo->prepare('SELECT direction, sender_type, content FROM conversation_messages WHERE conversation_id = :id AND message_type = "text" AND content IS NOT NULL AND TRIM(content) <> "" ORDER BY id ASC LIMIT 80');
        $messages->execute(['id' => $conversationId]);
        $rows = $messages->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $steps = [];
        foreach ($rows as $row) {
            if (($row['direction'] ?? '') !== 'incoming') {
                continue;
            }
            $steps[] = ['message' => trim((string) ($row['content'] ?? '')), 'expect' => []];
        }
        if ($steps === []) {
            throw new RuntimeException('A conversa não possui mensagens recebidas em texto para transformar em teste.');
        }
        $slug = 'replay-' . $conversationId . '-' . date('YmdHis');
        $stmt = $pdo->prepare(
            'INSERT INTO agent_test_scenarios
                (tenant_id, agent_id, name, slug, description, mode, status, steps_json, source_conversation_id, created_by)
             VALUES
                (:tenant_id, :agent_id, :name, :slug, :description, "real", "active", :steps_json, :conversation_id, :created_by)'
        );
        $stmt->execute([
            'tenant_id' => $tenantId,
            'agent_id' => $agentId > 0 ? $agentId : null,
            'name' => 'Replay da conversa #' . $conversationId,
            'slug' => $slug,
            'description' => 'Criado a partir de uma conversa real com ' . (string) ($conversation['contact_name'] ?? 'contato') . '. Revise as expectativas antes de usar como trava de regressão.',
            'steps_json' => json_encode($steps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'conversation_id' => $conversationId,
            'created_by' => $createdBy,
        ]);
        return (int) $pdo->lastInsertId();
    }

    private function assertions(array $result, array $expect): array
    {
        $checks = [];
        $triage = is_array($result['triage'] ?? null) ? $result['triage'] : [];
        $decision = is_array($triage['decision'] ?? null) ? $triage['decision'] : [];
        $response = mb_strtolower((string) ($result['response'] ?? ''));

        $add = static function (array &$checks, string $name, bool $ok, mixed $expected, mixed $actual): void {
            $checks[] = ['name' => $name, 'ok' => $ok, 'expected' => $expected, 'actual' => $actual];
        };

        foreach ($expect as $key => $expected) {
            switch ($key) {
                case 'decision_code':
                    $actual = (string) ($decision['code'] ?? '');
                    $add($checks, 'Regra/decisão esperada', $actual === (string) $expected, $expected, $actual);
                    break;
                case 'not_decision_code':
                    $actual = (string) ($decision['code'] ?? '');
                    $add($checks, 'Regra não deve ser acionada', $actual !== (string) $expected, 'diferente de ' . $expected, $actual);
                    break;
                case 'calendar_allowed':
                    $actual = !empty($triage['calendar_allowed']);
                    $add($checks, 'Agenda liberada', $actual === (bool) $expected, (bool) $expected, $actual);
                    break;
                case 'conversation_continues':
                    $actual = !empty($triage['conversation_continues']);
                    $add($checks, 'Conversa continua', $actual === (bool) $expected, (bool) $expected, $actual);
                    break;
                case 'scheduling_intent':
                    $actual = !empty($triage['scheduling_intent']);
                    $add($checks, 'Intenção de agenda', $actual === (bool) $expected, (bool) $expected, $actual);
                    break;
                case 'ai_allowed':
                    $actual = !empty($triage['should_use_ai']);
                    $add($checks, 'IA liberada para conversar', $actual === (bool) $expected, (bool) $expected, $actual);
                    break;
                case 'rule_message':
                    $actual = ($result['response_kind'] ?? '') === 'rule';
                    $add($checks, 'Mensagem da regra utilizada', $actual === (bool) $expected, (bool) $expected, $actual);
                    break;
                case 'response_contains':
                    $needle = mb_strtolower(trim((string) $expected));
                    $add($checks, 'Resposta contém trecho', $needle !== '' && str_contains($response, $needle), $expected, $result['response'] ?? '');
                    break;
                case 'response_not_contains':
                    $needle = mb_strtolower(trim((string) $expected));
                    $add($checks, 'Resposta não contém trecho', $needle === '' || !str_contains($response, $needle), 'não conter ' . $expected, $result['response'] ?? '');
                    break;
            }
        }

        $failed = count(array_filter($checks, static fn (array $check): bool => empty($check['ok'])));
        return ['checks' => $checks, 'passed' => count($checks) - $failed, 'failed' => $failed];
    }

    /** @return array<int,array<string,mixed>> */
    private function safetyFindings(array $triage, string $response): array
    {
        $findings = [];
        $response = trim($response);
        if ($response === '') {
            return $findings;
        }
        if (empty($triage['calendar_allowed'])) {
            $normalized = mb_strtolower($response);
            $patterns = [
                '/\b(ficou|esta|está|foi)\s+(agendad[oa]|confirmad[oa]|reservad[oa])\b/u',
                '/\b(pre[- ]?reservei|reservei|agendei|confirmei)\b/u',
                '/\btenho\s+disponibilidade\b/u',
                '/\bhorario\s+(esta|está)\s+disponivel\b/u',
            ];
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $normalized)) {
                    $findings[] = [
                        'code' => 'calendar_claim_without_permission',
                        'message' => 'A resposta afirma disponibilidade ou agendamento enquanto a agenda está bloqueada pelas regras.',
                    ];
                    break;
                }
            }
        }
        return $findings;
    }

    /** @return array<int,array<string,mixed>> */
    private function messageHistory(array $history, string $currentMessage): array
    {
        $messages = [];
        foreach ($history as $item) {
            if (!is_array($item)) {
                continue;
            }
            $content = trim((string) ($item['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            $assistant = (string) ($item['role'] ?? '') === 'assistant';
            $messages[] = [
                'direction' => $assistant ? 'outgoing' : 'incoming',
                'sender_type' => $assistant ? 'ai' : 'contact',
                'content' => $content,
            ];
        }
        $messages[] = ['direction' => 'incoming', 'sender_type' => 'contact', 'content' => $currentMessage];
        return array_slice($messages, -30);
    }

    private function agent(PDO $pdo, int $tenantId, int $agentId): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT a.*,
                    COALESCE(ac_agent.provider, ac_tenant.provider) AS credential_provider,
                    COALESCE(ac_agent.api_key_encrypted, ac_tenant.api_key_encrypted) AS credential_api_key_encrypted,
                    COALESCE(ac_agent.base_url, ac_tenant.base_url) AS credential_base_url,
                    COALESCE(ac_agent.default_model, ac_tenant.default_model) AS credential_default_model
             FROM ai_agents a
             LEFT JOIN ai_provider_credentials ac_agent ON ac_agent.id = (
                SELECT x.id FROM ai_provider_credentials x WHERE x.agent_id = a.id AND x.status = "active"
                ORDER BY x.is_default DESC, x.id DESC LIMIT 1
             )
             LEFT JOIN ai_provider_credentials ac_tenant ON ac_tenant.id = (
                SELECT y.id FROM ai_provider_credentials y WHERE y.tenant_id = a.tenant_id AND y.agent_id IS NULL AND y.status = "active"
                ORDER BY y.is_default DESC, y.id DESC LIMIT 1
             )
             WHERE a.id = :agent_id AND a.tenant_id = :tenant_id LIMIT 1'
        );
        $stmt->execute(['agent_id' => $agentId, 'tenant_id' => $tenantId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function scenario(PDO $pdo, int $id): ?array
    {
        if (!$this->tableExists($pdo, 'agent_test_scenarios')) {
            throw new RuntimeException('Execute a migration 105_agent_testing_lab.sql antes de usar o laboratório.');
        }
        $stmt = $pdo->prepare('SELECT * FROM agent_test_scenarios WHERE id = :id AND status = "active" LIMIT 1');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function startRun(PDO $pdo, int $tenantId, int $scenarioId, int $agentId, string $mode, ?int $createdBy): int
    {
        $stmt = $pdo->prepare(
            'INSERT INTO agent_test_runs (tenant_id, scenario_id, agent_id, mode, status, created_by, started_at)
             VALUES (:tenant_id, :scenario_id, :agent_id, :mode, "running", :created_by, :started_at)'
        );
        $stmt->execute([
            'tenant_id' => $tenantId,
            'scenario_id' => $scenarioId,
            'agent_id' => $agentId,
            'mode' => $mode,
            'created_by' => $createdBy,
            'started_at' => Clock::nowUtc(),
        ]);
        return (int) $pdo->lastInsertId();
    }

    private function storeRunStep(PDO $pdo, int $runId, int $order, string $message, array $result, array $assertions, string $status): void
    {
        $decision = is_array($result['triage']['decision'] ?? null) ? $result['triage']['decision'] : [];
        $stmt = $pdo->prepare(
            'INSERT INTO agent_test_run_steps
                (run_id, step_order, user_message, assistant_response, result_status, decision_code, decision_label,
                 triage_json, assertions_json, provider_usage_json, error_message)
             VALUES
                (:run_id, :step_order, :user_message, :assistant_response, :result_status, :decision_code, :decision_label,
                 :triage_json, :assertions_json, :provider_usage_json, :error_message)'
        );
        $stmt->execute([
            'run_id' => $runId,
            'step_order' => $order,
            'user_message' => $message,
            'assistant_response' => (string) ($result['response'] ?? ''),
            'result_status' => $status,
            'decision_code' => mb_substr((string) ($decision['code'] ?? ''), 0, 120) ?: null,
            'decision_label' => mb_substr((string) ($decision['policy_key'] ?? ''), 0, 190) ?: null,
            'triage_json' => json_encode($result['triage'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'assertions_json' => json_encode(['assertions' => $assertions, 'safety_findings' => $result['safety_findings'] ?? []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'provider_usage_json' => !empty($result['provider_usage']) ? json_encode($result['provider_usage'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'error_message' => isset($result['error']) && $result['error'] !== null ? mb_substr((string) $result['error'], 0, 1000) : null,
        ]);
    }

    private function finishRun(PDO $pdo, int $runId, string $status, int $passed, int $failed, mixed $provider, mixed $model, array $summary): void
    {
        $pdo->prepare(
            'UPDATE agent_test_runs
             SET status = :status, passed_steps = :passed, failed_steps = :failed,
                 provider = :provider, model = :model, summary_json = :summary_json, completed_at = :completed_at
             WHERE id = :id'
        )->execute([
            'status' => $status,
            'passed' => $passed,
            'failed' => $failed,
            'provider' => trim((string) $provider) !== '' ? mb_substr((string) $provider, 0, 40) : null,
            'model' => trim((string) $model) !== '' ? mb_substr((string) $model, 0, 120) : null,
            'summary_json' => json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'completed_at' => Clock::nowUtc(),
            'id' => $runId,
        ]);
    }

    private function decodeArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        try {
            $stmt = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table LIMIT 1');
            $stmt->execute(['table' => $table]);
            return (bool) $stmt->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }
}
