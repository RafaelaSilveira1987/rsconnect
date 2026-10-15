<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

/**
 * A triagem obrigatória é requisito de negócio, não uma instrução de prompt.
 * Planeja e repara somente vínculos de coleta antes da primeira ação de agenda.
 * Não cria campos, não modifica permissões e não altera dados de agenda.
 */
final class WorkflowTriageIntegrityService
{
    /**
     * @param list<array<string,mixed>> $fields
     * @param list<array<string,mixed>> $steps
     * @return array{missing:list<string>,late:list<string>,unrepairable:list<string>}
     */
    public function plan(array $fields, array $steps): array
    {
        $required = [];
        $unrepairable = [];
        foreach ($fields as $field) {
            if (empty($field['active']) || empty($field['required_before_schedule'])) continue;
            $key = trim((string) ($field['field_key'] ?? ''));
            if ($key === '') continue;
            if (trim((string) ($field['prompt_text'] ?? '')) === '') {
                $unrepairable[] = $key;
                continue;
            }
            $required[$key] = true;
        }
        $calendar = null;
        foreach ($steps as $step) {
            if (empty($step['active']) || ($step['step_type'] ?? '') !== 'action') continue;
            $config = self::config($step);
            if (str_starts_with(trim((string) ($config['action_key'] ?? '')), 'calendar.')) {
                $position = (int) ($step['position'] ?? 0);
                $calendar = $calendar === null ? $position : min($calendar, $position);
            }
        }
        $linked = [];
        foreach ($steps as $step) {
            if (empty($step['active']) || ($step['step_type'] ?? '') !== 'collect') continue;
            $config = self::config($step);
            foreach (self::keys($config) as $key) {
                if (!isset($required[$key])) continue;
                $position = (int) ($step['position'] ?? 0);
                $linked[$key] = isset($linked[$key]) ? min($linked[$key], $position) : $position;
            }
        }
        $missing = [];
        $late = [];
        foreach (array_keys($required) as $key) {
            if (!isset($linked[$key])) $missing[] = $key;
            elseif ($calendar !== null && $linked[$key] >= $calendar) $late[] = $key;
        }
        return ['missing'=>$missing, 'late'=>$late, 'unrepairable'=>array_values(array_unique($unrepairable))];
    }

