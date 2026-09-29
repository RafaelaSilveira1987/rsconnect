#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Database;
use App\Services\ConversationCycleService;

// Este utilitário roda no CLI. O bootstrap global oculta detalhes de exceções
// quando APP_DEBUG está desativado, o que dificulta diagnosticar um comando
// administrativo. Aqui exibimos somente a mensagem técnica no terminal.
set_exception_handler(static function (Throwable $exception): void {
    fwrite(STDERR, "Falha no utilitário de reset: " . $exception->getMessage() . PHP_EOL);
    exit(1);
});

$options = getopt('', [
    'tenant:',
    'contact:',
    'agent::',
    'apply',
    'purge-messages',
    'purge-calendar',
    'help',
]);

$help = static function (): void {
    echo "RS Connect — limpeza segura de contato de teste\n\n";
    echo "Prévia (não altera o banco):\n";
    echo "  php bin/reset-test-conversation.php --tenant=ID_OU_SLUG --contact=Tester --agent=Rafa\n\n";
    echo "Aplicar reset de estado/memória mantendo mensagens:\n";
    echo "  php bin/reset-test-conversation.php --tenant=ID_OU_SLUG --contact=Tester --agent=Rafa --apply\n\n";
    echo "Apagar também o histórico de mensagens do contato de teste:\n";
    echo "  php bin/reset-test-conversation.php --tenant=ID_OU_SLUG --contact=Tester --agent=Rafa --apply --purge-messages\n\n";
    echo "Opcionalmente remover também compromissos/pré-agendamentos do contato:\n";
    echo "  ... --purge-calendar\n\n";
    echo "A ação é sempre limitada a uma única empresa e a um único contato encontrado de forma exata.\n";
};

if (isset($options['help'])) {
    $help();
    exit(0);
}

$tenantRef = trim((string) ($options['tenant'] ?? ''));
$contactRef = trim((string) ($options['contact'] ?? ''));
$agentRef = trim((string) ($options['agent'] ?? ''));
if ($tenantRef === '' || $contactRef === '') {
    fwrite(STDERR, "Informe --tenant e --contact.\n\n");
    $help();
    exit(2);
}

$pdo = Database::connection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$tableExists = static function (PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table LIMIT 1');
    $stmt->execute(['table' => $table]);
    return (bool) $stmt->fetchColumn();
};
$columnExists = static function (PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column LIMIT 1');
    $stmt->execute(['table' => $table, 'column' => $column]);
    return (bool) $stmt->fetchColumn();
};

if (ctype_digit($tenantRef)) {
    $tenantSql = 'SELECT id, name, slug FROM tenants WHERE id = :ref LIMIT 2';
    $tenantParams = ['ref' => $tenantRef];
} else {
    // PDO usa prepares nativos (ATTR_EMULATE_PREPARES=false), portanto o mesmo
    // placeholder nomeado não pode ser reutilizado duas vezes na instrução.
    $tenantSql = 'SELECT id, name, slug FROM tenants WHERE slug = :slug_ref OR name = :name_ref LIMIT 2';
    $tenantParams = ['slug_ref' => $tenantRef, 'name_ref' => $tenantRef];
}
$stmt = $pdo->prepare($tenantSql);
$stmt->execute($tenantParams);
$tenants = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
if (count($tenants) !== 1) {
    fwrite(STDERR, count($tenants) === 0 ? "Empresa não encontrada.\n" : "Empresa ambígua; use o ID.\n");
    exit(3);
}
$tenant = $tenants[0];
$tenantId = (int) $tenant['id'];

