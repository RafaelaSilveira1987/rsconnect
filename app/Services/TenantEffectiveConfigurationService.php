<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Visão efetiva por domínio: nenhuma string do prompt é autoridade de negócio.
 * Não há atualização, migração, bootstrap ou gravação aqui.
 */
final class TenantEffectiveConfigurationService
{
    /** @param array<string,mixed> $snapshot
     * @return array<string,mixed> */
    public function resolve(array $snapshot): array
    {
        $agent = is_array($snapshot['agent'] ?? null) ? $snapshot['agent'] : [];
        $profile = is_array($snapshot['profile'] ?? null) ? $snapshot['profile'] : [];
        $config = is_array($profile['config'] ?? null) ? $profile['config'] : [];
        $express = is_array($config['express'] ?? null) ? $config['express'] : [];
        $pre = is_array($snapshot['pre_schedule_settings'] ?? null) ? $snapshot['pre_schedule_settings'] : [];
        $availability = is_array($snapshot['calendar_availability_settings'] ?? null) ? $snapshot['calendar_availability_settings'] : [];
        $caps = is_array($snapshot['capabilities'] ?? null) ? $snapshot['capabilities'] : [];

        $hours = BusinessHoursNormalizer::normalize($agent['business_hours_json'] ?? null);
        $human = (int) ($pre['require_human_approval'] ?? 0) === 1;
        $mayConfirm = !$human && (int) ($pre['ai_can_confirm'] ?? 0) === 1
            && ($caps['calendar.confirm'] ?? false) === true;
        $fields = array_values(array_filter((array) ($snapshot['triage_fields'] ?? []),
            static fn ($field) => is_array($field) && !empty($field['active'])));

        return [
            'schema_version'=>1,
            'blueprint'=>['source'=>'tenant_agent_profiles',
                'version_id'=>$profile['blueprint_version_id'] ?? null],
            'triage'=>['source'=>'tenant_triage_fields',
                'fields'=>array_map(static fn (array $field) => [
                    'key'=>(string) ($field['field_key'] ?? ''),
                    'required_before_schedule'=>!empty($field['required_before_schedule']),
                ], $fields)],
            'workflow'=>['source'=>'tenant_agent_workflow_steps',
                'count'=>count(array_filter((array) ($snapshot['workflow'] ?? []),
                    static fn ($step) => is_array($step) && !empty($step['active'])))],
            'commercial'=>['policy_source'=>'tenant_agent_policies',
                'reference_source'=>$express !== [] ? 'tenant_agent_profiles.express' : null],
            'conversation_hours'=>[
                'source'=>'ai_agents.business_hours_json',
                'enabled'=>(int) ($agent['business_hours_enabled'] ?? 0) === 1,
                'timezone'=>$agent['business_timezone'] ?? null,
                'ranges'=>$hours,
            ],
            'calendar'=>['source'=>'tenant_pre_schedule_settings + tenant_calendar_availability_settings',
                'enabled'=>(int) ($availability['enabled'] ?? 0) === 1,
                'require_human_approval'=>$human,
                'can_confirm_automatically'=>$mayConfirm],
            'meeting_link'=>['source'=>'calendar_appointments.meeting_url (quando houver), senão tenant_agent_profiles.express.routing',
                'default'=>$express['routing']['default_meeting_link'] ?? null],
            'capabilities'=>['source'=>'tenant_agent_capabilities', 'values'=>$caps],
        ];
    }
}
