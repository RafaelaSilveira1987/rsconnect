<?php

declare(strict_types=1);

namespace App\Services;

final class AgentPolicyEngineService
{
    /**
     * Avalia regras estruturadas. O LLM nunca decide elegibilidade nem autorização de ação.
     *
     * @return array{allowed:bool,decision:string,code:string,message:?string,policy_key:?string,evidence:array<string,mixed>}
     */
    public function evaluate(array $profile, array $collected, string $action = 'conversation'): array
    {
        $capabilities = is_array($profile['capabilities'] ?? null) ? $profile['capabilities'] : [];
        $policies = is_array($profile['policies'] ?? null) ? $profile['policies'] : [];
        $fields = is_array($profile['triage_fields'] ?? null) ? $profile['triage_fields'] : [];

        if (($profile['status'] ?? 'inactive') !== 'active') {
            return $this->allow('profile_inactive');
        }

        $humanApprovalCapability = $this->capability($capabilities, 'calendar.human_approval', false);
        if ($action !== 'conversation' && array_key_exists($action, $capabilities) && empty($capabilities[$action])) {
            // Em fluxos com aprovação humana, calendar.confirm=false significa que a IA não
            // confirma sozinha; o resultado correto é handoff, não um erro genérico de capability.
            if (!($action === 'calendar.confirm' && $humanApprovalCapability)) {
                return $this->block('capability_denied', 'capability:' . $action, 'Esta ação não está habilitada para este atendimento.', [
                    'capability' => $action,
                ]);
            }
        }

        $policyMap = [];
        foreach ($policies as $policy) {
            if (!is_array($policy) || empty($policy['enabled'])) {
                continue;
            }
            $policyMap[(string) ($policy['policy_key'] ?? '')] = $policy;
        }

        $age = $this->numericValue($collected['patient_age'] ?? null);
        if (isset($policyMap['minimum_age']) && $age !== null) {
            $minimum = $this->numericValue($policyMap['minimum_age']['value'] ?? null);
            if ($minimum !== null && $age < $minimum) {
                $message = $this->renderPolicyMessage(
                    (string) ($policyMap['minimum_age']['customer_message'] ?? ''),
                    ['minimum_age' => $this->formatNumber($minimum), 'patient_age' => $this->formatNumber($age)]
                );
                return $this->block('minimum_age', 'minimum_age', $message !== '' ? $message : 'Este atendimento não está disponível para a idade informada.', [
                    'patient_age' => $age,
                    'minimum_age' => $minimum,
                ]);
            }
        }

        $coupleIntent = !empty($collected['couple_intent']);
        if ($coupleIntent && isset($policyMap['couple_service_allowed']) && !$this->boolValue($policyMap['couple_service_allowed']['value'] ?? true)) {
            $message = trim((string) ($policyMap['couple_service_allowed']['customer_message'] ?? ''));
            return $this->block('couple_service_not_allowed', 'couple_service_allowed', $message !== '' ? $message : 'Este tipo de atendimento não está disponível.', [
                'couple_intent' => true,
            ]);
        }

        $genericDecision = $this->evaluateGenericJsonPolicies($policyMap, $collected, $action);
        if ($genericDecision !== null) {
            return $genericDecision;
        }

        if (str_starts_with($action, 'calendar.')) {
            $missing = $this->missingRequiredBeforeSchedule($fields, $collected);
            if ($missing !== []) {
                $field = $missing[0];
                return [
                    'allowed' => false,
                    'decision' => 'collect',
                    'code' => 'triage_incomplete',
                    'message' => trim((string) ($field['prompt_text'] ?? '')) ?: ('Antes de consultar a agenda, preciso confirmar: ' . (string) ($field['label'] ?? $field['field_key'] ?? 'uma informação') . '.'),
                    'policy_key' => 'required_before_schedule',
                    'evidence' => [
                        'missing_fields' => array_values(array_map(static fn (array $row): string => (string) ($row['field_key'] ?? ''), $missing)),
                        'next_field' => (string) ($field['field_key'] ?? ''),
                    ],
                ];
            }
        }

        if ($action === 'calendar.confirm') {
            $policyRequiresHuman = isset($policyMap['confirmation_requires_human'])
                && $this->boolValue($policyMap['confirmation_requires_human']['value'] ?? false);
            if ($humanApprovalCapability || $policyRequiresHuman) {
                $message = trim((string) ($policyMap['confirmation_requires_human']['customer_message'] ?? ''));
                return $this->block(
                    'human_approval_required',
                    'confirmation_requires_human',
                    $message !== '' ? $message : 'Este agendamento precisa de confirmação da equipe responsável.',
                    ['human_approval' => true],
                    'handoff'
                );
            }
        }

        return $this->allow('allowed');
    }

