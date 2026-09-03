<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Env;
use Throwable;

/**
 * Consulta a API pública do n8n para que o monitor não confunda cadastros locais
 * do RS Connect com o estado real dos workflows no n8n.
 */
final class N8nLiveMetricsService
{
    /** @return array<string,mixed> */
    public function snapshot(): array
    {
        $baseUrl = rtrim(trim((string) Env::get('N8N_BASE_URL', '')), '/');
        $apiKey = trim((string) Env::get('N8N_API_KEY', ''));
        $apiBase = preg_match('#/api/v1$#', $baseUrl) === 1 ? $baseUrl : $baseUrl . '/api/v1';
        $started = microtime(true);

        $base = [
            'available' => false,
            'configured' => $baseUrl !== '' && $apiKey !== '',
            'base_url_configured' => $baseUrl !== '',
            'api_key_configured' => $apiKey !== '',
            'total' => 0,
            'active' => 0,
            'inactive' => 0,
            'archived' => 0,
            'latency_ms' => null,
            'checked_at' => \App\Core\Clock::nowUtc(),
            'source' => 'n8n_api_v1',
            'error' => null,
        ];

        if ($baseUrl === '') {
            $base['error'] = 'N8N_BASE_URL não configurada.';
            return $base;
        }
        if ($apiKey === '') {
            $base['error'] = 'N8N_API_KEY não configurada para consulta em tempo real.';
            return $base;
        }
        if (!function_exists('curl_init')) {
            $base['error'] = 'Extensão cURL indisponível no PHP.';
            return $base;
        }

        try {
            $cursor = null;
            $workflows = [];
            $pageGuard = 0;

            do {
                $pageGuard++;
                $url = $apiBase . '/workflows?limit=250';
                if (is_string($cursor) && $cursor !== '') {
                    $url .= '&cursor=' . rawurlencode($cursor);
                }

                $response = $this->request($url, $apiKey);
                $httpCode = (int) ($response['http_code'] ?? 0);
                if ($httpCode < 200 || $httpCode >= 300) {
                    $base['error'] = match ($httpCode) {
                        401, 403 => 'A API do n8n recusou a N8N_API_KEY.',
                        404 => 'Endpoint /api/v1/workflows não encontrado no n8n configurado.',
                        default => 'A API do n8n respondeu HTTP ' . $httpCode . '.',
                    };
                    $base['latency_ms'] = (int) round((microtime(true) - $started) * 1000);
                    return $base;
                }

                $payload = json_decode((string) ($response['body'] ?? ''), true);
                if (!is_array($payload) || !is_array($payload['data'] ?? null)) {
                    $base['error'] = 'Resposta inesperada da API do n8n.';
                    $base['latency_ms'] = (int) round((microtime(true) - $started) * 1000);
                    return $base;
                }

                foreach ($payload['data'] as $workflow) {
                    if (is_array($workflow)) {
                        $workflows[] = $workflow;
                    }
                }

                $cursor = isset($payload['nextCursor']) && is_string($payload['nextCursor'])
                    ? trim($payload['nextCursor'])
                    : null;
            } while ($cursor !== null && $cursor !== '' && $pageGuard < 20);

            $active = 0;
            $archived = 0;
            foreach ($workflows as $workflow) {
                if (!empty($workflow['isArchived'])) {
                    $archived++;
                }
                if (($workflow['active'] ?? false) === true && empty($workflow['isArchived'])) {
                    $active++;
                }
            }

            $total = count($workflows);
            $base['available'] = true;
            $base['total'] = $total;
            $base['active'] = $active;
            $base['archived'] = $archived;
            $base['inactive'] = max(0, $total - $active - $archived);
            $base['latency_ms'] = (int) round((microtime(true) - $started) * 1000);
            return $base;
        } catch (Throwable $exception) {
            $base['error'] = 'Falha ao consultar o n8n: ' . $exception->getMessage();
            $base['latency_ms'] = (int) round((microtime(true) - $started) * 1000);
            return $base;
        }
    }

    /** @return array{http_code:int,body:string} */
    private function request(string $url, string $apiKey): array
    {
        $curl = curl_init($url);
        if ($curl === false) {
            throw new \RuntimeException('Não foi possível inicializar cURL.');
        }

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 7,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'X-N8N-API-KEY: ' . $apiKey,
            ],
        ]);

        $body = curl_exec($curl);
        if ($body === false) {
            $error = curl_error($curl);
            curl_close($curl);
            throw new \RuntimeException($error !== '' ? $error : 'Erro de comunicação com o n8n.');
        }

        $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        return ['http_code' => $httpCode, 'body' => (string) $body];
    }
}
