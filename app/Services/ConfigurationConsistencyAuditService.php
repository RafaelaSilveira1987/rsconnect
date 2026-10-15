<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Diagnóstico exclusivamente de leitura. Divergências precisam ser resolvidas
 * pela área responsável, nunca por um Auto-Fix que sobrescreve silenciosamente.
 */
final class ConfigurationConsistencyAuditService
{
    /** @param array<string,mixed> $snapshot
     * @return array{ok:bool,issues:list<array<string,string>>,effective:array<string,mixed>} */
    public function inspect(array $snapshot): array
    {
        $issues = [];
        $add = static function (string $key, string $severity, string $message, string $owner) use (&$issues): void {
            $issues[] = ['key'=>$key,'severity'=>$severity,'message'=>$message,'owner'=>$owner];
        };
        $effective = (new TenantEffectiveConfigurationService())->resolve($snapshot);
        $profile = is_array($snapshot['profile'] ?? null) ? $snapshot['profile'] : [];
        $config = is_array($profile['config'] ?? null) ? $profile['config'] : [];
        $express = is_array($config['express'] ?? null) ? $config['express'] : [];
        $agent = is_array($snapshot['agent'] ?? null) ? $snapshot['agent'] : [];
        $onboarding = is_array($snapshot['onboarding_settings'] ?? null) ? $snapshot['onboarding_settings'] : [];
        $caps = is_array($snapshot['capabilities'] ?? null) ? $snapshot['capabilities'] : [];
        $pre = is_array($snapshot['pre_schedule_settings'] ?? null) ? $snapshot['pre_schedule_settings'] : [];

        $runtimeHours = BusinessHoursNormalizer::normalize($agent['business_hours_json'] ?? null);
        foreach ([
            'Ativação Assistida' => $onboarding['business_hours_json'] ?? null,
            'Configuração Expressa' => $express['routing']['business_hours'] ?? null,
        ] as $origin => $value) {
            if ($value === null || $value === '') continue;
            $other = BusinessHoursNormalizer::normalize($value);
            if ($other === null) {
                $add('invalid_hours_' . ($origin === 'Ativação Assistida' ? 'onboarding' : 'express'),
                    'high', "O horário de {$origin} tem formato inválido.", 'empresa');
            } elseif ($runtimeHours !== null && $other !== $runtimeHours) {
                $add('hours_mismatch_' . ($origin === 'Ativação Assistida' ? 'onboarding' : 'express'),
                    'high', "O horário mostrado em {$origin} difere do horário operacional do assistente.", 'empresa');
            }
        }
        if ((int) ($pre['require_human_approval'] ?? 0) === 1
            && ((int) ($pre['ai_can_confirm'] ?? 0) === 1 || ($caps['calendar.confirm'] ?? false) === true)) {
            $add('human_approval_conflict','critical',
                'A empresa exige aprovação humana, mas outra permissão autoriza confirmação automática.', 'agenda');
        }
        if (($caps['calendar.confirmation_link'] ?? false) === true) {
            $url = trim((string) ($express['routing']['default_meeting_link'] ?? ''));
            if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false
                || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
                $add('meeting_link_missing','high',
                    'O envio de link está ligado, mas o link padrão não é HTTPS válido. Links por compromisso podem existir.', 'empresa');
            }
        }
        $fields = array_values(array_filter((array) ($snapshot['triage_fields'] ?? []),
            static fn ($f) => is_array($f) && !empty($f['active'])));
        $unique = [];
        foreach ($fields as $field) {
            $rawPrompt = (string) ($field['prompt_text'] ?? '');
            $prompt = trim(function_exists('mb_strtolower') ? mb_strtolower($rawPrompt) : strtolower($rawPrompt));
            if ($prompt === '') continue;
            if (isset($unique[$prompt]) && ($unique[$prompt] !== ($field['field_key'] ?? null))) {
                $add('duplicate_triage','high',
                    'Há perguntas ativas com o mesmo texto em campos diferentes. Revise antes de aplicar um modelo.', 'empresa');
                break;
            }
            $unique[$prompt] = $field['field_key'] ?? null;
        }
        $tenant = is_array($snapshot['tenant'] ?? null) ? $snapshot['tenant'] : [];
        if (!empty($tenant['agent_blueprint_version_id']) && !empty($profile['blueprint_version_id'])
            && (int) $tenant['agent_blueprint_version_id'] !== (int) $profile['blueprint_version_id']) {
            $add('blueprint_version_mismatch','high',
                'O modelo registrado pela empresa não coincide com a versão do perfil operacional.', 'admin');
        }
        return ['ok'=>!array_filter($issues, static fn ($i) => $i['severity'] === 'critical'),
            'issues'=>$issues, 'effective'=>$effective];
    }
}
