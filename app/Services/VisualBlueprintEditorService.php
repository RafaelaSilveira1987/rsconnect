<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

/**
 * Converte escolhas do editor de modelos em configuração operacional.
 * Nunca executa SQL, não produz regras a partir de texto livre e preserva
 * propriedades avançadas que o formulário visual não administra.
 */
final class VisualBlueprintEditorService
{
    /** @param array<string,mixed> $current @param array<string,mixed> $input @return array<string,mixed> */
    public function compile(array $current, array $input): array
    {
        $config = $current === [] ? self::starter() : $current;
        foreach (['capabilities', 'triage_fields', 'policies', 'workflow'] as $key) {
            if (!isset($config[$key]) || !is_array($config[$key])) {
                throw new InvalidArgumentException('O modelo tem uma estrutura incompleta. Use a edição avançada para revisar.');
            }
        }
        $mode = (string) ($input['interaction_mode'] ?? '');
        if (!in_array($mode, ['hybrid', 'form', 'prompt'], true)) {
            throw new InvalidArgumentException('Selecione uma forma de conversa válida.');
        }
        $config['interaction_mode'] = $mode;

        $calendar = ($input['calendar_pre_schedule'] ?? '') === '1';
        // O formulário só conhece o fluxo padrão. Rejeita rotas personalizadas
        // em vez de removê-las ou deixá-las inconsistentes silenciosamente.
        foreach ($config['workflow'] as $step) {
            if (!is_array($step)) continue;
            $key = (string) ($step['key'] ?? '');
            $type = (string) ($step['type'] ?? 'collect');
            if (in_array($type, ['action', 'handoff', 'complete'], true)
                && !in_array($key, ['calendar', 'confirm', 'human_approval'], true)) {
                throw new InvalidArgumentException('Este modelo possui etapas especiais. Edite-o na configuração avançada para preservar o fluxo.');
            }
        }
        $capabilities = $config['capabilities'];
        // Ao habilitar a agenda pelo editor simples, nunca habilitar confirmação
        // automática. A aprovação humana é o único padrão seguro neste fluxo.
        $capabilities['calendar.read'] = $calendar;
        $capabilities['calendar.pre_schedule'] = $calendar;
        $capabilities['calendar.confirm'] = false;
        $capabilities['calendar.human_approval'] = true;
        $capabilities['policy.fail_closed'] = true;
        $config['capabilities'] = $capabilities;

        foreach ($config['policies'] as &$policy) {
            if (!is_array($policy)) continue;
            if (($policy['key'] ?? '') === 'confirmation_requires_human') {
                $policy['type'] = 'boolean';
                $policy['value'] = true;
                $policy['action'] = 'human_approval';
                if (trim((string) ($policy['message'] ?? '')) === '') {
                    $policy['message'] = 'A equipe precisa confirmar o horário antes de finalizar a reserva.';
                }
            }
        }
        unset($policy);

        $updates = $input['questions'] ?? [];
        if (!is_array($updates)) {
            throw new InvalidArgumentException('As perguntas precisam ser enviadas em uma lista.');
        }
        $known = [];
        foreach ($config['triage_fields'] as &$field) {
            if (!is_array($field)) continue;
            $key = (string) ($field['key'] ?? '');
            if ($key === '') continue;
            $known[$key] = true;
            if (!array_key_exists($key, $updates)) continue;
            $row = $updates[$key];
            if (!is_array($row)) throw new InvalidArgumentException('Pergunta inválida.');
            $prompt = trim((string) ($row['prompt'] ?? ''));
            if ($prompt === '' || (function_exists('mb_strlen') ? mb_strlen($prompt) : strlen($prompt)) > 600) {
                throw new InvalidArgumentException('Cada pergunta precisa ter de 1 a 600 caracteres.');
            }
            $field['prompt'] = $prompt;
            $field['required_before_schedule'] = ($row['required_before_schedule'] ?? '') === '1';
            if ($field['required_before_schedule']) {
                $field['required_for_completion'] = true;
            }
        }
        unset($field);
        if (array_diff(array_keys($updates), array_keys($known)) !== []) {
            throw new InvalidArgumentException('O formulário contém uma pergunta que não pertence a este modelo.');
        }

        // Não reordena o fluxo quando ele já está na configuração segura.
        // Só recalcula as etapas finais se o estado da agenda mudar ou se houver
        // confirmação automática no padrão anterior.
        $workflow = $config['workflow'];
        $workflowKeys = array_column($workflow, 'key');
        $hasCalendar = in_array('calendar', $workflowKeys, true);
        $hasHumanApproval = in_array('human_approval', $workflowKeys, true);
        $hasAutoConfirm = in_array('confirm', $workflowKeys, true);
        if (($calendar && (!$hasCalendar || !$hasHumanApproval || $hasAutoConfirm))
            || (!$calendar && ($hasCalendar || $hasHumanApproval || $hasAutoConfirm))) {
            $workflow = array_values(array_filter($workflow, static function ($step) {
                return !is_array($step) || !in_array((string) ($step['key'] ?? ''), ['calendar', 'confirm', 'human_approval'], true);
            }));
            if ($calendar) {
                $positions = array_map(static fn ($step): int => is_array($step) ? (int) ($step['position'] ?? 0) : 0, $workflow);
                $next = max($positions ?: [0]) + 10;
                $workflow[] = ['key' => 'calendar', 'label' => 'Consultar disponibilidade', 'type' => 'action', 'position' => $next];
                $workflow[] = ['key' => 'human_approval', 'label' => 'Aguardar aprovação da equipe', 'type' => 'handoff', 'position' => $next + 10];
            }
        }
        $config['workflow'] = $workflow;
        return $config;
    }

    /** @return array<string,mixed> */
    private static function starter(): array
    {
        return [
            'interaction_mode' => 'hybrid',
            'capabilities' => ['triage.enabled' => true, 'policy.fail_closed' => true],
            'triage_fields' => [
                ['key' => 'requester_name', 'label' => 'Nome do contato', 'type' => 'text',
                    'prompt' => 'Como posso chamar você?', 'required_before_schedule' => true,
                    'required_for_completion' => true, 'position' => 10],
                ['key' => 'brief_demand', 'label' => 'Motivo do contato', 'type' => 'textarea',
                    'prompt' => 'Em que podemos ajudar?', 'required_before_schedule' => true,
                    'required_for_completion' => true, 'position' => 20],
            ],
            'policies' => [
                ['key' => 'confirmation_requires_human', 'type' => 'boolean', 'value' => true,
                    'action' => 'human_approval', 'message' => 'A equipe precisa confirmar o horário.', 'priority' => 10],
            ],
            'workflow' => [
                ['key' => 'identify_intent', 'label' => 'Identificar intenção', 'type' => 'collect', 'position' => 10],
                ['key' => 'collect_demand', 'label' => 'Entender a solicitação', 'type' => 'collect', 'position' => 20],
            ],
        ];
    }
}
