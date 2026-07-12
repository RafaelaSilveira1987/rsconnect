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

final class QueueController
{
    private array $statusLabels = [
        'new' => 'Novo',
        'waiting_agent' => 'Aguardando atendimento',
        'in_service' => 'Em atendimento',
        'waiting_customer' => 'Aguardando cliente',
        'resolved' => 'Resolvido',
        'archived' => 'Arquivado',
    ];

    private array $priorityLabels = [
        'low' => 'Baixa',
        'normal' => 'Normal',
        'high' => 'Alta',
        'urgent' => 'Urgente',
    ];

    public function index(): void
    {
        $pdo = Database::connection();
        $filters = [
            'tenant_id' => Auth::isSuperAdmin() ? (int) ($_GET['tenant_id'] ?? 0) : (int) Auth::tenantId(),
            'operational_status' => trim((string) ($_GET['operational_status'] ?? '')),
            'department_id' => (int) ($_GET['department_id'] ?? 0),
            'assigned_user_id' => (int) ($_GET['assigned_user_id'] ?? 0),
            'priority' => trim((string) ($_GET['priority'] ?? '')),
        ];

        $tenants = Auth::isSuperAdmin()
            ? $pdo->query('SELECT id, name FROM tenants WHERE status <> "inactive" ORDER BY name')->fetchAll(PDO::FETCH_ASSOC)
            : [];

        $tenantForLists = $filters['tenant_id'] > 0 ? $filters['tenant_id'] : (Auth::isSuperAdmin() ? null : Auth::tenantId());
        $departments = $this->departments($tenantForLists);
        $users = $this->users($tenantForLists);
        $metrics = $this->metrics($pdo, $filters);
        $conversations = $this->conversations($pdo, $filters);

        View::render('queue.index', [
            'title' => 'Fila de atendimento',
            'filters' => $filters,
            'tenants' => $tenants,
            'departments' => $departments,
            'users' => $users,
            'conversations' => $conversations,
            'metrics' => $metrics,
            'statusLabels' => $this->statusLabels,
            'priorityLabels' => $this->priorityLabels,
        ]);
    }

