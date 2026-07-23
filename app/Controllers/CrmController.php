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

final class CrmController
{
    public function index(): void
    {
        if (Auth::isSuperAdmin()) {
            (new AdminCrmController())->index();
            return;
        }
        $pdo = Database::connection();
        $tenantId = $this->resolveTenantFromQuery();

        $tenants = Auth::isSuperAdmin()
            ? $pdo->query('SELECT id, name FROM tenants WHERE status = "active" ORDER BY name')->fetchAll(PDO::FETCH_ASSOC)
            : [];

        $pipelines = [];
        $stages = [];
        $leads = [];
        $contacts = [];
        $team = [];
        $metrics = ['open_count' => 0, 'open_value' => 0, 'won_count' => 0, 'won_value' => 0];
        $selected = null;
        $notes = [];
        $selectedTasks = [];

        $filters = [
            'tenant_id' => $tenantId,
            'pipeline_id' => (int) ($_GET['pipeline_id'] ?? 0),
            'search' => trim((string) ($_GET['search'] ?? '')),
            'owner_id' => (int) ($_GET['owner_id'] ?? 0),
        ];

        if ($tenantId > 0) {
            $pipelineStatement = $pdo->prepare(
                'SELECT * FROM crm_pipelines WHERE tenant_id = :tenant_id ORDER BY is_default DESC, name'
            );
            $pipelineStatement->execute(['tenant_id' => $tenantId]);
            $pipelines = $pipelineStatement->fetchAll(PDO::FETCH_ASSOC);

            if ($filters['pipeline_id'] < 1 && $pipelines) {
                $filters['pipeline_id'] = (int) $pipelines[0]['id'];
            }

            if ($filters['pipeline_id'] > 0 && !$this->pipelineBelongsToTenant($filters['pipeline_id'], $tenantId)) {
                $filters['pipeline_id'] = $pipelines ? (int) $pipelines[0]['id'] : 0;
            }

            if ($filters['pipeline_id'] > 0) {
                $stageStatement = $pdo->prepare(
                    'SELECT * FROM crm_stages
                     WHERE tenant_id = :tenant_id AND pipeline_id = :pipeline_id
                     ORDER BY position'
                );
                $stageStatement->execute([
                    'tenant_id' => $tenantId,
                    'pipeline_id' => $filters['pipeline_id'],
                ]);
                $stages = $stageStatement->fetchAll(PDO::FETCH_ASSOC);

                $conditions = ['l.tenant_id = :tenant_id', 'l.pipeline_id = :pipeline_id'];
                $params = ['tenant_id' => $tenantId, 'pipeline_id' => $filters['pipeline_id']];
                if ($filters['search'] !== '') {
                    $conditions[] = '(l.title LIKE :search OR ct.name LIKE :search OR ct.phone LIKE :search OR ct.company LIKE :search)';
                    $params['search'] = '%' . $filters['search'] . '%';
                }
                if ($filters['owner_id'] > 0) {
                    $conditions[] = 'l.owner_user_id = :owner_id';
                    $params['owner_id'] = $filters['owner_id'];
                }

                $leadStatement = $pdo->prepare(
                    'SELECT l.*, ct.name AS contact_name, ct.phone, ct.company,
                            s.name AS stage_name, s.color_key, s.stage_type,
                            u.name AS owner_name,
                            (SELECT COUNT(*) FROM crm_tasks tk WHERE tk.lead_id = l.id AND tk.status = "pending") AS pending_tasks
                     FROM crm_leads l
                     INNER JOIN contacts ct ON ct.id = l.contact_id
                     INNER JOIN crm_stages s ON s.id = l.stage_id
                     LEFT JOIN users u ON u.id = l.owner_user_id
                     WHERE ' . implode(' AND ', $conditions) . '
                     ORDER BY l.updated_at DESC, l.id DESC'
                );
                $leadStatement->execute($params);
                $leads = $leadStatement->fetchAll(PDO::FETCH_ASSOC);
            }

            $contactStatement = $pdo->prepare(
                'SELECT id, name, phone, company FROM contacts
                 WHERE tenant_id = :tenant_id AND status <> "inactive"
                 ORDER BY COALESCE(name, phone)'
            );
            $contactStatement->execute(['tenant_id' => $tenantId]);
            $contacts = $contactStatement->fetchAll(PDO::FETCH_ASSOC);

            $teamStatement = $pdo->prepare(
                'SELECT id, name FROM users WHERE tenant_id = :tenant_id AND status = "active" ORDER BY name'
            );
            $teamStatement->execute(['tenant_id' => $tenantId]);
            $team = $teamStatement->fetchAll(PDO::FETCH_ASSOC);

            $metricStatement = $pdo->prepare(
                'SELECT
                    COALESCE(SUM(status = "open"), 0) AS open_count,
                    COALESCE(SUM(IF(status = "open", value, 0)), 0) AS open_value,
                    COALESCE(SUM(status = "won"), 0) AS won_count,
                    COALESCE(SUM(IF(status = "won", value, 0)), 0) AS won_value
                 FROM crm_leads
                 WHERE tenant_id = :tenant_id'
            );
            $metricStatement->execute(['tenant_id' => $tenantId]);
            $metrics = $metricStatement->fetch(PDO::FETCH_ASSOC) ?: $metrics;

            $leadId = (int) ($_GET['lead_id'] ?? 0);
            if ($leadId > 0) {
                $selected = $this->findLead($leadId, $tenantId);
                if ($selected) {
                    $noteStatement = $pdo->prepare(
                        'SELECT n.*, u.name AS user_name
                         FROM crm_notes n
                         LEFT JOIN users u ON u.id = n.user_id
                         WHERE n.lead_id = :lead_id AND n.tenant_id = :tenant_id
                         ORDER BY n.created_at DESC, n.id DESC
                         LIMIT 50'
                    );
                    $noteStatement->execute(['lead_id' => $leadId, 'tenant_id' => $tenantId]);
                    $notes = $noteStatement->fetchAll(PDO::FETCH_ASSOC);

                    $taskStatement = $pdo->prepare(
                        'SELECT tk.*, u.name AS assigned_name
                         FROM crm_tasks tk
                         LEFT JOIN users u ON u.id = tk.assigned_user_id
                         WHERE tk.lead_id = :lead_id AND tk.tenant_id = :tenant_id
                         ORDER BY (tk.status = "pending") DESC, tk.due_at IS NULL, tk.due_at, tk.id DESC
                         LIMIT 30'
                    );
                    $taskStatement->execute(['lead_id' => $leadId, 'tenant_id' => $tenantId]);
                    $selectedTasks = $taskStatement->fetchAll(PDO::FETCH_ASSOC);
                }
            }
        }

        View::render('crm.pipeline', [
            'title' => 'Comercial',
            'tenants' => $tenants,
            'pipelines' => $pipelines,
            'stages' => $stages,
            'leads' => $leads,
            'contacts' => $contacts,
            'team' => $team,
            'metrics' => $metrics,
            'selected' => $selected,
            'notes' => $notes,
            'selectedTasks' => $selectedTasks,
            'filters' => $filters,
            'canManage' => Auth::can('crm.manage'),
            'canManageTasks' => Auth::can('tasks.manage'),
        ]);
    }

