<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\View;
use App\Services\CommercialBetaService;

final class DocumentationController
{
    public function index(): void
    {
        View::render('docs.index', [
            'title' => 'Central de ajuda',
            'is_super_admin' => Auth::isSuperAdmin(),
        ]);
    }

    public function beta(): void
    {
        $service = new CommercialBetaService();
        View::render('docs.beta', [
            'title' => 'Beta 1.0',
            'data' => $service->dashboard(),
        ]);
    }
}
