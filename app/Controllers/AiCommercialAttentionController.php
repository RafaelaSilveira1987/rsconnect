<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Flash;
use App\Core\Router;
use App\Core\View;
use App\Services\AiCommercialAttentionService;
use Throwable;

final class AiCommercialAttentionController
{
    public function index(): void
    {
        $filter = trim((string) ($_GET['filter'] ?? 'active'));
        $search = trim((string) ($_GET['search'] ?? ''));
        $dashboard = (new AiCommercialAttentionService())->dashboard($filter, $search);

        View::render('ai_attention.index', [
            'title' => 'Clientes que precisam de atenção',
            'attentionDashboard' => $dashboard,
        ]);
    }

    public function save(): void
    {
        $tenantId = max(0, (int) ($_POST['tenant_id'] ?? 0));
        $returnFilter = trim((string) ($_POST['return_filter'] ?? 'active'));
        $returnSearch = trim((string) ($_POST['return_search'] ?? ''));
        try {
            (new AiCommercialAttentionService())->saveTracking($tenantId, $_POST, Auth::id());
            Audit::log('ai.commercial_attention.updated', [
                'status' => $_POST['status'] ?? 'open',
                'due_at' => $_POST['due_at'] ?? null,
                'note' => $_POST['note'] ?? null,
            ], $tenantId > 0 ? $tenantId : null);
            Flash::set('success', 'Acompanhamento atualizado.');
        } catch (Throwable $exception) {
            Flash::set('error', 'Não foi possível atualizar o acompanhamento: ' . $exception->getMessage());
        }

        $query = http_build_query(array_filter([
            'filter' => $returnFilter,
            'search' => $returnSearch,
        ], static fn (mixed $value): bool => $value !== null && $value !== ''));
        header('Location: ' . Router::url('/client-attention') . ($query !== '' ? '?' . $query : ''));
        exit;
    }
}
