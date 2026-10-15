<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\PublicId;
use PDO;
use RuntimeException;

/** Auditoria somente-leitura sobre as tabelas operacionais do RS Connect. */
final class AgentReadinessService
{
    public function __construct(
        private readonly ?PDO $pdo = null,
        private readonly ?AgentReadinessEvaluator $evaluator = null,
    ) {}

    /** @return array{agent_id:string,status:string,readiness_score:int,can_operate:bool,checklist:list<array<string,mixed>>} */
    public function audit(int $tenantId, int $agentId): array
    {
        $pdo = $this->pdo ?? Database::connection();
        $snapshot = $this->snapshot($tenantId, $agentId, $pdo);
        return $this->report($snapshot);
    }

    /** @param array<string,mixed> $snapshot */
    public function report(array $snapshot): array
    {
        $evaluation = ($this->evaluator ?? new AgentReadinessEvaluator())->evaluate($snapshot);
        $consistency = (new ConfigurationConsistencyAuditService())->inspect($snapshot);
        // Contradições de autorização crítica também bloqueiam ativação, mesmo
        // quando cada checkbox isolado da prontidão parece válido.
        foreach ($consistency['issues'] as $issue) {
            $critical = $issue['severity'] === 'critical';
            if ($critical) {
                $evaluation['can_operate'] = false;
                $evaluation['status'] = 'CONFIGURING';
            }
            $evaluation['readiness_score'] = max(0, $evaluation['readiness_score'] - ($critical ? 8 : 3));
            $evaluation['checklist'][] = [
                'category'=>'configuration_consistency',
                'item'=>$critical ? 'Regras de atendimento conflitantes' : 'Configurações precisam ser alinhadas',
                'status'=>$critical ? 'error' : 'warning',
                'severity'=>$critical ? 'critical' : 'high',
                'message'=>$issue['message'], 'auto_fix_available'=>false,
            ];
        }
        return ['agent_id' => PublicId::encode('agent', (int) ($snapshot['agent']['id'] ?? 0))] + $evaluation
            + ['consistency'=>['ok'=>$consistency['ok'],'issues'=>$consistency['issues']]];
    }

