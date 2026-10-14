<?php

declare(strict_types=1);

namespace App\Services;

use App\DTO\ExpressOnboardingData;
use App\Core\Database;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Unit of work da Configuração Expressa. Só o serviço grava no banco.
 * O ID do tenant SEMPRE vem da sessão/autorização (nunca do JSON).
 */
final class ExpressOnboardingService
{
    public function __construct(
        private readonly ?PDO $pdo = null,
        private readonly ?AgentBlueprintService $blueprints = null,
    ) {}

    /**
     * @return array{tenant_id:int,blueprint_id:int,version_id:int,configuration_saved:bool,connected_agents_updated:int,audit:array<string,mixed>,requires_review:bool}
     */
    public function apply(int $tenantId, ExpressOnboardingData $dto): array
    {
        if ($tenantId < 1) throw new RuntimeException('Empresa não identificada.');
        $pdo = $this->pdo ?? Database::connection();
        $blueprints = $this->blueprints ?? new AgentBlueprintService();
        if ($pdo->inTransaction()) throw new RuntimeException('Transação externa não permitida neste endpoint.');
        $pdo->beginTransaction();
        try {
            // Serializa gravações concorrentes para a mesma empresa.
            $lock = $pdo->prepare('SELECT id FROM tenants WHERE id = :tenant_id FOR UPDATE');
            $lock->execute(['tenant_id'=>$tenantId]);
            if (!(int) $lock->fetchColumn()) throw new RuntimeException('Empresa não encontrada.');

            // Usa o catálogo existente, sem duplicar políticas em templates espalhados.
            $lookup = $pdo->prepare(
                'SELECT b.id FROM agent_blueprints b
                 INNER JOIN business_niches n ON n.id = b.niche_id
                 WHERE n.code = :niche AND n.active = 1 AND b.active = 1
                 ORDER BY b.id ASC LIMIT 1'
            );
            $lookup->execute(['niche'=>$dto->segment->nicheCode()]);
            $blueprintId = (int) $lookup->fetchColumn();
            if ($blueprintId < 1) throw new RuntimeException('Blueprint não disponível para este segmento.');
            $base = $blueprints->blueprint($blueprintId, $pdo);
            if (!$base || (int) ($base['version_id'] ?? 0) < 1) {
                throw new RuntimeException('Blueprint sem versão publicada.');
            }
            $versionId = (int) $base['version_id'];
            $compiled = $blueprints->compileExpress($dto, (array) ($base['config'] ?? []));
            $json = static fn (mixed $value): string => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $profile = $pdo->prepare(
                'INSERT INTO tenant_agent_profiles
                  (tenant_id,niche_id,blueprint_id,blueprint_version_id,interaction_mode,status,config_json,customized,applied_at)
                 VALUES (:tenant_id,:niche,:blueprint,:version,"hybrid","active",:config,1,UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE
                  niche_id=VALUES(niche_id),blueprint_id=VALUES(blueprint_id),
                  blueprint_version_id=VALUES(blueprint_version_id), status="active",
                  config_json=VALUES(config_json),customized=1,applied_at=UTC_TIMESTAMP()'
            );
            // Policy Engine exige perfil active; isto NÃO liga ai_agents.auto_reply_enabled nem status.
            // O endpoint é o botão explícito Aplicar, e não um salvamento de rascunho.
            $profile->execute([
                'tenant_id'=>$tenantId,'niche'=>(int) $base['niche_id'],
                'blueprint'=>$blueprintId,'version'=>$versionId,'config'=>$json($compiled['config']),
            ]);

            $this->persistCapabilities($pdo, $tenantId, $compiled['capabilities']);
            $this->persistTriage($pdo, $tenantId, $compiled['triage_fields'], $json);
            $this->persistPolicies($pdo, $tenantId, $compiled['policies'], $json);
            $this->persistWorkflow($pdo, $tenantId, $compiled['workflow'], $json);
            $pdo->prepare(
                'UPDATE tenants SET business_niche_id=:niche, agent_blueprint_id=:blueprint,
                 agent_blueprint_version_id=:version WHERE id=:tenant_id'
            )->execute(['niche'=>(int) $base['niche_id'], 'blueprint'=>$blueprintId,
                'version'=>$versionId,'tenant_id'=>$tenantId]);

            // Existing runtime source of truth for business hours (NOT calendar slots).
            // Never create an ai_agent without a WhatsApp instance binding.
            $updated = $this->updateExistingAgent($pdo, $tenantId, $dto, $compiled);
            // tenant_onboarding_settings é usado por SLA e pela tela de implantação.
            $this->syncOnboardingHours($pdo, $tenantId, $dto, $compiled['hours_json']);

            // Valida as invariantes antes do COMMIT. Sem side effects LLM.
            $audit = (new AgentRuntimeConfigurationService())->audit([
                'triage_fields'=>array_map(static fn ($f) => [
                    'field_key'=>$f['key'],'label'=>$f['label'],'prompt_text'=>$f['prompt'],
                    'active'=>1,'required_before_schedule'=>$f['required_before_schedule'] ?? false,
                    'required_for_completion'=>$f['required_for_completion'] ?? false,
                ], $compiled['triage_fields']),
                'workflow'=>array_map(static fn ($step) => [
                    'step_key'=>$step['key'],'label'=>$step['label'],'step_type'=>$step['type'],
                    'config'=>$step['config'] ?? [],'active'=>1,'position'=>$step['position'],
                ], $compiled['workflow']),
                'policies'=>array_map(static fn ($p) => [
                    'policy_key'=>$p['key'],'policy_type'=>$p['type'], 'value'=>$p['value'],
                    'action_key'=>$p['action'], 'customer_message'=>$p['message'], 'enabled'=>1,
                ], $compiled['policies']),
            ]);
            if (!$audit['ok']) {
                throw new RuntimeException('Invariantes do workflow não aprovadas: ' . implode('; ', $audit['errors']));
            }
            $pdo->commit();
            return [
                'tenant_id'=>$tenantId,'blueprint_id'=>$blueprintId,'version_id'=>$versionId,
                'configuration_saved'=>true, 'connected_agents_updated'=>$updated,
                'audit'=>['ok'=>$audit['ok'],'warnings'=>$audit['warnings']],
                'requires_review'=>true,
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /** @param array<string,bool> $capabilities */
    private function persistCapabilities(PDO $pdo, int $tenantId, array $capabilities): void
    {
        $pdo->prepare('DELETE FROM tenant_agent_capabilities WHERE tenant_id=:tid AND agent_id IS NULL')
            ->execute(['tid'=>$tenantId]);
        $stmt = $pdo->prepare(
            'INSERT INTO tenant_agent_capabilities (tenant_id,agent_id,capability_key,enabled,source)
             VALUES (:tid,NULL,:key,:enabled,"tenant")'
        );
        foreach ($capabilities as $key => $enabled) {
            $stmt->execute(['tid'=>$tenantId,'key'=>$key,'enabled'=>$enabled ? 1 : 0]);
        }
    }

    /** @param list<array<string,mixed>> $fields */
    private function persistTriage(PDO $pdo, int $tenantId, array $fields, callable $json): void
    {
        $pdo->prepare('DELETE FROM tenant_triage_fields WHERE tenant_id=:tid')->execute(['tid'=>$tenantId]);
        $stmt = $pdo->prepare(
            'INSERT INTO tenant_triage_fields
              (tenant_id,field_key,label,field_type,prompt_text,options_json,
               required_before_schedule,required_for_completion,active,position,source)
             VALUES (:tid,:key,:label,:type,:prompt,:options,:before,:required,1,:position,"tenant")'
        );
        foreach ($fields as $f) {
            $stmt->execute([
                'tid'=>$tenantId,'key'=>$f['key'],'label'=>$f['label'],
                'type'=>$f['type'] ?? 'text','prompt'=>$f['prompt'],
                'options'=>empty($f['options']) ? null : $json($f['options']),
                'before'=>!empty($f['required_before_schedule']) ? 1 : 0,
                'required'=>!empty($f['required_for_completion']) ? 1 : 0,
                'position'=>$f['position'],
            ]);
        }
    }

    /** @param list<array<string,mixed>> $policies */
    private function persistPolicies(PDO $pdo, int $tenantId, array $policies, callable $json): void
    {
        $pdo->prepare('DELETE FROM tenant_agent_policies WHERE tenant_id=:tid')->execute(['tid'=>$tenantId]);
        $stmt = $pdo->prepare(
            'INSERT INTO tenant_agent_policies
              (tenant_id,policy_key,policy_type,value_json,action_key,customer_message,enabled,priority,source)
             VALUES (:tid,:key,:type,:value,:action,:message,1,:priority,"tenant")'
        );
        foreach ($policies as $index => $p) {
            $stmt->execute([
                'tid'=>$tenantId,'key'=>$p['key'],'type'=>$p['type'],'value'=>$json($p['value']),
                'action'=>$p['action'] ?? 'block','message'=>($p['message'] ?? '') !== '' ? $p['message'] : null,
                'priority'=>$p['priority'] ?? (($index+1)*10),
            ]);
        }
    }

    /** @param list<array<string,mixed>> $workflow */
    private function persistWorkflow(PDO $pdo, int $tenantId, array $workflow, callable $json): void
    {
        $pdo->prepare('DELETE FROM tenant_agent_workflow_steps WHERE tenant_id=:tid')->execute(['tid'=>$tenantId]);
        $stmt = $pdo->prepare(
            'INSERT INTO tenant_agent_workflow_steps
              (tenant_id,step_key,label,step_type,config_json,active,position,source)
             VALUES (:tid,:key,:label,:type,:config,1,:position,"tenant")'
        );
        foreach ($workflow as $step) {
            $stmt->execute([
                'tid'=>$tenantId,'key'=>$step['key'],'label'=>$step['label'],
                'type'=>$step['type'],'config'=>$json($step['config'] ?? []),
                'position'=>$step['position'],
            ]);
        }
    }

    private function updateExistingAgent(PDO $pdo, int $tenantId, ExpressOnboardingData $dto, array $compiled): int
    {
        // Configura APENAS o agente padrão existente. Mantém status/auto_reply_enabled.
        $stmt = $pdo->prepare(
            'UPDATE ai_agents SET name=:name, segment=:segment, system_prompt=:prompt,
              knowledge_base=:knowledge, business_hours_enabled=1,
              business_timezone=:timezone,business_hours_json=:hours,
              after_hours_message=:after_hours
             WHERE tenant_id=:tid AND is_default=1'
        );
        $afterHoursMessage = match ($dto->outOfHoursAction) {
            'capture_lead_and_promise_contact'=>'Estamos fora do horário. Vou registrar seu contato para a equipe retornar no próximo expediente.',
            'handoff_to_human'=>'Estamos fora do horário. Seu contato será encaminhado à equipe para atendimento quando disponível.',
            default=>'No momento estamos fora do horário de atendimento. Retornaremos no próximo expediente.',
        };
        $stmt->execute([
            'name'=>$dto->agentName,'segment'=>$dto->segment->value,
            'prompt'=>$compiled['system_prompt'],'knowledge'=>$compiled['knowledge_text'],
            'timezone'=>$dto->timezone,'hours'=>$compiled['hours_json'],
            'after_hours'=>$afterHoursMessage,'tid'=>$tenantId,
        ]);
        return $stmt->rowCount();
    }

    private function syncOnboardingHours(PDO $pdo, int $tenantId, ExpressOnboardingData $dto, string $hoursJson): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO tenant_onboarding_settings
              (tenant_id,business_timezone,business_hours_json,after_hours_message,human_handoff_message,cooldown_seconds)
             VALUES (:tid,:timezone,:hours,:outside,:handoff,60)
             ON DUPLICATE KEY UPDATE business_timezone=VALUES(business_timezone),
              business_hours_json=VALUES(business_hours_json),
              after_hours_message=VALUES(after_hours_message)'
        );
        $stmt->execute([
            'tid'=>$tenantId,'timezone'=>$dto->timezone,'hours'=>$hoursJson,
            'outside'=>'No momento estamos fora do horário. Sua solicitação ficará registrada para continuidade no próximo expediente.',
            'handoff'=>'Vou encaminhar sua solicitação para uma pessoa da equipe.',
        ]);
    }
}
