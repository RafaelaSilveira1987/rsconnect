<?php

declare(strict_types=1);

use App\Core\Autoloader;
use App\Core\Database;
use App\Core\Env;
use App\Services\AgentRoutingService;

$root = dirname(__DIR__);
require_once $root . '/app/Core/Autoloader.php';
Autoloader::register($root . '/app');
Env::load($root . '/.env');

$pdo = Database::connection();
$errors = 0;
$warnings = 0;

function checkOk(bool $ok, string $label): void
{
    global $errors;
    echo ($ok ? '[OK] ' : '[ERRO] ') . $label . "\n";
    if (!$ok) {
        $errors++;
    }
}

function warn(string $label): void
{
    global $warnings;
    $warnings++;
    echo '[AVISO] ' . $label . "\n";
}

function tableExists(PDO $pdo, string $table): bool
{
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
    );
    $st->execute(['table' => $table]);
    return (int) $st->fetchColumn() > 0;
}

echo "RS CONNECT — HOMOLOGAÇÃO MULTIAGENTES / ROUND-ROBIN\n";
echo str_repeat('=', 72) . "\n\n";

echo "=== 1. PRÉ-REQUISITOS ===\n";
checkOk(tableExists($pdo, 'ai_agent_instance_bindings'), 'Vínculos N:N agente ↔ canal disponíveis');
checkOk(tableExists($pdo, 'ai_agent_routing_state'), 'Estado transacional do round-robin disponível');

$service = new AgentRoutingService();
checkOk($service->supportsRouting($pdo), 'Roteamento multiagente habilitado');
checkOk($service->supportsRoundRobin($pdo), 'Round-robin habilitado');

if ($errors > 0) {
    echo "\n[FALHA] Pré-requisitos ausentes. Aplique a migration 099 antes do teste.\n";
    exit(1);
}

echo "\n=== 2. CANAL COM PELO MENOS 2 AGENTES ===\n";
$candidate = $pdo->query(
    'SELECT b.tenant_id, b.instance_id, i.name AS instance_name,
            COUNT(DISTINCT b.agent_id) AS agent_count
     FROM ai_agent_instance_bindings b
     INNER JOIN ai_agents a
        ON a.id = b.agent_id AND a.tenant_id = b.tenant_id
     INNER JOIN evolution_instances i
        ON i.id = b.instance_id AND i.tenant_id = b.tenant_id
     WHERE b.status = "active"
       AND a.status = "active"
       AND a.auto_reply_enabled = 1
     GROUP BY b.tenant_id, b.instance_id, i.name
     HAVING COUNT(DISTINCT b.agent_id) >= 2
     ORDER BY agent_count DESC, b.instance_id ASC
     LIMIT 1'
)->fetch(PDO::FETCH_ASSOC);

if (!$candidate) {
    echo "[BLOQUEADO] Nenhum canal possui 2 agentes ativos com resposta automática.\n";
    echo "Configure dois agentes no mesmo canal e execute novamente.\n";
    exit(2);
}

$tenantId = (int) $candidate['tenant_id'];
$instanceId = (int) $candidate['instance_id'];
echo "Canal: #{$instanceId} {$candidate['instance_name']} | tenant={$tenantId} | agentes={$candidate['agent_count']}\n";

$bindingStatement = $pdo->prepare(
    'SELECT b.agent_id, b.is_primary, b.priority, b.routing_keywords, a.name
     FROM ai_agent_instance_bindings b
     INNER JOIN ai_agents a ON a.id = b.agent_id AND a.tenant_id = b.tenant_id
     WHERE b.tenant_id = :tenant_id
       AND b.instance_id = :instance_id
       AND b.status = "active"
       AND a.status = "active"
       AND a.auto_reply_enabled = 1
     ORDER BY b.is_primary DESC, b.priority DESC, b.id ASC'
);
$bindingStatement->execute(['tenant_id' => $tenantId, 'instance_id' => $instanceId]);
$bindings = $bindingStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];
$genericBindings = array_values(array_filter(
    $bindings,
    static fn (array $row): bool => trim((string) ($row['routing_keywords'] ?? '')) === ''
));
if ($genericBindings === []) {
    $genericBindings = $bindings;
}
$genericAgentIds = array_map(static fn (array $row): int => (int) $row['agent_id'], $genericBindings);

