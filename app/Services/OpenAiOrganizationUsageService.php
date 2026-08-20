<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Env;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;

/**
 * Consulta a Usage API administrativa da OpenAI sem expor a Admin API Key no navegador.
 *
 * A chave usada aqui é diferente da OPENAI_API_KEY de inferência. Os endpoints
 * /organization/usage/* e /organization/costs exigem uma Admin API Key da organização.
 */
final class OpenAiOrganizationUsageService
{
    private const PERIODS = ['month', '7d', '30d'];

    /** @return array<string,mixed> */
    public function dashboard(string $period = 'month', bool $forceRefresh = false): array
    {
        $period = in_array($period, self::PERIODS, true) ? $period : 'month';
        $range = $this->range($period);
        $apiKey = trim((string) Env::get('OPENAI_ADMIN_API_KEY', ''));

        if ($apiKey === '') {
            return $this->emptyDashboard($period, $range, 'not_configured', null);
        }

        $cacheScope = hash('sha256', implode('|', [
            trim((string) Env::get('OPENAI_ORGANIZATION_ID', '')),
            trim((string) Env::get('OPENAI_USAGE_PROJECT_IDS', '')),
            rtrim((string) Env::get('OPENAI_ADMIN_API_BASE_URL', 'https://api.openai.com/v1'), '/'),
        ]));
        $cacheKey = hash('sha256', implode('|', [
            $period,
            (string) $range['start_time'],
            (string) $range['end_time'],
            $cacheScope,
        ]));

        if (!$forceRefresh) {
            $cached = $this->readCache($cacheKey);
            if ($cached !== null) {
                $cached['from_cache'] = true;
                return $cached;
            }
        }

        try {
            $common = [
                'start_time' => (int) $range['start_time'],
                'end_time' => (int) $range['end_time'],
                'bucket_width' => '1d',
                'limit' => (int) $range['days'],
            ];
            $projectIds = $this->csvValues((string) Env::get('OPENAI_USAGE_PROJECT_IDS', ''));
            if ($projectIds !== []) {
                $common['project_ids'] = $projectIds;
            }

            $completions = $this->fetchAllPages('/organization/usage/completions', $common + [
                'group_by' => ['model'],
            ], $apiKey);
            $costs = $this->fetchAllPages('/organization/costs', $common + [
                'group_by' => ['line_item'],
            ], $apiKey);

            $dashboard = $this->summarize($period, $range, $completions, $costs);
            $dashboard['cache_scope'] = $cacheScope;
            $this->writeCache($cacheKey, $dashboard);

            return $dashboard;
        } catch (Throwable $exception) {
            $fallback = $this->readNewestCacheForPeriod($period, $cacheScope);
            if ($fallback !== null) {
                $fallback['status'] = 'stale';
                $fallback['from_cache'] = true;
                $fallback['error'] = $this->friendlyError($exception);
                return $fallback;
            }

            return $this->emptyDashboard($period, $range, 'error', $this->friendlyError($exception));
        }
    }

