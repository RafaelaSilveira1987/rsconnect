<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

/**
 * Comando por domínio para a configuração expressa.
 * Deve ser chamado sob lock do tenant e dentro da transação do serviço orquestrador.
 * Não apaga toda a triagem, os fluxos ou políticas de outro editor.
 */
final class TenantExpressConfigurationWriter
{
    public function __construct(private readonly ?ExpressConfigurationReconciler $reconciler = null) {}

    /**
     * @param array<string,mixed> $compiled
     * @return array{triage_fields:list<array<string,mixed>>,workflow:list<array<string,mixed>>,policies:list<array<string,mixed>>,capabilities:array<string,bool>,protected_fields:list<string>}
     */
    public function save(PDO $pdo, int $tenantId, array $compiled): array
    {
        if (!$pdo->inTransaction()) throw new RuntimeException('Operação exige transação ativa.');
        $reconcile = $this->reconciler ?? new ExpressConfigurationReconciler();
        $json = static fn (mixed $value): string => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $fields = $this->rows($pdo,
            'SELECT * FROM tenant_triage_fields WHERE tenant_id=:tid ORDER BY position,id FOR UPDATE', $tenantId);
        $steps = $this->rows($pdo,
            'SELECT * FROM tenant_agent_workflow_steps WHERE tenant_id=:tid ORDER BY position,id FOR UPDATE', $tenantId);
        $policies = $this->rows($pdo,
            'SELECT * FROM tenant_agent_policies WHERE tenant_id=:tid ORDER BY priority,id FOR UPDATE', $tenantId);
        $capRows = $this->rows($pdo,
            'SELECT capability_key,enabled FROM tenant_agent_capabilities WHERE tenant_id=:tid AND agent_id IS NULL FOR UPDATE', $tenantId);
        $caps = [];
        foreach ($capRows as $row) $caps[$row['capability_key']] = (int) $row['enabled'] === 1;
        $ps = $this->rows($pdo,
            'SELECT require_human_approval,ai_can_confirm FROM tenant_pre_schedule_settings WHERE tenant_id=:tid FOR UPDATE', $tenantId);

        // Não criar uma segunda chave para a mesma pergunta cadastrada pela Ativação Assistida.
        $existingPrompts = [];
        foreach ($fields as $field) {
            if (empty($field['active'])) continue;
            $text = $this->normalizePrompt((string) ($field['prompt_text'] ?? ''));
            if ($text !== '') $existingPrompts[$text] = (string) $field['field_key'];
        }
        foreach ($compiled['triage_fields'] as $field) {
            $key = (string) ($field['key'] ?? '');
            if (!str_starts_with($key, 'express_')) continue;
            $duplicate = $existingPrompts[$this->normalizePrompt((string) ($field['prompt'] ?? ''))] ?? null;
            if ($duplicate !== null && $duplicate !== $key) {
                throw new RuntimeException('Esta pergunta já existe em Informações do atendimento. Remova a duplicata e edite a pergunta original naquela área.');
            }
        }
        $fieldPlan = $reconcile->triage($fields, $compiled['triage_fields']);
        $flowPlan = $reconcile->workflow($steps, $compiled['workflow'], $fieldPlan['delete_keys']);
        $mergedPolicies = $reconcile->policies($policies, $compiled['policies']);
        $mergedCaps = $reconcile->capabilities($caps, $compiled['capabilities']);
        // O contrato de aprovação humana da Agenda é autoridade para confirmação.
        if (!empty($ps[0]['require_human_approval']) || (isset($ps[0]['ai_can_confirm']) && (int) $ps[0]['ai_can_confirm'] === 0)) {
            $mergedCaps['calendar.human_approval'] = true;
            $mergedCaps['calendar.confirm'] = false;
        }

        $remove = $pdo->prepare('DELETE FROM tenant_triage_fields WHERE tenant_id=:tid AND field_key=:key');
        foreach ($fieldPlan['delete_keys'] as $key) $remove->execute(['tid'=>$tenantId,'key'=>$key]);
        $upsertField = $pdo->prepare(
            'INSERT INTO tenant_triage_fields
              (tenant_id,field_key,label,field_type,prompt_text,options_json,
               required_before_schedule,required_for_completion,active,position,source)
             VALUES (:tid,:key,:label,:type,:prompt,:options,:before,:required,:active,:position,"tenant")
             ON DUPLICATE KEY UPDATE label=VALUES(label),field_type=VALUES(field_type),
               prompt_text=VALUES(prompt_text),options_json=VALUES(options_json),
               required_before_schedule=VALUES(required_before_schedule),
               required_for_completion=VALUES(required_for_completion),active=VALUES(active),
               position=VALUES(position),source="tenant"'
        );
        $existingFields = array_fill_keys(array_map(static fn ($f) => (string) $f['field_key'], $fields), true);
        foreach ($fieldPlan['fields'] as $f) {
            $key = (string) $f['key'];
            if (isset($existingFields[$key]) && !ExpressConfigurationReconciler::ownsField($key)) continue;
            $upsertField->execute([
                'tid'=>$tenantId,'key'=>$key,'label'=>$f['label'],
                'type'=>$f['type'] ?? 'text','prompt'=>$f['prompt'],
                'options'=>empty($f['options']) ? null : $json($f['options']),
                'before'=>!empty($f['required_before_schedule']) ? 1 : 0,
                'required'=>!empty($f['required_for_completion']) ? 1 : 0,
                'active'=>!empty($f['active']) ? 1 : 0, 'position'=>$f['position'] ?? 100,
            ]);
        }

        $removeStep = $pdo->prepare('DELETE FROM tenant_agent_workflow_steps WHERE tenant_id=:tid AND step_key=:key');
        foreach ($flowPlan['delete_keys'] as $key) $removeStep->execute(['tid'=>$tenantId,'key'=>$key]);
        $upsertStep = $pdo->prepare(
            'INSERT INTO tenant_agent_workflow_steps
              (tenant_id,step_key,label,step_type,config_json,active,position,source)
             VALUES (:tid,:key,:label,:type,:config,:active,:position,"tenant")
             ON DUPLICATE KEY UPDATE position=VALUES(position),config_json=VALUES(config_json),
               active=VALUES(active)'
        );
        foreach ($flowPlan['steps'] as $f) {
            $config = $f['config'] ?? [];
            $upsertStep->execute([
                'tid'=>$tenantId,'key'=>$f['key'],'label'=>$f['label'],
                'type'=>$f['type'],'config'=>$json($config),
                'active'=>!empty($f['active']) ? 1 : 0,'position'=>$f['position'],
            ]);
        }

        $oldPolicies = array_fill_keys(array_map(static fn ($p) => (string) $p['policy_key'], $policies), true);
        $upsertPolicy = $pdo->prepare(
            'INSERT INTO tenant_agent_policies
              (tenant_id,policy_key,policy_type,value_json,action_key,customer_message,enabled,priority,source)
             VALUES (:tid,:key,:type,:value,:action,:message,:enabled,:priority,"tenant")
             ON DUPLICATE KEY UPDATE policy_type=VALUES(policy_type),value_json=VALUES(value_json),
               action_key=VALUES(action_key),customer_message=VALUES(customer_message),
               enabled=VALUES(enabled),priority=VALUES(priority),source="tenant"'
        );
        foreach ($mergedPolicies as $p) {
            $key = (string) $p['key'];
            if (isset($oldPolicies[$key]) && !str_starts_with($key, 'express.')) continue;
            $upsertPolicy->execute([
                'tid'=>$tenantId,'key'=>$key,'type'=>$p['type'],
                'value'=>$json($p['value'] ?? null), 'action'=>$p['action'] ?? 'block',
                'message'=>$p['message'] ?? null,'enabled'=>(int) ($p['enabled'] ?? 1),
                'priority'=>$p['priority'] ?? 100,
            ]);
        }

        // agent_id nullable não possui unique key: atualiza somente chaves do tenant,
        // sem apagar ou sobrescrever overrides por agente.
        $removeCap = $pdo->prepare('DELETE FROM tenant_agent_capabilities WHERE tenant_id=:tid AND agent_id IS NULL AND capability_key=:key');
        $insertCap = $pdo->prepare(
            'INSERT INTO tenant_agent_capabilities (tenant_id,agent_id,capability_key,enabled,source)
             VALUES (:tid,NULL,:key,:enabled,"tenant")'
        );
        foreach ($mergedCaps as $key => $enabled) {
            if (isset($caps[$key]) && $caps[$key] === $enabled) continue;
            $removeCap->execute(['tid'=>$tenantId,'key'=>$key]);
            $insertCap->execute(['tid'=>$tenantId,'key'=>$key,'enabled'=>$enabled ? 1 : 0]);
        }

        return [
            'triage_fields'=>$fieldPlan['fields'], 'workflow'=>$flowPlan['steps'],
            'policies'=>$mergedPolicies, 'capabilities'=>$mergedCaps,
            'protected_fields'=>$fieldPlan['protected'],
        ];
    }

    private function normalizePrompt(string $prompt): string
    {
        $prompt = mb_strtolower(trim($prompt));
        return trim((string) preg_replace('/[\s?!.,;:]+/u', ' ', $prompt));
    }

    /** @return list<array<string,mixed>> */
    private function rows(PDO $pdo, string $sql, int $tenantId): array
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['tid'=>$tenantId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
