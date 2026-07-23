<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Flash;
use App\Core\Router;
use App\Core\View;
use App\Services\PrivacyService;
use PDO;

final class PrivacyController
{
    public function index(): void
    {
        $service = new PrivacyService();
        $tenantId = $this->tenantScope();
        $selectedTenantId = $this->requestedTenantId();

        View::render('privacy.index', [
            'title' => Auth::isSuperAdmin() ? 'Privacidade e LGPD' : 'Privacidade',
            'metrics' => $service->dashboard(Auth::isSuperAdmin() ? null : $tenantId),
            'requests' => $service->requests(Auth::isSuperAdmin() ? ($selectedTenantId ?: null) : $tenantId),
            'companies' => Auth::isSuperAdmin() ? $service->tenantsOverview() : [],
            'selectedTenantId' => $selectedTenantId,
            'settings' => $service->settings(Auth::isSuperAdmin() && $selectedTenantId ? $selectedTenantId : $tenantId),
            'acceptances' => Auth::isSuperAdmin() && $selectedTenantId ? $service->acceptances($selectedTenantId) : ($tenantId ? $service->acceptances($tenantId) : []),
            'contacts' => $tenantId ? $this->contacts($tenantId) : [],
        ]);
    }

    public function accept(): void
    {
        $tenantId = Auth::tenantId();
        $userId = Auth::id();
        if (!$tenantId || !$userId) {
            $this->redirect('/');
        }

        $service = new PrivacyService();
        View::render('privacy.accept', [
            'title' => 'Aceite LGPD',
            'settings' => $service->settings((int) $tenantId),
        ]);
    }

    public function acceptStore(): void
    {
        if (!Csrf::validate($_POST['_token'] ?? null)) {
            Flash::set('error', 'Sessão expirada. Atualize a página e tente novamente.');
            $this->redirect('/privacy/accept');
        }

        $tenantId = Auth::tenantId();
        $userId = Auth::id();
        if (!$tenantId || !$userId || empty($_POST['accept_terms'])) {
            Flash::set('error', 'É necessário confirmar o aceite para continuar.');
            $this->redirect('/privacy/accept');
        }

        (new PrivacyService())->acceptCurrentPolicy((int) $tenantId, (int) $userId);
        Audit::log('privacy.terms_accepted', ['user_id' => $userId], (int) $tenantId);
        Flash::set('success', 'Aceite registrado. Você já pode continuar usando o painel.');
        $this->redirect('/');
    }

    public function saveSettings(): void
    {
        if (!Csrf::validate($_POST['_token'] ?? null)) {
            Flash::set('error', 'Sessão expirada. Atualize a página e tente novamente.');
            $this->redirect('/privacy');
        }

        $tenantId = Auth::isSuperAdmin()
            ? (int) ($_POST['tenant_id'] ?? 0)
            : (int) Auth::tenantId();

        if ($tenantId < 1 || (!Auth::isSuperAdmin() && !Auth::can('privacy.manage'))) {
            Flash::set('error', 'Empresa inválida ou permissão insuficiente.');
            $this->redirect('/privacy');
        }

        (new PrivacyService())->saveSettings($tenantId, [
            'require_company_acceptance' => isset($_POST['require_company_acceptance']),
            'policy_version' => trim((string) ($_POST['policy_version'] ?? 'v1')),
            'privacy_policy_title' => trim((string) ($_POST['privacy_policy_title'] ?? '')),
            'privacy_policy_text' => trim((string) ($_POST['privacy_policy_text'] ?? '')),
            'terms_title' => trim((string) ($_POST['terms_title'] ?? '')),
            'terms_text' => trim((string) ($_POST['terms_text'] ?? '')),
            'dpo_name' => trim((string) ($_POST['dpo_name'] ?? '')),
            'dpo_email' => trim((string) ($_POST['dpo_email'] ?? '')),
            'retention_days' => (int) ($_POST['retention_days'] ?? 365),
            'allow_export_requests' => isset($_POST['allow_export_requests']),
            'allow_delete_requests' => isset($_POST['allow_delete_requests']),
        ]);

        Audit::log('privacy.settings_updated', ['tenant_id' => $tenantId], $tenantId);
        Flash::set('success', 'Configurações de privacidade atualizadas. Usuários precisarão aceitar novamente se o texto ou a versão mudou.');
        $this->redirect('/privacy' . (Auth::isSuperAdmin() ? '?tenant_id=' . $tenantId : ''));
    }