    /**
     * Método público e determinístico para validação por fixture.
     *
     * @param array<string,mixed> $range
     * @param array<string,mixed> $completions
     * @param array<string,mixed> $costs
     * @return array<string,mixed>
     */
    public function summarize(string $period, array $range, array $completions, array $costs): array
    {
        $daily = [];
        $cursor = (new DateTimeImmutable('@' . (int) $range['start_time']))->setTimezone(new DateTimeZone('UTC'));
        $end = (new DateTimeImmutable('@' . (int) $range['end_time']))->setTimezone(new DateTimeZone('UTC'));
        while ($cursor < $end) {
            $key = $cursor->format('Y-m-d');
            $daily[$key] = [
                'date' => $key,
                'label' => $cursor->format('d/m'),
                'input_tokens' => 0,
                'output_tokens' => 0,
                'total_tokens' => 0,
                'cached_tokens' => 0,
                'requests' => 0,
                'cost' => 0.0,
                'currency' => 'usd',
            ];
            $cursor = $cursor->add(new DateInterval('P1D'));
        }

        $totals = [
            'input_tokens' => 0,
            'output_tokens' => 0,
            'total_tokens' => 0,
            'cached_tokens' => 0,
            'requests' => 0,
            'cost' => 0.0,
            'currency' => 'usd',
        ];
        $models = [];
        $lineItems = [];

        foreach (($completions['data'] ?? []) as $bucket) {
            if (!is_array($bucket)) {
                continue;
            }
            $date = gmdate('Y-m-d', (int) ($bucket['start_time'] ?? 0));
            foreach (($bucket['results'] ?? []) as $result) {
                if (!is_array($result)) {
                    continue;
                }
                $input = (int) ($result['input_tokens'] ?? 0);
                $output = (int) ($result['output_tokens'] ?? 0);
                $cached = (int) ($result['input_cached_tokens'] ?? $result['input_cached_text_tokens'] ?? 0);
                $requests = (int) ($result['num_model_requests'] ?? 0);
                $total = $input + $output;
                $model = trim((string) ($result['model'] ?? '')) ?: 'Não identificado';

                $totals['input_tokens'] += $input;
                $totals['output_tokens'] += $output;
                $totals['total_tokens'] += $total;
                $totals['cached_tokens'] += $cached;
                $totals['requests'] += $requests;

                if (isset($daily[$date])) {
                    $daily[$date]['input_tokens'] += $input;
                    $daily[$date]['output_tokens'] += $output;
                    $daily[$date]['total_tokens'] += $total;
                    $daily[$date]['cached_tokens'] += $cached;
                    $daily[$date]['requests'] += $requests;
                }

                $models[$model] ??= [
                    'model' => $model,
                    'input_tokens' => 0,
                    'output_tokens' => 0,
                    'total_tokens' => 0,
                    'cached_tokens' => 0,
                    'requests' => 0,
                ];
                $models[$model]['input_tokens'] += $input;
                $models[$model]['output_tokens'] += $output;
                $models[$model]['total_tokens'] += $total;
                $models[$model]['cached_tokens'] += $cached;
                $models[$model]['requests'] += $requests;
            }
        }

        foreach (($costs['data'] ?? []) as $bucket) {
            if (!is_array($bucket)) {
                continue;
            }
            $date = gmdate('Y-m-d', (int) ($bucket['start_time'] ?? 0));
            foreach (($bucket['results'] ?? []) as $result) {
                if (!is_array($result)) {
                    continue;
                }
                $amount = is_array($result['amount'] ?? null) ? $result['amount'] : [];
                $value = (float) ($amount['value'] ?? 0);
                $currency = strtolower(trim((string) ($amount['currency'] ?? 'usd'))) ?: 'usd';
                $lineItem = trim((string) ($result['line_item'] ?? '')) ?: 'Outros serviços';

                $totals['cost'] += $value;
                $totals['currency'] = $currency;
                if (isset($daily[$date])) {
                    $daily[$date]['cost'] += $value;
                    $daily[$date]['currency'] = $currency;
                }

                $lineItems[$lineItem] ??= [
                    'line_item' => $lineItem,
                    'cost' => 0.0,
                    'currency' => $currency,
                    'quantity' => 0.0,
                ];
                $lineItems[$lineItem]['cost'] += $value;
                $lineItems[$lineItem]['currency'] = $currency;
                $lineItems[$lineItem]['quantity'] += (float) ($result['quantity'] ?? 0);
            }
        }

        $models = array_values($models);
        usort($models, static fn (array $a, array $b): int => ($b['total_tokens'] <=> $a['total_tokens']));
        $lineItems = array_values($lineItems);
        usort($lineItems, static fn (array $a, array $b): int => ($b['cost'] <=> $a['cost']));

        $dailyRows = array_values($daily);
        $maxDailyTokens = 0;
        foreach ($dailyRows as $row) {
            $maxDailyTokens = max($maxDailyTokens, (int) ($row['total_tokens'] ?? 0));
        }

        return [
            'status' => 'ok',
            'configured' => true,
            'period' => $period,
            'period_label' => (string) ($range['label'] ?? ''),
            'start_date' => (string) ($range['start_date'] ?? ''),
            'end_date' => (string) ($range['end_date'] ?? ''),
            'days' => (int) ($range['days'] ?? count($dailyRows)),
            'totals' => $totals,
            'daily' => $dailyRows,
            'models' => array_slice($models, 0, 12),
            'line_items' => array_slice($lineItems, 0, 12),
            'max_daily_tokens' => $maxDailyTokens,
            'project_filter' => $this->csvValues((string) Env::get('OPENAI_USAGE_PROJECT_IDS', '')),
            'fetched_at' => gmdate('c'),
            'from_cache' => false,
            'error' => null,
        ];
    }

