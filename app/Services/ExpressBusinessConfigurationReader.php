<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

/**
 * Leitura canônica do editor e do wizard. O compilador armazena horários no
 * mapa mon=>[[inicio,fim]], a API aceita uma lista day/start/end.
 * Sem UPDATE/DDL: apenas transforma os dados persistidos para o contrato HTTP.
 */
final class ExpressBusinessConfigurationReader
{
    /** @return list<array{day:string,start:string,end:string}> */
    public static function normalizeHours(mixed $hours): array
    {
        return BusinessHoursNormalizer::asApiList($hours);
    }

    /** @return array{payload:array<string,mixed>,agents:list<array<string,mixed>>,has_saved:bool} */
    public function load(PDO $pdo, int $tenantId): array
    {
        if ($tenantId < 1) throw new RuntimeException('Empresa não identificada.');
        $query = $pdo->prepare('SELECT config_json FROM tenant_agent_profiles WHERE tenant_id=:tenant LIMIT 1');
        $query->execute(['tenant'=>$tenantId]);
        $stored = json_decode((string) ($query->fetchColumn() ?: ''), true);
        $config = is_array($stored) ? $stored : [];
        $express = is_array($config['express'] ?? null) ? $config['express'] : [];
        $hasSaved = $express !== [];

        $agentQuery = $pdo->prepare('SELECT id,name,is_default,business_hours_enabled,business_hours_json,
            business_timezone,after_hours_message FROM ai_agents WHERE tenant_id=:tenant ORDER BY is_default DESC,id ASC');
        $agentQuery->execute(['tenant'=>$tenantId]);
        $agentRows = $agentQuery->fetchAll(PDO::FETCH_ASSOC);
        $primary = $agentRows[0] ?? [];
        $agents = array_map(static fn (array $a): array => [
            'id'=>\App\Core\PublicId::encode('agent', (int) $a['id']),
            'name'=>(string) $a['name'],
            'is_default'=>(int) ($a['is_default'] ?? 0) === 1,
        ], $agentRows);
        $companyQuery = $pdo->prepare('SELECT name FROM tenants WHERE id=:tenant LIMIT 1');
        $companyQuery->execute(['tenant'=>$tenantId]);
        $companyName = trim((string) ($companyQuery->fetchColumn() ?: 'sua empresa'));

        // A tabela operacional é a fonte da triagem. Não depender de prompts compilados.
        $fieldsQuery = $pdo->prepare(
            'SELECT field_key,prompt_text,active FROM tenant_triage_fields WHERE tenant_id=:tenant ORDER BY position,id'
        );
        $fieldsQuery->execute(['tenant'=>$tenantId]);
        $operationalFields = $fieldsQuery->fetchAll(PDO::FETCH_ASSOC);
        $fields = $operationalFields !== [] ? $operationalFields : (array) ($config['triage_fields'] ?? []);
        $customQuestions = [];
        $protectedQuestions = [];
        $hasName = false;
        $hasPhone = false;
        foreach ($fields as $field) {
            if (!is_array($field) || (array_key_exists('active', $field) && !(bool) $field['active'])) continue;
            $key = (string) ($field['field_key'] ?? $field['key'] ?? '');
            $prompt = trim((string) ($field['prompt_text'] ?? $field['prompt'] ?? ''));
            if ($key === 'requester_name') $hasName = true;
            if ($key === 'contact_phone') $hasPhone = true;
            if (str_starts_with($key, 'express_') && $prompt !== '') $customQuestions[] = $prompt;
            elseif (!in_array($key, ['requester_name','contact_phone'], true) && $prompt !== '') {
                $protectedQuestions[] = ['key'=>$key, 'question'=>$prompt];
            }
        }
        $capabilities = is_array($config['capabilities'] ?? null) ? $config['capabilities'] : [];
        // Capability edits made with Auto-Fix must be reflected by the editor too.
        $capQuery = $pdo->prepare(
            'SELECT capability_key,enabled FROM tenant_agent_capabilities WHERE tenant_id=:tenant AND agent_id IS NULL'
        );
        $capQuery->execute(['tenant'=>$tenantId]);
        foreach ($capQuery->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $capabilities[(string) $row['capability_key']] = (int) $row['enabled'] === 1;
        }
        $commercial = is_array($express['commercial'] ?? null) ? $express['commercial'] : [];
        $routing = is_array($express['routing'] ?? null) ? $express['routing'] : [];
        $defaultHours = [];
        foreach (['monday','tuesday','wednesday','thursday','friday'] as $day) {
            $defaultHours[] = ['day'=>$day,'start'=>'08:00','end'=>'18:00'];
        }
        // O runtime é autoridade do expediente de conversa. O JSON da Expressa
        // representa o último editor, mas pode estar desatualizado após onboarding.
        $operationalHours = (int) ($primary['business_hours_enabled'] ?? 0) === 1
            ? BusinessHoursNormalizer::normalize($primary['business_hours_json'] ?? null) : null;
        $hoursForEditor = $operationalHours !== null
            ? BusinessHoursNormalizer::asApiList($operationalHours)
            : ($hasSaved ? self::normalizeHours($routing['business_hours'] ?? null) : $defaultHours);
        $payload = [
            'business_info' => [
                'agent_name'=>(string) ($express['agent_name'] ?? ($agents[0]['name'] ?? ('Assistente ' . $companyName))),
                'segment'=>(string) ($express['segment'] ?? 'general_services'),
                'tone_of_voice'=>(string) ($express['tone_of_voice'] ?? 'empathetic_and_welcoming'),
            ],
            'commercial_info' => [
                'pricing_strategy'=>(string) ($commercial['pricing_strategy'] ?? 'on_request'),
                'price_value'=>$commercial['price_value'] ?? null,
                'payment_methods'=>array_values((array) ($commercial['payment_methods'] ?? [])),
            ],
            'triage_config' => [
                'collect_name'=>$fields !== [] ? $hasName : true,
                'collect_phone'=>$fields !== [] ? $hasPhone : true,
                'custom_questions'=>$customQuestions,
            ],
            'routing_and_schedule' => [
                'timezone'=>(string) (($primary['business_timezone'] ?? '') ?: ($routing['timezone'] ?? 'America/Sao_Paulo')),
                // Se uma config existente estiver inválida, não inventar novos horários.
                'business_hours'=>$hoursForEditor,
                'out_of_hours_action'=>(string) ($routing['out_of_hours_action'] ?? 'capture_lead_and_promise_contact'),
                'default_meeting_link'=>$routing['default_meeting_link'] ?? null,
            ],
            'knowledge_base' => [
                'faq'=>array_values(array_filter((array) ($express['faq'] ?? []), 'is_array')),
            ],
            'capabilities' => [
                'allow_rescheduling'=>(bool) ($capabilities['calendar.reschedule'] ?? false),
                'escalate_to_human_on_error'=>(bool) ($capabilities['handoff.on_error'] ?? true),
                'send_meeting_link_on_confirmation'=>(bool) ($capabilities['calendar.confirmation_link'] ?? false),
            ],
        ];
        return ['payload'=>$payload, 'agents'=>$agents, 'has_saved'=>$hasSaved,
            'protected_questions'=>$protectedQuestions];
    }
}
