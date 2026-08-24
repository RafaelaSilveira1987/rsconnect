<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Crypto;
use App\Core\Database;
use App\Core\Env;
use RuntimeException;
use Throwable;

final class AiModelService
{
    /** @var array{input_tokens:?int,output_tokens:?int,total_tokens:?int,cached_tokens:?int,provider_calls:int,provider:?string,model:?string} */
    private array $lastUsage = [
        'input_tokens' => null,
        'output_tokens' => null,
        'total_tokens' => null,
        'cached_tokens' => null,
        'provider_calls' => 0,
        'provider' => null,
        'model' => null,
    ];

    public function generateReply(array $agent, array $messages, array $contact, array $conversation): string
    {
        $provider = $this->provider($agent);
        $this->lastUsage = [
            'input_tokens' => null,
            'output_tokens' => null,
            'total_tokens' => null,
            'cached_tokens' => null,
            'provider_calls' => 0,
            'provider' => $provider,
            'model' => null,
        ];

        return match ($provider) {
            'openai' => $this->generateWithOpenAi($agent, $messages, $contact, $conversation),
            'google' => $this->generateWithGemini($agent, $messages, $contact, $conversation),
            default => throw new RuntimeException('Provedor de IA ainda não implementado: ' . $provider),
        };
    }

    private function generateWithOpenAi(array $agent, array $messages, array $contact, array $conversation): string
    {
        $apiKey = $this->apiKey($agent, 'openai');
        if ($apiKey === '') {
            throw new RuntimeException('Configure OPENAI_API_KEY no ambiente ou uma credencial OpenAI no painel RS.');
        }

        $model = $this->model($agent, 'gpt-4o-mini');
        $endpointBase = $this->baseUrl($agent, 'OPENAI_API_BASE_URL', 'https://api.openai.com/v1');
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
            'max_output_tokens' => max(64, min(2000, (int) ($agent['_ai_max_output_tokens'] ?? Env::get('AI_MAX_OUTPUT_TOKENS', 420)))),
        ];

        // A chamada é contabilizada tecnicamente mesmo que o provedor responda com erro.
        // Isso não consome franquia comercial: a franquia só é confirmada após a entrega ao cliente.
        $this->lastUsage['provider_calls'] = 1;
        $this->lastUsage['model'] = $model;
        $response = $this->postJson($url, $payload, [
            'Authorization: Bearer ' . $apiKey,
        ]);
        $usage = is_array($response['usage'] ?? null) ? $response['usage'] : [];
        $inputDetails = is_array($usage['input_tokens_details'] ?? null) ? $usage['input_tokens_details'] : [];
        $this->lastUsage = [
            'input_tokens' => isset($usage['input_tokens']) ? max(0, (int) $usage['input_tokens']) : null,
            'output_tokens' => isset($usage['output_tokens']) ? max(0, (int) $usage['output_tokens']) : null,
            'total_tokens' => isset($usage['total_tokens']) ? max(0, (int) $usage['total_tokens']) : null,
            'cached_tokens' => isset($inputDetails['cached_tokens']) ? max(0, (int) $inputDetails['cached_tokens']) : null,
            'provider_calls' => 1,
            'provider' => 'openai',
            'model' => $model,
        ];

        $text = $this->extractOpenAiText($response);
        if ($text === '') {
            throw new RuntimeException('A OpenAI não retornou texto.');
        }

