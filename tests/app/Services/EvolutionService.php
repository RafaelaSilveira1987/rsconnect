<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Env;
use RuntimeException;

final class EvolutionService
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly string $instanceName,
        private readonly int $timeoutSeconds = 20,
        private readonly bool $verifySsl = true,
        private readonly ?string $caBundle = null,
    ) {
    }

    /**
     * Cria uma instância diretamente na Evolution API.
     * As configurações operacionais e o webhook são aplicados em chamadas
     * separadas para manter compatibilidade entre versões 2.x.
     */
    public function createInstance(
        string $integration = 'WHATSAPP-BAILEYS',
        ?string $phone = null,
        bool $requestQrCode = true
    ): array {
        $endpoint = rtrim($this->baseUrl, '/') . '/instance/create';
        $payload = [
            'instanceName' => trim($this->instanceName),
            'qrcode' => $requestQrCode,
            'integration' => trim($integration) !== '' ? trim($integration) : 'WHATSAPP-BAILEYS',
        ];

        $phone = $phone !== null ? $this->normalizePhone($phone) : '';
        if ($phone !== '') {
            $payload['number'] = $phone;
        }

        return $this->request('POST', $endpoint, $payload, 'createInstance');
    }

    public function sendText(string $phone, string $message): array
    {
        $endpoint = rtrim($this->baseUrl, '/') . '/message/sendText/' . rawurlencode($this->instanceName);
        $payload = [
            'number' => $this->normalizePhone($phone),
            'text' => $message,
        ];

        return $this->request('POST', $endpoint, $payload, 'sendText');
    }

    /**
     * Envia imagem, áudio ou documento pela rota oficial de mídia da Evolution.
     * O campo media aceita base64 puro ou URL; a RS Connect usa base64 para não
     * expor o armazenamento privado ao provedor externo.
     */
    public function sendMedia(
        string $phone,
        string $mediaType,
        string $mimeType,
        string $fileName,
        string $base64,
        string $caption = ''
    ): array {
        $mediaType = strtolower(trim($mediaType));
        if (!in_array($mediaType, ['image', 'audio', 'document', 'video'], true)) {
            throw new RuntimeException('Tipo de mídia não permitido para envio.');
        }

        $endpoint = rtrim($this->baseUrl, '/') . '/message/sendMedia/' . rawurlencode($this->instanceName);
        $payload = [
            'number' => $this->normalizePhone($phone),
            'mediatype' => $mediaType,
            'mimetype' => trim($mimeType),
            'media' => $base64,
            'fileName' => $fileName,
        ];
        if (trim($caption) !== '') {
            $payload['caption'] = $caption;
        }

        return $this->request('POST', $endpoint, $payload, 'sendMedia');
    }

    /**
     * Solicita à Evolution o conteúdo de uma mídia recebida. A resposta varia
     * entre versões; o chamador faz a extração flexível do campo base64.
     *
     * @param array<string,mixed> $message
     */
    public function downloadMediaMessage(array $message, bool $convertToMp4 = false): array
    {
        $endpoint = rtrim($this->baseUrl, '/') . '/chat/getBase64FromMediaMessage/' . rawurlencode($this->instanceName);
        return $this->request('POST', $endpoint, [
            'message' => $message,
            'convertToMp4' => $convertToMp4,
        ], 'getBase64FromMediaMessage');
    }

    public function connectQrCode(): array
    {
        $endpoint = rtrim($this->baseUrl, '/') . '/instance/connect/' . rawurlencode($this->instanceName);
        return $this->request('GET', $endpoint, null, 'connect');
    }

    public function connectionState(): array
    {
        $endpoint = rtrim($this->baseUrl, '/') . '/instance/connectionState/' . rawurlencode($this->instanceName);
        $result = $this->request('GET', $endpoint, null, 'connectionState');
        $body = is_array($result['body'] ?? null) ? $result['body'] : [];
        $state = strtolower(trim((string) ($body['instance']['state'] ?? $body['state'] ?? '')));

        return [
            'status' => (int) ($result['status'] ?? 0),
            'state' => $state,
            'body' => $body,
        ];
    }

    /**
     * @param list<string> $events
     * @param array<string,string> $headers
     */
    public function setWebhook(
        string $url,
        array $events,
        bool $enabled = true,
        bool $webhookByEvents = false,
        bool $base64 = false,
        array $headers = []
    ): array {
        $endpoint = rtrim($this->baseUrl, '/') . '/webhook/set/' . rawurlencode($this->instanceName);
        $webhook = [
            'enabled' => $enabled,
            'url' => $enabled ? trim($url) : '',
            'webhookByEvents' => $webhookByEvents,
            'webhookBase64' => $base64,
            'base64' => $base64,
            'events' => array_values(array_unique(array_filter(array_map(
                static fn (mixed $event): string => strtoupper(trim((string) $event)),
                $events
            )))),
        ];
        if ($headers !== []) {
            $webhook['headers'] = $headers;
        }

        return $this->request('POST', $endpoint, ['webhook' => $webhook], 'setWebhook');
    }

    public function findWebhook(): array
    {
        $endpoint = rtrim($this->baseUrl, '/') . '/webhook/find/' . rawurlencode($this->instanceName);
        return $this->request('GET', $endpoint, null, 'findWebhook');
    }

    /** @param array<string,mixed> $settings */
    public function setSettings(array $settings): array
    {
        $allowed = [
            'rejectCall',
            'msgCall',
            'groupsIgnore',
            'alwaysOnline',
            'readMessages',
            'readStatus',
            'syncFullHistory',
        ];
        $payload = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $settings)) {
                $payload[$key] = $settings[$key];
            }
        }

        $endpoint = rtrim($this->baseUrl, '/') . '/settings/set/' . rawurlencode($this->instanceName);
        return $this->request('POST', $endpoint, $payload, 'setSettings');
    }

    public function findSettings(): array
    {
        $endpoint = rtrim($this->baseUrl, '/') . '/settings/find/' . rawurlencode($this->instanceName);
        return $this->request('GET', $endpoint, null, 'findSettings');
    }

    public function restartInstance(): array
    {
        $endpoint = rtrim($this->baseUrl, '/') . '/instance/restart/' . rawurlencode($this->instanceName);
        try {
            return $this->request('PUT', $endpoint, null, 'restart');
        } catch (RuntimeException $exception) {
            if (!str_contains($exception->getMessage(), 'HTTP 404') && !str_contains($exception->getMessage(), 'HTTP 405')) {
                throw $exception;
            }
            return $this->request('POST', $endpoint, [], 'restart');
        }
    }

    public function logoutInstance(): array
    {
        $endpoint = rtrim($this->baseUrl, '/') . '/instance/logout/' . rawurlencode($this->instanceName);
        return $this->request('DELETE', $endpoint, null, 'logout');
    }

    public function deleteInstance(): array
    {
        $endpoint = rtrim($this->baseUrl, '/') . '/instance/delete/' . rawurlencode($this->instanceName);
        return $this->request('DELETE', $endpoint, null, 'deleteInstance');
    }

    public function fetchProfilePictureUrl(string $phone): ?string
    {
        $endpoint = rtrim($this->baseUrl, '/') . '/chat/fetchProfilePictureUrl/' . rawurlencode($this->instanceName);
        $result = $this->request('POST', $endpoint, [
            'number' => $this->normalizePhone($phone),
        ], 'fetchProfilePictureUrl');
        $body = is_array($result['body'] ?? null) ? $result['body'] : [];
        $url = $this->extractProfilePictureUrl($body);

        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return null;
        }

        return mb_substr($url, 0, 500);
    }

    /** @param array<string|int,mixed> $payload */
    private function extractProfilePictureUrl(array $payload): string
    {
        foreach (['profilePictureUrl', 'profilePicUrl', 'url'] as $key) {
            $candidate = $payload[$key] ?? null;
            if (is_string($candidate) && preg_match('#^https?://#i', trim($candidate))) {
                return trim($candidate);
            }
        }

        foreach (['data', 'response', 'result', 'contact'] as $key) {
            $nested = $payload[$key] ?? null;
            if (is_array($nested)) {
                $url = $this->extractProfilePictureUrl($nested);
                if ($url !== '') {
                    return $url;
                }
            }
        }

        if (array_is_list($payload)) {
            foreach ($payload as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $url = $this->extractProfilePictureUrl($item);
                if ($url !== '') {
                    return $url;
                }
            }
        }

        return '';
    }

    /** @param array<string,mixed> $body */
    public static function extractQrCode(array $body): string
    {
        $candidates = [
            $body['base64'] ?? null,
            $body['qrCode'] ?? null,
            $body['qrcode']['base64'] ?? null,
            $body['qrcode']['code'] ?? null,
            $body['instance']['qrcode']['base64'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $value = trim((string) $candidate);
            if ($value === '') {
                continue;
            }
            if (str_starts_with($value, 'data:image/')) {
                return $value;
            }
            if (preg_match('/^[A-Za-z0-9+\/=\r\n]+$/', $value) === 1 && strlen($value) > 500) {
                return 'data:image/png;base64,' . preg_replace('/\s+/', '', $value);
            }
        }

        return '';
    }

    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?: '';
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        $countryCode = preg_replace('/\D+/', '', (string) Env::get('DEFAULT_COUNTRY_CODE', '55')) ?: '55';
        if ($countryCode === '55' && in_array(strlen($digits), [10, 11], true)) {
            $digits = '55' . $digits;
        }

        return $digits;
    }

    private function request(string $method, string $url, ?array $payload = null, string $operation = 'request'): array
    {
        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('Não foi possível iniciar o cURL.');
        }

        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'apikey: ' . $this->apiKey,
            ],
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
        ];

        if ($payload !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        }

        if ($this->verifySsl && $this->caBundle !== null && $this->caBundle !== '') {
            if (!is_file($this->caBundle) || !is_readable($this->caBundle)) {
                curl_close($curl);
                throw new RuntimeException(
                    'O arquivo configurado em EVOLUTION_CA_BUNDLE não existe ou não pode ser lido: ' . $this->caBundle
                );
            }

            $options[CURLOPT_CAINFO] = $this->caBundle;
        }

        curl_setopt_array($curl, $options);

        $response = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        $errorNumber = curl_errno($curl);
        curl_close($curl);

        if ($response === false) {
            $sslHint = in_array($errorNumber, [CURLE_PEER_FAILED_VERIFICATION, CURLE_SSL_CACERT, CURLE_SSL_CONNECT_ERROR], true)
                || str_contains(strtolower($error), 'certificate');

            if ($sslHint) {
                throw new RuntimeException(
                    'Falha ao validar o certificado SSL da Evolution API. ' .
                    'Configure EVOLUTION_CA_BUNDLE com o caminho do cacert.pem ou, apenas no localhost, ' .
                    'use EVOLUTION_SSL_VERIFY=false. Detalhe do cURL: ' . $error
                );
            }

            throw new RuntimeException('Erro de conexão com a Evolution API: ' . $error);
        }

        $decoded = json_decode($response, true);
        $body = is_array($decoded) ? $decoded : ['raw' => $response];

        if ($status < 200 || $status >= 300) {
            $detail = $body['message'] ?? $body['error'] ?? $body['response']['message'] ?? $body['raw'] ?? 'Resposta não aceita pela Evolution API.';
            if (is_array($detail)) {
                $detail = json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $detailText = trim((string) $detail);
            if ($detailText === '' || strtolower($detailText) === 'bad request') {
                $rawPreview = trim((string) ($body['raw'] ?? $response));
                if ($rawPreview !== '' && strtolower($rawPreview) !== 'bad request') {
                    $detailText .= ($detailText !== '' ? ' — ' : '') . mb_substr($rawPreview, 0, 450);
                }
            }
            throw new RuntimeException('Evolution ' . $operation . ' HTTP ' . $status . ': ' . ($detailText !== '' ? $detailText : 'requisição recusada.'));
        }

        return ['status' => $status, 'body' => $body];
    }
}
