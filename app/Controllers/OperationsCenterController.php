<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\View;
use App\Services\AppVersionService;
use App\Services\BackupAutomationService;
use App\Services\CommercialBetaService;
use App\Services\OperationsService;
use App\Services\SecurityService;

final class OperationsCenterController
{
    public function index(): void { $this->render('monitoring'); }
    public function monitoring(): void { $this->render('monitoring'); }
    public function security(): void { $this->render('security'); }
    public function backups(): void { $this->render('backups'); }
    public function beta(): void { $this->render('beta'); }
    public function status(): void { $this->render('status'); }

    private function render(string $selectedTab): void
    {
        View::render('operations.center', [
            'title' => 'Central de operação',
            'selectedTab' => $selectedTab,
            'operationsData' => (new OperationsService())->dashboard(),
            'securityData' => (new SecurityService())->dashboard(),
            'backupData' => (new BackupAutomationService())->dashboard(),
            'betaData' => (new CommercialBetaService())->dashboard(),
            'versionData' => (new AppVersionService())->dashboard(),
        ]);
    }
}
