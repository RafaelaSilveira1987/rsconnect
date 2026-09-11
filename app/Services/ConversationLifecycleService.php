<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use Throwable;

/**
 * Mantém o limite entre ciclos de atendimento sem apagar o histórico permanente.
 *
 * Um novo ciclo pode reutilizar a mesma linha de conversations, mas não deve
 * herdar estado transitório de triagem, demanda ou recuperação pós-horário do
 * ciclo anterior.
 */
final class ConversationLifecycleService
{
    public function resetTransientStateForNewCycle(
        PDO $pdo,
        int $tenantId,
        int $conversationId,
        string $source = 'conversation_reopened'
    ): void {
        if ($tenantId < 1 || $conversationId < 1) {
            return;
        }

        foreach (['conversation_flow_states', 'conversation_triage_sessions'] as $table) {
            if (!$this->tableExists($pdo, $table)) {
                continue;
            }
            try {
                $statement = $pdo->prepare(
                    sprintf('DELETE FROM %s WHERE tenant_id = :tenant_id AND conversation_id = :conversation_id', $table)
                );
                $statement->execute([
                    'tenant_id' => $tenantId,
                    'conversation_id' => $conversationId,
                ]);
            } catch (Throwable) {
                // Compatibilidade com instalações antigas: o novo ciclo não deve
                // falhar apenas porque uma tabela opcional ainda não existe.
            }
        }

        if ($this->tableExists($pdo, 'ai_after_hours_pending')) {
            try {
                $statement = $pdo->prepare(
                    'UPDATE ai_after_hours_pending
                     SET status = "cancelled",
                         next_attempt_at = NULL,
                         recovery_source = :source,
                         last_error = NULL
                     WHERE tenant_id = :tenant_id
                       AND conversation_id = :conversation_id
                       AND status IN ("pending", "processing", "blocked_plan", "blocked_human", "error")'
                );
                $statement->execute([
                    'source' => mb_substr($source, 0, 80),
                    'tenant_id' => $tenantId,
                    'conversation_id' => $conversationId,
                ]);
            } catch (Throwable) {
            }
        }
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        try {
            $statement = $pdo->prepare(
                'SELECT 1 FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = :table LIMIT 1'
            );
            $statement->execute(['table' => $table]);
            return (bool) $statement->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }
}
