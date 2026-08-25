<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Regras opcionais de atendimento exclusivo por profissional.
 *
 * A atribuição automática é deliberadamente independente da ativação geral:
 * - professional_assignment_enabled: habilita vínculo/bloqueio;
 * - professional_lock_enabled: impede outro usuário de interferir na conversa aberta;
 * - professional_auto_assign_enabled: usa o profissional preferido ao receber nova mensagem.
 */
final class ConversationOwnershipService
{
    private static array $columnCache = [];

    public function settingsForTenant(PDO $pdo, int $tenantId): array
    {
        $defaults = [
            'enabled' => false,
            'lock_enabled' => true,
            'auto_assign_enabled' => false,
        ];
        if ($tenantId < 1 || !$this->hasColumn($pdo, 'tenants', 'professional_assignment_enabled')) {
            return $defaults;
        }

        try {
            $statement = $pdo->prepare(
                'SELECT professional_assignment_enabled,
                        professional_lock_enabled,
                        professional_auto_assign_enabled
                 FROM tenants
                 WHERE id = :id
                 LIMIT 1'
            );
            $statement->execute(['id' => $tenantId]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return $defaults;
            }

            return [
                'enabled' => (int) ($row['professional_assignment_enabled'] ?? 0) === 1,
                'lock_enabled' => (int) ($row['professional_lock_enabled'] ?? 1) === 1,
                'auto_assign_enabled' => (int) ($row['professional_auto_assign_enabled'] ?? 0) === 1,
            ];
        } catch (Throwable) {
            return $defaults;
        }
    }

