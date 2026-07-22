<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Csrf;
use App\Core\Flash;
use App\Core\Router;
use App\Core\View;
use App\Services\BackupAutomationService;
use Throwable;

final class BackupAutomationController
{
    public function index(): void
    {
        $service = new BackupAutomationService();
        View::render('operations.backup_automation', [
            'title' => 'Backup automático',
            'data' => $service->dashboard(),
        ]);
    }

    public function save(): void
    {
        if (!Csrf::validate($_POST['_token'] ?? null)) {
            Flash::set('error', 'Sessão expirada. Atualize a página e tente novamente.');
            $this->redirect('/backup-automatico');
        }

        try {
            (new BackupAutomationService())->saveRoutine($_POST);
            Flash::set('success', 'Rotina de backup salva.');
        } catch (Throwable $exception) {
            Flash::set('error', 'Não foi possível salvar a rotina: ' . $exception->getMessage());
        }

        $this->redirect('/backup-automatico');
    }

    public function trigger(): void
    {
        if (!Csrf::validate($_POST['_token'] ?? null)) {
            Flash::set('error', 'Sessão expirada. Atualize a página e tente novamente.');
            $this->redirect('/backup-automatico');
        }

        $routineId = (int) ($_POST['routine_id'] ?? 0);
        $triggerType = (string) ($_POST['trigger_type'] ?? 'manual');
        $result = (new BackupAutomationService())->triggerRoutine($routineId, $triggerType);
        Flash::set(!empty($result['ok']) ? 'success' : 'error', (string) ($result['message'] ?? 'Solicitação processada.'));
        $this->redirect($this->safeReturnPath((string) ($_POST['return_to'] ?? ''), '/backup-automatico'));
    }

    public function testConnection(): void
    {
        if (!Csrf::validate($_POST['_token'] ?? null)) {
            Flash::set('error', 'Sessão expirada. Atualize a página e tente novamente.');
            $this->redirect('/backup-automatico');
        }

        $routineId = (int) ($_POST['routine_id'] ?? 0);
        $result = (new BackupAutomationService())->testConnection($routineId);
        Flash::set(!empty($result['ok']) ? 'success' : 'error', (string) ($result['message'] ?? 'Teste processado.'));
        $this->redirect('/backup-automatico');
    }

    public function status(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        echo json_encode([
            'ok' => true,
            'data' => (new BackupAutomationService())->dashboard(),
            'refreshed_at' => date('c'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function toggle(): void
    {
        if (!Csrf::validate($_POST['_token'] ?? null)) {
            Flash::set('error', 'Sessão expirada. Atualize a página e tente novamente.');
            $this->redirect('/backup-automatico');
        }

        $routineId = (int) ($_POST['routine_id'] ?? 0);
        $status = (string) ($_POST['status'] ?? 'active');
        (new BackupAutomationService())->toggleRoutine($routineId, $status);
        Flash::set('success', 'Status da rotina atualizado.');
        $this->redirect('/backup-automatico');
    }

    private function safeReturnPath(string $path, string $fallback): string
    {
        $path = trim($path);
        if ($path === '' || !str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return $fallback;
        }
        return $path;
    }

    private function redirect(string $path): void
    {
        header('Location: ' . Router::url($path));
        exit;
    }
}