if (ctype_digit($contactRef)) {
    $contactSql = 'SELECT id, name, phone FROM contacts WHERE tenant_id = :tenant_id AND id = :ref LIMIT 2';
    $contactParams = ['tenant_id' => $tenantId, 'ref' => $contactRef];
} else {
    $contactSql = 'SELECT id, name, phone FROM contacts WHERE tenant_id = :tenant_id AND (name = :name_ref OR phone = :phone_ref) LIMIT 2';
    $contactParams = ['tenant_id' => $tenantId, 'name_ref' => $contactRef, 'phone_ref' => $contactRef];
}
$stmt = $pdo->prepare($contactSql);
$stmt->execute($contactParams);
$contacts = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
if (count($contacts) !== 1) {
    fwrite(STDERR, count($contacts) === 0 ? "Contato não encontrado nesta empresa.\n" : "Contato ambíguo; use o ID ou telefone exato.\n");
    exit(4);
}
$contact = $contacts[0];
$contactId = (int) $contact['id'];

$agent = null;
if ($agentRef !== '') {
    $agentSql = ctype_digit($agentRef)
        ? 'SELECT id, name FROM ai_agents WHERE tenant_id = :tenant_id AND id = :ref LIMIT 2'
        : 'SELECT id, name FROM ai_agents WHERE tenant_id = :tenant_id AND name = :ref LIMIT 2';
    $stmt = $pdo->prepare($agentSql);
    $stmt->execute(['tenant_id' => $tenantId, 'ref' => $agentRef]);
    $agents = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (count($agents) !== 1) {
        fwrite(STDERR, count($agents) === 0 ? "Agente não encontrado nesta empresa.\n" : "Agente ambíguo; use o ID.\n");
        exit(5);
    }
    $agent = $agents[0];
}

$stmt = $pdo->prepare('SELECT id, status, attendance_mode, last_message_at, last_message_preview' .
    ($columnExists($pdo, 'conversations', 'ai_agent_id') ? ', ai_agent_id' : '') .
    ' FROM conversations WHERE tenant_id = :tenant_id AND contact_id = :contact_id ORDER BY id');
$stmt->execute(['tenant_id' => $tenantId, 'contact_id' => $contactId]);
$conversations = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
$conversationIds = array_values(array_map(static fn (array $row): int => (int) $row['id'], $conversations));

$messageCount = 0;
if ($conversationIds !== [] && $tableExists($pdo, 'conversation_messages')) {
    $placeholders = implode(',', array_fill(0, count($conversationIds), '?'));
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM conversation_messages WHERE conversation_id IN (' . $placeholders . ')');
    $stmt->execute($conversationIds);
    $messageCount = (int) $stmt->fetchColumn();
}
$appointmentCount = 0;
if ($tableExists($pdo, 'calendar_appointments')) {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM calendar_appointments WHERE tenant_id = :tenant_id AND contact_id = :contact_id');
    $stmt->execute(['tenant_id' => $tenantId, 'contact_id' => $contactId]);
    $appointmentCount = (int) $stmt->fetchColumn();
}

echo "Empresa: #{$tenantId} " . (string) $tenant['name'] . "\n";
echo "Contato: #{$contactId} " . (string) ($contact['name'] ?? '') . " | " . (string) ($contact['phone'] ?? '') . "\n";
if ($agent !== null) {
    echo "Agente validado: #" . (int) $agent['id'] . ' ' . (string) $agent['name'] . "\n";
}
echo "Conversas encontradas: " . count($conversations) . "\n";
echo "Mensagens encontradas: {$messageCount}\n";
echo "Compromissos/pré-agendamentos encontrados: {$appointmentCount}\n";
foreach ($conversations as $conversation) {
    echo '  conversa #' . (int) $conversation['id']
        . ' | ' . (string) ($conversation['status'] ?? '')
        . ' | ' . (string) ($conversation['attendance_mode'] ?? '')
        . ' | última=' . (string) ($conversation['last_message_at'] ?? '-')
        . (array_key_exists('ai_agent_id', $conversation) ? ' | agente=' . (string) ($conversation['ai_agent_id'] ?? '-') : '')
        . "\n";
}

