<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Env;
use App\Core\Flash;
use App\Core\Router;
use App\Core\View;
use App\Services\AiAutomationService;
use App\Services\AiModelService;
use App\Services\ConversationFlowService;
use App\Services\EvolutionService;
use PDO;
use Throwable;

final class ConversationController
{
    public function index(): void
    {
        $pdo = Database::connection();
        $tenantId = Auth::isSuperAdmin()
            ? (int) ($_GET['tenant_id'] ?? 0)
            : (int) (Auth::tenantId() ?? 0);

        if (!Auth::isSuperAdmin() && $tenantId < 1) {
            Flash::set('error', 'Sua conta não está vinculada a uma empresa ativa. Entre novamente ou solicite a correção do usuário.');
            $this->redirect('/');
        }

        $filters = [
            'search' => trim((string) ($_GET['search'] ?? '')),
            'status' => trim((string) ($_GET['status'] ?? '')),
            'mode' => trim((string) ($_GET['mode'] ?? '')),
            'instance_id' => (int) ($_GET['instance_id'] ?? 0),
            'tenant_id' => $tenantId,
            'intent' => trim((string) ($_GET['intent'] ?? '')),
        ];

        $conditions = [];
        $params = [];

        if ($tenantId > 0) {
            $conditions[] = 'c.tenant_id = :tenant_scope';
            $params['tenant_scope'] = $tenantId;
        } else {
            // O Super Admin precisa escolher uma empresa. A visão global foi removida por privacidade.
            $conditions[] = '1 = 0';
        }

        if ($filters['search'] !== '') {
            $conditions[] = '(ct.name LIKE :search_name OR ct.phone LIKE :search_phone OR c.last_message_preview LIKE :search_preview)';
            $searchValue = '%' . $filters['search'] . '%';
            $params['search_name'] = $searchValue;
            $params['search_phone'] = $searchValue;
            $params['search_preview'] = $searchValue;
        }

        if (in_array($filters['status'], ['open', 'pending', 'closed'], true)) {
            $conditions[] = 'c.status = :status';
            $params['status'] = $filters['status'];
        }

        if (in_array($filters['mode'], ['ai', 'human', 'paused'], true)) {
            $conditions[] = 'c.attendance_mode = :mode';
            $params['mode'] = $filters['mode'];
        }

        if ($filters['instance_id'] > 0) {
            $conditions[] = 'c.evolution_instance_id = :instance_id';
            $params['instance_id'] = $filters['instance_id'];
        }

        if ($filters['intent'] === 'agenda') {
            if ($this->hasColumn($pdo, 'conversations', 'agenda_intent_detected')) {
                $conditions[] = 'c.agenda_intent_detected = 1';
            } else {
                $conditions[] = '1 = 0';
            }
        }

        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
        $statement = $pdo->prepare(
            'SELECT c.*, ct.name AS contact_name, ct.phone, ct.email, ct.company, ct.notes, ct.tags_json,
                    ct.status AS contact_status, i.name AS instance_label, i.instance_name,
                    t.name AS tenant_name, u.name AS assigned_user_name
             FROM conversations c
             INNER JOIN contacts ct ON ct.id = c.contact_id AND ct.tenant_id = c.tenant_id
             INNER JOIN evolution_instances i ON i.id = c.evolution_instance_id AND i.tenant_id = c.tenant_id
             INNER JOIN tenants t ON t.id = c.tenant_id
             LEFT JOIN users u ON u.id = c.assigned_user_id AND u.tenant_id = c.tenant_id
             ' . $where . '
             ORDER BY COALESCE(c.last_message_at, c.created_at) DESC
             LIMIT 100'
        );
        $statement->execute($params);
        $conversations = $statement->fetchAll(PDO::FETCH_ASSOC);

        $selectedId = (int) ($_GET['conversation_id'] ?? 0);
        if ($selectedId < 1 && $conversations) {
            $selectedId = (int) $conversations[0]['id'];
        }

        $selected = null;
        $messages = [];
        $team = [];

        if ($selectedId > 0) {
            $selected = $this->findConversation($selectedId, $tenantId > 0 ? $tenantId : null);
            if ($selected !== null && Auth::isSuperAdmin() && !$this->conversationAllowedInFilters($selected, $filters)) {
                $selected = null;
                $selectedId = 0;
            }
            if ($selected !== null) {
                // HOTFIX 30.2: primeiro seleciona somente os IDs mais recentes e depois busca
                // o conteúdo completo. Assim o MySQL nunca precisa ordenar TEXT/JSON no sort_buffer.
                $messages = $this->loadRecentConversationMessages(
                    $pdo,
                    (int) $selected['tenant_id'],
                    $selectedId,
                    250
                );

                if ((int) $selected['unread_count'] > 0) {
                    $pdo->prepare('UPDATE conversations SET unread_count = 0 WHERE id = :id AND tenant_id = :tenant_id')
                        ->execute([
                            'id' => $selectedId,
                            'tenant_id' => (int) $selected['tenant_id'],
                        ]);
                    $selected['unread_count'] = 0;
                    foreach ($conversations as &$conversation) {
                        if ((int) $conversation['id'] === $selectedId) {
                            $conversation['unread_count'] = 0;
                            break;
                        }
                    }
                    unset($conversation);
                }

                $teamStatement = $pdo->prepare(
                    'SELECT id, name, role
                     FROM users
                     WHERE tenant_id = :tenant_id AND status = "active"
                     ORDER BY name'
                );
                $teamStatement->execute(['tenant_id' => $selected['tenant_id']]);
                $team = $teamStatement->fetchAll(PDO::FETCH_ASSOC);
            }
        }

        if (Auth::isSuperAdmin()) {
            $tenants = $pdo->query('SELECT id, name FROM tenants WHERE status = "active" ORDER BY name')
                ->fetchAll(PDO::FETCH_ASSOC);
            $instanceSql = 'SELECT i.id, i.tenant_id, i.name, i.instance_name, t.name AS tenant_name FROM evolution_instances i INNER JOIN tenants t ON t.id = i.tenant_id ORDER BY t.name, i.name';
            $instances = $pdo->query($instanceSql)->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $tenants = [];
            $instanceStatement = $pdo->prepare(
                'SELECT id, tenant_id, name, instance_name
                 FROM evolution_instances
                 WHERE tenant_id = :tenant_id
                 ORDER BY name'
            );
            $instanceStatement->execute(['tenant_id' => Auth::tenantId()]);
            $instances = $instanceStatement->fetchAll(PDO::FETCH_ASSOC);
        }

        View::render('conversations.index', [
            'title' => 'Conversas',
            'conversations' => $conversations,
            'selected' => $selected,
            'messages' => $messages,
            'team' => $team,
            'instances' => $instances,
            'tenants' => $tenants,
            'filters' => $filters,
        ]);
    }