    public function teamForTenant(PDO $pdo, int $tenantId): array
    {
        if ($tenantId < 1) {
            return [];
        }
        $statement = $pdo->prepare(
            'SELECT id, name, role, whatsapp_display_name, whatsapp_role_label
             FROM users
             WHERE tenant_id = :tenant_id AND status = "active"
             ORDER BY name'
        );
        $statement->execute(['tenant_id' => $tenantId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function snapshot(PDO $pdo, array $conversation): array
    {
        $tenantId = (int) ($conversation['tenant_id'] ?? 0);
        $settings = $this->settingsForTenant($pdo, $tenantId);
        $assignedUserId = (int) ($conversation['assigned_user_id'] ?? 0);
        $actorId = (int) (Auth::id() ?? 0);
        $actorBelongsToTenant = $actorId > 0 && $this->activeUserBelongsToTenant($pdo, $actorId, $tenantId);
        $manager = $this->isManagerForTenant($actorBelongsToTenant);
        $open = (string) ($conversation['status'] ?? '') !== 'closed';
        $lockedByOther = $settings['enabled']
            && $settings['lock_enabled']
            && $open
            && $assignedUserId > 0
            && $assignedUserId !== $actorId
            && !Auth::isSuperAdmin();

        return $settings + [
            'assigned_user_id' => $assignedUserId,
            'assigned_user_name' => (string) ($conversation['assigned_user_name'] ?? ''),
            'is_open' => $open,
            'locked_by_other' => $lockedByOther,
            'can_interact' => !$lockedByOther,
            'can_claim' => $settings['enabled'] && $open && $assignedUserId < 1 && $actorBelongsToTenant,
            'can_assign' => $settings['enabled'] && $open && $assignedUserId < 1 && $manager,
            'can_release' => $settings['enabled'] && $open && $assignedUserId > 0
                && ($assignedUserId === $actorId || $manager),
            'can_transfer' => $settings['enabled'] && $open && $assignedUserId > 0
                && ($assignedUserId === $actorId || $manager),
        ];
    }

    public function assertMayInteract(PDO $pdo, array $conversation): void
    {
        $snapshot = $this->snapshot($pdo, $conversation);
        if (!empty($snapshot['locked_by_other'])) {
            $name = trim((string) ($snapshot['assigned_user_name'] ?? '')) ?: 'outro profissional';
            throw new RuntimeException('Esta conversa está sendo atendida por ' . $name . '. Para continuar, solicite a transferência do atendimento.');
        }
    }

    /**
     * Garante uma atribuição segura antes de uma ação humana.
     * O primeiro usuário que interagir em uma conversa sem responsável assume o atendimento.
     */
    public function claimForHumanAction(PDO $pdo, array $conversation): array
    {
        $tenantId = (int) ($conversation['tenant_id'] ?? 0);
        $conversationId = (int) ($conversation['id'] ?? 0);
        $actorId = (int) (Auth::id() ?? 0);
        $settings = $this->settingsForTenant($pdo, $tenantId);

        if (!$settings['enabled'] || $conversationId < 1 || $actorId < 1) {
            return $conversation;
        }
        // Super Admin global pode prestar suporte sem se tornar profissional da empresa.
        if (!$this->activeUserBelongsToTenant($pdo, $actorId, $tenantId)) {
            if (Auth::isSuperAdmin()) {
                return $conversation;
            }
            throw new RuntimeException('Seu usuário não pertence à equipe desta empresa.');
        }

        $started = !$pdo->inTransaction();
        if ($started) {
            $pdo->beginTransaction();
        }

        try {
            $statement = $pdo->prepare(
                'SELECT c.id, c.tenant_id, c.status, c.assigned_user_id, u.name AS assigned_user_name
                 FROM conversations c
                 LEFT JOIN users u ON u.id = c.assigned_user_id AND u.tenant_id = c.tenant_id
                 WHERE c.id = :id AND c.tenant_id = :tenant_id
                 FOR UPDATE'
            );
            $statement->execute(['id' => $conversationId, 'tenant_id' => $tenantId]);
            $current = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$current) {
                throw new RuntimeException('Conversa não encontrada para atribuição.');
            }

            $assignedUserId = (int) ($current['assigned_user_id'] ?? 0);
            if ($settings['lock_enabled']
                && (string) ($current['status'] ?? '') !== 'closed'
                && $assignedUserId > 0
                && $assignedUserId !== $actorId
                && !Auth::isSuperAdmin()) {
                $name = trim((string) ($current['assigned_user_name'] ?? '')) ?: 'outro profissional';
                throw new RuntimeException('Esta conversa está sendo atendida por ' . $name . '. Para continuar, solicite a transferência do atendimento.');
            }

            if ((string) ($current['status'] ?? '') !== 'closed' && $assignedUserId < 1) {
                $this->updateAssignment($pdo, $conversationId, $tenantId, $actorId, 'claim', $actorId);
                $conversation['assigned_user_id'] = $actorId;
                $conversation['assigned_user_name'] = (string) (Auth::user()['name'] ?? '');
            }

            $conversation['after_hours_resolved'] = (new AiAfterHoursRecoveryService())->resolveForHumanTakeover(
                $pdo,
                $tenantId,
                $conversationId,
                $actorId,
                'ownership_claim'
            );

            if ($started) {
                $pdo->commit();
            }
            return $conversation;
        } catch (Throwable $exception) {
            if ($started && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function changeAssignment(PDO $pdo, int $conversationId, ?int $targetUserId, string $action): array
    {
        $actorId = (int) (Auth::id() ?? 0);
        if ($conversationId < 1 || $actorId < 1) {
            throw new RuntimeException('Conversa ou usuário inválido.');
        }
        if (!in_array($action, ['claim', 'assign', 'transfer', 'release'], true)) {
            throw new RuntimeException('Ação de atribuição inválida.');
        }

        $started = !$pdo->inTransaction();
        if ($started) {
            $pdo->beginTransaction();
        }

        try {
            $statement = $pdo->prepare(
                'SELECT c.id, c.tenant_id, c.status, c.assigned_user_id,
                        u.name AS assigned_user_name
                 FROM conversations c
                 LEFT JOIN users u ON u.id = c.assigned_user_id AND u.tenant_id = c.tenant_id
                 WHERE c.id = :id
                 FOR UPDATE'
            );
            $statement->execute(['id' => $conversationId]);
            $conversation = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$conversation) {
                throw new RuntimeException('Conversa não encontrada.');
            }

            $tenantId = (int) $conversation['tenant_id'];
            $settings = $this->settingsForTenant($pdo, $tenantId);
            if (!$settings['enabled']) {
                throw new RuntimeException('O atendimento por profissional não está ativado para esta empresa.');
            }
            if ((string) $conversation['status'] === 'closed') {
                throw new RuntimeException('A conversa encerrada já está liberada. Reabra-a antes de definir um responsável.');
            }

            $currentUserId = (int) ($conversation['assigned_user_id'] ?? 0);
            $actorBelongsToTenant = $this->activeUserBelongsToTenant($pdo, $actorId, $tenantId);
            $manager = $this->isManagerForTenant($actorBelongsToTenant);
            if (!Auth::isSuperAdmin() && !$actorBelongsToTenant) {
                throw new RuntimeException('Seu usuário não pertence à equipe desta empresa.');
            }

            if ($action === 'claim') {
                if (!$this->activeUserBelongsToTenant($pdo, $actorId, $tenantId)) {
                    throw new RuntimeException('Escolha um profissional da empresa para assumir esta conversa.');
                }
                $targetUserId = $actorId;
                if ($currentUserId > 0 && $currentUserId !== $actorId) {
                    $name = trim((string) ($conversation['assigned_user_name'] ?? '')) ?: 'outro profissional';
                    throw new RuntimeException('Esta conversa já está sendo atendida por ' . $name . '.');
                }
            } elseif ($action === 'release') {
                if ($currentUserId < 1) {
                    throw new RuntimeException('A conversa já está sem responsável.');
                }
                if (!$manager && $currentUserId !== $actorId) {
                    throw new RuntimeException('Somente o responsável atual ou um administrador pode liberar esta conversa.');
                }
                $targetUserId = null;
            } else {
                if (!$manager && $currentUserId !== $actorId) {
                    throw new RuntimeException('Somente o responsável atual ou um administrador pode transferir esta conversa.');
                }
                if (($targetUserId ?? 0) < 1) {
                    throw new RuntimeException('Escolha o novo responsável.');
                }
            }

            if (($targetUserId ?? 0) > 0 && !$this->activeUserBelongsToTenant($pdo, (int) $targetUserId, $tenantId)) {
                throw new RuntimeException('O profissional selecionado não pertence à empresa ou está inativo.');
            }

            $source = match ($action) {
                'claim' => 'claim',
                'transfer' => 'transfer',
                'assign' => 'manual',
                default => 'released',
            };
            $this->updateAssignment($pdo, $conversationId, $tenantId, $targetUserId, $source, $actorId);

            $afterHoursResolved = 0;
            if ($action !== 'release') {
                $afterHoursResolved = (new AiAfterHoursRecoveryService())->resolveForHumanTakeover(
                    $pdo,
                    $tenantId,
                    $conversationId,
                    $actorId,
                    'ownership_' . $action
                );
            }

            $name = '';
            if (($targetUserId ?? 0) > 0) {
                $nameStatement = $pdo->prepare('SELECT name FROM users WHERE id = :id LIMIT 1');
                $nameStatement->execute(['id' => $targetUserId]);
                $name = (string) ($nameStatement->fetchColumn() ?: '');
            }

            if ($started) {
                $pdo->commit();
            }

            return [
                'tenant_id' => $tenantId,
                'conversation_id' => $conversationId,
                'previous_user_id' => $currentUserId,
                'assigned_user_id' => $targetUserId,
                'assigned_user_name' => $name,
                'action' => $action,
                'after_hours_resolved' => $afterHoursResolved,
            ];
        } catch (Throwable $exception) {
            if ($started && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function autoAssignPreferred(PDO $pdo, int $tenantId, int $conversationId, int $contactId): ?int
    {
        $settings = $this->settingsForTenant($pdo, $tenantId);
        if (!$settings['enabled'] || !$settings['auto_assign_enabled']
            || !$this->hasColumn($pdo, 'contacts', 'preferred_user_id')) {
            return null;
        }

        $preferred = $pdo->prepare(
            'SELECT u.id
             FROM contacts ct
             INNER JOIN users u ON u.id = ct.preferred_user_id
                AND u.tenant_id = ct.tenant_id
                AND u.status = "active"
             WHERE ct.id = :contact_id AND ct.tenant_id = :tenant_id
             LIMIT 1'
        );
        $preferred->execute(['contact_id' => $contactId, 'tenant_id' => $tenantId]);
        $preferredUserId = (int) ($preferred->fetchColumn() ?: 0);
        if ($preferredUserId < 1) {
            return null;
        }

        $started = !$pdo->inTransaction();
        if ($started) {
            $pdo->beginTransaction();
        }

        try {
            $lock = $pdo->prepare(
                'SELECT status, assigned_user_id
                 FROM conversations
                 WHERE id = :id AND tenant_id = :tenant_id
                 FOR UPDATE'
            );
            $lock->execute(['id' => $conversationId, 'tenant_id' => $tenantId]);
            $conversation = $lock->fetch(PDO::FETCH_ASSOC);
            if (!$conversation || (string) $conversation['status'] !== 'open' || (int) ($conversation['assigned_user_id'] ?? 0) > 0) {
                if ($started) {
                    $pdo->commit();
                }
                return null;
            }

            $this->updateAssignment($pdo, $conversationId, $tenantId, $preferredUserId, 'preferred_auto', null);
            if ($started) {
                $pdo->commit();
            }
            return $preferredUserId;
        } catch (Throwable $exception) {
            if ($started && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * Reabre uma conversa encerrada como disponível, sem reaproveitar um responsável antigo.
     */
    public function reopenIfClosed(PDO $pdo, array $conversation): array
    {
        $tenantId = (int) ($conversation['tenant_id'] ?? 0);
        $conversationId = (int) ($conversation['id'] ?? 0);
        $settings = $this->settingsForTenant($pdo, $tenantId);
        if (!$settings['enabled'] || $conversationId < 1 || (string) ($conversation['status'] ?? '') !== 'closed') {
            return $conversation;
        }

        $pdo->prepare(
            'UPDATE conversations
             SET status = "open",
                 status_changed_by_user_id = :status_actor_id,
                 assigned_user_id = NULL,
                 assigned_at = NULL,
                 assignment_source = "released",
                 assignment_updated_by_user_id = :actor_id,
                 assignment_released_at = CURRENT_TIMESTAMP,
                 operational_status = "waiting_agent"
             WHERE id = :id AND tenant_id = :tenant_id'
        )->execute([
            'status_actor_id' => Auth::id(),
            'actor_id' => Auth::id(),
            'id' => $conversationId,
            'tenant_id' => $tenantId,
        ]);

        (new ConversationCycleService())->ensureActiveCycle(
            $pdo,
            $conversationId,
            $tenantId,
            'application_conversation_reopened'
        );

        $conversation['status'] = 'open';
        $conversation['assigned_user_id'] = null;
        $conversation['assigned_user_name'] = null;
        $conversation['assignment_source'] = 'released';
        return $conversation;
    }

    public function releaseWhenClosed(PDO $pdo, int $conversationId, int $tenantId): void
    {
        $settings = $this->settingsForTenant($pdo, $tenantId);
        if (!$settings['enabled']) {
            return;
        }

        $pdo->prepare(
            'UPDATE conversations
             SET assigned_user_id = NULL,
                 assigned_at = NULL,
                 assignment_source = "released",
                 assignment_updated_by_user_id = :actor_id,
                 assignment_released_at = CURRENT_TIMESTAMP,
                 operational_status = "resolved"
             WHERE id = :id AND tenant_id = :tenant_id'
        )->execute([
            'actor_id' => Auth::id(),
            'id' => $conversationId,
            'tenant_id' => $tenantId,
        ]);
    }

    private function updateAssignment(
        PDO $pdo,
        int $conversationId,
        int $tenantId,
        ?int $targetUserId,
        string $source,
        ?int $actorId
    ): void {
        if (!$this->hasColumn($pdo, 'conversations', 'assignment_source')) {
            $pdo->prepare(
                'UPDATE conversations
                 SET assigned_user_id = :assigned_user_id,
                     assigned_at = :assigned_at,
                     attendance_mode = :attendance_mode,
                     operational_status = :operational_status
                 WHERE id = :id AND tenant_id = :tenant_id'
            )->execute([
                'assigned_user_id' => $targetUserId,
                'assigned_at' => $targetUserId !== null ? \App\Core\Clock::nowUtc() : null,
                'attendance_mode' => $targetUserId !== null ? 'human' : 'paused',
                'operational_status' => $targetUserId !== null ? 'in_service' : 'waiting_agent',
                'id' => $conversationId,
                'tenant_id' => $tenantId,
            ]);
            return;
        }

        $pdo->prepare(
            'UPDATE conversations
             SET assigned_user_id = :assigned_user_id,
                 assigned_at = :assigned_at,
                 attendance_mode = :attendance_mode,
                 operational_status = :operational_status,
                 assignment_source = :assignment_source,
                 assignment_updated_by_user_id = :assignment_updated_by_user_id,
                 assignment_released_at = :assignment_released_at
             WHERE id = :id AND tenant_id = :tenant_id'
        )->execute([
            'assigned_user_id' => $targetUserId,
            'assigned_at' => $targetUserId !== null ? \App\Core\Clock::nowUtc() : null,
            'attendance_mode' => $targetUserId !== null ? 'human' : 'paused',
            'operational_status' => $targetUserId !== null ? 'in_service' : 'waiting_agent',
            'assignment_source' => $source,
            'assignment_updated_by_user_id' => $actorId,
            'assignment_released_at' => $targetUserId === null ? \App\Core\Clock::nowUtc() : null,
            'id' => $conversationId,
            'tenant_id' => $tenantId,
        ]);
    }

    private function activeUserBelongsToTenant(PDO $pdo, int $userId, int $tenantId): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM users
             WHERE id = :id AND tenant_id = :tenant_id AND status = "active"'
        );
        $statement->execute(['id' => $userId, 'tenant_id' => $tenantId]);
        return (int) $statement->fetchColumn() > 0;
    }

    private function isManagerForTenant(bool $actorBelongsToTenant): bool
    {
        return Auth::isSuperAdmin() || (Auth::role() === 'client_admin' && $actorBelongsToTenant);
    }

    private function hasColumn(PDO $pdo, string $table, string $column): bool
    {
        $key = $table . ':' . $column;
        if (array_key_exists($key, self::$columnCache)) {
            return self::$columnCache[$key];
        }
        try {
            $statement = $pdo->prepare(
                'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = :table_name
                   AND COLUMN_NAME = :column_name'
            );
            $statement->execute(['table_name' => $table, 'column_name' => $column]);
            self::$columnCache[$key] = (int) $statement->fetchColumn() > 0;
        } catch (Throwable) {
            self::$columnCache[$key] = false;
        }
        return self::$columnCache[$key];
    }
}