    public function store(): void
    {
        $tenantId = $this->resolveTenantFromPost();
        $contactId = (int) ($_POST['contact_id'] ?? 0);
        $pipelineId = (int) ($_POST['pipeline_id'] ?? 0);
        $stageId = (int) ($_POST['stage_id'] ?? 0);
        $ownerId = (int) ($_POST['owner_user_id'] ?? 0);
        $title = trim((string) ($_POST['title'] ?? ''));
        $value = $this->moneyToDecimal((string) ($_POST['value'] ?? '0'));
        $priority = (string) ($_POST['priority'] ?? 'medium');
        $expectedClose = trim((string) ($_POST['expected_close_at'] ?? ''));

        if ($tenantId < 1 || $contactId < 1 || $pipelineId < 1 || $stageId < 1 || $title === '') {
            Flash::set('error', 'Preencha contato, título, funil e etapa.');
            $this->redirect('/crm' . ($tenantId > 0 ? '?tenant_id=' . $tenantId : ''));
        }
        if (!$this->contactBelongsToTenant($contactId, $tenantId)
            || !$this->pipelineBelongsToTenant($pipelineId, $tenantId)
            || !$this->stageBelongsToPipeline($stageId, $pipelineId, $tenantId)
            || ($ownerId > 0 && !$this->userBelongsToTenant($ownerId, $tenantId))) {
            Flash::set('error', 'Os dados selecionados não pertencem à mesma empresa.');
            $this->redirect('/crm?tenant_id=' . $tenantId . '&pipeline_id=' . $pipelineId);
        }
        if (!in_array($priority, ['low', 'medium', 'high'], true)) {
            $priority = 'medium';
        }

        $stage = $this->findStage($stageId, $tenantId);
        $status = $stage['stage_type'] ?? 'open';
        $closedAt = $status === 'open' ? null : date('Y-m-d H:i:s');

        $statement = Database::connection()->prepare(
            'INSERT INTO crm_leads
                (tenant_id, contact_id, pipeline_id, stage_id, owner_user_id, title, value,
                 priority, status, expected_close_at, closed_at)
             VALUES
                (:tenant_id, :contact_id, :pipeline_id, :stage_id, :owner_id, :title, :value,
                 :priority, :status, :expected_close_at, :closed_at)'
        );
        $statement->execute([
            'tenant_id' => $tenantId,
            'contact_id' => $contactId,
            'pipeline_id' => $pipelineId,
            'stage_id' => $stageId,
            'owner_id' => $ownerId > 0 ? $ownerId : null,
            'title' => $title,
            'value' => $value,
            'priority' => $priority,
            'status' => $status,
            'expected_close_at' => $expectedClose !== '' ? $expectedClose : null,
            'closed_at' => $closedAt,
        ]);
        $leadId = (int) Database::connection()->lastInsertId();
        Audit::log('crm.lead_created', ['lead_id' => $leadId, 'title' => $title], $tenantId);
        Flash::set('success', 'Negócio adicionado ao funil.');
        $this->redirect('/crm?tenant_id=' . $tenantId . '&pipeline_id=' . $pipelineId . '&lead_id=' . $leadId);
    }

