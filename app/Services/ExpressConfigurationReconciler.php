<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Plano puro de reconciliação. Regras de outras interfaces nunca desaparecem
 * por estarem ausentes do payload limitado da Configuração Expressa.
 * "managed" identifica explicitamente os campos controlados por esse editor.
 */
final class ExpressConfigurationReconciler
{
    private const OWNED_FIELDS = ['requester_name', 'contact_phone'];
    private const OWNED_CAPABILITIES = [
        'triage.enabled', 'policy.fail_closed', 'calendar.reschedule',
        'handoff.on_error', 'calendar.confirmation_link',
    ];

    public static function ownsField(string $key): bool
    {
        return in_array($key, self::OWNED_FIELDS, true) || str_starts_with($key, 'express_');
    }

    /**
     * @param list<array<string,mixed>> $current Fields from the database (includes inactive)
     * @param list<array<string,mixed>> $incoming Fields from compiler
     * @return array{fields:list<array<string,mixed>>,delete_keys:list<string>,protected:list<string>}
     */
    public function triage(array $current, array $incoming): array
    {
        $compiled = [];
        foreach ($incoming as $f) $compiled[(string) $f['key']] = $f;
        $merged = [];
        $protected = [];
        $currentKeys = [];
        foreach ($current as $f) {
            $key = (string) ($f['field_key'] ?? '');
            if ($key === '') continue;
            $currentKeys[] = $key;
            if (!self::ownsField($key)) {
                // Persistência operacional de outras telas tem precedência sobre padrão do blueprint.
                $merged[$key] = [
                    'key'=>$key, 'label'=>$f['label'], 'type'=>$f['field_type'] ?? 'text',
                    'prompt'=>$f['prompt_text'] ?? '', 'options'=>self::decode($f['options_json'] ?? null),
                    'required_before_schedule'=>(bool) ($f['required_before_schedule'] ?? false),
                    'required_for_completion'=>(bool) ($f['required_for_completion'] ?? false),
                    'active'=>(int) ($f['active'] ?? 1), 'position'=>(int) ($f['position'] ?? 100),
                ];
                $protected[] = $key;
                unset($compiled[$key]);
            }
        }
        foreach ($compiled as $key => $f) {
            $merged[$key] = $f + ['active'=>1];
        }
        $toDelete = array_values(array_filter($currentKeys, static fn ($key) =>
            self::ownsField($key) && !array_key_exists($key, $merged)));
        uasort($merged, static fn (array $a, array $b) =>
            ((int) ($a['position'] ?? 100) <=> (int) ($b['position'] ?? 100))
            ?: strcmp((string) $a['key'], (string) $b['key']));
        return ['fields'=>array_values($merged), 'delete_keys'=>$toDelete, 'protected'=>$protected];
    }

