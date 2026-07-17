<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Flash;
use App\Core\Router;
use App\Core\View;
use App\Services\AiAutomationService;
use App\Services\ConversationFlowService;
use App\Services\SubscriptionService;
use PDO;
use Throwable;

final class AgentController
{
    public function index(): void
    {
        $pdo = Database::connection();
        $tenantId = $this->resolveTenantId();
        $tenants = [];

        if (Auth::isSuperAdmin()) {
            $tenants = $pdo->query(
                'SELECT t.id, t.name, t.status,
                        COUNT(DISTINCT a.id) AS agents_count,
                        COUNT(DISTINCT i.id) AS instances_count
                 FROM tenants t
                 LEFT JOIN ai_agents a ON a.tenant_id = t.id
                 LEFT JOIN evolution_instances i ON i.tenant_id = t.id
                 GROUP BY t.id
                 ORDER BY (t.id = ' . (int) $tenantId . ') DESC, t.status = "active" DESC, t.name'
            )->fetchAll(PDO::FETCH_ASSOC);
        }

        $agents = [];
        $instances = [];
        $companyProfile = [];
        $groupRules = [];

        if ($tenantId > 0) {
            $agentsStatement = $pdo->prepare(
                'SELECT a.*, i.name AS instance_name, t.name AS tenant_name,
                        COALESCE(ac_agent.label, ac_tenant.label) AS credential_label,
                        COALESCE(ac_agent.provider, ac_tenant.provider) AS credential_provider,
                        COALESCE(ac_agent.default_model, ac_tenant.default_model) AS credential_model
                 FROM ai_agents a
                 INNER JOIN tenants t ON t.id = a.tenant_id
                 LEFT JOIN evolution_instances i ON i.id = a.instance_id
                 LEFT JOIN ai_provider_credentials ac_agent ON ac_agent.id = (
                    SELECT x.id FROM ai_provider_credentials x
                    WHERE x.agent_id = a.id AND x.status = "active"
                    ORDER BY x.is_default DESC, x.id DESC LIMIT 1
                 )
                 LEFT JOIN ai_provider_credentials ac_tenant ON ac_tenant.id = (
                    SELECT y.id FROM ai_provider_credentials y
                    WHERE y.tenant_id = a.tenant_id AND y.agent_id IS NULL AND y.status = "active"
                    ORDER BY y.is_default DESC, y.id DESC LIMIT 1
                 )
                 WHERE a.tenant_id = :tenant_id
                 ORDER BY a.is_default DESC, a.created_at DESC'
            );
            $agentsStatement->execute(['tenant_id' => $tenantId]);
            $agents = $agentsStatement->fetchAll(PDO::FETCH_ASSOC);

            $instancesStatement = $pdo->prepare(
                'SELECT id, name FROM evolution_instances WHERE tenant_id = :tenant_id ORDER BY is_default DESC, name'
            );
            $instancesStatement->execute(['tenant_id' => $tenantId]);
            $instances = $instancesStatement->fetchAll(PDO::FETCH_ASSOC);

            $companyStatement = $pdo->prepare('SELECT * FROM tenants WHERE id = :tenant_id LIMIT 1');
            $companyStatement->execute(['tenant_id' => $tenantId]);
            $companyProfile = $companyStatement->fetch(PDO::FETCH_ASSOC) ?: [];

            $groupRules = (new ConversationFlowService())->rulesForAgents(
                $pdo,
                $tenantId,
                array_column($agents, 'id')
            );
        }

        View::render('agents.index', [
            'title' => 'Assistentes de IA',
            'agents' => $agents,
            'instances' => $instances,
            'companyProfile' => $companyProfile,
            'tenants' => $tenants,
            'selectedTenantId' => $tenantId,
            'groupRules' => $groupRules,
            'contactGroups' => ConversationFlowService::GROUPS,
        ]);
    }

