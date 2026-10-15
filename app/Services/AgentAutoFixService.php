<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Database;
use PDO;
use RuntimeException;
use Throwable;

/** Correções estruturais allowlist; nenhum dado gerado por LLM chega ao SQL. */
final class AgentAutoFixService
{
    private const ALLOWED_ACTIONS = [
        'set_default_out_of_hours_action',
        'disable_meeting_link_capability',
        'restore_required_triage_steps',
    ];

    private const DEFAULT_OUTSIDE_MESSAGE =
        'Estamos fora do horário de atendimento. Vou registrar seu contato para que a equipe retorne no próximo expediente.';

    private const MEETING_PROMPT_ENABLED =
        'Link em confirmação: use apenas a saída verificada da função de confirmação de agendamento.';
    private const MEETING_PROMPT_DISABLED =
        'Link em confirmação: não compartilhe link de reunião automaticamente.';

    public function __construct(
        private readonly ?PDO $pdo = null,
        private readonly ?AgentReadinessService $readiness = null,
    ) {}

    /** @return array{applied:bool,action:string,readiness:array<string,mixed>} */
    public function apply(int $tenantId, int $agentId, string $action): array
    {
        if (!in_array($action, self::ALLOWED_ACTIONS, true)) {
            throw new RuntimeException('Ação de correção não permitida.', 422);
        }
        $pdo = $this->pdo ?? Database::connection();
        $readiness = $this->readiness ?? new AgentReadinessService($pdo);
        if ($pdo->inTransaction()) throw new RuntimeException('Transação externa não permitida.', 409);
        $pdo->beginTransaction();
        try {
            // Serializa correções e salva apenas no tenant autenticado.
            $lockAgent = $pdo->prepare('SELECT id FROM ai_agents WHERE id=:aid AND tenant_id=:tid FOR UPDATE');
            $lockAgent->execute(['aid'=>$agentId,'tid'=>$tenantId]);
            if (!$lockAgent->fetchColumn()) throw new RuntimeException('Agente não encontrado.', 404);
            $lockProfile = $pdo->prepare('SELECT id FROM tenant_agent_profiles WHERE tenant_id=:tid FOR UPDATE');
            $lockProfile->execute(['tid'=>$tenantId]);
            if (!$lockProfile->fetchColumn()) throw new RuntimeException('Perfil da empresa não configurado.', 409);

            $before = $readiness->audit($tenantId, $agentId);
            $offered = false;
            foreach ($before['checklist'] as $check) {
                if (($check['auto_fix_action'] ?? null) === $action && !empty($check['auto_fix_available'])) {
                    $offered = true;
                    break;
                }
            }
            if (!$offered) throw new RuntimeException('Correção não está disponível no diagnóstico atual.', 409);

            match ($action) {
                'set_default_out_of_hours_action' => $this->setDefaultOutOfHoursAction($pdo, $tenantId, $agentId),
                'disable_meeting_link_capability' => $this->disableMeetingLinkCapability($pdo, $tenantId, $agentId),
                'restore_required_triage_steps' => (new WorkflowTriageIntegrityService())->repair($pdo, $tenantId),
            };

            $updated = $readiness->audit($tenantId, $agentId);
            if ($this->stillOffered($updated, $action)) {
                throw new RuntimeException('A correção não resolveu a inconsistência. Nenhuma alteração foi gravada.', 409);
            }
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }

        try {
            Audit::log('agent.readiness_autofix', [
                'agent_id'=>$agentId,'action'=>$action,
                'score_before'=>$before['readiness_score'], 'score_after'=>$updated['readiness_score'],
            ], $tenantId);
        } catch (Throwable $error) {
            error_log('[RS Connect][Readiness][audit_log] ' . $error->getMessage());
        }
        return ['applied'=>true,'action'=>$action,'readiness'=>$updated];
    }

    private function stillOffered(array $report, string $action): bool
    {
        foreach ($report['checklist'] as $check) {
            if (($check['auto_fix_action'] ?? null) === $action && !empty($check['auto_fix_available'])) return true;
        }
        return false;
    }

