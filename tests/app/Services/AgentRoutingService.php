<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use Throwable;

final class AgentRoutingService
{
    /**
     * Resolve o agente da conversa e o fixa para manter continuidade.
     * Ordem: agente já fixado -> palavras de roteamento -> principal do canal -> legado.
     */
    public function resolve(PDO $pdo, array $instance, int $conversationId = 0, string $incomingContent = '', bool $pin = true): ?array
    {
        $tenantId = (int) ($instance['tenant_id'] ?? 0);
        $instanceId = (int) ($instance['id'] ?? 0);
        if ($tenantId < 1 || $instanceId < 1) {
            return null;
        }

        if ($this->supportsRouting($pdo)) {
            $pinnedId = $conversationId > 0 ? $this->pinnedAgentId($pdo, $tenantId, $instanceId, $conversationId) : 0;
            if ($pinnedId > 0) {
                return $this->agentById($pdo, $tenantId, $pinnedId);
            }

            $bindings = $this->activeBindings($pdo, $tenantId, $instanceId);
            $agentId = $this->keywordMatch($bindings, $incomingContent);
            if ($agentId < 1) {
                foreach ($bindings as $binding) {
                    if ((int) ($binding['is_primary'] ?? 0) === 1) {
                        $agentId = (int) $binding['agent_id'];
                        break;
                    }
                }
            }
            if ($agentId < 1 && $bindings !== []) {
                $agentId = (int) ($bindings[0]['agent_id'] ?? 0);
            }

            if ($agentId > 0) {
                if ($pin && $conversationId > 0) {
                    $this->pin($pdo, $tenantId, $instanceId, $conversationId, $agentId, true);
                }
                return $this->agentById($pdo, $tenantId, $agentId);
            }
        }

        return $this->legacyAgent($pdo, $tenantId, $instanceId);
    }

    /**
     * Resolve o agente para uma automação que vai responder agora.
     *
     * Se o agente fixado/especialista estiver fora do próprio expediente, mas houver
     * outro agente ativo e disponível no mesmo canal, usa o agente disponível em vez
     * de declarar o WhatsApp inteiro fora do horário. Só retorna um agente fechado
     * quando nenhum agente elegível do canal estiver disponível.
     */
    public function resolveForAutomation(PDO $pdo, array $instance, int $conversationId = 0, string $incomingContent = '', bool $pin = true): ?array
    {
        $tenantId = (int) ($instance['tenant_id'] ?? 0);
        $instanceId = (int) ($instance['id'] ?? 0);
        if ($tenantId < 1 || $instanceId < 1) {
            return null;
        }

        if (!$this->supportsRouting($pdo)) {
            return $this->legacyAgent($pdo, $tenantId, $instanceId);
        }

        $policy = new AgentOperatingPolicyService();
        $closedFallback = null;

        $pinnedId = $conversationId > 0 ? $this->pinnedAgentId($pdo, $tenantId, $instanceId, $conversationId) : 0;
        if ($pinnedId > 0) {
            $pinned = $this->agentById($pdo, $tenantId, $pinnedId);
            if (is_array($pinned)) {
                if ($policy->allowsConversationalAutomation($pinned)) {
                    return $pinned;
                }
                $closedFallback = $pinned;
            }
        }

        $bindings = $this->activeBindings($pdo, $tenantId, $instanceId);
        $availableBindings = [];
        $availableAgents = [];
        foreach ($bindings as $binding) {
            $agentId = (int) ($binding['agent_id'] ?? 0);
            if ($agentId < 1) {
                continue;
            }
            $agent = $this->agentById($pdo, $tenantId, $agentId);
            if (!is_array($agent)) {
                continue;
            }
            if ($closedFallback === null) {
                $closedFallback = $agent;
            }
            if (!$policy->allowsConversationalAutomation($agent)) {
                continue;
            }
            $availableBindings[] = $binding;
            $availableAgents[$agentId] = $agent;
        }

        if ($availableBindings !== []) {
            $agentId = $this->keywordMatch($availableBindings, $incomingContent);
            if ($agentId < 1) {
                foreach ($availableBindings as $binding) {
                    if ((int) ($binding['is_primary'] ?? 0) === 1) {
                        $agentId = (int) ($binding['agent_id'] ?? 0);
                        break;
                    }
                }
            }
            if ($agentId < 1) {
                $agentId = (int) ($availableBindings[0]['agent_id'] ?? 0);
            }
            if ($agentId > 0 && isset($availableAgents[$agentId])) {
                if ($pin && $conversationId > 0) {
                    $this->pin($pdo, $tenantId, $instanceId, $conversationId, $agentId, true);
                }
                return $availableAgents[$agentId];
            }
        }

        // Todos os agentes vinculados estão fora do expediente. Mantém o agente
        // originalmente resolvido para que a mensagem de ausência e a recuperação
        // pós-horário fiquem associadas à configuração correta.
        if (is_array($closedFallback)) {
            return $closedFallback;
        }

        return $this->resolve($pdo, $instance, $conversationId, $incomingContent, $pin);
    }

