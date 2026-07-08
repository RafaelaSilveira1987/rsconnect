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

final class TaskController
{
    public function index(): void
    {
        $pdo = Database::connection();
        $tenantId = $this->resolveTenantFromQuery();
        $filters = [
            'tenant_id' => $tenantId,
            'status' => (string) ($_GET['status'] ?? 'pending'),
            'type' => (string) ($_GET['type'] ?? ''),
            'assigned_user_id' => (int) ($_GET['assigned_user_id'] ?? 0),
        ];

        $tenants = Auth::isSuperAdmin()
            ? $pdo->query('SELECT id, name FROM tenants WHERE status = "active" ORDER BY name')->fetchAll(PDO::FETCH_ASSOC)
            : [];
        $tasks = [];
        $contacts = [];
        $leads = [];
        $team = [];
        $metrics = ['overdue' => 0, 'today_count' => 0, 'pending_count' => 0, 'completed_count' => 0];

        if ($tenantId > 0) {
            $conditions = ['tk.tenant_id = :tenant_id'];
            $params = ['tenant_id' => $tenantId];

            if (in_array($filters['status'], ['pending', 'completed', 'cancelled'], true)) {
                $conditions[] = 'tk.status = :status';
                $params['status'] = $filters['status'];
            }
            if (in_array($filters['type'], ['task', 'follow_up', 'call', 'meeting'], true)) {
                $conditions[] = 'tk.task_type = :task_type';
                $params['task_type'] = $filters['type'];
            }
            if ($filters['assigned_user_id'] > 0) {
                $conditions[] = 'tk.assigned_user_id = :assigned_user_id';
                $params['assigned_user_id'] = $filters['assigned_user_id'];
            }

            $statement = $pdo->prepare(
                'SELECT tk.*, ct.name AS contact_name, ct.phone,
                        l.title AS lead_title, u.name AS assigned_name, creator.name AS creator_name
                 FROM crm_tasks tk
                 LEFT JOIN contacts ct ON ct.id = tk.contact_id
                 LEFT JOIN crm_leads l ON l.id = tk.lead_id
                 LEFT JOIN users u ON u.id = tk.assigned_user_id
                 LEFT JOIN users creator ON creator.id = tk.created_by_user_id
                 WHERE ' . implode(' AND ', $conditions) . '
                 ORDER BY
                    (tk.status = "pending" AND tk.due_at IS NOT NULL AND tk.due_at < NOW()) DESC,
                    tk.due_at IS NULL,
                    tk.due_at,
                    tk.created_at DESC
                 LIMIT 250'
            );
            $statement->execute($params);
            $tasks = $statement->fetchAll(PDO::FETCH_ASSOC);

            $contactStatement = $pdo->prepare(
                'SELECT id, name, phone FROM contacts WHERE tenant_id = :tenant_id AND status <> "inactive" ORDER BY COALESCE(name, phone)'
            );
            $contactStatement->execute(['tenant_id' => $tenantId]);
            $contacts = $contactStatement->fetchAll(PDO::FETCH_ASSOC);

            $leadStatement = $pdo->prepare(
                'SELECT l.id, l.title, ct.name AS contact_name, ct.phone
                 FROM crm_leads l
                 INNER JOIN contacts ct ON ct.id = l.contact_id
                 WHERE l.tenant_id = :tenant_id AND l.status = "open"
                 ORDER BY l.updated_at DESC'
            );
            $leadStatement->execute(['tenant_id' => $tenantId]);
            $leads = $leadStatement->fetchAll(PDO::FETCH_ASSOC);

            $teamStatement = $pdo->prepare(
                'SELECT id, name FROM users WHERE tenant_id = :tenant_id AND status = "active" ORDER BY name'
            );
            $teamStatement->execute(['tenant_id' => $tenantId]);
            $team = $teamStatement->fetchAll(PDO::FETCH_ASSOC);

            $metricStatement = $pdo->prepare(
                'SELECT
                    COALESCE(SUM(status = "pending" AND due_at IS NOT NULL AND due_at < NOW()), 0) AS overdue,
                    COALESCE(SUM(status = "pending" AND DATE(due_at) = CURDATE()), 0) AS today_count,
                    COALESCE(SUM(status = "pending"), 0) AS pending_count,
                    COALESCE(SUM(status = "completed" AND completed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)), 0) AS completed_count
                 FROM crm_tasks
                 WHERE tenant_id = :tenant_id'
            );
            $metricStatement->execute(['tenant_id' => $tenantId]);
            $metrics = $metricStatement->fetch(PDO::FETCH_ASSOC) ?: $metrics;
        }

