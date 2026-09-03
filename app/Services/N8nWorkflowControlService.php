<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Env;
use RuntimeException;
use Throwable;

/**
 * Controle operacional de workflows críticos do n8n.
 *
 * A ativação não depende do cadastro local do RS Connect: o workflow é localizado
 * diretamente pela API pública do n8n e, quando solicitado explicitamente, publicado.
 */
final class N8nWorkflowControlService
{
    private string $baseUrl;
    private string $apiKey;
    private string $apiBase;

    public function __construct()
    {
        $this->baseUrl = rtrim(trim((string) Env::get('N8N_BASE_URL', '')), '/');
        $this->apiKey = trim((string) Env::get('N8N_API_KEY', ''));
        $this->apiBase = preg_match('#/api/v1$#', $this->baseUrl) === 1
            ? $this->baseUrl
            : $this->baseUrl . '/api/v1';
    }

    /** @return array<string,mixed> */
    public function operationsMonitor(bool $activateIfInactive = false): array
    {
        $result = [
            'available' => false,
            'configured' => $this->baseUrl !== '' && $this->apiKey !== '',
            'found' => false,
            'workflow_id' => null,
            'workflow_name' => null,
            'active' => false,
            'archived' => false,
            'schedule_trigger_present' => false,
            'schedule_nodes' => [],
            'activation_attempted' => false,
            'activation_succeeded' => false,
            'last_execution_available' => false,
            'last_execution_status' => null,
            'last_execution_started_at' => null,
            'last_execution_mode' => null,
            'last_execution_id' => null,
            'error' => null,
        ];

        if ($this->baseUrl === '') {
            $result['error'] = 'N8N_BASE_URL não configurada.';
            return $result;
        }
        if ($this->apiKey === '') {
            $result['error'] = 'N8N_API_KEY não configurada.';
            return $result;
        }
        if (!function_exists('curl_init')) {
            $result['error'] = 'Extensão cURL indisponível no PHP.';
            return $result;
        }

        try {
            $workflows = $this->listWorkflows();
            $workflow = $this->findOperationsMonitor($workflows);
            $result['available'] = true;

            if ($workflow === null) {
                $result['error'] = 'Workflow “RS Connect - Monitor operacional” não encontrado no n8n.';
                return $result;
            }

            $workflowId = trim((string) ($workflow['id'] ?? ''));
            if ($workflowId === '') {
                $result['error'] = 'Workflow do monitor encontrado sem ID válido.';
                return $result;
            }

            $detail = $this->workflowDetail($workflowId);
            if ($detail !== null) {
                $workflow = array_merge($workflow, $detail);
            }

            $result['found'] = true;
            $result['workflow_id'] = $workflowId;
            $result['workflow_name'] = trim((string) ($workflow['name'] ?? 'RS Connect - Monitor operacional'));
            $result['active'] = ($workflow['active'] ?? false) === true;
            $result['archived'] = !empty($workflow['isArchived']);

            $scheduleNodes = $this->scheduleNodes(is_array($workflow['nodes'] ?? null) ? $workflow['nodes'] : []);
            $result['schedule_nodes'] = $scheduleNodes;
            $result['schedule_trigger_present'] = count($scheduleNodes) > 0;

            if ($result['archived']) {
                $result['error'] = 'O workflow do Monitor operacional está arquivado no n8n.';
                $this->appendLatestExecution($result, $workflowId);
                return $result;
            }

            if (!$result['active'] && $activateIfInactive) {
                $result['activation_attempted'] = true;
                $activation = $this->request('POST', $this->apiBase . '/workflows/' . rawurlencode($workflowId) . '/activate');
                $code = (int) ($activation['http_code'] ?? 0);
                if ($code < 200 || $code >= 300) {
                    $message = $this->responseMessage((string) ($activation['body'] ?? ''));
                    $result['error'] = 'Falha ao publicar o workflow do Monitor operacional no n8n: HTTP ' . $code
                        . ($message !== '' ? ' — ' . $message : '.');
                    $this->appendLatestExecution($result, $workflowId);
                    return $result;
                }

                $confirmed = $this->workflowDetail($workflowId);
                $result['active'] = is_array($confirmed) && (($confirmed['active'] ?? false) === true);
                $result['activation_succeeded'] = $result['active'];
                if (!$result['active']) {
                    $result['error'] = 'A API aceitou a publicação, mas o workflow não voltou como ativo na confirmação.';
                }
            }

            if (!$result['active'] && $result['error'] === null) {
                $result['error'] = 'O workflow do Monitor operacional está despublicado/inativo.';
            } elseif (!$result['schedule_trigger_present'] && $result['error'] === null) {
                $result['error'] = 'O workflow está ativo, mas nenhum gatilho de agenda/cron foi encontrado.';
            }

            $this->appendLatestExecution($result, $workflowId);
            return $result;
        } catch (Throwable $exception) {
            $result['error'] = 'Falha ao consultar/controlar o workflow do monitor: ' . $exception->getMessage();
            return $result;
        }
    }

