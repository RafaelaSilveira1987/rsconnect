<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\HealthCheckService;

final class HealthController
{
    public function live(): void
    {
        $this->json(['status' => 'ok'], 200);
    }

    public function ready(): void
    {
        $ready = (new HealthCheckService())->isReady();
        $this->json(
            ['status' => $ready ? 'ok' : 'unavailable'],
            $ready ? 200 : 503,
            !$ready ? 5 : null
        );
    }

    public function readyDetails(): void
    {
        $details = (new HealthCheckService())->readinessDetails();
        $this->json($details, $details['status'] === 'ok' ? 200 : 503);
    }

    private function json(array $payload, int $status, ?int $retryAfter = null): void
    {
        http_response_code($status);
        if (!headers_sent()) {
            header_remove('X-Powered-By');
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('X-Content-Type-Options: nosniff');
            header('X-Robots-Tag: noindex, nofollow');
            if ($retryAfter !== null) {
                header('Retry-After: ' . $retryAfter);
            }
        }

        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