    public function update(): void
    {
        $leadId = (int) ($_POST['lead_id'] ?? 0);
        $tenantId = $this->resolveTenantFromPost();
        $lead = $this->findLead($leadId, $tenantId);
        if (!$lead) {
            Flash::set('error', 'Negócio não encontrado.');
            $this->redirect('/crm');
        }

        $title = trim((string) ($_POST['title'] ?? ''));
        $ownerId = (int) ($_POST['owner_user_id'] ?? 0);
        $value = $this->moneyToDecimal((string) ($_POST['value'] ?? '0'));
        $priority = (string) ($_POST['priority'] ?? 'medium');
        $expectedClose = trim((string) ($_POST['expected_close_at'] ?? ''));
        $lostReason = trim((string) ($_POST['lost_reason'] ?? ''));

        if ($title === '' || ($ownerId > 0 && !$this->userBelongsToTenant($ownerId, $tenantId))) {
            Flash::set('error', 'Confira o título e o responsável.');
            $this->redirect($this->leadUrl($lead));
        }
        if (!in_array($priority, ['low', 'medium', 'high'], true)) {
            $priority = 'medium';
        }

        $statement = Database::connection()->prepare(
            'UPDATE crm_leads
             SET title = :title, owner_user_id = :owner_id, value = :value, priority = :priority,
                 expected_close_at = :expected_close_at, lost_reason = :lost_reason
             WHERE id = :id AND tenant_id = :tenant_id'
        );
        $statement->execute([
            'title' => $title,
            'owner_id' => $ownerId > 0 ? $ownerId : null,
            'value' => $value,
            'priority' => $priority,
            'expected_close_at' => $expectedClose !== '' ? $expectedClose : null,
            'lost_reason' => $lostReason !== '' ? $lostReason : null,
            'id' => $leadId,
            'tenant_id' => $tenantId,
        ]);
        Audit::log('crm.lead_updated', ['lead_id' => $leadId], $tenantId);
        Flash::set('success', 'Negócio atualizado.');
        $this->redirect($this->leadUrl($lead));
    }

