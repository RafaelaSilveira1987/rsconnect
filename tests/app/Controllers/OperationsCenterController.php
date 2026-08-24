<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\View;
use App\Services\AiReprocessService;
use App\Services\AppVersionService;
use App\Services\BackupAutomationService;
use App\Services\CommercialBetaService;
use App\Services\OperationsService;
use App\Services\OperationalPlaybookService;
use App\Services\SecurityService;

final class OperationsCenterController
{
    public function index(): void
    {
        $diagnosticKey = trim((string) ($_GET['diagnostico'] ?? ''));
        $tab = (string) ($_GET['tab'] ?? '');
        if ($tab === '' && $diagnosticKey !== '') {
            $tab = match ($diagnosticKey) {
                'backup' => 'backups',
                'ai_reprocess', 'openai', 'evolution' => 'ai_reprocess',
                'database', 'migrations' => 'status',
                default => 'monitoring',
            };
        }
        if ($tab === '') $tab = 'monitoring';
        $allowed = ['monitoring', 'ai_reprocess', 'security', 'backups', 'beta', 'status'];
        $this->render(in_array($tab, $allowed, true) ? $tab : 'monitoring', $diagnosticKey);
    }
    public function monitoring(): void { $this->render('monitoring'); }
    public function aiReprocess(): void { $this->render('ai_reprocess'); }
    public function security(): void { $this->render('security'); }
    public function backups(): void { $this->render('backups'); }
    public function beta(): void { $this->render('beta'); }
    public function status(): void { $this->render('status'); }

    private function render(string $selectedTab, string $diagnosticKey = ''): void
    {
        View::render('operations.center', [
            'title' => 'Central de operação',
            'selectedTab' => $selectedTab,
            'operationsData' => (new OperationsService())->dashboard(),
            'aiReprocessData' => (new AiReprocessService())->dashboard(),
            'securityData' => (new SecurityService())->dashboard(),
            'backupData' => (new BackupAutomationService())->dashboard(),
            'betaData' => (new CommercialBetaService())->dashboard(),
            'versionData' => (new AppVersionService())->dashboard(),
            'diagnosticData' => $diagnosticKey !== '' ? (new OperationalPlaybookService())->diagnose($diagnosticKey, (int) ($_GET['tenant'] ?? 0)) : null,
        ]);
    }
}
