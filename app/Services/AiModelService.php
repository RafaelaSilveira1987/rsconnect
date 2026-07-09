<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Env;
use RuntimeException;

final class AiModelService
{
    public function generateReply(array $agent, array $messages, array $contact, array $conversation): string
    {
        $provider = strtolower((string) ($agent['model_provider'] ?? 'google'));

        return match ($provider) {
            'openai' => $this->generateWithOpenAi($agent, $messages, $contact, $conversation),
            'google' => $this->generateWithGemini($agent, $messages, $contact, $conversation),
            default => throw new RuntimeException('Provedor de IA ainda não implementado: ' . $provider),
        };
    }

    private function generateWithOpenAi(array $agent, array $messages, array $contact, array $conversation): string
    {
        $apiKey = trim((string) Env::get('OPENAI_API_KEY', ''));
        if ($apiKey === '') {
            throw new RuntimeException('Configure OPENAI_API_KEY no ambiente para ativar respostas automáticas com OpenAI.');
        }

        $model = trim((string) ($agent['model_name'] ?? 'gpt-4o-mini')) ?: 'gpt-4o-mini';
        $endpointBase = rtrim((string) Env::get('OPENAI_API_BASE_URL', 'https://api.openai.com/v1'), '/');
        $url = $endpointBase . '/responses';

        $systemPrompt = $this->buildSystemPrompt($agent, $contact, $conversation);
        $input = $this->buildOpenAiInput($messages);

        if ($input === []) {
            throw new RuntimeException('Sem mensagens suficientes para gerar resposta.');
        }

        $payload = [
            'model' => $model,
            'instructions' => $systemPrompt,
            'input' => $input,
            'temperature' => (float) ($agent['temperature'] ?? 0.2),
            'max_output_tokens' => (int) Env::get('AI_MAX_OUTPUT_TOKENS', 420),
        ];

        $response = $this->postJson($url, $payload, [
            'Authorization: Bearer ' . $apiKey,
        ]);

        $text = $this->extractOpenAiText($response);
        if ($text === '') {
            throw new RuntimeException('A OpenAI não retornou texto.');
        }

        return mb_substr($text, 0, (int) Env::get('AI_MAX_REPLY_CHARS', 1400));
    }

    private function generateWithGemini(array $agent, array $messages, array $contact, array $conversation): string
    {
        $apiKey = trim((string) Env::get('GEMINI_API_KEY', Env::get('GOOGLE_GEMINI_API_KEY', '')));
        if ($apiKey === '') {
            throw new RuntimeException('Configure GEMINI_API_KEY no ambiente para ativar respostas automáticas.');
        }

        $model = trim((string) ($agent['model_name'] ?? 'gemini-2.0-flash')) ?: 'gemini-2.0-flash';
        $endpointBase = rtrim((string) Env::get('GEMINI_API_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'), '/');
        $url = $endpointBase . '/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($apiKey);

        $systemPrompt = $this->buildSystemPrompt($agent, $contact, $conversation);
        $contents = $this->buildGeminiContents($messages);

        if ($contents === []) {
            throw new RuntimeException('Sem mensagens suficientes para gerar resposta.');
        }

        $payload = [
            'systemInstruction' => [
                'parts' => [
                    ['text' => $systemPrompt],
                ],
            ],
            'contents' => $contents,
            'generationConfig' => [
                'temperature' => (float) ($agent['temperature'] ?? 0.2),
                'maxOutputTokens' => (int) Env::get('AI_MAX_OUTPUT_TOKENS', 420),
            ],
        ];

        $response = $this->postJson($url, $payload, []);
        $text = $response['candidates'][0]['content']['parts'][0]['text'] ?? '';
        $text = trim((string) $text);

        if ($text === '') {
            throw new RuntimeException('A IA não retornou texto.');
        }

        return mb_substr($text, 0, (int) Env::get('AI_MAX_REPLY_CHARS', 1400));
    }