    public function createRequest(): void
    {
        if (!Csrf::validate($_POST['_token'] ?? null)) {
            Flash::set('error', 'Sessão expirada. Atualize a página e tente novamente.');
            $this->redirect('/privacy');
        }

        $tenantId = Auth::isSuperAdmin()
            ? (int) ($_POST['tenant_id'] ?? 0)
            : (int) Auth::tenantId();
        if ($tenantId < 1) {
            Flash::set('error', 'Empresa inválida.');
            $this->redirect('/privacy');
        }

        (new PrivacyService())->createRequest($tenantId, $_POST);
        Audit::log('privacy.request_created', ['type' => $_POST['request_type'] ?? 'export'], $tenantId);
        Flash::set('success', 'Solicitação LGPD registrada.');
        $this->redirect('/privacy' . (Auth::isSuperAdmin() ? '?tenant_id=' . $tenantId : ''));
    }

    public function updateRequest(): void
    {
        if (!Csrf::validate($_POST['_token'] ?? null)) {
            Flash::set('error', 'Sessão expirada. Atualize a página e tente novamente.');
            $this->redirect('/privacy');
        }

        $requestId = (int) ($_POST['request_id'] ?? 0);
        $status = (string) ($_POST['status'] ?? 'processing');
        $summary = trim((string) ($_POST['response_summary'] ?? ''));
        (new PrivacyService())->updateRequest($requestId, $status, $summary);
        Audit::log('privacy.request_updated', ['request_id' => $requestId, 'status' => $status], Auth::tenantId());
        Flash::set('success', 'Solicitação atualizada.');
        $this->redirect('/privacy' . (Auth::isSuperAdmin() && !empty($_POST['tenant_id']) ? '?tenant_id=' . (int) $_POST['tenant_id'] : ''));
    }

    public function exportContact(): void
    {
        $tenantId = Auth::isSuperAdmin()
            ? (int) ($_GET['tenant_id'] ?? 0)
            : (int) Auth::tenantId();
        $contactId = (int) ($_GET['contact_id'] ?? 0);
        if ($tenantId < 1 || $contactId < 1) {
            http_response_code(400);
            echo 'Dados inválidos.';
            return;
        }

        $data = (new PrivacyService())->exportContactData($tenantId, $contactId);
        if (!$data) {
            http_response_code(404);
            echo 'Contato não encontrado.';
            return;
        }

        Audit::log('privacy.contact_exported', ['contact_id' => $contactId], $tenantId);
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="rsconnect-dados-contato-' . $contactId . '.json"');
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function tenantScope(): int
    {
        if (Auth::isSuperAdmin()) {
            return $this->requestedTenantId();
        }
        return (int) Auth::tenantId();
    }

    private function requestedTenantId(): int
    {
        if (!Auth::isSuperAdmin()) {
            return (int) Auth::tenantId();
        }
        return max(0, (int) ($_GET['tenant_id'] ?? $_POST['tenant_id'] ?? 0));
    }

    /** @return array<int,array<string,mixed>> */
    private function contacts(int $tenantId): array
    {
        $statement = Database::connection()->prepare(
            'SELECT id, name, phone, email FROM contacts WHERE tenant_id = :tenant_id ORDER BY updated_at DESC LIMIT 250'
        );
        $statement->execute(['tenant_id' => $tenantId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function redirect(string $path): never
    {
        header('Location: ' . Router::url($path));
        exit;
    }
}
