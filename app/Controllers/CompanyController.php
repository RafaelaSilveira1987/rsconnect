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

final class CompanyController
{
    public function index(): void
    {
        $pdo = Database::connection();
        $companies = $pdo->query(
            'SELECT t.*,
                    COUNT(DISTINCT u.id) AS users_count,
                    COUNT(DISTINCT i.id) AS instances_count,
                    COUNT(DISTINCT a.id) AS agents_count
             FROM tenants t
             LEFT JOIN users u ON u.tenant_id = t.id
             LEFT JOIN evolution_instances i ON i.tenant_id = t.id
             LEFT JOIN ai_agents a ON a.tenant_id = t.id
             GROUP BY t.id
             ORDER BY t.created_at DESC'
        )->fetchAll(PDO::FETCH_ASSOC);

        View::render('companies.index', [
            'title' => 'Empresas',
            'companies' => $companies,
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

        View::render('companies.settings', [
            'title' => 'Configurações da empresa',
            'company' => $company,
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

        $name = trim((string) ($_POST['name'] ?? ''));
        $legalName = trim((string) ($_POST['legal_name'] ?? ''));
        $document = trim((string) ($_POST['document'] ?? ''));
        $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $website = trim((string) ($_POST['website'] ?? ''));
        $segment = trim((string) ($_POST['segment'] ?? ''));

        if ($name === '' || ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL))) {
            Flash::set('error', 'Informe o nome da empresa e um e-mail válido.');
            $this->redirect('/company-settings' . (Auth::isSuperAdmin() ? '?id=' . $tenantId : ''));
        }

        if ($website !== '' && !filter_var($website, FILTER_VALIDATE_URL)) {
            Flash::set('error', 'Informe o site com endereço completo, incluindo https://.');
            $this->redirect('/company-settings' . (Auth::isSuperAdmin() ? '?id=' . $tenantId : ''));
        }

        $statement = Database::connection()->prepare(
            'UPDATE tenants
             SET name = :name, legal_name = :legal_name, document = :document, email = :email,
                 phone = :phone, website = :website, segment = :segment,
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
            'id' => $tenantId,
        ]);

        if (!Auth::isSuperAdmin() && Auth::tenantId() === $tenantId) {
            Auth::refreshUser();
        }

        Audit::log('company.updated', ['company_name' => $name], $tenantId);
        Flash::set('success', 'Dados da empresa atualizados.');
        $this->redirect('/company-settings' . (Auth::isSuperAdmin() ? '?id=' . $tenantId : ''));
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
        Flash::set('success', 'Plano e status da empresa atualizados.');
        $this->redirect('/companies');
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
