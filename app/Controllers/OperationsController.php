<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Csrf;
use App\Core\Flash;
use App\Core\Router;
use App\Core\View;
use App\Services\BackupAutomationService;
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
        $returnPath = $this->safeReturnPath((string) ($_POST['return_to'] ?? ''), '/operations');
        $redirectUrl = Router::url($returnPath);

        if ($wantsJson) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => true,
                'message' => 'Verificações executadas com sucesso.',
                'redirect' => $redirectUrl,
                'checked_at' => date('Y-m-d H:i:s'),
                'data' => $service->dashboard(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return;
        }

        Flash::set('success', 'Verificações executadas. Confira o painel de monitoramento.');
        header('Location: ' . $redirectUrl);
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
        $service = new OperationsService();
        if (!$service->validBackupToken($this->backupRequestToken())) {
            $this->json(['ok' => false, 'error' => 'Token inválido.'], 403);
            return;
        }

        $payload = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            $payload = $_POST;
        }

        $result = $service->registerExternalBackup($payload);
        $status = (int) ($result['http_status'] ?? (!empty($result['ok']) ? 200 : 422));
        unset($result['http_status']);
        $this->json($result, $status);
    }

    public function runBackupDispatch(): void
    {
        $service = new OperationsService();
        if (!$service->validBackupToken($this->backupRequestToken())) {
            $this->json(['ok' => false, 'error' => 'Token inválido.'], 403);
            return;
        }

        $this->json((new BackupAutomationService())->dispatchDueRoutines());
    }

    private function backupRequestToken(): string
    {
        $queryToken = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
        if ($queryToken !== '') {
            return $queryToken;
        }

        $headerToken = trim((string) ($_SERVER['HTTP_X_RS_CONNECT_TOKEN'] ?? ''));
        if ($headerToken !== '') {
            return $headerToken;
        }

        $authorization = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
        if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
            return trim((string) ($matches[1] ?? ''));
        }

        return '';
    }

    private function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function safeReturnPath(string $path, string $fallback): string
    {
        $path = trim($path);
        if ($path === '' || !str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return $fallback;
        }
        return $path;
    }
}
