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

    public function sendText(string $phone, string $message): array
    {
        $endpoint = rtrim($this->baseUrl, '/') . '/message/sendText/' . rawurlencode($this->instanceName);
        $payload = [
            'number' => $this->normalizePhone($phone),
            'text' => $message,
        ];

        return $this->request('POST', $endpoint, $payload, 'sendText');
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
