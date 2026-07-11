<?php

declare(strict_types=1);

namespace App\Services;

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
            'number' => preg_replace('/\D+/', '', $phone),
            'text' => $message,
        ];

        return $this->request('POST', $endpoint, $payload);
    }

    /**
     * Solicita conexão da instância e retorna o QR Code/base64 ou pairingCode quando disponível.
     */
    public function connectInstance(): array
    {
        $endpoint = rtrim($this->baseUrl, '/') . '/instance/connect/' . rawurlencode($this->instanceName);

        return $this->request('GET', $endpoint);
    }

    /**
     * Consulta o estado atual da conexão na Evolution API.
     */
    public function connectionState(): array
    {
        $endpoint = rtrim($this->baseUrl, '/') . '/instance/connectionState/' . rawurlencode($this->instanceName);

        return $this->request('GET', $endpoint);
    }

    private function request(string $method, string $url, ?array $payload = null): array
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
            $detail = $body['message'] ?? $body['error'] ?? 'Resposta não aceita pela Evolution API.';
            if (is_array($detail)) {
                $detail = json_encode($detail, JSON_UNESCAPED_UNICODE);
            }
            throw new RuntimeException('HTTP ' . $status . ': ' . $detail);
        }

        return ['status' => $status, 'body' => $body];
    }
}
