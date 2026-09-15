<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Flash;
use App\Core\Router;
use App\Core\View;
use App\Services\OperationalLoadService;
use PDO;

final class OperationalLoadController
{
    public function index(): void
    {
        $tenantId = $this->tenantId();
        if ($tenantId < 1 && !Auth::isSuperAdmin()) {
            Flash::set('error', 'Sua conta não está vinculada a uma empresa ativa.');
            header('Location: ' . Router::url('/'));
            exit;
        }

        $filters = $this->filters($tenantId);
        $data = $tenantId > 0 ? (new OperationalLoadService())->build($tenantId, $filters) : $this->emptyData();

        View::render('operational_load.index', [
            'title' => 'Carga operacional',
            'filters' => $filters,
            'data' => $data,
            'tenants' => Auth::isSuperAdmin() ? $this->tenants() : [],
        ]);
    }

    public function snapshot(): void
    {
        $tenantId = $this->tenantId();
        $filters = $this->filters($tenantId);
        $data = $tenantId > 0 ? (new OperationalLoadService())->build($tenantId, $filters) : $this->emptyData();
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        echo json_encode([
            'ok' => true,
            'fingerprint' => (string) ($data['fingerprint'] ?? ''),
            'generated_at' => (string) ($data['generated_at'] ?? ''),
            'summary' => $data['summary'] ?? [],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function tenantId(): int
    {
        return Auth::isSuperAdmin()
            ? max(0, (int) ($_GET['tenant_id'] ?? 0))
            : max(0, (int) (Auth::tenantId() ?? 0));
    }

    private function filters(int $tenantId): array
    {
        return [
            'tenant_id' => $tenantId,
            'instance_id' => max(0, (int) ($_GET['instance_id'] ?? 0)),
            'assigned_user_id' => trim((string) ($_GET['assigned_user_id'] ?? '')),
            'status' => trim((string) ($_GET['status'] ?? '')),
            'mode' => trim((string) ($_GET['mode'] ?? '')),
            'sla' => trim((string) ($_GET['sla'] ?? '')),
        ];
    }

    private function tenants(): array
    {
        $rows = Database::connection()->query('SELECT id, name FROM tenants WHERE status <> "inactive" ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
        return $rows ?: [];
    }

    private function emptyData(): array
    {
        return [
            'summary' => ['active_total' => 0, 'unassigned' => 0, 'human_active' => 0, 'awaiting_first_response' => 0, 'sla_warning' => 0, 'sla_breached' => 0],
            'team' => [], 'conversations' => [], 'instances' => [], 'users' => [], 'tenant_live' => false,
            'generated_at' => gmdate('Y-m-d H:i:s'), 'fingerprint' => '',
        ];
    }
}