    /** @return array<string,mixed> */
    private function range(string $period): array
    {
        $timezone = new DateTimeZone('UTC');
        $now = new DateTimeImmutable('now', $timezone);
        $today = $now->setTime(0, 0, 0);

        if ($period === '7d') {
            $start = $today->sub(new DateInterval('P6D'));
            $label = 'Últimos 7 dias';
        } elseif ($period === '30d') {
            $start = $today->sub(new DateInterval('P29D'));
            $label = 'Últimos 30 dias';
        } else {
            $start = $today->modify('first day of this month');
            $label = 'Mês atual';
        }

        $end = $today->add(new DateInterval('P1D'));
        $days = max(1, (int) $start->diff($end)->format('%a'));

        return [
            'start_time' => $start->getTimestamp(),
            'end_time' => $end->getTimestamp(),
            'start_date' => $start->format('Y-m-d'),
            'end_date' => $today->format('Y-m-d'),
            'days' => min(31, $days),
            'label' => $label,
        ];
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function fetchAllPages(string $path, array $params, string $apiKey): array
    {
        $all = [];
        $page = null;
        $iterations = 0;

        do {
            $query = $params;
            if (is_string($page) && $page !== '') {
                $query['page'] = $page;
            }
            $response = $this->request($path, $query, $apiKey);
            foreach (($response['data'] ?? []) as $bucket) {
                if (is_array($bucket)) {
                    $all[] = $bucket;
                }
            }
            $page = !empty($response['has_more']) ? (string) ($response['next_page'] ?? '') : null;
            $iterations++;
        } while ($page !== null && $page !== '' && $iterations < 10);

        return ['data' => $all, 'has_more' => false, 'next_page' => null];
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function request(string $path, array $params, string $apiKey): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('A extensão cURL do PHP não está habilitada no servidor.');
        }

        $baseUrl = rtrim((string) Env::get('OPENAI_ADMIN_API_BASE_URL', 'https://api.openai.com/v1'), '/');
        $url = $baseUrl . $path . '?' . $this->queryString($params);
        $headers = [
            'Authorization: Bearer ' . $apiKey,
            'Accept: application/json',
            'User-Agent: RS-Connect-OpenAI-Usage/36.16.3',
        ];
        $organizationId = trim((string) Env::get('OPENAI_ORGANIZATION_ID', ''));
        if ($organizationId !== '') {
            $headers[] = 'OpenAI-Organization: ' . $organizationId;
        }

        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('Não foi possível iniciar a consulta à OpenAI.');
        }

