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

        $text = $this->applyCurrentTurnContinuityGuard($text, $agent, $contact);

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

        $text = $this->applyCurrentTurnContinuityGuard($text, $agent, $contact);

        return mb_substr($text, 0, (int) Env::get('AI_MAX_REPLY_CHARS', 1400));
    }

    /**
     * Executa uma tarefa curta de bastidor sem carregar prompt comercial, base de conhecimento
     * ou histórico completo. Usado para memória progressiva e extrações estruturadas.
     */
    public function generateCompactTask(array $agent, string $instructions, string $input, int $maxOutputTokens = 280): string
    {
        $provider = $this->provider($agent);
        $maxOutputTokens = max(96, min(700, $maxOutputTokens));
        $this->lastUsage = [
            'input_tokens' => null,
            'output_tokens' => null,
            'total_tokens' => null,
            'cached_tokens' => null,
            'provider_calls' => 1,
            'provider' => $provider,
            'model' => null,
        ];

        if ($provider === 'openai') {
            $apiKey = $this->apiKey($agent, 'openai');
            if ($apiKey === '') {
                throw new RuntimeException('Configure uma credencial OpenAI para atualizar a memória da conversa.');
            }
            $configuredModel = trim((string) Env::get('AI_MEMORY_MODEL_OPENAI', ''));
            $model = $configuredModel !== '' ? $configuredModel : $this->model($agent, 'gpt-4o-mini');
            $url = $this->baseUrl($agent, 'OPENAI_API_BASE_URL', 'https://api.openai.com/v1') . '/responses';
            $response = $this->postJson($url, [
                'model' => $model,
                'instructions' => trim($instructions),
                'input' => [['role' => 'user', 'content' => trim($input)]],
                'temperature' => 0.1,
                'max_output_tokens' => $maxOutputTokens,
            ], ['Authorization: Bearer ' . $apiKey]);
            $usage = is_array($response['usage'] ?? null) ? $response['usage'] : [];
            $details = is_array($usage['input_tokens_details'] ?? null) ? $usage['input_tokens_details'] : [];
            $this->lastUsage = [
                'input_tokens' => isset($usage['input_tokens']) ? max(0, (int) $usage['input_tokens']) : null,
                'output_tokens' => isset($usage['output_tokens']) ? max(0, (int) $usage['output_tokens']) : null,
                'total_tokens' => isset($usage['total_tokens']) ? max(0, (int) $usage['total_tokens']) : null,
                'cached_tokens' => isset($details['cached_tokens']) ? max(0, (int) $details['cached_tokens']) : null,
                'provider_calls' => 1,
                'provider' => 'openai',
                'model' => $model,
            ];
            $text = $this->extractOpenAiText($response);
            if ($text === '') {
                throw new RuntimeException('A OpenAI não retornou conteúdo para a memória da conversa.');
            }
            return trim($text);
        }

        if ($provider === 'google') {
            $apiKey = $this->apiKey($agent, 'google');
            if ($apiKey === '') {
                throw new RuntimeException('Configure uma credencial Gemini para atualizar a memória da conversa.');
            }
            $configuredModel = trim((string) Env::get('AI_MEMORY_MODEL_GOOGLE', ''));
            $model = $configuredModel !== '' ? $configuredModel : $this->model($agent, 'gemini-2.0-flash');
            $url = $this->baseUrl($agent, 'GEMINI_API_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta')
                . '/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($apiKey);
            $response = $this->postJson($url, [
                'systemInstruction' => ['parts' => [['text' => trim($instructions)]]],
                'contents' => [['role' => 'user', 'parts' => [['text' => trim($input)]]]],
                'generationConfig' => ['temperature' => 0.1, 'maxOutputTokens' => $maxOutputTokens],
            ], []);
            $usage = is_array($response['usageMetadata'] ?? null) ? $response['usageMetadata'] : [];
            $this->lastUsage = [
                'input_tokens' => isset($usage['promptTokenCount']) ? max(0, (int) $usage['promptTokenCount']) : null,
                'output_tokens' => isset($usage['candidatesTokenCount']) ? max(0, (int) $usage['candidatesTokenCount']) : null,
                'total_tokens' => isset($usage['totalTokenCount']) ? max(0, (int) $usage['totalTokenCount']) : null,
                'cached_tokens' => isset($usage['cachedContentTokenCount']) ? max(0, (int) $usage['cachedContentTokenCount']) : null,
                'provider_calls' => 1,
                'provider' => 'google',
                'model' => $model,
            ];
            $text = trim((string) ($response['candidates'][0]['content']['parts'][0]['text'] ?? ''));
            if ($text === '') {
                throw new RuntimeException('A IA não retornou conteúdo para a memória da conversa.');
            }
            return $text;
        }

        throw new RuntimeException('Provedor não suportado para memória progressiva: ' . $provider);
    }

    /** @return array{input_tokens:?int,output_tokens:?int,total_tokens:?int,cached_tokens:?int,provider_calls:int,provider:?string,model:?string} */
    public function lastUsage(): array
    {
        return $this->lastUsage;
    }

    /**
     * Última barreira de continuidade conversacional.
     *
     * Regras de negócio críticas continuam no Policy Engine. Aqui só garantimos
     * coerência de conversa para fatos que o RS Connect já conhece com certeza:
     * identidade pública do assistente e telefone do contato recebido pelo canal.
     */
    private function applyCurrentTurnContinuityGuard(string $reply, array $agent, array $contact): string
    {
        $reply = trim($reply);
        if ($reply === '') {
            return $reply;
        }

        $prioritizeCurrentTurn = !array_key_exists('prioritize_current_turn', $agent)
            || (int) ($agent['prioritize_current_turn'] ?? 1) === 1;
        if (!$prioritizeCurrentTurn) {
            return $reply;
        }

        $currentTurn = trim((string) ($agent['_current_turn_text'] ?? ''));
        if ($currentTurn === '') {
            return $reply;
        }

        // Se o cliente perguntou com quem está falando, o nome configurado no
        // assistente é a fonte de verdade. O modelo pode variar a frase, mas não
        // pode ignorar essa pergunta nem inventar outra identidade.
        $asksIdentity = preg_match(
            '/(?:com\\s+quem\\s+(?:eu\\s+)?(?:falo|estou\\s+falando)|quem\\s+(?:é|e)\\s+(?:você|voce)|qual\\s+(?:é|e)\\s+(?:o\\s+)?seu\\s+nome|como\\s+(?:você|voce)\\s+se\\s+chama)/iu',
            $currentTurn
        ) === 1;
        $assistantName = trim((string) ($agent['name'] ?? ''));
        if ($asksIdentity && $assistantName !== '') {
            $firstName = trim((string) preg_split('/[,_\\-–—]/u', $assistantName, 2)[0]);
            $hasIdentity = $firstName !== '' && mb_stripos($reply, $firstName) !== false;
            if (!$hasIdentity) {
                $reply = 'Você está falando com ' . $assistantName . '. ' . ltrim($reply);
            }
        }

        // No WhatsApp o telefone já é um dado técnico do contato. Se o modelo
        // tentar pedir o telefone novamente sem que o cliente esteja falando de
        // telefone, removemos essa redundância e preservamos a coleta de outros
        // dados (por exemplo, o nome da pessoa).
        $knownPhone = trim((string) ($contact['phone'] ?? $contact['phone_number'] ?? $contact['whatsapp'] ?? ''));
        $turnMentionsPhone = preg_match('/\\b(?:telefone|celular|whatsapp|número|numero)\\b/iu', $currentTurn) === 1;
        if ($knownPhone !== '' && !$turnMentionsPhone) {
            $reply = preg_replace('/\\bnome\\s+e\\s+telefone\\b/iu', 'nome', $reply) ?? $reply;
            $reply = preg_replace('/\\btelefone\\s+e\\s+nome\\b/iu', 'nome', $reply) ?? $reply;
            $reply = preg_replace('/(?:^|(?<=[.!?])\\s+)(?:você|voce)?\\s*(?:pode\\s+)?(?:me\\s+)?(?:informar|passar|dizer)\\s+(?:o\\s+)?(?:seu\\s+)?telefone\\s*[?!.]?/iu', '', $reply) ?? $reply;
            $reply = preg_replace('/\\s{2,}/u', ' ', $reply) ?? $reply;
        }

        return trim($reply);
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
        $assistantName = trim((string) ($agent['name'] ?? ''));
        $assistantRole = trim((string) ($agent['segment'] ?? ''));
        $prioritizeCurrentTurn = !array_key_exists('prioritize_current_turn', $agent)
            || (int) ($agent['prioritize_current_turn'] ?? 1) === 1;
        $currentTurnText = trim((string) ($agent['_current_turn_text'] ?? ''));
        $currentTurnCount = max(1, (int) ($agent['_current_turn_count'] ?? 1));

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
            'Quando o cliente enviar várias mensagens antes da sua resposta, trate todas como uma única fala e responda ao conjunto, não apenas ao último balão.',
            'Se o cliente fizer uma pergunta direta durante um fluxo, responda essa pergunta primeiro. Só depois retome a etapa pendente do atendimento, sem reiniciar o roteiro.',
            'Se o cliente perguntar com quem está falando, qual é o seu nome ou quem você é, responda usando o nome público do assistente informado pelo RS Connect. Não invente outro nome e não peça o nome ou telefone do cliente para responder essa pergunta.',
            'Não invente preço, prazo, disponibilidade, política ou informação que não esteja no prompt/base.',
            'Não pergunte novamente informações que já estejam no histórico, no cadastro do contato ou no resumo da demanda.',
            'Quando o telefone já estiver disponível no cadastro do WhatsApp, não peça o telefone novamente como condição para continuar um atendimento comum.',
            'Se a pergunta exigir decisão humana, peça uma confirmação e diga que encaminhará para atendimento.',
            'Não mencione que você é um modelo de linguagem.',
            'Se o lead pedir humano, atendente, suporte ou uma pessoa, sinalize transferência em vez de insistir no atendimento automático.',
            'Não transforme menções casuais de data, hora, hoje, amanhã, tarde ou noite em pedido de agendamento. Agenda só deve ser conduzida quando houver intenção real e explícita de marcar, remarcar, consultar disponibilidade ou quando a conversa já estiver em um fluxo recente de agenda.',
            'Cliente ou paciente já identificado deve ter continuidade de atendimento: não reabra triagem, não peça novamente motivo/queixa e não trate como novo lead apenas porque iniciou uma nova conversa.',
            'O contexto operacional fornecido pelo RS Connect (modo da conversa, horário, classificação, grupo e tags) tem prioridade sobre instruções conflitantes do prompt livre.',
            'Nunca afirme que uma transferência para outro assistente virtual ou setor automatizado já aconteceu apenas por decisão textual sua. A troca entre assistentes é executada pelo motor do RS Connect antes da resposta. Se não houver o bloco TRANSFERÊNCIA INTERNA CONFIRMADA abaixo, não diga que já transferiu, que está transferindo agora ou que outro assistente já assumiu.',
        ];

        $tenantId = (int) ($conversation['tenant_id'] ?? $contact['tenant_id'] ?? 0);
        $preScheduleBlock = '';
        if ($tenantId > 0) {
            $preScheduling = new PreSchedulingService();
            if ($agendaContextActive && $preScheduling->isEnabled($tenantId)) {
                $settings = $preScheduling->settings($tenantId);
                $rules[] = 'A conversa está em contexto real de agenda. Antes de conduzir ao pré-agendamento, siga a regra do grupo informada abaixo; quando ela exigir demanda, confirme que foi coletada ou recusada.';
                $rules[] = 'Quando o contato estiver liberado pelas regras do grupo e do fluxo e demonstrar intenção real de agendar, colete dia/período/horário preferido e modalidade. Nunca invente disponibilidade e nunca declare um compromisso confirmado apenas por decisão textual sua.';
                $agendaMessageMode = strtolower(trim((string) ($settings['message_mode'] ?? 'form')));
                if ($agendaMessageMode === 'prompt') {
                    $rules[] = 'As perguntas de coleta da agenda estão no modo Prompt Studio. Use as instruções do assistente para formular a resposta de modo natural, perguntando somente os dados que ainda faltam.';
                    $rules[] = 'No modo Prompt Studio da agenda, se ainda faltarem tanto dia/horário quanto modalidade, você pode reunir essas duas perguntas relacionadas em uma única mensagem curta. Exemplo de intenção, sem copiar literalmente: perguntar qual o melhor dia e horário e se prefere online ou presencial.';
                    $rules[] = 'Não use frases burocráticas como "vou registrar sua preferência e encaminhar" quando ainda faltarem informações. Primeiro colete o necessário e deixe o RS Connect validar a agenda.';
                } else {
                    $rules[] = 'As perguntas de coleta da agenda estão no modo Formulário. Use a mensagem configurada para a etapa e não improvise outra redação.';
                }
                if (!empty($settings['ai_can_confirm']) && empty($settings['require_human_approval'])) {
                    $rules[] = 'A confirmação final é executada tecnicamente pelo RS Connect depois que um horário real foi selecionado e o cliente responde afirmativamente. Você pode pedir confirmação, mas não diga que está confirmado antes de o sistema registrar o compromisso como confirmado.';
                } else {
                    $rules[] = 'Se o contato informou preferência de dia ou horário, deixe claro que a escolha depende de confirmação humana. Não diga que está marcado ou confirmado.';
                }
                $preScheduleBlock = "Configurações de pré-agendamento do cliente:\n" .
                    '- Modo das perguntas de coleta: ' . ($agendaMessageMode === 'prompt' ? 'Prompt Studio' : 'Formulário') . "\n" .
                    '- Mensagem inicial do formulário: ' . (string) ($settings['initial_collect_message'] ?? '') . "\n" .
                    '- Mensagem para coletar dia/horário: ' . (string) ($settings['collect_message'] ?? '') . "\n" .
                    '- Mensagem enquanto a agenda é consultada: ' . (string) ($settings['default_message'] ?? '') . "\n" .
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

        $currentTurnBlock = '';
        if ($prioritizeCurrentTurn && $currentTurnText !== '') {
            $currentTurnBlock = "TURNO ATUAL DO CLIENTE (prioridade de resposta):
"
                . "O cliente enviou " . $currentTurnCount . " mensagem(ns) desde a sua última resposta. Trate o bloco abaixo como UMA ÚNICA FALA e resolva primeiro as perguntas/pedidos explícitos antes de continuar o roteiro:
---
"
                . mb_substr($currentTurnText, 0, 2200)
                . "
---
"
                . "Se houver mais de um pedido neste bloco, responda de forma curta a todos os que puderem ser atendidos agora. Depois, se ainda for necessário, faça somente a próxima pergunta pendente do fluxo.

";
        }

        $structuredContext = "CONTEXTO CADASTRAL PRIORITÁRIO DO RS CONNECT (fonte de verdade):
" .
            '- Assistente atual: ' . ($assistantName !== '' ? $assistantName : 'não informado') . ($assistantRole !== '' ? ' — ' . $assistantRole : '') . "
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

        $handoffFromAgentName = trim((string) ($agent['_routing_handoff_from_agent_name'] ?? ''));
        $handoffToAgentName = trim((string) ($agent['_routing_handoff_to_agent_name'] ?? $agent['name'] ?? ''));
        $handoffBlock = '';
        if ($handoffFromAgentName !== '' && $handoffToAgentName !== '') {
            $handoffBlock = "TRANSFERÊNCIA INTERNA CONFIRMADA PELO RS CONNECT:\n"
                . '- A conversa acabou de sair de IA - ' . $handoffFromAgentName . ' e foi atribuída a IA - ' . $handoffToAgentName . ".\n"
                . "- Você é o novo assistente responsável neste turno. Continue exatamente do ponto em que o atendimento parou e não repita perguntas já respondidas.\n"
                . "- Na primeira resposta após a transferência, identifique-se de forma curta pelo seu nome e função quando isso ajudar a deixar a troca clara para o cliente.\n"
                . "- Não diga que ainda vai transferir: a transferência já foi concluída pelo sistema.\n\n";
        }

        $policyEngineBlock = '';
        if ($tenantId > 0) {
            try {
                $agentProfile = (new AgentBlueprintService())->profileForTenant($tenantId, true);
                $triageContext = is_array($conversation['_simulation_triage_context'] ?? null)
                    ? $conversation['_simulation_triage_context']
                    : (new AgentTriageService())->context($tenantId, (int) ($conversation['id'] ?? $conversation['conversation_id'] ?? 0));
                if (($agentProfile['status'] ?? 'inactive') === 'active') {
                    $collected = is_array($triageContext['collected'] ?? null) ? $triageContext['collected'] : [];
                    if (isset($collected['brief_demand'])) {
                        $collected['brief_demand'] = '[já coletada e registrada]';
                    }
                    $missing = is_array($triageContext['missing'] ?? null) ? $triageContext['missing'] : [];
                    $policyEngineBlock = "POLICY ENGINE / BLUEPRINT DO RS CONNECT (fonte de verdade, prioridade máxima):
"
                        . '- Nicho: ' . (string) ($agentProfile['niche_name'] ?? 'não definido') . "
"
                        . '- Blueprint: ' . (string) ($agentProfile['blueprint_name'] ?? 'não definido') . "
"
                        . '- Modo de conversa: ' . (string) ($agentProfile['interaction_mode'] ?? 'hybrid') . "
"
                        . '- Status da triagem: ' . (string) ($triageContext['status'] ?? 'ainda não iniciada') . "
"
                        . '- Elegibilidade: ' . (string) ($triageContext['eligibility_status'] ?? 'ainda não avaliada') . "
"
                        . '- Motivo de bloqueio: ' . (trim((string) ($triageContext['block_reason'] ?? '')) ?: 'nenhum') . "
"
                        . '- Próximo campo obrigatório: ' . (trim((string) ($triageContext['current_field_key'] ?? '')) ?: 'nenhum') . "
"
                        . '- Campos ainda faltantes: ' . ($missing !== [] ? implode(', ', array_map('strval', $missing)) : 'nenhum obrigatório conhecido') . "
"
                        . '- Dados estruturados já coletados: ' . ($collected !== [] ? json_encode($collected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '{}') . "
"
                        . "REGRAS: nunca contrarie elegibilidade, capability ou bloqueio do RS Connect. Se houver pergunta ou pedido explícito no TURNO ATUAL, responda primeiro ao que for permitido; só depois retome o próximo campo obrigatório. Quando for retomar a coleta, faça somente uma pergunta por vez. Não afirme disponibilidade, pré-reserva ou confirmação por texto: essas ações só existem quando o backend as executa.

";
                }
            } catch (Throwable) {
                $policyEngineBlock = '';
            }
        }

        $memorySummary = trim((string) ($agent['_conversation_memory_summary'] ?? ''));
        $memoryFacts = is_array($agent['_conversation_memory_facts'] ?? null) ? $agent['_conversation_memory_facts'] : [];
        $memoryScope = (string) ($agent['_conversation_memory_scope'] ?? 'conversation');
        $memoryBlock = '';
        if ($memorySummary !== '') {
            $memoryBlock = ($memoryScope === 'contact'
                ? "MEMÓRIA PRESERVADA DO CONTATO (de atendimento anterior):\n"
                : "MEMÓRIA PROGRESSIVA DA CONVERSA (contexto histórico resumido):\n")
                . $memorySummary . "\n";
            if ($memoryFacts !== []) {
                $memoryBlock .= "Fatos estruturados confirmados: "
                    . json_encode($memoryFacts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
            }
            $memoryBlock .= "Use esta memória para manter continuidade e não repetir perguntas. Se uma mensagem recente contradisser a memória, a mensagem recente prevalece.\n\n";
        }

        return trim($base . "

" .
            $currentTurnBlock .
            $structuredContext .
            $policyEngineBlock .
            $handoffBlock .
            $memoryBlock .
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
