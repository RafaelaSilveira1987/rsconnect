<?php

declare(strict_types=1);

namespace App\Services;

final class AgentRuntimeConfigurationService
{
    /**
     * Valida se o fluxo visual tem contrato executável suficiente para o runtime.
     * Não conhece nicho, nomes de campos ou conteúdo de negócio: lê somente o que
     * foi persistido no perfil, nos campos e no config_json das etapas.
     *
     * @return array{ok:bool,errors:array<int,string>,warnings:array<int,string>,steps:array<int,array<string,mixed>>}
     */
    public function audit(array $profile): array
    {
        $fields = is_array($profile['triage_fields'] ?? null) ? array_values($profile['triage_fields']) : [];
        $workflow = is_array($profile['workflow'] ?? null) ? array_values($profile['workflow']) : [];

        $fieldMap = [];
        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }
            $key = trim((string) ($field['field_key'] ?? ''));
            if ($key !== '') {
                $fieldMap[$key] = $field;
            }
        }

        $errors = [];
        $warnings = [];
        $steps = [];
        $seenFields = [];
        $fieldStepPositions = [];
        $calendarPosition = null;

        foreach ($workflow as $index => $step) {
            if (!is_array($step) || empty($step['active'])) {
                continue;
            }
            $type = (string) ($step['step_type'] ?? 'collect');
            $config = is_array($step['config'] ?? null) ? $step['config'] : [];
            $label = trim((string) ($step['label'] ?? $step['step_key'] ?? 'Etapa'));
            $position = (int) ($step['position'] ?? (($index + 1) * 10));
            $keys = $this->fieldKeys($config);
            $actionKey = trim((string) ($config['action_key'] ?? ''));

            if ($type === 'action' && str_starts_with($actionKey, 'calendar.') && $calendarPosition === null) {
                $calendarPosition = $position;
            }

            $fieldLabels = [];
            if ($type === 'collect') {
                if ($keys === []) {
                    $errors[] = 'A etapa “' . $label . '” é de coleta, mas não está vinculada a nenhuma informação.';
                }
                foreach ($keys as $key) {
                    if (!isset($fieldMap[$key])) {
                        $errors[] = 'A etapa “' . $label . '” aponta para uma informação que não existe mais: ' . $key . '.';
                        continue;
                    }
                    $field = $fieldMap[$key];
                    $fieldLabels[] = (string) ($field['label'] ?? $key);
                    if (isset($seenFields[$key])) {
                        $warnings[] = 'A informação “' . (string) ($field['label'] ?? $key) . '” aparece em mais de uma etapa de coleta.';
                    }
                    $seenFields[$key] = true;
                    $fieldStepPositions[$key] = $position;
                    if (!empty($field['active']) && trim((string) ($field['prompt_text'] ?? '')) === '') {
                        $errors[] = 'A informação “' . (string) ($field['label'] ?? $key) . '” está ativa, mas não possui pergunta configurada.';
                    }
                }
            }

            if ($type === 'action' && $actionKey === '') {
                $warnings[] = 'A etapa de ação “' . $label . '” não possui uma ação técnica vinculada.';
            }

            $steps[] = [
                'label' => $label,
                'type' => $type,
                'position' => $position,
                'fields' => $fieldLabels,
                'field_keys' => $keys,
                'action_key' => $actionKey,
            ];
        }

        if ($workflow !== [] && $calendarPosition === null) {
            $warnings[] = 'Nenhuma ação de agenda foi vinculada ao fluxo. A ordem visual não controla uma fronteira de agenda.';
        }

        foreach ($fields as $field) {
            if (!is_array($field) || empty($field['active'])) {
                continue;
            }
            $key = trim((string) ($field['field_key'] ?? ''));
            if ($key === '') {
                continue;
            }
            if (!empty($field['required_for_completion']) && !isset($seenFields[$key])) {
                $warnings[] = 'A informação obrigatória “' . (string) ($field['label'] ?? $key) . '” não aparece em nenhuma etapa da Ordem do atendimento.';
            }
            if (!empty($field['required_before_schedule']) && $calendarPosition !== null) {
                if (!isset($fieldStepPositions[$key])) {
                    $errors[] = 'A informação “' . (string) ($field['label'] ?? $key) . '” é obrigatória antes da agenda, mas não está vinculada a uma etapa de coleta.';
                } elseif ($fieldStepPositions[$key] > $calendarPosition) {
                    $errors[] = 'A informação “' . (string) ($field['label'] ?? $key) . '” é obrigatória antes da agenda, mas sua etapa está depois de “Consultar agenda”. Reordene o fluxo.';
                }
            }
        }

        return [
            'ok' => $errors === [],
            'errors' => array_values(array_unique($errors)),
            'warnings' => array_values(array_unique($warnings)),
            'steps' => $steps,
        ];
    }

    /** @return array<int,string> */
    private function fieldKeys(array $config): array
    {
        $keys = [];
        $one = trim((string) ($config['field_key'] ?? ''));
        if ($one !== '') {
            $keys[] = $one;
        }
        if (is_array($config['field_keys'] ?? null)) {
            foreach ($config['field_keys'] as $key) {
                $key = trim((string) $key);
                if ($key !== '') {
                    $keys[] = $key;
                }
            }
        }
        return array_values(array_unique($keys));
    }
}