    /** @return array<int,array<string,mixed>> */
    public function agentsForInstance(PDO $pdo, int $tenantId, int $instanceId, bool $onlyAutoReply = false): array
    {
        if (!$this->supportsRouting($pdo)) {
            $sql = 'SELECT a.id AS agent_id, a.name, a.segment, a.status, a.auto_reply_enabled,
                           CASE WHEN a.is_default = 1 THEN 1 ELSE 0 END AS is_primary,
                           CASE WHEN a.is_default = 1 THEN 200 ELSE 100 END AS priority,
                           NULL AS routing_keywords
                    FROM ai_agents a
                    WHERE a.tenant_id = :tenant_id
                      AND (a.instance_id = :instance_id OR a.is_default = 1)';
            if ($onlyAutoReply) {
                $sql .= ' AND a.status = "active" AND a.auto_reply_enabled = 1';
            }
            $sql .= ' ORDER BY a.is_default DESC, a.name';
            $statement = $pdo->prepare($sql);
            $statement->execute(['tenant_id' => $tenantId, 'instance_id' => $instanceId]);
            return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        $sql = 'SELECT b.id AS binding_id, b.agent_id, b.instance_id, b.is_primary, b.priority,
                       b.routing_keywords, b.status AS binding_status,
                       a.name, a.segment, a.status, a.auto_reply_enabled, a.is_default
                FROM ai_agent_instance_bindings b
                INNER JOIN ai_agents a ON a.id = b.agent_id AND a.tenant_id = b.tenant_id
                WHERE b.tenant_id = :tenant_id
                  AND b.instance_id = :instance_id
                  AND b.status = "active"';
        if ($onlyAutoReply) {
            $sql .= ' AND a.status = "active" AND a.auto_reply_enabled = 1';
        }
        $sql .= ' ORDER BY b.is_primary DESC, b.priority DESC, a.name';
        $statement = $pdo->prepare($sql);
        $statement->execute(['tenant_id' => $tenantId, 'instance_id' => $instanceId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<int,array<string,mixed>> */
    public function bindingsForTenant(PDO $pdo, int $tenantId): array
    {
        if (!$this->supportsRouting($pdo)) {
            return [];
        }
        $statement = $pdo->prepare(
            'SELECT b.*, a.name AS agent_name, a.segment AS agent_segment, i.name AS instance_name
             FROM ai_agent_instance_bindings b
             INNER JOIN ai_agents a ON a.id = b.agent_id
             INNER JOIN evolution_instances i ON i.id = b.instance_id
             WHERE b.tenant_id = :tenant_id AND b.status = "active"
             ORDER BY i.is_default DESC, i.name, b.is_primary DESC, b.priority DESC, a.name'
        );
        $statement->execute(['tenant_id' => $tenantId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function pin(PDO $pdo, int $tenantId, int $instanceId, int $conversationId, int $agentId, bool $force = true): bool
    {
        if (!$this->supportsRouting($pdo) || $conversationId < 1 || $agentId < 1) {
            return false;
        }

        $check = $pdo->prepare(
            'SELECT 1
             FROM ai_agent_instance_bindings b
             INNER JOIN ai_agents a ON a.id = b.agent_id
             WHERE b.tenant_id = :tenant_id
               AND b.instance_id = :instance_id
               AND b.agent_id = :agent_id
               AND b.status = "active"
               AND a.status = "active"
               AND a.auto_reply_enabled = 1
             LIMIT 1'
        );
        $check->execute([
            'tenant_id' => $tenantId,
            'instance_id' => $instanceId,
            'agent_id' => $agentId,
        ]);
        if (!$check->fetchColumn()) {
            return false;
        }

        $sql = 'UPDATE conversations SET ai_agent_id = :agent_id
                WHERE id = :conversation_id AND tenant_id = :tenant_id AND evolution_instance_id = :instance_id';
        if (!$force) {
            $sql .= ' AND ai_agent_id IS NULL';
        }
        $statement = $pdo->prepare($sql);
        $statement->execute([
            'agent_id' => $agentId,
            'conversation_id' => $conversationId,
            'tenant_id' => $tenantId,
            'instance_id' => $instanceId,
        ]);
        if ($statement->rowCount() > 0) {
            return true;
        }

        // MySQL pode retornar 0 quando o valor já era o mesmo; nesse caso o vínculo é válido.
        $confirm = $pdo->prepare(
            'SELECT 1 FROM conversations
             WHERE id = :conversation_id AND tenant_id = :tenant_id
               AND evolution_instance_id = :instance_id AND ai_agent_id = :agent_id
             LIMIT 1'
        );
        $confirm->execute([
            'conversation_id' => $conversationId,
            'tenant_id' => $tenantId,
            'instance_id' => $instanceId,
            'agent_id' => $agentId,
        ]);
        return (bool) $confirm->fetchColumn();
    }

    public function supportsRouting(PDO $pdo): bool
    {
        return $this->tableExists($pdo, 'ai_agent_instance_bindings')
            && $this->columnExists($pdo, 'conversations', 'ai_agent_id');
    }

    private function pinnedAgentId(PDO $pdo, int $tenantId, int $instanceId, int $conversationId): int
    {
        try {
            $statement = $pdo->prepare(
                'SELECT c.ai_agent_id
                 FROM conversations c
                 INNER JOIN ai_agents a ON a.id = c.ai_agent_id AND a.tenant_id = c.tenant_id
                 INNER JOIN ai_agent_instance_bindings b
                    ON b.agent_id = a.id
                   AND b.instance_id = c.evolution_instance_id
                   AND b.tenant_id = c.tenant_id
                   AND b.status = "active"
                 WHERE c.id = :conversation_id
                   AND c.tenant_id = :tenant_id
                   AND c.evolution_instance_id = :instance_id
                   AND a.status = "active"
                   AND a.auto_reply_enabled = 1
                 LIMIT 1'
            );
            $statement->execute([
                'conversation_id' => $conversationId,
                'tenant_id' => $tenantId,
                'instance_id' => $instanceId,
            ]);
            return (int) ($statement->fetchColumn() ?: 0);
        } catch (Throwable) {
            return 0;
        }
    }

    /** @return array<int,array<string,mixed>> */
    private function activeBindings(PDO $pdo, int $tenantId, int $instanceId): array
    {
        $statement = $pdo->prepare(
            'SELECT b.agent_id, b.is_primary, b.priority, b.routing_keywords
             FROM ai_agent_instance_bindings b
             INNER JOIN ai_agents a ON a.id = b.agent_id AND a.tenant_id = b.tenant_id
             WHERE b.tenant_id = :tenant_id
               AND b.instance_id = :instance_id
               AND b.status = "active"
               AND a.status = "active"
               AND a.auto_reply_enabled = 1
             ORDER BY b.is_primary DESC, b.priority DESC, b.id ASC'
        );
        $statement->execute(['tenant_id' => $tenantId, 'instance_id' => $instanceId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @param array<int,array<string,mixed>> $bindings */
    private function keywordMatch(array $bindings, string $incomingContent): int
    {
        $content = $this->normalize($incomingContent);
        if ($content === '') {
            return 0;
        }

        $bestAgent = 0;
        $bestScore = 0;
        foreach ($bindings as $binding) {
            $keywords = array_values(array_filter(array_map('trim', preg_split('/[,;\n]+/u', (string) ($binding['routing_keywords'] ?? '')) ?: [])));
            $score = 0;
            foreach ($keywords as $keyword) {
                $normalized = $this->normalize($keyword);
                if ($normalized !== '' && str_contains($content, $normalized)) {
                    $score += max(1, mb_strlen($normalized));
                }
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestAgent = (int) ($binding['agent_id'] ?? 0);
            }
        }
        return $bestAgent;
    }

    private function agentById(PDO $pdo, int $tenantId, int $agentId): ?array
    {
        $statement = $pdo->prepare($this->agentSelectSql() . ' WHERE a.id = :agent_id AND a.tenant_id = :tenant_id LIMIT 1');
        $statement->execute(['agent_id' => $agentId, 'tenant_id' => $tenantId]);
        $agent = $statement->fetch(PDO::FETCH_ASSOC);
        return $agent ?: null;
    }

    private function legacyAgent(PDO $pdo, int $tenantId, int $instanceId): ?array
    {
        $statement = $pdo->prepare(
            $this->agentSelectSql() .
            ' WHERE a.tenant_id = :tenant_id
                AND a.status = "active"
                AND a.auto_reply_enabled = 1
                AND (a.instance_id = :instance_id_filter OR a.instance_id IS NULL OR a.is_default = 1)
              ORDER BY (a.instance_id = :instance_id_order) DESC, a.is_default DESC, a.id DESC
              LIMIT 1'
        );
        $statement->execute([
            'tenant_id' => $tenantId,
            'instance_id_filter' => $instanceId,
            'instance_id_order' => $instanceId,
        ]);
        $agent = $statement->fetch(PDO::FETCH_ASSOC);
        return $agent ?: null;
    }

    private function agentSelectSql(): string
    {
        return 'SELECT a.*,
                    COALESCE(ac_agent.id, ac_tenant.id) AS credential_id,
                    COALESCE(ac_agent.label, ac_tenant.label) AS credential_label,
                    COALESCE(ac_agent.provider, ac_tenant.provider) AS credential_provider,
                    COALESCE(ac_agent.api_key_encrypted, ac_tenant.api_key_encrypted) AS credential_api_key_encrypted,
                    COALESCE(ac_agent.base_url, ac_tenant.base_url) AS credential_base_url,
                    COALESCE(ac_agent.default_model, ac_tenant.default_model) AS credential_default_model
             FROM ai_agents a
             LEFT JOIN ai_provider_credentials ac_agent ON ac_agent.id = (
                SELECT x.id FROM ai_provider_credentials x
                WHERE x.agent_id = a.id AND x.status = "active"
                ORDER BY x.is_default DESC, x.id DESC LIMIT 1
             )
             LEFT JOIN ai_provider_credentials ac_tenant ON ac_tenant.id = (
                SELECT y.id FROM ai_provider_credentials y
                WHERE y.tenant_id = a.tenant_id AND y.agent_id IS NULL AND y.status = "active"
                ORDER BY y.is_default DESC, y.id DESC LIMIT 1
             )';
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        return preg_replace('/\s+/u', ' ', $ascii !== false ? $ascii : $value) ?: $value;
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        try {
            $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table');
            $statement->execute(['table' => $table]);
            return (int) $statement->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    private function columnExists(PDO $pdo, string $table, string $column): bool
    {
        try {
            $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column');
            $statement->execute(['table' => $table, 'column' => $column]);
            return (int) $statement->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }
}