foreach ($bindings as $index => $binding) {
    $mode = (int) ($binding['is_primary'] ?? 0) === 1
        ? 'principal'
        : (trim((string) ($binding['routing_keywords'] ?? '')) !== '' ? 'especialista' : 'round-robin');
    echo sprintf(
        "  %d. agente #%d %s | modo=%s primary=%d priority=%d%s\n",
        $index + 1,
        (int) $binding['agent_id'],
        (string) $binding['name'],
        $mode,
        (int) $binding['is_primary'],
        (int) $binding['priority'],
        $mode === 'especialista' ? ' | keywords=' . trim((string) $binding['routing_keywords']) : ''
    );
}

$contactStatement = $pdo->prepare(
    'SELECT id FROM contacts WHERE tenant_id = :tenant_id ORDER BY id ASC LIMIT 1'
);
$contactStatement->execute(['tenant_id' => $tenantId]);
$contactId = (int) ($contactStatement->fetchColumn() ?: 0);

$pdo->beginTransaction();
try {
    if ($contactId < 1) {
        $phone = '5599' . substr((string) time(), -8);
        $insertContact = $pdo->prepare(
            'INSERT INTO contacts (tenant_id, evolution_instance_id, remote_jid, phone, name, status)
             VALUES (:tenant_id, :instance_id, :remote_jid, :phone, :name, "lead")'
        );
        $insertContact->execute([
            'tenant_id' => $tenantId,
            'instance_id' => $instanceId,
            'remote_jid' => $phone . '@s.whatsapp.net',
            'phone' => $phone,
            'name' => 'Homologacao Round Robin',
        ]);
        $contactId = (int) $pdo->lastInsertId();
    }

    // Zera somente dentro da transação; tudo será revertido no final.
    $reset = $pdo->prepare(
        'DELETE FROM ai_agent_routing_state WHERE tenant_id = :tenant_id AND instance_id = :instance_id'
    );
    $reset->execute(['tenant_id' => $tenantId, 'instance_id' => $instanceId]);

    echo "\n=== 3. DISTRIBUIÇÃO GENÉRICA ===\n";
    $selected = [];
    $conversationIds = [];
    $testCount = min(6, max(4, count($genericAgentIds) * 2));

    for ($i = 0; $i < $testCount; $i++) {
        $remoteJid = '5598' . substr((string) (time() + $i), -8) . '@s.whatsapp.net';
        $insertConversation = $pdo->prepare(
            'INSERT INTO conversations
                (tenant_id, evolution_instance_id, contact_id, remote_jid, status, attendance_mode)
             VALUES (:tenant_id, :instance_id, :contact_id, :remote_jid, "open", "ai")'
        );
        $insertConversation->execute([
            'tenant_id' => $tenantId,
            'instance_id' => $instanceId,
            'contact_id' => $contactId,
            'remote_jid' => $remoteJid,
        ]);
        $conversationId = (int) $pdo->lastInsertId();
        $conversationIds[] = $conversationId;

        $agent = $service->resolve(
            $pdo,
            ['id' => $instanceId, 'tenant_id' => $tenantId],
            $conversationId,
            '__rsconnect_round_robin_homologacao__',
            true
        );
        $selectedId = (int) ($agent['id'] ?? 0);
        $selected[] = $selectedId;
        $expectedId = $genericAgentIds[$i % count($genericAgentIds)] ?? 0;

        checkOk(
            $selectedId === $expectedId,
            'Conversa ' . ($i + 1) . " → agente #{$selectedId} (esperado #{$expectedId})"
        );
    }

    echo "Sequência: " . implode(' → ', array_map(static fn (int $id): string => '#' . $id, $selected)) . "\n";

    $specialistIds = array_map(
        static fn (array $row): int => (int) $row['agent_id'],
        array_values(array_filter(
            $bindings,
            static fn (array $row): bool => trim((string) ($row['routing_keywords'] ?? '')) !== ''
        ))
    );
    if ($specialistIds !== [] && count($genericAgentIds) < count($bindings)) {
        checkOk(
            array_intersect($selected, $specialistIds) === [],
            'Especialistas não participam da distribuição genérica'
        );
    }

    echo "\n=== 4. CONTINUIDADE / PINNING ===\n";
    $firstConversationId = $conversationIds[0] ?? 0;
    $firstAgentId = $selected[0] ?? 0;
    $beforeCountStatement = $pdo->prepare(
        'SELECT assignment_count FROM ai_agent_routing_state
         WHERE tenant_id = :tenant_id AND instance_id = :instance_id'
    );
    $beforeCountStatement->execute(['tenant_id' => $tenantId, 'instance_id' => $instanceId]);
    $beforeCount = (int) ($beforeCountStatement->fetchColumn() ?: 0);

    $again = $service->resolve(
        $pdo,
        ['id' => $instanceId, 'tenant_id' => $tenantId],
        $firstConversationId,
        'segunda mensagem da mesma conversa',
        true
    );
    $againId = (int) ($again['id'] ?? 0);

    $beforeCountStatement->execute(['tenant_id' => $tenantId, 'instance_id' => $instanceId]);
    $afterCount = (int) ($beforeCountStatement->fetchColumn() ?: 0);

    checkOk($againId === $firstAgentId, 'Mesma conversa mantém o mesmo agente');
    checkOk($afterCount === $beforeCount, 'Mensagem seguinte não consome nova posição do round-robin');

    echo "\n=== 5. KEYWORD / ESPECIALISTA ===\n";
    $keywordBinding = null;
    $keyword = '';
    foreach ($bindings as $binding) {
        $raw = trim((string) ($binding['routing_keywords'] ?? ''));
        if ($raw === '') {
            continue;
        }
        $parts = preg_split('/[,;\n]+/u', $raw) ?: [];
        $keyword = trim((string) ($parts[0] ?? ''));
        if ($keyword !== '') {
            $keywordBinding = $binding;
            break;
        }
    }

    if (is_array($keywordBinding) && $keyword !== '') {
        $insertConversation = $pdo->prepare(
            'INSERT INTO conversations
                (tenant_id, evolution_instance_id, contact_id, remote_jid, status, attendance_mode)
             VALUES (:tenant_id, :instance_id, :contact_id, :remote_jid, "open", "ai")'
        );
        $remoteJid = '5597' . substr((string) time(), -8) . '@s.whatsapp.net';
        $insertConversation->execute([
            'tenant_id' => $tenantId,
            'instance_id' => $instanceId,
            'contact_id' => $contactId,
            'remote_jid' => $remoteJid,
        ]);
        $keywordConversationId = (int) $pdo->lastInsertId();

        $beforeCountStatement->execute(['tenant_id' => $tenantId, 'instance_id' => $instanceId]);
        $beforeKeywordCount = (int) ($beforeCountStatement->fetchColumn() ?: 0);

        $specialist = $service->resolve(
            $pdo,
            ['id' => $instanceId, 'tenant_id' => $tenantId],
            $keywordConversationId,
            'Preciso falar sobre ' . $keyword,
            true
        );
        $specialistId = (int) ($specialist['id'] ?? 0);

        $beforeCountStatement->execute(['tenant_id' => $tenantId, 'instance_id' => $instanceId]);
        $afterKeywordCount = (int) ($beforeCountStatement->fetchColumn() ?: 0);

        checkOk(
            $specialistId === (int) $keywordBinding['agent_id'],
            "Keyword '{$keyword}' → especialista #{$specialistId}"
        );
        checkOk(
            $afterKeywordCount === $beforeKeywordCount,
            'Especialista por keyword não consome o cursor genérico'
        );

        echo "\n=== 5B. TRANSFERÊNCIA IA → IA POR INTENÇÃO ===\n";
        $sourceBinding = null;
        foreach ($bindings as $binding) {
            if ((int) ($binding['agent_id'] ?? 0) !== (int) $keywordBinding['agent_id']) {
                $sourceBinding = $binding;
                break;
            }
        }

        if (is_array($sourceBinding)) {
            $remoteJid = '5596' . substr((string) (time() + 20), -8) . '@s.whatsapp.net';
            $insertConversation->execute([
                'tenant_id' => $tenantId,
                'instance_id' => $instanceId,
                'contact_id' => $contactId,
                'remote_jid' => $remoteJid,
            ]);
            $handoffConversationId = (int) $pdo->lastInsertId();
            $sourceAgentId = (int) $sourceBinding['agent_id'];
            $targetAgentId = (int) $keywordBinding['agent_id'];

            checkOk(
                $service->pin(
                    $pdo,
                    $tenantId,
                    $instanceId,
                    $handoffConversationId,
                    $sourceAgentId,
                    true
                ),
                "Conversa fixada inicialmente no agente #{$sourceAgentId}"
            );

            $beforeCountStatement->execute(['tenant_id' => $tenantId, 'instance_id' => $instanceId]);
            $beforeHandoffCount = (int) ($beforeCountStatement->fetchColumn() ?: 0);

            $transferred = $service->resolve(
                $pdo,
                ['id' => $instanceId, 'tenant_id' => $tenantId],
                $handoffConversationId,
                'Quero falar sobre ' . $keyword,
                true
            );
            $transferredId = (int) ($transferred['id'] ?? 0);

            $pinStatement = $pdo->prepare(
                'SELECT ai_agent_id FROM conversations
                 WHERE id = :conversation_id AND tenant_id = :tenant_id
                 LIMIT 1'
            );
            $pinStatement->execute([
                'conversation_id' => $handoffConversationId,
                'tenant_id' => $tenantId,
            ]);
            $persistedTargetId = (int) ($pinStatement->fetchColumn() ?: 0);

            $beforeCountStatement->execute(['tenant_id' => $tenantId, 'instance_id' => $instanceId]);
            $afterHandoffCount = (int) ($beforeCountStatement->fetchColumn() ?: 0);

            checkOk(
                $transferredId === $targetAgentId,
                "Keyword transfere agente #{$sourceAgentId} → especialista #{$targetAgentId}"
            );
            checkOk(
                $persistedTargetId === $targetAgentId,
                'Novo especialista fica pinado na conversa'
            );
            checkOk(
                $afterHandoffCount === $beforeHandoffCount,
                'Transferência IA → IA não consome cursor do round-robin'
            );
        } else {
            warn('Não há outro agente elegível para provar transferência IA → IA.');
        }
    } else {
        warn('Canal não possui routing_keywords configuradas; prioridade por keyword ficou validada pela suíte estática.');
    }

    echo "\n=== 6. ROLLBACK ===\n";
    $pdo->rollBack();
    checkOk(!$pdo->inTransaction(), 'ROLLBACK concluído — conversas e cursor de teste não persistiram');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo '[ERRO] ' . $e->getMessage() . "\n";
    $errors++;
}

echo "\n=== 7. RESULTADO ===\n";
echo "Falhas: {$errors} | Avisos: {$warnings}\n";
if ($errors === 0) {
    echo "[APROVADO] ROUND-ROBIN + CONTINUIDADE HOMOLOGADOS NO MOTOR E NO BANCO.\n";
    exit(0);
}

echo "[FALHA] MULTIAGENTES requer revisão.\n";
exit(1);
