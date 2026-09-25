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
            return [
                'ok' => true,
                'violations' => [],
                'repair_instruction' => '',
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

        // A geração textual não pode fingir uma consulta humana que não aconteceu.
        if ($reply !== '' && preg_match(
            '/\b(vou|iremos)\s+(?:verificar|confirmar|consultar|falar|perguntar)\s+(?:com|pra|para)\s+(?:a|o|uma|um)?\s*(?:profissional|psic[oó]log[ao]|m[eé]dic[ao]|especialista|equipe|respons[aá]vel)|\bte\s+retorno\s+assim\s+que|\bretorno\s+assim\s+que/u',
            mb_strtolower($reply)
        )) {
            $violations[] = 'unsupported_human_check';
        }

        // Consultar agenda é ação do backend, nunca uma promessa textual do modelo.
        if ($reply !== '' && preg_match(
            '/\b(vou|iremos)\s+(?:verificar|consultar|checar)\s+(?:a\s+)?(?:agenda|disponibilidade|vaga|hor[aá]rios?)\b/u',
            mb_strtolower($reply)
        )) {
            $violations[] = 'unsupported_calendar_action';
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
                . 'Não simule consulta com humano, agenda, vaga, confirmação ou transferência que o backend não executou.';
        }

        return [
            'ok' => $violations === [],
            'violations' => $violations,
            'repair_instruction' => $repair,
            'mode' => $mode,
            'current_field' => $fieldKey,
        ];
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
