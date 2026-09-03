<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Crypto;
use App\Core\Flash;
use App\Core\Router;
use App\Core\View;
use App\Services\AiAutomationService;
use App\Services\AgentRoutingService;
use App\Services\ConversationFlowService;
use App\Services\SubscriptionService;
use App\Services\OnboardingGuideService;
use App\Services\PromptStudioService;
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
        $promptVersions = [];

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
            foreach ($agents as &$agentRow) {
                $legacyUrl = trim((string) ($agentRow['n8n_webhook_url'] ?? ''));
                $agentRow['n8n_calendar_conflict'] = $legacyUrl !== ''
                    && $this->isProtectedCalendarWriterUrl((int) $tenantId, $legacyUrl);
            }
            unset($agentRow);

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

            $promptVersions = (new PromptStudioService())->versionsForAgents($tenantId, array_column($agents, 'id'));

            try {
                $bindings = (new AgentRoutingService())->bindingsForTenant($pdo, $tenantId);
                $channelsByAgent = [];
                foreach ($bindings as $binding) {
                    $agentKey = (int) ($binding['agent_id'] ?? 0);
                    $channelsByAgent[$agentKey][] = $binding;
                }
                foreach ($agents as &$agentRow) {
                    $agentBindings = $channelsByAgent[(int) $agentRow['id']] ?? [];
                    $agentRow['channels'] = $agentBindings;
                    $agentRow['channel_count'] = count($agentBindings);
                    $agentRow['channel_names'] = implode(', ', array_values(array_filter(array_map(
                        static fn (array $binding): string => trim((string) ($binding['instance_name'] ?? '')),
                        $agentBindings
                    ))));
                }
                unset($agentRow);
            } catch (Throwable) {
                foreach ($agents as &$agentRow) {
                    $agentRow['channels'] = [];
                    $agentRow['channel_count'] = !empty($agentRow['instance_id']) ? 1 : 0;
                    $agentRow['channel_names'] = (string) ($agentRow['instance_name'] ?? '');
                }
                unset($agentRow);
            }
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
            'promptVersions' => $promptVersions,
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
        $aiEfficiencyMode = $this->aiEfficiencyModeFromPost();
        $aiMaxOutputTokens = $this->nullableIntFromPost('ai_max_output_tokens', 64, 2000);
        $aiKnowledgeBudgetChars = $this->nullableIntFromPost('ai_knowledge_budget_chars', 1000, 120000);
        $aiSelectiveKnowledge = isset($_POST['ai_selective_knowledge']);
        $localAutomation = $this->aiLocalAutomationFromPost();
        $n8nWebhookUrl = trim((string) ($_POST['n8n_webhook_url'] ?? ''));
        $autoReplyEnabled = isset($_POST['auto_reply_enabled']);
        $n8nEnabled = isset($_POST['n8n_enabled']);
        $isDefault = isset($_POST['is_default']);
        $routingMode = $this->routingModeFromValue((string) ($_POST['routing_mode'] ?? ''));
        try {
            $routingKeywords = $this->routingKeywordsForMode(
                $routingMode,
                (string) ($_POST['routing_keywords'] ?? '')
            );
        } catch (\RuntimeException $exception) {
            Flash::set('error', $exception->getMessage());
            $this->redirectToAgents($tenantId ?? 0);
            return;
        }
        $replyToReactions = isset($_POST['reply_to_reactions']);

        if ($instanceId < 1 || $name === '' || $segment === '' || $prompt === '') {
            Flash::set('error', 'Escolha a conexão WhatsApp e informe o nome, a área de atendimento e as instruções do assistente.');
            $this->redirectToAgents($tenantId ?? 0);
        }
        if ($n8nWebhookUrl !== '' && $this->isProtectedCalendarWriterUrl((int) $tenantId, $n8nWebhookUrl)) {
            Flash::set('error', 'Não vincule o workflow “Agenda Google Calendar por Empresa” diretamente ao assistente. Configure-o em n8n → Fluxos por empresa; o RS Connect só o acionará quando existir um compromisso real.');
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
                     ai_efficiency_mode, ai_max_output_tokens, ai_knowledge_budget_chars, ai_selective_knowledge,
                     ai_local_replies_enabled, ai_greeting_reply, ai_gratitude_reply, ai_farewell_reply, ai_menu_reply,
                     ai_exact_cache_enabled, ai_exact_cache_ttl_hours, knowledge_base, n8n_enabled, n8n_webhook_url, business_hours_enabled, business_timezone,
                     business_hours_json, after_hours_message, human_handoff_message, handoff_action, cooldown_seconds, reply_to_reactions)
                 VALUES
                    (:tenant_id, :instance_id, :name, :segment, :provider, :model, :temperature, :prompt,
                     "active", :is_default, :auto_reply_enabled, :handoff_keywords, :max_context_messages,
                     :ai_efficiency_mode, :ai_max_output_tokens, :ai_knowledge_budget_chars, :ai_selective_knowledge,
                     :ai_local_replies_enabled, :ai_greeting_reply, :ai_gratitude_reply, :ai_farewell_reply, :ai_menu_reply,
                     :ai_exact_cache_enabled, :ai_exact_cache_ttl_hours, :knowledge_base, :n8n_enabled, :n8n_webhook_url, :business_hours_enabled, :business_timezone,
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
                'ai_efficiency_mode' => $aiEfficiencyMode,
                'ai_max_output_tokens' => $aiMaxOutputTokens,
                'ai_knowledge_budget_chars' => $aiKnowledgeBudgetChars,
                'ai_selective_knowledge' => $aiSelectiveKnowledge ? 1 : 0,
                'ai_local_replies_enabled' => $localAutomation['enabled'],
                'ai_greeting_reply' => $localAutomation['greeting'],
                'ai_gratitude_reply' => $localAutomation['gratitude'],
                'ai_farewell_reply' => $localAutomation['farewell'],
                'ai_menu_reply' => $localAutomation['menu'],
                'ai_exact_cache_enabled' => $localAutomation['cache_enabled'],
                'ai_exact_cache_ttl_hours' => $localAutomation['cache_ttl_hours'],
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
            $agentId = (int) $pdo->lastInsertId();

            try {
                $binding = $pdo->prepare(
                    'INSERT INTO ai_agent_instance_bindings
                        (tenant_id, agent_id, instance_id, is_primary, priority, routing_keywords, status)
                     VALUES (:tenant_id, :agent_id, :instance_id, :is_primary, :priority, :routing_keywords, "active")
                     ON DUPLICATE KEY UPDATE
                        status = "active",
                        is_primary = VALUES(is_primary),
                        priority = VALUES(priority),
                        routing_keywords = VALUES(routing_keywords)'
                );
                $existingPrimary = $pdo->prepare(
                    'SELECT COUNT(*) FROM ai_agent_instance_bindings
                     WHERE tenant_id = :tenant_id AND instance_id = :instance_id AND status = "active" AND is_primary = 1'
                );
                $existingPrimary->execute(['tenant_id' => $tenantId, 'instance_id' => $instanceId]);
                $hasPrimary = (int) $existingPrimary->fetchColumn() > 0;
                $makePrimary = $routingMode === 'primary' || (!$hasPrimary && $routingMode !== 'specialist');
                if ($makePrimary) {
                    $pdo->prepare('UPDATE ai_agent_instance_bindings SET is_primary = 0 WHERE tenant_id = :tenant_id AND instance_id = :instance_id')
                        ->execute(['tenant_id' => $tenantId, 'instance_id' => $instanceId]);
                }
                $binding->execute([
                    'tenant_id' => $tenantId,
                    'agent_id' => $agentId,
                    'instance_id' => $instanceId,
                    'is_primary' => $makePrimary ? 1 : 0,
                    'priority' => $makePrimary ? 200 : 100,
                    'routing_keywords' => $routingMode === 'specialist' ? $routingKeywords : null,
                ]);
            } catch (Throwable) {
                // Compatibilidade antes da migration 055: o vínculo legado instance_id continua válido.
            }

            $pdo->commit();
            $promptSource = trim((string) ($_POST['prompt_studio_generated'] ?? '')) === '1' ? 'prompt_studio' : 'manual';
            $answers = null;
            $warnings = null;
            if (!empty($_POST['prompt_studio_answers_json'])) {
                $decodedAnswers = json_decode((string) $_POST['prompt_studio_answers_json'], true);
                $answers = is_array($decodedAnswers) ? $decodedAnswers : null;
            }
            if (!empty($_POST['prompt_studio_warnings_json'])) {
                $decodedWarnings = json_decode((string) $_POST['prompt_studio_warnings_json'], true);
                $warnings = is_array($decodedWarnings) ? $decodedWarnings : null;
            }
            (new PromptStudioService())->createVersion($tenantId, $agentId, $prompt, $promptSource, Auth::id(), $answers, $warnings, 'Criação do assistente');
            $guideService = new OnboardingGuideService();
            if ($guideService->requiresGuidedAccess($tenantId)) {
                $guideService->applyStoredAttendanceToAgent($tenantId, $agentId);
                $guideService->saveStep($tenantId, 'ai_agent', 'complete', 'Agente criado e regras operacionais aplicadas durante o primeiro acesso.', Auth::id());
            }
            Audit::log('agent.created', ['agent_id' => $agentId, 'name' => $name], $tenantId);
            Flash::set('success', $guideService->requiresGuidedAccess($tenantId)
                ? 'Assistente criado com as regras operacionais. Volte aos Primeiros passos para executar o teste final.'
                : 'Assistente criado. Revise as instruções e faça uma conversa de teste antes de liberar o atendimento.');
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
        $aiEfficiencyMode = $this->aiEfficiencyModeFromPost();
        $aiMaxOutputTokens = $this->nullableIntFromPost('ai_max_output_tokens', 64, 2000);
        $aiKnowledgeBudgetChars = $this->nullableIntFromPost('ai_knowledge_budget_chars', 1000, 120000);
        $aiSelectiveKnowledge = isset($_POST['ai_selective_knowledge']);
        $memoryEnabled = isset($_POST['ai_progressive_memory_enabled']);
        $memoryRefreshMessages = max(4, min(30, (int) ($_POST['ai_memory_refresh_messages'] ?? 8)));
        $memoryMaxChars = max(800, min(6000, (int) ($_POST['ai_memory_max_chars'] ?? 2200)));
        $localAutomation = $this->aiLocalAutomationFromPost();
        $n8nWebhookUrl = trim((string) ($_POST['n8n_webhook_url'] ?? ''));
        $isDefault = isset($_POST['is_default']);
        $replyToReactions = isset($_POST['reply_to_reactions']);
        $channelSelectionSubmitted = isset($_POST['channels_present']);
        $selectedInstanceIds = $channelSelectionSubmitted
            ? $this->positiveIntArray($_POST['instance_ids'] ?? [])
            : [];
        $legacyPrimaryInstanceIds = $channelSelectionSubmitted
            ? array_values(array_intersect(
                $this->positiveIntArray($_POST['primary_instance_ids'] ?? []),
                $selectedInstanceIds
            ))
            : [];
        $routingModesByInstance = $channelSelectionSubmitted
            ? $this->routingModesFromPost($selectedInstanceIds, $legacyPrimaryInstanceIds)
            : [];
        try {
            $routingKeywordsByInstance = $channelSelectionSubmitted
                ? $this->routingKeywordsFromPost($selectedInstanceIds, $routingModesByInstance)
                : [];
        } catch (\RuntimeException $exception) {
            Flash::set('error', $exception->getMessage());
            $this->redirectToAgents($tenantId ?? 0);
            return;
        }
        $primaryInstanceIds = array_values(array_map(
            'intval',
            array_keys(array_filter(
                $routingModesByInstance,
                static fn (string $mode): bool => $mode === 'primary'
            ))
        ));

        if ($agentId < 1 || !in_array($status, ['active', 'inactive'], true)) {
            Flash::set('error', 'Não foi possível identificar o assistente ou a opção escolhida.');
            $this->redirectToAgents($tenantId ?? 0);
        }
        if ($n8nWebhookUrl !== '' && $this->isProtectedCalendarWriterUrl((int) $tenantId, $n8nWebhookUrl)) {
            Flash::set('error', 'Esse workflow de Agenda não deve ficar na integração externa do assistente. Remova a URL daqui e mantenha o fluxo cadastrado em n8n → Fluxos por empresa.');
            $this->redirectToAgents($tenantId ?? 0);
        }

        $pdo = Database::connection();
        try {
            $business = $this->businessHoursFromPost();
            if ($channelSelectionSubmitted) {
                $this->assertInstancesBelongToTenant($pdo, $tenantId, $selectedInstanceIds);
            }

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
                     ai_efficiency_mode = :ai_efficiency_mode,
                     ai_max_output_tokens = :ai_max_output_tokens,
                     ai_knowledge_budget_chars = :ai_knowledge_budget_chars,
                     ai_selective_knowledge = :ai_selective_knowledge,
                     ai_local_replies_enabled = :ai_local_replies_enabled,
                     ai_greeting_reply = :ai_greeting_reply,
                     ai_gratitude_reply = :ai_gratitude_reply,
                     ai_farewell_reply = :ai_farewell_reply,
                     ai_menu_reply = :ai_menu_reply,
                     ai_exact_cache_enabled = :ai_exact_cache_enabled,
                     ai_exact_cache_ttl_hours = :ai_exact_cache_ttl_hours,
                     ai_progressive_memory_enabled = :ai_progressive_memory_enabled,
                     ai_memory_refresh_messages = :ai_memory_refresh_messages,
                     ai_memory_max_chars = :ai_memory_max_chars,
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
                'ai_efficiency_mode' => $aiEfficiencyMode,
                'ai_max_output_tokens' => $aiMaxOutputTokens,
                'ai_knowledge_budget_chars' => $aiKnowledgeBudgetChars,
                'ai_selective_knowledge' => $aiSelectiveKnowledge ? 1 : 0,
                'ai_local_replies_enabled' => $localAutomation['enabled'],
                'ai_greeting_reply' => $localAutomation['greeting'],
                'ai_gratitude_reply' => $localAutomation['gratitude'],
                'ai_farewell_reply' => $localAutomation['farewell'],
                'ai_menu_reply' => $localAutomation['menu'],
                'ai_exact_cache_enabled' => $localAutomation['cache_enabled'],
                'ai_exact_cache_ttl_hours' => $localAutomation['cache_ttl_hours'],
                'ai_progressive_memory_enabled' => $memoryEnabled ? 1 : 0,
                'ai_memory_refresh_messages' => $memoryRefreshMessages,
                'ai_memory_max_chars' => $memoryMaxChars,
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

            if ($channelSelectionSubmitted) {
                $this->syncAgentChannels(
                    $pdo,
                    $tenantId,
                    $agentId,
                    $selectedInstanceIds,
                    $primaryInstanceIds,
                    $routingModesByInstance,
                    $routingKeywordsByInstance
                );
            }

            $pdo->commit();
            Audit::log('agent.status_updated', [
                'agent_id' => $agentId,
                'status' => $status,
                'auto_reply_enabled' => $autoReplyEnabled,
                'n8n_enabled' => $n8nEnabled,
                'cooldown_seconds' => $business['cooldown_seconds'],
                'ai_efficiency_mode' => $aiEfficiencyMode,
                'ai_local_replies_enabled' => $localAutomation['enabled'] === 1,
                'ai_exact_cache_enabled' => $localAutomation['cache_enabled'] === 1,
                'channels_updated' => $channelSelectionSubmitted,
                'instance_ids' => $channelSelectionSubmitted ? $selectedInstanceIds : null,
                'primary_instance_ids' => $channelSelectionSubmitted ? $primaryInstanceIds : null,
                'routing_modes' => $channelSelectionSubmitted ? $routingModesByInstance : null,
                'routing_keywords_configured' => $channelSelectionSubmitted
                    ? array_map(static fn (?string $value): bool => trim((string) $value) !== '', $routingKeywordsByInstance)
                    : null,
            ], $tenantId);

            $reprocess = (new AiAutomationService())->reprocessLatestPendingForAgent($tenantId, $agentId);
            $message = $this->settingsSavedMessage($reprocess);
            if ($channelSelectionSubmitted) {
                $message = $selectedInstanceIds === []
                    ? 'Configurações salvas. O assistente ficou sem canal WhatsApp vinculado.'
                    : 'Canais WhatsApp e configurações do assistente atualizados.';
            }
            Flash::set('success', $message);
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

            (new PromptStudioService())->createVersion($tenantId, $agentId, $prompt, 'manual', Auth::id(), null, null, 'Edição manual');

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

    private function isProtectedCalendarWriterUrl(int $tenantId, string $url): bool
    {
        if (trim($url) === '') {
            return false;
        }

        // O endpoint oficial do writer é protegido mesmo quando a empresa ainda não
        // possui registro em n8n_tenant_flows. Isso impede salvar o webhook de criação
        // de evento como "integração externa do assistente".
        $path = mb_strtolower((string) (parse_url($url, PHP_URL_PATH) ?? ''));
        if (str_contains($path, 'rsconnect-agenda-cliente')) {
            return true;
        }

        if ($tenantId < 1) {
            return false;
        }
        try {
            $statement = Database::connection()->prepare(
                'SELECT flow_key, name, webhook_url_encrypted
                 FROM n8n_tenant_flows
                 WHERE tenant_id = :tenant_id AND status = "active"'
            );
            $statement->execute(['tenant_id' => $tenantId]);
            $target = $this->normalizeWebhookUrl($url);
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $flow) {
                $identity = mb_strtolower(trim((string) ($flow['flow_key'] ?? '') . ' ' . (string) ($flow['name'] ?? '')));
                $identity = strtr($identity, [
                    'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a',
                    'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c',
                ]);
                if (!str_contains($identity, 'agenda-google-calendar') && !str_contains($identity, 'agenda google calendar por empresa')) {
                    continue;
                }
                $registered = Crypto::decrypt((string) ($flow['webhook_url_encrypted'] ?? ''));
                if ($registered !== '' && $this->normalizeWebhookUrl($registered) === $target) {
                    return true;
                }
            }
        } catch (Throwable) {
            return false;
        }
        return false;
    }

    private function normalizeWebhookUrl(string $url): string
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts) || empty($parts['host'])) {
            return rtrim(mb_strtolower(trim($url)), '/');
        }
        $scheme = mb_strtolower((string) ($parts['scheme'] ?? 'https'));
        $host = mb_strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $path = rtrim((string) ($parts['path'] ?? ''), '/');
        return $scheme . '://' . $host . $port . $path;
    }

    private function businessHoursFromPost(): array
    {
        $enabled = isset($_POST['business_hours_enabled']) ? 1 : 0;
        $timezone = trim((string) ($_POST['business_timezone'] ?? 'America/Sao_Paulo')) ?: 'America/Sao_Paulo';
        $validDays = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];
        $dayLabels = ['sun' => 'Dom', 'mon' => 'Seg', 'tue' => 'Ter', 'wed' => 'Qua', 'thu' => 'Qui', 'fri' => 'Sex', 'sat' => 'Sáb'];

        try {
            new \DateTimeZone($timezone);
        } catch (\Throwable) {
            throw new \RuntimeException('Fuso horário inválido. Exemplo: America/Sao_Paulo.');
        }

        $rules = [];
        $dayEnabled = $_POST['business_day_enabled'] ?? null;
        $dayStarts = $_POST['business_day_start'] ?? null;
        $dayEnds = $_POST['business_day_end'] ?? null;

        if (is_array($dayEnabled) || is_array($dayStarts) || is_array($dayEnds)) {
            $dayEnabled = is_array($dayEnabled) ? $dayEnabled : [];
            $dayStarts = is_array($dayStarts) ? $dayStarts : [];
            $dayEnds = is_array($dayEnds) ? $dayEnds : [];
            foreach ($validDays as $day) {
                if (!isset($dayEnabled[$day])) {
                    continue;
                }
                $start = trim((string) ($dayStarts[$day] ?? ''));
                $end = trim((string) ($dayEnds[$day] ?? ''));
                if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $start)
                    || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $end)) {
                    throw new \RuntimeException('Informe horários válidos para ' . ($dayLabels[$day] ?? $day) . '.');
                }
                if ($start >= $end) {
                    throw new \RuntimeException('Em ' . ($dayLabels[$day] ?? $day) . ', o horário inicial deve ser anterior ao horário final.');
                }
                $rules[$day] = [[$start, $end]];
            }
        } else {
            // Compatibilidade com formulários anteriores à 36.6.30.
            $start = trim((string) ($_POST['business_start'] ?? '08:00')) ?: '08:00';
            $end = trim((string) ($_POST['business_end'] ?? '18:00')) ?: '18:00';
            $days = $_POST['business_days'] ?? ['mon', 'tue', 'wed', 'thu', 'fri'];
            $days = is_array($days) ? $days : [];
            if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $start)
                || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $end)) {
                throw new \RuntimeException('Informe horários válidos no formato HH:MM.');
            }
            if ($start >= $end) {
                throw new \RuntimeException('O horário inicial deve ser anterior ao horário final.');
            }
            foreach ($validDays as $day) {
                if (in_array($day, $days, true)) {
                    $rules[$day] = [[$start, $end]];
                }
            }
        }

        if ($enabled === 1 && $rules === []) {
            throw new \RuntimeException('Selecione pelo menos um dia de atendimento quando a restrição de horário estiver ativa.');
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

    /** @return int[] */
    private function positiveIntArray(mixed $value): array
    {
        $items = is_array($value) ? $value : [$value];
        $ids = [];
        foreach ($items as $item) {
            $id = (int) $item;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        return array_values($ids);
    }

    private function routingModeFromValue(string $value): string
    {
        $value = trim($value);
        return in_array($value, ['primary', 'specialist', 'round_robin'], true)
            ? $value
            : 'round_robin';
    }

    private function routingKeywordsForMode(string $mode, string $raw): ?string
    {
        if ($mode !== 'specialist') {
            return null;
        }

        $parts = preg_split('/[,;\n]+/u', $raw) ?: [];
        $clean = [];
        $seen = [];
        foreach ($parts as $part) {
            $keyword = trim((string) $part);
            if ($keyword === '') {
                continue;
            }
            $key = mb_strtolower($keyword);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $clean[] = $keyword;
        }

        $value = implode(', ', $clean);
        if ($value === '') {
            throw new \RuntimeException('Informe ao menos uma intenção ou palavra para o assistente especialista.');
        }
        if (mb_strlen($value) > 1000) {
            throw new \RuntimeException('As palavras de direcionamento ultrapassam o limite de 1.000 caracteres.');
        }

        return $value;
    }

    /** @param int[] $instanceIds @param int[] $legacyPrimaryInstanceIds @return array<int,string> */
    private function routingModesFromPost(array $instanceIds, array $legacyPrimaryInstanceIds): array
    {
        $posted = is_array($_POST['routing_mode'] ?? null) ? $_POST['routing_mode'] : [];
        $result = [];
        foreach ($instanceIds as $instanceId) {
            $raw = (string) ($posted[(string) $instanceId] ?? $posted[$instanceId] ?? '');
            if ($raw === '') {
                $raw = in_array($instanceId, $legacyPrimaryInstanceIds, true) ? 'primary' : 'round_robin';
            }
            $result[$instanceId] = $this->routingModeFromValue($raw);
        }
        return $result;
    }

    /** @param int[] $instanceIds @param array<int,string> $modes @return array<int,?string> */
    private function routingKeywordsFromPost(array $instanceIds, array $modes): array
    {
        $posted = is_array($_POST['routing_keywords'] ?? null) ? $_POST['routing_keywords'] : [];
        $result = [];
        foreach ($instanceIds as $instanceId) {
            $raw = (string) ($posted[(string) $instanceId] ?? $posted[$instanceId] ?? '');
            $result[$instanceId] = $this->routingKeywordsForMode(
                $modes[$instanceId] ?? 'round_robin',
                $raw
            );
        }
        return $result;
    }

    /** @param int[] $instanceIds */
    private function assertInstancesBelongToTenant(PDO $pdo, int $tenantId, array $instanceIds): void
    {
        if ($instanceIds === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($instanceIds), '?'));
        $statement = $pdo->prepare(
            'SELECT id FROM evolution_instances WHERE tenant_id = ? AND id IN (' . $placeholders . ')'
        );
        $statement->execute(array_merge([$tenantId], $instanceIds));
        $found = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN) ?: []);
        sort($found);
        $expected = $instanceIds;
        sort($expected);
        if ($found !== $expected) {
            throw new \RuntimeException('Uma das conexões selecionadas não pertence a esta empresa.');
        }
    }

    /**
     * Atualiza o vínculo N:N entre assistente e canais e mantém ai_agents.instance_id
     * como compatibilidade para rotinas antigas.
     *
     * @param int[] $instanceIds
     * @param int[] $primaryInstanceIds
     */
    private function syncAgentChannels(
        PDO $pdo,
        int $tenantId,
        int $agentId,
        array $instanceIds,
        array $primaryInstanceIds,
        array $routingModesByInstance = [],
        array $routingKeywordsByInstance = []
    ): void
    {
        $legacyInstanceId = $primaryInstanceIds[0] ?? $instanceIds[0] ?? null;

        if (!$this->tableExists($pdo, 'ai_agent_instance_bindings')) {
            $updateLegacy = $pdo->prepare(
                'UPDATE ai_agents SET instance_id = :instance_id WHERE id = :agent_id AND tenant_id = :tenant_id'
            );
            $updateLegacy->execute([
                'instance_id' => $legacyInstanceId,
                'agent_id' => $agentId,
                'tenant_id' => $tenantId,
            ]);
            return;
        }

        $current = $pdo->prepare(
            'SELECT instance_id FROM ai_agent_instance_bindings WHERE tenant_id = :tenant_id AND agent_id = :agent_id'
        );
        $current->execute(['tenant_id' => $tenantId, 'agent_id' => $agentId]);
        $currentIds = array_map('intval', $current->fetchAll(PDO::FETCH_COLUMN) ?: []);
        $affectedInstanceIds = array_values(array_unique(array_merge($currentIds, $instanceIds)));

        if ($instanceIds === []) {
            $delete = $pdo->prepare(
                'DELETE FROM ai_agent_instance_bindings WHERE tenant_id = :tenant_id AND agent_id = :agent_id'
            );
            $delete->execute(['tenant_id' => $tenantId, 'agent_id' => $agentId]);
        } else {
            $placeholders = implode(',', array_fill(0, count($instanceIds), '?'));
            $delete = $pdo->prepare(
                'DELETE FROM ai_agent_instance_bindings
                 WHERE tenant_id = ? AND agent_id = ? AND instance_id NOT IN (' . $placeholders . ')'
            );
            $delete->execute(array_merge([$tenantId, $agentId], $instanceIds));

            $insert = $pdo->prepare(
                'INSERT INTO ai_agent_instance_bindings
                    (tenant_id, agent_id, instance_id, is_primary, priority, routing_keywords, status)
                 VALUES (:tenant_id, :agent_id, :instance_id, :is_primary, :priority, :routing_keywords, "active")
                 ON DUPLICATE KEY UPDATE
                    status = "active",
                    is_primary = VALUES(is_primary),
                    priority = VALUES(priority),
                    routing_keywords = VALUES(routing_keywords)'
            );

            foreach ($instanceIds as $instanceId) {
                $mode = $routingModesByInstance[$instanceId] ?? (
                    in_array($instanceId, $primaryInstanceIds, true) ? 'primary' : 'round_robin'
                );
                $isPrimary = $mode === 'primary';
                $routingKeywords = $mode === 'specialist'
                    ? ($routingKeywordsByInstance[$instanceId] ?? null)
                    : null;
                if ($isPrimary) {
                    $clearPrimary = $pdo->prepare(
                        'UPDATE ai_agent_instance_bindings
                         SET is_primary = 0
                         WHERE tenant_id = :tenant_id AND instance_id = :instance_id'
                    );
                    $clearPrimary->execute(['tenant_id' => $tenantId, 'instance_id' => $instanceId]);
                }
                $insert->execute([
                    'tenant_id' => $tenantId,
                    'agent_id' => $agentId,
                    'instance_id' => $instanceId,
                    'is_primary' => $isPrimary ? 1 : 0,
                    'priority' => $isPrimary ? 200 : 100,
                    'routing_keywords' => $routingKeywords,
                ]);
            }
        }

        foreach ($affectedInstanceIds as $instanceId) {
            $this->normalizePrimaryAgentForInstance($pdo, $tenantId, $instanceId);
        }

        $updateLegacy = $pdo->prepare(
            'UPDATE ai_agents SET instance_id = :instance_id WHERE id = :agent_id AND tenant_id = :tenant_id'
        );
        $updateLegacy->execute([
            'instance_id' => $legacyInstanceId,
            'agent_id' => $agentId,
            'tenant_id' => $tenantId,
        ]);
    }

    private function normalizePrimaryAgentForInstance(PDO $pdo, int $tenantId, int $instanceId): void
    {
        $primary = $pdo->prepare(
            'SELECT id
             FROM ai_agent_instance_bindings
             WHERE tenant_id = :tenant_id AND instance_id = :instance_id AND status = "active"
             ORDER BY is_primary DESC, priority DESC, id ASC
             LIMIT 1'
        );
        $primary->execute(['tenant_id' => $tenantId, 'instance_id' => $instanceId]);
        $primaryId = (int) ($primary->fetchColumn() ?: 0);
        if ($primaryId < 1) {
            return;
        }

        $normalize = $pdo->prepare(
            'UPDATE ai_agent_instance_bindings
             SET is_primary = CASE WHEN id = :primary_id THEN 1 ELSE 0 END
             WHERE tenant_id = :tenant_id AND instance_id = :instance_id AND status = "active"'
        );
        $normalize->execute([
            'primary_id' => $primaryId,
            'tenant_id' => $tenantId,
            'instance_id' => $instanceId,
        ]);
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = :table_name'
        );
        $statement->execute(['table_name' => $table]);
        return (int) $statement->fetchColumn() > 0;
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
    /** @return array{enabled:int,greeting:?string,gratitude:?string,farewell:?string,menu:?string,cache_enabled:int,cache_ttl_hours:int} */
    private function aiLocalAutomationFromPost(): array
    {
        $clean = static function (string $key, int $limit): ?string {
            $value = trim((string) ($_POST[$key] ?? ''));
            return $value !== '' ? mb_substr($value, 0, $limit) : null;
        };

        return [
            'enabled' => isset($_POST['ai_local_replies_enabled']) ? 1 : 0,
            'greeting' => $clean('ai_greeting_reply', 500),
            'gratitude' => $clean('ai_gratitude_reply', 500),
            'farewell' => $clean('ai_farewell_reply', 500),
            'menu' => $clean('ai_menu_reply', 4000),
            'cache_enabled' => isset($_POST['ai_exact_cache_enabled']) ? 1 : 0,
            'cache_ttl_hours' => max(1, min(720, (int) ($_POST['ai_exact_cache_ttl_hours'] ?? 168))),
        ];
    }

    private function aiEfficiencyModeFromPost(): string
    {
        $mode = strtolower(trim((string) ($_POST['ai_efficiency_mode'] ?? 'balanced')));
        return in_array($mode, ['economy', 'balanced', 'quality'], true) ? $mode : 'balanced';
    }

    private function nullableIntFromPost(string $key, int $min, int $max): ?int
    {
        $raw = trim((string) ($_POST[$key] ?? ''));
        if ($raw === '') {
            return null;
        }
        return max($min, min($max, (int) $raw));
    }

}