    /**
     * Somente dentro de transação, após lock do tenant. Retorna contagem de vínculos reparados.
     * Ação explícita do usuário (autofix) ou salvar campos escolhidos na Ativação Assistida.
     */
    public function repair(PDO $pdo, int $tenantId): int
    {
        if (!$pdo->inTransaction() || $tenantId < 1) throw new RuntimeException('Correção exige empresa e transação ativa.');
        $q = $pdo->prepare('SELECT field_key,active,required_before_schedule,prompt_text FROM tenant_triage_fields WHERE tenant_id=:tid FOR UPDATE');
        $q->execute(['tid'=>$tenantId]);
        $fields = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $q = $pdo->prepare('SELECT id,step_key,step_type,config_json,active,position FROM tenant_agent_workflow_steps WHERE tenant_id=:tid ORDER BY position,id FOR UPDATE');
        $q->execute(['tid'=>$tenantId]);
        $steps = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $plan = $this->plan($fields, $steps);
        if ($plan['unrepairable'] !== []) {
            throw new RuntimeException('Há informações obrigatórias sem pergunta configurada. Preencha as perguntas antes de corrigir o fluxo.', 409);
        }
        if ($plan['missing'] === [] && $plan['late'] === []) return 0;

        // Adiciona APENAS os campos obrigatórios ainda não vinculados; não regrava
        // os coletores personalizados e não reativa etapas excluídas pelo usuário.
        if ($plan['missing'] !== []) {
            $existing = null;
            foreach ($steps as $i => $step) {
                if ((string) $step['step_key'] === 'system_required_intake') { $existing = $i; break; }
            }
            $config = $existing !== null ? self::config($steps[$existing]) : [];
            $config['field_keys'] = array_values(array_unique(array_merge(self::keys($config), $plan['missing'])));
            unset($config['field_key']);
            $encoded = json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($existing !== null) {
                $pdo->prepare('UPDATE tenant_agent_workflow_steps SET active=1,config_json=:config,source="tenant" WHERE tenant_id=:tid AND id=:id')
                    ->execute(['tid'=>$tenantId,'id'=>$steps[$existing]['id'],'config'=>$encoded]);
                $steps[$existing]['active'] = 1;
                $steps[$existing]['config_json'] = $encoded;
            } else {
                $pdo->prepare('INSERT INTO tenant_agent_workflow_steps (tenant_id,step_key,label,step_type,config_json,active,position,source) VALUES (:tid,"system_required_intake","Informações obrigatórias","collect",:config,1,0,"tenant")')
                    ->execute(['tid'=>$tenantId,'config'=>$encoded]);
                $steps[] = [ 'id'=>(int) $pdo->lastInsertId(), 'step_key'=>'system_required_intake',
                    'step_type'=>'collect','config_json'=>$encoded,'active'=>1,'position'=>0 ];
            }
        }

        // Move SOMENTE coletores que têm campos obrigatórios para antes da
        // primeira calendar.*, respeitando a ordem relativa de todas as demais etapas.
        usort($steps, static fn ($a,$b) => ((int)$a['position'] <=> (int)$b['position']) ?: ((int)$a['id'] <=> (int)$b['id']));
        $boundaryIndex = null;
        foreach ($steps as $i => $step) {
            $config = self::config($step);
            if (!empty($step['active']) && $step['step_type'] === 'action'
                && str_starts_with((string)($config['action_key'] ?? ''), 'calendar.')) { $boundaryIndex = $i; break; }
        }
        if ($boundaryIndex === null) $boundaryIndex = count($steps);
        $promote = [];
        $remaining = [];
        $requiredKeys = array_fill_keys(array_merge($plan['missing'], $plan['late']), true);
        foreach ($steps as $index => $step) {
            $keys = self::keys(self::config($step));
            $mustMove = !empty($step['active']) && $step['step_type'] === 'collect'
                && ($step['step_key'] === 'system_required_intake'
                    || ($index >= $boundaryIndex && array_intersect_key(array_fill_keys($keys,true), $requiredKeys) !== []));
            if ($mustMove) $promote[] = $step;
            else $remaining[] = $step;
        }
        $newBoundary = count($remaining);
        foreach ($remaining as $i => $step) {
            $config = self::config($step);
            if (!empty($step['active']) && $step['step_type'] === 'action'
                && str_starts_with((string)($config['action_key'] ?? ''), 'calendar.')) { $newBoundary = $i; break; }
        }
        array_splice($remaining, $newBoundary, 0, $promote);
        $update = $pdo->prepare('UPDATE tenant_agent_workflow_steps SET position=:position WHERE tenant_id=:tid AND id=:id');
        foreach ($remaining as $index => $step) {
            $position = ($index+1)*10;
            if ((int) $step['position'] !== $position) $update->execute(['position'=>$position,'tid'=>$tenantId,'id'=>$step['id']]);
        }
        return count($plan['missing']) + count($plan['late']);
    }

    /** @param array<string,mixed> $step */
    private static function config(array $step): array
    {
        if (is_array($step['config'] ?? null)) return $step['config'];
        $config = json_decode((string)($step['config_json'] ?? ''), true);
        return is_array($config) ? $config : [];
    }

    /** @param array<string,mixed> $config @return list<string> */
    private static function keys(array $config): array
    {
        $keys = [];
        if (is_string($config['field_key'] ?? null) && $config['field_key'] !== '') $keys[] = $config['field_key'];
        foreach (is_array($config['field_keys'] ?? null) ? $config['field_keys'] : [] as $key) {
            if (is_string($key) && trim($key) !== '') $keys[] = trim($key);
        }
        return array_values(array_unique($keys));
    }
}