        View::render('tasks.index', [
            'title' => 'Tarefas e follow-ups',
            'tenants' => $tenants,
            'tasks' => $tasks,
            'contacts' => $contacts,
            'leads' => $leads,
            'team' => $team,
            'metrics' => $metrics,
            'filters' => $filters,
            'canManage' => Auth::can('tasks.manage'),
        ]);
    }

    public function store(): void
    {
        $tenantId = $this->resolveTenantFromPost();
        $leadId = (int) ($_POST['lead_id'] ?? 0);
        $contactId = (int) ($_POST['contact_id'] ?? 0);
        $assignedUserId = (int) ($_POST['assigned_user_id'] ?? 0);
        $taskType = (string) ($_POST['task_type'] ?? 'task');
        $title = trim((string) ($_POST['title'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $priority = (string) ($_POST['priority'] ?? 'medium');
        $dueAt = trim((string) ($_POST['due_at'] ?? ''));

        if ($tenantId < 1 || $title === '') {
            Flash::set('error', 'Informe a empresa e o título da tarefa.');
            $this->redirect('/tasks');
        }
        if (!in_array($taskType, ['task', 'follow_up', 'call', 'meeting'], true)) {
            $taskType = 'task';
        }
        if (!in_array($priority, ['low', 'medium', 'high'], true)) {
            $priority = 'medium';
        }
        if ($leadId > 0) {
            $lead = $this->findLead($leadId, $tenantId);
            if (!$lead) {
                Flash::set('error', 'O negócio selecionado não pertence à empresa.');
                $this->redirect('/tasks?tenant_id=' . $tenantId);
            }
            $contactId = (int) $lead['contact_id'];
        }
        if ($contactId > 0 && !$this->contactBelongsToTenant($contactId, $tenantId)) {
            Flash::set('error', 'O contato selecionado não pertence à empresa.');
            $this->redirect('/tasks?tenant_id=' . $tenantId);
        }
        if ($assignedUserId > 0 && !$this->userBelongsToTenant($assignedUserId, $tenantId)) {
            Flash::set('error', 'O responsável selecionado não pertence à empresa.');
            $this->redirect('/tasks?tenant_id=' . $tenantId);
        }

        $normalizedDue = null;
        if ($dueAt !== '') {
            $timestamp = strtotime($dueAt);
            if ($timestamp === false) {
                Flash::set('error', 'A data da tarefa é inválida.');
                $this->redirect('/tasks?tenant_id=' . $tenantId);
            }
            $normalizedDue = date('Y-m-d H:i:s', $timestamp);
        }

        $statement = Database::connection()->prepare(
            'INSERT INTO crm_tasks
                (tenant_id, contact_id, lead_id, assigned_user_id, created_by_user_id,
                 task_type, title, description, priority, status, due_at)
             VALUES
                (:tenant_id, :contact_id, :lead_id, :assigned_user_id, :created_by_user_id,
                 :task_type, :title, :description, :priority, "pending", :due_at)'
        );
        $statement->execute([
            'tenant_id' => $tenantId,
            'contact_id' => $contactId > 0 ? $contactId : null,
            'lead_id' => $leadId > 0 ? $leadId : null,
            'assigned_user_id' => $assignedUserId > 0 ? $assignedUserId : null,
            'created_by_user_id' => Auth::id(),
            'task_type' => $taskType,
            'title' => $title,
            'description' => $description !== '' ? $description : null,
            'priority' => $priority,
            'due_at' => $normalizedDue,
        ]);
        $taskId = (int) Database::connection()->lastInsertId();
        Audit::log('crm.task_created', ['task_id' => $taskId, 'type' => $taskType], $tenantId);
        Flash::set('success', 'Tarefa cadastrada.');

        $return = trim((string) ($_POST['return_to'] ?? ''));
        if ($return !== '' && str_starts_with($return, '/crm?')) {
            $this->redirect($return);
        }
        $this->redirect('/tasks?tenant_id=' . $tenantId . '&status=pending');
    }

    public function updateStatus(): void
    {
        $taskId = (int) ($_POST['task_id'] ?? 0);
        $status = (string) ($_POST['status'] ?? 'pending');
        $tenantId = $this->resolveTenantFromPost();

        if (!in_array($status, ['pending', 'completed', 'cancelled'], true)) {
            Flash::set('error', 'Status de tarefa inválido.');
            $this->redirect('/tasks');
        }

        $statement = Database::connection()->prepare(
            'UPDATE crm_tasks
             SET status = :status,
                 completed_at = IF(:completed_status = "completed", NOW(), NULL)
             WHERE id = :id AND tenant_id = :tenant_id'
        );
        $statement->execute([
            'status' => $status,
            'completed_status' => $status,
            'id' => $taskId,
            'tenant_id' => $tenantId,
        ]);

        if ($statement->rowCount() === 0) {
            Flash::set('error', 'Tarefa não encontrada.');
        } else {
            Audit::log('crm.task_status_updated', ['task_id' => $taskId, 'status' => $status], $tenantId);
            Flash::set('success', $status === 'completed' ? 'Tarefa concluída.' : 'Status da tarefa atualizado.');
        }

        $return = trim((string) ($_POST['return_to'] ?? ''));
        if ($return !== '' && str_starts_with($return, '/')) {
            $this->redirect($return);
        }
        $this->redirect('/tasks?tenant_id=' . $tenantId . '&status=' . $status);
    }

    private function resolveTenantFromQuery(): int
    {
        if (!Auth::isSuperAdmin()) {
            return (int) Auth::tenantId();
        }
        $requested = (int) ($_GET['tenant_id'] ?? 0);
        if ($requested > 0) {
            return $requested;
        }
        $first = Database::connection()->query('SELECT id FROM tenants WHERE status = "active" ORDER BY name LIMIT 1')->fetchColumn();
        return $first ? (int) $first : 0;
    }

    private function resolveTenantFromPost(): int
    {
        return Auth::isSuperAdmin()
            ? (int) ($_POST['tenant_id'] ?? 0)
            : (int) Auth::tenantId();
    }

    private function findLead(int $leadId, int $tenantId): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT id, contact_id FROM crm_leads WHERE id = :id AND tenant_id = :tenant_id LIMIT 1'
        );
        $statement->execute(['id' => $leadId, 'tenant_id' => $tenantId]);
        $lead = $statement->fetch(PDO::FETCH_ASSOC);
        return $lead ?: null;
    }

    private function contactBelongsToTenant(int $contactId, int $tenantId): bool
    {
        $statement = Database::connection()->prepare(
            'SELECT COUNT(*) FROM contacts WHERE id = :id AND tenant_id = :tenant_id'
        );
        $statement->execute(['id' => $contactId, 'tenant_id' => $tenantId]);
        return (int) $statement->fetchColumn() > 0;
    }

    private function userBelongsToTenant(int $userId, int $tenantId): bool
    {
        $statement = Database::connection()->prepare(
            'SELECT COUNT(*) FROM users WHERE id = :id AND tenant_id = :tenant_id AND status = "active"'
        );
        $statement->execute(['id' => $userId, 'tenant_id' => $tenantId]);
        return (int) $statement->fetchColumn() > 0;
    }

    private function redirect(string $path): never
    {
        header('Location: ' . Router::url($path));
        exit;
    }
}
