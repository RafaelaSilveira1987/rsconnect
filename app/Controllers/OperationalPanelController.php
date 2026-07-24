<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\View;
use App\Services\OperationalOverviewService;

final class OperationalPanelController
{
    public function index(): void
    {
        View::render('operations.overview', [
            'title' => 'Painel operacional',
            'data' => (new OperationalOverviewService())->dashboard(),
        ]);
    }
}
