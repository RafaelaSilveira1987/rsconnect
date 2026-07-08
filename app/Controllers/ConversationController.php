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
use App\Services\EvolutionService;
use PDO;
use Throwable;

final class ConversationController
{
    public function index(): void
    {
        $pdo = Database::connection();
        $filters = [
            'search' => trim((string) ($_GET['search'] ?? '')),
            'status' => trim((string) ($_GET['status'] ?? '')),
            'mode' => trim((string) ($_GET['mode'] ?? '')),
            'instance_id' => (int) ($_GET['instance_id'] ?? 0),
            'tenant_id' => Auth::isSuperAdmin() ? (int) ($_GET['tenant_id'] ?? 0) : (int) Auth::tenantId(),
        ];

        $conditions = [];
        $params = [];

        if (!Auth::isSuperAdmin()) {
            $conditions[] = 'c.tenant_id = :tenant_scope';
            $params['tenant_scope'] = Auth::tenantId();
        } elseif ($filters['tenant_id'] > 0) {
            $conditions[] = 'c.tenant_id = :tenant_scope';
            $params['tenant_scope'] = $filters['tenant_id'];
        }

        if ($filters['search'] !== '') {
            $conditions[] = '(ct.name LIKE :search OR ct.phone LIKE :search OR c.last_message_preview LIKE :search)';
            $params['search'] = '%' . $filters['search'] . '%';
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

        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
        $statement = $pdo->prepare(
            'SELECT c.*, ct.name AS contact_name, ct.phone, ct.email, ct.company, ct.notes, ct.tags_json,
                    ct.status AS contact_status, i.name AS instance_label, i.instance_name,
                    t.name AS tenant_name, u.name AS assigned_user_name
             FROM conversations c
             INNER JOIN contacts ct ON ct.id = c.contact_id
             INNER JOIN evolution_instances i ON i.id = c.evolution_instance_id
             INNER JOIN tenants t ON t.id = c.tenant_id
             LEFT JOIN users u ON u.id = c.assigned_user_id
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
            $selected = $this->findConversation($selectedId);
            if ($selected !== null) {
                $messageStatement = $pdo->prepare(
                    'SELECT * FROM (
                        SELECT m.*, u.name AS sender_user_name
                        FROM conversation_messages m
                        LEFT JOIN users u ON u.id = m.sender_user_id
                        WHERE m.conversation_id = :conversation_id
                        ORDER BY m.sent_at DESC, m.id DESC
                        LIMIT 250
                    ) recent
                    ORDER BY recent.sent_at ASC, recent.id ASC'
                );
                $messageStatement->execute(['conversation_id' => $selectedId]);
                $messages = $messageStatement->fetchAll(PDO::FETCH_ASSOC);

                if ((int) $selected['unread_count'] > 0) {
                    $pdo->prepare('UPDATE conversations SET unread_count = 0 WHERE id = :id')
                        ->execute(['id' => $selectedId]);
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
        $tags = array_values(array_filter(array_map('trim', explode(',', (string) ($_POST['tags'] ?? '')))));

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Flash::set('error', 'Informe um e-mail válido.');
            $this->redirect('/conversations?conversation_id=' . $conversationId);
        }
        if (!in_array($status, ['lead', 'customer', 'inactive'], true)) {
            $status = 'lead';
        }

        $statement = Database::connection()->prepare(
            'UPDATE contacts
             SET name = :name, email = :email, company = :company, notes = :notes,
                 tags_json = :tags_json, status = :status
             WHERE id = :contact_id AND tenant_id = :tenant_id'
        );
        $statement->execute([
            'name' => $name !== '' ? $name : null,
            'email' => $email !== '' ? $email : null,
            'company' => $company !== '' ? $company : null,
            'notes' => $notes !== '' ? $notes : null,
            'tags_json' => $tags ? json_encode($tags, JSON_UNESCAPED_UNICODE) : null,
            'status' => $status,
            'contact_id' => $conversation['contact_id'],
            'tenant_id' => $conversation['tenant_id'],
        ]);

        Audit::log('conversation.contact_updated', ['conversation_id' => $conversationId], (int) $conversation['tenant_id']);
        Flash::set('success', 'Dados do contato atualizados.');
        $this->redirect('/conversations?conversation_id=' . $conversationId);
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

    private function findConversation(int $id): ?array
    {
        $sql = 'SELECT c.*, ct.name AS contact_name, ct.phone, ct.email, ct.company, ct.notes,
                       ct.tags_json, ct.status AS contact_status, ct.id AS contact_id,
                       i.name AS instance_label, i.instance_name, i.base_url, i.api_key_encrypted,
                       t.name AS tenant_name, u.name AS assigned_user_name
                FROM conversations c
                INNER JOIN contacts ct ON ct.id = c.contact_id
                INNER JOIN evolution_instances i ON i.id = c.evolution_instance_id
                INNER JOIN tenants t ON t.id = c.tenant_id
                LEFT JOIN users u ON u.id = c.assigned_user_id
                WHERE c.id = :id';
        $params = ['id' => $id];

        if (!Auth::isSuperAdmin()) {
            $sql .= ' AND c.tenant_id = :tenant_id';
            $params['tenant_id'] = Auth::tenantId();
        }

        $statement = Database::connection()->prepare($sql . ' LIMIT 1');
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

    private function redirect(string $path): never
    {
        header('Location: ' . Router::url($path));
        exit;
    }
}
