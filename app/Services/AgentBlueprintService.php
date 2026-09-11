<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use Throwable;

final class AgentBlueprintService
{
    public function niches(bool $activeOnly = true): array
    {
        $pdo = Database::connection();
        if (!$this->tableExists($pdo, 'business_niches')) {
            return [];
        }
        $sql = 'SELECT * FROM business_niches' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY position, name';
        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function blueprints(?int $nicheId = null, bool $activeOnly = true): array
    {
        $pdo = Database::connection();
        if (!$this->tableExists($pdo, 'agent_blueprints')) {
            return [];
        }
        $where = [];
        $params = [];
        if ($activeOnly) {
            $where[] = 'b.active = 1';
        }
        if (($nicheId ?? 0) > 0) {
            $where[] = 'b.niche_id = :niche_id';
            $params['niche_id'] = $nicheId;
        }
        $sql = 'SELECT b.*, n.name AS niche_name, n.code AS niche_code,
                       v.id AS current_version_id, v.version_no, v.version_label
                FROM agent_blueprints b
                INNER JOIN business_niches n ON n.id = b.niche_id
                LEFT JOIN agent_blueprint_versions v ON v.id = (
                    SELECT av.id FROM agent_blueprint_versions av
                    WHERE av.blueprint_id = b.id
                    ORDER BY av.is_current DESC, av.version_no DESC, av.id DESC LIMIT 1
                )'
            . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY n.position, b.name';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function blueprint(int $blueprintId, ?PDO $pdo = null): ?array
    {
        $pdo ??= Database::connection();
        if ($blueprintId < 1 || !$this->tableExists($pdo, 'agent_blueprints')) {
            return null;
        }
        $stmt = $pdo->prepare(
            'SELECT b.*, n.code AS niche_code, n.name AS niche_name,
                    v.id AS version_id, v.version_no, v.version_label, v.config_json, v.prompt_guidance
             FROM agent_blueprints b
             INNER JOIN business_niches n ON n.id = b.niche_id
             LEFT JOIN agent_blueprint_versions v ON v.id = (
                SELECT av.id FROM agent_blueprint_versions av
                WHERE av.blueprint_id = b.id
                ORDER BY av.is_current DESC, av.version_no DESC, av.id DESC LIMIT 1
             )
             WHERE b.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $blueprintId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $row['config'] = $this->decodeJson($row['config_json'] ?? null);
        return $row;
    }

    public function profileForTenant(int $tenantId, bool $autoBootstrap = true, ?PDO $pdo = null): array
    {
        $pdo ??= Database::connection();
        if ($tenantId < 1 || !$this->tableExists($pdo, 'tenant_agent_profiles')) {
            return $this->emptyProfile($tenantId);
        }

        $stmt = $pdo->prepare(
            'SELECT p.*, n.code AS niche_code, n.name AS niche_name,
                    b.code AS blueprint_code, b.name AS blueprint_name,
                    v.version_no, v.version_label, v.prompt_guidance
             FROM tenant_agent_profiles p
             LEFT JOIN business_niches n ON n.id = p.niche_id
             LEFT JOIN agent_blueprints b ON b.id = p.blueprint_id
             LEFT JOIN agent_blueprint_versions v ON v.id = p.blueprint_version_id
             WHERE p.tenant_id = :tenant_id LIMIT 1'
        );
        $stmt->execute(['tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row && $autoBootstrap) {
            $blueprintId = $this->suggestedBlueprintIdForTenant($tenantId, $pdo);
            if ($blueprintId > 0) {
                $this->applyBlueprint($tenantId, $blueprintId, $pdo, false);
                $stmt->execute(['tenant_id' => $tenantId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
            }
        }

        if (!$row) {
            return $this->emptyProfile($tenantId);
        }

        $row['config'] = $this->decodeJson($row['config_json'] ?? null);
        $row['capabilities'] = $this->capabilities($tenantId, 0, $pdo);
        $row['workflow'] = $this->workflow($tenantId, $pdo);
        $row['triage_fields'] = $this->applyWorkflowOrderToTriageFields(
            $this->triageFields($tenantId, $pdo),
            $row['workflow']
        );
        $row['policies'] = $this->policies($tenantId, $pdo);
        return $row;
    }

    public function suggestedBlueprintIdForTenant(int $tenantId, ?PDO $pdo = null): int
    {
        $pdo ??= Database::connection();
        if ($tenantId < 1 || !$this->tableExists($pdo, 'tenants') || !$this->tableExists($pdo, 'agent_blueprints')) {
            return 0;
        }
        $stmt = $pdo->prepare('SELECT business_niche_id, segment FROM tenants WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $tenantId]);
        $tenant = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $nicheId = (int) ($tenant['business_niche_id'] ?? 0);
        if ($nicheId < 1) {
            $nicheCode = $this->inferNicheCode((string) ($tenant['segment'] ?? ''));
            if ($nicheCode !== '') {
                $niche = $pdo->prepare('SELECT id FROM business_niches WHERE code = :code AND active = 1 LIMIT 1');
                $niche->execute(['code' => $nicheCode]);
                $nicheId = (int) ($niche->fetchColumn() ?: 0);
                if ($nicheId > 0) {
                    try {
                        $pdo->prepare('UPDATE tenants SET business_niche_id = :niche_id WHERE id = :id AND business_niche_id IS NULL')
                            ->execute(['niche_id' => $nicheId, 'id' => $tenantId]);
                    } catch (Throwable) {
                    }
                }
            }
        }
        if ($nicheId < 1) {
            return 0;
        }
        $bp = $pdo->prepare(
            'SELECT id FROM agent_blueprints WHERE niche_id = :niche_id AND active = 1 ORDER BY id LIMIT 1'
        );
        $bp->execute(['niche_id' => $nicheId]);
        return (int) ($bp->fetchColumn() ?: 0);
    }

    public function applyBlueprint(int $tenantId, int $blueprintId, ?PDO $pdo = null, bool $markCustomized = false): array
    {
        $pdo ??= Database::connection();
        $blueprint = $this->blueprint($blueprintId, $pdo);
        if ($tenantId < 1 || !$blueprint || empty($blueprint['version_id'])) {
            throw new \RuntimeException('Blueprint de agente inválido ou sem versão ativa.');
        }
        $config = is_array($blueprint['config'] ?? null) ? $blueprint['config'] : [];
        $interactionMode = strtolower(trim((string) ($config['interaction_mode'] ?? 'hybrid')));
        if (!in_array($interactionMode, ['hybrid', 'form', 'prompt'], true)) {
            $interactionMode = 'hybrid';
        }
        $normalizedCapabilities = $this->normalizeCapabilities(is_array($config['capabilities'] ?? null) ? $config['capabilities'] : []);
        $config['capabilities'] = $normalizedCapabilities;

        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $pdo->prepare(
                'INSERT INTO tenant_agent_profiles
                    (tenant_id, niche_id, blueprint_id, blueprint_version_id, interaction_mode, status, config_json, customized, applied_at)
                 VALUES
                    (:tenant_id, :niche_id, :blueprint_id, :version_id, :interaction_mode, "active", :config_json, :customized, NOW())
                 ON DUPLICATE KEY UPDATE
                    niche_id = VALUES(niche_id), blueprint_id = VALUES(blueprint_id), blueprint_version_id = VALUES(blueprint_version_id),
                    interaction_mode = VALUES(interaction_mode), status = "active", config_json = VALUES(config_json),
                    customized = VALUES(customized), applied_at = NOW(), updated_at = CURRENT_TIMESTAMP'
            )->execute([
                'tenant_id' => $tenantId,
                'niche_id' => (int) $blueprint['niche_id'],
                'blueprint_id' => $blueprintId,
                'version_id' => (int) $blueprint['version_id'],
                'interaction_mode' => $interactionMode,
                'config_json' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'customized' => $markCustomized ? 1 : 0,
            ]);

            $this->replaceCapabilities($pdo, $tenantId, $normalizedCapabilities);
            $this->replaceTriageFields($pdo, $tenantId, $config['triage_fields'] ?? []);
            $this->replacePolicies($pdo, $tenantId, $config['policies'] ?? []);
            $this->replaceWorkflow($pdo, $tenantId, $config['workflow'] ?? []);

            $pdo->prepare(
                'UPDATE tenants
                 SET business_niche_id = :niche_id,
                     agent_blueprint_id = :blueprint_id,
                     agent_blueprint_version_id = :version_id,
                     segment = COALESCE(NULLIF(TRIM(segment), ""), :segment)
                 WHERE id = :tenant_id'
            )->execute([
                'niche_id' => (int) $blueprint['niche_id'],
                'blueprint_id' => $blueprintId,
                'version_id' => (int) $blueprint['version_id'],
                'segment' => (string) ($blueprint['niche_name'] ?? ''),
                'tenant_id' => $tenantId,
            ]);

            $this->syncCalendarDefaults($pdo, $tenantId, $normalizedCapabilities);

            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        return $this->profileForTenant($tenantId, false, $pdo);
    }

    public function saveTenantConfiguration(int $tenantId, array $data): void
    {
        $pdo = Database::connection();
        $profile = $this->profileForTenant($tenantId, true, $pdo);
        if (empty($profile['id'])) {
            throw new \RuntimeException('A empresa ainda não possui um blueprint de agente aplicado.');
        }

        $interactionMode = strtolower(trim((string) ($data['interaction_mode'] ?? ($profile['interaction_mode'] ?? 'hybrid'))));
        if (!in_array($interactionMode, ['hybrid', 'form', 'prompt'], true)) {
            $interactionMode = 'hybrid';
        }

        $currentConfig = is_array($profile['config'] ?? null) ? $profile['config'] : [];
        if (is_array($data['conversation_behavior'] ?? null)) {
            $fallbackBehavior = is_array($currentConfig['conversation_behavior'] ?? null) ? $currentConfig['conversation_behavior'] : [];
            $currentConfig['conversation_behavior'] = AgentConversationBehaviorService::normalizeConfiguration(
                $data['conversation_behavior'],
                $fallbackBehavior
            );
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'UPDATE tenant_agent_profiles
                 SET interaction_mode = :mode, config_json = :config_json, customized = 1
                 WHERE tenant_id = :tenant_id'
            )->execute([
                'mode' => $interactionMode,
                'config_json' => json_encode($currentConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'tenant_id' => $tenantId,
            ]);

            $postedCapabilities = is_array($data['capabilities'] ?? null) ? $data['capabilities'] : [];
            $effectiveCapabilities = $this->capabilities($tenantId, 0, $pdo);
            foreach ($effectiveCapabilities as $key => $enabled) {
                if (array_key_exists($key, $postedCapabilities)) {
                    $effectiveCapabilities[$key] = !empty($postedCapabilities[$key]);
                }
            }
            foreach ($postedCapabilities as $key => $enabled) {
                if (!array_key_exists((string) $key, $effectiveCapabilities)) {
                    $effectiveCapabilities[(string) $key] = !empty($enabled);
                }
            }
            $effectiveCapabilities = $this->normalizeCapabilities($effectiveCapabilities);
            foreach ($effectiveCapabilities as $key => $enabled) {
                $this->upsertCapability($pdo, $tenantId, (string) $key, (bool) $enabled, 'tenant');
            }

            $fieldRows = is_array($data['triage_fields'] ?? null) ? $data['triage_fields'] : [];
            foreach ($fieldRows as $fieldKey => $posted) {
                if (!is_array($posted)) {
                    continue;
                }
                $pdo->prepare(
                    'UPDATE tenant_triage_fields
                     SET label = :label, prompt_text = :prompt_text,
                         required_before_schedule = :required_before_schedule,
                         required_for_completion = :required_for_completion,
                         active = :active, source = "tenant"
                     WHERE tenant_id = :tenant_id AND field_key = :field_key'
                )->execute([
                    'label' => mb_substr(trim((string) ($posted['label'] ?? $fieldKey)), 0, 160),
                    'prompt_text' => mb_substr(trim((string) ($posted['prompt_text'] ?? '')), 0, 1000) ?: null,
                    'required_before_schedule' => !empty($posted['required_before_schedule']) ? 1 : 0,
                    'required_for_completion' => !empty($posted['required_for_completion']) ? 1 : 0,
                    'active' => !empty($posted['active']) ? 1 : 0,
                    'tenant_id' => $tenantId,
                    'field_key' => (string) $fieldKey,
                ]);
            }

            $policyRows = is_array($data['policies'] ?? null) ? $data['policies'] : [];
            foreach ($policyRows as $policyKey => $posted) {
                if (!is_array($posted)) {
                    continue;
                }
                $current = $pdo->prepare('SELECT policy_type, value_json, action_key FROM tenant_agent_policies WHERE tenant_id = :tenant_id AND policy_key = :policy_key LIMIT 1');
                $current->execute(['tenant_id' => $tenantId, 'policy_key' => $policyKey]);
                $row = $current->fetch(PDO::FETCH_ASSOC);
                if (!$row) {
                    continue;
                }
                $type = (string) ($row['policy_type'] ?? 'string');
                $value = $this->normalizePolicyInput($type, $posted['value'] ?? null);
                $action = trim((string) ($posted['action_key'] ?? $row['action_key'] ?? 'block')) ?: 'block';
                $pdo->prepare(
                    'UPDATE tenant_agent_policies
                     SET value_json = :value_json, action_key = :action_key, customer_message = :customer_message,
                         enabled = :enabled, source = "tenant"
                     WHERE tenant_id = :tenant_id AND policy_key = :policy_key'
                )->execute([
                    'value_json' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'action_key' => mb_substr($action, 0, 120),
                    'customer_message' => mb_substr(trim((string) ($posted['customer_message'] ?? '')), 0, 1200) ?: null,
                    'enabled' => !empty($posted['enabled']) ? 1 : 0,
                    'tenant_id' => $tenantId,
                    'policy_key' => $policyKey,
                ]);
            }

            $workflowRows = is_array($data['workflow'] ?? null) ? $data['workflow'] : [];
            if ($workflowRows !== []) {
                $this->updateWorkflowConfiguration($pdo, $tenantId, $workflowRows);
            }

            if (is_array($currentConfig['conversation_behavior'] ?? null)) {
                $this->syncConversationBehavior($pdo, $tenantId, $currentConfig['conversation_behavior']);
            }
            $this->syncCalendarDefaults($pdo, $tenantId, $this->capabilities($tenantId, 0, $pdo));
            $pdo->commit();
            (new AgentConversationBehaviorService())->clearCache($tenantId);
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function capabilities(int $tenantId, int $agentId = 0, ?PDO $pdo = null): array
    {
        $pdo ??= Database::connection();
        if (!$this->tableExists($pdo, 'tenant_agent_capabilities')) {
            return [];
        }
        $stmt = $pdo->prepare(
            'SELECT capability_key, enabled, agent_id, source
             FROM tenant_agent_capabilities
             WHERE tenant_id = :tenant_id AND (agent_id IS NULL OR agent_id = :agent_id)
             ORDER BY CASE WHEN agent_id IS NULL THEN 0 ELSE 1 END, id'
        );
        $stmt->execute(['tenant_id' => $tenantId, 'agent_id' => max(0, $agentId)]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $result[(string) $row['capability_key']] = (int) $row['enabled'] === 1;
        }
        return $result;
    }

    public function can(int $tenantId, string $capability, int $agentId = 0, bool $default = false, ?PDO $pdo = null): bool
    {
        $capabilities = $this->capabilities($tenantId, $agentId, $pdo);
        return array_key_exists($capability, $capabilities) ? (bool) $capabilities[$capability] : $default;
    }

    public function triageFields(int $tenantId, ?PDO $pdo = null): array
    {
        $pdo ??= Database::connection();
        if (!$this->tableExists($pdo, 'tenant_triage_fields')) {
            return [];
        }
        $stmt = $pdo->prepare('SELECT * FROM tenant_triage_fields WHERE tenant_id = :tenant_id ORDER BY position, id');
        $stmt->execute(['tenant_id' => $tenantId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            $row['options'] = $this->decodeJson($row['options_json'] ?? null);
        }
        unset($row);
        return $rows;
    }

    public function policies(int $tenantId, ?PDO $pdo = null): array
    {
        $pdo ??= Database::connection();
        if (!$this->tableExists($pdo, 'tenant_agent_policies')) {
            return [];
        }
        $stmt = $pdo->prepare('SELECT * FROM tenant_agent_policies WHERE tenant_id = :tenant_id ORDER BY priority, id');
        $stmt->execute(['tenant_id' => $tenantId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            $decoded = $this->decodeJsonValue($row['value_json'] ?? null);
            $row['value'] = $decoded;
        }
        unset($row);
        return $rows;
    }

    public function workflow(int $tenantId, ?PDO $pdo = null): array
    {
        $pdo ??= Database::connection();
        if (!$this->tableExists($pdo, 'tenant_agent_workflow_steps')) {
            return [];
        }
        $stmt = $pdo->prepare('SELECT * FROM tenant_agent_workflow_steps WHERE tenant_id = :tenant_id ORDER BY position, id');
        $stmt->execute(['tenant_id' => $tenantId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            $row['config'] = $this->decodeJson($row['config_json'] ?? null);
        }
        unset($row);
        return $rows;
    }

    private function replaceCapabilities(PDO $pdo, int $tenantId, mixed $raw): void
    {
        $items = is_array($raw) ? $raw : [];
        $pdo->prepare('DELETE FROM tenant_agent_capabilities WHERE tenant_id = :tenant_id AND agent_id IS NULL')->execute(['tenant_id' => $tenantId]);
        foreach ($items as $key => $enabled) {
            $this->upsertCapability($pdo, $tenantId, (string) $key, (bool) $enabled, 'blueprint');
        }
    }

    private function upsertCapability(PDO $pdo, int $tenantId, string $key, bool $enabled, string $source): void
    {
        $pdo->prepare('DELETE FROM tenant_agent_capabilities WHERE tenant_id = :tenant_id AND agent_id IS NULL AND capability_key = :capability_key')
            ->execute(['tenant_id' => $tenantId, 'capability_key' => $key]);
        $pdo->prepare(
            'INSERT INTO tenant_agent_capabilities (tenant_id, agent_id, capability_key, enabled, source)
             VALUES (:tenant_id, NULL, :capability_key, :enabled, :source)'
        )->execute([
            'tenant_id' => $tenantId,
            'capability_key' => mb_substr($key, 0, 120),
            'enabled' => $enabled ? 1 : 0,
            'source' => in_array($source, ['blueprint', 'tenant', 'agent'], true) ? $source : 'tenant',
        ]);
    }

    private function replaceTriageFields(PDO $pdo, int $tenantId, mixed $raw): void
    {
        $items = is_array($raw) ? $raw : [];
        $pdo->prepare('DELETE FROM tenant_triage_fields WHERE tenant_id = :tenant_id')->execute(['tenant_id' => $tenantId]);
        $stmt = $pdo->prepare(
            'INSERT INTO tenant_triage_fields
                (tenant_id, field_key, label, field_type, prompt_text, options_json,
                 required_before_schedule, required_for_completion, active, position, source)
             VALUES
                (:tenant_id, :field_key, :label, :field_type, :prompt_text, :options_json,
                 :required_before_schedule, :required_for_completion, 1, :position, "blueprint")'
        );
        foreach ($items as $index => $item) {
            if (!is_array($item) || trim((string) ($item['key'] ?? '')) === '') {
                continue;
            }
            $type = strtolower(trim((string) ($item['type'] ?? 'text')));
            if (!in_array($type, ['text', 'number', 'boolean', 'select', 'date', 'time', 'textarea'], true)) {
                $type = 'text';
            }
            $options = is_array($item['options'] ?? null) ? array_values($item['options']) : [];
            $stmt->execute([
                'tenant_id' => $tenantId,
                'field_key' => mb_substr(trim((string) $item['key']), 0, 100),
                'label' => mb_substr(trim((string) ($item['label'] ?? $item['key'])), 0, 160),
                'field_type' => $type,
                'prompt_text' => mb_substr(trim((string) ($item['prompt'] ?? '')), 0, 1000) ?: null,
                'options_json' => $options !== [] ? json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                'required_before_schedule' => !empty($item['required_before_schedule']) ? 1 : 0,
                'required_for_completion' => !empty($item['required_for_completion']) ? 1 : 0,
                'position' => max(1, (int) ($item['position'] ?? (($index + 1) * 10))),
            ]);
        }
    }

    private function replacePolicies(PDO $pdo, int $tenantId, mixed $raw): void
    {
        $items = is_array($raw) ? $raw : [];
        $pdo->prepare('DELETE FROM tenant_agent_policies WHERE tenant_id = :tenant_id')->execute(['tenant_id' => $tenantId]);
        $stmt = $pdo->prepare(
            'INSERT INTO tenant_agent_policies
                (tenant_id, policy_key, policy_type, value_json, action_key, customer_message, enabled, priority, source)
             VALUES
                (:tenant_id, :policy_key, :policy_type, :value_json, :action_key, :customer_message, 1, :priority, "blueprint")'
        );
        foreach ($items as $index => $item) {
            if (!is_array($item) || trim((string) ($item['key'] ?? '')) === '') {
                continue;
            }
            $type = strtolower(trim((string) ($item['type'] ?? 'string')));
            if (!in_array($type, ['boolean', 'number', 'string', 'json'], true)) {
                $type = 'string';
            }
            $value = $this->normalizePolicyInput($type, $item['value'] ?? null);
            $stmt->execute([
                'tenant_id' => $tenantId,
                'policy_key' => mb_substr(trim((string) $item['key']), 0, 120),
                'policy_type' => $type,
                'value_json' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'action_key' => mb_substr(trim((string) ($item['action'] ?? 'block')), 0, 120) ?: 'block',
                'customer_message' => mb_substr(trim((string) ($item['message'] ?? '')), 0, 1200) ?: null,
                'priority' => max(1, (int) ($item['priority'] ?? (($index + 1) * 10))),
            ]);
        }
    }

    private function replaceWorkflow(PDO $pdo, int $tenantId, mixed $raw): void
    {
        $items = is_array($raw) ? $raw : [];
        $pdo->prepare('DELETE FROM tenant_agent_workflow_steps WHERE tenant_id = :tenant_id')->execute(['tenant_id' => $tenantId]);
        $stmt = $pdo->prepare(
            'INSERT INTO tenant_agent_workflow_steps
                (tenant_id, step_key, label, step_type, config_json, active, position, source)
             VALUES
                (:tenant_id, :step_key, :label, :step_type, :config_json, 1, :position, "blueprint")'
        );
        foreach ($items as $index => $item) {
            if (!is_array($item) || trim((string) ($item['key'] ?? '')) === '') {
                continue;
            }
            $type = strtolower(trim((string) ($item['type'] ?? 'collect')));
            if (!in_array($type, ['collect', 'policy', 'action', 'handoff', 'complete'], true)) {
                $type = 'collect';
            }
            $config = is_array($item['config'] ?? null) ? $item['config'] : [];
            $stmt->execute([
                'tenant_id' => $tenantId,
                'step_key' => mb_substr(trim((string) $item['key']), 0, 120),
                'label' => mb_substr(trim((string) ($item['label'] ?? $item['key'])), 0, 180),
                'step_type' => $type,
                'config_json' => $config !== [] ? json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                'position' => max(1, (int) ($item['position'] ?? (($index + 1) * 10))),
            ]);
        }
    }

    /** @param array<string,mixed> $behavior */
    private function syncConversationBehavior(PDO $pdo, int $tenantId, array $behavior): void
    {
        $demand = is_array($behavior['demand'] ?? null) ? $behavior['demand'] : [];
        if (!empty($demand['enabled']) && $this->tableExists($pdo, 'tenant_triage_fields')) {
            try {
                $prompt = mb_substr(trim((string) ($demand['prompt'] ?? '')), 0, 1000);
                $pdo->prepare(
                    'UPDATE tenant_triage_fields
                     SET active = 1,
                         required_before_schedule = :required_before_schedule,
                         prompt_text = CASE WHEN :prompt_text <> "" THEN :prompt_text_value ELSE prompt_text END,
                         source = "tenant"
                     WHERE tenant_id = :tenant_id AND field_key = "brief_demand"'
                )->execute([
                    'required_before_schedule' => !empty($demand['required_before_schedule']) ? 1 : 0,
                    'prompt_text' => $prompt,
                    'prompt_text_value' => $prompt,
                    'tenant_id' => $tenantId,
                ]);
            } catch (Throwable) {
            }
        }

        $noAvailability = is_array($behavior['no_availability'] ?? null) ? $behavior['no_availability'] : [];
        $message = mb_substr(trim((string) ($noAvailability['message'] ?? '')), 0, 1200);
        if ($message !== '' && $this->tableExists($pdo, 'tenant_pre_schedule_settings')) {
            try {
                $pdo->prepare(
                    'UPDATE tenant_pre_schedule_settings SET no_availability_message = :message WHERE tenant_id = :tenant_id'
                )->execute(['message' => $message, 'tenant_id' => $tenantId]);
            } catch (Throwable) {
            }
        }
    }

    private function syncCalendarDefaults(PDO $pdo, int $tenantId, array $capabilities): void
    {
        if (!$this->tableExists($pdo, 'tenant_pre_schedule_settings')) {
            return;
        }
        $preSchedule = !empty($capabilities['calendar.pre_schedule']);
        $confirm = !empty($capabilities['calendar.confirm']);
        $humanApproval = !empty($capabilities['calendar.human_approval']);
        try {
            $pdo->prepare(
                'INSERT INTO tenant_pre_schedule_settings (tenant_id, enabled, require_human_approval, ai_can_suggest_slots, ai_can_confirm)
                 VALUES (:tenant_id, :enabled, :human, 1, :confirm)
                 ON DUPLICATE KEY UPDATE
                    enabled = VALUES(enabled), require_human_approval = VALUES(require_human_approval), ai_can_confirm = VALUES(ai_can_confirm)'
            )->execute([
                'tenant_id' => $tenantId,
                'enabled' => $preSchedule ? 1 : 0,
                'human' => $humanApproval ? 1 : 0,
                'confirm' => $confirm && !$humanApproval ? 1 : 0,
            ]);
        } catch (Throwable) {
            // Compatibilidade com instalações onde a tabela ainda não recebeu todas as colunas.
        }
    }

    /**
     * Mantém combinações de capabilities coerentes e seguras. O tenant pode personalizar
     * o blueprint, mas não deve criar estados contraditórios (ex.: exigir aprovação humana
     * e ao mesmo tempo confirmar automaticamente).
     *
     * @param array<string,mixed> $capabilities
     * @return array<string,bool>
     */
    private function normalizeCapabilities(array $capabilities): array
    {
        $normalized = [];
        foreach ($capabilities as $key => $enabled) {
            $key = trim((string) $key);
            if ($key === '') {
                continue;
            }
            $normalized[$key] = !empty($enabled);
        }

        if (!empty($normalized['eligibility.enabled'])) {
            $normalized['triage.enabled'] = true;
            $normalized['policy.fail_closed'] = true;
        }
        if (!empty($normalized['calendar.pre_schedule'])) {
            $normalized['calendar.read'] = true;
        }
        if (!empty($normalized['calendar.confirm'])) {
            $normalized['calendar.read'] = true;
            $normalized['calendar.pre_schedule'] = true;
        }
        if (!empty($normalized['calendar.human_approval'])) {
            $normalized['calendar.confirm'] = false;
            $normalized['calendar.read'] = true;
        }

        return $normalized;
    }

    private function normalizePolicyInput(string $type, mixed $value): mixed
    {
        return match ($type) {
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? !empty($value),
            'number' => is_numeric($value) ? (float) $value : 0,
            'json' => is_array($value) ? $value : ($this->decodeJson((string) $value) ?: []),
            default => trim((string) $value),
        };
    }

    /**
     * Atualiza apenas a personalização do fluxo do tenant. O tipo e a configuração
     * técnica de cada etapa continuam definidos pelo modelo da RS Connect.
     *
     * @param array<string,array<string,mixed>> $postedRows
     */
    private function updateWorkflowConfiguration(PDO $pdo, int $tenantId, array $postedRows): void
    {
        $currentStmt = $pdo->prepare(
            'SELECT step_key, label, step_type, active, position
             FROM tenant_agent_workflow_steps
             WHERE tenant_id = :tenant_id
             ORDER BY position, id'
        );
        $currentStmt->execute(['tenant_id' => $tenantId]);
        $currentRows = $currentStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($currentRows === []) {
            return;
        }

        $currentByKey = [];
        foreach ($currentRows as $row) {
            $currentByKey[(string) ($row['step_key'] ?? '')] = $row;
        }

        $ordered = [];
        foreach ($postedRows as $stepKey => $posted) {
            $stepKey = trim((string) $stepKey);
            if ($stepKey === '' || !isset($currentByKey[$stepKey]) || !is_array($posted)) {
                continue;
            }
            $position = max(1, min(9990, (int) ($posted['position'] ?? $currentByKey[$stepKey]['position'] ?? 100)));
            $ordered[] = [
                'step_key' => $stepKey,
                'position' => $position,
                'label' => mb_substr(
                    trim((string) ($posted['label'] ?? $currentByKey[$stepKey]['label'] ?? $stepKey)),
                    0,
                    180
                ),
            ];
        }

        // Etapas não enviadas nunca são excluídas. Elas permanecem no fim, preservando
        // compatibilidade e evitando que um formulário antigo desmonte o fluxo.
        $sentKeys = array_column($ordered, 'step_key');
        foreach ($currentRows as $row) {
            $key = (string) ($row['step_key'] ?? '');
            if ($key === '' || in_array($key, $sentKeys, true)) {
                continue;
            }
            $ordered[] = [
                'step_key' => $key,
                'position' => (int) ($row['position'] ?? 100),
                'label' => (string) ($row['label'] ?? $key),
            ];
        }

        usort($ordered, static function (array $a, array $b): int {
            $cmp = ((int) $a['position']) <=> ((int) $b['position']);
            return $cmp !== 0 ? $cmp : strcmp((string) $a['step_key'], (string) $b['step_key']);
        });

        $update = $pdo->prepare(
            'UPDATE tenant_agent_workflow_steps
             SET label = :label, position = :position, source = "tenant"
             WHERE tenant_id = :tenant_id AND step_key = :step_key'
        );
        foreach ($ordered as $index => $row) {
            $update->execute([
                'label' => (string) $row['label'],
                'position' => ($index + 1) * 10,
                'tenant_id' => $tenantId,
                'step_key' => (string) $row['step_key'],
            ]);
        }
    }

    /**
     * Faz a ordem visual do fluxo também influenciar a próxima informação pedida.
     * O Policy Engine continua sendo a trava final; alterar a sequência nunca remove
     * uma política de segurança ou autoriza uma ação proibida.
     *
     * @param array<int,array<string,mixed>> $fields
     * @param array<int,array<string,mixed>> $workflow
     * @return array<int,array<string,mixed>>
     */
    private function applyWorkflowOrderToTriageFields(array $fields, array $workflow): array
    {
        if ($fields === [] || $workflow === []) {
            return $fields;
        }

        $fallbackMap = [
            'identify_intent' => ['requester_name'],
            'identify_subject' => ['is_for_self', 'patient_name'],
            'collect_age' => ['patient_age'],
            'collect_modality' => ['modality'],
            'collect_demand' => ['brief_demand'],
            'collect_schedule' => ['preferred_schedule'],
            'collect_source' => ['contact_source'],
            'collect_service' => ['service'],
            'collect_professional' => ['professional'],
            'triage' => [],
        ];

        $rank = [];
        $rankIndex = 0;
        foreach ($workflow as $step) {
            if (!is_array($step) || empty($step['active'])) {
                continue;
            }
            $config = is_array($step['config'] ?? null) ? $step['config'] : [];
            $stepKey = trim((string) ($step['step_key'] ?? ''));

            $keys = [];
            if (!empty($config['field_key'])) {
                $keys[] = (string) $config['field_key'];
            }
            if (is_array($config['field_keys'] ?? null)) {
                foreach ($config['field_keys'] as $key) {
                    if (trim((string) $key) !== '') {
                        $keys[] = (string) $key;
                    }
                }
            }
            if ($keys === [] && array_key_exists($stepKey, $fallbackMap)) {
                $keys = $fallbackMap[$stepKey];
            }

            if ($stepKey === 'triage' && $keys === []) {
                foreach ($fields as $field) {
                    $key = trim((string) ($field['field_key'] ?? ''));
                    if ($key !== '' && !array_key_exists($key, $rank)) {
                        $rank[$key] = $rankIndex++;
                    }
                }
                continue;
            }

            foreach ($keys as $key) {
                $key = trim((string) $key);
                if ($key !== '' && !array_key_exists($key, $rank)) {
                    $rank[$key] = $rankIndex++;
                }
            }
        }

        $originalOrder = [];
        foreach ($fields as $index => $field) {
            $originalOrder[(string) ($field['field_key'] ?? '')] = $index;
        }

        usort($fields, static function (array $a, array $b) use ($rank, $originalOrder): int {
            $aKey = (string) ($a['field_key'] ?? '');
            $bKey = (string) ($b['field_key'] ?? '');
            $aRank = $rank[$aKey] ?? (10000 + ($originalOrder[$aKey] ?? 0));
            $bRank = $rank[$bKey] ?? (10000 + ($originalOrder[$bKey] ?? 0));
            return $aRank <=> $bRank;
        });

        return array_values($fields);
    }

    private function inferNicheCode(string $segment): string
    {
        $value = $this->normalize($segment);
        if ($value === '') {
            return '';
        }
        if (preg_match('/psicolog|psicoterap/u', $value)) {
            return 'psychology';
        }
        if (preg_match('/barbear|salao|cabeleire|estetic/u', $value)) {
            return 'beauty';
        }
        if (preg_match('/clinica|consultorio|medic|odont/u', $value)) {
            return 'health_clinic';
        }
        if (preg_match('/servic/u', $value)) {
            return 'services';
        }
        return '';
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        return strtr($value, [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a',
            'é' => 'e', 'ê' => 'e', 'í' => 'i',
            'ó' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ú' => 'u', 'ü' => 'u', 'ç' => 'c',
        ]);
    }

    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function decodeJsonValue(mixed $value): mixed
    {
        if (is_bool($value) || is_numeric($value) || is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string) $value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    private function emptyProfile(int $tenantId): array
    {
        return [
            'tenant_id' => $tenantId,
            'status' => 'inactive',
            'interaction_mode' => 'hybrid',
            'capabilities' => [],
            'triage_fields' => [],
            'policies' => [],
            'workflow' => [],
            'config' => [],
        ];
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        try {
            $stmt = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table LIMIT 1');
            $stmt->execute(['table' => $table]);
            return (bool) $stmt->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }
}
