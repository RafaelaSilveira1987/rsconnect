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
use App\Services\AgentRoutingService;
use App\Services\SubscriptionService;
use PDO;
use Throwable;

final class InstanceController
{
    public function index(): void
    {
        $pdo = Database::connection();

        $routingAvailable = $this->tableExists($pdo, 'ai_agent_instance_bindings');
        $agentCountSql = $routingAvailable
            ? '(SELECT COUNT(*) FROM ai_agent_instance_bindings b WHERE b.instance_id = i.id AND b.status = "active")'
            : '(SELECT COUNT(*) FROM ai_agents a WHERE a.instance_id = i.id)';

        if (Auth::isSuperAdmin()) {
            $campaignCountSql = $this->tableExists($pdo, 'message_campaigns')
                ? '(SELECT COUNT(*) FROM message_campaigns mc WHERE mc.evolution_instance_id = i.id)'
                : '0';

            $statement = $pdo->query(
                'SELECT i.*, t.name AS tenant_name,
                        ' . $agentCountSql . ' AS agents_count,
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
                        ' . $agentCountSql . ' AS agents_count,
                        (SELECT COUNT(*) FROM contacts ct WHERE ct.evolution_instance_id = i.id) AS contacts_count,
                        (SELECT COUNT(*) FROM conversations c WHERE c.evolution_instance_id = i.id) AS conversations_count,
                        0 AS campaigns_count
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
        } else {
            $agentStatement = $pdo->prepare(
                'SELECT id, tenant_id, instance_id, name, segment, status, is_default, auto_reply_enabled
                 FROM ai_agents WHERE tenant_id = :tenant_id ORDER BY status = "active" DESC, is_default DESC, name'
            );
            $agentStatement->execute(['tenant_id' => (int) Auth::tenantId()]);
            $adminAgents = $agentStatement->fetchAll(PDO::FETCH_ASSOC);
        }

        $routingByInstance = [];
        $routingService = new AgentRoutingService();
        foreach ($instances as $instance) {
            $routingByInstance[(int) $instance['id']] = $routingService->agentsForInstance(
                $pdo,
                (int) $instance['tenant_id'],
                (int) $instance['id'],
                false
            );
        }

        $channelPlan = null;
        $channelUsage = null;
        if (!Auth::isSuperAdmin() && Auth::tenantId()) {
            $subscription = new SubscriptionService();
            $channelPlan = $subscription->currentPlanForTenant((int) Auth::tenantId());
            $channelUsage = $subscription->usageForTenant((int) Auth::tenantId());
        }

        View::render('instances.index', [
            'title' => 'Conexões WhatsApp',
            'instances' => $instances,
            'tenants' => $tenants,
            'adminAgents' => $adminAgents,
            'instancesByTenant' => $instancesByTenant,
            'defaultUrl' => (string) Env::get('EVOLUTION_DEFAULT_URL', ''),
            'routingByInstance' => $routingByInstance,
            'channelPlan' => $channelPlan,
            'channelUsage' => $channelUsage,
        ]);
    }


    public function statusFeed(): void
    {
        header('Content-Type: application/json; charset=UTF-8');
        $tenantId = Auth::isSuperAdmin() ? (int) ($_GET['tenant_id'] ?? 0) : (int) (Auth::tenantId() ?? 0);
        $instanceId = (int) ($_GET['instance_id'] ?? 0);

        $conditions = [];
        $params = [];
        if (!Auth::isSuperAdmin()) {
            $conditions[] = 'tenant_id = :tenant_id';
            $params['tenant_id'] = $tenantId;
        } elseif ($tenantId > 0) {
            $conditions[] = 'tenant_id = :tenant_id';
            $params['tenant_id'] = $tenantId;
        }
        if ($instanceId > 0) {
            $conditions[] = 'id = :instance_id';
            $params['instance_id'] = $instanceId;
        }

        $sql = 'SELECT id, tenant_id, name, instance_name, base_url, api_key_encrypted,
                       status, connection_state, connection_reason,
                       connection_updated_at, last_status_check_at, last_webhook_at,
                       profile_name, profile_phone, profile_picture_url,
                       qrcode_base64, qrcode_updated_at, qrcode_expires_at
                FROM evolution_instances';
        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }
        $sql .= ' ORDER BY id';

        try {
            $pdo = Database::connection();
            $statement = $pdo->prepare($sql);
            $statement->execute($params);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $now = time();
            $liveChecks = 0;
            $freshForSeconds = 8;

            foreach ($rows as &$row) {
                $row['_live_check'] = 'cached';
                $lastCheck = !empty($row['last_status_check_at']) ? strtotime((string) $row['last_status_check_at']) : false;
                $hasRealState = trim((string) ($row['connection_state'] ?? '')) !== '';
                $isFresh = $lastCheck !== false && ($now - $lastCheck) < $freshForSeconds;

                // Nunca confia apenas no status manual quando connection_state ainda está vazio.
                if ($liveChecks >= 8 || ($hasRealState && $isFresh)) {
                    continue;
                }

                $row['_live_check'] = 'requested';
                try {
                    $verifySsl = filter_var(Env::get('EVOLUTION_SSL_VERIFY', true), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
                    $caBundle = trim((string) Env::get('EVOLUTION_CA_BUNDLE', ''));
                    $service = new EvolutionService(
                        (string) $row['base_url'],
                        Crypto::decrypt((string) $row['api_key_encrypted']),
                        (string) $row['instance_name'],
                        8,
                        $verifySsl ?? true,
                        $caBundle !== '' ? $caBundle : null
                    );
                    $live = $service->connectionState();
                    $state = mb_strtolower(trim((string) ($live['state'] ?? '')));
                    if ($state === '') {
                        throw new \RuntimeException('A Evolution respondeu sem informar instance.state.');
                    }

                    $connected = in_array($state, ['open', 'connected', 'online', 'active'], true);
                    $pending = in_array($state, ['connecting', 'qrcode', 'qr', 'pending', 'created'], true);
                    $mappedStatus = $connected ? 'connected' : ($pending ? 'pending' : 'disconnected');

                    // SQL separado elimina qualquer ambiguidade de parâmetros PDO/MariaDB.
                    $updateSql = 'UPDATE evolution_instances
                                  SET connection_state = :state,
                                      status = :status,
                                      connection_reason = NULL,
                                      last_status_check_at = NOW(),
                                      connection_updated_at = NOW()';
                    if ($connected) {
                        $updateSql .= ', qrcode_base64 = NULL, qrcode_expires_at = NULL';
                    }
                    $updateSql .= ' WHERE id = :id';

                    $update = $pdo->prepare($updateSql);
                    $update->execute([
                        'state' => $state,
                        'status' => $mappedStatus,
                        'id' => (int) $row['id'],
                    ]);

                    $row['connection_state'] = $state;
                    $row['status'] = $mappedStatus;
                    $row['connection_reason'] = '';
                    $row['last_status_check_at'] = \App\Core\Clock::nowUtc();
                    $row['connection_updated_at'] = \App\Core\Clock::nowUtc();
                    $row['_live_check'] = 'updated';
                } catch (Throwable $exception) {
                    $safeReason = mb_substr('Falha na consulta em tempo real: ' . $exception->getMessage(), 0, 255);
                    error_log(
                        '[InstanceController::statusFeed v36.6.38][instance_id=' . (int) $row['id'] .
                        '][instance=' . (string) $row['instance_name'] . '] ' . $exception->getMessage()
                    );
                    try {
                        $errorUpdate = $pdo->prepare(
                            'UPDATE evolution_instances
                             SET last_status_check_at = NOW(), connection_reason = :reason
                             WHERE id = :id'
                        );
                        $errorUpdate->execute([
                            'reason' => $safeReason,
                            'id' => (int) $row['id'],
                        ]);
                        $row['last_status_check_at'] = \App\Core\Clock::nowUtc();
                        $row['connection_reason'] = $safeReason;
                        $row['_live_check'] = 'failed';
                    } catch (Throwable $databaseException) {
                        error_log(
                            '[InstanceController::statusFeed v36.6.38][instance_id=' . (int) $row['id'] .
                            '] Falha ao registrar erro: ' . $databaseException->getMessage()
                        );
                    }
                }
                $liveChecks++;
            }
            unset($row);

            $items = [];
            foreach ($rows as $row) {
                $expiresAt = !empty($row['qrcode_expires_at']) ? strtotime((string) $row['qrcode_expires_at']) : false;
                $qrValid = !empty($row['qrcode_base64']) && $expiresAt !== false && $expiresAt >= $now;
                $state = trim((string) (($row['connection_state'] ?? '') ?: ($row['status'] ?? 'unknown')));
                $items[] = [
                    'id' => (int) $row['id'],
                    'tenant_id' => (int) $row['tenant_id'],
                    'name' => (string) $row['name'],
                    'instance_name' => (string) $row['instance_name'],
                    'status' => (string) $row['status'],
                    'connection_state' => $state,
                    'status_label' => $this->connectionLabel($state, (string) $row['status']),
                    'reason' => (string) ($row['connection_reason'] ?? ''),
                    'updated_at' => (string) (($row['connection_updated_at'] ?? '') ?: ($row['last_status_check_at'] ?? '') ?: ($row['last_webhook_at'] ?? '')),
                    'profile_name' => (string) ($row['profile_name'] ?? ''),
                    'profile_phone' => (string) ($row['profile_phone'] ?? ''),
                    'profile_picture_url' => (string) ($row['profile_picture_url'] ?? ''),
                    'qr_ready' => $qrValid,
                    'qr_code' => $instanceId > 0 && $qrValid ? (string) $row['qrcode_base64'] : null,
                    'live_check' => (string) ($row['_live_check'] ?? 'unknown'),
                ];
            }

            echo json_encode([
                'ok' => true,
                'source_version' => '36.6.38-live-status',
                'items' => $items,
                'checked_at' => date(DATE_ATOM),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (Throwable $exception) {
            error_log('[InstanceController::statusFeed v36.6.38] ' . $exception->getMessage());
            http_response_code(500);
            echo json_encode([
                'ok' => false,
                'source_version' => '36.6.38-live-status',
                'message' => 'Não foi possível atualizar o status das conexões.',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
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
        $status = 'pending';

        if ($tenantId < 1 || $name === '' || $instanceName === '' || !filter_var($baseUrl, FILTER_VALIDATE_URL) || $apiKey === '') {
            Flash::set('error', 'Preencha empresa, nome, identificador da Evolution, URL válida e API Key.');
            $this->redirect('/instances');
        }


        $limit = (new SubscriptionService())->ensureCanCreate($tenantId, 'instances');
        if (empty($limit['ok'])) {
            Flash::set('error', $limit['message']);
            $this->redirect('/instances');
        }

        try {
            $pdo = Database::connection();

            $duplicate = $pdo->prepare(
                'SELECT id, name, tenant_id
                 FROM evolution_instances
                 WHERE LOWER(base_url) = LOWER(:base_url)
                   AND LOWER(instance_name) = LOWER(:instance_name)
                 LIMIT 1'
            );
            $duplicate->execute([
                'base_url' => $baseUrl,
                'instance_name' => $instanceName,
            ]);
            $existingInstance = $duplicate->fetch(PDO::FETCH_ASSOC);

            if ($existingInstance) {
                Flash::set(
                    'error',
                    'A instância "' . $instanceName . '" já está cadastrada nesta mesma Evolution como "' .
                    $existingInstance['name'] . '". Atualize o cadastro existente em vez de criar outro.'
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

            try {
                $verifySsl = filter_var(Env::get('EVOLUTION_SSL_VERIFY', true), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
                $caBundle = trim((string) Env::get('EVOLUTION_CA_BUNDLE', ''));
                $service = new EvolutionService(
                    $baseUrl,
                    $apiKey,
                    $instanceName,
                    20,
                    $verifySsl ?? true,
                    $caBundle !== '' ? $caBundle : null
                );
                $this->configureRealtimeWebhook($service, $instanceId);
            } catch (Throwable $webhookException) {
                $this->audit($tenantId, 'evolution.webhook_config_warning', [
                    'instance_id' => $instanceId,
                    'error' => $webhookException->getMessage(),
                ]);
            }

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
        $isDefault = isset($_POST['is_default']);

        if ($instanceId < 1 || $name === '' || $instanceName === '' || !filter_var($baseUrl, FILTER_VALIDATE_URL)) {
            Flash::set('error', 'Informe nome interno, nome na Evolution e URL válida.');
            $this->redirect('/instances');
        }
        $pdo = Database::connection();
        try {
            $source = $this->findInstance($pdo, $instanceId);
            if (!$source) {
                throw new \RuntimeException('Instância não encontrada.');
            }

            $duplicate = $pdo->prepare(
                'SELECT id FROM evolution_instances
                 WHERE LOWER(base_url) = LOWER(:base_url)
                   AND LOWER(instance_name) = LOWER(:instance_name)
                   AND id <> :id
                 LIMIT 1'
            );
            $duplicate->execute([
                'base_url' => $baseUrl,
                'instance_name' => $instanceName,
                'id' => $instanceId,
            ]);
            if ($duplicate->fetchColumn()) {
                throw new \RuntimeException('Já existe outro cadastro apontando para esta mesma instância da Evolution.');
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
                        status = "pending",
                        connection_state = NULL,
                        connection_reason = NULL,
                        last_status_check_at = NULL,
                        connection_updated_at = NOW(),
                        is_default = :is_default';
            $params = [
                'name' => $name,
                'instance_name' => $instanceName,
                'base_url' => $baseUrl,
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

    /** Configura quais assistentes atuam em um canal e quem recebe novas conversas por padrão. */
    public function updateRouting(): void
    {
        $instanceId = (int) ($_POST['instance_id'] ?? 0);
        $rawAgentIds = $_POST['agent_ids'] ?? [];
        $primaryAgentId = (int) ($_POST['primary_agent_id'] ?? 0);
        $rawKeywords = is_array($_POST['routing_keywords'] ?? null) ? $_POST['routing_keywords'] : [];
        $rawPriorities = is_array($_POST['priority'] ?? null) ? $_POST['priority'] : [];

        if (!is_array($rawAgentIds)) {
            $rawAgentIds = [$rawAgentIds];
        }
        $agentIds = array_values(array_unique(array_filter(array_map('intval', $rawAgentIds), static fn (int $id): bool => $id > 0)));

        $pdo = Database::connection();
        $instance = $this->findInstance($pdo, $instanceId);
        if (!$instance) {
            Flash::set('error', 'Canal WhatsApp não encontrado.');
            $this->redirect('/instances');
        }
        $tenantId = (int) $instance['tenant_id'];
        if (!Auth::isSuperAdmin() && $tenantId !== (int) Auth::tenantId()) {
            Flash::set('error', 'Este canal não pertence à sua empresa.');
            $this->redirect('/instances');
        }
        if (!$this->tableExists($pdo, 'ai_agent_instance_bindings')) {
            Flash::set('error', 'A migration 055 precisa ser aplicada antes de configurar múltiplos agentes por canal.');
            $this->redirect('/instances');
        }
        if ($agentIds !== [] && !in_array($primaryAgentId, $agentIds, true)) {
            $primaryAgentId = $agentIds[0];
        }
        if ($agentIds === []) {
            $primaryAgentId = 0;
        }

        if ($agentIds !== []) {
            $placeholders = implode(',', array_fill(0, count($agentIds), '?'));
            $validate = $pdo->prepare('SELECT id FROM ai_agents WHERE tenant_id = ? AND id IN (' . $placeholders . ')');
            $validate->execute(array_merge([$tenantId], $agentIds));
            $validIds = array_map('intval', $validate->fetchAll(PDO::FETCH_COLUMN) ?: []);
            sort($validIds);
            $expected = $agentIds; sort($expected);
            if ($validIds !== $expected) {
                Flash::set('error', 'Um dos assistentes selecionados não pertence a esta empresa.');
                $this->redirect('/instances');
            }
        }

        try {
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM ai_agent_instance_bindings WHERE tenant_id = :tenant_id AND instance_id = :instance_id')
                ->execute(['tenant_id' => $tenantId, 'instance_id' => $instanceId]);

            $insert = $pdo->prepare(
                'INSERT INTO ai_agent_instance_bindings
                    (tenant_id, agent_id, instance_id, is_primary, priority, routing_keywords, status)
                 VALUES
                    (:tenant_id, :agent_id, :instance_id, :is_primary, :priority, :routing_keywords, "active")'
            );
            foreach ($agentIds as $agentId) {
                $keywords = trim((string) ($rawKeywords[$agentId] ?? ''));
                $priority = max(1, min(999, (int) ($rawPriorities[$agentId] ?? ($agentId === $primaryAgentId ? 200 : 100))));
                $insert->execute([
                    'tenant_id' => $tenantId,
                    'agent_id' => $agentId,
                    'instance_id' => $instanceId,
                    'is_primary' => $agentId === $primaryAgentId ? 1 : 0,
                    'priority' => $priority,
                    'routing_keywords' => $keywords !== '' ? mb_substr($keywords, 0, 1000) : null,
                ]);
            }

            // Conversas permanecem com o agente atual enquanto ele continuar no canal.
            // Se o vínculo foi removido, limpa a fixação para a próxima mensagem ser roteada novamente.
            if ($agentIds === []) {
                $pdo->prepare(
                    'UPDATE conversations SET ai_agent_id = NULL
                     WHERE tenant_id = :tenant_id AND evolution_instance_id = :instance_id'
                )->execute(['tenant_id' => $tenantId, 'instance_id' => $instanceId]);
            } else {
                $conversationPlaceholders = implode(',', array_fill(0, count($agentIds), '?'));
                $clearPinned = $pdo->prepare(
                    'UPDATE conversations
                     SET ai_agent_id = NULL
                     WHERE tenant_id = ? AND evolution_instance_id = ?
                       AND ai_agent_id IS NOT NULL
                       AND ai_agent_id NOT IN (' . $conversationPlaceholders . ')'
                );
                $clearPinned->execute(array_merge([$tenantId, $instanceId], $agentIds));
            }

            // Compatibilidade: mantém o vínculo legado apontando para um dos canais do agente.
            foreach ($agentIds as $agentId) {
                $pdo->prepare('UPDATE ai_agents SET instance_id = COALESCE(instance_id, :instance_id) WHERE id = :agent_id AND tenant_id = :tenant_id')
                    ->execute(['instance_id' => $instanceId, 'agent_id' => $agentId, 'tenant_id' => $tenantId]);
            }

            $pdo->commit();
            $this->audit($tenantId, 'evolution.channel_routing_updated', [
                'instance_id' => $instanceId,
                'agent_ids' => $agentIds,
                'primary_agent_id' => $primaryAgentId,
            ]);
            Flash::set('success', $agentIds === [] ? 'Canal salvo sem automação de IA. Ele continuará disponível para atendimento humano.' : 'Roteamento do canal atualizado. Novas conversas usarão o agente principal ou um especialista quando houver palavra de roteamento.');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Flash::set('error', 'Não foi possível salvar o roteamento do canal: ' . $exception->getMessage());
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

            if ($this->tableExists($pdo, 'ai_agent_instance_bindings')) {
                if ($isDefault) {
                    $pdo->prepare('UPDATE ai_agent_instance_bindings SET is_primary = 0 WHERE tenant_id = :tenant_id AND instance_id = :instance_id')
                        ->execute(['tenant_id' => (int) $agent['tenant_id'], 'instance_id' => $instanceId]);
                }
                $pdo->prepare(
                    'INSERT INTO ai_agent_instance_bindings
                        (tenant_id, agent_id, instance_id, is_primary, priority, status)
                     VALUES (:tenant_id, :agent_id, :instance_id, :is_primary, :priority, "active")
                     ON DUPLICATE KEY UPDATE status = "active", is_primary = VALUES(is_primary), priority = VALUES(priority)'
                )->execute([
                    'tenant_id' => (int) $agent['tenant_id'],
                    'agent_id' => $agentId,
                    'instance_id' => $instanceId,
                    'is_primary' => $isDefault ? 1 : 0,
                    'priority' => $isDefault ? 200 : 100,
                ]);
            }
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
            $migrationStats = ['agents' => 0, 'agent_bindings' => 0, 'contacts' => 0, 'conversations' => 0, 'merged_conversations' => 0, 'campaigns' => 0];

            if ($replacement) {
                $migrationStats['agents'] = $this->updateReference($pdo, 'ai_agents', 'instance_id', $instanceId, $replacementId);
                if ($this->tableExists($pdo, 'ai_agent_instance_bindings')) {
                    $migrationStats['agent_bindings'] = $this->migrateAgentBindings(
                        $pdo,
                        $instanceId,
                        $replacementId,
                        (int) $source['tenant_id']
                    );
                }
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

    /** Gera o QR Code da conexão já cadastrada. Disponível ao cliente sem expor URL ou API Key. */
    public function qrCode(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $instanceId = (int) ($_POST['instance_id'] ?? 0);
        if ($instanceId < 1) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'message' => 'Selecione uma conexão WhatsApp válida.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return;
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
            http_response_code(404);
            echo json_encode(['ok' => false, 'message' => 'Essa conexão não foi encontrada para sua empresa.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return;
        }

        try {
            $verifySsl = filter_var(
                Env::get('EVOLUTION_SSL_VERIFY', true),
                FILTER_VALIDATE_BOOL,
                FILTER_NULL_ON_FAILURE
            );
            $caBundle = trim((string) Env::get('EVOLUTION_CA_BUNDLE', ''));
            $service = new EvolutionService(
                (string) $instance['base_url'],
                Crypto::decrypt((string) $instance['api_key_encrypted']),
                (string) $instance['instance_name'],
                25,
                $verifySsl ?? true,
                $caBundle !== '' ? $caBundle : null
            );
            $webhookWarning = null;
            try {
                $this->configureRealtimeWebhook($service, $instanceId);
            } catch (Throwable $webhookException) {
                $webhookWarning = $webhookException->getMessage();
            }

            $result = $service->connectQrCode();
            $body = is_array($result['body'] ?? null) ? $result['body'] : [];
            $base64 = trim((string) ($body['base64'] ?? $body['qrcode'] ?? $body['qrCode'] ?? ''));
            $pairingCode = trim((string) ($body['pairingCode'] ?? ''));

            if ($base64 === '' || !str_starts_with($base64, 'data:image/')) {
                throw new \RuntimeException(
                    $pairingCode !== ''
                        ? 'A Evolution retornou apenas um código de pareamento. Gere novamente o QR Code no painel administrativo.'
                        : 'O QR Code não foi retornado. A conexão pode já estar ativa ou ainda estar iniciando.'
                );
            }

            $update = $pdo->prepare(
                'UPDATE evolution_instances
                 SET status = IF(status = "connected", status, "pending"),
                     connection_state = IF(status = "connected", connection_state, "qrcode"),
                     connection_reason = NULL,
                     qrcode_base64 = :qrcode,
                     qrcode_updated_at = NOW(),
                     qrcode_expires_at = DATE_ADD(NOW(), INTERVAL 2 MINUTE),
                     connection_updated_at = NOW()
                 WHERE id = :id'
            );
            $update->execute(['id' => $instanceId, 'qrcode' => $base64]);

            $this->audit((int) $instance['tenant_id'], 'evolution.qrcode_generated', [
                'instance_id' => $instanceId,
                'instance_name' => (string) $instance['instance_name'],
            ]);

            echo json_encode([
                'ok' => true,
                'qr_code' => $base64,
                'connection_name' => (string) $instance['name'],
                'message' => $webhookWarning === null
                    ? 'QR Code gerado. O status será atualizado automaticamente após a leitura.'
                    : 'QR Code gerado. A atualização automática do status precisa ser revisada: ' . $webhookWarning,
                'webhook_warning' => $webhookWarning,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (Throwable $exception) {
            $this->audit((int) $instance['tenant_id'], 'evolution.qrcode_failed', [
                'instance_id' => $instanceId,
                'error' => $exception->getMessage(),
            ]);
            http_response_code(422);
            echo json_encode([
                'ok' => false,
                'message' => 'Não foi possível gerar o QR Code agora. ' . $exception->getMessage(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
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

    private function configureRealtimeWebhook(EvolutionService $service, int $instanceId): void
    {
        $appUrl = rtrim(trim((string) Env::get('APP_URL', '')), '/');
        if ($appUrl === '' || !str_starts_with($appUrl, 'https://')) {
            throw new \RuntimeException('APP_URL HTTPS não configurada.');
        }
        $token = trim((string) Env::get('EVOLUTION_WEBHOOK_TOKEN', ''));
        $webhookUrl = Router::url('/webhooks/evolution?instance_id=' . $instanceId);
        if ($token !== '') {
            $webhookUrl .= '&token=' . rawurlencode($token);
        }
        $service->setWebhook($webhookUrl, [
            'MESSAGES_UPSERT',
            'MESSAGES_UPDATE',
            'CONNECTION_UPDATE',
            'QRCODE_UPDATED',
            'CONTACTS_UPSERT',
        ]);
    }

    private function connectionLabel(string $state, string $status): string
    {
        $value = mb_strtolower(trim($state !== '' ? $state : $status));
        return match ($value) {
            'open', 'connected', 'online', 'active' => 'Conectado',
            'qrcode', 'qr', 'qrcode_updated' => 'Aguardando leitura do QR Code',
            'connecting' => 'Conectando',
            'created' => 'Instância criada',
            'pending' => 'Pendente',
            'refused' => 'Conexão recusada',
            'logged_out', 'logout', 'loggedout' => 'Sessão encerrada',
            'error', 'failed' => 'Falha na conexão',
            default => 'Desconectado',
        };
    }

    private function migrateAgentBindings(PDO $pdo, int $sourceInstanceId, int $replacementInstanceId, int $tenantId): int
    {
        $rows = $pdo->prepare(
            'SELECT agent_id, is_primary, priority, routing_keywords, status
             FROM ai_agent_instance_bindings
             WHERE tenant_id = :tenant_id AND instance_id = :instance_id'
        );
        $rows->execute(['tenant_id' => $tenantId, 'instance_id' => $sourceInstanceId]);
        $bindings = $rows->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($bindings === []) {
            return 0;
        }

        $insert = $pdo->prepare(
            'INSERT INTO ai_agent_instance_bindings
                (tenant_id, agent_id, instance_id, is_primary, priority, routing_keywords, status)
             VALUES
                (:tenant_id, :agent_id, :instance_id, :is_primary, :priority, :routing_keywords, :status)
             ON DUPLICATE KEY UPDATE
                priority = GREATEST(priority, VALUES(priority)),
                routing_keywords = COALESCE(routing_keywords, VALUES(routing_keywords)),
                status = "active"'
        );
        foreach ($bindings as $binding) {
            $insert->execute([
                'tenant_id' => $tenantId,
                'agent_id' => (int) $binding['agent_id'],
                'instance_id' => $replacementInstanceId,
                'is_primary' => (int) ($binding['is_primary'] ?? 0),
                'priority' => (int) ($binding['priority'] ?? 100),
                'routing_keywords' => $binding['routing_keywords'] ?? null,
                'status' => 'active',
            ]);
        }

        // Mantém apenas um agente principal no canal substituto.
        $primary = $pdo->prepare(
            'SELECT id FROM ai_agent_instance_bindings
             WHERE tenant_id = :tenant_id AND instance_id = :instance_id AND status = "active"
             ORDER BY is_primary DESC, priority DESC, id ASC LIMIT 1'
        );
        $primary->execute(['tenant_id' => $tenantId, 'instance_id' => $replacementInstanceId]);
        $primaryId = (int) ($primary->fetchColumn() ?: 0);
        if ($primaryId > 0) {
            $pdo->prepare(
                'UPDATE ai_agent_instance_bindings
                 SET is_primary = CASE WHEN id = :primary_id THEN 1 ELSE 0 END
                 WHERE tenant_id = :tenant_id AND instance_id = :instance_id'
            )->execute([
                'primary_id' => $primaryId,
                'tenant_id' => $tenantId,
                'instance_id' => $replacementInstanceId,
            ]);
        }

        $pdo->prepare(
            'DELETE FROM ai_agent_instance_bindings
             WHERE tenant_id = :tenant_id AND instance_id = :instance_id'
        )->execute(['tenant_id' => $tenantId, 'instance_id' => $sourceInstanceId]);

        return count($bindings);
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
            'agent_bindings' => $this->tableExists($pdo, 'ai_agent_instance_bindings')
                ? $this->referenceCount($pdo, 'ai_agent_instance_bindings', 'instance_id', $instanceId)
                : 0,
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
            '%d agente(s) legado, %d vínculo(s) de canal, %d contato(s), %d conversa(s), %d campanha(s)',
            (int) ($counts['agents'] ?? 0),
            (int) ($counts['agent_bindings'] ?? 0),
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
