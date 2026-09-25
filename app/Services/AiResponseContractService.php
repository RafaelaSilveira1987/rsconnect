<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use Throwable;

/**
 * Contrato determinístico entre o estado do RS Connect e a redação do LLM.
 *
 * O backend decide O QUE precisa acontecer no turno (campo pendente, bloqueios,
 * agenda, handoff). O modelo decide COMO dizer isso. Esta classe impede que a
 * camada de linguagem substitua as regras cadastradas ou reinicie o roteiro.
 */
final class AiResponseContractService
{
    /** @param array<string,mixed> $profile @param array<string,mixed> $triageContext */
    public function promptBlock(array $profile, array $triageContext, string $currentTurnText): string
    {
        $mode = $this->interactionMode($profile);
        $field = $this->currentField($profile, $triageContext);
        $fieldKey = (string) ($field['field_key'] ?? '');
        $fieldLabel = trim((string) ($field['label'] ?? ''));
        $fieldPrompt = trim((string) ($field['prompt_text'] ?? ''));
        $collected = is_array($triageContext['collected'] ?? null) ? $triageContext['collected'] : [];
        if (isset($collected['brief_demand'])) {
            $collected['brief_demand'] = '[já coletada]';
        }
        $informational = (new AgentConversationBehaviorService())->hasInformationalQuestion($currentTurnText);

        $wording = match ($mode) {
            'form' => 'FORMULÁRIO: quando houver próxima etapa, preserve o texto cadastrado.',
            'prompt' => 'PROMPT STUDIO: a pergunta cadastrada é um objetivo; redija naturalmente segundo o prompt e o contexto.',
            default => 'NATURAL COM REGRAS: a pergunta cadastrada é um objetivo; não a trate como resposta pronta.',
        };

        return "CONTRATO DE RESPOSTA DO TURNO — OBRIGATÓRIO:\n"
            . '- Modo: ' . $wording . "\n"
            . '- O turno atual contém pedido informativo: ' . ($informational ? 'sim' : 'não') . "\n"
            . '- Próxima etapa permitida: ' . ($fieldKey !== '' ? $fieldKey : 'nenhuma') . "\n"
            . '- Objetivo da próxima etapa: ' . ($fieldLabel !== '' ? $fieldLabel : 'não definido') . "\n"
            . '- Texto cadastrado para esse objetivo: ' . ($fieldPrompt !== '' ? $fieldPrompt : 'não definido') . "\n"
            . '- Dados já coletados: ' . ($collected !== [] ? json_encode($collected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '{}') . "\n"
            . "REGRAS DO CONTRATO:\n"
            . "1. Responda primeiro ao conteúdo da fala atual. Se houver dúvida/pedido informativo, responda com as informações cadastradas antes de retomar a coleta.\n"
            . "2. Nunca pergunte novamente um dado que já esteja em 'Dados já coletados'.\n"
            . ($fieldKey !== ''
                ? "3. Depois de responder/reconhecer o turno, avance SOMENTE para a próxima etapa indicada acima e faça no máximo uma pergunta.\n"
                : "3. Não invente uma nova etapa de triagem quando não houver próxima etapa indicada.\n")
            . ($mode === 'form'
                ? "4. No modo Formulário, o texto cadastrado pode ser enviado literalmente.\n"
                : "4. Neste modo, NÃO anexe nem repita mecanicamente o texto cadastrado; formule a pergunta de modo humano, coerente com o tom e com a fala atual.\n")
            . "5. Não prometa 'vou verificar com a profissional/equipe e retorno' apenas porque a pessoa perguntou se o serviço pode ajudar. Essa é uma pergunta informativa: explique o escopo configurado, sem diagnosticar, prometer resultado ou simular uma consulta humana.\n"
            . "6. Não diga que vai consultar agenda, verificar vaga, reservar, confirmar ou transferir se o backend não tiver fornecido uma ação operacional correspondente.\n\n";
    }

    /**
     * @return array{ok:bool,violations:array<int,string>,repair_instruction:string,mode:string,current_field:string}
     */
    public function validateForConversation(PDO $pdo, int $tenantId, int $conversationId, string $currentTurnText, string $reply): array
    {
        try {
            $profile = (new AgentBlueprintService())->profileForTenant($tenantId, true, $pdo);
            $profile = (new AgentConversationBehaviorService())->applyOperationalOverridesToProfile($profile);
            $triageContext = (new AgentTriageService())->context($tenantId, $conversationId, $pdo);
            return $this->validate($profile, $triageContext, $currentTurnText, $reply);
        } catch (Throwable) {
            // Falha de leitura do estado não pode liberar uma resposta livre justamente
            // quando o contrato não pôde ser verificado. O caller fará uma tentativa de
            // reparo e, persistindo a falha, cairá no fallback determinístico.
            return [
                'ok' => false,
                'violations' => ['runtime_contract_unavailable'],
                'repair_instruction' => 'O estado operacional do turno não pôde ser validado. Não invente agenda, encaminhamento, confirmação ou dados do atendimento; responda apenas de forma neutra e sem executar ações.',
                'mode' => 'unknown',
                'current_field' => '',
            ];
        }
    }

    /**
     * @param array<string,mixed> $profile
     * @param array<string,mixed> $triageContext
     * @return array{ok:bool,violations:array<int,string>,repair_instruction:string,mode:string,current_field:string}
     */
    public function validate(array $profile, array $triageContext, string $currentTurnText, string $reply): array
    {
        $reply = trim($reply);
        $mode = $this->interactionMode($profile);
        $field = $this->currentField($profile, $triageContext);
        $fieldKey = trim((string) ($field['field_key'] ?? ''));
        $fieldPrompt = trim((string) ($field['prompt_text'] ?? ''));
        $violations = [];

        if ($reply === '') {
            $violations[] = 'empty_reply';
        }

        if ($reply !== '' && $mode !== 'form' && $fieldKey !== '' && !str_contains($reply, '?')) {
            $violations[] = 'missing_pending_question';
        }

        $normalizedReply = $this->normalize($reply);
        $normalizedPrompt = $this->normalize($fieldPrompt);
        $informational = (new AgentConversationBehaviorService())->hasInformationalQuestion($currentTurnText);
        if ($mode !== 'form' && $informational && $normalizedPrompt !== '' && $normalizedReply === $normalizedPrompt) {
            $violations[] = 'informational_request_ignored';
        }

        // A geração textual não pode fingir uma consulta/encaminhamento humano que não aconteceu.
        $lowerReply = mb_strtolower($reply);
        if ($reply !== '' && preg_match(
            '/\b(vou|iremos)\s+(?:verificar|confirmar|consultar|falar|perguntar)\s+(?:com|pra|para)\s+(?:a|o|uma|um)?\s*(?:profissional|psic[oó]log[ao]|m[eé]dic[ao]|especialista|equipe|respons[aá]vel)|\b(?:vou|iremos)\s+(?:registrar[^.!?]{0,120}e\s+)?encaminhar[^.!?]{0,100}(?:verificar|consultar|confirmar)[^.!?]{0,80}(?:agenda|disponibilidade|vaga|hor[aá]rio)|\bte\s+retorno\s+assim\s+que|\bretorno\s+assim\s+que/u',
            $lowerReply
        )) {
            $violations[] = 'unsupported_human_check';
        }

        // Consultar agenda é ação do backend, nunca uma promessa textual do modelo.
        if ($reply !== '' && preg_match(
            '/\b(vou|iremos)\s+(?:verificar|consultar|checar)\s+(?:a\s+)?(?:agenda|disponibilidade|vaga|hor[aá]rios?)\b|\bencaminhar[^.!?]{0,120}(?:verificar|consultar|confirmar)[^.!?]{0,80}(?:agenda|disponibilidade|vaga|hor[aá]rio)/u',
            $lowerReply
        )) {
            $violations[] = 'unsupported_calendar_action';
        }

        // O calendário informa disponibilidade; ele não informa, por si só, a causa da
        // indisponibilidade. A IA não pode transformar "indisponível" em "ocupado".
        if ($reply !== '' && preg_match(
            '/(?:hor[aá]rio|op[cç][aã]o|vaga|agenda)[^.!?]{0,70}(?:preenchid[ao]|ocupad[ao]|lotad[ao]|cheia)|(?:preenchid[ao]|ocupad[ao]|lotad[ao])[^.!?]{0,70}(?:hor[aá]rio|op[cç][aã]o|vaga|agenda)/u',
            $lowerReply
        )) {
            $violations[] = 'unsupported_calendar_reason';
        }

        if ($reply !== '' && preg_match(
            '/\b(?:te\s+)?avis(?:o|amos|arei|aremos)[^.!?]{0,100}assim\s+que[^.!?]{0,80}(?:abrir|surgir)[^.!?]{0,60}(?:vaga|encaixe|hor[aá]rio)|\bavisar[^.!?]{0,100}(?:abrir|surgir)[^.!?]{0,60}(?:vaga|encaixe|hor[aá]rio)/u',
            $lowerReply
        )) {
            $violations[] = 'unsupported_waitlist_promise';
        }

        $violations = array_values(array_unique($violations));
        $repair = '';
        if ($violations !== []) {
            $repair = 'A resposta anterior violou o contrato de runtime (' . implode(', ', $violations) . '). '
                . 'Reescreva a resposta do zero. Responda primeiro à fala atual usando somente regras/base cadastradas; '
                . ($fieldKey !== ''
                    ? 'depois faça apenas a próxima pergunta necessária para o objetivo "' . trim((string) ($field['label'] ?? $fieldKey)) . '". '
                    : 'não invente nova pergunta de triagem. ')
                . ($mode === 'form'
                    ? 'Preserve o texto de formulário quando aplicável. '
                    : 'Não copie mecanicamente a pergunta cadastrada; use linguagem natural e o tom configurado. ')
                . 'Não simule consulta com humano, agenda, vaga, confirmação, lista de espera ou transferência que o backend não executou e não invente o motivo de uma indisponibilidade.';
        }

        return [
            'ok' => $violations === [],
            'violations' => $violations,
            'repair_instruction' => $repair,
            'mode' => $mode,
            'current_field' => $fieldKey,
        ];
    }

    /**
     * Última barreira determinística quando até a segunda tentativa do LLM viola o
     * contrato. Nunca cria fatos de agenda; apenas reflete o estado persistido.
     */
    public function safeFallbackForConversation(PDO $pdo, int $tenantId, int $conversationId): string
    {
        try {
            $profile = (new AgentBlueprintService())->profileForTenant($tenantId, true, $pdo);
            $profile = (new AgentConversationBehaviorService())->applyOperationalOverridesToProfile($profile);
            $triage = (new AgentTriageService())->context($tenantId, $conversationId, $pdo);
            $field = $this->currentField($profile, $triage);
            $prompt = trim((string) ($field['prompt_text'] ?? ''));
            if ($prompt !== '') {
                return $prompt;
            }

            $stmt = $pdo->prepare(
                'SELECT * FROM calendar_appointments
                 WHERE tenant_id = :tenant_id AND conversation_id = :conversation_id
                 ORDER BY id DESC LIMIT 1'
            );
            $stmt->execute(['tenant_id' => $tenantId, 'conversation_id' => $conversationId]);
            $appointment = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            if ($appointment !== []) {
                $settings = (new PreSchedulingService())->settings($tenantId);
                $modality = strtolower(trim((string) ($appointment['appointment_modality'] ?? $appointment['location_type'] ?? '')));
                $day = trim((string) ($appointment['preferred_day_text'] ?? ''));
                $time = trim((string) ($appointment['preferred_time_text'] ?? ''));
                $availability = trim((string) ($appointment['availability_status'] ?? ''));

                if (!in_array($modality, ['online', 'presencial', 'telefone'], true)) {
                    return trim((string) ($settings['modality_message'] ?? '')) ?: 'Você prefere atendimento online ou presencial?';
                }
                if ($day === '' || $time === '') {
                    return trim((string) ($settings['collect_message'] ?? '')) ?: 'Qual o melhor dia e horário para você?';
                }
                if ($availability === 'empty') {
                    return 'Não encontrei disponibilidade para essa preferência. Pode me informar outro dia ou horário?';
                }
                if (in_array($availability, ['requested', 'sent', 'communicating'], true)) {
                    return 'Recebi sua preferência e a consulta da agenda está em andamento. Assim que a agenda retornar, eu mostro as opções disponíveis.';
                }
            }
        } catch (Throwable) {
            // Fallback final abaixo: melhor uma resposta neutra que um fato inventado.
        }

        return 'Certo. Posso continuar te orientando por aqui.';
    }

    /** @param array<string,mixed> $profile */
    private function interactionMode(array $profile): string
    {
        $mode = strtolower(trim((string) ($profile['interaction_mode'] ?? 'hybrid')));
        return in_array($mode, ['form', 'prompt', 'hybrid'], true) ? $mode : 'hybrid';
    }

    /** @param array<string,mixed> $profile @param array<string,mixed> $triageContext @return array<string,mixed> */
    private function currentField(array $profile, array $triageContext): array
    {
        $key = trim((string) ($triageContext['current_field_key'] ?? ''));
        if ($key === '') {
            return [];
        }
        foreach ((array) ($profile['triage_fields'] ?? []) as $field) {
            if (is_array($field) && (string) ($field['field_key'] ?? '') === $key) {
                return $field;
            }
        }
        return ['field_key' => $key, 'label' => $key, 'prompt_text' => ''];
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = strtr($value, [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a',
            'é' => 'e', 'ê' => 'e', 'í' => 'i',
            'ó' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ú' => 'u', 'ü' => 'u', 'ç' => 'c',
        ]);
        return trim((string) preg_replace('/[^a-z0-9]+/u', ' ', $value));
    }
}
