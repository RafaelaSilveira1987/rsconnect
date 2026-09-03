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
            $bindings = $this->activeBindings($pdo, $tenantId, $instanceId);
            $keywordAgentId = $this->keywordMatch($bindings, $incomingContent);

            $pinnedId = $conversationId > 0 ? $this->pinnedAgentId($pdo, $tenantId, $instanceId, $conversationId) : 0;
            if ($pinnedId > 0) {
                // Uma mensagem que casa com o especialista de outro agente transfere
                // explicitamente o pin. Isso permite, por exemplo, Recepção -> Comercial
                // sem quebrar a continuidade das mensagens genéricas da conversa.
                if ($keywordAgentId > 0 && $keywordAgentId !== $pinnedId) {
                    $agentId = $pin && $conversationId > 0
                        ? $this->transferPinToSpecialist(
                            $pdo,
                            $tenantId,
                            $instanceId,
                            $conversationId,
                            $keywordAgentId
                        )
                        : $keywordAgentId;
                    return $this->agentById($pdo, $tenantId, $agentId);
                }

                return $this->agentById($pdo, $tenantId, $pinnedId);
            }

            $agentId = $keywordAgentId;
            if ($agentId < 1) {
                $agentId = $this->roundRobinAgentId(
                    $pdo,
                    $tenantId,
                    $instanceId,
                    $bindings,
                    $conversationId,
                    $pin
                );
            } elseif ($pin && $conversationId > 0) {
                // Especialistas por palavra-chave permanecem prioritários e não consomem
                // o cursor do round-robin. Em concorrência, o primeiro pin válido vence.
                $this->pin($pdo, $tenantId, $instanceId, $conversationId, $agentId, false);
                $agentId = $this->pinnedAgentId($pdo, $tenantId, $instanceId, $conversationId) ?: $agentId;
            }

            if ($agentId > 0) {
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
        $pinned = $pinnedId > 0 ? $this->agentById($pdo, $tenantId, $pinnedId) : null;
        if (is_array($pinned)) {
            $closedFallback = $pinned;
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

        $keywordAgentId = $availableBindings !== []
            ? $this->keywordMatch($availableBindings, $incomingContent)
            : 0;

        if (is_array($pinned) && $policy->allowsConversationalAutomation($pinned)) {
            if ($keywordAgentId > 0 && $keywordAgentId !== $pinnedId) {
                $agentId = $pin && $conversationId > 0
                    ? $this->transferPinToSpecialist(
                        $pdo,
                        $tenantId,
                        $instanceId,
                        $conversationId,
                        $keywordAgentId
                    )
                    : $keywordAgentId;
                if (isset($availableAgents[$agentId])) {
                    return $availableAgents[$agentId];
                }
                $resolved = $this->agentById($pdo, $tenantId, $agentId);
                if (is_array($resolved) && $policy->allowsConversationalAutomation($resolved)) {
                    return $resolved;
                }
            }

            return $pinned;
        }

        if ($availableBindings !== []) {
            $agentId = $keywordAgentId;
            if ($agentId < 1) {
                $agentId = $this->roundRobinAgentId(
                    $pdo,
                    $tenantId,
                    $instanceId,
                    $availableBindings,
                    $conversationId,
                    $pin
                );
            } elseif ($pin && $conversationId > 0) {
                // O especialista disponível vence a distribuição genérica sem avançar
                // o cursor do canal. O pin sem force mantém a primeira decisão concorrente.
                $this->pin($pdo, $tenantId, $instanceId, $conversationId, $agentId, false);
                $agentId = $this->pinnedAgentId($pdo, $tenantId, $instanceId, $conversationId) ?: $agentId;
            }
            if ($agentId > 0 && isset($availableAgents[$agentId])) {
                return $availableAgents[$agentId];
            }
            if ($agentId > 0) {
                $resolved = $this->agentById($pdo, $tenantId, $agentId);
                if (is_array($resolved) && $policy->allowsConversationalAutomation($resolved)) {
                    return $resolved;
                }
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

    public function supportsRoundRobin(PDO $pdo): bool
    {
        return $this->supportsRouting($pdo)
            && $this->tableExists($pdo, 'ai_agent_routing_state');
    }

    /**
     * Distribui apenas conversas genéricas entre os agentes elegíveis do canal.
     *
     * - palavra-chave/especialista é resolvida antes deste método;
     * - conversa já fixada é revalidada dentro do lock e não avança o cursor;
     * - o cursor é travado por linha (FOR UPDATE), portanto duas conversas
     *   concorrentes recebem posições consecutivas da rotação;
     * - quando pin=false, apenas consulta o próximo agente sem consumir a rotação.
     *
     * @param array<int,array<string,mixed>> $bindings
     */
    private function roundRobinAgentId(
        PDO $pdo,
        int $tenantId,
        int $instanceId,
        array $bindings,
        int $conversationId,
        bool $pin
    ): int {
        $agentIds = [];
        foreach ($bindings as $binding) {
            $agentId = (int) ($binding['agent_id'] ?? 0);
            if ($agentId > 0 && !in_array($agentId, $agentIds, true)) {
                $agentIds[] = $agentId;
            }
        }

        if ($agentIds === []) {
            return 0;
        }

        if (count($agentIds) === 1) {
            $agentId = $agentIds[0];
            if ($pin && $conversationId > 0) {
                $this->pin($pdo, $tenantId, $instanceId, $conversationId, $agentId, false);
                return $this->pinnedAgentId($pdo, $tenantId, $instanceId, $conversationId) ?: $agentId;
            }
            return $agentId;
        }

        // Sem a migration nova, mantém o comportamento anterior (primeiro elegível,
        // que pela ordenação existente é o principal/maior prioridade).
        if (!$this->supportsRoundRobin($pdo)) {
            $agentId = $agentIds[0];
            if ($pin && $conversationId > 0) {
                $this->pin($pdo, $tenantId, $instanceId, $conversationId, $agentId, false);
                return $this->pinnedAgentId($pdo, $tenantId, $instanceId, $conversationId) ?: $agentId;
            }
            return $agentId;
        }

        // Chamadas de inspeção não alteram o cursor.
        if (!$pin || $conversationId < 1) {
            return $this->peekRoundRobinAgentId($pdo, $tenantId, $instanceId, $agentIds);
        }

        $ownsTransaction = !$pdo->inTransaction();
        try {
            if ($ownsTransaction) {
                $pdo->beginTransaction();
            }

            // Garante a linha antes do FOR UPDATE. A chave única por canal serializa
            // também o primeiro acesso concorrente ao cursor.
            $ensure = $pdo->prepare(
                'INSERT INTO ai_agent_routing_state
                    (tenant_id, instance_id, last_agent_id, last_conversation_id, assignment_count)
                 VALUES (:tenant_id, :instance_id, NULL, NULL, 0)
                 ON DUPLICATE KEY UPDATE instance_id = VALUES(instance_id)'
            );
            $ensure->execute([
                'tenant_id' => $tenantId,
                'instance_id' => $instanceId,
            ]);

            $stateStatement = $pdo->prepare(
                'SELECT last_agent_id
                 FROM ai_agent_routing_state
                 WHERE tenant_id = :tenant_id AND instance_id = :instance_id
                 FOR UPDATE'
            );
            $stateStatement->execute([
                'tenant_id' => $tenantId,
                'instance_id' => $instanceId,
            ]);
            $lastAgentId = (int) ($stateStatement->fetchColumn() ?: 0);

            // Depois de adquirir o lock, revalida o pin. Isso evita que duas
            // mensagens simultâneas da MESMA conversa consumam duas posições.
            $pinnedId = $this->pinnedAgentId($pdo, $tenantId, $instanceId, $conversationId);
            if ($pinnedId > 0) {
                if ($ownsTransaction) {
                    $pdo->commit();
                }
                return $pinnedId;
            }

            $agentId = $this->nextAgentId($agentIds, $lastAgentId);

            $update = $pdo->prepare(
                'UPDATE ai_agent_routing_state
                 SET last_agent_id = :agent_id,
                     last_conversation_id = :conversation_id,
                     assignment_count = assignment_count + 1,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE tenant_id = :tenant_id AND instance_id = :instance_id'
            );
            $update->execute([
                'agent_id' => $agentId,
                'conversation_id' => $conversationId,
                'tenant_id' => $tenantId,
                'instance_id' => $instanceId,
            ]);

            $this->pin($pdo, $tenantId, $instanceId, $conversationId, $agentId, false);
            $finalAgentId = $this->pinnedAgentId($pdo, $tenantId, $instanceId, $conversationId) ?: $agentId;

            if ($ownsTransaction) {
                $pdo->commit();
            }

            return $finalAgentId;
        } catch (Throwable) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            // Fail-safe: não derruba webhook/IA por indisponibilidade do cursor.
            $agentId = $agentIds[0];
            if ($pin && $conversationId > 0) {
                try {
                    $this->pin($pdo, $tenantId, $instanceId, $conversationId, $agentId, false);
                    return $this->pinnedAgentId($pdo, $tenantId, $instanceId, $conversationId) ?: $agentId;
                } catch (Throwable) {
                    return $agentId;
                }
            }
            return $agentId;
        }
    }

    /** @param array<int,int> $agentIds */
    private function peekRoundRobinAgentId(PDO $pdo, int $tenantId, int $instanceId, array $agentIds): int
    {
        try {
            $statement = $pdo->prepare(
                'SELECT last_agent_id
                 FROM ai_agent_routing_state
                 WHERE tenant_id = :tenant_id AND instance_id = :instance_id
                 LIMIT 1'
            );
            $statement->execute([
                'tenant_id' => $tenantId,
                'instance_id' => $instanceId,
            ]);
            $lastAgentId = (int) ($statement->fetchColumn() ?: 0);
            return $this->nextAgentId($agentIds, $lastAgentId);
        } catch (Throwable) {
            return $agentIds[0] ?? 0;
        }
    }

    /** @param array<int,int> $agentIds */
    private function nextAgentId(array $agentIds, int $lastAgentId): int
    {
        if ($agentIds === []) {
            return 0;
        }
        $lastIndex = array_search($lastAgentId, $agentIds, true);
        if ($lastIndex === false) {
            return $agentIds[0];
        }
        return $agentIds[((int) $lastIndex + 1) % count($agentIds)];
    }

    /**
     * Transfere uma conversa já pinada para um especialista do mesmo canal.
     *
     * O lock da conversa serializa mensagens concorrentes e evita que uma transferência
     * de especialidade seja sobrescrita por outra decisão simultânea. O cursor de
     * round-robin não é alterado: transferência por keyword é uma decisão de intenção.
     */
    private function transferPinToSpecialist(
        PDO $pdo,
        int $tenantId,
        int $instanceId,
        int $conversationId,
        int $agentId
    ): int {
        if ($conversationId < 1 || $agentId < 1) {
            return $agentId;
        }

        $ownsTransaction = !$pdo->inTransaction();
        try {
            if ($ownsTransaction) {
                $pdo->beginTransaction();
            }

            $lock = $pdo->prepare(
                'SELECT ai_agent_id
                 FROM conversations
                 WHERE id = :conversation_id
                   AND tenant_id = :tenant_id
                   AND evolution_instance_id = :instance_id
                 FOR UPDATE'
            );
            $lock->execute([
                'conversation_id' => $conversationId,
                'tenant_id' => $tenantId,
                'instance_id' => $instanceId,
            ]);

            if ($lock->fetchColumn() === false) {
                if ($ownsTransaction) {
                    $pdo->rollBack();
                }
                return $agentId;
            }

            $this->pin($pdo, $tenantId, $instanceId, $conversationId, $agentId, true);
            $finalAgentId = $this->pinnedAgentId(
                $pdo,
                $tenantId,
                $instanceId,
                $conversationId
            ) ?: $agentId;

            if ($ownsTransaction) {
                $pdo->commit();
            }

            return $finalAgentId;
        } catch (Throwable) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            // Fail-safe: mantém a conversa no agente que já estava pinado quando
            // a troca segura não puder ser concluída.
            try {
                return $this->pinnedAgentId(
                    $pdo,
                    $tenantId,
                    $instanceId,
                    $conversationId
                ) ?: $agentId;
            } catch (Throwable) {
                return $agentId;
            }
        }
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
