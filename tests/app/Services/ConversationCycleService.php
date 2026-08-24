<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use Throwable;

/**
 * Sincroniza o ciclo operacional da conversa com o status persistido.
 *
 * Os triggers continuam cobrindo webhook, n8n e rotinas externas. Este serviço
 * adiciona uma segunda camada para as ações do painel, evitando que relatórios
 * fiquem inconsistentes caso um trigger legado ou uma janela de implantação
 * deixe o ciclo sem fechamento/reabertura.
 */
final class ConversationCycleService
{
    private static ?bool $cycleTableAvailable = null;

    public function ensureActiveCycle(
        PDO $pdo,
        int $conversationId,
        int $tenantId,
        string $source = 'application_status_sync'
    ): void {
        if ($conversationId < 1 || $tenantId < 1 || !$this->cycleTableExists($pdo)) {
            return;
        }

        $exists = $pdo->prepare(
            'SELECT id
             FROM conversation_service_cycles
             WHERE conversation_id = :conversation_id
               AND tenant_id = :tenant_id
               AND cycle_status = "active"
             ORDER BY cycle_number DESC
             LIMIT 1'
        );
        $exists->execute([
            'conversation_id' => $conversationId,
            'tenant_id' => $tenantId,
        ]);
        if ($exists->fetchColumn() !== false) {
            return;
        }

        $next = $pdo->prepare(
            'SELECT COALESCE(MAX(cycle_number), 0) + 1
             FROM conversation_service_cycles
             WHERE conversation_id = :conversation_id'
        );
        $next->execute(['conversation_id' => $conversationId]);
        $cycleNumber = max(1, (int) $next->fetchColumn());

        $insert = $pdo->prepare(
            'INSERT IGNORE INTO conversation_service_cycles
                (tenant_id, conversation_id, cycle_number, opened_at,
                 first_incoming_at, last_incoming_at, first_response_at,
                 first_response_user_id, cycle_status, source)
             SELECT
                c.tenant_id,
                c.id,
                :cycle_number,
                COALESCE(c.opened_at, c.created_at, CURRENT_TIMESTAMP),
                c.first_incoming_at,
                c.last_incoming_at,
                c.first_response_at,
                c.first_response_user_id,
                "active",
                :source
             FROM conversations c
             WHERE c.id = :conversation_id
               AND c.tenant_id = :tenant_id'
        );
        $insert->execute([
            'cycle_number' => $cycleNumber,
            'source' => $source,
            'conversation_id' => $conversationId,
            'tenant_id' => $tenantId,
        ]);
    }

    public function closeActiveCycle(
        PDO $pdo,
        int $conversationId,
        int $tenantId,
        ?int $actorUserId
    ): void {
        if ($conversationId < 1 || $tenantId < 1 || !$this->cycleTableExists($pdo)) {
            return;
        }

        $statement = $pdo->prepare(
            'UPDATE conversation_service_cycles sc
             INNER JOIN conversations c
                     ON c.id = sc.conversation_id
                    AND c.tenant_id = sc.tenant_id
             SET sc.first_incoming_at = COALESCE(sc.first_incoming_at, c.first_incoming_at),
                 sc.last_incoming_at = COALESCE(c.last_incoming_at, sc.last_incoming_at),
                 sc.first_response_at = COALESCE(sc.first_response_at, c.first_response_at),
                 sc.first_response_user_id = COALESCE(sc.first_response_user_id, c.first_response_user_id),
                 sc.closed_at = COALESCE(c.closed_at, CURRENT_TIMESTAMP),
                 sc.closed_by_user_id = COALESCE(:actor_user_id, c.status_changed_by_user_id, c.assignment_updated_by_user_id, c.assigned_user_id),
                 sc.cycle_status = "closed"
             WHERE sc.conversation_id = :conversation_id
               AND sc.tenant_id = :tenant_id
               AND sc.cycle_status = "active"'
        );
        $statement->execute([
            'actor_user_id' => ($actorUserId ?? 0) > 0 ? $actorUserId : null,
            'conversation_id' => $conversationId,
            'tenant_id' => $tenantId,
        ]);
        if ($statement->rowCount() > 0) {
            return;
        }

        // Se o trigger já fechou o ciclo na mesma atualização, não cria outro.
        $latest = $pdo->prepare(
            'SELECT cycle_status
             FROM conversation_service_cycles
             WHERE conversation_id = :conversation_id
               AND tenant_id = :tenant_id
             ORDER BY cycle_number DESC
             LIMIT 1'
        );
        $latest->execute([
            'conversation_id' => $conversationId,
            'tenant_id' => $tenantId,
        ]);
        $latestStatus = $latest->fetchColumn();
        if ($latestStatus !== false) {
            return;
        }

        // Conversa completamente legada: cria um único snapshot já encerrado.
        $insert = $pdo->prepare(
            'INSERT INTO conversation_service_cycles
                (tenant_id, conversation_id, cycle_number, opened_at,
                 first_incoming_at, last_incoming_at, first_response_at,
                 first_response_user_id, closed_at, closed_by_user_id,
                 cycle_status, source)
             SELECT
                c.tenant_id,
                c.id,
                1,
                COALESCE(c.opened_at, c.created_at, CURRENT_TIMESTAMP),
                c.first_incoming_at,
                c.last_incoming_at,
                c.first_response_at,
                c.first_response_user_id,
                COALESCE(c.closed_at, CURRENT_TIMESTAMP),
                COALESCE(:actor_user_id, c.status_changed_by_user_id, c.assignment_updated_by_user_id, c.assigned_user_id),
                "closed",
                "application_close_recovery"
             FROM conversations c
             WHERE c.id = :conversation_id
               AND c.tenant_id = :tenant_id'
        );
        $insert->execute([
            'actor_user_id' => ($actorUserId ?? 0) > 0 ? $actorUserId : null,
            'conversation_id' => $conversationId,
            'tenant_id' => $tenantId,
        ]);
    }

    private function cycleTableExists(PDO $pdo): bool
    {
        if (self::$cycleTableAvailable !== null) {
            return self::$cycleTableAvailable;
        }

        try {
            $statement = $pdo->query(
                'SELECT COUNT(*)
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = "conversation_service_cycles"'
            );
            self::$cycleTableAvailable = (int) $statement->fetchColumn() > 0;
        } catch (Throwable) {
            self::$cycleTableAvailable = false;
        }

        return self::$cycleTableAvailable;
    }
}
