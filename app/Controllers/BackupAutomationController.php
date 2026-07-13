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
            $this->redirect('/operations/backups/automation');
        }

        try {
            (new BackupAutomationService())->saveRoutine($_POST);
            Flash::set('success', 'Rotina de backup salva.');
        } catch (Throwable $exception) {
            Flash::set('error', 'Não foi possível salvar a rotina: ' . $exception->getMessage());
        }

        $this->redirect('/operations/backups/automation');
    }

    public function trigger(): void
    {
        if (!Csrf::validate($_POST['_token'] ?? null)) {
            Flash::set('error', 'Sessão expirada. Atualize a página e tente novamente.');
            $this->redirect('/operations/backups/automation');
        }

        $routineId = (int) ($_POST['routine_id'] ?? 0);
        $triggerType = (string) ($_POST['trigger_type'] ?? 'manual');
        $result = (new BackupAutomationService())->triggerRoutine($routineId, $triggerType);
        Flash::set(!empty($result['ok']) ? 'success' : 'error', (string) ($result['message'] ?? 'Solicitação processada.'));
        $this->redirect('/operations/backups/automation');
    }

    public function toggle(): void
    {
        if (!Csrf::validate($_POST['_token'] ?? null)) {
            Flash::set('error', 'Sessão expirada. Atualize a página e tente novamente.');
            $this->redirect('/operations/backups/automation');
        }

        $routineId = (int) ($_POST['routine_id'] ?? 0);
        $status = (string) ($_POST['status'] ?? 'active');
        (new BackupAutomationService())->toggleRoutine($routineId, $status);
        Flash::set('success', 'Status da rotina atualizado.');
        $this->redirect('/operations/backups/automation');
    }

    private function redirect(string $path): void
    {
        header('Location: ' . Router::url($path));
        exit;
    }
}
