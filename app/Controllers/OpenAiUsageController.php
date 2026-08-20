<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\View;
use App\Services\OpenAiOrganizationUsageService;

final class OpenAiUsageController
{
    public function index(): void
    {
        $period = (string) ($_GET['usage_period'] ?? 'month');
        $forceRefresh = isset($_GET['refresh_usage']) && (string) $_GET['refresh_usage'] === '1';
        $usage = (new OpenAiOrganizationUsageService())->dashboard($period, $forceRefresh);

        View::render('openai_usage.index', [
            'title' => 'Consumo OpenAI',
            'openAiUsage' => $usage,
        ]);
    }
}
