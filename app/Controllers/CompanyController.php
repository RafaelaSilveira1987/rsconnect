<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Flash;
use App\Core\Router;
use App\Core\View;
use App\Services\AdminDashboardService;
use App\Services\PreSchedulingService;
use App\Services\TenantModuleService;
use PDO;
use Throwable;

final class CompanyController
{
    public function index(): void
    {
        $data = (new AdminDashboardService())->companies([
            'q' => (string) ($_GET['q'] ?? ''),
            'status' => (string) ($_GET['status'] ?? ''),
            'plan' => (string) ($_GET['plan'] ?? ''),
            'health' => (string) ($_GET['health'] ?? ''),
            'tracking' => (string) ($_GET['tracking'] ?? ''),
        ]);

        View::render('companies.index', [
            'title' => 'Empresas',
            'companies' => $data['companies'],
            'summary' => $data['summary'],
            'filters' => $data['filters'],
            'dataWarnings' => $data['data_warnings'] ?? [],
        ]);
    }

    public function overview(): void
    {
        $tenantId = (int) ($_GET['id'] ?? 0);
        $company = (new AdminDashboardService())->companyOverview($tenantId);
        if (!$company) {
            Flash::set('error', 'Empresa não encontrada.');
            $this->redirect('/companies');
        }

        View::render('companies.overview', [
            'title' => 'Visão geral da empresa',
            'company' => $company,
        ]);
    }

    public function store(): void
    {
        $name = trim((string) ($_POST['name'] ?? ''));
        $legalName = trim((string) ($_POST['legal_name'] ?? ''));
        $document = trim((string) ($_POST['document'] ?? ''));
        $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $segment = trim((string) ($_POST['segment'] ?? ''));
        $plan = (string) ($_POST['plan'] ?? 'starter');
        $ownerName = trim((string) ($_POST['owner_name'] ?? ''));
        $ownerEmail = mb_strtolower(trim((string) ($_POST['owner_email'] ?? '')));
        $ownerPassword = (string) ($_POST['owner_password'] ?? '');

        if ($name === '' || $ownerName === '' || !filter_var($ownerEmail, FILTER_VALIDATE_EMAIL) || strlen($ownerPassword) < 8) {
            Flash::set('error', 'Informe empresa, responsável, e-mail válido e uma senha com pelo menos 8 caracteres.');
            $this->redirect('/companies');
        }

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Flash::set('error', 'O e-mail comercial da empresa é inválido.');
            $this->redirect('/companies');
        }

        if (!in_array($plan, ['starter', 'pro', 'business', 'custom'], true)) {
            $plan = 'starter';
        }

        $pdo = Database::connection();
        try {
            $pdo->beginTransaction();
            $slug = $this->uniqueSlug($name);

            $tenant = $pdo->prepare(
                'INSERT INTO tenants
                    (name, legal_name, slug, document, email, phone, segment, plan, status, onboarding_step)
                 VALUES
                    (:name, :legal_name, :slug, :document, :email, :phone, :segment, :plan, "active", 1)'
            );
            $tenant->execute([
                'name' => $name,
                'legal_name' => $legalName !== '' ? $legalName : null,
                'slug' => $slug,
                'document' => $document !== '' ? $document : null,
                'email' => $email !== '' ? $email : null,
                'phone' => $phone !== '' ? $phone : null,
                'segment' => $segment !== '' ? $segment : null,
                'plan' => $plan,
            ]);
            $tenantId = (int) $pdo->lastInsertId();

            $user = $pdo->prepare(
                'INSERT INTO users (tenant_id, name, email, password_hash, role, status)
                 VALUES (:tenant_id, :name, :email, :password_hash, "client_admin", "active")'
            );
            $user->execute([
                'tenant_id' => $tenantId,
                'name' => $ownerName,
                'email' => $ownerEmail,
                'password_hash' => password_hash($ownerPassword, PASSWORD_DEFAULT),
            ]);

            $this->createDefaultPipeline($pdo, $tenantId);

            $pdo->commit();
            Audit::log('company.created', ['company_name' => $name, 'owner_email' => $ownerEmail], $tenantId);
            Flash::set('success', 'Empresa e administrador do cliente cadastrados.');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Flash::set('error', 'Não foi possível cadastrar. Confira se o e-mail já está em uso.');
        }

