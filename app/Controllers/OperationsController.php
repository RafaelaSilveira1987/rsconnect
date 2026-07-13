<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Csrf;
use App\Core\Flash;
use App\Core\Router;
use App\Core\View;
use App\Services\OperationsService;

final class OperationsController
{
    public function index(): void
    {
        $service = new OperationsService();
        View::render('operations.index', [
            'title' => 'Monitoramento',
            'data' => $service->dashboard(),
        ]);
    }

    public function runHealthChecks(): void
    {
        if (!Csrf::validate($_POST['_token'] ?? null)) {
            Flash::set('error', 'Sessão expirada. Atualize a página e tente novamente.');
            header('Location: ' . Router::url('/operations'));
            exit;
        }

        (new OperationsService())->runChecks();
        Flash::set('success', 'Verificações executadas. Confira o painel de monitoramento.');
        header('Location: ' . Router::url('/operations'));
        exit;
    }

    public function registerBackup(): void
    {
        if (!Csrf::validate($_POST['_token'] ?? null)) {
            Flash::set('error', 'Sessão expirada. Atualize a página e tente novamente.');
            header('Location: ' . Router::url('/operations'));
            exit;
        }

        $type = (string) ($_POST['backup_type'] ?? 'manual');
        $location = trim((string) ($_POST['location'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));

        (new OperationsService())->registerManualBackup($type, $location, $notes);
        Flash::set('success', 'Registro de backup salvo.');
        header('Location: ' . Router::url('/operations'));
        exit;
    }

    public function runBackupHook(): void
    {
        $token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
        $service = new OperationsService();

        if (!$service->validBackupToken($token)) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Token inválido.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $payload = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            $payload = [];
        }

        $result = $service->registerExternalBackup($payload);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