    public function missingRequiredBeforeSchedule(array $fields, array $collected): array
    {
        $missing = [];
        foreach ($fields as $field) {
            if (!is_array($field) || empty($field['active']) || empty($field['required_before_schedule'])) {
                continue;
            }
            $key = (string) ($field['field_key'] ?? $field['key'] ?? '');
            if ($key === '') {
                continue;
            }
            if ($key === 'patient_name' && !empty($collected['is_for_self'])) {
                continue;
            }
            if (!$this->hasMeaningfulValue($collected[$key] ?? null)) {
                $missing[] = $field;
            }
        }
        return $missing;
    }

    public function missingForCompletion(array $fields, array $collected): array
    {
        $missing = [];
        foreach ($fields as $field) {
            if (!is_array($field) || empty($field['active']) || empty($field['required_for_completion'])) {
                continue;
            }
            $key = (string) ($field['field_key'] ?? $field['key'] ?? '');
            if ($key === '') {
                continue;
            }
            if ($key === 'patient_name' && !empty($collected['is_for_self'])) {
                continue;
            }
            if (!$this->hasMeaningfulValue($collected[$key] ?? null)) {
                $missing[] = $field;
            }
        }
        return $missing;
    }

    /**
     * Políticas JSON permitem criar travas de novos nichos sem adicionar ifs específicos
     * ao código. Exemplo de value_json:
     * {"field":"service","operator":"in","value":["progressiva"],"applies_to":["calendar.*"]}
     * A política é acionada quando a condição for verdadeira.
     */
    private function evaluateGenericJsonPolicies(array $policyMap, array $collected, string $action): ?array
    {
        foreach ($policyMap as $key => $policy) {
            if (!is_array($policy) || (string) ($policy['policy_type'] ?? '') !== 'json') {
                continue;
            }
            $rule = $policy['value'] ?? null;
            if (!is_array($rule) || $rule === []) {
                continue;
            }
            if (!$this->actionMatches($action, $rule['applies_to'] ?? null)) {
                continue;
            }

            $condition = is_array($rule['condition'] ?? null) ? $rule['condition'] : $rule;
            if (!$this->conditionMatches($condition, $collected)) {
                continue;
            }

            $actionKey = strtolower(trim((string) ($policy['action_key'] ?? 'block')));
            $message = trim((string) ($policy['customer_message'] ?? ''));
            $decision = str_contains($actionKey, 'handoff') || str_contains($actionKey, 'human') ? 'handoff' : 'block';
            return $this->block(
                'policy_rule:' . (string) $key,
                (string) $key,
                $message !== '' ? $message : 'Esta ação não está disponível para os dados informados.',
                [
                    'field' => (string) ($condition['field'] ?? ''),
                    'operator' => (string) ($condition['operator'] ?? 'eq'),
                    'rule_matched' => true,
                ],
                $decision
            );
        }
        return null;
    }