    public function move(): void
    {
        $leadId = (int) ($_POST['lead_id'] ?? 0);
        $stageId = (int) ($_POST['stage_id'] ?? 0);
        $tenantId = $this->resolveTenantFromPost();
        $lead = $this->findLead($leadId, $tenantId);

        if (!$lead || !$this->stageBelongsToPipeline($stageId, (int) $lead['pipeline_id'], $tenantId)) {
            if ($this->wantsJson()) {
                $this->json(['ok' => false, 'message' => 'Não foi possível mover o negócio para essa etapa.'], 422);
            }
            Flash::set('error', 'Não foi possível mover o negócio para essa etapa.');
            $this->redirect('/crm');
        }

        $stage = $this->findStage($stageId, $tenantId);
        $status = $stage['stage_type'] ?? 'open';
        $closedAt = $status === 'open' ? null : date('Y-m-d H:i:s');

        $statement = Database::connection()->prepare(
            'UPDATE crm_leads
             SET stage_id = :stage_id, status = :status, closed_at = :closed_at,
                 lost_reason = IF(:reason_status = "lost", lost_reason, NULL)
             WHERE id = :id AND tenant_id = :tenant_id'
        );
        $statement->execute([
            'stage_id' => $stageId,
            'status' => $status,
            'closed_at' => $closedAt,
            'reason_status' => $status,
            'id' => $leadId,
            'tenant_id' => $tenantId,
        ]);
        Audit::log('crm.lead_moved', [
            'lead_id' => $leadId,
            'stage_id' => $stageId,
            'status' => $status,
        ], $tenantId);
        if ($this->wantsJson()) {
            $this->json([
                'ok' => true,
                'message' => 'Negócio movido para ' . ($stage['name'] ?? 'a nova etapa') . '.',
                'item_id' => $leadId,
                'stage_id' => $stageId,
                'stage_name' => $stage['name'] ?? '',
                'status' => $status,
                'metrics' => $this->tenantMetrics($tenantId),
            ]);
        }
        Flash::set('success', 'Negócio movido para ' . ($stage['name'] ?? 'a nova etapa') . '.');
        $lead['stage_id'] = $stageId;
        $this->redirect($this->leadUrl($lead));
    }

    public function addNote(): void
    {
        $leadId = (int) ($_POST['lead_id'] ?? 0);
        $tenantId = $this->resolveTenantFromPost();
        $note = trim((string) ($_POST['note'] ?? ''));
        $lead = $this->findLead($leadId, $tenantId);

        if (!$lead || $note === '') {
            Flash::set('error', 'Digite uma nota válida.');
            $this->redirect('/crm');
        }

        $statement = Database::connection()->prepare(
            'INSERT INTO crm_notes (tenant_id, contact_id, lead_id, user_id, note)
             VALUES (:tenant_id, :contact_id, :lead_id, :user_id, :note)'
        );
        $statement->execute([
            'tenant_id' => $tenantId,
            'contact_id' => $lead['contact_id'],
            'lead_id' => $leadId,
            'user_id' => Auth::id(),
            'note' => $note,
        ]);
        Audit::log('crm.note_created', ['lead_id' => $leadId], $tenantId);
        Flash::set('success', 'Nota adicionada ao negócio.');
        $this->redirect($this->leadUrl($lead));
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
        if ($leadId < 1 || $tenantId < 1) {
            return null;
        }
        $statement = Database::connection()->prepare(
            'SELECT l.*, ct.name AS contact_name, ct.phone, ct.email, ct.company, ct.notes AS contact_notes,
                    s.name AS stage_name, s.stage_type, p.name AS pipeline_name, u.name AS owner_name
             FROM crm_leads l
             INNER JOIN contacts ct ON ct.id = l.contact_id
             INNER JOIN crm_stages s ON s.id = l.stage_id
             INNER JOIN crm_pipelines p ON p.id = l.pipeline_id
             LEFT JOIN users u ON u.id = l.owner_user_id
             WHERE l.id = :id AND l.tenant_id = :tenant_id
             LIMIT 1'
        );
        $statement->execute(['id' => $leadId, 'tenant_id' => $tenantId]);
        $lead = $statement->fetch(PDO::FETCH_ASSOC);
        return $lead ?: null;
    }

