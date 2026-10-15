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
            $lock = $pdo->prepare('SELECT id,agent_blueprint_id FROM tenants WHERE id = :tenant_id FOR UPDATE');
            $lock->execute(['tenant_id'=>$tenantId]);
            $tenant = $lock->fetch(PDO::FETCH_ASSOC) ?: [];
            if (empty($tenant['id'])) throw new RuntimeException('Empresa não encontrada.');
            $existingProfile = $pdo->prepare(
                'SELECT blueprint_id,blueprint_version_id,config_json FROM tenant_agent_profiles
                 WHERE tenant_id=:tid FOR UPDATE'
            );
            $existingProfile->execute(['tid'=>$tenantId]);
            $previous = $existingProfile->fetch(PDO::FETCH_ASSOC) ?: [];
            $preferredBlueprintId = (int) (($previous['blueprint_id'] ?? 0) ?: ($tenant['agent_blueprint_id'] ?? 0));

            // Usa o catálogo existente, sem duplicar políticas em templates espalhados.
            $lookup = $pdo->prepare(
                'SELECT b.id FROM agent_blueprints b
                 INNER JOIN business_niches n ON n.id = b.niche_id
                 WHERE n.code = :niche AND n.active = 1 AND b.active = 1
                 ORDER BY CASE WHEN b.id=:preferred THEN 0 ELSE 1 END, b.id ASC LIMIT 1'
            );
            $lookup->execute(['niche'=>$dto->segment->nicheCode(), 'preferred'=>$preferredBlueprintId]);
            $blueprintId = (int) $lookup->fetchColumn();
            if ($blueprintId < 1) throw new RuntimeException('Blueprint não disponível para este segmento.');
            $base = $blueprints->blueprint($blueprintId, $pdo);
            if (!$base || (int) ($base['version_id'] ?? 0) < 1) {
                throw new RuntimeException('Blueprint sem versão publicada.');
            }
            $versionId = (int) $base['version_id'];
            // A edição diária NÃO é um comando de trocar Blueprint ou sua versão.
            // Uma versão nova publicada nunca migra uma empresa implicitamente.
            if (!empty($previous['blueprint_id']) && (int) $previous['blueprint_id'] !== $blueprintId) {
                throw new RuntimeException('Esta empresa utiliza outro modelo. A troca de segmento exige revisão na Ativação Assistida.');
            }
            $newerBlueprintAvailable = false;
            if (!empty($previous['blueprint_version_id'])
                && (int) $previous['blueprint_version_id'] !== $versionId) {
                $pinned = $pdo->prepare(
                    'SELECT config_json FROM agent_blueprint_versions
                     WHERE id=:version AND blueprint_id=:blueprint LIMIT 1'
                );
                $pinned->execute(['version'=>(int) $previous['blueprint_version_id'], 'blueprint'=>$blueprintId]);
                $rawPinned = $pinned->fetchColumn();
                $pinnedConfig = is_string($rawPinned) ? json_decode($rawPinned, true) : null;
                if (!is_array($pinnedConfig)) {
                    throw new RuntimeException('Não foi possível ler a versão aplicada. A administração precisa revisar o modelo.');
                }
                $base['config'] = $pinnedConfig;
                $versionId = (int) $previous['blueprint_version_id'];
                $newerBlueprintAvailable = true;
            }
            $compiled = $blueprints->compileExpress($dto, (array) ($base['config'] ?? []));
            // Preserva personalizações que não fazem parte do contrato limitado Express.
            $previousConfig = json_decode((string) ($previous['config_json'] ?? ''), true);
            $config = is_array($previousConfig) ? $previousConfig : [];
            foreach ($compiled['config'] as $key => $value) $config[$key] = $value;
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
                'blueprint'=>$blueprintId,'version'=>$versionId,'config'=>$json($config),
            ]);

            // Reconcilia por chave; campos de outras telas permanecem operacionais.
            $effective = (new TenantExpressConfigurationWriter())->save($pdo, $tenantId, $compiled);
            foreach (['triage_fields','workflow','policies','capabilities'] as $domain) {
                $config[$domain] = $effective[$domain];
            }
            $pdo->prepare('UPDATE tenant_agent_profiles SET config_json=:config WHERE tenant_id=:tid')
                ->execute(['tid'=>$tenantId,'config'=>$json($config)]);
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
                    'active'=>$f['active'] ?? 1,'required_before_schedule'=>$f['required_before_schedule'] ?? false,
                    'required_for_completion'=>$f['required_for_completion'] ?? false,
                ], $effective['triage_fields']),
                'workflow'=>array_map(static fn ($step) => [
                    'step_key'=>$step['key'],'label'=>$step['label'],'step_type'=>$step['type'],
                    'config'=>$step['config'] ?? [],'active'=>$step['active'] ?? 1,'position'=>$step['position'],
                ], $effective['workflow']),
                'policies'=>array_map(static fn ($p) => [
                    'policy_key'=>$p['key'],'policy_type'=>$p['type'], 'value'=>$p['value'],
                    'action_key'=>$p['action'], 'customer_message'=>$p['message'], 'enabled'=>$p['enabled'] ?? 1,
                ], $effective['policies']),
            ]);
            if (!$audit['ok']) {
                throw new RuntimeException('Invariantes do workflow não aprovadas: ' . implode('; ', $audit['errors']));
            }
            $pdo->commit();
            return [
                'tenant_id'=>$tenantId,'blueprint_id'=>$blueprintId,'version_id'=>$versionId,
                'configuration_saved'=>true, 'connected_agents_updated'=>$updated,
                'audit'=>['ok'=>$audit['ok'],'warnings'=>$audit['warnings']],
                'protected_fields'=>$effective['protected_fields'],
                'newer_blueprint_available'=>$newerBlueprintAvailable,
                'requires_review'=>true,
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
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