        return mb_substr($text, 0, (int) Env::get('AI_MAX_REPLY_CHARS', 1400));
    }

    private function generateWithGemini(array $agent, array $messages, array $contact, array $conversation): string
    {
        $apiKey = $this->apiKey($agent, 'google');
        if ($apiKey === '') {
            throw new RuntimeException('Configure GEMINI_API_KEY no ambiente ou uma credencial Gemini no painel RS.');
        }

        $model = $this->model($agent, 'gemini-2.0-flash');
        $endpointBase = $this->baseUrl($agent, 'GEMINI_API_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta');
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
                'maxOutputTokens' => max(64, min(2000, (int) ($agent['_ai_max_output_tokens'] ?? Env::get('AI_MAX_OUTPUT_TOKENS', 420)))),
            ],
        ];

        $this->lastUsage['provider_calls'] = 1;
        $this->lastUsage['model'] = $model;
        $response = $this->postJson($url, $payload, []);
        $usage = is_array($response['usageMetadata'] ?? null) ? $response['usageMetadata'] : [];
        $inputTokens = isset($usage['promptTokenCount']) ? max(0, (int) $usage['promptTokenCount']) : null;
        $outputTokens = isset($usage['candidatesTokenCount']) ? max(0, (int) $usage['candidatesTokenCount']) : null;
        $totalTokens = isset($usage['totalTokenCount']) ? max(0, (int) $usage['totalTokenCount']) : null;
        $cachedTokens = isset($usage['cachedContentTokenCount']) ? max(0, (int) $usage['cachedContentTokenCount']) : null;
        $this->lastUsage = [
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'total_tokens' => $totalTokens,
            'cached_tokens' => $cachedTokens,
            'provider_calls' => 1,
            'provider' => 'google',
            'model' => $model,
        ];
        $text = $response['candidates'][0]['content']['parts'][0]['text'] ?? '';
        $text = trim((string) $text);

        if ($text === '') {
            throw new RuntimeException('A IA não retornou texto.');
        }

        return mb_substr($text, 0, (int) Env::get('AI_MAX_REPLY_CHARS', 1400));
    }

    /** @return array{input_tokens:?int,output_tokens:?int,total_tokens:?int,cached_tokens:?int,provider_calls:int,provider:?string,model:?string} */
    public function lastUsage(): array
    {
        return $this->lastUsage;
    }

    private function provider(array $agent): string
    {
        $credentialProvider = trim((string) ($agent['credential_provider'] ?? ''));
        $provider = $credentialProvider !== '' ? $credentialProvider : (string) ($agent['model_provider'] ?? 'google');
        return strtolower($provider);
    }

    private function model(array $agent, string $fallback): string
    {
        $routedModel = trim((string) ($agent['_ai_selected_model'] ?? ''));
        if ($routedModel !== '') {
            return $routedModel;
        }

        $credentialModel = trim((string) ($agent['credential_default_model'] ?? ''));
        if ($credentialModel !== '') {
            return $credentialModel;
        }

        $agentModel = trim((string) ($agent['model_name'] ?? ''));
        return $agentModel !== '' ? $agentModel : $fallback;
    }

    private function baseUrl(array $agent, string $envKey, string $fallback): string
    {
        $credentialBaseUrl = trim((string) ($agent['credential_base_url'] ?? ''));
        if ($credentialBaseUrl !== '') {
            return rtrim($credentialBaseUrl, '/');
        }

        return rtrim((string) Env::get($envKey, $fallback), '/');
    }

    private function apiKey(array $agent, string $provider): string
    {
        $encrypted = trim((string) ($agent['credential_api_key_encrypted'] ?? ''));
        if ($encrypted !== '') {
            try {
                return trim(Crypto::decrypt($encrypted));
            } catch (Throwable) {
                throw new RuntimeException('Não foi possível descriptografar a credencial de IA do cliente/agente. Confira a APP_KEY.');
            }
        }

        return match ($provider) {
            'openai' => trim((string) Env::get('OPENAI_API_KEY', '')),
            'google' => trim((string) Env::get('GEMINI_API_KEY', Env::get('GOOGLE_GEMINI_API_KEY', ''))),
            default => '',
        };
    }

    private function buildSystemPrompt(array $agent, array $contact, array $conversation): string
    {
        $base = trim((string) ($agent['system_prompt'] ?? ''));
        $knowledge = trim((string) ($agent['knowledge_base'] ?? ''));
        $contactName = trim((string) ($contact['name'] ?? $conversation['contact_name'] ?? ''));
        $contactPhone = trim((string) ($contact['phone'] ?? $conversation['phone'] ?? ''));
        $timezone = trim((string) ($agent['business_timezone'] ?? Env::get('APP_TIMEZONE', 'America/Sao_Paulo')));

        $group = trim((string) ($conversation['contact_group'] ?? $contact['contact_group'] ?? 'unclassified')) ?: 'unclassified';
        $groupLabel = ConversationFlowService::GROUPS[$group] ?? 'Outro grupo';
        $contactStatus = trim((string) ($conversation['contact_status'] ?? $contact['contact_status'] ?? $contact['status'] ?? ''));
        $contactStatusLabel = $this->contactStatusLabel($contactStatus);
        $tagsRaw = $conversation['tags_json'] ?? $contact['tags_json'] ?? null;
        $tags = is_array($tagsRaw) ? $tagsRaw : json_decode((string) $tagsRaw, true);
        $tags = is_array($tags)
            ? array_values(array_unique(array_filter(array_map(static fn ($tag): string => trim((string) $tag), $tags))))
            : [];
        $tagsText = $tags !== [] ? implode(', ', $tags) : 'nenhuma';
        $tagFacts = $this->tagFacts($tags);
        $isExistingCustomer = $contactStatus === 'customer'
            || in_array($group, ['customer', 'patient'], true)
            || $this->hasAnyNormalizedTag($tags, ['cliente', 'customer', 'client', 'paciente', 'paciente atual']);
        $flowStage = trim((string) ($conversation['flow_stage'] ?? 'identifying_contact')) ?: 'identifying_contact';
        $demandStatus = trim((string) ($conversation['demand_status'] ?? 'pending')) ?: 'pending';
        $demandSummary = trim((string) ($conversation['demand_summary'] ?? ''));
        $lastIntent = trim((string) ($conversation['last_intent'] ?? ''));
        $agendaContextActive = in_array($lastIntent, ['schedule', 'reschedule'], true)
            || in_array($flowStage, ['scheduling', 'awaiting_approval'], true);
        $contactCompany = trim((string) ($contact['company'] ?? $conversation['company'] ?? ''));
        $contactNotes = trim((string) ($contact['notes'] ?? $conversation['notes'] ?? ''));
        $flowStageLabel = ConversationFlowService::STAGES[$flowStage] ?? $flowStage;
        $demandStatusLabel = ConversationFlowService::DEMAND_STATUSES[$demandStatus] ?? $demandStatus;

        $rules = [
            'Responda sempre em português do Brasil.',
            'Seja breve, educada e objetiva. Evite textos longos.',
            'Faça somente uma pergunta por mensagem.',
            'Não invente preço, prazo, disponibilidade, política ou informação que não esteja no prompt/base.',
            'Não pergunte novamente informações que já estejam no histórico, no cadastro do contato ou no resumo da demanda.',
            'Se a pergunta exigir decisão humana, peça uma confirmação e diga que encaminhará para atendimento.',
            'Não mencione que você é um modelo de linguagem.',
            'Se o lead pedir humano, atendente, suporte ou uma pessoa, sinalize transferência em vez de insistir no atendimento automático.',
            'Não transforme menções casuais de data, hora, hoje, amanhã, tarde ou noite em pedido de agendamento. Agenda só deve ser conduzida quando houver intenção real e explícita de marcar, remarcar, consultar disponibilidade ou quando a conversa já estiver em um fluxo recente de agenda.',
            'Cliente ou paciente já identificado deve ter continuidade de atendimento: não reabra triagem, não peça novamente motivo/queixa e não trate como novo lead apenas porque iniciou uma nova conversa.',
            'O contexto operacional fornecido pelo RS Connect (modo da conversa, horário, classificação, grupo e tags) tem prioridade sobre instruções conflitantes do prompt livre.',
        ];

        $tenantId = (int) ($conversation['tenant_id'] ?? $contact['tenant_id'] ?? 0);
        $preScheduleBlock = '';
        if ($tenantId > 0) {
            $preScheduling = new PreSchedulingService();
            if ($agendaContextActive && $preScheduling->isEnabled($tenantId)) {
                $settings = $preScheduling->settings($tenantId);
                $rules[] = 'A conversa está em contexto real de agenda. Antes de conduzir ao pré-agendamento, siga a regra do grupo informada abaixo; quando ela exigir demanda, confirme que foi coletada ou recusada.';
                $rules[] = 'Quando o contato estiver liberado pelas regras do grupo e do fluxo e demonstrar intenção real de agendar, colete dia/período/horário preferido e modalidade. Não confirme horário, não diga que está marcado e não prometa link.';
                $rules[] = 'Se o contato ainda não informou dia ou horário depois de estar liberado para agenda, use a mensagem de coleta configurada pelo cliente, adaptando somente o nome se necessário.';
                $rules[] = 'Se o contato informou preferência de dia ou horário, use a mensagem de registro configurada pelo cliente e deixe claro que depende de confirmação humana.';
                $preScheduleBlock = "Configurações de pré-agendamento do cliente:\n" .
                    '- Mensagem para coletar dia/horário: ' . (string) ($settings['collect_message'] ?? '') . "\n" .
                    '- Mensagem após registrar preferência: ' . (string) ($settings['default_message'] ?? '') . "\n" .
                    '- IA pode confirmar sozinha: ' . (!empty($settings['ai_can_confirm']) ? 'sim' : 'não') . "\n" .
                    '- Aprovação humana obrigatória: ' . (!empty($settings['require_human_approval']) ? 'sim' : 'não') . "\n\n";
            }
        }

        $groupRule = [];
        try {
            $groupRule = (new ConversationFlowService())->ruleForAgent(
                Database::connection(),
                $tenantId,
                (int) ($agent['id'] ?? 0),
                $group
            );
        } catch (Throwable) {
            $groupRule = [];
        }
        if ($isExistingCustomer) {
            // Cadastro de cliente/paciente prevalece sobre regra antiga gravada no banco.
            // Evita que uma configuração histórica reabra qualificação de quem já é cliente.
            $groupRule['require_demand_before_pre_schedule'] = false;
        }
        $groupInstructions = trim((string) ($groupRule['instructions'] ?? ''));
        $groupRuleBlock = '';
        if ($agendaContextActive) {
            $groupRuleBlock = "Regras do grupo de contato para agenda:\n" .
                '- Grupo: ' . $groupLabel . "\n" .
                '- Pré-agendamento permitido: ' . (!empty($groupRule['allow_pre_schedule']) ? 'sim' : 'não') . "\n" .
                '- Exigir demanda antes do pré-agendamento: ' . (!empty($groupRule['require_demand_before_pre_schedule']) ? 'sim' : 'não') . "\n" .
                '- Remarcação sem repetir a demanda: ' . (!empty($groupRule['allow_reschedule_without_demand']) ? 'sim' : 'não') . "\n" .
                ($groupInstructions !== '' ? '- Orientação específica: ' . $groupInstructions . "\n" : '') . "\n";
        }

        $structuredContext = "CONTEXTO CADASTRAL PRIORITÁRIO DO RS CONNECT (fonte de verdade):
" .
            '- Nome: ' . ($contactName !== '' ? $contactName : 'não informado') . "
" .
            '- Telefone: ' . ($contactPhone !== '' ? $contactPhone : 'não informado') . "
" .
            '- Empresa/atividade: ' . ($contactCompany !== '' ? $contactCompany : 'não informada') . "
" .
            '- Classificação: ' . $contactStatusLabel . ($contactStatus !== '' ? ' (código: ' . $contactStatus . ')' : '') . "
" .
            '- Relacionamento atual: ' . ($isExistingCustomer ? 'já é cliente/paciente da empresa' : 'não confirmado como cliente atual') . "
" .
            '- Grupo de atendimento: ' . $groupLabel . "
" .
            '- Tags cadastradas: ' . $tagsText . "
" .
            ($tagFacts !== [] ? '- Fatos derivados das tags: ' . implode('; ', $tagFacts) . "
" : '') .
            '- Etapa atual: ' . $flowStageLabel . "
" .
            '- Situação da demanda: ' . $demandStatusLabel . "
" .
            '- Resumo da demanda: ' . ($demandSummary !== '' ? $demandSummary : 'ainda não registrado') . "
" .
            '- Última intenção detectada: ' . ($lastIntent !== '' ? $lastIntent : 'conversa geral') . "
" .
            ($contactNotes !== '' ? '- Observações cadastradas: ' . mb_substr($contactNotes, 0, 1200) . "
" : '') .
            '- Fuso de atendimento: ' . ($timezone !== '' ? $timezone : 'não informado') . "

" .
            "COMO USAR ESTE CONTEXTO:
" .
            "- Trate classificação, grupo e tags como informações já conhecidas e válidas.
" .
            "- Não pergunte novamente se a pessoa é cliente, paciente, interessada ou pertence a um grupo quando isso já estiver indicado acima.
" .
            "- Se a classificação for Cliente ou o grupo indicar Cliente/Paciente atual, fale com a pessoa como relacionamento já existente, sem reiniciar o fluxo de novo interessado.
" .
            "- Para cliente/paciente atual, NÃO peça motivo do atendimento, principal queixa ou nova qualificação como pré-condição para responder uma dúvida, consultar agenda, marcar ou remarcar horário. Responda diretamente ao pedido atual usando cadastro e histórico.
" .
            "- Use as tags para personalizar a resposta e respeitar segmentações, mas não invente significado além do texto da tag.
" .
            ($agendaContextActive
                ? "- Esta conversa está em contexto de agenda. As regras do Grupo de atendimento têm prioridade para agenda e pré-agendamento; tags não liberam agenda quando a regra do grupo bloquear.\n\n"
                : "- Esta conversa NÃO está em contexto de agenda. Não conduza o atendimento para agenda por iniciativa própria; siga o objetivo e o fluxo descritos no prompt do agente.\n\n");

        return trim($base . "

" .
            $structuredContext .
            ($knowledge !== '' ? "Base de conhecimento:
" . $knowledge . "

" : '') .
            $groupRuleBlock .
            $preScheduleBlock .
            "Regras obrigatórias:
- " . implode("
- ", $rules));
    }

    private function contactStatusLabel(string $status): string
    {
        return match ($status) {
            'lead' => 'Lead / novo contato',
            'customer' => 'Cliente atual',
            'inactive' => 'Contato inativo',
            '' => 'Não informada',
            default => $status,
        };
    }

    /** @param array<int, string> $tags */
    private function tagFacts(array $tags): array
    {
        $facts = [];
        foreach ($tags as $tag) {
            $normalized = $this->normalizeTag($tag);
            if ($normalized === '') {
                continue;
            }
            if (in_array($normalized, ['cliente', 'customer', 'client'], true)) {
                $facts[] = 'o contato está identificado como cliente';
            } elseif (str_contains($normalized, 'paciente')) {
                $facts[] = 'o contato está identificado como paciente';
            } elseif (str_contains($normalized, 'interessad') || $normalized === 'lead' || str_contains($normalized, 'prospect')) {
                $facts[] = 'o contato está identificado como novo interessado';
            } elseif (str_contains($normalized, 'familiar') || str_contains($normalized, 'familia')) {
                $facts[] = 'o contato está relacionado a um familiar';
            } elseif (str_contains($normalized, 'casal')) {
                $facts[] = 'o contato está relacionado a atendimento de casal';
            } elseif (str_contains($normalized, 'prioridade') || str_contains($normalized, 'urgente')) {
                $facts[] = 'o contato possui marcação de prioridade';
            }
        }
        return array_values(array_unique($facts));
    }

    /** @param array<int, string> $tags @param array<int, string> $needles */
    private function hasAnyNormalizedTag(array $tags, array $needles): bool
    {
        $normalizedNeedles = array_map([$this, 'normalizeTag'], $needles);
        foreach ($tags as $tag) {
            $normalized = $this->normalizeTag($tag);
            if (in_array($normalized, $normalizedNeedles, true)) {
                return true;
            }
        }
        return false;
    }

    private function normalizeTag(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = strtr($value, [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a',
            'é' => 'e', 'ê' => 'e', 'í' => 'i',
            'ó' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ú' => 'u', 'ü' => 'u', 'ç' => 'c',
        ]);
        $normalized = preg_replace('/[^a-z0-9]+/u', ' ', $value);
        return trim((string) $normalized);
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
                'content' => $content,
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