        $this->redirect('/companies');
    }

    public function settings(): void
    {
        $tenantId = $this->requestedTenantId();
        $statement = Database::connection()->prepare('SELECT * FROM tenants WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $tenantId]);
        $company = $statement->fetch(PDO::FETCH_ASSOC);

        if (!$company) {
            Flash::set('error', 'Empresa não encontrada.');
            $this->redirect(Auth::isSuperAdmin() ? '/companies' : '/');
        }

        $preSchedulingService = new PreSchedulingService();
        $moduleService = new TenantModuleService();

        View::render('companies.settings', [
            'title' => 'Configurações da empresa',
            'company' => $company,
            'preScheduleSettings' => $preSchedulingService->settings($tenantId),
            'availableModules' => TenantModuleService::modules(),
            'moduleSettings' => $moduleService->settingsForTenant($tenantId),
        ]);
    }

    public function updateSettings(): void
    {
        $tenantId = Auth::isSuperAdmin()
            ? (int) ($_POST['tenant_id'] ?? 0)
            : (int) Auth::tenantId();

        if ($tenantId < 1) {
            Flash::set('error', 'Empresa inválida.');
            $this->redirect('/');
        }

        $pdo = Database::connection();
        $currentStatement = $pdo->prepare('SELECT * FROM tenants WHERE id = :id LIMIT 1');
        $currentStatement->execute(['id' => $tenantId]);
        $current = $currentStatement->fetch(PDO::FETCH_ASSOC);
        if (!$current) {
            Flash::set('error', 'Empresa não encontrada.');
            $this->redirect(Auth::isSuperAdmin() ? '/companies' : '/');
        }

        $posted = static function (string $key, array $current, bool $lowercase = false): string {
            $value = array_key_exists($key, $_POST)
                ? trim((string) $_POST[$key])
                : trim((string) ($current[$key] ?? ''));
            return $lowercase ? mb_strtolower($value) : $value;
        };

        $name = $posted('name', $current);
        $legalName = $posted('legal_name', $current);
        $document = $posted('document', $current);
        $email = $posted('email', $current, true);
        $phone = $posted('phone', $current);
        $website = $posted('website', $current);
        $segment = $posted('segment', $current);
        $commercialWhatsapp = $posted('commercial_whatsapp', $current);
        $instagram = $posted('instagram', $current);
        $postalCode = $posted('postal_code', $current);
        $addressLine = $posted('address_line', $current);
        $addressNumber = $posted('address_number', $current);
        $addressComplement = $posted('address_complement', $current);
        $district = $posted('district', $current);
        $city = $posted('city', $current);
        $state = $posted('state', $current);
        $companyAbout = $posted('company_about', $current);
        $companyServices = $posted('company_services', $current);
        $companyDifferentials = $posted('company_differentials', $current);
        $companyBusinessHours = $posted('company_business_hours', $current);
        $companyNotes = $posted('company_notes', $current);

        if ($name === '' || ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL))) {
            Flash::set('error', 'Informe o nome da empresa e um e-mail válido.');
            $this->redirect('/company-settings' . (Auth::isSuperAdmin() ? '?id=' . $tenantId : ''));
        }

        if ($website !== '' && !filter_var($website, FILTER_VALIDATE_URL)) {
            Flash::set('error', 'Informe o site com endereço completo, incluindo https://.');
            $this->redirect('/company-settings' . (Auth::isSuperAdmin() ? '?id=' . $tenantId : ''));
        }

        $statement = $pdo->prepare(
            'UPDATE tenants
             SET name = :name, legal_name = :legal_name, document = :document, email = :email,
                 phone = :phone, website = :website, segment = :segment,
                 commercial_whatsapp = :commercial_whatsapp, instagram = :instagram,
                 postal_code = :postal_code, address_line = :address_line, address_number = :address_number,
                 address_complement = :address_complement, district = :district, city = :city, state = :state,
                 company_about = :company_about, company_services = :company_services,
                 company_differentials = :company_differentials,
                 company_business_hours = :company_business_hours, company_notes = :company_notes,
                 onboarding_step = GREATEST(onboarding_step, 2)
             WHERE id = :id'
        );
        $statement->execute([
            'name' => $name,
            'legal_name' => $legalName !== '' ? $legalName : null,
            'document' => $document !== '' ? $document : null,
            'email' => $email !== '' ? $email : null,
            'phone' => $phone !== '' ? $phone : null,
            'website' => $website !== '' ? $website : null,
            'segment' => $segment !== '' ? $segment : null,
            'commercial_whatsapp' => $commercialWhatsapp !== '' ? $commercialWhatsapp : null,
            'instagram' => $instagram !== '' ? $instagram : null,
            'postal_code' => $postalCode !== '' ? $postalCode : null,
            'address_line' => $addressLine !== '' ? $addressLine : null,
            'address_number' => $addressNumber !== '' ? $addressNumber : null,
            'address_complement' => $addressComplement !== '' ? $addressComplement : null,
            'district' => $district !== '' ? $district : null,
            'city' => $city !== '' ? $city : null,
            'state' => $state !== '' ? $state : null,
            'company_about' => $companyAbout !== '' ? $companyAbout : null,
            'company_services' => $companyServices !== '' ? $companyServices : null,
            'company_differentials' => $companyDifferentials !== '' ? $companyDifferentials : null,
            'company_business_hours' => $companyBusinessHours !== '' ? $companyBusinessHours : null,
            'company_notes' => $companyNotes !== '' ? $companyNotes : null,
            'id' => $tenantId,
        ]);

        (new PreSchedulingService())->saveSettings($tenantId, [
            'enabled' => isset($_POST['pre_schedule_enabled']),
            'require_human_approval' => isset($_POST['pre_schedule_require_human_approval']),
            'ai_can_suggest_slots' => isset($_POST['pre_schedule_ai_can_suggest_slots']),
            'ai_can_confirm' => isset($_POST['pre_schedule_ai_can_confirm']),
            'send_approval_message' => isset($_POST['pre_schedule_send_approval_message']),
            'default_duration_minutes' => (int) ($_POST['pre_schedule_default_duration_minutes'] ?? 50),
            'default_message' => trim((string) ($_POST['pre_schedule_default_message'] ?? '')),
            'collect_message' => trim((string) ($_POST['pre_schedule_collect_message'] ?? '')),
            'approved_message' => trim((string) ($_POST['pre_schedule_approved_message'] ?? '')),
            'rejected_message' => trim((string) ($_POST['pre_schedule_rejected_message'] ?? '')),
            'reschedule_message' => trim((string) ($_POST['pre_schedule_reschedule_message'] ?? '')),
        ]);

        (new TenantModuleService())->saveSettings(
            $tenantId,
            array_values(array_filter(array_map('strval', (array) ($_POST['module_visible'] ?? [])))),
            array_values(array_filter(array_map('strval', (array) ($_POST['module_enabled'] ?? []))))
        );

        if (!Auth::isSuperAdmin() && Auth::tenantId() === $tenantId) {
            Auth::refreshUser();
        }

        Audit::log('company.updated', ['company_name' => $name, 'profile_enriched' => !Auth::isSuperAdmin()], $tenantId);
        Flash::set('success', 'Dados da empresa atualizados. As novas informações já podem ser usadas pelos assistentes.');
        $this->redirect('/company-settings' . (Auth::isSuperAdmin() ? '?id=' . $tenantId : ''));
    }

    public function updateTracking(): void
    {
        $tenantId = (int) ($_POST['tenant_id'] ?? 0);
        $trackingStatus = trim((string) ($_POST['tracking_status'] ?? 'automatic'));
        $priority = trim((string) ($_POST['priority'] ?? 'attention'));
        $note = trim((string) ($_POST['note'] ?? ''));

        if ($tenantId < 1
            || !in_array($trackingStatus, ['automatic', 'attention', 'reviewed', 'resolved'], true)
            || !in_array($priority, ['attention', 'critical', 'implantation'], true)) {
            Flash::set('error', 'Não foi possível atualizar o acompanhamento da empresa.');
            $this->redirect('/companies');
        }

        $pdo = Database::connection();
        $company = $pdo->prepare('SELECT id, name FROM tenants WHERE id = :id LIMIT 1');
        $company->execute(['id' => $tenantId]);
        $tenant = $company->fetch(PDO::FETCH_ASSOC);
        if (!$tenant) {
            Flash::set('error', 'Empresa não encontrada.');
            $this->redirect('/companies');
        }

        try {
            $acknowledgedAt = in_array($trackingStatus, ['reviewed', 'resolved'], true) ? date('Y-m-d H:i:s') : null;
            $resolvedAt = $trackingStatus === 'resolved' ? date('Y-m-d H:i:s') : null;
            if ($trackingStatus === 'automatic') {
                $note = '';
                $acknowledgedAt = null;
                $resolvedAt = null;
            }

            $statement = $pdo->prepare(
                'INSERT INTO tenant_admin_tracking
                    (tenant_id, tracking_status, priority, note, acknowledged_at, resolved_at, updated_by)
                 VALUES
                    (:tenant_id, :tracking_status, :priority, :note, :acknowledged_at, :resolved_at, :updated_by)
                 ON DUPLICATE KEY UPDATE
                    tracking_status = VALUES(tracking_status),
                    priority = VALUES(priority),
                    note = VALUES(note),
                    acknowledged_at = VALUES(acknowledged_at),
                    resolved_at = VALUES(resolved_at),
                    updated_by = VALUES(updated_by)'
            );
            $statement->execute([
                'tenant_id' => $tenantId,
                'tracking_status' => $trackingStatus,
                'priority' => $priority,
                'note' => $note !== '' ? $note : null,
                'acknowledged_at' => $acknowledgedAt,
                'resolved_at' => $resolvedAt,
                'updated_by' => Auth::id(),
            ]);

            $action = match ($trackingStatus) {
                'attention' => 'company.attention_marked',
                'reviewed' => 'company.attention_reviewed',
                'resolved' => 'company.attention_resolved',
                default => 'company.attention_reset',
            };
            Audit::log($action, [
                'company_name' => (string) ($tenant['name'] ?? ''),
                'tracking_status' => $trackingStatus,
                'priority' => $priority,
                'note' => $note,
            ], $tenantId);

            $message = match ($trackingStatus) {
                'attention' => 'Empresa marcada para atenção.',
                'reviewed' => 'Pendência marcada como visualizada e em acompanhamento.',
                'resolved' => 'Pendência marcada como corrigida. Falhas antigas foram reconhecidas.',
                default => 'Classificação automática restaurada.',
            };
            Flash::set('success', $message);
        } catch (Throwable $exception) {
            Flash::set('error', 'Não foi possível salvar o acompanhamento. Execute a migration 035 e tente novamente.');
        }

        $returnTo = trim((string) ($_POST['return_to'] ?? '/companies'));
        if (!str_starts_with($returnTo, '/companies')) {
            $returnTo = '/companies';
        }
        $this->redirect($returnTo);
    }

    public function updateStatus(): void
    {
        $tenantId = (int) ($_POST['tenant_id'] ?? 0);
        $status = (string) ($_POST['status'] ?? 'active');
        $plan = (string) ($_POST['plan'] ?? 'starter');

        if ($tenantId < 1 || !in_array($status, ['active', 'inactive', 'suspended'], true)
            || !in_array($plan, ['starter', 'pro', 'business', 'custom'], true)) {
            Flash::set('error', 'Dados de atualização inválidos.');
            $this->redirect('/companies');
        }

        $statement = Database::connection()->prepare(
            'UPDATE tenants SET status = :status, plan = :plan WHERE id = :id'
        );
        $statement->execute(['status' => $status, 'plan' => $plan, 'id' => $tenantId]);
        Audit::log('company.status_updated', ['status' => $status, 'plan' => $plan], $tenantId);
        $statusMessage = match ($status) {
            'inactive' => 'Empresa inativada. Os usuários do cliente não poderão entrar enquanto ela estiver inativa.',
            'suspended' => 'Empresa suspensa. Revise cobrança e acesso antes de reativar.',
            default => 'Empresa ativada e plano atualizado.',
        };
        Flash::set('success', $statusMessage);
        $returnTo = trim((string) ($_POST['return_to'] ?? '/companies'));
        if (!str_starts_with($returnTo, '/companies')) {
            $returnTo = '/companies';
        }
        $this->redirect($returnTo);
    }


    private function createDefaultPipeline(PDO $pdo, int $tenantId): void
    {
        $pipeline = $pdo->prepare(
            'INSERT INTO crm_pipelines (tenant_id, name, is_default)
             VALUES (:tenant_id, "Funil comercial", 1)'
        );
        $pipeline->execute(['tenant_id' => $tenantId]);
        $pipelineId = (int) $pdo->lastInsertId();

        $stages = [
            ['Novo', 'open', 'blue', 1, 10],
            ['Qualificação', 'open', 'cyan', 2, 25],
            ['Proposta', 'open', 'violet', 3, 50],
            ['Negociação', 'open', 'amber', 4, 75],
            ['Ganho', 'won', 'green', 5, 100],
            ['Perdido', 'lost', 'slate', 6, 0],
        ];
        $statement = $pdo->prepare(
            'INSERT INTO crm_stages
                (tenant_id, pipeline_id, name, stage_type, color_key, position, probability)
             VALUES
                (:tenant_id, :pipeline_id, :name, :stage_type, :color_key, :position, :probability)'
        );
        foreach ($stages as [$name, $type, $color, $position, $probability]) {
            $statement->execute([
                'tenant_id' => $tenantId,
                'pipeline_id' => $pipelineId,
                'name' => $name,
                'stage_type' => $type,
                'color_key' => $color,
                'position' => $position,
                'probability' => $probability,
            ]);
        }
    }

    private function requestedTenantId(): int
    {
        if (!Auth::isSuperAdmin()) {
            return (int) Auth::tenantId();
        }

        $tenantId = (int) ($_GET['id'] ?? 0);
        if ($tenantId < 1) {
            Flash::set('warning', 'Selecione uma empresa para editar.');
            $this->redirect('/companies');
        }
        return $tenantId;
    }

    private function uniqueSlug(string $name): string
    {
        $base = mb_strtolower($name);
        $base = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $base) ?: $base;
        $base = preg_replace('/[^a-z0-9]+/', '-', $base) ?: 'empresa';
        $base = trim($base, '-') ?: 'empresa';
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

    private function redirect(string $path): never
    {
        header('Location: ' . Router::url($path));
        exit;
    }
}