        $verifySsl = filter_var(Env::get('OPENAI_USAGE_SSL_VERIFY', true), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) !== false;
        $timeout = max(5, min(60, (int) Env::get('OPENAI_USAGE_HTTP_TIMEOUT', 20)));
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => $verifySsl,
            CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
        ]);

        $body = curl_exec($curl);
        $curlError = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if ($body === false || $curlError !== '') {
            throw new RuntimeException('Falha de comunicação com a OpenAI: ' . ($curlError ?: 'resposta vazia.'));
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('A OpenAI retornou uma resposta inválida.');
        }

        if ($status < 200 || $status >= 300) {
            $message = trim((string) ($decoded['error']['message'] ?? $decoded['message'] ?? 'Consulta recusada pela OpenAI.'));
            throw new RuntimeException('OpenAI HTTP ' . $status . ': ' . $message);
        }

        return $decoded;
    }

    /** @param array<string,mixed> $params */
    private function queryString(array $params): string
    {
        $parts = [];
        foreach ($params as $key => $value) {
            if (is_array($value)) {
                // OpenAPI query arrays use form/explode: repeat the same key for each value.
                foreach ($value as $item) {
                    $parts[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $item);
                }
                continue;
            }
            if ($value === null || $value === '') {
                continue;
            }
            $parts[] = rawurlencode((string) $key) . '=' . rawurlencode(is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);
        }

        return implode('&', $parts);
    }

    /** @return list<string> */
    private function csvValues(string $value): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (string $item): string => trim($item),
            explode(',', $value)
        ), static fn (string $item): bool => $item !== '')));
    }

    /** @param array<string,mixed> $range @return array<string,mixed> */
    private function emptyDashboard(string $period, array $range, string $status, ?string $error): array
    {
        return [
            'status' => $status,
            'configured' => $status !== 'not_configured',
            'period' => $period,
            'period_label' => (string) ($range['label'] ?? ''),
            'start_date' => (string) ($range['start_date'] ?? ''),
            'end_date' => (string) ($range['end_date'] ?? ''),
            'days' => (int) ($range['days'] ?? 0),
            'totals' => [
                'input_tokens' => 0,
                'output_tokens' => 0,
                'total_tokens' => 0,
                'cached_tokens' => 0,
                'requests' => 0,
                'cost' => 0.0,
                'currency' => 'usd',
            ],
            'daily' => [],
            'models' => [],
            'line_items' => [],
            'max_daily_tokens' => 0,
            'project_filter' => $this->csvValues((string) Env::get('OPENAI_USAGE_PROJECT_IDS', '')),
            'fetched_at' => null,
            'from_cache' => false,
            'error' => $error,
        ];
    }

    private function cacheDirectory(): string
    {
        $directory = dirname(__DIR__, 2) . '/storage/cache/openai-usage';
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }
        return $directory;
    }

    /** @return array<string,mixed>|null */
    private function readCache(string $cacheKey): ?array
    {
        $path = $this->cacheDirectory() . '/' . $cacheKey . '.json';
        if (!is_file($path)) {
            return null;
        }
        $ttl = max(30, min(3600, (int) Env::get('OPENAI_USAGE_CACHE_SECONDS', 300)));
        if ((time() - (int) filemtime($path)) > $ttl) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string,mixed> $payload */
    private function writeCache(string $cacheKey, array $payload): void
    {
        $path = $this->cacheDirectory() . '/' . $cacheKey . '.json';
        @file_put_contents(
            $path,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            LOCK_EX
        );
    }

    /** @return array<string,mixed>|null */
    private function readNewestCacheForPeriod(string $period, string $cacheScope): ?array
    {
        $files = glob($this->cacheDirectory() . '/*.json') ?: [];
        usort($files, static fn (string $a, string $b): int => ((int) filemtime($b)) <=> ((int) filemtime($a)));
        foreach ($files as $file) {
            $decoded = json_decode((string) file_get_contents($file), true);
            if (is_array($decoded) && ($decoded['period'] ?? '') === $period && ($decoded['cache_scope'] ?? '') === $cacheScope) {
                return $decoded;
            }
        }
        return null;
    }

    private function friendlyError(Throwable $exception): string
    {
        $message = trim($exception->getMessage());
        if (str_contains($message, 'HTTP 401')) {
            return 'A Admin API Key foi recusada. Gere uma chave administrativa da organização na OpenAI e atualize OPENAI_ADMIN_API_KEY.';
        }
        if (str_contains($message, 'HTTP 403')) {
            return 'A chave não possui permissão para consultar uso e custos da organização.';
        }
        if (str_contains($message, 'HTTP 429')) {
            return 'A OpenAI limitou temporariamente as consultas. Aguarde alguns minutos e atualize novamente.';
        }
        return $message !== '' ? $message : 'Não foi possível consultar o consumo da OpenAI.';
    }
}