    private function setDefaultOutOfHoursAction(PDO $pdo, int $tenantId, int $agentId): void
    {
        $profile = $this->profileConfig($pdo, $tenantId);
        $express = $profile['express'] ?? null;
        if (!is_array($express)) {
            throw new RuntimeException('Corrigir a ação exige Configuração Expressa aplicada.', 409);
        }
        if (!is_array($express['routing'] ?? null)) $express['routing'] = [];
        $express['routing']['out_of_hours_action'] = 'capture_lead_and_promise_contact';
        $profile['express'] = $express;
        $this->saveProfileConfig($pdo, $tenantId, $profile);

        $pdo->prepare('UPDATE ai_agents SET after_hours_message=:message WHERE tenant_id=:tid AND id=:aid')
            ->execute(['message'=>self::DEFAULT_OUTSIDE_MESSAGE,'tid'=>$tenantId,'aid'=>$agentId]);
        // SLA e recuperação fora do horário leem as configurações da empresa.
        $pdo->prepare(
            'INSERT INTO tenant_onboarding_settings (tenant_id,after_hours_message)
             VALUES (:tid,:message) ON DUPLICATE KEY UPDATE after_hours_message=VALUES(after_hours_message)'
        )->execute(['tid'=>$tenantId,'message'=>self::DEFAULT_OUTSIDE_MESSAGE]);

        $pdo->prepare(
            'INSERT INTO tenant_agent_policies
                (tenant_id,policy_key,policy_type,value_json,action_key,customer_message,enabled,priority,source)
             VALUES (:tid,"express.out_of_hours","string",:value,"warn",:message,1,220,"tenant")
             ON DUPLICATE KEY UPDATE policy_type="string",value_json=VALUES(value_json),
                 action_key="warn",customer_message=VALUES(customer_message),enabled=1'
        )->execute([
            'tid'=>$tenantId,
            'value'=>json_encode('capture_lead_and_promise_contact', JSON_THROW_ON_ERROR),
            'message'=>self::DEFAULT_OUTSIDE_MESSAGE,
        ]);
    }

    private function disableMeetingLinkCapability(PDO $pdo, int $tenantId, int $agentId): void
    {
        $profile = $this->profileConfig($pdo, $tenantId);
        $express = $profile['express'] ?? null;
        if (!is_array($express)) throw new RuntimeException('Perfil Expressa necessário para esta correção.', 409);
        if (!is_array($express['tools'] ?? null)) $express['tools'] = [];
        if (!is_array($express['tools']['calendar.confirmed.meeting_link'] ?? null)) {
            $express['tools']['calendar.confirmed.meeting_link'] = [];
        }
        $express['tools']['calendar.confirmed.meeting_link']['enabled'] = false;
        if (isset($express['compiled_prompt']) && is_string($express['compiled_prompt'])) {
            $express['compiled_prompt'] = str_replace(self::MEETING_PROMPT_ENABLED, self::MEETING_PROMPT_DISABLED, $express['compiled_prompt']);
        }
        $profile['express'] = $express;
        if (!is_array($profile['capabilities'] ?? null)) $profile['capabilities'] = [];
        $profile['capabilities']['calendar.confirmation_link'] = false;
        $this->saveProfileConfig($pdo, $tenantId, $profile);

        // O contrato da Fase 1 é tenant-wide; sobrescreve também overrides por agente.
        $pdo->prepare(
            'UPDATE tenant_agent_capabilities SET enabled=0
             WHERE tenant_id=:tid AND capability_key="calendar.confirmation_link"'
        )->execute(['tid'=>$tenantId]);
        // Só altera trecho exato da geração determinística, preserva prompts customizados.
        $pdo->prepare(
            'UPDATE ai_agents SET system_prompt=REPLACE(system_prompt,:before,:after)
             WHERE tenant_id=:tid AND system_prompt LIKE :fragment'
        )->execute([
            'tid'=>$tenantId,
            'before'=>self::MEETING_PROMPT_ENABLED,'after'=>self::MEETING_PROMPT_DISABLED,
            'fragment'=>'%' . self::MEETING_PROMPT_ENABLED . '%',
        ]);
    }

    /** @return array<string,mixed> */
    private function profileConfig(PDO $pdo, int $tenantId): array
    {
        $q = $pdo->prepare('SELECT config_json FROM tenant_agent_profiles WHERE tenant_id=:tid LIMIT 1');
        $q->execute(['tid'=>$tenantId]);
        $data = $q->fetchColumn();
        $value = is_string($data) ? json_decode($data, true) : null;
        if (!is_array($value)) throw new RuntimeException('Configuração estruturada inexistente.', 409);
        return $value;
    }

    /** @param array<string,mixed> $config */
    private function saveProfileConfig(PDO $pdo, int $tenantId, array $config): void
    {
        $pdo->prepare('UPDATE tenant_agent_profiles SET config_json=:config WHERE tenant_id=:tid')
            ->execute(['tid'=>$tenantId, 'config'=>json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    }
}