    public function start(): void
    {
        $instanceId = (int) ($_POST['instance_id'] ?? 0);
        $phone = preg_replace('/\D+/', '', (string) ($_POST['phone'] ?? '')) ?: '';
        $name = trim((string) ($_POST['name'] ?? ''));
        $message = trim((string) ($_POST['message'] ?? ''));

        if ($instanceId < 1 || strlen($phone) < 10 || $message === '') {
            Flash::set('error', 'Selecione a instância, informe o telefone completo e a primeira mensagem.');
            $this->redirect('/conversations');
        }

        $instance = $this->findInstance($instanceId);
        if ($instance === null) {
            Flash::set('error', 'Instância não encontrada para sua empresa.');
            $this->redirect('/conversations');
        }

        $sentAt = date('Y-m-d H:i:s');
        $remoteJid = $phone . '@s.whatsapp.net';

        try {
            $service = $this->serviceFor($instance);
            $result = $service->sendText($phone, $message);
            $externalId = $this->extractMessageId($result['body'] ?? []);

            $pdo = Database::connection();
            $pdo->beginTransaction();

            $contactStatement = $pdo->prepare(
                'INSERT INTO contacts
                    (tenant_id, evolution_instance_id, remote_jid, phone, name)
                 VALUES
                    (:tenant_id, :instance_id, :remote_jid, :phone, :name)
                 ON DUPLICATE KEY UPDATE
                    id = LAST_INSERT_ID(id),
                    evolution_instance_id = VALUES(evolution_instance_id),
                    remote_jid = VALUES(remote_jid),
                    name = IF(VALUES(name) IS NULL OR VALUES(name) = "", name, VALUES(name))'
            );
            $contactStatement->execute([
                'tenant_id' => $instance['tenant_id'],
                'instance_id' => $instance['id'],
                'remote_jid' => $remoteJid,
                'phone' => $phone,
                'name' => $name !== '' ? $name : null,
            ]);
            $contactId = (int) $pdo->lastInsertId();

            $conversationStatement = $pdo->prepare(
                'INSERT INTO conversations
                    (tenant_id, evolution_instance_id, contact_id, remote_jid, status,
                     attendance_mode, assigned_user_id, last_message_at, last_message_preview)
                 VALUES
                    (:tenant_id, :instance_id, :contact_id, :remote_jid, "open",
                     "human", :user_id, :sent_at, :preview)
                 ON DUPLICATE KEY UPDATE
                    id = LAST_INSERT_ID(id),
                    contact_id = VALUES(contact_id),
                    status = "open",
                    attendance_mode = "human",
                    assigned_user_id = VALUES(assigned_user_id),
                    last_message_at = VALUES(last_message_at),
                    last_message_preview = VALUES(last_message_preview)'
            );
            $conversationStatement->execute([
                'tenant_id' => $instance['tenant_id'],
                'instance_id' => $instance['id'],
                'contact_id' => $contactId,
                'remote_jid' => $remoteJid,
                'user_id' => Auth::id(),
                'sent_at' => $sentAt,
                'preview' => mb_substr($message, 0, 255),
            ]);
            $conversationId = (int) $pdo->lastInsertId();

            $messageStatement = $pdo->prepare(
                'INSERT INTO conversation_messages
                    (tenant_id, conversation_id, evolution_message_id, direction, sender_type,
                     sender_user_id, message_type, content, status, raw_payload_json, sent_at)
                 VALUES
                    (:tenant_id, :conversation_id, :external_id, "outgoing", "user",
                     :user_id, "text", :content, "sent", :raw_payload, :sent_at)
                 ON DUPLICATE KEY UPDATE
                    conversation_id = VALUES(conversation_id),
                    sender_type = "user",
                    sender_user_id = VALUES(sender_user_id),
                    content = VALUES(content),
                    status = "sent",
                    raw_payload_json = VALUES(raw_payload_json),
                    sent_at = VALUES(sent_at)'
            );
            $messageStatement->execute([
                'tenant_id' => $instance['tenant_id'],
                'conversation_id' => $conversationId,
                'external_id' => $externalId,
                'user_id' => Auth::id(),
                'content' => $message,
                'raw_payload' => json_encode($result['body'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'sent_at' => $sentAt,
            ]);

            $this->insertEvent($conversationId, (int) $instance['tenant_id'], 'conversation.started', 'Conversa iniciada pelo painel.');
            $pdo->commit();

            Audit::log('conversation.started', [
                'conversation_id' => $conversationId,
                'instance_id' => $instanceId,
                'http_status' => $result['status'] ?? null,
            ], (int) $instance['tenant_id']);

            Flash::set('success', 'Conversa iniciada e mensagem enviada.');
            $this->redirect('/conversations?conversation_id=' . $conversationId);
        } catch (Throwable $exception) {
            if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Audit::log('conversation.start_failed', [
                'instance_id' => $instanceId,
                'phone' => $phone,
                'error' => $exception->getMessage(),
            ], (int) $instance['tenant_id']);
            Flash::set('error', 'Não foi possível iniciar a conversa: ' . $exception->getMessage());
            $this->redirect('/conversations');
        }
    }

    public function send(): void
    {
        $conversationId = (int) ($_POST['conversation_id'] ?? 0);
        $message = trim((string) ($_POST['message'] ?? ''));

        if ($conversationId < 1 || $message === '') {
            Flash::set('error', 'Informe a conversa e a mensagem.');
            $this->redirect('/conversations');
        }

        $conversation = $this->findConversation($conversationId);
        if ($conversation === null) {
            Flash::set('error', 'Conversa não encontrada para sua empresa.');
            $this->redirect('/conversations');
        }

        $sentAt = date('Y-m-d H:i:s');

        try {
            $service = $this->serviceFor($conversation);
            $result = $service->sendText((string) $conversation['phone'], $message);
            $externalId = $this->extractMessageId($result['body'] ?? []);

            $pdo = Database::connection();
            $pdo->beginTransaction();

            $insert = $pdo->prepare(
                'INSERT INTO conversation_messages
                    (tenant_id, conversation_id, evolution_message_id, direction, sender_type,
                     sender_user_id, message_type, content, status, raw_payload_json, sent_at)
                 VALUES
                    (:tenant_id, :conversation_id, :external_id, "outgoing", "user",
                     :sender_user_id, "text", :content, "sent", :raw_payload, :sent_at)
                 ON DUPLICATE KEY UPDATE
                    conversation_id = VALUES(conversation_id),
                    sender_type = "user",
                    sender_user_id = VALUES(sender_user_id),
                    content = VALUES(content),
                    status = "sent",
                    raw_payload_json = VALUES(raw_payload_json),
                    sent_at = VALUES(sent_at)'
            );
            $insert->execute([
                'tenant_id' => $conversation['tenant_id'],
                'conversation_id' => $conversationId,
                'external_id' => $externalId,
                'sender_user_id' => Auth::id(),
                'content' => $message,
                'raw_payload' => json_encode($result['body'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'sent_at' => $sentAt,
            ]);

            $update = $pdo->prepare(
                'UPDATE conversations
                 SET last_message_at = :sent_at,
                     last_message_preview = :preview,
                     status = IF(status = "closed", "open", status),
                     attendance_mode = "human",
                     assigned_user_id = :user_id
                 WHERE id = :id'
            );
            $update->execute([
                'sent_at' => $sentAt,
                'preview' => mb_substr($message, 0, 255),
                'user_id' => Auth::id(),
                'id' => $conversationId,
            ]);

            $this->insertEvent($conversationId, (int) $conversation['tenant_id'], 'message.sent', 'Mensagem enviada pelo painel.');
            $pdo->commit();

            Audit::log('conversation.message_sent', [
                'conversation_id' => $conversationId,
                'http_status' => $result['status'] ?? null,
            ], (int) $conversation['tenant_id']);
            Flash::set('success', 'Mensagem enviada pela Evolution API.');
        } catch (Throwable $exception) {
            if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $this->recordFailedMessage($conversation, $message, $sentAt, $exception->getMessage());
            Audit::log('conversation.message_failed', [
                'conversation_id' => $conversationId,
                'error' => $exception->getMessage(),
            ], (int) $conversation['tenant_id']);
            Flash::set('error', 'Falha no envio: ' . $exception->getMessage());
        }

        $this->redirect('/conversations?conversation_id=' . $conversationId);
    }

    public function markRead(): void
    {
        $tenantId = Auth::isSuperAdmin()
            ? (int) ($_POST['tenant_id'] ?? 0)
            : (int) (Auth::tenantId() ?? 0);

        if ($tenantId < 1) {
            Flash::set('error', 'Selecione uma empresa antes de marcar conversas como lidas.');
            $this->redirect($this->conversationReturnPath());
        }

        $rawIds = $_POST['conversation_ids'] ?? [];
        if (!is_array($rawIds)) {
            $rawIds = [$rawIds];
        }

        $conversationIds = array_values(array_unique(array_filter(
            array_map(static fn ($value): int => (int) $value, $rawIds),
            static fn (int $value): bool => $value > 0
        )));
        $conversationIds = array_slice($conversationIds, 0, 100);

        if ($conversationIds === []) {
            Flash::set('warning', 'Selecione ao menos uma conversa para marcar como lida.');
            $this->redirect($this->conversationReturnPath());
        }

        $placeholders = [];
        $params = ['tenant_id' => $tenantId];
        foreach ($conversationIds as $index => $conversationId) {
            $key = 'conversation_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $conversationId;
        }

        $pdo = Database::connection();
        $statement = $pdo->prepare(
            'UPDATE conversations
             SET unread_count = 0
             WHERE tenant_id = :tenant_id
               AND unread_count > 0
               AND id IN (' . implode(', ', $placeholders) . ')'
        );
        $statement->execute($params);
        $updated = $statement->rowCount();

        Audit::log('conversation.bulk_marked_read', [
            'selected_count' => count($conversationIds),
            'updated_count' => $updated,
            'conversation_ids' => $conversationIds,
        ], $tenantId);

        Flash::set(
            'success',
            $updated === 1
                ? '1 conversa foi marcada como lida.'
                : $updated . ' conversas foram marcadas como lidas.'
        );
        $this->redirect($this->conversationReturnPath());
    }

    public function delete(): void
    {
        $tenantId = Auth::isSuperAdmin()
            ? (int) ($_POST['tenant_id'] ?? 0)
            : (int) (Auth::tenantId() ?? 0);

        if ($tenantId < 1) {
            Flash::set('error', 'Selecione uma empresa antes de excluir conversas.');
            $this->redirect($this->conversationReturnPath(false));
        }

        $rawIds = $_POST['conversation_ids'] ?? [];
        if (!is_array($rawIds)) {
            $rawIds = [$rawIds];
        }

        $conversationIds = array_values(array_unique(array_filter(
            array_map(static fn ($value): int => (int) $value, $rawIds),
            static fn (int $value): bool => $value > 0
        )));
        $conversationIds = array_slice($conversationIds, 0, 100);

        if ($conversationIds === []) {
            Flash::set('warning', 'Selecione ao menos uma conversa para excluir.');
            $this->redirect($this->conversationReturnPath(false));
        }

        $placeholders = [];
        $params = ['tenant_id' => $tenantId];
        foreach ($conversationIds as $index => $conversationId) {
            $key = 'conversation_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $conversationId;
        }
        $inClause = implode(', ', $placeholders);

        $pdo = Database::connection();
        $lookup = $pdo->prepare(
            'SELECT c.id, c.remote_jid, ct.name AS contact_name, ct.phone
             FROM conversations c
             INNER JOIN contacts ct ON ct.id = c.contact_id AND ct.tenant_id = c.tenant_id
             WHERE c.tenant_id = :tenant_id
               AND c.id IN (' . $inClause . ')'
        );
        $lookup->execute($params);
        $existing = $lookup->fetchAll(PDO::FETCH_ASSOC);

        if ($existing === []) {
            Flash::set('warning', 'Nenhuma das conversas selecionadas pertence à empresa atual.');
            $this->redirect($this->conversationReturnPath(false));
        }

        $existingIds = array_map(static fn (array $row): int => (int) $row['id'], $existing);
        $deletePlaceholders = [];
        $deleteParams = ['tenant_id' => $tenantId];
        foreach ($existingIds as $index => $conversationId) {
            $key = 'delete_conversation_' . $index;
            $deletePlaceholders[] = ':' . $key;
            $deleteParams[$key] = $conversationId;
        }
        $deleteInClause = implode(', ', $deletePlaceholders);

        try {
            $pdo->beginTransaction();

            // Mantém CRM, agenda, consentimentos e logs, removendo somente o vínculo
            // com a conversa que será apagada.
            $nullableReferences = [
                ['crm_leads', 'source_conversation_id'],
                ['ai_automation_logs', 'conversation_id'],
                ['calendar_appointments', 'conversation_id'],
                ['privacy_consents', 'conversation_id'],
            ];
            foreach ($nullableReferences as [$table, $column]) {
                if (!$this->hasTable($pdo, $table) || !$this->hasColumn($pdo, $table, $column)) {
                    continue;
                }
                $statement = $pdo->prepare(
                    'UPDATE `' . $table . '`
                     SET `' . $column . '` = NULL
                     WHERE tenant_id = :tenant_id
                       AND `' . $column . '` IN (' . $deleteInClause . ')'
                );
                $statement->execute($deleteParams);
            }

            // A exclusão explícita também funciona em instalações antigas onde as
            // chaves estrangeiras CASCADE podem não ter sido criadas corretamente.
            foreach (['conversation_internal_notes', 'conversation_events', 'conversation_messages'] as $table) {
                if (!$this->hasTable($pdo, $table) || !$this->hasColumn($pdo, $table, 'conversation_id')) {
                    continue;
                }
                $statement = $pdo->prepare(
                    'DELETE FROM `' . $table . '`
                     WHERE tenant_id = :tenant_id
                       AND conversation_id IN (' . $deleteInClause . ')'
                );
                $statement->execute($deleteParams);
            }

            $delete = $pdo->prepare(
                'DELETE FROM conversations
                 WHERE tenant_id = :tenant_id
                   AND id IN (' . $deleteInClause . ')'
            );
            $delete->execute($deleteParams);
            $deleted = $delete->rowCount();

            $pdo->commit();

            Audit::log('conversation.bulk_deleted', [
                'selected_count' => count($conversationIds),
                'deleted_count' => $deleted,
                'conversation_ids' => $existingIds,
                'contacts' => array_map(static fn (array $row): array => [
                    'name' => (string) ($row['contact_name'] ?? ''),
                    'phone' => (string) ($row['phone'] ?? ''),
                    'remote_jid' => (string) ($row['remote_jid'] ?? ''),
                ], $existing),
            ], $tenantId);

            Flash::set(
                'success',
                $deleted === 1
                    ? '1 conversa e seu histórico foram excluídos do RS Connect.'
                    : $deleted . ' conversas e seus históricos foram excluídos do RS Connect.'
            );
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Audit::log('conversation.bulk_delete_failed', [
                'conversation_ids' => $existingIds,
                'error' => $exception->getMessage(),
            ], $tenantId);
            Flash::set('error', 'Não foi possível excluir as conversas: ' . $exception->getMessage());
        }

        $this->redirect($this->conversationReturnPath(false));
    }

    public function setMode(): void
    {
        $conversationId = (int) ($_POST['conversation_id'] ?? 0);
        $mode = (string) ($_POST['mode'] ?? '');

        if (!in_array($mode, ['ai', 'human', 'paused'], true)) {
            Flash::set('error', 'Modo de atendimento inválido.');
            $this->redirect('/conversations?conversation_id=' . $conversationId);
        }

        $conversation = $this->findConversation($conversationId);
        if ($conversation === null) {
            Flash::set('error', 'Conversa não encontrada.');
            $this->redirect('/conversations');
        }

        $assignedUserId = $mode === 'human' ? Auth::id() : null;
        $statement = Database::connection()->prepare(
            'UPDATE conversations
             SET attendance_mode = :mode,
                 assigned_user_id = :assigned_user_id,
                 status = IF(status = "closed", "open", status)
             WHERE id = :id'
        );
        $statement->execute([
            'mode' => $mode,
            'assigned_user_id' => $assignedUserId,
            'id' => $conversationId,
        ]);

        $descriptions = [
            'ai' => 'Atendimento devolvido para a IA.',
            'human' => 'Atendimento assumido por ' . (Auth::user()['name'] ?? 'usuário') . '.',
            'paused' => 'IA pausada nesta conversa.',
        ];
        $this->insertEvent($conversationId, (int) $conversation['tenant_id'], 'mode.' . $mode, $descriptions[$mode]);
        Audit::log('conversation.mode_changed', ['conversation_id' => $conversationId, 'mode' => $mode], (int) $conversation['tenant_id']);

        Flash::set('success', $descriptions[$mode]);
        $this->redirect('/conversations?conversation_id=' . $conversationId);
    }

    public function updateStatus(): void
    {
        $conversationId = (int) ($_POST['conversation_id'] ?? 0);
        $status = (string) ($_POST['status'] ?? '');

        if (!in_array($status, ['open', 'pending', 'closed'], true)) {
            Flash::set('error', 'Status da conversa inválido.');
            $this->redirect('/conversations?conversation_id=' . $conversationId);
        }

        $conversation = $this->findConversation($conversationId);
        if ($conversation === null) {
            Flash::set('error', 'Conversa não encontrada.');
            $this->redirect('/conversations');
        }

        Database::connection()->prepare('UPDATE conversations SET status = :status WHERE id = :id')
            ->execute(['status' => $status, 'id' => $conversationId]);

        $label = ['open' => 'aberta', 'pending' => 'marcada como pendente', 'closed' => 'encerrada'][$status];
        $this->insertEvent($conversationId, (int) $conversation['tenant_id'], 'status.' . $status, 'Conversa ' . $label . '.');
        Audit::log('conversation.status_changed', ['conversation_id' => $conversationId, 'status' => $status], (int) $conversation['tenant_id']);

        Flash::set('success', 'Conversa ' . $label . '.');
        $this->redirect('/conversations?conversation_id=' . $conversationId);
    }

    public function updateContact(): void
    {
        $conversationId = (int) ($_POST['conversation_id'] ?? 0);
        $conversation = $this->findConversation($conversationId);
        if ($conversation === null) {
            Flash::set('error', 'Conversa não encontrada.');
            $this->redirect('/conversations');
        }

        $name = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $company = trim((string) ($_POST['company'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $status = (string) ($_POST['contact_status'] ?? 'lead');
        $contactGroup = (string) ($_POST['contact_group'] ?? 'unclassified');
        $flowStage = (string) ($_POST['flow_stage'] ?? 'identifying_contact');
        $demandStatus = (string) ($_POST['demand_status'] ?? 'pending');
        $demandSummary = trim((string) ($_POST['demand_summary'] ?? ''));
        $tags = array_values(array_filter(array_map('trim', explode(',', (string) ($_POST['tags'] ?? '')))));

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Flash::set('error', 'Informe um e-mail válido.');
            $this->redirect('/conversations?conversation_id=' . $conversationId);
        }
        if (!in_array($status, ['lead', 'customer', 'inactive'], true)) {
            $status = 'lead';
        }
        if (!array_key_exists($contactGroup, ConversationFlowService::GROUPS)) {
            $contactGroup = 'unclassified';
        }

        $pdo = Database::connection();
        $statement = $pdo->prepare(
            'UPDATE contacts
             SET name = :name, email = :email, company = :company, notes = :notes,
                 tags_json = :tags_json, status = :status, contact_group = :contact_group
             WHERE id = :contact_id AND tenant_id = :tenant_id'
        );
        $statement->execute([
            'name' => $name !== '' ? $name : null,
            'email' => $email !== '' ? $email : null,
            'company' => $company !== '' ? $company : null,
            'notes' => $notes !== '' ? $notes : null,
            'tags_json' => $tags ? json_encode($tags, JSON_UNESCAPED_UNICODE) : null,
            'status' => $status,
            'contact_group' => $contactGroup,
            'contact_id' => $conversation['contact_id'],
            'tenant_id' => $conversation['tenant_id'],
        ]);

        (new ConversationFlowService())->updateManual($pdo, (int) $conversation['tenant_id'], $conversationId, (int) $conversation['contact_id'], [
            'contact_group' => $contactGroup,
            'flow_stage' => $flowStage,
            'demand_status' => $demandStatus,
            'demand_summary' => $demandSummary,
        ]);

        Audit::log('conversation.contact_updated', [
            'conversation_id' => $conversationId,
            'contact_group' => $contactGroup,
            'flow_stage' => $flowStage,
            'demand_status' => $demandStatus,
        ], (int) $conversation['tenant_id']);
        Flash::set('success', 'Dados do contato atualizados.');
        $this->redirect('/conversations?conversation_id=' . $conversationId);
    }


    public function suggest(): void
    {
        $conversationId = (int) ($_POST['conversation_id'] ?? 0);
        $conversation = $this->findConversation($conversationId);
        if ($conversation === null) {
            Flash::set('error', 'Conversa não encontrada.');
            $this->redirect('/conversations');
        }

        try {
            $pdo = Database::connection();
            $agent = $this->agentForConversation($pdo, $conversation);
            if (!$agent) {
                Flash::set('error', 'Nenhum agente ativo encontrado para gerar sugestão.');
                $this->redirect('/conversations?conversation_id=' . $conversationId);
            }

            $messages = $this->recentMessages($pdo, (int) $conversation['tenant_id'], $conversationId, 14);
            $suggestion = (new AiModelService())->generateReply($agent, $messages, $conversation, $conversation);

            if ($this->hasColumn($pdo, 'conversations', 'last_ai_suggestion')) {
                $pdo->prepare(
                    'UPDATE conversations
                     SET last_ai_suggestion = :suggestion,
                         last_ai_suggestion_at = CURRENT_TIMESTAMP
                     WHERE id = :id'
                )->execute([
                    'suggestion' => $suggestion,
                    'id' => $conversationId,
                ]);
            }

            $this->insertEvent($conversationId, (int) $conversation['tenant_id'], 'ai.suggestion', 'Sugestão de resposta gerada pela IA.');
            Audit::log('conversation.ai_suggestion', ['conversation_id' => $conversationId], (int) $conversation['tenant_id']);
            Flash::set('success', 'Sugestão de resposta gerada pela IA.');
        } catch (Throwable $exception) {
            Audit::log('conversation.ai_suggestion_failed', [
                'conversation_id' => $conversationId,
                'error' => $exception->getMessage(),
            ], (int) $conversation['tenant_id']);
            Flash::set('error', 'Não foi possível gerar sugestão: ' . $exception->getMessage());
        }

        $this->redirect('/conversations?conversation_id=' . $conversationId);
    }

    public function reprocessAi(): void
    {
        $conversationId = (int) ($_POST['conversation_id'] ?? 0);
        $conversation = $this->findConversation($conversationId);
        if ($conversation === null) {
            Flash::set('error', 'Conversa não encontrada.');
            $this->redirect('/conversations');
        }

        $pdo = Database::connection();
        $message = $pdo->prepare(
            'SELECT content
             FROM conversation_messages
             WHERE conversation_id = :conversation_id AND direction = "incoming"
             ORDER BY sent_at DESC, id DESC
             LIMIT 1'
        );
        $message->execute(['conversation_id' => $conversationId]);
        $content = trim((string) $message->fetchColumn());

        if ($content === '') {
            Flash::set('error', 'Não existe mensagem recebida para reprocessar com IA.');
            $this->redirect('/conversations?conversation_id=' . $conversationId);
        }

        $instance = [
            'id' => (int) $conversation['evolution_instance_id'],
            'tenant_id' => (int) $conversation['tenant_id'],
            'base_url' => (string) $conversation['base_url'],
            'api_key_encrypted' => (string) $conversation['api_key_encrypted'],
            'instance_name' => (string) $conversation['instance_name'],
        ];

        try {
            (new AiAutomationService())->handleIncoming($instance, $conversationId, $content, [
                'event' => 'manual.reprocess',
                'conversation_id' => $conversationId,
                'bypass_cooldown' => true,
            ]);
            $this->insertEvent($conversationId, (int) $conversation['tenant_id'], 'ai.reprocess', 'Última mensagem reprocessada manualmente com IA.');
            Flash::set('success', 'Reprocessamento solicitado. Confira a conversa e os logs de automação.');
        } catch (Throwable $exception) {
            Flash::set('error', 'Falha ao reprocessar com IA: ' . $exception->getMessage());
        }

        $this->redirect('/conversations?conversation_id=' . $conversationId);
    }


    private function conversationAllowedInFilters(array $conversation, array $filters): bool
    {
        $tenantId = Auth::isSuperAdmin()
            ? (int) ($filters['tenant_id'] ?? 0)
            : (int) (Auth::tenantId() ?? 0);

        return $tenantId > 0 && (int) $conversation['tenant_id'] === $tenantId;
    }

    private function findInstance(int $id): ?array
    {
        $sql = 'SELECT * FROM evolution_instances WHERE id = :id';
        $params = ['id' => $id];
        if (!Auth::isSuperAdmin()) {
            $sql .= ' AND tenant_id = :tenant_id';
            $params['tenant_id'] = Auth::tenantId();
        }

        $statement = Database::connection()->prepare($sql . ' LIMIT 1');
        $statement->execute($params);
        $instance = $statement->fetch(PDO::FETCH_ASSOC);
        return $instance ?: null;
    }

    private function findConversation(int $id, ?int $tenantScope = null): ?array
    {
        $pdo = Database::connection();

        $leadSelect = 'NULL AS lead_id, NULL AS lead_title, NULL AS lead_status, NULL AS lead_value,
                       NULL AS lead_priority, NULL AS lead_pipeline_id, NULL AS lead_stage_id,
                       NULL AS lead_stage_name';
        $leadJoins = '';

        $crmAvailable = $this->hasColumn($pdo, 'conversations', 'crm_lead_id')
            && $this->hasTable($pdo, 'crm_leads')
            && $this->hasTable($pdo, 'crm_stages')
            && $this->hasColumn($pdo, 'crm_leads', 'tenant_id')
            && $this->hasColumn($pdo, 'crm_leads', 'title')
            && $this->hasColumn($pdo, 'crm_leads', 'status')
            && $this->hasColumn($pdo, 'crm_leads', 'value')
            && $this->hasColumn($pdo, 'crm_leads', 'priority')
            && $this->hasColumn($pdo, 'crm_leads', 'pipeline_id')
            && $this->hasColumn($pdo, 'crm_leads', 'stage_id')
            && $this->hasColumn($pdo, 'crm_stages', 'tenant_id')
            && $this->hasColumn($pdo, 'crm_stages', 'name');

        if ($crmAvailable) {
            $leadSelect = 'l.id AS lead_id, l.title AS lead_title, l.status AS lead_status, l.value AS lead_value,
                           l.priority AS lead_priority, l.pipeline_id AS lead_pipeline_id, l.stage_id AS lead_stage_id,
                           s.name AS lead_stage_name';
            $leadJoins = ' LEFT JOIN crm_leads l ON l.id = c.crm_lead_id AND l.tenant_id = c.tenant_id
                           LEFT JOIN crm_stages s ON s.id = l.stage_id AND s.tenant_id = c.tenant_id';
        }

        $sql = 'SELECT c.*, ct.name AS contact_name, ct.phone, ct.email, ct.company, ct.notes,
                       ct.tags_json, ct.status AS contact_status,
                       COALESCE(NULLIF(ct.contact_group, ""), "unclassified") AS contact_group,
                       ct.id AS contact_id,
                       fs.stage AS flow_stage, fs.demand_status, fs.demand_summary,
                       fs.is_existing_patient, fs.last_intent,
                       i.name AS instance_label, i.instance_name, i.base_url, i.api_key_encrypted,
                       t.name AS tenant_name, u.name AS assigned_user_name,
                       ' . $leadSelect . '
                FROM conversations c
                INNER JOIN contacts ct ON ct.id = c.contact_id AND ct.tenant_id = c.tenant_id
                INNER JOIN evolution_instances i ON i.id = c.evolution_instance_id AND i.tenant_id = c.tenant_id
                INNER JOIN tenants t ON t.id = c.tenant_id
                LEFT JOIN users u ON u.id = c.assigned_user_id AND u.tenant_id = c.tenant_id
                LEFT JOIN conversation_flow_states fs ON fs.conversation_id = c.id AND fs.tenant_id = c.tenant_id
                ' . $leadJoins . '
                WHERE c.id = :id';
        $params = ['id' => $id];

        $effectiveTenantId = !Auth::isSuperAdmin()
            ? (int) (Auth::tenantId() ?? 0)
            : (int) ($tenantScope ?? 0);

        if ($effectiveTenantId > 0) {
            $sql .= ' AND c.tenant_id = :tenant_id';
            $params['tenant_id'] = $effectiveTenantId;
        } elseif (!Auth::isSuperAdmin()) {
            return null;
        }

        $statement = $pdo->prepare($sql . ' LIMIT 1');
        $statement->execute($params);
        $conversation = $statement->fetch(PDO::FETCH_ASSOC);
        return $conversation ?: null;
    }

    private function serviceFor(array $conversation): EvolutionService
    {
        $verifySsl = filter_var(
            Env::get('EVOLUTION_SSL_VERIFY', true),
            FILTER_VALIDATE_BOOL,
            FILTER_NULL_ON_FAILURE
        );
        $caBundle = trim((string) Env::get('EVOLUTION_CA_BUNDLE', ''));

        return new EvolutionService(
            (string) $conversation['base_url'],
            Crypto::decrypt((string) $conversation['api_key_encrypted']),
            (string) $conversation['instance_name'],
            20,
            $verifySsl ?? true,
            $caBundle !== '' ? $caBundle : null
        );
    }

    private function extractMessageId(array $body): ?string
    {
        $id = $body['key']['id'] ?? $body['messageId'] ?? $body['id'] ?? $body['data']['key']['id'] ?? null;
        return is_scalar($id) && trim((string) $id) !== '' ? trim((string) $id) : null;
    }

    private function recordFailedMessage(array $conversation, string $message, string $sentAt, string $error): void
    {
        try {
            Database::connection()->prepare(
                'INSERT INTO conversation_messages
                    (tenant_id, conversation_id, direction, sender_type, sender_user_id,
                     message_type, content, status, error_message, sent_at)
                 VALUES
                    (:tenant_id, :conversation_id, "outgoing", "user", :user_id,
                     "text", :content, "failed", :error_message, :sent_at)'
            )->execute([
                'tenant_id' => $conversation['tenant_id'],
                'conversation_id' => $conversation['id'],
                'user_id' => Auth::id(),
                'content' => $message,
                'error_message' => mb_substr($error, 0, 500),
                'sent_at' => $sentAt,
            ]);
        } catch (Throwable) {
            // O erro original do envio é mais importante que uma falha ao registrar o histórico.
        }
    }

    private function insertEvent(int $conversationId, int $tenantId, string $type, string $description): void
    {
        Database::connection()->prepare(
            'INSERT INTO conversation_events (tenant_id, conversation_id, user_id, event_type, description)
             VALUES (:tenant_id, :conversation_id, :user_id, :event_type, :description)'
        )->execute([
            'tenant_id' => $tenantId,
            'conversation_id' => $conversationId,
            'user_id' => Auth::id(),
            'event_type' => $type,
            'description' => $description,
        ]);
    }


    private function agentForConversation(PDO $pdo, array $conversation): ?array
    {
        $statement = $pdo->prepare(
            'SELECT a.*,
                    COALESCE(ac_agent.id, ac_tenant.id) AS credential_id,
                    COALESCE(ac_agent.label, ac_tenant.label) AS credential_label,
                    COALESCE(ac_agent.provider, ac_tenant.provider) AS credential_provider,
                    COALESCE(ac_agent.api_key_encrypted, ac_tenant.api_key_encrypted) AS credential_api_key_encrypted,
                    COALESCE(ac_agent.base_url, ac_tenant.base_url) AS credential_base_url,
                    COALESCE(ac_agent.default_model, ac_tenant.default_model) AS credential_default_model
             FROM ai_agents a
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
               AND a.status = "active"
               AND (a.instance_id = :instance_id_filter OR a.instance_id IS NULL OR a.is_default = 1)
             ORDER BY (a.instance_id = :instance_id_order) DESC, a.is_default DESC, a.id DESC
             LIMIT 1'
        );
        $statement->execute([
            'tenant_id' => $conversation['tenant_id'],
            'instance_id_filter' => $conversation['evolution_instance_id'],
            'instance_id_order' => $conversation['evolution_instance_id'],
        ]);
        $agent = $statement->fetch(PDO::FETCH_ASSOC);
        return $agent ?: null;
    }

    private function recentMessages(PDO $pdo, int $tenantId, int $conversationId, int $limit): array
    {
        $rows = $this->loadRecentConversationMessages($pdo, $tenantId, $conversationId, max(4, min(30, $limit)));

        return array_map(static fn (array $message): array => [
            'direction' => (string) ($message['direction'] ?? ''),
            'sender_type' => (string) ($message['sender_type'] ?? ''),
            'content' => (string) ($message['content'] ?? ''),
            'sent_at' => (string) ($message['sent_at'] ?? ''),
        ], $rows);
    }

    /**
     * Carrega as mensagens mais recentes sem ordenar as colunas TEXT/JSON.
     * A primeira consulta trabalha apenas com BIGINT (id); a ordenação final ocorre no PHP.
     */
    private function loadRecentConversationMessages(
        PDO $pdo,
        int $tenantId,
        int $conversationId,
        int $limit
    ): array {
        $limit = max(1, min(500, $limit));
        $indexHint = $this->messageIndexHint($pdo);
        $statement = $pdo->prepare(
            'SELECT id
             FROM conversation_messages' . $indexHint . '
             WHERE tenant_id = :tenant_id
               AND conversation_id = :conversation_id
             ORDER BY id DESC
             LIMIT ' . $limit
        );
        $statement->execute([
            'tenant_id' => $tenantId,
            'conversation_id' => $conversationId,
        ]);

        $ids = array_values(array_filter(array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN)), static fn (int $id): bool => $id > 0));
        return $this->fetchConversationMessagesByIds($pdo, $tenantId, $conversationId, $ids);
    }

    /**
     * Carrega somente mensagens posteriores ao último ID conhecido para o polling em tempo real.
     */
    private function loadConversationMessagesAfter(
        PDO $pdo,
        int $tenantId,
        int $conversationId,
        int $afterId,
        int $limit
    ): array {
        $limit = max(1, min(250, $limit));
        $indexHint = $this->messageIndexHint($pdo);
        $statement = $pdo->prepare(
            'SELECT id
             FROM conversation_messages' . $indexHint . '
             WHERE tenant_id = :tenant_id
               AND conversation_id = :conversation_id
               AND id > :after_id
             ORDER BY id ASC
             LIMIT ' . $limit
        );
        $statement->execute([
            'tenant_id' => $tenantId,
            'conversation_id' => $conversationId,
            'after_id' => $afterId,
        ]);

        $ids = array_values(array_filter(array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN)), static fn (int $id): bool => $id > 0));
        return $this->fetchConversationMessagesByIds($pdo, $tenantId, $conversationId, $ids);
    }

    private function fetchConversationMessagesByIds(
        PDO $pdo,
        int $tenantId,
        int $conversationId,
        array $ids
    ): array {
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $statement = $pdo->prepare(
            'SELECT m.*, u.name AS sender_user_name
             FROM conversation_messages m
             LEFT JOIN users u ON u.id = m.sender_user_id AND u.tenant_id = m.tenant_id
             WHERE m.tenant_id = ?
               AND m.conversation_id = ?
               AND m.id IN (' . $placeholders . ')'
        );
        $statement->execute(array_merge([$tenantId, $conversationId], $ids));
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        usort($rows, static fn (array $left, array $right): int => (int) $left['id'] <=> (int) $right['id']);
        return $rows;
    }

    /**
     * Usa o índice novo quando disponível e mantém compatibilidade com bancos ainda sem a migration 032.
     */
    private function messageIndexHint(PDO $pdo): string
    {
        if ($this->hasIndexColumns($pdo, 'conversation_messages', 'idx_messages_tenant_conversation_id_v2', ['tenant_id', 'conversation_id', 'id'])) {
            return ' FORCE INDEX (idx_messages_tenant_conversation_id_v2)';
        }

        if ($this->hasIndexColumns($pdo, 'conversation_messages', 'idx_messages_tenant_conversation_id', ['tenant_id', 'conversation_id', 'id'])) {
            return ' FORCE INDEX (idx_messages_tenant_conversation_id)';
        }

        return '';
    }

    private function hasIndexColumns(PDO $pdo, string $table, string $index, array $expectedColumns): bool
    {
        static $cache = [];
        $cacheKey = $table . ':' . $index;
        if (array_key_exists($cacheKey, $cache)) {
            return $cache[$cacheKey];
        }

        $statement = $pdo->prepare(
            'SELECT COLUMN_NAME
             FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table
               AND INDEX_NAME = :index_name
             ORDER BY SEQ_IN_INDEX'
        );
        $statement->execute([
            'table' => $table,
            'index_name' => $index,
        ]);
        $columns = array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
        $cache[$cacheKey] = $columns === $expectedColumns;
        return $cache[$cacheKey];
    }

    private function hasColumn(PDO $pdo, string $table, string $column): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column'
        );
        $statement->execute(['table' => $table, 'column' => $column]);
        return (int) $statement->fetchColumn() > 0;
    }

