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
    private const DAYS = [
        'mon'=>'monday', 'tue'=>'tuesday', 'wed'=>'wednesday', 'thu'=>'thursday',
        'fri'=>'friday', 'sat'=>'saturday', 'sun'=>'sunday',
    ];

    /** @return list<array{day:string,start:string,end:string}> */
    public static function normalizeHours(mixed $hours): array
    {
        if (!is_array($hours)) return [];
        $result = [];
        if (array_is_list($hours)) {
            foreach ($hours as $interval) {
                if (!is_array($interval) || !is_string($interval['day'] ?? null)
                    || !is_string($interval['start'] ?? null) || !is_string($interval['end'] ?? null)) continue;
                if (!in_array($interval['day'], self::DAYS, true)) continue;
                $result[] = ['day'=>$interval['day'], 'start'=>$interval['start'], 'end'=>$interval['end']];
            }
        } else {
            foreach (self::DAYS as $short => $long) {
                $ranges = $hours[$short] ?? $hours[$long] ?? [];
                if (!is_array($ranges)) continue;
                foreach ($ranges as $range) {
                    if (!is_array($range)) continue;
                    $start = $range['start'] ?? $range[0] ?? null;
                    $end = $range['end'] ?? $range[1] ?? null;
                    if (!is_string($start) || !is_string($end)) continue;
                    $result[] = ['day'=>$long,'start'=>$start,'end'=>$end];
                }
            }
        }
        $order = array_flip(array_values(self::DAYS));
        usort($result, static fn (array $a, array $b): int =>
            ($order[$a['day']] <=> $order[$b['day']]) ?: strcmp($a['start'], $b['start']));
        return $result;
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

        $agentQuery = $pdo->prepare('SELECT id,name,is_default FROM ai_agents WHERE tenant_id=:tenant ORDER BY is_default DESC,id ASC');
        $agentQuery->execute(['tenant'=>$tenantId]);
        $agents = array_map(static fn (array $a): array => [
            'id'=>\App\Core\PublicId::encode('agent', (int) $a['id']),
            'name'=>(string) $a['name'],
            'is_default'=>(int) ($a['is_default'] ?? 0) === 1,
        ], $agentQuery->fetchAll(PDO::FETCH_ASSOC));
        $companyQuery = $pdo->prepare('SELECT name FROM tenants WHERE id=:tenant LIMIT 1');
        $companyQuery->execute(['tenant'=>$tenantId]);
        $companyName = trim((string) ($companyQuery->fetchColumn() ?: 'sua empresa'));

        // A tabela operacional é a fonte da triagem. Não depender de prompts compilados.
        $fieldsQuery = $pdo->prepare(
            'SELECT field_key,prompt_text,active FROM tenant_triage_fields WHERE tenant_id=:tenant ORDER BY position,id'
        );
        $fieldsQuery->execute(['tenant'=>$tenantId]);
        $operationalFields = $fieldsQuery->fetchAll(PDO::FETCH_ASSOC);
        $fields = $hasSaved ? $operationalFields : (array) ($config['triage_fields'] ?? []);
        $customQuestions = [];
        $hasName = false;
        $hasPhone = false;
        foreach ($fields as $field) {
            if (!is_array($field) || (array_key_exists('active', $field) && !(bool) $field['active'])) continue;
            $key = (string) ($field['field_key'] ?? $field['key'] ?? '');
            $prompt = trim((string) ($field['prompt_text'] ?? $field['prompt'] ?? ''));
            if ($key === 'requester_name') $hasName = true;
            if ($key === 'contact_phone') $hasPhone = true;
            if (str_starts_with($key, 'express_') && $prompt !== '') $customQuestions[] = $prompt;
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
                'collect_name'=>$hasSaved ? $hasName : true,
                'collect_phone'=>$hasSaved ? $hasPhone : true,
                'custom_questions'=>$customQuestions,
            ],
            'routing_and_schedule' => [
                'timezone'=>(string) ($routing['timezone'] ?? 'America/Sao_Paulo'),
                // Se uma config existente estiver inválida, não inventar novos horários.
                'business_hours'=>$hasSaved ? self::normalizeHours($routing['business_hours'] ?? null) : $defaultHours,
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
        return ['payload'=>$payload, 'agents'=>$agents, 'has_saved'=>$hasSaved];
    }
}
