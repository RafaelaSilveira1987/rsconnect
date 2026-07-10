<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Flash;
use App\Core\Router;
use App\Core\View;
use App\Services\SubscriptionService;
use PDO;
use Throwable;

final class AgentController
{
    public function index(): void
    {
        $tenantId = (int) Auth::tenantId();
        $pdo = Database::connection();

        $agentsStatement = $pdo->prepare(
            'SELECT a.*, i.name AS instance_name,
                    COALESCE(ac_agent.label, ac_tenant.label) AS credential_label,
                    COALESCE(ac_agent.provider, ac_tenant.provider) AS credential_provider,
                    COALESCE(ac_agent.default_model, ac_tenant.default_model) AS credential_model
             FROM ai_agents a
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

        $instancesStatement = $pdo->prepare(
            'SELECT id, name FROM evolution_instances WHERE tenant_id = :tenant_id ORDER BY is_default DESC, name'
        );
        $instancesStatement->execute(['tenant_id' => $tenantId]);

        View::render('agents.index', [
            'title' => 'Agentes de IA',
            'agents' => $agentsStatement->fetchAll(PDO::FETCH_ASSOC),
            'instances' => $instancesStatement->fetchAll(PDO::FETCH_ASSOC),
        ]);
    }

    public function store(): void
    {
        $tenantId = (int) Auth::tenantId();
        $instanceId = (int) ($_POST['instance_id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $segment = trim((string) ($_POST['segment'] ?? ''));
        $provider = (string) ($_POST['model_provider'] ?? 'openai');
        $model = trim((string) ($_POST['model_name'] ?? 'gpt-4o-mini'));
        $temperature = max(0, min(1, (float) ($_POST['temperature'] ?? 0.2)));
        $prompt = trim((string) ($_POST['system_prompt'] ?? ''));
        $knowledgeBase = trim((string) ($_POST['knowledge_base'] ?? ''));
        $handoffKeywords = trim((string) ($_POST['handoff_keywords'] ?? 'humano, atendente, pessoa, suporte'));
        $maxContextMessages = max(4, min(30, (int) ($_POST['max_context_messages'] ?? 12)));
        $n8nWebhookUrl = trim((string) ($_POST['n8n_webhook_url'] ?? ''));
        $autoReplyEnabled = isset($_POST['auto_reply_enabled']);
        $n8nEnabled = isset($_POST['n8n_enabled']);
        $isDefault = isset($_POST['is_default']);

        if ($instanceId < 1 || $name === '' || $segment === '' || $prompt === '') {
            Flash::set('error', 'Preencha instância, nome, segmento e prompt.');
            $this->redirect('/agents');
        }

        $limit = (new SubscriptionService())->ensureCanCreate($tenantId, 'agents');
        if (empty($limit['ok'])) {
            Flash::set('error', $limit['message']);
            $this->redirect('/agents');
        }

        $pdo = Database::connection();
        $check = $pdo->prepare(
            'SELECT id FROM evolution_instances WHERE id = :id AND tenant_id = :tenant_id LIMIT 1'
        );
        $check->execute(['id' => $instanceId, 'tenant_id' => $tenantId]);
        if (!$check->fetchColumn()) {
            Flash::set('error', 'Instância inválida para sua empresa.');
            $this->redirect('/agents');
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
                     business_hours_json, after_hours_message, human_handoff_message, handoff_action, cooldown_seconds)
                 VALUES
                    (:tenant_id, :instance_id, :name, :segment, :provider, :model, :temperature, :prompt,
                     "active", :is_default, :auto_reply_enabled, :handoff_keywords, :max_context_messages,
                     :knowledge_base, :n8n_enabled, :n8n_webhook_url, :business_hours_enabled, :business_timezone,
                     :business_hours_json, :after_hours_message, :human_handoff_message, :handoff_action, :cooldown_seconds)'
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
            ]);

            $pdo->commit();
            Audit::log('agent.created', ['agent_id' => (int) $pdo->lastInsertId(), 'name' => $name], $tenantId);
            Flash::set('success', 'Agente cadastrado. A chave de IA pode ser global da RS ou configurada no painel RS por cliente.');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Flash::set('error', 'Não foi possível cadastrar o agente: ' . $exception->getMessage());
        }

        $this->redirect('/agents');
    }

    public function updateStatus(): void
    {
        $tenantId = (int) Auth::tenantId();
        $agentId = (int) ($_POST['agent_id'] ?? 0);
        $status = (string) ($_POST['status'] ?? 'inactive');
        $autoReplyEnabled = isset($_POST['auto_reply_enabled']);
        $n8nEnabled = isset($_POST['n8n_enabled']);
        $handoffKeywords = trim((string) ($_POST['handoff_keywords'] ?? ''));
        $maxContextMessages = max(4, min(30, (int) ($_POST['max_context_messages'] ?? 12)));
        $n8nWebhookUrl = trim((string) ($_POST['n8n_webhook_url'] ?? ''));
        $isDefault = isset($_POST['is_default']);

        if ($agentId < 1 || !in_array($status, ['active', 'inactive'], true)) {
            Flash::set('error', 'Dados do agente inválidos.');
            $this->redirect('/agents');
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
                     cooldown_seconds = :cooldown_seconds
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
                'id' => $agentId,
                'tenant_id' => $tenantId,
            ]);
            if ($update->rowCount() < 1) {
                throw new \RuntimeException('Agente não encontrado.');
            }

            $pdo->commit();
            Audit::log('agent.status_updated', ['agent_id' => $agentId, 'status' => $status], $tenantId);
            Flash::set('success', 'Agente atualizado.');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Flash::set('error', $exception->getMessage());
        }

        $this->redirect('/agents');
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

    private function redirect(string $path): never
    {
        header('Location: ' . Router::url($path));
        exit;
    }
}
