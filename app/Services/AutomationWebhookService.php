<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Env;

final class AutomationWebhookService
{
    public function dispatch(string $event, array $payload, ?string $url = null): void
    {
        $target = trim((string) ($url ?: Env::get('N8N_WEBHOOK_URL', '')));
        if ($target === '') {
            return;
        }

        $body = [
            'event' => $event,
            'source' => 'rs-connect',
            'payload' => $payload,
            'sent_at' => date('c'),
        ];

        $curl = curl_init($target);
        if ($curl === false) {
            return;
        }

        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        curl_exec($curl);
        curl_close($curl);
    }
}
