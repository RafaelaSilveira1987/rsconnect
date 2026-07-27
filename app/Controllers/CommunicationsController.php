<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\Router;
use App\Core\View;
use App\Services\ClientCommunicationService;
use Throwable;

final class CommunicationsController
{
    public function index(): void
    {
        View::render('communications.index', [
            'title' => 'Comunicados',
            'data' => (new ClientCommunicationService())->dashboard(),
            'prefillTenant' => (int) ($_GET['tenant_id'] ?? 0),
            'prefillIncident' => (int) ($_GET['incident_id'] ?? 0),
            'prefillType' => (string) ($_GET['type'] ?? ''),
        ]);
    }

    public function send(): void
    {
        if (!Csrf::validate($_POST['_token'] ?? null)) {
            $this->go('error', 'Sessão expirada.');
        }
        try {
            (new ClientCommunicationService())->send($_POST);
            $this->go('success', 'Comunicado enviado pela Central de comunicação.');
        } catch (Throwable $exception) {
            $this->go('error', $exception->getMessage());
        }
    }

    public function inbox(): void
    {
        $tenantId = (int) (Auth::tenantId() ?? 0);
        if ($tenantId < 1) {
            $this->json(['ok' => false, 'message' => 'Empresa não identificada.'], 403);
        }
        $service = new ClientCommunicationService();
        $payload = $service->inbox($tenantId, 30);
        $selectedId = (int) ($_GET['communication_id'] ?? 0);
        $thread = $selectedId > 0 ? $service->thread($tenantId, $selectedId) : null;
        $this->json(['ok' => true] + $payload + ['thread' => $thread]);
    }

    public function read(): void
    {
        $this->tenantAction(static function (ClientCommunicationService $service, int $tenantId, int $communicationId): void {
            $service->markRead($tenantId, $communicationId, Auth::id());
        });
    }

    public function acknowledge(): void
    {
        $this->tenantAction(static function (ClientCommunicationService $service, int $tenantId, int $communicationId): void {
            $service->acknowledge($tenantId, $communicationId, Auth::id());
        });
    }

    public function respond(): void
    {
        $tenantId = (int) (Auth::tenantId() ?? 0);
        $communicationId = (int) ($_POST['communication_id'] ?? 0);
        if ($tenantId < 1 || $communicationId < 1) {
            $this->json(['ok' => false, 'message' => 'Comunicado inválido.'], 422);
        }
        try {
            (new ClientCommunicationService())->tenantReply(
                $tenantId,
                $communicationId,
                Auth::id(),
                (string) ($_POST['message'] ?? '')
            );
            $this->json([
                'ok' => true,
                'message' => 'Resposta enviada para a equipe RS.',
                'thread' => (new ClientCommunicationService())->thread($tenantId, $communicationId),
            ]);
        } catch (Throwable $exception) {
            $this->json(['ok' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function adminReply(): void
    {
        if (!Csrf::validate($_POST['_token'] ?? null)) {
            $this->go('error', 'Sessão expirada.');
        }
        try {
            (new ClientCommunicationService())->adminReply(
                (int) ($_POST['communication_id'] ?? 0),
                (int) ($_POST['tenant_id'] ?? 0),
                Auth::id(),
                (string) ($_POST['message'] ?? '')
            );
            $this->go('success', 'Resposta enviada para a empresa.');
        } catch (Throwable $exception) {
            $this->go('error', $exception->getMessage());
        }
    }

    private function tenantAction(callable $action): void
    {
        $tenantId = (int) (Auth::tenantId() ?? 0);
        $communicationId = (int) ($_POST['communication_id'] ?? 0);
        if ($tenantId < 1 || $communicationId < 1) {
            $this->json(['ok' => false, 'message' => 'Comunicado inválido.'], 422);
        }
        try {
            $service = new ClientCommunicationService();
            $action($service, $tenantId, $communicationId);
            $this->json([
                'ok' => true,
                'inbox' => $service->inbox($tenantId, 30),
                'thread' => $service->thread($tenantId, $communicationId),
            ]);
        } catch (Throwable $exception) {
            $this->json(['ok' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    /** @param array<string,mixed> $payload */
    private function json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    private function go(string $type, string $message): never
    {
        Flash::set($type, $message);
        header('Location: ' . Router::url('/comunicados'));
        exit;
    }
}