    private function findStage(int $stageId, int $tenantId): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT * FROM crm_stages WHERE id = :id AND tenant_id = :tenant_id LIMIT 1'
        );
        $statement->execute(['id' => $stageId, 'tenant_id' => $tenantId]);
        $stage = $statement->fetch(PDO::FETCH_ASSOC);
        return $stage ?: null;
    }

    private function pipelineBelongsToTenant(int $pipelineId, int $tenantId): bool
    {
        $statement = Database::connection()->prepare(
            'SELECT COUNT(*) FROM crm_pipelines WHERE id = :id AND tenant_id = :tenant_id'
        );
        $statement->execute(['id' => $pipelineId, 'tenant_id' => $tenantId]);
        return (int) $statement->fetchColumn() > 0;
    }

    private function stageBelongsToPipeline(int $stageId, int $pipelineId, int $tenantId): bool
    {
        $statement = Database::connection()->prepare(
            'SELECT COUNT(*) FROM crm_stages
             WHERE id = :id AND pipeline_id = :pipeline_id AND tenant_id = :tenant_id'
        );
        $statement->execute(['id' => $stageId, 'pipeline_id' => $pipelineId, 'tenant_id' => $tenantId]);
        return (int) $statement->fetchColumn() > 0;
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

    private function moneyToDecimal(string $value): string
    {
        $clean = preg_replace('/[^0-9,.-]/', '', $value) ?: '0';
        if (str_contains($clean, ',')) {
            $clean = str_replace('.', '', $clean);
            $clean = str_replace(',', '.', $clean);
        }
        return number_format(max(0, (float) $clean), 2, '.', '');
    }

    private function leadUrl(array $lead): string
    {
        return '/crm?tenant_id=' . (int) $lead['tenant_id']
            . '&pipeline_id=' . (int) $lead['pipeline_id']
            . '&lead_id=' . (int) $lead['id'];
    }

    /**
     * Métricas do Comercial usadas pelo painel e pelo retorno AJAX do Kanban.
     * Mantém os cards superiores sincronizados imediatamente após mover uma oportunidade.
     */
    private function tenantMetrics(int $tenantId): array
    {
        $defaults = ['open_count' => 0, 'open_value' => 0, 'won_count' => 0, 'won_value' => 0];
        if ($tenantId < 1) {
            return $defaults;
        }

        $statement = Database::connection()->prepare(
            'SELECT
                COALESCE(SUM(status = "open"), 0) AS open_count,
                COALESCE(SUM(IF(status = "open", value, 0)), 0) AS open_value,
                COALESCE(SUM(status = "won"), 0) AS won_count,
                COALESCE(SUM(IF(status = "won", value, 0)), 0) AS won_value
             FROM crm_leads
             WHERE tenant_id = :tenant_id'
        );
        $statement->execute(['tenant_id' => $tenantId]);
        return $statement->fetch(PDO::FETCH_ASSOC) ?: $defaults;
    }

    private function wantsJson(): bool
    {
        return strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
            || str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');
    }

    private function json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    private function redirect(string $path): never
    {
        header('Location: ' . Router::url($path));
        exit;
    }
}