    /**
     * @param list<array<string,mixed>> $current
     * @param list<array<string,mixed>> $incoming
     * @param list<string> $deletedFieldKeys
     * @return array{steps:list<array<string,mixed>>,delete_keys:list<string>}
     */
    public function workflow(array $current, array $incoming, array $deletedFieldKeys): array
    {
        $merged = [];
        $deleted = [];
        $alreadyCollected = [];
        $hasExistingWorkflow = $current !== [];
        foreach ($current as $row) {
            $key = (string) ($row['step_key'] ?? '');
            if ($key === '') continue;
            $owned = str_starts_with($key, 'collect_express_')
                || in_array($key, ['collect_requester_name', 'collect_contact_phone'], true);
            if ($owned) {
                $deleted[] = $key;
                continue;
            }
            $config = self::decode($row['config_json'] ?? null);
            if ($deletedFieldKeys !== [] && ($row['step_type'] ?? '') === 'collect') {
                $keys = $config['field_keys'] ?? [];
                if (is_array($keys)) $config['field_keys'] = array_values(array_diff($keys, $deletedFieldKeys));
                if (isset($config['field_key']) && in_array($config['field_key'], $deletedFieldKeys, true)) unset($config['field_key']);
            }
            if (($row['step_type'] ?? '') === 'collect') {
                foreach (($config['field_keys'] ?? []) as $fieldKey) {
                    if (!empty($row['active'])) $alreadyCollected[(string) $fieldKey] = true;
                }
                if (is_string($config['field_key'] ?? null)) $alreadyCollected[$config['field_key']] = true;
            }
            $isEmptyCollect = ($row['step_type'] ?? '') === 'collect'
                && ($config['field_keys'] ?? []) === [] && empty($config['field_key']);
            $merged[$key] = [
                'key'=>$key, 'label'=>(string) ($row['label'] ?? $key),
                'type'=>(string) ($row['step_type'] ?? 'collect'),
                'config'=>$config,'position'=>(int) ($row['position'] ?? 100),
                'active'=>$isEmptyCollect ? 0 : (int) ($row['active'] ?? 1),
            ];
        }
        foreach ($incoming as $step) {
            $key = (string) ($step['key'] ?? '');
            if ($key === '') continue;
            if (!str_starts_with($key, 'collect_')) {
                // Etapas e ordem personalizadas de outras telas permanecem como estão.
                if (!$hasExistingWorkflow && !array_key_exists($key, $merged)) $merged[$key] = $step;
                continue;
            }
            $fieldKeys = $step['config']['field_keys'] ?? [];
            if (is_array($fieldKeys) && $fieldKeys !== []
                && count(array_filter($fieldKeys, static fn ($f) => !isset($alreadyCollected[$f]))) === 0) continue;
            $merged[$key] = $step;
        }
        // Toda coleta nova fica antes de qualquer ação, preservando a ordem relativa preexistente.
        uasort($merged, static function (array $a, array $b): int {
            $priority = static fn ($s): int => ($s['type'] ?? '') === 'collect' ? 0 : 1;
            return ($priority($a) <=> $priority($b))
                ?: ((int) ($a['position'] ?? 100) <=> (int) ($b['position'] ?? 100));
        });
        $steps = array_values($merged);
        foreach ($steps as $i => &$step) $step['position'] = ($i + 1) * 10;
        unset($step);
        return ['steps'=>$steps, 'delete_keys'=>$deleted];
    }

    /** @param list<array<string,mixed>> $current @param list<array<string,mixed>> $incoming
     * @return list<array<string,mixed>> */
    public function policies(array $current, array $incoming): array
    {
        $out = [];
        foreach ($current as $row) {
            $key = (string) ($row['policy_key'] ?? '');
            if ($key === '') continue;
            $out[$key] = [
                'key'=>$key,'type'=>$row['policy_type'],'value'=>self::decodeValue($row['value_json'] ?? null),
                'action'=>$row['action_key'], 'message'=>$row['customer_message'],
                'enabled'=>(int) ($row['enabled'] ?? 1), 'priority'=>(int) ($row['priority'] ?? 100),
            ];
        }
        foreach ($incoming as $row) {
            $key = (string) ($row['key'] ?? '');
            if ($key === '') continue;
            if (!isset($out[$key]) || str_starts_with($key, 'express.')) $out[$key] = $row;
        }
        return array_values($out);
    }

    /** @param array<string,bool> $current @param array<string,bool> $incoming
     * @return array<string,bool> */
    public function capabilities(array $current, array $incoming): array
    {
        foreach ($incoming as $key => $enabled) {
            if (!array_key_exists($key, $current) || in_array($key, self::OWNED_CAPABILITIES, true)) {
                $current[$key] = (bool) $enabled;
            }
        }
        if (($current['calendar.human_approval'] ?? false) === true) $current['calendar.confirm'] = false;
        return $current;
    }

    /** @return array<string,mixed> */
    private static function decode(mixed $value): array
    {
        if (is_array($value)) return $value;
        $decoded = is_string($value) ? json_decode($value, true) : null;
        return is_array($decoded) ? $decoded : [];
    }

    private static function decodeValue(mixed $value): mixed
    {
        if (!is_string($value)) return $value;
        $decoded = json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }
}