    /**
     * Carrega somente dados persistidos, sem bootstrap/migração/ALTER TABLE.
     * Id do tenant vem da autenticação; nunca do body de requisição.
     *
     * @return array<string,mixed>
     */
    public function snapshot(int $tenantId, int $agentId, ?PDO $pdo = null): array
    {
        if ($tenantId < 1 || $agentId < 1) throw new RuntimeException('Agente não encontrado.', 404);
        $pdo ??= $this->pdo ?? Database::connection();
        $agentQuery = $pdo->prepare('SELECT * FROM ai_agents WHERE id=:aid AND tenant_id=:tid LIMIT 1');
        $agentQuery->execute(['aid'=>$agentId, 'tid'=>$tenantId]);
        $agent = $agentQuery->fetch(PDO::FETCH_ASSOC);
        if (!$agent) throw new RuntimeException('Agente não encontrado.', 404);

        $profileQuery = $pdo->prepare('SELECT id,tenant_id,blueprint_version_id,status,config_json FROM tenant_agent_profiles WHERE tenant_id=:tid LIMIT 1');
        $profileQuery->execute(['tid'=>$tenantId]);
        $profile = $profileQuery->fetch(PDO::FETCH_ASSOC) ?: [];
        $profile['config'] = $this->decode($profile['config_json'] ?? null);

        $capabilityQuery = $pdo->prepare(
            'SELECT capability_key,enabled,agent_id FROM tenant_agent_capabilities
             WHERE tenant_id=:tid AND (agent_id IS NULL OR agent_id=:aid)
             ORDER BY CASE WHEN agent_id IS NULL THEN 0 ELSE 1 END,id'
        );
        $capabilityQuery->execute(['tid'=>$tenantId,'aid'=>$agentId]);
        $capabilities = [];
        foreach ($capabilityQuery->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $capabilities[(string) $row['capability_key']] = (int) $row['enabled'] === 1;
        }

        $channelQuery = $pdo->prepare(
            'SELECT b.status AS binding_status,i.status,i.receive_messages,i.webhook_enabled,
                    i.identity_status,i.profile_phone,i.authorized_phone
             FROM ai_agent_instance_bindings b
             INNER JOIN evolution_instances i ON i.id=b.instance_id AND i.tenant_id=b.tenant_id
             WHERE b.tenant_id=:tid AND b.agent_id=:aid'
        );
        $channelQuery->execute(['tid'=>$tenantId,'aid'=>$agentId]);
        $channels = $channelQuery->fetchAll(PDO::FETCH_ASSOC);

        // Conexão do WhatsApp é distinta do vínculo com o atendente.
        // Uma instância conectada não significa que a IA esteja recebendo mensagens.
        $instanceQuery = $pdo->prepare(
            'SELECT id,status,receive_messages,webhook_enabled,identity_status,
                    authorized_phone,profile_phone
             FROM evolution_instances WHERE tenant_id=:tid ORDER BY is_default DESC,id'
        );
        $instanceQuery->execute(['tid'=>$tenantId]);
        $instances = $instanceQuery->fetchAll(PDO::FETCH_ASSOC);

        // Mesma prioridade de AgentRoutingService: chave específica do agente,
        // depois chave compartilhada do tenant; na ausência, fallback por provedor
        // às variáveis do servidor usadas por AiModelService.
        $credentialQuery = $pdo->prepare(
            'SELECT agent_id,provider,api_key_encrypted
             FROM ai_provider_credentials
             WHERE tenant_id=:tid AND status="active" AND (agent_id=:aid OR agent_id IS NULL)
             ORDER BY (agent_id IS NOT NULL) DESC,is_default DESC,id DESC LIMIT 1'
        );
        $credentialQuery->execute(['tid'=>$tenantId,'aid'=>$agentId]);
        $selectedCredential = $credentialQuery->fetch(PDO::FETCH_ASSOC) ?: null;
        $credentials = (new AgentCredentialAvailabilityService())->evaluate($agent, $selectedCredential);

        $fieldQuery = $pdo->prepare(
            'SELECT field_key,label,prompt_text,active,required_before_schedule,required_for_completion,position
             FROM tenant_triage_fields WHERE tenant_id=:tid ORDER BY position,id'
        );
        $fieldQuery->execute(['tid'=>$tenantId]);
        $fields = $fieldQuery->fetchAll(PDO::FETCH_ASSOC);

        $flowQuery = $pdo->prepare(
            'SELECT step_key,label,step_type,active,position,config_json
             FROM tenant_agent_workflow_steps WHERE tenant_id=:tid ORDER BY position,id'
        );
        $flowQuery->execute(['tid'=>$tenantId]);
        $workflow = [];
        foreach ($flowQuery->fetchAll(PDO::FETCH_ASSOC) as $step) {
            $step['config'] = $this->decode($step['config_json'] ?? null);
            $workflow[] = $step;
        }

        $policyQuery = $pdo->prepare(
            'SELECT policy_key,policy_type,value_json,action_key,customer_message,enabled
             FROM tenant_agent_policies WHERE tenant_id=:tid ORDER BY priority,id'
        );
        $policyQuery->execute(['tid'=>$tenantId]);
        $policies = [];
        foreach ($policyQuery->fetchAll(PDO::FETCH_ASSOC) as $policy) {
            $policy['value'] = is_string($policy['value_json'] ?? null)
                ? json_decode($policy['value_json'], true) : null;
            $policies[] = $policy;
        }

        $preScheduleQuery = $pdo->prepare(
            'SELECT enabled,require_human_approval,ai_can_confirm
             FROM tenant_pre_schedule_settings WHERE tenant_id=:tid LIMIT 1'
        );
        $preScheduleQuery->execute(['tid'=>$tenantId]);
        $availabilityQuery = $pdo->prepare(
            'SELECT enabled,availability_mode,internal_availability_strategy
             FROM tenant_calendar_availability_settings WHERE tenant_id=:tid LIMIT 1'
        );
        $availabilityQuery->execute(['tid'=>$tenantId]);

        $onboardingQuery = $pdo->prepare(
            'SELECT business_timezone,business_hours_json,after_hours_message
             FROM tenant_onboarding_settings WHERE tenant_id=:tid LIMIT 1'
        );
        $onboardingQuery->execute(['tid'=>$tenantId]);
        $tenantQuery = $pdo->prepare('SELECT agent_blueprint_version_id FROM tenants WHERE id=:tid LIMIT 1');
        $tenantQuery->execute(['tid'=>$tenantId]);
        return [
            'onboarding_settings'=>$onboardingQuery->fetch(PDO::FETCH_ASSOC) ?: [],
            'tenant'=>$tenantQuery->fetch(PDO::FETCH_ASSOC) ?: [],
            'pre_schedule_settings'=>$preScheduleQuery->fetch(PDO::FETCH_ASSOC) ?: [],
            'calendar_availability_settings'=>$availabilityQuery->fetch(PDO::FETCH_ASSOC) ?: [],
            'agent'=>$agent, 'profile'=>$profile,
            'channels'=>$channels, 'whatsapp_instances'=>$instances,
            'credentials'=>$credentials,
            'capabilities'=>$capabilities,
            'triage_fields'=>$fields,
            'workflow'=>$workflow, 'policies'=>$policies,
        ];
    }

    private function decode(mixed $raw): array
    {
        $value = is_string($raw) ? json_decode($raw, true) : $raw;
        return is_array($value) ? $value : [];
    }
}
