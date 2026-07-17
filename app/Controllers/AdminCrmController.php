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

final class AdminCrmController
{
    public function index(): void
    {
        $pdo = Database::connection();
        $filters = [
            'q' => trim((string) ($_GET['q'] ?? '')),
            'stage_id' => (int) ($_GET['stage_id'] ?? 0),
            'owner_id' => (int) ($_GET['owner_id'] ?? 0),
            'priority' => trim((string) ($_GET['priority'] ?? '')),
        ];

        try {
            $stages = $pdo->query('SELECT * FROM admin_crm_stages ORDER BY position')->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            View::render('crm.admin_setup', [
                'title' => 'CRM comercial RS',
                'migration' => 'database/migrations/037_admin_commercial_crm_reports.sql',
            ]);
            return;
        }
        $team = $pdo->query('SELECT id, name FROM users WHERE role = "super_admin" AND status = "active" ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);

        $where = ['1=1'];
        $params = [];
        if ($filters['q'] !== '') {
            $where[] = '(o.company_name LIKE :q OR o.contact_name LIKE :q OR o.email LIKE :q OR o.phone LIKE :q OR o.title LIKE :q)';
            $params['q'] = '%' . $filters['q'] . '%';
        }
        if ($filters['stage_id'] > 0) {
            $where[] = 'o.stage_id = :stage_id';
            $params['stage_id'] = $filters['stage_id'];
        }
        if ($filters['owner_id'] > 0) {
            $where[] = 'o.owner_user_id = :owner_id';
            $params['owner_id'] = $filters['owner_id'];
        }
        if (in_array($filters['priority'], ['low', 'medium', 'high'], true)) {
            $where[] = 'o.priority = :priority';
            $params['priority'] = $filters['priority'];
        }

        $statement = $pdo->prepare(
            'SELECT o.*, s.name AS stage_name, s.stage_key, s.stage_type, s.color_key, s.position,
                    u.name AS owner_name, t.name AS tenant_name,
                    (SELECT COUNT(*) FROM admin_crm_activities a WHERE a.opportunity_id = o.id AND a.status = "pending") AS pending_activities,
                    (SELECT MIN(a.due_at) FROM admin_crm_activities a WHERE a.opportunity_id = o.id AND a.status = "pending") AS next_due_at
             FROM admin_crm_opportunities o
             INNER JOIN admin_crm_stages s ON s.id = o.stage_id
             LEFT JOIN users u ON u.id = o.owner_user_id
             LEFT JOIN tenants t ON t.id = o.tenant_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY s.position, o.priority = "high" DESC, o.updated_at DESC, o.id DESC'
        );
        $statement->execute($params);
        $opportunities = $statement->fetchAll(PDO::FETCH_ASSOC);

        $metrics = $pdo->query(
            'SELECT
                COALESCE(SUM(status = "open"),0) AS open_count,
                COALESCE(SUM(IF(status = "open", value, 0)),0) AS open_value,
                COALESCE(SUM(status IN ("won","active")),0) AS won_count,
                COALESCE(SUM(IF(status IN ("won","active"), value, 0)),0) AS won_value,
                COALESCE(SUM(status = "lost"),0) AS lost_count
             FROM admin_crm_opportunities'
        )->fetch(PDO::FETCH_ASSOC) ?: [];
        $totalClosed = (int) ($metrics['won_count'] ?? 0) + (int) ($metrics['lost_count'] ?? 0);
        $metrics['conversion_rate'] = $totalClosed > 0 ? round(((int) $metrics['won_count'] / $totalClosed) * 100, 1) : 0;

        $dueActivities = $pdo->query(
            'SELECT a.*, o.company_name, o.contact_name, u.name AS assigned_name
             FROM admin_crm_activities a
             INNER JOIN admin_crm_opportunities o ON o.id = a.opportunity_id
             LEFT JOIN users u ON u.id = a.assigned_user_id
             WHERE a.status = "pending"
             ORDER BY a.due_at IS NULL, a.due_at, a.id DESC
             LIMIT 12'
        )->fetchAll(PDO::FETCH_ASSOC);

        $selected = null;
        $notes = [];
        $activities = [];
        $opportunityId = (int) ($_GET['opportunity_id'] ?? 0);
        if ($opportunityId > 0) {
            $selected = $this->findOpportunity($opportunityId);
            if ($selected) {
                $notesStatement = $pdo->prepare(
                    'SELECT n.*, u.name AS user_name FROM admin_crm_notes n
                     LEFT JOIN users u ON u.id = n.user_id
                     WHERE n.opportunity_id = :id ORDER BY n.id DESC LIMIT 60'
                );
                $notesStatement->execute(['id' => $opportunityId]);
                $notes = $notesStatement->fetchAll(PDO::FETCH_ASSOC);

                $activityStatement = $pdo->prepare(
                    'SELECT a.*, u.name AS assigned_name FROM admin_crm_activities a
                     LEFT JOIN users u ON u.id = a.assigned_user_id
                     WHERE a.opportunity_id = :id
                     ORDER BY (a.status = "pending") DESC, a.due_at IS NULL, a.due_at, a.id DESC LIMIT 60'
                );
                $activityStatement->execute(['id' => $opportunityId]);
                $activities = $activityStatement->fetchAll(PDO::FETCH_ASSOC);
            }
        }

        View::render('crm.admin', [
            'title' => 'CRM comercial RS',
            'stages' => $stages,
            'team' => $team,
            'opportunities' => $opportunities,
            'metrics' => $metrics,
            'filters' => $filters,
            'selected' => $selected,
            'notes' => $notes,
            'activities' => $activities,
            'dueActivities' => $dueActivities,
        ]);
    }

    public function store(): void
    {
        $data = $this->opportunityInput();
        if ($data['company_name'] === '' || $data['contact_name'] === '' || $data['title'] === '' || $data['stage_id'] < 1) {
            Flash::set('error', 'Informe empresa, contato, oportunidade e etapa.');
            $this->redirect('/crm');
        }
        if ($data['email'] !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            Flash::set('error', 'Informe um e-mail válido.');
            $this->redirect('/crm');
        }
        if (!$this->stageExists($data['stage_id'])) {
            Flash::set('error', 'A etapa selecionada não existe.');
            $this->redirect('/crm');
        }

        $stage = $this->findStage($data['stage_id']);
        $status = (string) ($stage['stage_type'] ?? 'open');
        $statement = Database::connection()->prepare(
            'INSERT INTO admin_crm_opportunities
                (stage_id, owner_user_id, company_name, contact_name, email, phone, segment, source,
                 title, value, priority, status, expected_close_at, next_activity_at, created_by_user_id, closed_at)
             VALUES
                (:stage_id, :owner_id, :company_name, :contact_name, :email, :phone, :segment, :source,
                 :title, :value, :priority, :status, :expected_close_at, :next_activity_at, :created_by, :closed_at)'
        );
        $statement->execute([
            'stage_id' => $data['stage_id'],
            'owner_id' => $data['owner_user_id'] ?: null,
            'company_name' => $data['company_name'],
            'contact_name' => $data['contact_name'],
            'email' => $data['email'] ?: null,
            'phone' => $data['phone'] ?: null,
            'segment' => $data['segment'] ?: null,
            'source' => $data['source'] ?: null,
            'title' => $data['title'],
            'value' => $data['value'],
            'priority' => $data['priority'],
            'status' => $status,
            'expected_close_at' => $data['expected_close_at'] ?: null,
            'next_activity_at' => $data['next_activity_at'] ?: null,
            'created_by' => Auth::id(),
            'closed_at' => in_array($status, ['won', 'lost', 'active'], true) ? date('Y-m-d H:i:s') : null,
        ]);
        $id = (int) Database::connection()->lastInsertId();
        if ($data['next_activity_at']) {
            Database::connection()->prepare(
                'INSERT INTO admin_crm_activities
                 (opportunity_id, assigned_user_id, created_by_user_id, activity_type, title, due_at)
                 VALUES (:id, :assigned, :creator, "follow_up", :title, :due_at)'
            )->execute([
                'id' => $id,
                'assigned' => $data['owner_user_id'] ?: null,
                'creator' => Auth::id(),
                'title' => 'Realizar próximo contato comercial',
                'due_at' => $data['next_activity_at'],
            ]);
        }
        Audit::log('admin_crm.opportunity_created', ['opportunity_id' => $id, 'company' => $data['company_name']]);
        Flash::set('success', 'Oportunidade adicionada ao CRM comercial.');
        $this->redirect('/crm?opportunity_id=' . $id);
    }

    public function update(): void
    {
        $id = (int) ($_POST['opportunity_id'] ?? 0);
        $current = $this->findOpportunity($id);
        if (!$current) {
            Flash::set('error', 'Oportunidade não encontrada.');
            $this->redirect('/crm');
        }
        $data = $this->opportunityInput();
        if ($data['company_name'] === '' || $data['contact_name'] === '' || $data['title'] === '') {
            Flash::set('error', 'Preencha os dados principais da oportunidade.');
            $this->redirect('/crm?opportunity_id=' . $id);
        }
        $statement = Database::connection()->prepare(
            'UPDATE admin_crm_opportunities SET
                owner_user_id = :owner_id, company_name = :company_name, contact_name = :contact_name,
                email = :email, phone = :phone, segment = :segment, source = :source, title = :title,
                value = :value, priority = :priority, expected_close_at = :expected_close_at,
                next_activity_at = :next_activity_at, lost_reason = :lost_reason
             WHERE id = :id'
        );
        $statement->execute([
            'owner_id' => $data['owner_user_id'] ?: null,
            'company_name' => $data['company_name'],
            'contact_name' => $data['contact_name'],
            'email' => $data['email'] ?: null,
            'phone' => $data['phone'] ?: null,
            'segment' => $data['segment'] ?: null,
            'source' => $data['source'] ?: null,
            'title' => $data['title'],
            'value' => $data['value'],
            'priority' => $data['priority'],
            'expected_close_at' => $data['expected_close_at'] ?: null,
            'next_activity_at' => $data['next_activity_at'] ?: null,
            'lost_reason' => $data['lost_reason'] ?: null,
            'id' => $id,
        ]);
        Audit::log('admin_crm.opportunity_updated', ['opportunity_id' => $id]);
        Flash::set('success', 'Oportunidade atualizada.');
        $this->redirect('/crm?opportunity_id=' . $id);
    }

    public function move(): void
    {
        $id = (int) ($_POST['opportunity_id'] ?? 0);
        $stageId = (int) ($_POST['stage_id'] ?? 0);
        $opportunity = $this->findOpportunity($id);
        $stage = $this->findStage($stageId);
        if (!$opportunity || !$stage) {
            if ($this->wantsJson()) {
                $this->json(['ok' => false, 'message' => 'Não foi possível mover a oportunidade.'], 422);
            }
            Flash::set('error', 'Não foi possível mover a oportunidade.');
            $this->redirect('/crm');
        }
        $status = (string) $stage['stage_type'];
        $statement = Database::connection()->prepare(
            'UPDATE admin_crm_opportunities SET stage_id = :stage_id, status = :status,
             closed_at = :closed_at WHERE id = :id'
        );
        $statement->execute([
            'stage_id' => $stageId,
            'status' => $status,
            'closed_at' => in_array($status, ['won', 'lost', 'active'], true) ? date('Y-m-d H:i:s') : null,
            'id' => $id,
        ]);
        Audit::log('admin_crm.opportunity_moved', ['opportunity_id' => $id, 'stage' => $stage['stage_key']]);
        if ($this->wantsJson()) {
            $this->json([
                'ok' => true,
                'message' => 'Oportunidade movida para ' . $stage['name'] . '.',
                'item_id' => $id,
                'stage_id' => $stageId,
                'stage_name' => $stage['name'],
                'status' => $status,
            ]);
        }
        Flash::set('success', 'Oportunidade movida para ' . $stage['name'] . '.');
        $this->redirect('/crm?opportunity_id=' . $id);
    }

    public function addNote(): void
    {
        $id = (int) ($_POST['opportunity_id'] ?? 0);
        $note = trim((string) ($_POST['note'] ?? ''));
        if (!$this->findOpportunity($id) || $note === '') {
            Flash::set('error', 'Digite uma observação válida.');
            $this->redirect('/crm');
        }
        $statement = Database::connection()->prepare(
            'INSERT INTO admin_crm_notes (opportunity_id, user_id, note) VALUES (:id, :user_id, :note)'
        );
        $statement->execute(['id' => $id, 'user_id' => Auth::id(), 'note' => $note]);
        Audit::log('admin_crm.note_created', ['opportunity_id' => $id]);
        Flash::set('success', 'Observação registrada.');
        $this->redirect('/crm?opportunity_id=' . $id);
    }

    public function addActivity(): void
    {
        $id = (int) ($_POST['opportunity_id'] ?? 0);
        $title = trim((string) ($_POST['title'] ?? ''));
        $type = (string) ($_POST['activity_type'] ?? 'follow_up');
        $assigned = (int) ($_POST['assigned_user_id'] ?? 0);
        $dueAt = $this->dateTime((string) ($_POST['due_at'] ?? ''));
        if (!$this->findOpportunity($id) || $title === '') {
            Flash::set('error', 'Informe uma atividade válida.');
            $this->redirect('/crm');
        }
        if (!in_array($type, ['task', 'follow_up', 'call', 'meeting', 'demo', 'proposal'], true)) {
            $type = 'follow_up';
        }
        $statement = Database::connection()->prepare(
            'INSERT INTO admin_crm_activities
             (opportunity_id, assigned_user_id, created_by_user_id, activity_type, title, description, due_at)
             VALUES (:id, :assigned, :creator, :type, :title, :description, :due_at)'
        );
        $statement->execute([
            'id' => $id,
            'assigned' => $assigned ?: null,
            'creator' => Auth::id(),
            'type' => $type,
            'title' => $title,
            'description' => trim((string) ($_POST['description'] ?? '')) ?: null,
            'due_at' => $dueAt ?: null,
        ]);
        if ($dueAt) {
            Database::connection()->prepare('UPDATE admin_crm_opportunities SET next_activity_at = :due_at WHERE id = :id')
                ->execute(['due_at' => $dueAt, 'id' => $id]);
        }
        Audit::log('admin_crm.activity_created', ['opportunity_id' => $id, 'type' => $type]);
        Flash::set('success', 'Atividade criada.');
        $this->redirect('/crm?opportunity_id=' . $id);
    }

    public function activityStatus(): void
    {
        $activityId = (int) ($_POST['activity_id'] ?? 0);
        $status = (string) ($_POST['status'] ?? 'completed');
        if (!in_array($status, ['pending', 'completed', 'cancelled'], true)) {
            $status = 'completed';
        }
        $statement = Database::connection()->prepare(
            'SELECT opportunity_id FROM admin_crm_activities WHERE id = :id LIMIT 1'
        );
        $statement->execute(['id' => $activityId]);
        $opportunityId = (int) $statement->fetchColumn();
        if ($opportunityId < 1) {
            Flash::set('error', 'Atividade não encontrada.');
            $this->redirect('/crm');
        }
        Database::connection()->prepare(
            'UPDATE admin_crm_activities SET status = :status, completed_at = :completed_at WHERE id = :id'
        )->execute([
            'status' => $status,
            'completed_at' => $status === 'completed' ? date('Y-m-d H:i:s') : null,
            'id' => $activityId,
        ]);
        Audit::log('admin_crm.activity_status_updated', ['activity_id' => $activityId, 'status' => $status]);
        Flash::set('success', 'Atividade atualizada.');
        $this->redirect('/crm?opportunity_id=' . $opportunityId);
    }

    public function convert(): void
    {
        $id = (int) ($_POST['opportunity_id'] ?? 0);
        $opportunity = $this->findOpportunity($id);
        if (!$opportunity) {
            Flash::set('error', 'Oportunidade não encontrada.');
            $this->redirect('/crm');
        }
        if ((int) ($opportunity['tenant_id'] ?? 0) > 0) {
            Flash::set('warning', 'Esta oportunidade já está vinculada a uma empresa.');
            $this->redirect('/crm?opportunity_id=' . $id);
        }

        $ownerName = trim((string) ($_POST['owner_name'] ?? $opportunity['contact_name']));
        $ownerEmail = mb_strtolower(trim((string) ($_POST['owner_email'] ?? $opportunity['email'])));
        $password = (string) ($_POST['owner_password'] ?? '');
        $plan = (string) ($_POST['plan'] ?? 'starter');
        if ($ownerName === '' || !filter_var($ownerEmail, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
            Flash::set('error', 'Informe responsável, e-mail válido e senha com pelo menos 8 caracteres.');
            $this->redirect('/crm?opportunity_id=' . $id);
        }
        if (!in_array($plan, ['starter', 'pro', 'business', 'custom'], true)) {
            $plan = 'starter';
        }

        $pdo = Database::connection();
        try {
            $pdo->beginTransaction();
            $tenant = $pdo->prepare(
                'INSERT INTO tenants (name, legal_name, slug, email, phone, segment, plan, status, onboarding_step)
                 VALUES (:name, :legal_name, :slug, :email, :phone, :segment, :plan, "active", 1)'
            );
            $tenant->execute([
                'name' => $opportunity['company_name'],
                'legal_name' => $opportunity['company_name'],
                'slug' => $this->uniqueSlug((string) $opportunity['company_name']),
                'email' => $opportunity['email'] ?: null,
                'phone' => $opportunity['phone'] ?: null,
                'segment' => $opportunity['segment'] ?: null,
                'plan' => $plan,
            ]);
            $tenantId = (int) $pdo->lastInsertId();

            $pdo->prepare(
                'INSERT INTO users (tenant_id, name, email, password_hash, role, status)
                 VALUES (:tenant_id, :name, :email, :password, "client_admin", "active")'
            )->execute([
                'tenant_id' => $tenantId,
                'name' => $ownerName,
                'email' => $ownerEmail,
                'password' => password_hash($password, PASSWORD_DEFAULT),
            ]);

            $this->createInitialSubscription($pdo, $tenantId, $plan);
            $this->createDefaultPipeline($pdo, $tenantId);

            $activeStageId = (int) $pdo->query('SELECT id FROM admin_crm_stages WHERE stage_key = "active" LIMIT 1')->fetchColumn();
            $pdo->prepare(
                'UPDATE admin_crm_opportunities SET tenant_id = :tenant_id, stage_id = :stage_id,
                 status = "active", converted_at = NOW(), closed_at = NOW() WHERE id = :id'
            )->execute(['tenant_id' => $tenantId, 'stage_id' => $activeStageId, 'id' => $id]);
            $pdo->commit();

            Audit::log('admin_crm.opportunity_converted', ['opportunity_id' => $id, 'company_name' => $opportunity['company_name']], $tenantId);
            Flash::set('success', 'Oportunidade convertida em empresa e primeiro acesso criado.');
            $this->redirect('/companies/overview?id=' . $tenantId);
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Flash::set('error', 'Não foi possível converter. Confira se o e-mail já está em uso.');
            $this->redirect('/crm?opportunity_id=' . $id);
        }
    }

    private function opportunityInput(): array
    {
        $priority = (string) ($_POST['priority'] ?? 'medium');
        if (!in_array($priority, ['low', 'medium', 'high'], true)) {
            $priority = 'medium';
        }
        return [
            'company_name' => trim((string) ($_POST['company_name'] ?? '')),
            'contact_name' => trim((string) ($_POST['contact_name'] ?? '')),
            'email' => mb_strtolower(trim((string) ($_POST['email'] ?? ''))),
            'phone' => trim((string) ($_POST['phone'] ?? '')),
            'segment' => trim((string) ($_POST['segment'] ?? '')),
            'source' => trim((string) ($_POST['source'] ?? '')),
            'title' => trim((string) ($_POST['title'] ?? '')),
            'value' => $this->money((string) ($_POST['value'] ?? '0')),
            'priority' => $priority,
            'stage_id' => (int) ($_POST['stage_id'] ?? 0),
            'owner_user_id' => (int) ($_POST['owner_user_id'] ?? 0),
            'expected_close_at' => $this->date((string) ($_POST['expected_close_at'] ?? '')),
            'next_activity_at' => $this->dateTime((string) ($_POST['next_activity_at'] ?? '')),
            'lost_reason' => trim((string) ($_POST['lost_reason'] ?? '')),
        ];
    }

    private function findOpportunity(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }
        $statement = Database::connection()->prepare(
            'SELECT o.*, s.name AS stage_name, s.stage_key, s.stage_type, s.color_key,
                    u.name AS owner_name, t.name AS tenant_name
             FROM admin_crm_opportunities o
             INNER JOIN admin_crm_stages s ON s.id = o.stage_id
             LEFT JOIN users u ON u.id = o.owner_user_id
             LEFT JOIN tenants t ON t.id = o.tenant_id
             WHERE o.id = :id LIMIT 1'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function findStage(int $id): ?array
    {
        $statement = Database::connection()->prepare('SELECT * FROM admin_crm_stages WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function stageExists(int $id): bool
    {
        return $this->findStage($id) !== null;
    }

    private function money(string $value): float
    {
        $value = preg_replace('/[^0-9,.-]/', '', $value) ?? '0';
        if (str_contains($value, ',')) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        }
        return max(0, round((float) $value, 2));
    }

    private function date(string $value): ?string
    {
        $value = trim($value);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }

    private function dateTime(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $timestamp = strtotime($value);
        return $timestamp ? date('Y-m-d H:i:s', $timestamp) : null;
    }

    private function createInitialSubscription(PDO $pdo, int $tenantId, string $planKey): void
    {
        $statement = $pdo->prepare('SELECT id, monthly_price FROM saas_plans WHERE plan_key = :plan LIMIT 1');
        $statement->execute(['plan' => $planKey]);
        $plan = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$plan) {
            return;
        }
        $start = date('Y-m-d');
        $pdo->prepare(
            'INSERT INTO tenant_subscriptions
             (tenant_id, plan_id, billing_cycle, billing_status, starts_at, current_period_starts_at,
              current_period_ends_at, next_billing_at, amount, notes, created_by_user_id)
             VALUES (:tenant_id, :plan_id, "monthly", "active", :start, :start,
                     :ends, :next, :amount, :notes, :user_id)'
        )->execute([
            'tenant_id' => $tenantId,
            'plan_id' => $plan['id'],
            'start' => $start,
            'ends' => date('Y-m-d', strtotime('+1 month -1 day')),
            'next' => date('Y-m-d', strtotime('+1 month')),
            'amount' => $plan['monthly_price'] ?? 0,
            'notes' => 'Assinatura criada pela conversão do CRM comercial RS.',
            'user_id' => Auth::id(),
        ]);
    }

    private function createDefaultPipeline(PDO $pdo, int $tenantId): void
    {
        $pdo->prepare('INSERT INTO crm_pipelines (tenant_id, name, is_default) VALUES (:tenant_id, "Funil comercial", 1)')
            ->execute(['tenant_id' => $tenantId]);
        $pipelineId = (int) $pdo->lastInsertId();
        $rows = [
            ['Novo', 'open', 'blue', 1, 10], ['Qualificação', 'open', 'cyan', 2, 25],
            ['Proposta', 'open', 'violet', 3, 50], ['Negociação', 'open', 'amber', 4, 75],
            ['Ganho', 'won', 'green', 5, 100], ['Perdido', 'lost', 'slate', 6, 0],
        ];
        $statement = $pdo->prepare(
            'INSERT INTO crm_stages (tenant_id, pipeline_id, name, stage_type, color_key, position, probability)
             VALUES (:tenant_id, :pipeline_id, :name, :type, :color, :position, :probability)'
        );
        foreach ($rows as [$name, $type, $color, $position, $probability]) {
            $statement->execute([
                'tenant_id' => $tenantId,
                'pipeline_id' => $pipelineId,
                'name' => $name,
                'type' => $type,
                'color' => $color,
                'position' => $position,
                'probability' => $probability,
            ]);
        }
    }

    private function uniqueSlug(string $name): string
    {
        $base = mb_strtolower($name);
        $base = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $base) ?: $base;
        $base = trim(preg_replace('/[^a-z0-9]+/', '-', $base) ?: 'empresa', '-') ?: 'empresa';
        $slug = $base;
        $counter = 2;
        $pdo = Database::connection();
        while (true) {
            $statement = $pdo->prepare('SELECT COUNT(*) FROM tenants WHERE slug = :slug');
            $statement->execute(['slug' => $slug]);
            if ((int) $statement->fetchColumn() === 0) {
                return $slug;
            }
            $slug = $base . '-' . $counter++;
        }
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
