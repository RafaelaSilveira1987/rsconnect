<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Flash;
use App\Core\Router;
use App\Core\View;
use PDO;
use Throwable;

final class AgentController
{
    public function index(): void
    {
        $tenantId = (int) Auth::tenantId();
        $pdo = Database::connection();

        $agentsStatement = $pdo->prepare(
            'SELECT a.*, i.name AS instance_name
             FROM ai_agents a
             LEFT JOIN evolution_instances i ON i.id = a.instance_id
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
        $provider = (string) ($_POST['model_provider'] ?? 'google');
        $model = trim((string) ($_POST['model_name'] ?? 'gemini-2.0-flash'));
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

            $insert = $pdo->prepare(
                'INSERT INTO ai_agents
                    (tenant_id, instance_id, name, segment, model_provider, model_name, temperature, system_prompt,
                     status, is_default, auto_reply_enabled, handoff_keywords, max_context_messages,
                     knowledge_base, n8n_enabled, n8n_webhook_url)
                 VALUES
                    (:tenant_id, :instance_id, :name, :segment, :provider, :model, :temperature, :prompt,
                     "active", :is_default, :auto_reply_enabled, :handoff_keywords, :max_context_messages,
                     :knowledge_base, :n8n_enabled, :n8n_webhook_url)'
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
            ]);

            $pdo->commit();
            Audit::log('agent.created', ['agent_id' => (int) $pdo->lastInsertId(), 'name' => $name], $tenantId);
            Flash::set('success', 'Agente cadastrado. Ative a resposta automática quando a chave da IA estiver configurada.');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Flash::set('error', 'Não foi possível cadastrar o agente.');
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
                     n8n_webhook_url = :n8n_webhook_url
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

    private function redirect(string $path): never
    {
        header('Location: ' . Router::url($path));
        exit;
    }
}
