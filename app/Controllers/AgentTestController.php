<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Flash;
use App\Core\Router;
use App\Core\View;
use App\Services\AgentSimulationService;
use PDO;
use Throwable;

final class AgentTestController
{
    public function index(): void
    {
        $this->noStore();
        $service = new AgentSimulationService();
        $tenants = $service->tenants();
        $tenantId = (int) ($_GET['tenant_id'] ?? 0);
        $validTenantIds = array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $tenants);
        if ($tenantId < 1 || !in_array($tenantId, $validTenantIds, true)) {
            $tenantId = $tenants !== [] ? (int) ($tenants[0]['id'] ?? 0) : 0;
        }

        $agents = $tenantId > 0 ? $service->agents($tenantId) : [];
        $selectedAgentId = (int) ($_GET['agent_id'] ?? 0);
        $validAgentIds = array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $agents);
        if ($selectedAgentId < 1 || !in_array($selectedAgentId, $validAgentIds, true)) {
            $selectedAgentId = $agents !== [] ? (int) ($agents[0]['id'] ?? 0) : 0;
        }

        $migrationReady = $this->tableExists('agent_test_scenarios');
        $scenarios = $migrationReady && $tenantId > 0 ? $service->scenarios($tenantId) : [];
        $runs = $migrationReady && $tenantId > 0 ? $service->recentRuns($tenantId, 20) : [];

        View::render('agent_tests.index', [
            'title' => 'Laboratório de assistentes',
            'tenants' => $tenants,
            'selectedTenantId' => $tenantId,
            'agents' => $agents,
            'selectedAgentId' => $selectedAgentId,
            'scenarios' => $scenarios,
            'runs' => $runs,
            'migrationReady' => $migrationReady,
            'sourceConversationId' => max(0, (int) ($_GET['conversation_id'] ?? 0)),
            'labVersion' => '36.29.3',
        ]);
    }

    public function agents(): void
    {
        $this->noStore();
        $tenantId = (int) ($_GET['tenant_id'] ?? 0);
        $service = new AgentSimulationService();
        $tenants = $service->tenants();
        $tenant = null;
        foreach ($tenants as $row) {
            if ((int) ($row['id'] ?? 0) === $tenantId) {
                $tenant = $row;
                break;
            }
        }

        if (!$tenant) {
            $this->json(['ok' => false, 'message' => 'Empresa não encontrada.'], 404);
        }

        $agents = $service->agents($tenantId);
        $this->json([
            'ok' => true,
            'tenant' => [
                'id' => (int) ($tenant['id'] ?? 0),
                'name' => (string) ($tenant['name'] ?? ''),
            ],
            'agents' => array_map(static fn (array $agent): array => [
                'id' => (int) ($agent['id'] ?? 0),
                'name' => (string) ($agent['name'] ?? ''),
                'status' => (string) ($agent['status'] ?? ''),
                'provider' => (string) ($agent['model_provider'] ?? ''),
                'model' => (string) ($agent['model_name'] ?? ''),
            ], $agents),
        ]);
    }

    public function simulate(): void
    {
        $tenantId = (int) ($_POST['tenant_id'] ?? 0);
        $agentId = (int) ($_POST['agent_id'] ?? 0);
        $message = trim((string) ($_POST['message'] ?? ''));
        $mode = (string) ($_POST['mode'] ?? 'quick');
        $history = $this->decodeArray($_POST['history_json'] ?? '[]');
        $state = $this->decodeArray($_POST['state_json'] ?? '{}');

        try {
            $result = (new AgentSimulationService())->turn($tenantId, $agentId, $message, $history, $state, $mode);
            Audit::log('agent_test.simulated', [
                'agent_id' => $agentId,
                'mode' => $mode,
                'ai_called' => !empty($result['ai_called']),
                'decision_code' => $result['triage']['decision']['code'] ?? null,
                'safe' => !empty($result['safe']),
            ], $tenantId > 0 ? $tenantId : null);
            $this->json(['ok' => true, 'result' => $result]);
        } catch (Throwable $exception) {
            $this->json(['ok' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function createDefaults(): void
    {
        $tenantId = (int) ($_POST['tenant_id'] ?? 0);
        $agentId = (int) ($_POST['agent_id'] ?? 0);
        try {
            $count = (new AgentSimulationService())->createPsychologyDefaults($tenantId, $agentId, Auth::id());
            Flash::set('success', $count . ' cenário(s) padrão de Psicologia preparados para homologação.');
            Audit::log('agent_test.defaults_created', ['agent_id' => $agentId, 'count' => $count], $tenantId);
        } catch (Throwable $exception) {
            Flash::set('error', 'Não foi possível criar os cenários: ' . $exception->getMessage());
        }
        $this->redirect($tenantId, $agentId);
    }

    public function runScenario(): void
    {
        $tenantId = (int) ($_POST['tenant_id'] ?? 0);
        $agentId = (int) ($_POST['agent_id'] ?? 0);
        $scenarioId = (int) ($_POST['scenario_id'] ?? 0);
        $mode = in_array((string) ($_POST['mode'] ?? ''), ['quick', 'real'], true) ? (string) $_POST['mode'] : 'quick';
        try {
            $result = (new AgentSimulationService())->runScenario($scenarioId, $agentId, $mode, Auth::id());
            $message = $result['status'] === 'passed'
                ? 'Cenário aprovado: ' . (int) $result['passed'] . ' etapa(s) passaram.'
                : 'Cenário com falhas: ' . (int) $result['failed'] . ' etapa(s) precisam de revisão.';
            Flash::set($result['status'] === 'passed' ? 'success' : 'warning', $message);
            Audit::log('agent_test.scenario_run', [
                'scenario_id' => $scenarioId,
                'agent_id' => $agentId,
                'mode' => $mode,
                'status' => $result['status'],
                'passed' => $result['passed'],
                'failed' => $result['failed'],
            ], $tenantId);
        } catch (Throwable $exception) {
            Flash::set('error', 'Falha ao executar cenário: ' . $exception->getMessage());
        }
        $this->redirect($tenantId, $agentId);
    }

    public function importConversation(): void
    {
        $tenantId = (int) ($_POST['tenant_id'] ?? 0);
        $agentId = (int) ($_POST['agent_id'] ?? 0);
        $conversationId = (int) ($_POST['conversation_id'] ?? 0);
        try {
            $scenarioId = (new AgentSimulationService())->importConversation($tenantId, $conversationId, $agentId, Auth::id());
            Flash::set('success', 'Conversa transformada em cenário de teste #' . $scenarioId . '. Revise as expectativas antes de usá-la como regressão.');
            Audit::log('agent_test.replay_created', [
                'conversation_id' => $conversationId,
                'scenario_id' => $scenarioId,
                'agent_id' => $agentId,
            ], $tenantId);
        } catch (Throwable $exception) {
            Flash::set('error', 'Não foi possível transformar a conversa em teste: ' . $exception->getMessage());
        }
        $this->redirect($tenantId, $agentId);
    }

    private function redirect(int $tenantId, int $agentId = 0): never
    {
        $query = $tenantId > 0 ? '?tenant_id=' . $tenantId : '';
        if ($agentId > 0) {
            $query .= ($query === '' ? '?' : '&') . 'agent_id=' . $agentId;
        }
        header('Location: ' . Router::url('/agent-tests' . $query));
        exit;
    }

    private function json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    private function decodeArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function noStore(): void
    {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
    }

    private function tableExists(string $table): bool
    {
        try {
            $pdo = Database::connection();
            $stmt = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table LIMIT 1');
            $stmt->execute(['table' => $table]);
            return (bool) $stmt->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }
}