    public function storeDepartment(): void
    {
        $name = trim((string) ($_POST['name'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $color = trim((string) ($_POST['color'] ?? '#146498'));
        $tenantId = Auth::isSuperAdmin() ? (int) ($_POST['tenant_id'] ?? 0) : (int) Auth::tenantId();

        if ($tenantId < 1 || $name === '') {
            Flash::set('error', 'Informe a empresa e o nome do setor.');
            $this->redirect('/queue');
        }

        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            $color = '#146498';
        }

        try {
            Database::connection()->prepare(
                'INSERT INTO service_departments (tenant_id, name, description, color, status)
                 VALUES (:tenant_id, :name, :description, :color, "active")
                 ON DUPLICATE KEY UPDATE description = VALUES(description), color = VALUES(color), status = "active"'
            )->execute([
                'tenant_id' => $tenantId,
                'name' => $name,
                'description' => $description !== '' ? $description : null,
                'color' => $color,
            ]);
            Audit::log('queue.department_saved', ['name' => $name], $tenantId);
            Flash::set('success', 'Setor salvo.');
        } catch (Throwable $exception) {
            Flash::set('error', 'Não foi possível salvar o setor: ' . $exception->getMessage());
        }

        $this->redirect('/queue' . ($tenantId ? '?tenant_id=' . $tenantId : ''));
    }

    public function updateDepartmentStatus(): void
    {
        $departmentId = (int) ($_POST['department_id'] ?? 0);
        $status = (string) ($_POST['status'] ?? 'active');
        if ($departmentId < 1 || !in_array($status, ['active', 'inactive'], true)) {
            Flash::set('error', 'Setor inválido.');
            $this->redirect('/queue');
        }

        $department = $this->department($departmentId);
        if (!$department) {
            Flash::set('error', 'Setor não encontrado.');
            $this->redirect('/queue');
        }

        Database::connection()->prepare('UPDATE service_departments SET status = :status WHERE id = :id')
            ->execute(['status' => $status, 'id' => $departmentId]);
        Audit::log('queue.department_status', ['department_id' => $departmentId, 'status' => $status], (int) $department['tenant_id']);
        Flash::set('success', 'Status do setor atualizado.');
        $this->redirect('/queue?tenant_id=' . (int) $department['tenant_id']);
    }

    public function assign(): void
    {
        $conversationId = (int) ($_POST['conversation_id'] ?? 0);
        $userId = (int) ($_POST['assigned_user_id'] ?? 0) ?: null;
        $departmentId = (int) ($_POST['department_id'] ?? 0) ?: null;
        $priority = (string) ($_POST['priority'] ?? 'normal');
        $operationalStatus = (string) ($_POST['operational_status'] ?? 'in_service');

        if (!in_array($priority, array_keys($this->priorityLabels), true)) {
            $priority = 'normal';
        }
        if (!in_array($operationalStatus, array_keys($this->statusLabels), true)) {
            $operationalStatus = 'in_service';
        }

        $conversation = $this->conversation($conversationId);
        if (!$conversation) {
            Flash::set('error', 'Conversa não encontrada.');
            $this->redirect('/queue');
        }

        if ($userId !== null && !$this->userBelongsToTenant($userId, (int) $conversation['tenant_id'])) {
            Flash::set('error', 'Atendente fora da empresa da conversa.');
            $this->redirect('/queue');
        }
        if ($departmentId !== null && !$this->departmentBelongsToTenant($departmentId, (int) $conversation['tenant_id'])) {
            Flash::set('error', 'Setor fora da empresa da conversa.');
            $this->redirect('/queue');
        }

        $pdo = Database::connection();
        $pdo->prepare(
            'UPDATE conversations
             SET assigned_user_id = :user_id,
                 department_id = :department_id,
                 priority = :priority,
                 operational_status = :operational_status,
                 attendance_mode = IF(:user_id_for_mode IS NULL, attendance_mode, "human"),
                 assigned_at = IF(:user_id_for_date IS NULL, assigned_at, CURRENT_TIMESTAMP)
             WHERE id = :id'
        )->execute([
            'user_id' => $userId,
            'department_id' => $departmentId,
            'priority' => $priority,
            'operational_status' => $operationalStatus,
            'user_id_for_mode' => $userId,
            'user_id_for_date' => $userId,
            'id' => $conversationId,
        ]);

        $this->insertEvent($conversationId, (int) $conversation['tenant_id'], 'queue.assigned', 'Conversa distribuída na fila de atendimento.');
        Audit::log('queue.conversation_assigned', [
            'conversation_id' => $conversationId,
            'assigned_user_id' => $userId,
            'department_id' => $departmentId,
            'priority' => $priority,
            'operational_status' => $operationalStatus,
        ], (int) $conversation['tenant_id']);
        Flash::set('success', 'Conversa atualizada na fila.');
        $this->redirect('/queue?tenant_id=' . (int) $conversation['tenant_id']);
    }

    private function conversations(PDO $pdo, array $filters): array
    {
        $where = [];
        $params = [];
        if (!Auth::isSuperAdmin()) {
            $where[] = 'c.tenant_id = :tenant_scope';
            $params['tenant_scope'] = Auth::tenantId();
        } elseif (($filters['tenant_id'] ?? 0) > 0) {
            $where[] = 'c.tenant_id = :tenant_scope';
            $params['tenant_scope'] = (int) $filters['tenant_id'];
        }
        if (in_array($filters['operational_status'] ?? '', array_keys($this->statusLabels), true)) {
            $where[] = 'c.operational_status = :operational_status';
            $params['operational_status'] = $filters['operational_status'];
        }
        if (($filters['department_id'] ?? 0) > 0) {
            $where[] = 'c.department_id = :department_id';
            $params['department_id'] = (int) $filters['department_id'];
        }
        if (($filters['assigned_user_id'] ?? 0) > 0) {
            $where[] = 'c.assigned_user_id = :assigned_user_id';
            $params['assigned_user_id'] = (int) $filters['assigned_user_id'];
        }
        if (in_array($filters['priority'] ?? '', array_keys($this->priorityLabels), true)) {
            $where[] = 'c.priority = :priority';
            $params['priority'] = $filters['priority'];
        }
        $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $statement = $pdo->prepare(
            'SELECT c.id, c.tenant_id, c.status, c.attendance_mode, c.operational_status, c.priority,
                    c.unread_count, c.last_message_at, c.last_message_preview, c.assigned_at,
                    ct.name AS contact_name, ct.phone, t.name AS tenant_name,
                    i.name AS instance_label, u.name AS assigned_user_name,
                    d.name AS department_name, d.color AS department_color
             FROM conversations c
             INNER JOIN contacts ct ON ct.id = c.contact_id
             INNER JOIN tenants t ON t.id = c.tenant_id
             INNER JOIN evolution_instances i ON i.id = c.evolution_instance_id
             LEFT JOIN users u ON u.id = c.assigned_user_id
             LEFT JOIN service_departments d ON d.id = c.department_id
             ' . $sqlWhere . '
             ORDER BY FIELD(c.priority, "urgent", "high", "normal", "low"),
                      FIELD(c.operational_status, "new", "waiting_agent", "in_service", "waiting_customer", "resolved", "archived"),
                      COALESCE(c.last_message_at, c.created_at) DESC
             LIMIT 180'
        );
        $statement->execute($params);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function metrics(PDO $pdo, array $filters): array
    {
        $where = [];
        $params = [];
        if (!Auth::isSuperAdmin()) {
            $where[] = 'tenant_id = :tenant_scope';
            $params['tenant_scope'] = Auth::tenantId();
        } elseif (($filters['tenant_id'] ?? 0) > 0) {
            $where[] = 'tenant_id = :tenant_scope';
            $params['tenant_scope'] = (int) $filters['tenant_id'];
        }
        $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $statement = $pdo->prepare(
            'SELECT
                COUNT(*) AS total,
                SUM(operational_status IN ("new", "waiting_agent")) AS pending,
                SUM(operational_status = "in_service") AS in_service,
                SUM(operational_status = "waiting_customer") AS waiting_customer,
                SUM(operational_status IN ("resolved", "archived")) AS finished,
                SUM(assigned_user_id IS NULL AND operational_status NOT IN ("resolved", "archived")) AS unassigned,
                SUM(priority IN ("high", "urgent") AND operational_status NOT IN ("resolved", "archived")) AS priority_open,
                SUM(unread_count > 0) AS unread_threads
             FROM conversations ' . $sqlWhere
        );
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        return array_map('intval', $row);
    }

    private function departments(?int $tenantId): array
    {
        if ($tenantId !== null && $tenantId > 0) {
            $statement = Database::connection()->prepare(
                'SELECT d.*, t.name AS tenant_name
                 FROM service_departments d
                 INNER JOIN tenants t ON t.id = d.tenant_id
                 WHERE d.tenant_id = :tenant_id
                 ORDER BY d.status, d.name'
            );
            $statement->execute(['tenant_id' => $tenantId]);
            return $statement->fetchAll(PDO::FETCH_ASSOC);
        }

        return Database::connection()->query(
            'SELECT d.*, t.name AS tenant_name
             FROM service_departments d
             INNER JOIN tenants t ON t.id = d.tenant_id
             ORDER BY t.name, d.status, d.name'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    private function users(?int $tenantId): array
    {
        if ($tenantId !== null && $tenantId > 0) {
            $statement = Database::connection()->prepare(
                'SELECT id, tenant_id, name, role
                 FROM users
                 WHERE tenant_id = :tenant_id AND status = "active"
                 ORDER BY name'
            );
            $statement->execute(['tenant_id' => $tenantId]);
            return $statement->fetchAll(PDO::FETCH_ASSOC);
        }

        return Database::connection()->query(
            'SELECT id, tenant_id, name, role
             FROM users
             WHERE tenant_id IS NOT NULL AND status = "active"
             ORDER BY name'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    private function conversation(int $conversationId): ?array
    {
        $sql = 'SELECT * FROM conversations WHERE id = :id';
        $params = ['id' => $conversationId];
        if (!Auth::isSuperAdmin()) {
            $sql .= ' AND tenant_id = :tenant_id';
            $params['tenant_id'] = Auth::tenantId();
        }
        $statement = Database::connection()->prepare($sql . ' LIMIT 1');
        $statement->execute($params);
        $conversation = $statement->fetch(PDO::FETCH_ASSOC);
        return $conversation ?: null;
    }

    private function department(int $departmentId): ?array
    {
        $sql = 'SELECT * FROM service_departments WHERE id = :id';
        $params = ['id' => $departmentId];
        if (!Auth::isSuperAdmin()) {
            $sql .= ' AND tenant_id = :tenant_id';
            $params['tenant_id'] = Auth::tenantId();
        }
        $statement = Database::connection()->prepare($sql . ' LIMIT 1');
        $statement->execute($params);
        $department = $statement->fetch(PDO::FETCH_ASSOC);
        return $department ?: null;
    }

    private function userBelongsToTenant(int $userId, int $tenantId): bool
    {
        $statement = Database::connection()->prepare('SELECT COUNT(*) FROM users WHERE id = :id AND tenant_id = :tenant_id AND status = "active"');
        $statement->execute(['id' => $userId, 'tenant_id' => $tenantId]);
        return (int) $statement->fetchColumn() > 0;
    }

    private function departmentBelongsToTenant(int $departmentId, int $tenantId): bool
    {
        $statement = Database::connection()->prepare('SELECT COUNT(*) FROM service_departments WHERE id = :id AND tenant_id = :tenant_id AND status = "active"');
        $statement->execute(['id' => $departmentId, 'tenant_id' => $tenantId]);
        return (int) $statement->fetchColumn() > 0;
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
