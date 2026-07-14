<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\View;
use App\Services\AppVersionService;

final class VersionController
{
    public function index(): void
    {
        $service = new AppVersionService();
        View::render('docs.status', [
            'title' => 'Status Beta 1.0',
            'data' => $service->dashboard(),
        ]);
    }
}
