<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Database;
use PDO;
use Throwable;

/** Reconciliação EXPLÍCITA, sem alterar compromissos nem oferecer vagas por suposição. */
final class PublishedSlotReconciliationService
{
    public function __construct(private readonly PublishedSlotReconciliationPolicy $policy = new PublishedSlotReconciliationPolicy())
    {
    }

    /**
     * @return array{allowed:bool,reason:string,ids:list<int>,appointments:list<array<string,mixed>>}
     */
    public function preview(int $tenantId, int $slotId): array
    {
        $pdo = Database::connection();
        try {
            return $this->inspect($pdo, $tenantId, $slotId, false);
        } catch (Throwable) {
            return ['allowed' => false, 'reason' => 'Não foi possível verificar os vínculos. Nenhuma vaga será liberada.', 'ids' => [], 'appointments' => []];
        }
    }

    /**
     * @param list<int> $expectedIds Identificadores vistos e confirmados pela equipe.
     * @return array{ok:bool,message:string,linked:int}
     */
    public function reconcile(int $tenantId, int $slotId, array $expectedIds): array
    {
        $expectedIds = array_values(array_unique(array_filter(array_map('intval', $expectedIds), static fn (int $id): bool => $id > 0)));
        if ($tenantId < 1 || $slotId < 1 || $expectedIds === []) {
            return ['ok' => false, 'message' => 'Selecione um horário com compromissos para conciliar.', 'linked' => 0];
        }
        sort($expectedIds, SORT_NUMERIC);
        $pdo = Database::connection();
        if ($pdo->inTransaction()) {
            return ['ok' => false, 'message' => 'Operação indisponível durante outra transação.', 'linked' => 0];
        }
        try {
            // Obtenção preliminar FORA da transação: não cria snapshot antigo
            // de REPEATABLE READ antes dos bloqueios de escrita.
            $timeLookup = $pdo->prepare('SELECT starts_at, ends_at, owner_user_id FROM calendar_internal_slots WHERE tenant_id = :tenant_id AND id = :id');
            $timeLookup->execute(['tenant_id' => $tenantId, 'id' => $slotId]);
            $time = $timeLookup->fetch(PDO::FETCH_ASSOC);
            if (!$time) {
                throw new \RuntimeException('Horário removido. Atualize a página.');
            }
            $pdo->beginTransaction();
            // Ordem uniforme do RS Connect: compromissos -> horário publicado.
            $appointmentLocks = $pdo->prepare('SELECT id FROM calendar_appointments WHERE tenant_id = :tenant_id AND id = :id FOR UPDATE');
            foreach ($expectedIds as $id) {
                $appointmentLocks->execute(['tenant_id' => $tenantId, 'id' => $id]);
                if ((int) $appointmentLocks->fetchColumn() !== $id) {
                    throw new \RuntimeException('Compromisso alterado ou inexistente. Atualize a tela.');
                }
            }
            // Bloqueia também os demais compromissos sobrepostos antes do slot,
            // em ordem de identificador; não presume que a prévia continua válida.
            $allOverlapping = $pdo->prepare('SELECT id FROM calendar_appointments WHERE tenant_id = :tenant_id
                 AND status IN ("scheduled", "confirmed") AND starts_at < :ends_at AND ends_at > :starts_at
                 AND (owner_user_id = :owner_user_id OR owner_user_id IS NULL)
                 ORDER BY id FOR UPDATE');
            $allOverlapping->execute(['tenant_id' => $tenantId, 'ends_at' => $time['ends_at'],
                'starts_at' => $time['starts_at'], 'owner_user_id' => (int) ($time['owner_user_id'] ?? 0)]);
            $allOverlapping->fetchAll(PDO::FETCH_COLUMN);
            $slotLock = $pdo->prepare('SELECT starts_at, ends_at, owner_user_id FROM calendar_internal_slots WHERE tenant_id = :tenant_id AND id = :id FOR UPDATE');
            $slotLock->execute(['tenant_id' => $tenantId, 'id' => $slotId]);
            $lockedSlot = $slotLock->fetch(PDO::FETCH_ASSOC);
            if (!$lockedSlot || $lockedSlot != $time) {
                throw new \RuntimeException('O horário mudou durante a conferência. Atualize a página.');
            }
            $inspection = $this->inspect($pdo, $tenantId, $slotId, true);
            $currentIds = $inspection['ids'];
            sort($currentIds, SORT_NUMERIC);
            if (!$inspection['allowed'] || $currentIds !== $expectedIds) {
                throw new \RuntimeException('A agenda mudou desde a conferência. Atualize a tela antes de conciliar. ' . $inspection['reason']);
            }
            $statement = $pdo->prepare(
                'INSERT INTO calendar_internal_slot_allocations
                    (tenant_id, slot_id, appointment_id, state, expires_at)
                 VALUES (:tenant_id, :slot_id, :appointment_id, "booked", NULL)'
            );
            foreach ($expectedIds as $id) {
                $statement->execute(['tenant_id' => $tenantId, 'slot_id' => $slotId, 'appointment_id' => $id]);
            }
            // O status é apenas legado; booked significa capacidade totalmente ocupada.
            $counts = $pdo->prepare('SELECT COUNT(*) FROM calendar_internal_slot_allocations WHERE tenant_id = :tenant_id AND slot_id = :slot_id AND state IN ("booked", "held") AND (state <> "held" OR expires_at IS NULL OR expires_at >= NOW())');
            $counts->execute(['tenant_id' => $tenantId, 'slot_id' => $slotId]);
            $occupied = (int) $counts->fetchColumn();
            $capacity = $pdo->prepare('SELECT capacity_total FROM calendar_internal_slots WHERE tenant_id = :tenant_id AND id = :slot_id');
            $capacity->execute(['tenant_id' => $tenantId, 'slot_id' => $slotId]);
            $total = max(1, (int) $capacity->fetchColumn());
            $activeHolds = $pdo->prepare('SELECT COUNT(*) FROM calendar_internal_slot_allocations WHERE tenant_id = :tenant_id AND slot_id = :slot_id AND state = "held" AND (expires_at IS NULL OR expires_at >= NOW())');
            $activeHolds->execute(['tenant_id' => $tenantId, 'slot_id' => $slotId]);
            $status = $occupied < $total ? 'available' : ((int) $activeHolds->fetchColumn() > 0 ? 'held' : 'booked');
            // Mesmo contrato do sincronizador: a referência legada só vale na capacidade 1.
            $legacyId = $total === 1 && $occupied > 0 ? $expectedIds[0] : null;
            $pdo->prepare('UPDATE calendar_internal_slots SET status = :status, hold_appointment_id = :legacy_id,
                hold_expires_at = NULL, updated_at = CURRENT_TIMESTAMP WHERE tenant_id = :tenant_id AND id = :slot_id')
                ->execute(['status' => $status, 'legacy_id' => $legacyId, 'tenant_id' => $tenantId, 'slot_id' => $slotId]);
            // Comprova que não restou compromisso incompatível (falha fechada).
            $conflicts = (new PublishedSlotAppointmentConflictService())->counts($tenantId, [$slotId], true, $pdo);
            if ($conflicts === null || (int) ($conflicts[$slotId] ?? 0) > 0) {
                throw new \RuntimeException('Ainda existe conflito não conciliado; nenhuma alteração foi aplicada.');
            }
            $pdo->commit();
            try {
                Audit::log('calendar.published_slot_reconciled', ['slot_id' => $slotId, 'appointment_ids' => $expectedIds, 'linked' => count($expectedIds)], $tenantId);
            } catch (Throwable) {
                error_log('[RS Connect] Reconciliação aplicada, mas registro de auditoria falhou: tenant=' . $tenantId . ' slot=' . $slotId);
            }
            return ['ok' => true, 'message' => count($expectedIds) . ' compromisso(s) vinculado(s). Confira as vagas restantes antes de oferecer ao cliente.', 'linked' => count($expectedIds)];
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'message' => $exception instanceof \RuntimeException ? $exception->getMessage() : 'Não foi possível conciliar com segurança. Nenhuma alteração foi confirmada.', 'linked' => 0];
        }
    }

    /** @return array{allowed:bool,reason:string,ids:list<int>,appointments:list<array<string,mixed>>} */
    private function inspect(PDO $pdo, int $tenantId, int $slotId, bool $locking): array
    {
        $fail = static fn (string $reason): array => ['allowed' => false, 'reason' => $reason, 'ids' => [], 'appointments' => []];
        if ($tenantId < 1 || $slotId < 1) {
            return $fail('Empresa ou horário inválido.');
        }
        $query = $pdo->prepare('SELECT * FROM calendar_internal_slots WHERE tenant_id = :tenant_id AND id = :id LIMIT 1');
        $query->execute(['tenant_id' => $tenantId, 'id' => $slotId]);
        $slot = $query->fetch(PDO::FETCH_ASSOC);
        if (!$slot) {
            return $fail('Horário não encontrado nesta empresa.');
        }
        if ((string) ($slot['status'] ?? '') === 'held' && !empty($slot['hold_appointment_id'])) {
            return $fail('Este horário possui pré-reserva legada. Confira a reserva antes de conciliar.');
        }
        // Uma grade pode ter início a cada 30 min com duração de 50 min.
        // Sobreposição vizinha NÃO constitui outro candidato ao vínculo:
        // exige-se início e término idênticos, profissional inequívoco.
        $similar = $pdo->prepare('SELECT COUNT(*) FROM calendar_internal_slots WHERE tenant_id = :tenant_id AND id <> :id AND status IN ("available", "held", "booked") AND starts_at = :starts_at AND ends_at = :ends_at AND (owner_user_id = :owner_user_id OR owner_user_id IS NULL)');
        $similar->execute(['tenant_id' => $tenantId, 'id' => $slotId, 'ends_at' => $slot['ends_at'], 'starts_at' => $slot['starts_at'], 'owner_user_id' => (int) ($slot['owner_user_id'] ?? 0)]);
        $matchingSlots = (int) $similar->fetchColumn() + 1;
        // A leitura com FOR UPDATE no intervalo impede alterações de compromissos
        // existentes enquanto esta reconciliação é aplicada.
        $sql = 'SELECT a.id, a.title, a.status, a.starts_at, a.ends_at, a.owner_user_id, a.location_type
                FROM calendar_appointments a
                WHERE a.tenant_id = :tenant_id AND a.status IN ("scheduled", "confirmed")
                  AND a.starts_at < :ends_at AND a.ends_at > :starts_at
                  AND (a.owner_user_id = :owner_user_id OR a.owner_user_id IS NULL)
                  AND NOT EXISTS (SELECT 1 FROM calendar_internal_slot_allocations al
                      WHERE al.tenant_id = a.tenant_id AND al.appointment_id = a.id AND al.slot_id = :slot_id
                        AND al.state IN ("held", "booked"))
                ORDER BY a.id';
        if ($locking) {
            $sql .= ' FOR UPDATE';
        }
        $appointmentsQuery = $pdo->prepare($sql);
        $appointmentsQuery->execute(['tenant_id' => $tenantId, 'ends_at' => $slot['ends_at'], 'starts_at' => $slot['starts_at'], 'owner_user_id' => (int) ($slot['owner_user_id'] ?? 0), 'slot_id' => $slotId]);
        $appointments = $appointmentsQuery->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!$appointments) {
            return $fail('Não há compromissos ativos sem vínculo neste horário.');
        }
        $existing = $pdo->prepare('SELECT COUNT(*) FROM calendar_internal_slot_allocations WHERE tenant_id = :tenant_id AND slot_id = :slot_id AND state IN ("held", "booked") AND (state <> "held" OR expires_at IS NULL OR expires_at >= NOW())');
        $existing->execute(['tenant_id' => $tenantId, 'slot_id' => $slotId]);
        $occupied = (int) $existing->fetchColumn();
        // Enquanto um cliente segura uma vaga, preservar integralmente seu hold.
        $heldQuery = $pdo->prepare('SELECT COUNT(*) FROM calendar_internal_slot_allocations WHERE tenant_id = :tenant_id AND slot_id = :slot_id AND state = "held" AND (expires_at IS NULL OR expires_at >= NOW())');
        $heldQuery->execute(['tenant_id' => $tenantId, 'slot_id' => $slotId]);
        if ((int) $heldQuery->fetchColumn() > 0) {
            return $fail('Há pré-reserva ativa neste horário. Aguarde ou resolva-a antes de conciliar.');
        }
        $decision = $this->policy->decide($slot, $appointments, $occupied, $matchingSlots);
        if ($decision['allowed']) {
            // Nunca permitir que um compromisso consuma vagas de mais de um slot.
            $in = implode(',', array_fill(0, count($decision['ids']), '?'));
            $other = $pdo->prepare('SELECT COUNT(*) FROM calendar_internal_slot_allocations WHERE tenant_id = ? AND appointment_id IN (' . $in . ') AND slot_id <> ? AND state IN ("held", "booked")');
            $other->execute(array_merge([$tenantId], $decision['ids'], [$slotId]));
            if ((int) $other->fetchColumn() > 0) {
                return $fail('Um compromisso já possui reserva em outro horário. Confira manualmente.');
            }
        }
        return $decision + ['appointments' => $appointments];
    }
}
