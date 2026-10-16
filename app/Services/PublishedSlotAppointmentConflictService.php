<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use Throwable;

/**
 * Somente leitura. Identifica compromissos efetivos que se sobrepõem a um
 * horário publicado, mas NÃO foram vinculados à alocação daquele horário.
 * Nunca converte automaticamente compromisso manual em vaga/alocação.
 */
final class PublishedSlotAppointmentConflictService
{
    /**
     * @param list<int> $slotIds
     * @return array<int,int>|null Null = não foi possível verificar; não ofertar por suposição.
     */
    public function counts(int $tenantId, array $slotIds, bool $hasAllocations, ?PDO $pdo = null): ?array
    {
        $slotIds = array_values(array_unique(array_filter(array_map('intval', $slotIds),
            static fn (int $id): bool => $id > 0)));
        if ($tenantId < 1 || $slotIds === []) {
            return [];
        }

        try {
            $pdo ??= Database::connection();
            $result = [];
            foreach (array_chunk($slotIds, 150) as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                $linkedAllocationFilter = $hasAllocations ? '
                    AND NOT EXISTS (
                        SELECT 1 FROM calendar_internal_slot_allocations al
                        WHERE al.tenant_id = s.tenant_id
                          AND al.slot_id = s.id AND al.appointment_id = a.id
                          AND al.state IN ("held", "booked")
                    )' : '';
                $sql = 'SELECT s.id AS slot_id, COUNT(DISTINCT a.id) AS conflict_count
                        FROM calendar_internal_slots s
                        LEFT JOIN calendar_appointments a
                          ON a.tenant_id = s.tenant_id
                         AND a.status IN ("scheduled", "confirmed")
                         AND a.starts_at < s.ends_at AND a.ends_at > s.starts_at
                         AND (s.owner_user_id IS NULL OR a.owner_user_id IS NULL
                              OR a.owner_user_id = s.owner_user_id)'
                         . $linkedAllocationFilter . '
                        WHERE s.tenant_id = ? AND s.id IN (' . $placeholders . ')
                        GROUP BY s.id';
                $stmt = $pdo->prepare($sql);
                $stmt->execute(array_merge([$tenantId], $chunk));
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                    $result[(int) $row['slot_id']] = max(0, (int) $row['conflict_count']);
                }
                foreach ($chunk as $slotId) {
                    if (!array_key_exists($slotId, $result)) {
                        // A vaga pode ter sido removida entre as leituras: não presumir zero.
                        return null;
                    }
                }
            }
            return $result;
        } catch (Throwable $exception) {
            error_log('[RS Connect] Falha ao cruzar compromissos com horários publicados: tenant=' . $tenantId . ' error=' . $exception::class);
            return null;
        }
    }
}
