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
        $wantsJson = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
            || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';

        if (!Csrf::validate($_POST['_token'] ?? null)) {
            if ($wantsJson) {
                http_response_code(419);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'ok' => false,
                    'message' => 'Sessão expirada. Atualize a página e tente novamente.',
                    'redirect' => Router::url('/operations'),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                return;
            }

            Flash::set('error', 'Sessão expirada. Atualize a página e tente novamente.');
            header('Location: ' . Router::url('/operations'));
            exit;
        }

        $service = new OperationsService();
        $service->runChecks();

        if ($wantsJson) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => true,
                'message' => 'Verificações executadas com sucesso.',
                'redirect' => Router::url('/operations'),
                'checked_at' => date('Y-m-d H:i:s'),
                'data' => $service->dashboard(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return;
        }

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
        $storageType = (string) ($_POST['storage_type'] ?? 'manual_local');
        $fileName = trim((string) ($_POST['file_name'] ?? ''));
        $location = trim((string) ($_POST['location'] ?? ''));
        $sizeRaw = trim((string) ($_POST['size_bytes'] ?? ''));
        $sizeBytes = $sizeRaw !== '' && is_numeric($sizeRaw) ? max(0, (int) $sizeRaw) : null;
        $checksum = trim((string) ($_POST['checksum'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $verified = isset($_POST['verified']) && (string) $_POST['verified'] === '1';

        (new OperationsService())->registerManualBackup($type, $storageType, $fileName, $location, $sizeBytes, $checksum, $notes, $verified);
        Flash::set('success', 'Registro de backup salvo.');
        header('Location: ' . Router::url('/operations'));
        exit;
    }

    public function resolveIncident(): void
    {
        if (!Csrf::validate($_POST['_token'] ?? null)) {
            Flash::set('error', 'Sessão expirada. Atualize a página e tente novamente.');
            header('Location: ' . Router::url('/operations'));
            exit;
        }

        $id = (int) ($_POST['id'] ?? 0);
        (new OperationsService())->resolveIncident($id);
        Flash::set('success', 'Alerta/incidente marcado como resolvido.');
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
