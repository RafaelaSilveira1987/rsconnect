<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Env;
use App\Core\Flash;
use App\Core\Router;
use App\Core\View;
use App\Services\TenantHealthService;
use Throwable;

final class TenantHealthController
{
    public function index(): void
    {
        $tenantId = (int) ($_GET['tenant_id'] ?? $_GET['id'] ?? 0);
        if ($tenantId < 1) {
            Flash::set('error', 'Selecione uma empresa para abrir o diagnóstico.');
            $this->redirect('/companies');
        }

        $service = new TenantHealthService();
        $data = $service->dashboard($tenantId);
        if (empty($data['tenant'])) {
            Flash::set('error', 'Empresa não encontrada.');
            $this->redirect('/companies');
        }

        if (empty($data['snapshot'])) {
            try {
                $data = $service->runForTenant($tenantId, Auth::id(), 'automatic');
            } catch (Throwable $e) {
                Flash::set('error', 'Não foi possível executar a primeira verificação: ' . $e->getMessage());
                $data = $service->dashboard($tenantId);
            }
        }

        View::render('companies.health', [
            'title' => 'Saúde e diagnóstico do cliente',
            'healthData' => $data,
        ]);
    }

    public function run(): void
    {
        $tenantId = (int) ($_POST['tenant_id'] ?? 0);
        try {
            (new TenantHealthService())->runForTenant($tenantId, Auth::id(), 'manual');
            Audit::log('tenant.health.checked', ['tenant_id' => $tenantId], $tenantId);
            Flash::set('success', 'Diagnóstico atualizado. Os problemas normalizados foram resolvidos automaticamente.');
        } catch (Throwable $e) {
            Flash::set('error', 'Não foi possível concluir a verificação: ' . $e->getMessage());
        }
        $this->redirect('/companies/health?tenant_id=' . $tenantId);
    }

    public function incident(): void
    {
        $tenantId = (int) ($_POST['tenant_id'] ?? 0);
        $incidentId = (int) ($_POST['incident_id'] ?? 0);
        $action = (string) ($_POST['incident_action'] ?? '');
        $note = trim((string) ($_POST['note'] ?? ''));
        try {
            (new TenantHealthService())->updateIncident($incidentId, $tenantId, $action, $note, Auth::id());
            Audit::log('tenant.health.incident.' . $action, ['incident_id' => $incidentId, 'note' => $note], $tenantId);
            Flash::set('success', 'Acompanhamento do incidente atualizado.');
        } catch (Throwable $e) {
            Flash::set('error', 'Não foi possível atualizar o incidente: ' . $e->getMessage());
        }
        $this->redirect('/companies/health?tenant_id=' . $tenantId . '#incidents');
    }

    public function cron(): void
    {
        $token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
        $expected = trim((string) Env::get('TENANT_HEALTH_CRON_TOKEN', ''));
        if ($expected === '' || !hash_equals($expected, $token)) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'message' => 'Token inválido.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $result = (new TenantHealthService())->runAll(null, 'cron');
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'result' => $result], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function redirect(string $path): never
    {
        header('Location: ' . Router::url($path));
        exit;
    }
}