    private function actionMatches(string $action, mixed $appliesTo): bool
    {
        if ($appliesTo === null || $appliesTo === '' || $appliesTo === []) {
            return true;
        }
        $items = is_array($appliesTo) ? $appliesTo : [$appliesTo];
        foreach ($items as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate === '*' || $candidate === $action) {
                return true;
            }
            if (str_ends_with($candidate, '*') && str_starts_with($action, substr($candidate, 0, -1))) {
                return true;
            }
        }
        return false;
    }

    private function conditionMatches(array $condition, array $collected): bool
    {
        $field = trim((string) ($condition['field'] ?? ''));
        if ($field === '') {
            return false;
        }
        $actual = $this->fieldValue($collected, $field);
        $operator = strtolower(trim((string) ($condition['operator'] ?? 'eq')));
        $expected = $condition['value'] ?? null;

        return match ($operator) {
            'exists' => $this->hasMeaningfulValue($actual),
            'missing', 'not_exists' => !$this->hasMeaningfulValue($actual),
            'eq', '=' => $this->looselyEqual($actual, $expected),
            'neq', '!=' => !$this->looselyEqual($actual, $expected),
            'lt', '<' => $this->compareNumeric($actual, $expected, static fn (float $a, float $b): bool => $a < $b),
            'lte', '<=' => $this->compareNumeric($actual, $expected, static fn (float $a, float $b): bool => $a <= $b),
            'gt', '>' => $this->compareNumeric($actual, $expected, static fn (float $a, float $b): bool => $a > $b),
            'gte', '>=' => $this->compareNumeric($actual, $expected, static fn (float $a, float $b): bool => $a >= $b),
            'in' => is_array($expected) && $this->valueIn($actual, $expected),
            'not_in' => is_array($expected) && !$this->valueIn($actual, $expected),
            'contains' => $this->containsValue($actual, $expected),
            'not_contains' => !$this->containsValue($actual, $expected),
            'regex' => is_string($expected) && $expected !== '' && @preg_match($expected, (string) $actual) === 1,
            default => false,
        };
    }

    private function fieldValue(array $data, string $path): mixed
    {
        $value = $data;
        foreach (explode('.', $path) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return null;
            }
            $value = $value[$part];
        }
        return $value;
    }

    private function looselyEqual(mixed $actual, mixed $expected): bool
    {
        if (is_numeric($actual) && is_numeric($expected)) {
            return (float) $actual === (float) $expected;
        }
        if (is_bool($actual) || is_bool($expected)) {
            return $this->boolValue($actual) === $this->boolValue($expected);
        }
        return mb_strtolower(trim((string) $actual)) === mb_strtolower(trim((string) $expected));
    }

    private function compareNumeric(mixed $actual, mixed $expected, callable $comparator): bool
    {
        $a = $this->numericValue($actual);
        $b = $this->numericValue($expected);
        return $a !== null && $b !== null && $comparator($a, $b);
    }

    private function valueIn(mixed $actual, array $expected): bool
    {
        foreach ($expected as $item) {
            if ($this->looselyEqual($actual, $item)) {
                return true;
            }
        }
        return false;
    }

    private function containsValue(mixed $actual, mixed $expected): bool
    {
        $haystack = mb_strtolower((string) $actual);
        $needle = mb_strtolower(trim((string) $expected));
        return $needle !== '' && str_contains($haystack, $needle);
    }

    private function capability(array $capabilities, string $key, bool $default): bool
    {
        return array_key_exists($key, $capabilities) ? !empty($capabilities[$key]) : $default;
    }

    private function hasMeaningfulValue(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }
        if (is_bool($value) || is_numeric($value)) {
            return true;
        }
        if (is_array($value)) {
            return $value !== [];
        }
        return trim((string) $value) !== '';
    }

    private function numericValue(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_string($value) && is_numeric(trim($value))) {
            return (float) trim($value);
        }
        return null;
    }

    private function boolValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value === 1;
        }
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'sim', 'yes', 'on'], true);
    }

    private function renderPolicyMessage(string $message, array $vars): string
    {
        foreach ($vars as $key => $value) {
            $message = str_replace('{{' . $key . '}}', (string) $value, $message);
        }
        return trim($message);
    }

    private function formatNumber(float $value): string
    {
        return floor($value) === $value ? (string) (int) $value : number_format($value, 1, ',', '.');
    }

    private function allow(string $code): array
    {
        return [
            'allowed' => true,
            'decision' => 'allow',
            'code' => $code,
            'message' => null,
            'policy_key' => null,
            'evidence' => [],
        ];
    }

    private function block(string $code, string $policyKey, string $message, array $evidence = [], string $decision = 'block'): array
    {
        return [
            'allowed' => false,
            'decision' => $decision,
            'code' => $code,
            'message' => $message,
            'policy_key' => $policyKey,
            'evidence' => $evidence,
        ];
    }
}
