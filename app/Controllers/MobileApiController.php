<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\PublicId;
use App\Services\AccessControlService;
use App\Services\AgentRoutingService;
use App\Services\AiAfterHoursRecoveryService;
use App\Services\ConversationOwnershipService;
use PDO;
use Throwable;

final class MobileApiController
{
    public function login(): void
    {
        $body = $this->jsonBody();
        $email = mb_strtolower(trim((string) ($body['email'] ?? '')));
        $password = (string) ($body['password'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
            $this->json(['ok' => false, 'message' => 'Informe e-mail e senha válidos.'], 422);
        }

        if (!Auth::attempt($email, $password)) {
            $this->json(['ok' => false, 'message' => 'E-mail ou senha inválidos.'], 401);
        }

        $user = Auth::user();
        if (!$user) {
            $this->json(['ok' => false, 'message' => 'Não foi possível iniciar a sessão.'], 401);
        }

        $tenantId = isset($user['tenant_id']) ? (int) $user['tenant_id'] : null;
        if (($user['role'] ?? '') !== 'super_admin' && $tenantId) {
            $access = (new AccessControlService())->statusForTenant($tenantId);
            if (empty($access['allowed'])) {
                Auth::logout();
                $this->json([
                    'ok' => false,
                    'message' => (string) ($access['message'] ?? 'O acesso da empresa está temporariamente restrito.'),
                ], 403);
            }
        }

        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $deviceName = trim((string) ($body['device_name'] ?? 'RS Connect Mobile'));
        $pdo = Database::connection();
        $pdo->prepare('DELETE FROM mobile_api_tokens WHERE expires_at < NOW() OR revoked_at IS NOT NULL')
            ->execute();
        $statement = $pdo->prepare(
            'INSERT INTO mobile_api_tokens (user_id, tenant_id, token_hash, device_name, expires_at)
             VALUES (:user_id, :tenant_id, :token_hash, :device_name, DATE_ADD(NOW(), INTERVAL 30 DAY))'
        );
        $statement->execute([
            'user_id' => (int) $user['id'],
            'tenant_id' => $tenantId,
            'token_hash' => $tokenHash,
            'device_name' => mb_substr($deviceName, 0, 160),
        ]);

        $payload = [
            'token' => $rawToken,
            'expires_in' => 2592000,
            'user' => $this->publicUser($user),
        ];
        Auth::logout();
        $this->json(['ok' => true] + $payload);
    }

    public function logout(): void
    {
        [$token] = $this->authenticate();
        Database::connection()->prepare('UPDATE mobile_api_tokens SET revoked_at = NOW() WHERE token_hash = :token_hash')
            ->execute(['token_hash' => hash('sha256', $token)]);
        $this->json(['ok' => true]);
    }

    public function me(): void
    {
        [, $user] = $this->authenticate();
        $this->json(['ok' => true, 'user' => $this->publicUser($user)]);
    }

    public function conversations(): void
    {
        [, $user] = $this->authenticate('conversations.view');
        $tenantId = $this->tenantId($user);
        $statement = Database::connection()->prepare(
            'SELECT c.id, c.contact_id, c.status, c.attendance_mode, c.unread_count, c.last_message_at,
                    c.last_message_preview, c.assigned_user_id, c.department_id,
                    ct.name, ct.phone, ct.status AS contact_status, ct.contact_group,
                    u.name AS assigned_name, d.name AS department_name,
                    (SELECT l.title FROM crm_leads l WHERE l.tenant_id = c.tenant_id AND l.contact_id = c.contact_id
                     ORDER BY l.updated_at DESC, l.id DESC LIMIT 1) AS demand
             FROM conversations c
             INNER JOIN contacts ct ON ct.id = c.contact_id AND ct.tenant_id = c.tenant_id
             LEFT JOIN users u ON u.id = c.assigned_user_id AND u.tenant_id = c.tenant_id
             LEFT JOIN service_departments d ON d.id = c.department_id AND d.tenant_id = c.tenant_id
             WHERE c.tenant_id = :tenant_id
             ORDER BY COALESCE(c.last_message_at, c.created_at) DESC
             LIMIT 120'
        );
        $statement->execute(['tenant_id' => $tenantId]);
        $conversations = array_map(function (array $row): array {
            $name = trim((string) ($row['name'] ?? '')) ?: (string) $row['phone'];
            return [
                'id' => PublicId::encode('conversation', (int) $row['id']),
                'contactId' => PublicId::encode('contact', (int) $row['contact_id']),
                'name' => $name,
                'initials' => $this->initials($name),
                'phone' => (string) $row['phone'],
                'preview' => trim((string) ($row['last_message_preview'] ?? '')),
                'time' => $this->dateTimeLabel((string) ($row['last_message_at'] ?? '')),
                'unread' => (int) $row['unread_count'],
                'type' => $this->contactType((string) $row['contact_status'], (string) $row['contact_group']),
                'temperature' => $this->temperature((string) $row['contact_status'], (string) $row['contact_group']),
                'demand' => trim((string) ($row['demand'] ?? '')) ?: 'Demanda não informada',
                'responsible' => trim((string) ($row['assigned_name'] ?? '')) ?: ((string) $row['attendance_mode'] === 'ai' ? 'Agente RS • IA' : 'Equipe de atendimento'),
                'responsibleUserId' => !empty($row['assigned_user_id']) ? PublicId::encode('user', (int) $row['assigned_user_id']) : null,
                'department' => trim((string) ($row['department_name'] ?? '')),
                'attendanceMode' => (string) ($row['attendance_mode'] ?? 'ai'),
                'aiActive' => (string) $row['attendance_mode'] === 'ai',
                'aiPaused' => (string) $row['attendance_mode'] === 'paused',
                'isMine' => (int) ($row['assigned_user_id'] ?? 0) === (int) (Auth::id() ?? 0),
            ];
        }, $statement->fetchAll(PDO::FETCH_ASSOC));

        $this->json([
            'ok' => true,
            'conversations' => $conversations,
            'unread_total' => array_sum(array_column($conversations, 'unread')),
        ]);
    }

    public function conversationContext(): void
    {
        [, $user] = $this->authenticate('conversations.view');
        $conversationId = PublicId::decode('conversation', trim((string) ($_GET['conversation_id'] ?? '')));
        if (!$conversationId) {
            $this->json(['ok' => false, 'message' => 'Conversa inválida.'], 422);
        }

        $pdo = Database::connection();
        $conversation = $this->findConversation($pdo, $this->tenantId($user), $conversationId);
        if (!$conversation) {
            $this->json(['ok' => false, 'message' => 'Conversa não encontrada para sua empresa.'], 404);
        }

        $this->json(['ok' => true] + $this->conversationContextPayload($pdo, $conversation));
    }

    public function conversationAssignment(): void
    {
        [, $user] = $this->authenticate('conversations.manage');
        $body = $this->jsonBody();
        $conversationId = PublicId::decode('conversation', trim((string) ($body['conversation_id'] ?? '')));
        $action = trim((string) ($body['action'] ?? 'claim'));
        $targetUserId = null;
        if (!empty($body['assigned_user_id'])) {
            $targetUserId = PublicId::decode('user', trim((string) $body['assigned_user_id']));
            if (!$targetUserId) {
                $this->json(['ok' => false, 'message' => 'Profissional inválido.'], 422);
            }
        }
        if (!$conversationId || !in_array($action, ['claim', 'assign', 'transfer', 'release'], true)) {
            $this->json(['ok' => false, 'message' => 'Ação de atendimento inválida.'], 422);
        }

        $pdo = Database::connection();
        $tenantId = $this->tenantId($user);
        $conversation = $this->findConversation($pdo, $tenantId, $conversationId);
        if (!$conversation) {
            $this->json(['ok' => false, 'message' => 'Conversa não encontrada para sua empresa.'], 404);
        }

        try {
            $result = (new ConversationOwnershipService())->changeAssignment($pdo, $conversationId, $targetUserId, $action);
            $name = trim((string) ($result['assigned_user_name'] ?? ''));
            $description = match ($action) {
                'claim' => 'Atendimento assumido por ' . ($name !== '' ? $name : (string) ($user['name'] ?? 'usuário')) . '.',
                'release' => 'Conversa liberada para a equipe.',
                'transfer' => 'Atendimento transferido para ' . ($name !== '' ? $name : 'outro profissional') . '.',
                default => 'Responsável definido como ' . ($name !== '' ? $name : 'profissional selecionado') . '.',
            };
            $this->insertEvent($conversationId, $tenantId, 'ownership.' . $action, $description);
            Audit::log('conversation.ownership_changed_mobile', $result, $tenantId);
            $conversation = $this->findConversation($pdo, $tenantId, $conversationId) ?: $conversation;
            $this->json(['ok' => true, 'message' => $description] + $this->conversationContextPayload($pdo, $conversation));
        } catch (Throwable $exception) {
            $this->json(['ok' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function conversationDepartment(): void
    {
        [, $user] = $this->authenticate('conversations.manage');
        $body = $this->jsonBody();
        $conversationId = PublicId::decode('conversation', trim((string) ($body['conversation_id'] ?? '')));
        $targetDepartmentId = null;
        if (!empty($body['department_id'])) {
            $targetDepartmentId = PublicId::decode('department', trim((string) $body['department_id']));
            if (!$targetDepartmentId) {
                $this->json(['ok' => false, 'message' => 'Setor inválido.'], 422);
            }
        }
        if (!$conversationId) {
            $this->json(['ok' => false, 'message' => 'Conversa inválida.'], 422);
        }

        $pdo = Database::connection();
        $tenantId = $this->tenantId($user);
        $conversation = $this->findConversation($pdo, $tenantId, $conversationId);
        if (!$conversation) {
            $this->json(['ok' => false, 'message' => 'Conversa não encontrada para sua empresa.'], 404);
        }

        try {
            $result = (new ConversationOwnershipService())->changeDepartment($pdo, $conversationId, $targetDepartmentId);
            $departmentName = trim((string) ($result['department_name'] ?? ''));
            $description = $targetDepartmentId !== null
                ? 'Conversa transferida para o setor ' . ($departmentName !== '' ? $departmentName : 'selecionado') . '.'
                : 'Conversa liberada da fila de setor.';
            $this->insertEvent($conversationId, $tenantId, 'ownership.department_transfer', $description);
            Audit::log('conversation.department_transferred_mobile', $result, $tenantId);
            $conversation = $this->findConversation($pdo, $tenantId, $conversationId) ?: $conversation;
            $this->json(['ok' => true, 'message' => $description] + $this->conversationContextPayload($pdo, $conversation));
        } catch (Throwable $exception) {
            $this->json(['ok' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function conversationMessages(): void
    {
        [, $user] = $this->authenticate('conversations.view');
        $conversationId = PublicId::decode('conversation', trim((string) ($_GET['conversation_id'] ?? '')));
        if (!$conversationId) {
            $this->json(['ok' => false, 'message' => 'Conversa inválida.'], 422);
        }
        $tenantId = $this->tenantId($user);
        $check = Database::connection()->prepare('SELECT id FROM conversations WHERE id = :id AND tenant_id = :tenant_id LIMIT 1');
        $check->execute(['id' => $conversationId, 'tenant_id' => $tenantId]);
        if (!$check->fetchColumn()) {
            $this->json(['ok' => false, 'message' => 'Conversa não encontrada para sua empresa.'], 404);
        }

        $statement = Database::connection()->prepare(
            'SELECT id, direction, sender_type, content, delivered_content, sent_at
             FROM conversation_messages
             WHERE tenant_id = :tenant_id AND conversation_id = :conversation_id
             ORDER BY sent_at DESC, id DESC
             LIMIT 120'
        );
        $statement->execute(['tenant_id' => $tenantId, 'conversation_id' => $conversationId]);
        $rows = array_reverse($statement->fetchAll(PDO::FETCH_ASSOC));
        $messages = array_map(function (array $message): array {
            return [
                'id' => PublicId::encode('message', (int) $message['id']),
                'text' => (string) ($message['content'] ?: $message['delivered_content'] ?: ''),
                'time' => $this->timeLabel((string) $message['sent_at']),
                'direction' => $message['direction'] === 'outgoing' ? 'out' : 'in',
                'author' => $message['sender_type'] === 'ai' ? 'ai' : ($message['sender_type'] === 'contact' ? 'contact' : 'human'),
            ];
        }, $rows);
        $this->json(['ok' => true, 'messages' => $messages]);
    }

    public function markConversationRead(): void
    {
        [, $user] = $this->authenticate('conversations.view');
        $body = $this->jsonBody();
        $conversationId = PublicId::decode('conversation', trim((string) ($body['conversation_id'] ?? '')));
        if (!$conversationId) {
            $this->json(['ok' => false, 'message' => 'Conversa inválida.'], 422);
        }
        $statement = Database::connection()->prepare(
            'UPDATE conversations SET unread_count = 0 WHERE id = :id AND tenant_id = :tenant_id'
        );
        $statement->execute(['id' => $conversationId, 'tenant_id' => $this->tenantId($user)]);
        if ($statement->rowCount() < 1) {
            $check = Database::connection()->prepare('SELECT id FROM conversations WHERE id = :id AND tenant_id = :tenant_id LIMIT 1');
            $check->execute(['id' => $conversationId, 'tenant_id' => $this->tenantId($user)]);
            if (!$check->fetchColumn()) {
                $this->json(['ok' => false, 'message' => 'Conversa não encontrada para sua empresa.'], 404);
            }
        }
        $this->json(['ok' => true]);
    }

    public function sendConversation(): void
    {
        [, $user] = $this->authenticate('conversations.manage');
        $body = $this->jsonBody();
        $conversationPublicId = trim((string) ($body['conversation_id'] ?? ''));
        $conversationId = PublicId::decode('conversation', $conversationPublicId);
        $message = trim((string) ($body['message'] ?? ''));
        if (!$conversationId || $message === '') {
            $this->json(['ok' => false, 'message' => 'Informe a conversa e a mensagem.'], 422);
        }
        $check = Database::connection()->prepare('SELECT id FROM conversations WHERE id = :id AND tenant_id = :tenant_id LIMIT 1');
        $check->execute(['id' => $conversationId, 'tenant_id' => $this->tenantId($user)]);
        if (!$check->fetchColumn()) {
            $this->json(['ok' => false, 'message' => 'Conversa não encontrada para sua empresa.'], 404);
        }
        $_POST['conversation_id'] = (string) $conversationId;
        $_POST['message'] = $message;
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        (new ConversationController())->send();
    }

    public function conversationMode(): void
    {
        [, $user] = $this->authenticate('conversations.manage');
        $body = $this->jsonBody();
        $conversationId = PublicId::decode('conversation', trim((string) ($body['conversation_id'] ?? '')));
        $mode = trim((string) ($body['mode'] ?? ''));
        if (!$conversationId || !in_array($mode, ['ai', 'human', 'paused'], true)) {
            $this->json(['ok' => false, 'message' => 'Modo de atendimento inválido.'], 422);
        }

        $pdo = Database::connection();
        $tenantId = $this->tenantId($user);
        $conversation = $this->findConversation($pdo, $tenantId, $conversationId);
        if (!$conversation) {
            $this->json(['ok' => false, 'message' => 'Conversa não encontrada para sua empresa.'], 404);
        }

        $ownership = new ConversationOwnershipService();
        $settings = $ownership->settingsForTenant($pdo, $tenantId);
        $resolvedAfterHours = 0;

        try {
            $pdo->beginTransaction();
            if (!empty($settings['enabled'])) {
                $ownership->assertMayInteract($pdo, $conversation);
                $conversation = $ownership->reopenIfClosed($pdo, $conversation);
                if ($mode === 'human') {
                    $conversation = $ownership->claimForHumanAction($pdo, $conversation);
                    $pdo->prepare(
                        'UPDATE conversations SET attendance_mode = "human" WHERE id = :id AND tenant_id = :tenant_id'
                    )->execute(['id' => $conversationId, 'tenant_id' => $tenantId]);
                    $resolvedAfterHours = (new AiAfterHoursRecoveryService())->resolveForHumanTakeover(
                        $pdo,
                        $tenantId,
                        $conversationId,
                        (int) ($user['id'] ?? 0),
                        'mobile_mode_human'
                    );
                } elseif ($mode === 'ai') {
                    if ((int) ($conversation['assigned_user_id'] ?? 0) > 0) {
                        $ownership->changeAssignment($pdo, $conversationId, null, 'release');
                    }
                    $pdo->prepare(
                        'UPDATE conversations SET attendance_mode = "ai", status = IF(status = "closed", "open", status)
                         WHERE id = :id AND tenant_id = :tenant_id'
                    )->execute(['id' => $conversationId, 'tenant_id' => $tenantId]);
                } else {
                    $pdo->prepare(
                        'UPDATE conversations SET attendance_mode = "paused", status = IF(status = "closed", "open", status)
                         WHERE id = :id AND tenant_id = :tenant_id'
                    )->execute(['id' => $conversationId, 'tenant_id' => $tenantId]);
                }
            } else {
                $assignedUserId = $mode === 'human' ? (int) ($user['id'] ?? 0) : null;
                $pdo->prepare(
                    'UPDATE conversations
                     SET attendance_mode = :mode,
                         assigned_user_id = :assigned_user_id,
                         assigned_at = IF(:assigned_date IS NULL, NULL, CURRENT_TIMESTAMP),
                         assignment_source = IF(:assigned_source IS NULL, "released", "manual_mode"),
                         assignment_updated_by_user_id = :actor_id,
                         assignment_released_at = IF(:assigned_release IS NULL, CURRENT_TIMESTAMP, NULL),
                         status = IF(status = "closed", "open", status),
                         status_changed_by_user_id = :status_actor
                     WHERE id = :id AND tenant_id = :tenant_id'
                )->execute([
                    'mode' => $mode,
                    'assigned_user_id' => $assignedUserId,
                    'assigned_date' => $assignedUserId,
                    'assigned_source' => $assignedUserId,
                    'assigned_release' => $assignedUserId,
                    'actor_id' => (int) ($user['id'] ?? 0),
                    'status_actor' => (int) ($user['id'] ?? 0),
                    'id' => $conversationId,
                    'tenant_id' => $tenantId,
                ]);
                if ($mode === 'human') {
                    $resolvedAfterHours = (new AiAfterHoursRecoveryService())->resolveForHumanTakeover(
                        $pdo,
                        $tenantId,
                        $conversationId,
                        (int) ($user['id'] ?? 0),
                        'mobile_mode_human'
                    );
                }
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->json(['ok' => false, 'message' => $exception->getMessage()], 422);
        }

        if ($mode === 'ai' && empty($conversation['ai_agent_id'])) {
            try {
                (new AgentRoutingService())->resolve(
                    $pdo,
                    ['id' => (int) ($conversation['evolution_instance_id'] ?? 0), 'tenant_id' => $tenantId],
                    $conversationId,
                    '',
                    true
                );
            } catch (Throwable) {
                // O roteamento também será resolvido na próxima mensagem recebida.
            }
        }

        $descriptions = [
            'ai' => 'IA ativada e atendimento liberado para automação.',
            'human' => 'Atendimento assumido por ' . (string) ($user['name'] ?? 'usuário') . '.',
            'paused' => 'IA pausada nesta conversa.',
        ];
        $this->insertEvent($conversationId, $tenantId, 'mode.' . $mode, $descriptions[$mode]);
        Audit::log('conversation.mode_changed_mobile', [
            'conversation_id' => $conversationId,
            'mode' => $mode,
            'after_hours_resolved' => $resolvedAfterHours,
        ], $tenantId);

        $conversation = $this->findConversation($pdo, $tenantId, $conversationId) ?: $conversation;
        $this->json(['ok' => true, 'message' => $descriptions[$mode], 'mode' => $mode] + $this->conversationContextPayload($pdo, $conversation));
    }

    public function contacts(): void
    {
        [, $user] = $this->authenticate('contacts.view');
        $tenantId = $this->tenantId($user);
        $statement = Database::connection()->prepare(
            'SELECT ct.id, ct.name, ct.phone, ct.status, ct.contact_group, ct.updated_at,
                    c.last_message_at,
                    u.name AS responsible,
                    (SELECT l.title FROM crm_leads l WHERE l.tenant_id = ct.tenant_id AND l.contact_id = ct.id
                     ORDER BY l.updated_at DESC, l.id DESC LIMIT 1) AS demand
             FROM contacts ct
             LEFT JOIN conversations c ON c.id = (
                 SELECT c2.id FROM conversations c2 WHERE c2.tenant_id = ct.tenant_id AND c2.contact_id = ct.id
                 ORDER BY COALESCE(c2.last_message_at, c2.created_at) DESC, c2.id DESC LIMIT 1
             )
             LEFT JOIN users u ON u.id = c.assigned_user_id
             WHERE ct.tenant_id = :tenant_id AND ct.status <> "inactive"
             ORDER BY COALESCE(c.last_message_at, ct.updated_at) DESC
             LIMIT 300'
        );
        $statement->execute(['tenant_id' => $tenantId]);
        $contacts = array_map(function (array $row): array {
            $name = trim((string) ($row['name'] ?? '')) ?: (string) $row['phone'];
            return [
                'id' => PublicId::encode('contact', (int) $row['id']),
                'name' => $name,
                'initials' => $this->initials($name),
                'phone' => (string) $row['phone'],
                'type' => $this->contactType((string) $row['status'], (string) $row['contact_group']),
                'temperature' => $this->temperature((string) $row['status'], (string) $row['contact_group']),
                'demand' => trim((string) ($row['demand'] ?? '')) ?: 'Demanda não informada',
                'responsible' => trim((string) ($row['responsible'] ?? '')) ?: 'Não atribuído',
                'lastContact' => $this->dateTimeLabel((string) ($row['last_message_at'] ?? $row['updated_at'] ?? '')),
            ];
        }, $statement->fetchAll(PDO::FETCH_ASSOC));
        $this->json(['ok' => true, 'contacts' => $contacts]);
    }

    public function appointments(): void
    {
        [, $user] = $this->authenticate('calendar.view');
        $tenantId = $this->tenantId($user);
        $statement = Database::connection()->prepare(
            'SELECT a.id, a.title, a.starts_at, a.ends_at, a.status, a.location_type, a.meeting_url,
                    ct.name AS contact_name, ct.phone, u.name AS owner_name
             FROM calendar_appointments a
             LEFT JOIN contacts ct ON ct.id = a.contact_id
             LEFT JOIN users u ON u.id = a.owner_user_id
             WHERE a.tenant_id = :tenant_id
               AND a.starts_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
               AND a.starts_at <= DATE_ADD(CURDATE(), INTERVAL 45 DAY)
             ORDER BY a.starts_at ASC
             LIMIT 300'
        );
        $statement->execute(['tenant_id' => $tenantId]);
        $appointments = array_map(function (array $row): array {
            $starts = strtotime((string) $row['starts_at']) ?: time();
            return [
                'id' => PublicId::encode('appointment', (int) $row['id']),
                'date' => date('Y-m-d', $starts),
                'time' => date('H:i', $starts),
                'title' => (string) $row['title'],
                'contact' => trim((string) ($row['contact_name'] ?? '')) ?: (string) ($row['phone'] ?? 'Contato'),
                'mode' => (string) $row['location_type'] === 'presencial' ? 'Presencial' : 'Google Meet',
                'responsible' => trim((string) ($row['owner_name'] ?? '')) ?: 'Equipe',
                'status' => in_array((string) $row['status'], ['confirmed', 'completed'], true) ? 'Confirmado' : 'Pendente',
            ];
        }, $statement->fetchAll(PDO::FETCH_ASSOC));
        $this->json(['ok' => true, 'appointments' => $appointments]);
    }

    public function agent(): void
    {
        [, $user] = $this->authenticate('agents.view');
        $tenantId = $this->tenantId($user);
        $statement = Database::connection()->prepare(
            'SELECT id, name, segment, model_provider, model_name, status, is_default, updated_at
             FROM ai_agents WHERE tenant_id = :tenant_id
             ORDER BY is_default DESC, status = "active" DESC, updated_at DESC LIMIT 20'
        );
        $statement->execute(['tenant_id' => $tenantId]);
        $agents = array_map(static fn(array $row): array => [
            'id' => PublicId::encode('agent', (int) $row['id']),
            'name' => (string) $row['name'],
            'segment' => (string) $row['segment'],
            'provider' => (string) $row['model_provider'],
            'model' => (string) $row['model_name'],
            'active' => (string) $row['status'] === 'active',
            'default' => (bool) $row['is_default'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
        $this->json(['ok' => true, 'agents' => $agents]);
    }

    public function dashboard(): void
    {
        [, $user] = $this->authenticate();
        $tenantId = $this->tenantId($user);
        $pdo = Database::connection();
        $q = $pdo->prepare(
            'SELECT
                (SELECT COUNT(*) FROM conversations WHERE tenant_id = :t1 AND status <> "closed") AS open_conversations,
                (SELECT COUNT(*) FROM conversations WHERE tenant_id = :t2 AND attendance_mode = "ai" AND last_message_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS ai_conversations,
                (SELECT COUNT(*) FROM calendar_appointments WHERE tenant_id = :t3 AND DATE(starts_at) = CURDATE() AND status NOT IN ("cancelled","rejected")) AS appointments_today,
                (SELECT COUNT(*) FROM contacts WHERE tenant_id = :t4 AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS new_contacts,
                (SELECT COALESCE(SUM(unread_count),0) FROM conversations WHERE tenant_id = :t5) AS unread_total'
        );
        $q->execute(['t1'=>$tenantId,'t2'=>$tenantId,'t3'=>$tenantId,'t4'=>$tenantId,'t5'=>$tenantId]);
        $metrics = $q->fetch(PDO::FETCH_ASSOC) ?: [];
        $this->json(['ok' => true, 'metrics' => array_map('intval', $metrics)]);
    }

    public function notifications(): void
    {
        [, $user] = $this->authenticate();
        $tenantId = $this->tenantId($user);
        $statement = Database::connection()->prepare(
            'SELECT COALESCE(SUM(unread_count), 0) FROM conversations WHERE tenant_id = :tenant_id'
        );
        $statement->execute(['tenant_id' => $tenantId]);
        $this->json(['ok' => true, 'unread_conversations' => (int) $statement->fetchColumn()]);
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function authenticate(?string $permission = null): array
    {
        $authorization = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
        if ($authorization === '' && function_exists('getallheaders')) {
            foreach ((array) getallheaders() as $name => $value) {
                if (strcasecmp((string) $name, 'Authorization') === 0) {
                    $authorization = trim((string) $value);
                    break;
                }
            }
        }
        if (!preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
            $this->json(['ok' => false, 'message' => 'Token de acesso ausente.'], 401);
        }
        $token = trim((string) $matches[1]);
        if (strlen($token) < 32) {
            $this->json(['ok' => false, 'message' => 'Token de acesso inválido.'], 401);
        }
        $statement = Database::connection()->prepare(
            'SELECT tkn.id AS token_id, u.id, u.tenant_id, u.name, u.email, u.role,
                    tn.name AS tenant_name
             FROM mobile_api_tokens tkn
             INNER JOIN users u ON u.id = tkn.user_id AND u.status = "active"
             LEFT JOIN tenants tn ON tn.id = u.tenant_id
             WHERE tkn.token_hash = :token_hash
               AND tkn.revoked_at IS NULL
               AND tkn.expires_at > NOW()
               AND (u.tenant_id IS NULL OR tn.status = "active")
             LIMIT 1'
        );
        $statement->execute(['token_hash' => hash('sha256', $token)]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            $this->json(['ok' => false, 'message' => 'Sua sessão expirou. Entre novamente.'], 401);
        }
        Database::connection()->prepare('UPDATE mobile_api_tokens SET last_used_at = NOW() WHERE id = :id')
            ->execute(['id' => (int) $user['token_id']]);
        unset($user['token_id']);
        Auth::setApiUser($user);
        if ($permission !== null && !Auth::can($permission)) {
            $this->json(['ok' => false, 'message' => 'Seu perfil não possui permissão para esta função.'], 403);
        }
        return [$token, $user];
    }

    /** @return array<string,mixed>|null */
    private function findConversation(PDO $pdo, int $tenantId, int $conversationId): ?array
    {
        $statement = $pdo->prepare(
            'SELECT c.*, u.name AS assigned_user_name, d.name AS department_name,
                    ct.name AS contact_name, ct.phone AS contact_phone
             FROM conversations c
             INNER JOIN contacts ct ON ct.id = c.contact_id AND ct.tenant_id = c.tenant_id
             LEFT JOIN users u ON u.id = c.assigned_user_id AND u.tenant_id = c.tenant_id
             LEFT JOIN service_departments d ON d.id = c.department_id AND d.tenant_id = c.tenant_id
             WHERE c.id = :id AND c.tenant_id = :tenant_id
             LIMIT 1'
        );
        $statement->execute(['id' => $conversationId, 'tenant_id' => $tenantId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @param array<string,mixed> $conversation @return array<string,mixed> */
    private function conversationContextPayload(PDO $pdo, array $conversation): array
    {
        $ownership = new ConversationOwnershipService();
        $snapshot = $ownership->snapshot($pdo, $conversation);
        $team = array_map(static fn(array $member): array => [
            'id' => PublicId::encode('user', (int) $member['id']),
            'name' => (string) $member['name'],
            'role' => (string) ($member['whatsapp_role_label'] ?: $member['role'] ?: ''),
        ], $ownership->teamForTenant($pdo, (int) $conversation['tenant_id']));
        $departments = array_map(static fn(array $department): array => [
            'id' => PublicId::encode('department', (int) $department['id']),
            'name' => (string) $department['name'],
            'members' => (int) ($department['members_count'] ?? 0),
        ], $ownership->departmentsForTenant($pdo, (int) $conversation['tenant_id']));

        $mode = (string) ($conversation['attendance_mode'] ?? 'ai');
        return [
            'conversation' => [
                'id' => PublicId::encode('conversation', (int) $conversation['id']),
                'mode' => $mode,
                'status' => (string) ($conversation['status'] ?? 'open'),
                'responsible' => trim((string) ($conversation['assigned_user_name'] ?? '')) ?: ($mode === 'ai' ? 'Agente RS • IA' : 'Equipe de atendimento'),
                'responsibleUserId' => !empty($conversation['assigned_user_id']) ? PublicId::encode('user', (int) $conversation['assigned_user_id']) : null,
                'department' => trim((string) ($conversation['department_name'] ?? '')),
                'departmentId' => !empty($conversation['department_id']) ? PublicId::encode('department', (int) $conversation['department_id']) : null,
                'isMine' => (int) ($conversation['assigned_user_id'] ?? 0) === (int) (Auth::id() ?? 0),
            ],
            'actions' => [
                'canManage' => Auth::can('conversations.manage'),
                'canInteract' => !empty($snapshot['can_interact']),
                'canClaim' => !empty($snapshot['can_claim']),
                'canAssign' => !empty($snapshot['can_assign']),
                'canTransfer' => !empty($snapshot['can_transfer']),
                'canRelease' => !empty($snapshot['can_release']),
                'lockedByOther' => !empty($snapshot['locked_by_other']),
            ],
            'team' => $team,
            'departments' => $departments,
        ];
    }

    private function insertEvent(int $conversationId, int $tenantId, string $type, string $description): void
    {
        try {
            Database::connection()->prepare(
                'INSERT INTO conversation_events (tenant_id, conversation_id, user_id, event_type, description)
                 VALUES (:tenant_id, :conversation_id, :user_id, :event_type, :description)'
            )->execute([
                'tenant_id' => $tenantId,
                'conversation_id' => $conversationId,
                'user_id' => Auth::id(),
                'event_type' => $type,
                'description' => mb_substr($description, 0, 255),
            ]);
        } catch (Throwable) {
            // A ação principal não deve falhar se o histórico operacional estiver indisponível.
        }
    }

    /** @param array<string,mixed> $user */
    private function tenantId(array $user): int
    {
        $tenantId = (int) ($user['tenant_id'] ?? 0);
        if ($tenantId < 1) {
            $this->json(['ok' => false, 'message' => 'Selecione uma empresa no painel web antes de usar o aplicativo.'], 422);
        }
        return $tenantId;
    }

    /** @return array<string,mixed> */
    private function jsonBody(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        if ($raw === '') {
            return $_POST;
        }
        try {
            $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : [];
        } catch (Throwable) {
            $this->json(['ok' => false, 'message' => 'Corpo JSON inválido.'], 400);
        }
    }

    /** @param array<string,mixed> $user @return array<string,mixed> */
    private function publicUser(array $user): array
    {
        return [
            'id' => PublicId::encode('user', (int) $user['id']),
            'name' => (string) ($user['name'] ?? ''),
            'email' => (string) ($user['email'] ?? ''),
            'role' => (string) ($user['role'] ?? ''),
            'tenant' => [
                'id' => !empty($user['tenant_id']) ? PublicId::encode('tenant', (int) $user['tenant_id']) : null,
                'name' => (string) ($user['tenant_name'] ?? 'RS Connect'),
            ],
        ];
    }

    private function contactType(string $status, string $group): string
    {
        if ($status === 'customer' || in_array($group, ['customer', 'patient'], true)) {
            return 'Cliente';
        }
        if (in_array($group, ['family', 'couple'], true)) {
            return 'Continuidade';
        }
        return 'Lead';
    }

    private function temperature(string $status, string $group): string
    {
        if ($status === 'customer' || in_array($group, ['customer', 'patient'], true)) {
            return 'Ativo';
        }
        if (in_array($group, ['family', 'couple'], true)) {
            return 'Acompanhamento';
        }
        if ($group === 'interested') {
            return 'Quente';
        }
        return 'Novo';
    }

    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name)) ?: [];
        $first = $parts[0] ?? '';
        $last = count($parts) > 1 ? $parts[count($parts) - 1] : '';
        return mb_strtoupper(mb_substr($first, 0, 1) . mb_substr($last, 0, 1));
    }

    private function timeLabel(string $value): string
    {
        $time = strtotime($value);
        return $time ? date('H:i', $time) : '';
    }

    private function dateTimeLabel(string $value): string
    {
        if ($value === '') {
            return '';
        }
        $time = strtotime($value);
        if (!$time) {
            return '';
        }
        if (date('Y-m-d', $time) === date('Y-m-d')) {
            return date('H:i', $time);
        }
        if (date('Y-m-d', $time) === date('Y-m-d', strtotime('-1 day'))) {
            return 'Ontem';
        }
        return date('d/m', $time);
    }

    /** @param array<string,mixed> $payload */
    private function json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