if (!isset($options['apply'])) {
    echo "\nPRÉVIA somente. Nenhum dado foi alterado. Use --apply para confirmar.\n";
    exit(0);
}

try {
    $pdo->beginTransaction();

    if ($tableExists($pdo, 'contact_ai_memory')) {
        $pdo->prepare('DELETE FROM contact_ai_memory WHERE tenant_id = :tenant_id AND contact_id = :contact_id')
            ->execute(['tenant_id' => $tenantId, 'contact_id' => $contactId]);
    }

    foreach ($conversationIds as $conversationId) {
        foreach (['conversation_flow_states', 'conversation_triage_sessions', 'conversation_ai_memory', 'conversation_policy_decisions', 'ai_after_hours_pending'] as $table) {
            if (!$tableExists($pdo, $table)) {
                continue;
            }
            $sql = 'DELETE FROM ' . $table . ' WHERE tenant_id = :tenant_id AND conversation_id = :conversation_id';
            $pdo->prepare($sql)->execute(['tenant_id' => $tenantId, 'conversation_id' => $conversationId]);
        }

        if (isset($options['purge-messages'])) {
            foreach (['conversation_events', 'conversation_service_cycles'] as $table) {
                if ($tableExists($pdo, $table)) {
                    $pdo->prepare('DELETE FROM ' . $table . ' WHERE tenant_id = :tenant_id AND conversation_id = :conversation_id')
                        ->execute(['tenant_id' => $tenantId, 'conversation_id' => $conversationId]);
                }
            }
            if ($tableExists($pdo, 'conversation_messages')) {
                $pdo->prepare('DELETE FROM conversation_messages WHERE tenant_id = :tenant_id AND conversation_id = :conversation_id')
                    ->execute(['tenant_id' => $tenantId, 'conversation_id' => $conversationId]);
            }
        }

        $sets = [
            'status = "open"',
            'attendance_mode = "ai"',
            'assigned_user_id = NULL',
            'unread_count = 0',
        ];
        if (isset($options['purge-messages'])) {
            $sets[] = 'last_message_at = NULL';
            $sets[] = 'last_message_preview = NULL';
        }
        $params = ['tenant_id' => $tenantId, 'conversation_id' => $conversationId];
        if ($agent !== null && $columnExists($pdo, 'conversations', 'ai_agent_id')) {
            $sets[] = 'ai_agent_id = :agent_id';
            $params['agent_id'] = (int) $agent['id'];
        }
        if ($columnExists($pdo, 'conversations', 'operational_status')) {
            $sets[] = 'operational_status = "new"';
        }
        $pdo->prepare('UPDATE conversations SET ' . implode(', ', $sets) . ' WHERE tenant_id = :tenant_id AND id = :conversation_id')
            ->execute($params);
    }

    if (isset($options['purge-calendar']) && $tableExists($pdo, 'calendar_appointments')) {
        $pdo->prepare('DELETE FROM calendar_appointments WHERE tenant_id = :tenant_id AND contact_id = :contact_id')
            ->execute(['tenant_id' => $tenantId, 'contact_id' => $contactId]);
    }

    $pdo->commit();

    if ($conversationIds !== [] && isset($options['purge-messages']) && $tableExists($pdo, 'conversation_service_cycles')) {
        $cycles = new ConversationCycleService();
        foreach ($conversationIds as $conversationId) {
            try {
                $cycles->ensureActiveCycle($pdo, $conversationId, $tenantId, 'test_contact_reset');
            } catch (Throwable) {
            }
        }
    }

    echo "\nReset aplicado com sucesso.\n";
    echo isset($options['purge-messages']) ? "Histórico de mensagens removido.\n" : "Mensagens foram preservadas; apenas estado/memória foram limpos.\n";
    echo isset($options['purge-calendar']) ? "Compromissos do contato também foram removidos.\n" : "Agenda foi preservada.\n";
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "Falha ao aplicar reset: {$exception->getMessage()}\n");
    exit(10);
}