    private function hasTable(PDO $pdo, string $table): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
        );
        $statement->execute(['table' => $table]);
        return (int) $statement->fetchColumn() > 0;
    }


    public function poll(): void
    {
        $pdo = Database::connection();
        $selectedId = (int) ($_GET['conversation_id'] ?? 0);
        $afterId = (int) ($_GET['after_id'] ?? 0);
        $markRead = (int) ($_GET['mark_read'] ?? 1) === 1;

        $tenantId = Auth::isSuperAdmin()
            ? (int) ($_GET['tenant_id'] ?? 0)
            : (int) (Auth::tenantId() ?? 0);

        $filters = [
            'search' => trim((string) ($_GET['search'] ?? '')),
            'status' => trim((string) ($_GET['status'] ?? '')),
            'mode' => trim((string) ($_GET['mode'] ?? '')),
            'instance_id' => (int) ($_GET['instance_id'] ?? 0),
            'tenant_id' => $tenantId,
            'intent' => trim((string) ($_GET['intent'] ?? '')),
        ];

        if ($selectedId > 0 && $markRead) {
            $selected = $this->findConversation($selectedId, $tenantId > 0 ? $tenantId : null);
            if ($selected !== null && $this->conversationAllowedInFilters($selected, $filters)) {
                $pdo->prepare('UPDATE conversations SET unread_count = 0 WHERE id = :id AND tenant_id = :tenant_id')
                    ->execute([
                        'id' => $selectedId,
                        'tenant_id' => (int) $selected['tenant_id'],
                    ]);
            }
        }

        $conversations = $this->conversationSummaries($pdo, $filters);
        $messages = [];
        $latestMessageId = $afterId;

        if ($selectedId > 0) {
            $selected = $this->findConversation($selectedId, $tenantId > 0 ? $tenantId : null);
            if ($selected !== null && Auth::isSuperAdmin() && !$this->conversationAllowedInFilters($selected, $filters)) {
                $selected = null;
            }
            if ($selected !== null) {
                $rows = $this->loadConversationMessagesAfter(
                    $pdo,
                    (int) $selected['tenant_id'],
                    $selectedId,
                    $afterId,
                    120
                );
                foreach ($rows as $message) {
                    $latestMessageId = max($latestMessageId, (int) $message['id']);
                    $messages[] = $this->formatMessageForJson($message);
                }
            }
        }

        $unreadTotal = 0;
        foreach ($conversations as $conversation) {
            $unreadTotal += (int) ($conversation['unread_count'] ?? 0);
        }

        $this->json([
            'ok' => true,
            'server_time' => date(DATE_ATOM),
            'selected_conversation_id' => $selectedId,
            'latest_message_id' => $latestMessageId,
            'unread_total' => $unreadTotal,
            'has_new_messages' => count($messages) > 0,
            'conversations' => array_map(fn (array $conversation): array => $this->formatConversationForJson($conversation, $selectedId), $conversations),
            'messages' => $messages,
        ]);
    }

    private function conversationSummaries(PDO $pdo, array $filters): array
    {
        $conditions = [];
        $params = [];

        $tenantId = (int) ($filters['tenant_id'] ?? 0);
        if ($tenantId > 0) {
            $conditions[] = 'c.tenant_id = :tenant_scope';
            $params['tenant_scope'] = $tenantId;
        } else {
            $conditions[] = '1 = 0';
        }

        if (($filters['search'] ?? '') !== '') {
            $conditions[] = '(ct.name LIKE :search_name OR ct.phone LIKE :search_phone OR c.last_message_preview LIKE :search_preview)';
            $searchValue = '%' . $filters['search'] . '%';
            $params['search_name'] = $searchValue;
            $params['search_phone'] = $searchValue;
            $params['search_preview'] = $searchValue;
        }

        if (in_array($filters['status'] ?? '', ['open', 'pending', 'closed'], true)) {
            $conditions[] = 'c.status = :status';
            $params['status'] = $filters['status'];
        }

        if (in_array($filters['mode'] ?? '', ['ai', 'human', 'paused'], true)) {
            $conditions[] = 'c.attendance_mode = :mode';
            $params['mode'] = $filters['mode'];
        }

        if (($filters['instance_id'] ?? 0) > 0) {
            $conditions[] = 'c.evolution_instance_id = :instance_id';
            $params['instance_id'] = (int) $filters['instance_id'];
        }

        if (($filters['intent'] ?? '') === 'agenda') {
            if ($this->hasColumn($pdo, 'conversations', 'agenda_intent_detected')) {
                $conditions[] = 'c.agenda_intent_detected = 1';
            } else {
                $conditions[] = '1 = 0';
            }
        }

        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
        $statement = $pdo->prepare(
            'SELECT c.id, c.status, c.attendance_mode, c.unread_count, c.last_message_at, c.last_message_preview,
                    ct.name AS contact_name, ct.phone, i.name AS instance_label, i.instance_name,
                    t.name AS tenant_name
             FROM conversations c
             INNER JOIN contacts ct ON ct.id = c.contact_id AND ct.tenant_id = c.tenant_id
             INNER JOIN evolution_instances i ON i.id = c.evolution_instance_id AND i.tenant_id = c.tenant_id
             INNER JOIN tenants t ON t.id = c.tenant_id
             ' . $where . '
             ORDER BY COALESCE(c.last_message_at, c.created_at) DESC
             LIMIT 100'
        );
        $statement->execute($params);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function formatConversationForJson(array $conversation, int $selectedId): array
    {
        return [
            'id' => (int) $conversation['id'],
            'name' => (string) ($conversation['contact_name'] ?: $conversation['phone'] ?: 'Contato'),
            'phone' => (string) ($conversation['phone'] ?? ''),
            'tenant_name' => (string) ($conversation['tenant_name'] ?? ''),
            'instance_label' => (string) ($conversation['instance_label'] ?: $conversation['instance_name'] ?? ''),
            'preview' => (string) ($conversation['last_message_preview'] ?? ''),
            'last_message_at' => (string) ($conversation['last_message_at'] ?? ''),
            'last_message_label' => $this->formatTimeLabel((string) ($conversation['last_message_at'] ?? '')),
            'unread_count' => (int) ($conversation['unread_count'] ?? 0),
            'status' => (string) ($conversation['status'] ?? ''),
            'mode' => (string) ($conversation['attendance_mode'] ?? ''),
            'is_selected' => (int) $conversation['id'] === $selectedId,
        ];
    }

    private function formatMessageForJson(array $message): array
    {
        return [
            'id' => (int) $message['id'],
            'direction' => (string) $message['direction'],
            'sender_type' => (string) $message['sender_type'],
            'sender_name' => (string) ($message['sender_user_name'] ?? ''),
            'message_type' => (string) ($message['message_type'] ?? 'text'),
            'content' => (string) ($message['content'] ?? ''),
            'status' => (string) ($message['status'] ?? ''),
            'sent_at' => (string) $message['sent_at'],
            'time_label' => $this->formatTimeLabel((string) $message['sent_at']),
        ];
    }

    private function formatTimeLabel(string $dateTime): string
    {
        if ($dateTime === '') {
            return '';
        }
        $timestamp = strtotime($dateTime);
        if (!$timestamp) {
            return '';
        }
        return date('d/m H:i', $timestamp);
    }

    private function json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    private function conversationReturnPath(bool $includeConversationId = true): string
    {
        $rawQuery = trim((string) ($_POST['return_query'] ?? ''));
        if ($rawQuery === '') {
            return '/conversations';
        }

        parse_str(ltrim($rawQuery, '?'), $parsed);
        $allowedKeys = ['search', 'status', 'mode', 'instance_id', 'tenant_id', 'intent'];
        if ($includeConversationId) {
            $allowedKeys[] = 'conversation_id';
        }
        $safe = [];
        foreach ($allowedKeys as $key) {
            if (!array_key_exists($key, $parsed) || is_array($parsed[$key])) {
                continue;
            }
            $value = trim((string) $parsed[$key]);
            if ($value !== '') {
                $safe[$key] = $value;
            }
        }

        return '/conversations' . ($safe !== [] ? '?' . http_build_query($safe) : '');
    }

    private function redirect(string $path): never
    {
        header('Location: ' . Router::url($path));
        exit;
    }
}
