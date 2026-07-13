<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\Router;
use App\Core\View;
use App\Services\ImplementationChecklistService;

final class ImplementationController
{
    public function index(): void
    {
        $service = new ImplementationChecklistService();
        $tenantId = (int) ($_GET['tenant_id'] ?? 0);

        if ($tenantId > 0) {
            $detail = $service->tenantDetail($tenantId);
            if (!$detail['tenant']) {
                Flash::set('error', 'Empresa não encontrada.');
                header('Location: ' . Router::url('/implementation'));
                exit;
            }

            View::render('implementation.show', [
                'title' => 'Checklist de implantação',
                'detail' => $detail,
            ]);
            return;
        }

        View::render('implementation.index', [
            'title' => 'Implantação',
            'dashboard' => $service->dashboard(),
        ]);
    }

    public function refresh(): void
    {
        if (!Csrf::validate($_POST['_token'] ?? null)) {
            Flash::set('error', 'Sessão expirada. Atualize a página e tente novamente.');
            header('Location: ' . Router::url('/implementation'));
            exit;
        }

        $tenantId = (int) ($_POST['tenant_id'] ?? 0);
        $service = new ImplementationChecklistService();
        if ($tenantId > 0) {
            $service->refreshStatus($tenantId, Auth::id());
            Flash::set('success', 'Checklist da empresa recalculado.');
            header('Location: ' . Router::url('/implementation?tenant_id=' . $tenantId));
            exit;
        }

        foreach (($service->dashboard()['tenants'] ?? []) as $tenant) {
            $service->refreshStatus((int) $tenant['id'], Auth::id());
        }
        Flash::set('success', 'Checklist de todas as empresas recalculado.');
        header('Location: ' . Router::url('/implementation'));
        exit;
    }

    public function updateItem(): void
    {
        if (!Csrf::validate($_POST['_token'] ?? null)) {
            Flash::set('error', 'Sessão expirada. Atualize a página e tente novamente.');
            header('Location: ' . Router::url('/implementation'));
            exit;
        }

        $tenantId = (int) ($_POST['tenant_id'] ?? 0);
        $itemKey = trim((string) ($_POST['item_key'] ?? ''));
        $status = trim((string) ($_POST['manual_status'] ?? 'auto'));
        $notes = trim((string) ($_POST['notes'] ?? ''));

        if ($tenantId <= 0 || $itemKey === '') {
            Flash::set('error', 'Item inválido.');
            header('Location: ' . Router::url('/implementation'));
            exit;
        }

        (new ImplementationChecklistService())->updateItem($tenantId, $itemKey, $status, $notes, Auth::id());
        Flash::set('success', 'Item atualizado.');
        header('Location: ' . Router::url('/implementation?tenant_id=' . $tenantId));
        exit;
    }
}