    public function store(): void
    {
        $tenantId = $this->resolveTenantId();
        $instanceId = (int) ($_POST['instance_id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $segment = trim((string) ($_POST['segment'] ?? ''));
        $provider = (string) ($_POST['model_provider'] ?? 'openai');
        $model = trim((string) ($_POST['model_name'] ?? 'gpt-4o-mini'));
        $temperature = max(0, min(1, (float) ($_POST['temperature'] ?? 0.2)));
        $prompt = trim((string) ($_POST['system_prompt'] ?? ''));
        if ($prompt === '') {
            $prompt = $this->guidedPromptFromPost($name, $segment);
        }
        $knowledgeBase = trim((string) ($_POST['knowledge_base'] ?? ''));
        $handoffKeywords = trim((string) ($_POST['handoff_keywords'] ?? 'humano, atendente, pessoa, suporte'));
        $maxContextMessages = max(4, min(30, (int) ($_POST['max_context_messages'] ?? 12)));
        $n8nWebhookUrl = trim((string) ($_POST['n8n_webhook_url'] ?? ''));
        $autoReplyEnabled = isset($_POST['auto_reply_enabled']);
        $n8nEnabled = isset($_POST['n8n_enabled']);
        $isDefault = isset($_POST['is_default']);
        $replyToReactions = isset($_POST['reply_to_reactions']);

        if ($instanceId < 1 || $name === '' || $segment === '' || $prompt === '') {
            Flash::set('error', 'Escolha a conexão WhatsApp e informe o nome, a área de atendimento e as instruções do assistente.');
            $this->redirectToAgents($tenantId ?? 0);
        }

        $limit = (new SubscriptionService())->ensureCanCreate($tenantId, 'agents');
        if (empty($limit['ok'])) {
            Flash::set('error', $limit['message']);
            $this->redirectToAgents($tenantId ?? 0);
        }

        $pdo = Database::connection();
        $check = $pdo->prepare(
            'SELECT id FROM evolution_instances WHERE id = :id AND tenant_id = :tenant_id LIMIT 1'
        );
        $check->execute(['id' => $instanceId, 'tenant_id' => $tenantId]);
        if (!$check->fetchColumn()) {
            Flash::set('error', 'A conexão WhatsApp escolhida não está disponível para sua empresa.');
            $this->redirectToAgents($tenantId ?? 0);
        }

        try {
            $pdo->beginTransaction();
            if ($isDefault) {
                $reset = $pdo->prepare('UPDATE ai_agents SET is_default = 0 WHERE tenant_id = :tenant_id');
                $reset->execute(['tenant_id' => $tenantId]);
            }

            $business = $this->businessHoursFromPost();

            $insert = $pdo->prepare(
                'INSERT INTO ai_agents
                    (tenant_id, instance_id, name, segment, model_provider, model_name, temperature, system_prompt,
                     status, is_default, auto_reply_enabled, handoff_keywords, max_context_messages,
                     knowledge_base, n8n_enabled, n8n_webhook_url, business_hours_enabled, business_timezone,
                     business_hours_json, after_hours_message, human_handoff_message, handoff_action, cooldown_seconds, reply_to_reactions)
                 VALUES
                    (:tenant_id, :instance_id, :name, :segment, :provider, :model, :temperature, :prompt,
                     "active", :is_default, :auto_reply_enabled, :handoff_keywords, :max_context_messages,
                     :knowledge_base, :n8n_enabled, :n8n_webhook_url, :business_hours_enabled, :business_timezone,
                     :business_hours_json, :after_hours_message, :human_handoff_message, :handoff_action, :cooldown_seconds, :reply_to_reactions)'
            );
            $insert->execute([
                'tenant_id' => $tenantId,
                'instance_id' => $instanceId,
                'name' => $name,
                'segment' => $segment,
                'provider' => $provider,
                'model' => $model,
                'temperature' => $temperature,
                'prompt' => $prompt,
                'is_default' => $isDefault ? 1 : 0,
                'auto_reply_enabled' => $autoReplyEnabled ? 1 : 0,
                'handoff_keywords' => $handoffKeywords !== '' ? $handoffKeywords : null,
                'max_context_messages' => $maxContextMessages,
                'knowledge_base' => $knowledgeBase !== '' ? $knowledgeBase : null,
                'n8n_enabled' => $n8nEnabled ? 1 : 0,
                'n8n_webhook_url' => $n8nWebhookUrl !== '' ? $n8nWebhookUrl : null,
                'business_hours_enabled' => $business['enabled'],
                'business_timezone' => $business['timezone'],
                'business_hours_json' => $business['json'],
                'after_hours_message' => $business['after_hours_message'],
                'human_handoff_message' => $business['human_handoff_message'],
                'handoff_action' => $business['handoff_action'],
                'cooldown_seconds' => $business['cooldown_seconds'],
                'reply_to_reactions' => $replyToReactions ? 1 : 0,
            ]);

            $pdo->commit();
            Audit::log('agent.created', ['agent_id' => (int) $pdo->lastInsertId(), 'name' => $name], $tenantId);
            Flash::set('success', 'Assistente criado. Revise as instruções e faça uma conversa de teste antes de liberar o atendimento.');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Flash::set('error', 'Não foi possível cadastrar o agente: ' . $exception->getMessage());
        }

        $this->redirectToAgents($tenantId ?? 0);
    }

    public function updateStatus(): void
    {
        $tenantId = $this->resolveTenantId();
        $agentId = (int) ($_POST['agent_id'] ?? 0);
        $status = (string) ($_POST['status'] ?? 'inactive');
        $autoReplyEnabled = isset($_POST['auto_reply_enabled']);
        $n8nEnabled = isset($_POST['n8n_enabled']);
        $handoffKeywords = trim((string) ($_POST['handoff_keywords'] ?? ''));
        $maxContextMessages = max(4, min(30, (int) ($_POST['max_context_messages'] ?? 12)));
        $n8nWebhookUrl = trim((string) ($_POST['n8n_webhook_url'] ?? ''));
        $isDefault = isset($_POST['is_default']);
        $replyToReactions = isset($_POST['reply_to_reactions']);

        if ($agentId < 1 || !in_array($status, ['active', 'inactive'], true)) {
            Flash::set('error', 'Não foi possível identificar o assistente ou a opção escolhida.');
            $this->redirectToAgents($tenantId ?? 0);
        }

        $business = $this->businessHoursFromPost();
        $pdo = Database::connection();
        try {
            $pdo->beginTransaction();
            if ($isDefault) {
                $reset = $pdo->prepare('UPDATE ai_agents SET is_default = 0 WHERE tenant_id = :tenant_id');
                $reset->execute(['tenant_id' => $tenantId]);
            }

            $update = $pdo->prepare(
                'UPDATE ai_agents
                 SET status = :status,
                     is_default = :is_default,
                     auto_reply_enabled = :auto_reply_enabled,
                     n8n_enabled = :n8n_enabled,
                     handoff_keywords = :handoff_keywords,
                     max_context_messages = :max_context_messages,
                     n8n_webhook_url = :n8n_webhook_url,
                     business_hours_enabled = :business_hours_enabled,
                     business_timezone = :business_timezone,
                     business_hours_json = :business_hours_json,
                     after_hours_message = :after_hours_message,
                     human_handoff_message = :human_handoff_message,
                     handoff_action = :handoff_action,
                     cooldown_seconds = :cooldown_seconds,
                     reply_to_reactions = :reply_to_reactions
                 WHERE id = :id AND tenant_id = :tenant_id'
            );
            $update->execute([
                'status' => $status,
                'is_default' => $isDefault ? 1 : 0,
                'auto_reply_enabled' => $autoReplyEnabled ? 1 : 0,
                'n8n_enabled' => $n8nEnabled ? 1 : 0,
                'handoff_keywords' => $handoffKeywords !== '' ? $handoffKeywords : null,
                'max_context_messages' => $maxContextMessages,
                'n8n_webhook_url' => $n8nWebhookUrl !== '' ? $n8nWebhookUrl : null,
                'business_hours_enabled' => $business['enabled'],
                'business_timezone' => $business['timezone'],
                'business_hours_json' => $business['json'],
                'after_hours_message' => $business['after_hours_message'],
                'human_handoff_message' => $business['human_handoff_message'],
                'handoff_action' => $business['handoff_action'],
                'cooldown_seconds' => $business['cooldown_seconds'],
                'reply_to_reactions' => $replyToReactions ? 1 : 0,
                'id' => $agentId,
                'tenant_id' => $tenantId,
            ]);
            if ($update->rowCount() < 1) {
                $exists = $pdo->prepare('SELECT id FROM ai_agents WHERE id = :id AND tenant_id = :tenant_id LIMIT 1');
                $exists->execute(['id' => $agentId, 'tenant_id' => $tenantId]);
                if (!$exists->fetchColumn()) {
                    throw new \RuntimeException('Agente não encontrado.');
                }
            }

            $pdo->commit();
            Audit::log('agent.status_updated', [
                'agent_id' => $agentId,
                'status' => $status,
                'cooldown_seconds' => $business['cooldown_seconds'],
            ], $tenantId);

            $reprocess = (new AiAutomationService())->reprocessLatestPendingForAgent($tenantId, $agentId);
            Flash::set('success', $this->settingsSavedMessage($reprocess));
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Flash::set('error', $exception->getMessage());
        }

        $this->redirectToAgents($tenantId ?? 0);
    }

    public function updatePrompt(): void
    {
        $tenantId = $this->resolveTenantId();
        $agentId = (int) ($_POST['agent_id'] ?? 0);
        $prompt = trim((string) ($_POST['system_prompt'] ?? ''));
        $knowledgeBase = trim((string) ($_POST['knowledge_base'] ?? ''));

        if ($agentId < 1 || $prompt === '') {
            Flash::set('error', 'Informe como o assistente deve atender antes de salvar.');
            $this->redirectToAgents($tenantId ?? 0);
        }

        if (strlen($prompt) > 60000) {
            Flash::set('error', 'O prompt ultrapassa o limite de 60.000 caracteres. Resuma o conteúdo antes de salvar.');
            $this->redirectToAgents($tenantId ?? 0);
        }

        if (strlen($knowledgeBase) > 500000) {
            Flash::set('error', 'A base de conhecimento ultrapassa o limite de 500.000 caracteres.');
            $this->redirectToAgents($tenantId ?? 0);
        }

        $pdo = Database::connection();
        try {
            $agentStatement = $pdo->prepare(
                'SELECT id, name FROM ai_agents WHERE id = :id AND tenant_id = :tenant_id LIMIT 1'
            );
            $agentStatement->execute(['id' => $agentId, 'tenant_id' => $tenantId]);
            $agent = $agentStatement->fetch(PDO::FETCH_ASSOC);
            if (!$agent) {
                throw new \RuntimeException('Agente não encontrado para esta empresa.');
            }

            $update = $pdo->prepare(
                'UPDATE ai_agents
                 SET system_prompt = :system_prompt,
                     knowledge_base = :knowledge_base
                 WHERE id = :id AND tenant_id = :tenant_id'
            );
            $update->execute([
                'system_prompt' => $prompt,
                'knowledge_base' => $knowledgeBase !== '' ? $knowledgeBase : null,
                'id' => $agentId,
                'tenant_id' => $tenantId,
            ]);

            Audit::log('agent.prompt_updated', [
                'agent_id' => $agentId,
                'agent_name' => (string) ($agent['name'] ?? ''),
                'prompt_length' => strlen($prompt),
                'knowledge_base_length' => strlen($knowledgeBase),
            ], $tenantId);

            $reprocess = (new AiAutomationService())->reprocessLatestPendingForAgent($tenantId, $agentId);
            Flash::set('success', $this->promptSavedMessage($reprocess));
        } catch (Throwable $exception) {
            Flash::set('error', 'Não foi possível atualizar as instruções: ' . $exception->getMessage());
        }

        $this->redirectToAgents($tenantId ?? 0);
    }

    public function updateGroupRules(): void
    {
        $tenantId = $this->resolveTenantId();
        $agentId = (int) ($_POST['agent_id'] ?? 0);
        $rules = is_array($_POST['group_rules'] ?? null) ? $_POST['group_rules'] : [];

        if ($tenantId < 1 || $agentId < 1) {
            Flash::set('error', 'Selecione a empresa e o assistente antes de salvar as regras dos grupos.');
            $this->redirectToAgents($tenantId);
        }

        $pdo = Database::connection();
        $check = $pdo->prepare('SELECT id FROM ai_agents WHERE id = :id AND tenant_id = :tenant_id LIMIT 1');
        $check->execute(['id' => $agentId, 'tenant_id' => $tenantId]);
        if (!$check->fetchColumn()) {
            Flash::set('error', 'Assistente não encontrado para a empresa selecionada.');
            $this->redirectToAgents($tenantId);
        }

        try {
            (new ConversationFlowService())->saveGroupRules($pdo, $tenantId, $agentId, $rules);
            Audit::log('agent.group_rules_updated', ['agent_id' => $agentId], $tenantId);
            Flash::set('success', 'Regras por grupo de contato atualizadas. Elas passam a valer nas próximas mensagens.');
        } catch (Throwable $exception) {
            Flash::set('error', 'Não foi possível salvar as regras por grupo: ' . $exception->getMessage());
        }

        $this->redirectToAgents($tenantId);
    }

    private function guidedPromptFromPost(string $name, string $segment): string
    {
        $objective = trim((string) ($_POST['service_objective'] ?? ''));
        $tone = trim((string) ($_POST['tone_of_voice'] ?? 'claro, cordial e profissional'));
        $welcome = trim((string) ($_POST['welcome_message'] ?? ''));
        $rules = trim((string) ($_POST['assistant_rules'] ?? ''));

        if ($objective === '' && $rules === '') {
            return '';
        }

        $sections = [
            '# Identidade',
            'Você é ' . ($name !== '' ? $name : 'o assistente virtual') . ', responsável por ' . ($segment !== '' ? $segment : 'atendimento ao cliente') . '.',
            '',
            '# Objetivo do atendimento',
            $objective !== '' ? $objective : 'Atender, entender a necessidade do contato e encaminhar a conversa de forma útil e segura.',
            '',
            '# Tom de voz',
            $tone !== '' ? $tone : 'Claro, cordial e profissional.',
        ];

        if ($welcome !== '') {
            $sections[] = '';
            $sections[] = '# Mensagem de boas-vindas';
            $sections[] = $welcome;
        }

        if ($rules !== '') {
            $sections[] = '';
            $sections[] = '# Regras principais';
            $sections[] = $rules;
        }

        $sections[] = '';
        $sections[] = '# Segurança do atendimento';
        $sections[] = 'Não invente informações. Quando faltar contexto, faça perguntas objetivas ou encaminhe para uma pessoa da equipe.';

        return trim(implode("\n", $sections));
    }

    private function settingsSavedMessage(array $reprocess): string
    {
        $status = (string) ($reprocess['status'] ?? 'none');

        return match ($status) {
            'replied' => 'Configurações atualizadas. A última mensagem que aguardava o intervalo foi reprocessada e respondida.',
            'evaluated' => 'Configurações atualizadas. A última mensagem pendente foi reavaliada automaticamente; confira a conversa e os logs.',
            'skipped_reaction' => 'Configurações atualizadas. A última pendência era uma reação e foi ignorada conforme a preferência do assistente.',
            default => 'Configurações do assistente atualizadas.',
        };
    }

    private function promptSavedMessage(array $reprocess): string
    {
        $status = (string) ($reprocess['status'] ?? 'none');

        return match ($status) {
            'replied' => 'Instruções atualizadas. A última mensagem pendente foi reprocessada e respondida com as novas regras.',
            'evaluated' => 'Instruções atualizadas. A última mensagem pendente foi reavaliada automaticamente; confira a conversa e os logs.',
            default => 'Instruções e informações atualizadas. As mudanças valem nas próximas respostas.',
        };
    }

    private function businessHoursFromPost(): array
    {
        $enabled = isset($_POST['business_hours_enabled']) ? 1 : 0;
        $timezone = trim((string) ($_POST['business_timezone'] ?? 'America/Sao_Paulo')) ?: 'America/Sao_Paulo';
        $start = trim((string) ($_POST['business_start'] ?? '08:00')) ?: '08:00';
        $end = trim((string) ($_POST['business_end'] ?? '18:00')) ?: '18:00';
        $days = $_POST['business_days'] ?? ['mon', 'tue', 'wed', 'thu', 'fri'];
        $days = is_array($days) ? $days : [];
        $validDays = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

        $rules = [];
        foreach ($validDays as $day) {
            if (in_array($day, $days, true)) {
                $rules[$day] = [[$start, $end]];
            }
        }

        $handoffAction = (string) ($_POST['handoff_action'] ?? 'paused');
        if (!in_array($handoffAction, ['paused', 'human'], true)) {
            $handoffAction = 'paused';
        }

        return [
            'enabled' => $enabled,
            'timezone' => $timezone,
            'json' => json_encode($rules, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'after_hours_message' => trim((string) ($_POST['after_hours_message'] ?? '')) ?: null,
            'human_handoff_message' => trim((string) ($_POST['human_handoff_message'] ?? '')) ?: null,
            'handoff_action' => $handoffAction,
            'cooldown_seconds' => max(0, min(3600, (int) ($_POST['cooldown_seconds'] ?? 15))),
        ];
    }

    private function resolveTenantId(): int
    {
        if (!Auth::isSuperAdmin()) {
            return (int) (Auth::tenantId() ?? 0);
        }

        $requested = (int) ($_POST['tenant_id'] ?? $_GET['tenant_id'] ?? 0);
        if ($requested > 0 && $this->tenantExists($requested)) {
            $_SESSION['admin_agents_tenant_id'] = $requested;
            return $requested;
        }

        $remembered = (int) ($_SESSION['admin_agents_tenant_id'] ?? 0);
        if ($remembered > 0 && $this->tenantExists($remembered)) {
            return $remembered;
        }

        $statement = Database::connection()->query(
            'SELECT t.id
             FROM tenants t
             LEFT JOIN ai_agents a ON a.tenant_id = t.id
             GROUP BY t.id
             ORDER BY COUNT(a.id) DESC, t.status = "active" DESC, t.name
             LIMIT 1'
        );
        $tenantId = (int) ($statement->fetchColumn() ?: 0);
        if ($tenantId > 0) {
            $_SESSION['admin_agents_tenant_id'] = $tenantId;
        }
        return $tenantId;
    }

    private function tenantExists(int $tenantId): bool
    {
        $statement = Database::connection()->prepare('SELECT COUNT(*) FROM tenants WHERE id = :id');
        $statement->execute(['id' => $tenantId]);
        return (int) $statement->fetchColumn() > 0;
    }

    private function redirectToAgents(int $tenantId = 0): never
    {
        $path = '/agents';
        if (Auth::isSuperAdmin() && $tenantId > 0) {
            $path .= '?tenant_id=' . $tenantId;
        }
        header('Location: ' . Router::url($path));
        exit;
    }

    private function redirect(string $path): never
    {
        header('Location: ' . Router::url($path));
        exit;
    }
}