    private function buildSystemPrompt(array $agent, array $contact, array $conversation): string
    {
        $base = trim((string) ($agent['system_prompt'] ?? ''));
        $knowledge = trim((string) ($agent['knowledge_base'] ?? ''));
        $contactName = trim((string) ($contact['name'] ?? $conversation['contact_name'] ?? ''));
        $contactPhone = trim((string) ($contact['phone'] ?? $conversation['phone'] ?? ''));

        $rules = [
            'Responda sempre em português do Brasil.',
            'Seja breve, educada e objetiva. Evite textos longos.',
            'Não invente preço, prazo, disponibilidade, política ou informação que não esteja no prompt/base.',
            'Se a pergunta exigir decisão humana, peça uma confirmação e diga que encaminhará para atendimento.',
            'Não mencione que você é um modelo de linguagem.',
        ];

        return trim($base . "\n\nContexto do contato:\n" .
            '- Nome: ' . ($contactName !== '' ? $contactName : 'não informado') . "\n" .
            '- Telefone: ' . ($contactPhone !== '' ? $contactPhone : 'não informado') . "\n\n" .
            ($knowledge !== '' ? "Base de conhecimento:\n" . $knowledge . "\n\n" : '') .
            "Regras obrigatórias:\n- " . implode("\n- ", $rules));
    }

    private function buildOpenAiInput(array $messages): array
    {
        $input = [];
        foreach ($messages as $message) {
            $content = trim((string) ($message['content'] ?? ''));
            if ($content === '') {
                continue;
            }

            $senderType = (string) ($message['sender_type'] ?? 'contact');
            $direction = (string) ($message['direction'] ?? 'incoming');
            $role = ($senderType === 'ai' || $direction === 'outgoing') ? 'assistant' : 'user';

            $input[] = [
                'role' => $role,
                'content' => [
                    [
                        'type' => 'input_text',
                        'text' => $content,
                    ],
                ],
            ];
        }

        return $input;
    }

    private function buildGeminiContents(array $messages): array
    {
        $contents = [];
        foreach ($messages as $message) {
            $content = trim((string) ($message['content'] ?? ''));
            if ($content === '') {
                continue;
            }

            $senderType = (string) ($message['sender_type'] ?? 'contact');
            $direction = (string) ($message['direction'] ?? 'incoming');
            $role = ($senderType === 'ai' || $direction === 'outgoing') ? 'model' : 'user';
            $contents[] = [
                'role' => $role,
                'parts' => [['text' => $content]],
            ];
        }

        return $contents;
    }

    private function extractOpenAiText(array $response): string
    {
        $direct = trim((string) ($response['output_text'] ?? ''));
        if ($direct !== '') {
            return $direct;
        }

        $parts = [];
        foreach (($response['output'] ?? []) as $outputItem) {
            foreach (($outputItem['content'] ?? []) as $contentItem) {
                $text = $contentItem['text'] ?? $contentItem['content'] ?? null;
                if (is_string($text) && trim($text) !== '') {
                    $parts[] = trim($text);
                }
            }
        }

        return trim(implode("\n", $parts));
    }

    private function postJson(string $url, array $payload, array $headers): array
    {
        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('Não foi possível iniciar cURL para IA.');
        }

        $timeout = (int) Env::get('AI_HTTP_TIMEOUT', 28);
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Accept: application/json';

        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);

        $raw = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($raw === false) {
            throw new RuntimeException('Erro de conexão com IA: ' . $error);
        }

        $decoded = json_decode((string) $raw, true);
        $body = is_array($decoded) ? $decoded : ['raw' => $raw];

        if ($status < 200 || $status >= 300) {
            $detail = $body['error']['message'] ?? $body['message'] ?? $body['raw'] ?? 'Resposta não aceita pelo provedor de IA.';
            if (is_array($detail)) {
                $detail = json_encode($detail, JSON_UNESCAPED_UNICODE);
            }
            throw new RuntimeException('IA HTTP ' . $status . ': ' . mb_substr((string) $detail, 0, 500));
        }

        return $body;
    }
}
