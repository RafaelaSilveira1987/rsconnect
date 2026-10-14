<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\View;
use App\Services\ExpressBusinessConfigurationReader;
use Throwable;

/** Lê as regras persistidas sem alterar configuração operacional. */
final class ExpressSetupController
{
    public function index(): void
    {
        $this->renderSetup('agent_express/index');
    }

    public function businessDashboard(): void
    {
        $this->renderSetup('agents/business');
    }

    /** GET /api/v1/agents/business-config — leitura para conferir cada gravação. */
    public function savedConfiguration(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-store, max-age=0');
        $tenantId = (int) (Auth::tenantId() ?? 0);
        if ($tenantId < 1 || Auth::isSuperAdmin() || (!Auth::can('agents.view') && !Auth::can('agents.manage') && !Auth::can('onboarding.manage'))) {
            http_response_code(403);
            echo json_encode(['ok'=>false,'message'=>'Acesso não autorizado.']);
            return;
        }
        try {
            $loaded = (new ExpressBusinessConfigurationReader())->load(Database::connection(), $tenantId);
            echo json_encode(['ok'=>true,'payload'=>$loaded['payload'],'has_saved'=>$loaded['has_saved']],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (Throwable $e) {
            error_log('[RS Connect][BusinessConfig] ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['ok'=>false,'message'=>'Não foi possível conferir a configuração salva.']);
        }
    }

    private function renderSetup(string $view): void
    {
        $tenantId = (int) (Auth::tenantId() ?? 0);
        if ($tenantId < 1 || Auth::isSuperAdmin()) {
            http_response_code(403);
            View::render('errors.404', ['title'=>'Acesso indisponível']);
            return;
        }
        $loaded = (new ExpressBusinessConfigurationReader())->load(Database::connection(), $tenantId);
        View::render($view, [
            'title'=>$view === 'agents/business' ? 'Meu atendimento' : 'Preparar meu atendimento',
            'expressAssets'=>$view === 'agent_express/index',
            'businessDashboardAssets'=>$view === 'agents/business',
            'setupPayload'=>$loaded['payload'],
            'setupAgents'=>$loaded['agents'],
            'setupHasSaved'=>$loaded['has_saved'],
            'setupCanManage'=>Auth::can('agents.manage'),
            'setupCanView'=>Auth::can('agents.view'),
        ]);
    }
}