    /** @return list<array<string,mixed>> */
    private function listWorkflows(): array
    {
        $cursor = null;
        $all = [];
        $guard = 0;

        do {
            $guard++;
            $url = $this->apiBase . '/workflows?limit=250';
            if (is_string($cursor) && $cursor !== '') {
                $url .= '&cursor=' . rawurlencode($cursor);
            }

            $response = $this->request('GET', $url);
            $code = (int) ($response['http_code'] ?? 0);
            if ($code < 200 || $code >= 300) {
                $message = $this->responseMessage((string) ($response['body'] ?? ''));
                throw new RuntimeException('API do n8n respondeu HTTP ' . $code . ($message !== '' ? ': ' . $message : '.'));
            }

            $payload = json_decode((string) ($response['body'] ?? ''), true);
            if (!is_array($payload) || !is_array($payload['data'] ?? null)) {
                throw new RuntimeException('Resposta inesperada ao listar workflows do n8n.');
            }

            foreach ($payload['data'] as $workflow) {
                if (is_array($workflow)) {
                    $all[] = $workflow;
                }
            }

            $cursor = isset($payload['nextCursor']) && is_string($payload['nextCursor'])
                ? trim($payload['nextCursor'])
                : null;
        } while ($cursor !== null && $cursor !== '' && $guard < 20);

        return $all;
    }

    /** @param list<array<string,mixed>> $workflows */
    private function findOperationsMonitor(array $workflows): ?array
    {
        $aliases = [
            'RS Connect - Monitor operacional',
            'RS Connect — Monitor operacional',
            'Monitor operacional RS Connect',
        ];
        $normalizedAliases = array_map(fn (string $name): string => $this->normalizeName($name), $aliases);

        foreach ($workflows as $workflow) {
            $name = trim((string) ($workflow['name'] ?? ''));
            if ($name !== '' && in_array($this->normalizeName($name), $normalizedAliases, true)) {
                return $workflow;
            }
        }

        foreach ($workflows as $workflow) {
            $normalized = $this->normalizeName((string) ($workflow['name'] ?? ''));
            if (str_contains($normalized, 'rs connect') && str_contains($normalized, 'monitor operacional')) {
                return $workflow;
            }
        }

        return null;
    }

    /** @return array<string,mixed>|null */
    private function workflowDetail(string $workflowId): ?array
    {
        $response = $this->request('GET', $this->apiBase . '/workflows/' . rawurlencode($workflowId));
        $code = (int) ($response['http_code'] ?? 0);
        if ($code < 200 || $code >= 300) {
            return null;
        }
        $payload = json_decode((string) ($response['body'] ?? ''), true);
        return is_array($payload) ? $payload : null;
    }

    /** @param list<array<string,mixed>> $nodes @return list<array{name:string,type:string}> */
    private function scheduleNodes(array $nodes): array
    {
        $supported = [
            'n8n-nodes-base.scheduleTrigger',
            'n8n-nodes-base.cron',
            'n8n-nodes-base.interval',
        ];
        $result = [];
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $type = trim((string) ($node['type'] ?? ''));
            if (in_array($type, $supported, true)) {
                $result[] = [
                    'name' => trim((string) ($node['name'] ?? 'Gatilho de agenda')),
                    'type' => $type,
                ];
            }
        }
        return $result;
    }

    /** @param array<string,mixed> $result */
    private function appendLatestExecution(array &$result, string $workflowId): void
    {
        try {
            $response = $this->request(
                'GET',
                $this->apiBase . '/executions?limit=5&workflowId=' . rawurlencode($workflowId)
            );
            $code = (int) ($response['http_code'] ?? 0);
            if ($code < 200 || $code >= 300) {
                return;
            }
            $payload = json_decode((string) ($response['body'] ?? ''), true);
            if (!is_array($payload) || !is_array($payload['data'] ?? null) || count($payload['data']) < 1) {
                return;
            }
            $last = $payload['data'][0];
            if (!is_array($last)) {
                return;
            }
            $result['last_execution_available'] = true;
            $result['last_execution_status'] = $last['status'] ?? null;
            $result['last_execution_started_at'] = $last['startedAt'] ?? null;
            $result['last_execution_mode'] = $last['mode'] ?? null;
            $result['last_execution_id'] = $last['id'] ?? null;
        } catch (Throwable) {
            // A chave pode não ter execution:list. Isso não impede ativar/publicar o workflow.
        }
    }

    /** @return array{http_code:int,body:string} */
    private function request(string $method, string $url): array
    {
        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('Não foi possível inicializar cURL.');
        }

        $headers = [
            'Accept: application/json',
            'X-N8N-API-KEY: ' . $this->apiKey,
        ];

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_HTTPHEADER => $headers,
        ];

        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_HTTPHEADER] = array_merge($headers, ['Content-Type: application/json']);
            $options[CURLOPT_POSTFIELDS] = '{}';
        } elseif ($method !== 'GET') {
            $options[CURLOPT_CUSTOMREQUEST] = $method;
        }

        curl_setopt_array($curl, $options);
        $body = curl_exec($curl);
        if ($body === false) {
            $error = curl_error($curl);
            curl_close($curl);
            throw new RuntimeException($error !== '' ? $error : 'Erro de comunicação com o n8n.');
        }
        $code = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        return ['http_code' => $code, 'body' => (string) $body];
    }

    private function normalizeName(string $name): string
    {
        $name = str_replace(['—', '–', '_'], '-', trim($name));
        if (function_exists('iconv')) {
            $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
            if (is_string($ascii) && $ascii !== '') {
                $name = $ascii;
            }
        }
        $name = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
        return trim($name);
    }

    private function responseMessage(string $body): string
    {
        $payload = json_decode($body, true);
        if (!is_array($payload)) {
            return '';
        }
        foreach (['message', 'error', 'description'] as $key) {
            if (isset($payload[$key]) && is_scalar($payload[$key])) {
                return trim((string) $payload[$key]);
            }
        }
        return '';
    }
}
