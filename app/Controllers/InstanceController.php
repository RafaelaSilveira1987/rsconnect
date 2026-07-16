<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Env;
use App\Core\Flash;
use App\Core\Router;
use App\Core\View;
use App\Services\EvolutionService;
use App\Services\SubscriptionService;
use PDO;
use Throwable;

final class InstanceController
{
    public function index(): void
    {
        $pdo = Database::connection();

        if (Auth::isSuperAdmin()) {
            $campaignCountSql = $this->tableExists($pdo, 'message_campaigns')
                ? '(SELECT COUNT(*) FROM message_campaigns mc WHERE mc.evolution_instance_id = i.id)'
                : '0';

            $statement = $pdo->query(
                'SELECT i.*, t.name AS tenant_name,
                        (SELECT COUNT(*) FROM ai_agents a WHERE a.instance_id = i.id) AS agents_count,
                        (SELECT COUNT(*) FROM contacts ct WHERE ct.evolution_instance_id = i.id) AS contacts_count,
                        (SELECT COUNT(*) FROM conversations c WHERE c.evolution_instance_id = i.id) AS conversations_count,
                        ' . $campaignCountSql . ' AS campaigns_count
                 FROM evolution_instances i
                 INNER JOIN tenants t ON t.id = i.tenant_id
                 ORDER BY t.name, i.is_default DESC, i.created_at DESC'
            );
        } else {
            $statement = $pdo->prepare(
                'SELECT i.*, t.name AS tenant_name,
                        0 AS agents_count, 0 AS contacts_count, 0 AS conversations_count, 0 AS campaigns_count
                 FROM evolution_instances i
                 INNER JOIN tenants t ON t.id = i.tenant_id
                 WHERE i.tenant_id = :tenant_id
                 ORDER BY i.is_default DESC, i.created_at DESC'
            );
            $statement->execute(['tenant_id' => Auth::tenantId()]);
        }

        $instances = $statement->fetchAll(PDO::FETCH_ASSOC);
        $instancesByTenant = [];
        foreach ($instances as &$instance) {
            $instance['api_key_masked'] = $instance['api_key_encrypted'] ? '••••••••••••' : 'Não informada';
            unset($instance['api_key_encrypted']);
            $instancesByTenant[(int) $instance['tenant_id']][] = [
                'id' => (int) $instance['id'],
                'name' => (string) $instance['name'],
                'instance_name' => (string) $instance['instance_name'],
            ];
        }
        unset($instance);

        $tenants = [];
        $adminAgents = [];
        if (Auth::isSuperAdmin()) {
            $tenants = $pdo->query('SELECT id, name FROM tenants WHERE status = "active" ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
            $adminAgents = $pdo->query(
                'SELECT a.id, a.tenant_id, a.instance_id, a.name, a.segment, a.model_provider, a.model_name,
                        a.temperature, a.status, a.is_default, a.auto_reply_enabled, a.max_context_messages,
                        t.name AS tenant_name, i.name AS linked_instance_name
                 FROM ai_agents a
                 INNER JOIN tenants t ON t.id = a.tenant_id
                 LEFT JOIN evolution_instances i ON i.id = a.instance_id
                 ORDER BY t.name, a.is_default DESC, a.name'
            )->fetchAll(PDO::FETCH_ASSOC);
        }

        View::render('instances.index', [
            'title' => 'Conexões WhatsApp',
            'instances' => $instances,
            'tenants' => $tenants,
            'adminAgents' => $adminAgents,
            'instancesByTenant' => $instancesByTenant,
            'defaultUrl' => (string) Env::get('EVOLUTION_DEFAULT_URL', ''),
        ]);
    }

    public function store(): void
    {
        $tenantId = Auth::isSuperAdmin()
            ? (int) ($_POST['tenant_id'] ?? 0)
            : (int) Auth::tenantId();

        $name = trim((string) ($_POST['name'] ?? ''));
        $instanceName = trim((string) ($_POST['instance_name'] ?? ''));
        $baseUrl = rtrim(trim((string) ($_POST['base_url'] ?? '')), '/');
        $apiKey = trim((string) ($_POST['api_key'] ?? ''));
        $status = (string) ($_POST['status'] ?? 'disconnected');

        if ($tenantId < 1 || $name === '' || $instanceName === '' || !filter_var($baseUrl, FILTER_VALIDATE_URL) || $apiKey === '') {
            Flash::set('error', 'Preencha empresa, nome, identificador da Evolution, URL válida e API Key.');
            $this->redirect('/instances');
        }

        if (!in_array($status, ['connected', 'disconnected', 'pending'], true)) {
            $status = 'disconnected';
        }

        $limit = (new SubscriptionService())->ensureCanCreate($tenantId, 'instances');
        if (empty($limit['ok'])) {
            Flash::set('error', $limit['message']);
            $this->redirect('/instances');
        }

        try {
            $pdo = Database::connection();

            $duplicate = $pdo->prepare(
                'SELECT id, name
                 FROM evolution_instances
                 WHERE tenant_id = :tenant_id
                   AND LOWER(instance_name) = LOWER(:instance_name)
                 LIMIT 1'
            );
            $duplicate->execute([
                'tenant_id' => $tenantId,
                'instance_name' => $instanceName,
            ]);
            $existingInstance = $duplicate->fetch(PDO::FETCH_ASSOC);

            if ($existingInstance) {
                Flash::set(
                    'error',
                    'A instância "' . $instanceName . '" já está cadastrada para esta empresa como "' .
                    $existingInstance['name'] . '". Use outro nome na Evolution ou atualize a instância existente.'
                );
                $this->redirect('/instances');
            }

            $pdo->beginTransaction();
            $isDefault = isset($_POST['is_default']);
            if ($isDefault) {
                $reset = $pdo->prepare('UPDATE evolution_instances SET is_default = 0 WHERE tenant_id = :tenant_id');
                $reset->execute(['tenant_id' => $tenantId]);
            }

            $statement = $pdo->prepare(
                'INSERT INTO evolution_instances
                    (tenant_id, name, instance_name, base_url, api_key_encrypted, status, is_default)
                 VALUES
                    (:tenant_id, :name, :instance_name, :base_url, :api_key, :status, :is_default)'
            );
            $statement->execute([
                'tenant_id' => $tenantId,
                'name' => $name,
                'instance_name' => $instanceName,
                'base_url' => $baseUrl,
                'api_key' => Crypto::encrypt($apiKey),
                'status' => $status,
                'is_default' => $isDefault ? 1 : 0,
            ]);
            $instanceId = (int) $pdo->lastInsertId();
            $pdo->commit();

            $this->audit($tenantId, 'evolution.instance_created', [
                'instance_id' => $instanceId,
                'instance_name' => $instanceName,
            ]);
            Flash::set('success', 'Instância cadastrada com segurança.');
        } catch (Throwable $exception) {
            if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $isDuplicate = str_contains($exception->getMessage(), 'uq_instance_tenant_name')
                || str_contains($exception->getMessage(), 'Duplicate entry');

            Flash::set(
                'error',
                $isDuplicate
                    ? 'Essa conexão já está cadastrada para esta empresa. Use outro nome na Evolution ou atualize o cadastro existente.'
                    : 'Não foi possível cadastrar a instância. Verifique os dados informados e tente novamente.'
            );
        }

        $this->redirect('/instances');
    }

    /** Atualização técnica exclusiva do Super Admin RS. */
    public function update(): void
    {
        $instanceId = (int) ($_POST['instance_id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $instanceName = trim((string) ($_POST['instance_name'] ?? ''));
        $baseUrl = rtrim(trim((string) ($_POST['base_url'] ?? '')), '/');
        $apiKey = trim((string) ($_POST['api_key'] ?? ''));
        $status = (string) ($_POST['status'] ?? 'disconnected');
        $isDefault = isset($_POST['is_default']);

        if ($instanceId < 1 || $name === '' || $instanceName === '' || !filter_var($baseUrl, FILTER_VALIDATE_URL)) {
            Flash::set('error', 'Informe nome interno, nome na Evolution e URL válida.');
            $this->redirect('/instances');
        }
        if (!in_array($status, ['connected', 'disconnected', 'pending'], true)) {
            $status = 'disconnected';
        }

        $pdo = Database::connection();
        try {
            $source = $this->findInstance($pdo, $instanceId);
            if (!$source) {
                throw new \RuntimeException('Instância não encontrada.');
            }

            $duplicate = $pdo->prepare(
                'SELECT id FROM evolution_instances
                 WHERE tenant_id = :tenant_id
                   AND LOWER(instance_name) = LOWER(:instance_name)
                   AND id <> :id
                 LIMIT 1'
            );
            $duplicate->execute([
                'tenant_id' => (int) $source['tenant_id'],
                'instance_name' => $instanceName,
                'id' => $instanceId,
            ]);
            if ($duplicate->fetchColumn()) {
                throw new \RuntimeException('Já existe outra instância com esse nome na mesma empresa.');
            }

            $pdo->beginTransaction();
            if ($isDefault) {
                $reset = $pdo->prepare('UPDATE evolution_instances SET is_default = 0 WHERE tenant_id = :tenant_id');
                $reset->execute(['tenant_id' => (int) $source['tenant_id']]);
            }

            $sql = 'UPDATE evolution_instances
                    SET name = :name,
                        instance_name = :instance_name,
                        base_url = :base_url,
                        status = :status,
                        is_default = :is_default';
            $params = [
                'name' => $name,
                'instance_name' => $instanceName,
                'base_url' => $baseUrl,
                'status' => $status,
                'is_default' => $isDefault ? 1 : 0,
                'id' => $instanceId,
            ];
            if ($apiKey !== '') {
                $sql .= ', api_key_encrypted = :api_key';
                $params['api_key'] = Crypto::encrypt($apiKey);
            }
            $sql .= ' WHERE id = :id';

            $update = $pdo->prepare($sql);
            $update->execute($params);
            $pdo->commit();

            $this->audit((int) $source['tenant_id'], 'evolution.instance_updated', [
                'instance_id' => $instanceId,
                'old_instance_name' => (string) $source['instance_name'],
                'new_instance_name' => $instanceName,
                'api_key_replaced' => $apiKey !== '',
            ]);
            Flash::set('success', 'Instância atualizada. Os vínculos com conversas e agentes foram preservados.');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Flash::set('error', 'Não foi possível atualizar a instância: ' . $exception->getMessage());
        }

        $this->redirect('/instances');
    }

    /** Recupera ou altera a associação técnica do agente com a instância. */
    public function updateAgent(): void
    {
        $agentId = (int) ($_POST['agent_id'] ?? 0);
        $instanceId = (int) ($_POST['instance_id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $segment = trim((string) ($_POST['segment'] ?? ''));
        $provider = (string) ($_POST['model_provider'] ?? 'openai');
        $model = trim((string) ($_POST['model_name'] ?? 'gpt-4o-mini'));
        $temperature = max(0, min(1, (float) ($_POST['temperature'] ?? 0.2)));
        $status = (string) ($_POST['status'] ?? 'active');
        $maxContext = max(4, min(30, (int) ($_POST['max_context_messages'] ?? 12)));
        $autoReply = isset($_POST['auto_reply_enabled']);
        $isDefault = isset($_POST['is_default']);

        if ($agentId < 1 || $instanceId < 1 || $name === '' || $segment === '' || $model === '') {
            Flash::set('error', 'Informe agente, instância, nome, segmento e modelo.');
            $this->redirect('/instances');
        }
        if (!in_array($provider, ['google', 'openai', 'anthropic', 'custom'], true)) {
            $provider = 'openai';
        }
        if (!in_array($status, ['active', 'inactive'], true)) {
            $status = 'inactive';
        }

        $pdo = Database::connection();
        try {
            $agentStatement = $pdo->prepare('SELECT * FROM ai_agents WHERE id = :id LIMIT 1');
            $agentStatement->execute(['id' => $agentId]);
            $agent = $agentStatement->fetch(PDO::FETCH_ASSOC);
            if (!$agent) {
                throw new \RuntimeException('Agente não encontrado.');
            }

            $instance = $this->findInstance($pdo, $instanceId);
            if (!$instance || (int) $instance['tenant_id'] !== (int) $agent['tenant_id']) {
                throw new \RuntimeException('A conexão escolhida não pertence à mesma empresa do agente.');
            }

            $pdo->beginTransaction();
            if ($isDefault) {
                $reset = $pdo->prepare('UPDATE ai_agents SET is_default = 0 WHERE tenant_id = :tenant_id');
                $reset->execute(['tenant_id' => (int) $agent['tenant_id']]);
            }

            $update = $pdo->prepare(
                'UPDATE ai_agents
                 SET instance_id = :instance_id,
                     name = :name,
                     segment = :segment,
                     model_provider = :provider,
                     model_name = :model,
                     temperature = :temperature,
                     status = :status,
                     auto_reply_enabled = :auto_reply_enabled,
                     is_default = :is_default,
                     max_context_messages = :max_context_messages
                 WHERE id = :id'
            );
            $update->execute([
                'instance_id' => $instanceId,
                'name' => $name,
                'segment' => $segment,
                'provider' => $provider,
                'model' => $model,
                'temperature' => $temperature,
                'status' => $status,
                'auto_reply_enabled' => $autoReply ? 1 : 0,
                'is_default' => $isDefault ? 1 : 0,
                'max_context_messages' => $maxContext,
                'id' => $agentId,
            ]);
            $pdo->commit();

            $this->audit((int) $agent['tenant_id'], 'agent.technical_updated', [
                'agent_id' => $agentId,
                'old_instance_id' => $agent['instance_id'] !== null ? (int) $agent['instance_id'] : null,
                'new_instance_id' => $instanceId,
                'status' => $status,
                'auto_reply_enabled' => $autoReply,
            ]);
            Flash::set('success', 'Agente atualizado e associado à conexão selecionada. Prompt, base e credenciais foram preservados.');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Flash::set('error', 'Não foi possível atualizar o agente: ' . $exception->getMessage());
        }

        $this->redirect('/instances');
    }

    /** Exclui somente o cadastro no RS Connect, com migração opcional para outra instância. */
    public function delete(): void
    {
        $instanceId = (int) ($_POST['instance_id'] ?? 0);
        $replacementId = (int) ($_POST['replacement_instance_id'] ?? 0);
        $confirmation = trim((string) ($_POST['confirmation'] ?? ''));

        $pdo = Database::connection();
        try {
            $source = $this->findInstance($pdo, $instanceId);
            if (!$source) {
                throw new \RuntimeException('Instância não encontrada.');
            }

            $expected = 'EXCLUIR ' . (string) $source['instance_name'];
            if (!hash_equals($expected, $confirmation)) {
                throw new \RuntimeException('Confirmação inválida. Digite exatamente: ' . $expected);
            }

            $replacement = null;
            if ($replacementId > 0) {
                if ($replacementId === $instanceId) {
                    throw new \RuntimeException('A instância de substituição deve ser diferente da instância excluída.');
                }
                $replacement = $this->findInstance($pdo, $replacementId);
                if (!$replacement || (int) $replacement['tenant_id'] !== (int) $source['tenant_id']) {
                    throw new \RuntimeException('A instância de substituição não pertence à mesma empresa.');
                }
            }

            $counts = $this->dependencyCounts($pdo, $instanceId);
            $totalDependencies = array_sum($counts);
            if ($totalDependencies > 0 && !$replacement) {
                throw new \RuntimeException(
                    'Essa instância possui vínculos (' . $this->dependencySummary($counts) . '). Selecione uma instância de substituição para preservar os dados.'
                );
            }

            $pdo->beginTransaction();
            $migrationStats = ['agents' => 0, 'contacts' => 0, 'conversations' => 0, 'merged_conversations' => 0, 'campaigns' => 0];

            if ($replacement) {
                $migrationStats['agents'] = $this->updateReference($pdo, 'ai_agents', 'instance_id', $instanceId, $replacementId);
                $migrationStats['contacts'] = $this->updateReference($pdo, 'contacts', 'evolution_instance_id', $instanceId, $replacementId);
                if ($this->tableExists($pdo, 'message_campaigns')) {
                    $migrationStats['campaigns'] = $this->updateReference($pdo, 'message_campaigns', 'evolution_instance_id', $instanceId, $replacementId);
                }
                $conversationStats = $this->migrateConversations($pdo, $instanceId, $replacementId, (int) $source['tenant_id']);
                $migrationStats['conversations'] = $conversationStats['moved'];
                $migrationStats['merged_conversations'] = $conversationStats['merged'];

                if ((int) $source['is_default'] === 1) {
                    $default = $pdo->prepare('UPDATE evolution_instances SET is_default = 1 WHERE id = :id');
                    $default->execute(['id' => $replacementId]);
                }
            }

            $delete = $pdo->prepare('DELETE FROM evolution_instances WHERE id = :id');
            $delete->execute(['id' => $instanceId]);
            if ($delete->rowCount() < 1) {
                throw new \RuntimeException('A instância não foi excluída.');
            }
            $pdo->commit();

            $this->audit((int) $source['tenant_id'], 'evolution.instance_deleted', [
                'instance_id' => $instanceId,
                'instance_name' => (string) $source['instance_name'],
                'replacement_instance_id' => $replacementId > 0 ? $replacementId : null,
                'migration_stats' => $migrationStats,
            ]);
            Flash::set('success', $replacement
                ? 'Instância excluída do RS Connect e vínculos migrados para a substituta.'
                : 'Instância sem vínculos excluída do RS Connect.');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Flash::set('error', 'Não foi possível excluir a instância: ' . $exception->getMessage());
        }

        $this->redirect('/instances');
    }

    public function sendTest(): void
    {
        $instanceId = (int) ($_POST['instance_id'] ?? 0);
        $phone = preg_replace('/\D+/', '', (string) ($_POST['phone'] ?? '')) ?: '';
        $message = trim((string) ($_POST['message'] ?? ''));

        if ($instanceId < 1 || strlen($phone) < 10 || $message === '') {
            Flash::set('error', 'Selecione a instância, informe o telefone completo e uma mensagem.');
            $this->redirect('/instances');
        }

        $pdo = Database::connection();
        $sql = 'SELECT * FROM evolution_instances WHERE id = :id';
        $params = ['id' => $instanceId];

        if (!Auth::isSuperAdmin()) {
            $sql .= ' AND tenant_id = :tenant_id';
            $params['tenant_id'] = Auth::tenantId();
        }

        $statement = $pdo->prepare($sql . ' LIMIT 1');
        $statement->execute($params);
        $instance = $statement->fetch(PDO::FETCH_ASSOC);

        if (!$instance) {
            Flash::set('error', 'Instância não encontrada para sua empresa.');
            $this->redirect('/instances');
        }

        try {
            $verifySsl = filter_var(
                Env::get('EVOLUTION_SSL_VERIFY', true),
                FILTER_VALIDATE_BOOL,
                FILTER_NULL_ON_FAILURE
            );
            $caBundle = trim((string) Env::get('EVOLUTION_CA_BUNDLE', ''));

            $service = new EvolutionService(
                $instance['base_url'],
                Crypto::decrypt($instance['api_key_encrypted']),
                $instance['instance_name'],
                20,
                $verifySsl ?? true,
                $caBundle !== '' ? $caBundle : null
            );
            $result = $service->sendText($phone, $message);

            $this->audit((int) $instance['tenant_id'], 'evolution.test_sent', [
                'instance_id' => $instanceId,
                'phone' => $phone,
                'http_status' => $result['status'],
            ]);

            Flash::set('success', 'Mensagem de teste enviada. HTTP ' . $result['status'] . '.');
        } catch (Throwable $exception) {
            $this->audit((int) $instance['tenant_id'], 'evolution.test_failed', [
                'instance_id' => $instanceId,
                'error' => $exception->getMessage(),
            ]);
            Flash::set('error', 'Falha no envio: ' . $exception->getMessage());
        }

        $this->redirect('/instances');
    }

    private function migrateConversations(PDO $pdo, int $sourceInstanceId, int $replacementInstanceId, int $tenantId): array
    {
        $statement = $pdo->prepare(
            'SELECT id, remote_jid, unread_count, last_message_at, last_message_preview, status
             FROM conversations
             WHERE tenant_id = :tenant_id AND evolution_instance_id = :instance_id
             ORDER BY id'
        );
        $statement->execute(['tenant_id' => $tenantId, 'instance_id' => $sourceInstanceId]);
        $conversations = $statement->fetchAll(PDO::FETCH_ASSOC);

        $moved = 0;
        $merged = 0;
        foreach ($conversations as $conversation) {
            $targetStatement = $pdo->prepare(
                'SELECT id, unread_count, last_message_at, last_message_preview, status
                 FROM conversations
                 WHERE tenant_id = :tenant_id
                   AND evolution_instance_id = :replacement_id
                   AND remote_jid = :remote_jid
                 LIMIT 1'
            );
            $targetStatement->execute([
                'tenant_id' => $tenantId,
                'replacement_id' => $replacementInstanceId,
                'remote_jid' => $conversation['remote_jid'],
            ]);
            $target = $targetStatement->fetch(PDO::FETCH_ASSOC);

            if (!$target) {
                $move = $pdo->prepare('UPDATE conversations SET evolution_instance_id = :replacement_id WHERE id = :id');
                $move->execute(['replacement_id' => $replacementInstanceId, 'id' => (int) $conversation['id']]);
                $moved++;
                continue;
            }

            $sourceConversationId = (int) $conversation['id'];
            $targetConversationId = (int) $target['id'];
            $this->moveConversationChildren($pdo, $sourceConversationId, $targetConversationId);

            $sourceIsNewer = $conversation['last_message_at'] !== null
                && ($target['last_message_at'] === null || (string) $conversation['last_message_at'] > (string) $target['last_message_at']);
            $mergedStatus = ($conversation['status'] === 'open' || $target['status'] === 'open') ? 'open' : (string) $target['status'];
            $merge = $pdo->prepare(
                'UPDATE conversations
                 SET unread_count = :unread_count,
                     last_message_at = :last_message_at,
                     last_message_preview = :last_message_preview,
                     status = :status
                 WHERE id = :id'
            );
            $merge->execute([
                'unread_count' => (int) $conversation['unread_count'] + (int) $target['unread_count'],
                'last_message_at' => $sourceIsNewer ? $conversation['last_message_at'] : $target['last_message_at'],
                'last_message_preview' => $sourceIsNewer ? $conversation['last_message_preview'] : $target['last_message_preview'],
                'status' => $mergedStatus,
                'id' => $targetConversationId,
            ]);

            $delete = $pdo->prepare('DELETE FROM conversations WHERE id = :id');
            $delete->execute(['id' => $sourceConversationId]);
            $merged++;
        }

        return ['moved' => $moved, 'merged' => $merged];
    }

    private function moveConversationChildren(PDO $pdo, int $sourceConversationId, int $targetConversationId): void
    {
        $references = [
            ['conversation_messages', 'conversation_id'],
            ['conversation_events', 'conversation_id'],
            ['ai_automation_logs', 'conversation_id'],
            ['calendar_appointments', 'conversation_id'],
            ['conversation_internal_notes', 'conversation_id'],
            ['privacy_consents', 'conversation_id'],
            ['crm_leads', 'source_conversation_id'],
        ];

        foreach ($references as [$table, $column]) {
            if (!$this->tableExists($pdo, $table) || !$this->columnExists($pdo, $table, $column)) {
                continue;
            }
            $statement = $pdo->prepare("UPDATE `{$table}` SET `{$column}` = :target_id WHERE `{$column}` = :source_id");
            $statement->execute(['target_id' => $targetConversationId, 'source_id' => $sourceConversationId]);
        }
    }

    private function dependencyCounts(PDO $pdo, int $instanceId): array
    {
        $counts = [
            'agents' => $this->referenceCount($pdo, 'ai_agents', 'instance_id', $instanceId),
            'contacts' => $this->referenceCount($pdo, 'contacts', 'evolution_instance_id', $instanceId),
            'conversations' => $this->referenceCount($pdo, 'conversations', 'evolution_instance_id', $instanceId),
            'campaigns' => $this->tableExists($pdo, 'message_campaigns')
                ? $this->referenceCount($pdo, 'message_campaigns', 'evolution_instance_id', $instanceId)
                : 0,
        ];
        return $counts;
    }

    private function dependencySummary(array $counts): string
    {
        return sprintf(
            '%d agente(s), %d contato(s), %d conversa(s), %d campanha(s)',
            (int) ($counts['agents'] ?? 0),
            (int) ($counts['contacts'] ?? 0),
            (int) ($counts['conversations'] ?? 0),
            (int) ($counts['campaigns'] ?? 0)
        );
    }

    private function updateReference(PDO $pdo, string $table, string $column, int $sourceId, int $replacementId): int
    {
        $statement = $pdo->prepare("UPDATE `{$table}` SET `{$column}` = :replacement_id WHERE `{$column}` = :source_id");
        $statement->execute(['replacement_id' => $replacementId, 'source_id' => $sourceId]);
        return $statement->rowCount();
    }

    private function referenceCount(PDO $pdo, string $table, string $column, int $instanceId): int
    {
        if (!$this->tableExists($pdo, $table) || !$this->columnExists($pdo, $table, $column)) {
            return 0;
        }
        $statement = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` = :instance_id");
        $statement->execute(['instance_id' => $instanceId]);
        return (int) $statement->fetchColumn();
    }

    private function findInstance(PDO $pdo, int $instanceId): ?array
    {
        $statement = $pdo->prepare('SELECT * FROM evolution_instances WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $instanceId]);
        $instance = $statement->fetch(PDO::FETCH_ASSOC);
        return $instance ?: null;
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

    private function columnExists(PDO $pdo, string $table, string $column): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name'
        );
        $statement->execute(['table_name' => $table, 'column_name' => $column]);
        return (int) $statement->fetchColumn() > 0;
    }

    private function audit(int $tenantId, string $action, array $context): void
    {
        $statement = Database::connection()->prepare(
            'INSERT INTO audit_logs (tenant_id, user_id, action, context_json, ip_address)
             VALUES (:tenant_id, :user_id, :action, :context_json, :ip_address)'
        );
        $statement->execute([
            'tenant_id' => $tenantId,
            'user_id' => Auth::id(),
            'action' => $action,
            'context_json' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    }

    private function redirect(string $path): never
    {
        header('Location: ' . Router::url($path));
        exit;
    }
}
