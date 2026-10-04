<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Database;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

/**
 * Horários explicitamente publicados na Agenda interna.
 *
 * Esta camada é aditiva: empresas que continuam em "calculated" não passam por
 * aqui. No modo "published", um espaço vazio no calendário NÃO significa vaga;
 * somente registros disponíveis desta tabela podem ser oferecidos pelo agente.
 */
final class InternalCalendarSlotService
{
    public const STRATEGY_CALCULATED = 'calculated';
    public const STRATEGY_PUBLISHED = 'published';

    public function tableAvailable(): bool
    {
        try {
            $statement = Database::connection()->prepare(
                'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name'
            );
            $statement->execute(['table_name' => 'calendar_internal_slots']);
            return (int) $statement->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    public function allocationTableAvailable(): bool
    {
        try {
            $statement = Database::connection()->prepare(
                'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name'
            );
            $statement->execute(['table_name' => 'calendar_internal_slot_allocations']);
            return (int) $statement->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }


    /**
     * Retorna o vínculo de capacidade dos compromissos informados. Usado pela
     * Agenda visual para representar uma turma como um único bloco de horário,
     * mantendo os participantes individualizados na lista operacional.
     *
     * @param list<int> $appointmentIds
     * @return array<int,array<string,int>> mapa por appointment_id
     */
    public function capacitySummariesForAppointments(int $tenantId, array $appointmentIds): array
    {
        $appointmentIds = array_values(array_unique(array_filter(array_map('intval', $appointmentIds), static fn (int $id): bool => $id > 0)));
        if ($tenantId < 1 || $appointmentIds === [] || !$this->allocationTableAvailable()) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($appointmentIds), '?'));
        $sql = 'SELECT target.appointment_id,
                       target.slot_id,
                       COALESCE(s.capacity_total, 1) AS capacity_total,
                       COALESCE(summary.held_count, 0) AS held_count,
                       COALESCE(summary.booked_count, 0) AS booked_count,
                       COALESCE(summary.occupied_count, 0) AS occupied_count,
                       GREATEST(COALESCE(s.capacity_total, 1) - COALESCE(summary.occupied_count, 0), 0) AS remaining_capacity
                FROM calendar_internal_slot_allocations target
                INNER JOIN calendar_internal_slots s
                    ON s.id = target.slot_id AND s.tenant_id = target.tenant_id
                LEFT JOIN (
                    SELECT tenant_id, slot_id,
                           SUM(CASE WHEN state = "held" THEN 1 ELSE 0 END) AS held_count,
                           SUM(CASE WHEN state = "booked" THEN 1 ELSE 0 END) AS booked_count,
                           COUNT(*) AS occupied_count
                    FROM calendar_internal_slot_allocations
                    WHERE state IN ("held","booked")
                      AND (state <> "held" OR expires_at IS NULL OR expires_at >= NOW())
                    GROUP BY tenant_id, slot_id
                ) summary ON summary.tenant_id = target.tenant_id AND summary.slot_id = target.slot_id
                WHERE target.tenant_id = ?
                  AND target.appointment_id IN (' . $placeholders . ')
                  AND target.state IN ("held","booked")';
        try {
            $statement = Database::connection()->prepare($sql);
            $statement->execute(array_merge([$tenantId], $appointmentIds));
            $map = [];
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $appointmentId = (int) ($row['appointment_id'] ?? 0);
                if ($appointmentId < 1) {
                    continue;
                }
                $map[$appointmentId] = [
                    'slot_id' => (int) ($row['slot_id'] ?? 0),
                    'capacity_total' => max(1, (int) ($row['capacity_total'] ?? 1)),
                    'held_count' => max(0, (int) ($row['held_count'] ?? 0)),
                    'booked_count' => max(0, (int) ($row['booked_count'] ?? 0)),
                    'occupied_count' => max(0, (int) ($row['occupied_count'] ?? 0)),
                    'remaining_capacity' => max(0, (int) ($row['remaining_capacity'] ?? 0)),
                ];
            }
            return $map;
        } catch (Throwable) {
            return [];
        }
    }

    /** @return list<int> */
    public function activeAppointmentIdsForSlot(int $tenantId, int $slotId): array
    {
        if ($tenantId < 1 || $slotId < 1 || !$this->allocationTableAvailable()) {
            return [];
        }
        try {
            $statement = Database::connection()->prepare(
                'SELECT appointment_id
                 FROM calendar_internal_slot_allocations
                 WHERE tenant_id = :tenant_id AND slot_id = :slot_id
                   AND state IN ("held","booked")
                   AND (state <> "held" OR expires_at IS NULL OR expires_at >= NOW())
                 ORDER BY appointment_id'
            );
            $statement->execute(['tenant_id' => $tenantId, 'slot_id' => $slotId]);
            return array_values(array_filter(array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN) ?: [])));
        } catch (Throwable) {
            return [];
        }
    }

    public function normalizeStrategy(string $strategy): string
    {
        return strtolower(trim($strategy)) === self::STRATEGY_PUBLISHED
            ? self::STRATEGY_PUBLISHED
            : self::STRATEGY_CALCULATED;
    }

    /** @return list<array<string,mixed>> */
    public function listSlots(int $tenantId, string $from, string $to, int $ownerUserId = 0, bool $includeClosed = true): array
    {
        if ($tenantId < 1 || !$this->tableAvailable()) {
            return [];
        }

        $this->releaseExpiredHolds($tenantId);
        // 36.41.7: booked slots linked to appointments that are already cancelled,
        // rejected or rescheduled must not remain visually/operationally blocked.
        $this->releaseInactiveBookedSlots($tenantId);
        $withAllocations = $this->allocationTableAvailable();
        $allocationSelect = $withAllocations
            ? ', COALESCE(al.held_count, 0) AS held_count,
                 COALESCE(al.booked_count, 0) AS booked_count,
                 COALESCE(al.occupied_count, 0) AS occupied_count,
                 GREATEST(COALESCE(s.capacity_total, 1) - COALESCE(al.occupied_count, 0), 0) AS remaining_capacity'
            : ', 0 AS held_count, CASE WHEN s.status = "booked" THEN 1 ELSE 0 END AS booked_count,
                 CASE WHEN s.status IN ("held","booked") THEN 1 ELSE 0 END AS occupied_count,
                 CASE WHEN s.status = "available" THEN 1 ELSE 0 END AS remaining_capacity';
        $allocationJoin = $withAllocations
            ? ' LEFT JOIN (
                    SELECT slot_id,
                           SUM(CASE WHEN state = "held" THEN 1 ELSE 0 END) AS held_count,
                           SUM(CASE WHEN state = "booked" THEN 1 ELSE 0 END) AS booked_count,
                           COUNT(*) AS occupied_count
                    FROM calendar_internal_slot_allocations
                    WHERE tenant_id = :allocation_tenant_id
                      AND state IN ("held","booked")
                      AND (state <> "held" OR expires_at IS NULL OR expires_at >= NOW())
                    GROUP BY slot_id
                ) al ON al.slot_id = s.id'
            : '';
        $sql = 'SELECT s.*, u.name AS owner_name, a.title AS hold_appointment_title, a.status AS hold_appointment_status' . $allocationSelect . '
                FROM calendar_internal_slots s
                LEFT JOIN users u ON u.id = s.owner_user_id
                LEFT JOIN calendar_appointments a ON a.id = s.hold_appointment_id' . $allocationJoin . '
                WHERE s.tenant_id = :tenant_id
                  AND s.starts_at < :to_at
                  AND s.ends_at > :from_at';
        $params = ['tenant_id' => $tenantId, 'from_at' => $from, 'to_at' => $to];
        if ($withAllocations) {
            $params['allocation_tenant_id'] = $tenantId;
        }
        if ($ownerUserId > 0) {
            $sql .= ' AND s.owner_user_id = :owner_user_id';
            $params['owner_user_id'] = $ownerUserId;
        }
        if (!$includeClosed) {
            $sql .= ' AND s.status IN ("available","held")';
        }
        $sql .= ' ORDER BY s.starts_at ASC, s.owner_user_id ASC, s.id ASC LIMIT 1200';

        try {
            $statement = Database::connection()->prepare($sql);
            $statement->execute($params);
            return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Publica um horário único ou uma faixa quebrada em opções concretas.
     *
     * @param array<string,mixed> $data
     * @return array{created:int,skipped:int,message:string}
     */
    public function publish(int $tenantId, array $data, ?int $createdByUserId = null): array
    {
        if ($tenantId < 1 || !$this->tableAvailable()) {
            throw new \RuntimeException('Execute a migration 121 para liberar horários na Agenda interna.');
        }

        $timezoneName = trim((string) ($data['timezone'] ?? 'America/Sao_Paulo')) ?: 'America/Sao_Paulo';
        try {
            $timezone = new DateTimeZone($timezoneName);
        } catch (Throwable) {
            $timezone = new DateTimeZone('America/Sao_Paulo');
        }

        $date = trim((string) ($data['slot_date'] ?? ''));
        $startText = trim((string) ($data['slot_start'] ?? ''));
        $endText = trim((string) ($data['slot_end'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1
            || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $startText) !== 1
            || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $endText) !== 1) {
            throw new \RuntimeException('Informe data, início e fim válidos para liberar horários.');
        }

        $rangeStart = new DateTimeImmutable($date . ' ' . $startText . ':00', $timezone);
        $rangeEnd = new DateTimeImmutable($date . ' ' . $endText . ':00', $timezone);
        if ($rangeEnd <= $rangeStart) {
            throw new \RuntimeException('O fim da disponibilidade precisa ser posterior ao início.');
        }
        if ($rangeEnd <= new DateTimeImmutable('now', $timezone)) {
            throw new \RuntimeException('Não é possível liberar somente horários que já passaram.');
        }

        $duration = max(15, min(240, (int) ($data['slot_duration_minutes'] ?? 50)));
        $interval = max(5, min(240, (int) ($data['slot_interval_minutes'] ?? $duration)));
        $capacityTotal = max(1, min(100, (int) ($data['capacity_total'] ?? 1)));
        $ownerUserId = max(0, (int) ($data['owner_user_id'] ?? 0));
        if ($ownerUserId > 0 && !$this->ownerBelongsToTenant($tenantId, $ownerUserId)) {
            throw new \RuntimeException('O profissional selecionado não pertence a esta empresa.');
        }
        $modality = $this->normalizeModality((string) ($data['modality'] ?? 'indefinida'));
        $notes = trim((string) ($data['notes'] ?? ''));
        $single = !empty($data['single_slot']);
        $repeatWeeks = $single ? 1 : max(1, min(52, (int) ($data['repeat_weeks'] ?? 1)));

        $created = 0;
        $skipped = 0;
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $duplicate = $pdo->prepare(
                'SELECT id FROM calendar_internal_slots
                 WHERE tenant_id = :tenant_id
                   AND ((owner_user_id IS NULL AND :owner_user_id_zero = 1) OR owner_user_id = :owner_user_id)
                   AND starts_at = :starts_at
                   AND ends_at = :ends_at
                   AND status <> "cancelled"
                 LIMIT 1 FOR UPDATE'
            );
            $insert = $pdo->prepare(
                'INSERT INTO calendar_internal_slots
                    (tenant_id, owner_user_id, starts_at, ends_at, capacity_total, status, modality, source, notes, created_by_user_id)
                 VALUES
                    (:tenant_id, :owner_user_id, :starts_at, :ends_at, :capacity_total, "available", :modality, "manual", :notes, :created_by_user_id)'
            );

            $weekStart = $rangeStart;
            $weekEnd = $rangeEnd;
            for ($week = 0; $week < $repeatWeeks; $week++) {
                $cursor = $weekStart;
                while ($cursor < $weekEnd) {
                    $slotEnd = $cursor->add(new DateInterval('PT' . $duration . 'M'));
                    if ($slotEnd > $weekEnd) {
                        break;
                    }
                    if ($slotEnd > new DateTimeImmutable('now', $timezone)) {
                        $duplicate->execute([
                            'tenant_id' => $tenantId,
                            'owner_user_id_zero' => $ownerUserId < 1 ? 1 : 0,
                            'owner_user_id' => $ownerUserId > 0 ? $ownerUserId : null,
                            'starts_at' => $cursor->format('Y-m-d H:i:s'),
                            'ends_at' => $slotEnd->format('Y-m-d H:i:s'),
                        ]);
                        if ($duplicate->fetchColumn()) {
                            $skipped++;
                        } else {
                            $insert->execute([
                                'tenant_id' => $tenantId,
                                'owner_user_id' => $ownerUserId > 0 ? $ownerUserId : null,
                                'starts_at' => $cursor->format('Y-m-d H:i:s'),
                                'ends_at' => $slotEnd->format('Y-m-d H:i:s'),
                                'capacity_total' => $capacityTotal,
                                'modality' => $modality,
                                'notes' => $notes !== '' ? mb_substr($notes, 0, 500) : null,
                                'created_by_user_id' => ($createdByUserId ?? 0) > 0 ? $createdByUserId : null,
                            ]);
                            $created++;
                        }
                    }
                    if ($single) {
                        break;
                    }
                    $cursor = $cursor->add(new DateInterval('PT' . $interval . 'M'));
                }
                if ($single || $week + 1 >= $repeatWeeks) {
                    break;
                }
                $weekStart = $weekStart->add(new DateInterval('P7D'));
                $weekEnd = $weekEnd->add(new DateInterval('P7D'));
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        Audit::log('calendar.internal_slots_published', [
            'created' => $created,
            'skipped' => $skipped,
            'date' => $date,
            'owner_user_id' => $ownerUserId ?: null,
            'repeat_weeks' => $repeatWeeks,
            'capacity_total' => $capacityTotal,
        ], $tenantId);

        return [
            'created' => $created,
            'skipped' => $skipped,
            'message' => $created > 0
                ? $created . ' horário(s) liberado(s) na Agenda interna.' . ($skipped > 0 ? ' ' . $skipped . ' já existia(m).' : '')
                : 'Nenhum novo horário foi liberado.' . ($skipped > 0 ? ' Os horários informados já existiam.' : ''),
        ];
    }

    public function cancelSlot(int $tenantId, int $slotId): array
    {
        if ($tenantId < 1 || $slotId < 1 || !$this->tableAvailable()) {
            return ['ok' => false, 'message' => 'Horário inválido.'];
        }
        if ($this->allocationTableAvailable()) {
            $this->releaseExpiredHolds($tenantId);
            $count = Database::connection()->prepare(
                'SELECT COUNT(*) FROM calendar_internal_slot_allocations
                 WHERE tenant_id = :tenant_id AND slot_id = :slot_id AND state IN ("held","booked")'
            );
            $count->execute(['tenant_id' => $tenantId, 'slot_id' => $slotId]);
            if ((int) $count->fetchColumn() > 0) {
                return ['ok' => false, 'message' => 'Este horário possui pessoas pré-reservadas ou confirmadas e não pode ser removido.'];
            }
        }
        $statement = Database::connection()->prepare(
            'UPDATE calendar_internal_slots
             SET status = "cancelled", hold_appointment_id = NULL, hold_expires_at = NULL, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND tenant_id = :tenant_id AND status IN ("available","blocked")'
        );
        $statement->execute(['id' => $slotId, 'tenant_id' => $tenantId]);
        if ($statement->rowCount() < 1) {
            return ['ok' => false, 'message' => 'Este horário já está reservado, confirmado ou não pode mais ser removido.'];
        }
        Audit::log('calendar.internal_slot_cancelled', ['slot_id' => $slotId], $tenantId);
        return ['ok' => true, 'message' => 'Horário removido da disponibilidade.'];
    }

    /** @return list<array<string,mixed>> */
    public function availableForWindow(
        int $tenantId,
        string $start,
        string $end,
        int $ownerUserId = 0,
        string $requestedModality = 'indefinida',
        int $max = 5
    ): array {
        if ($tenantId < 1 || !$this->tableAvailable()) {
            return [];
        }
        $this->releaseExpiredHolds($tenantId);
        // Keep conversational availability consistent even if an older remarcação
        // left the published slot in booked state after the appointment became inactive.
        $this->releaseInactiveBookedSlots($tenantId);
        $modality = $this->normalizeModality($requestedModality);
        $sql = 'SELECT s.*, u.name AS owner_name
                FROM calendar_internal_slots s
                LEFT JOIN users u ON u.id = s.owner_user_id
                WHERE s.tenant_id = :tenant_id
                  AND s.status = "available"
                  AND s.starts_at >= :start_at
                  AND s.ends_at <= :end_at';
        $params = ['tenant_id' => $tenantId, 'start_at' => $start, 'end_at' => $end];
        if ($ownerUserId > 0) {
            $sql .= ' AND (s.owner_user_id = :owner_user_id OR s.owner_user_id IS NULL)';
            $params['owner_user_id'] = $ownerUserId;
        }
        if ($modality !== 'indefinida') {
            $sql .= ' AND (s.modality = :modality OR s.modality = "indefinida")';
            $params['modality'] = $modality;
        }
        $sql .= ' ORDER BY s.starts_at ASC, CASE WHEN s.owner_user_id IS NULL THEN 1 ELSE 0 END, s.owner_user_id ASC, s.id ASC LIMIT ' . max(1, min(200, $max * 4));

        $statement = Database::connection()->prepare($sql);
        $statement->execute($params);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Quando vários profissionais liberam o mesmo horário e o cliente não escolheu
        // um responsável, uma única opção é exibida. O slot preservado define quem será
        // atribuído atomicamente quando o cliente escolher.
        $seen = [];
        $result = [];
        foreach ($rows as $row) {
            $key = (string) ($row['starts_at'] ?? '') . '|' . (string) ($row['ends_at'] ?? '');
            if ($ownerUserId < 1 && isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $row;
            if (count($result) >= max(1, $max)) {
                break;
            }
        }
        return $result;
    }

    /**
     * Faz a pré-reserva do slot publicado. A atualização condicional garante que duas
     * conversas concorrentes não consigam consumir a mesma vaga.
     *
     * @return array{ok:bool,message:string,owner_user_id?:int|null,internal_slot_id?:int}
     */
    public function holdFromAvailabilitySlot(int $tenantId, int $appointmentId, array $availabilitySlot, int $holdMinutes): array
    {
        $internalSlotId = $this->internalSlotIdFromAvailabilitySlot($availabilitySlot);
        if ($internalSlotId < 1 || !$this->tableAvailable()) {
            return ['ok' => false, 'message' => 'O horário publicado não possui vínculo válido com a Agenda interna.'];
        }

        if ($this->allocationTableAvailable()) {
            return $this->holdWithAllocations($tenantId, $appointmentId, $internalSlotId, $holdMinutes);
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $this->releaseExpiredHolds($tenantId, $pdo);
            $select = $pdo->prepare('SELECT * FROM calendar_internal_slots WHERE id = :id AND tenant_id = :tenant_id LIMIT 1 FOR UPDATE');
            $select->execute(['id' => $internalSlotId, 'tenant_id' => $tenantId]);
            $slot = $select->fetch(PDO::FETCH_ASSOC);
            if (!$slot) {
                $pdo->rollBack();
                return ['ok' => false, 'message' => 'O horário liberado não existe mais. Faça uma nova consulta.'];
            }
            $status = (string) ($slot['status'] ?? '');
            $heldBySame = $status === 'held' && (int) ($slot['hold_appointment_id'] ?? 0) === $appointmentId;
            if ($status !== 'available' && !$heldBySame) {
                $pdo->rollBack();
                return ['ok' => false, 'message' => 'Esse horário acabou de ser reservado. Faça uma nova consulta para ver as opções atualizadas.'];
            }

            // Se a mesma conversa estava segurando outro slot, libera-o antes de trocar.
            $pdo->prepare(
                'UPDATE calendar_internal_slots
                 SET status = "available", hold_appointment_id = NULL, hold_expires_at = NULL, updated_at = CURRENT_TIMESTAMP
                 WHERE tenant_id = :tenant_id AND hold_appointment_id = :appointment_id AND status = "held" AND id <> :slot_id'
            )->execute(['tenant_id' => $tenantId, 'appointment_id' => $appointmentId, 'slot_id' => $internalSlotId]);

            $holdMinutes = max(5, min(1440, $holdMinutes));
            $update = $pdo->prepare(
                'UPDATE calendar_internal_slots
                 SET status = "held", hold_appointment_id = :appointment_id,
                     hold_expires_at = DATE_ADD(NOW(), INTERVAL ' . $holdMinutes . ' MINUTE), updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND tenant_id = :tenant_id
                   AND (status = "available" OR (status = "held" AND hold_appointment_id = :appointment_id_same))'
            );
            $update->execute([
                'appointment_id' => $appointmentId,
                'id' => $internalSlotId,
                'tenant_id' => $tenantId,
                'appointment_id_same' => $appointmentId,
            ]);
            if ($update->rowCount() < 1 && !$heldBySame) {
                $pdo->rollBack();
                return ['ok' => false, 'message' => 'Esse horário acabou de ser reservado. Faça uma nova consulta.'];
            }
            $pdo->commit();

            Audit::log('calendar.internal_slot_held', [
                'slot_id' => $internalSlotId,
                'appointment_id' => $appointmentId,
                'hold_minutes' => $holdMinutes,
            ], $tenantId);
            return [
                'ok' => true,
                'message' => 'Horário pré-reservado na Agenda interna.',
                'owner_user_id' => !empty($slot['owner_user_id']) ? (int) $slot['owner_user_id'] : null,
                'internal_slot_id' => $internalSlotId,
            ];
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'message' => 'Não foi possível pré-reservar o horário: ' . $exception->getMessage()];
        }
    }

    public function confirmForAppointment(int $tenantId, int $appointmentId): array
    {
        if (!$this->tableAvailable()) {
            return ['attempted' => false, 'ok' => true, 'message' => null];
        }
        if ($this->allocationTableAvailable()) {
            return $this->confirmAllocationForAppointment($tenantId, $appointmentId);
        }
        $statement = Database::connection()->prepare(
            'UPDATE calendar_internal_slots
             SET status = "booked", hold_expires_at = NULL, updated_at = CURRENT_TIMESTAMP
             WHERE tenant_id = :tenant_id AND hold_appointment_id = :appointment_id AND status IN ("held","booked")'
        );
        $statement->execute(['tenant_id' => $tenantId, 'appointment_id' => $appointmentId]);
        if ($statement->rowCount() < 1) {
            $exists = Database::connection()->prepare(
                'SELECT id FROM calendar_internal_slots WHERE tenant_id = :tenant_id AND hold_appointment_id = :appointment_id AND status = "booked" LIMIT 1'
            );
            $exists->execute(['tenant_id' => $tenantId, 'appointment_id' => $appointmentId]);
            if (!$exists->fetchColumn()) {
                return ['attempted' => true, 'ok' => false, 'message' => 'A vaga publicada não está mais pré-reservada para este agendamento.'];
            }
        }
        Audit::log('calendar.internal_slot_booked', ['appointment_id' => $appointmentId], $tenantId);
        return ['attempted' => true, 'ok' => true, 'message' => 'Horário confirmado na Agenda interna.'];
    }

    public function releaseForAppointment(int $tenantId, int $appointmentId): array
    {
        if (!$this->tableAvailable()) {
            return ['attempted' => false, 'ok' => true, 'message' => null];
        }
        if ($this->allocationTableAvailable()) {
            return $this->releaseAllocationForAppointment($tenantId, $appointmentId);
        }
        $statement = Database::connection()->prepare(
            'UPDATE calendar_internal_slots
             SET status = "available", hold_appointment_id = NULL, hold_expires_at = NULL, updated_at = CURRENT_TIMESTAMP
             WHERE tenant_id = :tenant_id AND hold_appointment_id = :appointment_id AND status IN ("held","booked")'
        );
        $statement->execute(['tenant_id' => $tenantId, 'appointment_id' => $appointmentId]);
        $attempted = $statement->rowCount() > 0;
        if ($attempted) {
            Audit::log('calendar.internal_slot_released', ['appointment_id' => $appointmentId], $tenantId);
        }
        return ['attempted' => $attempted, 'ok' => true, 'message' => $attempted ? 'Horário devolvido à disponibilidade da Agenda interna.' : null];
    }

    /**
     * Reconciles published slots that stayed booked after the linked appointment
     * was effectively cancelled/rejected/rescheduled. This is intentionally
     * conservative: active confirmed/scheduled appointments are never released.
     *
     * @return int number of slots returned to availability
     */
    public function releaseInactiveBookedSlots(int $tenantId, int $appointmentId = 0): int
    {
        if ($tenantId < 1 || !$this->tableAvailable()) {
            return 0;
        }

        if ($this->allocationTableAvailable()) {
            return $this->releaseInactiveAllocations($tenantId, $appointmentId);
        }

        $sql = 'UPDATE calendar_internal_slots s
                INNER JOIN calendar_appointments a
                        ON a.id = s.hold_appointment_id
                       AND a.tenant_id = s.tenant_id
                SET s.status = "available",
                    s.hold_appointment_id = NULL,
                    s.hold_expires_at = NULL,
                    s.updated_at = CURRENT_TIMESTAMP
                WHERE s.tenant_id = :tenant_id
                  AND s.status = "booked"
                  AND a.status IN ("cancelled","rejected","rescheduled")';
        $params = ['tenant_id' => $tenantId];
        if ($appointmentId > 0) {
            $sql .= ' AND a.id = :appointment_id';
            $params['appointment_id'] = $appointmentId;
        }

        try {
            $statement = Database::connection()->prepare($sql);
            $statement->execute($params);
            $released = $statement->rowCount();
            if ($released > 0) {
                Audit::log('calendar.internal_slots_inactive_released', [
                    'appointment_id' => $appointmentId > 0 ? $appointmentId : null,
                    'released' => $released,
                ], $tenantId);
            }
            return $released;
        } catch (Throwable) {
            return 0;
        }
    }

    public function validateHeldForAppointment(int $tenantId, int $appointmentId): array
    {
        if (!$this->tableAvailable()) {
            return ['ok' => false, 'message' => 'Tabela de horários publicados não encontrada.'];
        }
        $this->releaseExpiredHolds($tenantId);
        if ($this->allocationTableAvailable()) {
            $statement = Database::connection()->prepare(
                'SELECT slot_id, state, expires_at
                 FROM calendar_internal_slot_allocations
                 WHERE tenant_id = :tenant_id AND appointment_id = :appointment_id
                   AND state IN ("held","booked")
                 ORDER BY id DESC LIMIT 1'
            );
            $statement->execute(['tenant_id' => $tenantId, 'appointment_id' => $appointmentId]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return ['ok' => false, 'message' => 'A vaga publicada deixou de estar reservada para este agendamento.'];
            }
            return ['ok' => true, 'message' => null, 'internal_slot_id' => (int) $row['slot_id']];
        }
        $statement = Database::connection()->prepare(
            'SELECT id, status, hold_expires_at FROM calendar_internal_slots
             WHERE tenant_id = :tenant_id AND hold_appointment_id = :appointment_id
             ORDER BY id DESC LIMIT 1'
        );
        $statement->execute(['tenant_id' => $tenantId, 'appointment_id' => $appointmentId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$row || !in_array((string) ($row['status'] ?? ''), ['held', 'booked'], true)) {
            return ['ok' => false, 'message' => 'A vaga publicada deixou de estar reservada para este agendamento.'];
        }
        return ['ok' => true, 'message' => null, 'internal_slot_id' => (int) $row['id']];
    }

    public function capacityForAppointment(int $tenantId, int $appointmentId): int
    {
        if ($tenantId < 1 || $appointmentId < 1 || !$this->tableAvailable()) {
            return 1;
        }
        try {
            if ($this->allocationTableAvailable()) {
                $statement = Database::connection()->prepare(
                    'SELECT s.capacity_total
                     FROM calendar_internal_slot_allocations al
                     INNER JOIN calendar_internal_slots s ON s.id = al.slot_id AND s.tenant_id = al.tenant_id
                     WHERE al.tenant_id = :tenant_id AND al.appointment_id = :appointment_id
                     ORDER BY al.id DESC LIMIT 1'
                );
                $statement->execute(['tenant_id' => $tenantId, 'appointment_id' => $appointmentId]);
                $capacity = (int) $statement->fetchColumn();
                return max(1, $capacity);
            }
            $statement = Database::connection()->prepare(
                'SELECT capacity_total FROM calendar_internal_slots
                 WHERE tenant_id = :tenant_id AND hold_appointment_id = :appointment_id
                 ORDER BY id DESC LIMIT 1'
            );
            $statement->execute(['tenant_id' => $tenantId, 'appointment_id' => $appointmentId]);
            return max(1, (int) $statement->fetchColumn());
        } catch (Throwable) {
            return 1;
        }
    }

    public function releaseExpiredHolds(int $tenantId, ?PDO $pdo = null): int
    {
        if ($tenantId < 1 || !$this->tableAvailable()) {
            return 0;
        }
        $pdo ??= Database::connection();
        if ($this->allocationTableAvailable()) {
            try {
                $slots = $pdo->prepare(
                    'SELECT DISTINCT slot_id
                     FROM calendar_internal_slot_allocations
                     WHERE tenant_id = :tenant_id AND state = "held"
                       AND expires_at IS NOT NULL AND expires_at < NOW()'
                );
                $slots->execute(['tenant_id' => $tenantId]);
                $slotIds = array_values(array_filter(array_map('intval', $slots->fetchAll(PDO::FETCH_COLUMN) ?: [])));
                if ($slotIds === []) {
                    return 0;
                }
                $delete = $pdo->prepare(
                    'DELETE FROM calendar_internal_slot_allocations
                     WHERE tenant_id = :tenant_id AND state = "held"
                       AND expires_at IS NOT NULL AND expires_at < NOW()'
                );
                $delete->execute(['tenant_id' => $tenantId]);
                $released = $delete->rowCount();
                foreach ($slotIds as $slotId) {
                    $this->syncSlotStatus($tenantId, $slotId, $pdo);
                }
                return $released;
            } catch (Throwable) {
                return 0;
            }
        }
        try {
            $statement = $pdo->prepare(
                'UPDATE calendar_internal_slots
                 SET status = "available", hold_appointment_id = NULL, hold_expires_at = NULL, updated_at = CURRENT_TIMESTAMP
                 WHERE tenant_id = :tenant_id AND status = "held" AND hold_expires_at IS NOT NULL AND hold_expires_at < NOW()'
            );
            $statement->execute(['tenant_id' => $tenantId]);
            return $statement->rowCount();
        } catch (Throwable) {
            return 0;
        }
    }

    private function internalSlotIdFromAvailabilitySlot(array $availabilitySlot): int
    {
        $raw = [];
        if (isset($availabilitySlot['raw']) && is_array($availabilitySlot['raw'])) {
            $raw = $availabilitySlot['raw'];
        } elseif (!empty($availabilitySlot['raw_json'])) {
            $decoded = json_decode((string) $availabilitySlot['raw_json'], true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        return max(0, (int) ($raw['internal_slot_id'] ?? $availabilitySlot['internal_slot_id'] ?? 0));
    }

    /** @return array{ok:bool,message:string,owner_user_id?:int|null,internal_slot_id?:int,remaining_capacity?:int} */
    private function holdWithAllocations(int $tenantId, int $appointmentId, int $internalSlotId, int $holdMinutes): array
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $this->releaseExpiredHolds($tenantId, $pdo);
            $select = $pdo->prepare(
                'SELECT * FROM calendar_internal_slots
                 WHERE id = :id AND tenant_id = :tenant_id LIMIT 1 FOR UPDATE'
            );
            $select->execute(['id' => $internalSlotId, 'tenant_id' => $tenantId]);
            $slot = $select->fetch(PDO::FETCH_ASSOC);
            if (!$slot || in_array((string) ($slot['status'] ?? ''), ['cancelled', 'blocked', 'expired'], true)) {
                $pdo->rollBack();
                return ['ok' => false, 'message' => 'O horário liberado não existe mais ou está bloqueado. Faça uma nova consulta.'];
            }

            // Preserve a pré-reserva anterior até confirmar que o novo alvo ainda
            // tem capacidade. Assim uma tentativa concorrente não faz o paciente
            // perder a vaga que já estava segurada.
            $capacity = max(1, (int) ($slot['capacity_total'] ?? 1));
            $count = $pdo->prepare(
                'SELECT COUNT(*) FROM calendar_internal_slot_allocations
                 WHERE tenant_id = :tenant_id AND slot_id = :slot_id
                   AND appointment_id <> :appointment_id
                   AND state IN ("held","booked")
                   AND (state <> "held" OR expires_at IS NULL OR expires_at >= NOW())'
            );
            $count->execute([
                'tenant_id' => $tenantId,
                'slot_id' => $internalSlotId,
                'appointment_id' => $appointmentId,
            ]);
            $occupiedByOthers = (int) $count->fetchColumn();
            if ($occupiedByOthers >= $capacity) {
                $pdo->rollBack();
                return ['ok' => false, 'message' => 'Esse horário acabou de atingir a capacidade máxima. Faça uma nova consulta.'];
            }

            $otherSlots = $pdo->prepare(
                'SELECT slot_id FROM calendar_internal_slot_allocations
                 WHERE tenant_id = :tenant_id AND appointment_id = :appointment_id
                   AND state = "held" AND slot_id <> :slot_id'
            );
            $otherSlots->execute([
                'tenant_id' => $tenantId,
                'appointment_id' => $appointmentId,
                'slot_id' => $internalSlotId,
            ]);
            $releasedSlotIds = array_values(array_filter(array_map('intval', $otherSlots->fetchAll(PDO::FETCH_COLUMN) ?: [])));
            $pdo->prepare(
                'DELETE FROM calendar_internal_slot_allocations
                 WHERE tenant_id = :tenant_id AND appointment_id = :appointment_id
                   AND state = "held" AND slot_id <> :slot_id'
            )->execute([
                'tenant_id' => $tenantId,
                'appointment_id' => $appointmentId,
                'slot_id' => $internalSlotId,
            ]);

            $holdMinutes = max(5, min(1440, $holdMinutes));
            $pdo->prepare(
                'INSERT INTO calendar_internal_slot_allocations
                    (tenant_id, slot_id, appointment_id, state, expires_at)
                 VALUES
                    (:tenant_id, :slot_id, :appointment_id, "held", DATE_ADD(NOW(), INTERVAL ' . $holdMinutes . ' MINUTE))
                 ON DUPLICATE KEY UPDATE
                    state = IF(state = "booked", "booked", "held"),
                    expires_at = IF(state = "booked", NULL, VALUES(expires_at)),
                    updated_at = CURRENT_TIMESTAMP'
            )->execute([
                'tenant_id' => $tenantId,
                'slot_id' => $internalSlotId,
                'appointment_id' => $appointmentId,
            ]);

            foreach ($releasedSlotIds as $releasedSlotId) {
                $this->syncSlotStatus($tenantId, $releasedSlotId, $pdo);
            }
            $this->syncSlotStatus($tenantId, $internalSlotId, $pdo);
            $counts = $this->allocationCounts($tenantId, $internalSlotId, $pdo);
            $pdo->commit();

            Audit::log('calendar.internal_slot_held', [
                'slot_id' => $internalSlotId,
                'appointment_id' => $appointmentId,
                'hold_minutes' => $holdMinutes,
                'capacity_total' => $capacity,
                'occupied_count' => $counts['occupied'],
            ], $tenantId);
            return [
                'ok' => true,
                'message' => 'Horário pré-reservado na Agenda interna.',
                'owner_user_id' => !empty($slot['owner_user_id']) ? (int) $slot['owner_user_id'] : null,
                'internal_slot_id' => $internalSlotId,
                'remaining_capacity' => max(0, $capacity - (int) $counts['occupied']),
            ];
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'message' => 'Não foi possível pré-reservar o horário: ' . $exception->getMessage()];
        }
    }

    private function confirmAllocationForAppointment(int $tenantId, int $appointmentId): array
    {
        $pdo = Database::connection();
        try {
            // Descobre o slot antes da transação e depois trava o próprio slot,
            // seguindo a mesma ordem de lock usada na pré-reserva. Isso evita
            // confirmar uma allocation expirada/removida entre validação e update.
            $lookup = $pdo->prepare(
                'SELECT slot_id FROM calendar_internal_slot_allocations
                 WHERE tenant_id = :tenant_id AND appointment_id = :appointment_id
                   AND state IN ("held","booked") ORDER BY id DESC LIMIT 1'
            );
            $lookup->execute(['tenant_id' => $tenantId, 'appointment_id' => $appointmentId]);
            $slotId = (int) $lookup->fetchColumn();
            if ($slotId < 1) {
                return ['attempted' => true, 'ok' => false, 'message' => 'A vaga publicada não está mais pré-reservada para este agendamento.'];
            }

            $pdo->beginTransaction();
            $this->releaseExpiredHolds($tenantId, $pdo);
            $slotLock = $pdo->prepare(
                'SELECT id FROM calendar_internal_slots
                 WHERE id = :slot_id AND tenant_id = :tenant_id LIMIT 1 FOR UPDATE'
            );
            $slotLock->execute(['slot_id' => $slotId, 'tenant_id' => $tenantId]);
            if (!$slotLock->fetchColumn()) {
                $pdo->rollBack();
                return ['attempted' => true, 'ok' => false, 'message' => 'O horário publicado não existe mais.'];
            }

            $slotQuery = $pdo->prepare(
                'SELECT slot_id, state, expires_at FROM calendar_internal_slot_allocations
                 WHERE tenant_id = :tenant_id AND appointment_id = :appointment_id
                   AND slot_id = :slot_id AND state IN ("held","booked")
                 ORDER BY id DESC LIMIT 1 FOR UPDATE'
            );
            $slotQuery->execute([
                'tenant_id' => $tenantId,
                'appointment_id' => $appointmentId,
                'slot_id' => $slotId,
            ]);
            $allocation = $slotQuery->fetch(PDO::FETCH_ASSOC);
            if (!$allocation) {
                $pdo->rollBack();
                return ['attempted' => true, 'ok' => false, 'message' => 'A vaga publicada expirou ou deixou de estar reservada para este agendamento.'];
            }

            $update = $pdo->prepare(
                'UPDATE calendar_internal_slot_allocations
                 SET state = "booked", expires_at = NULL, updated_at = CURRENT_TIMESTAMP
                 WHERE tenant_id = :tenant_id AND appointment_id = :appointment_id AND slot_id = :slot_id'
            );
            $update->execute(['tenant_id' => $tenantId, 'appointment_id' => $appointmentId, 'slot_id' => $slotId]);
            $this->syncSlotStatus($tenantId, $slotId, $pdo);
            $pdo->commit();

            Audit::log('calendar.internal_slot_booked', ['appointment_id' => $appointmentId, 'slot_id' => $slotId], $tenantId);
            return ['attempted' => true, 'ok' => true, 'message' => 'Horário confirmado na Agenda interna.'];
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['attempted' => true, 'ok' => false, 'message' => 'Não foi possível confirmar a vaga publicada: ' . $exception->getMessage()];
        }
    }

    private function releaseAllocationForAppointment(int $tenantId, int $appointmentId): array
    {
        $pdo = Database::connection();
        try {
            $query = $pdo->prepare(
                'SELECT DISTINCT slot_id FROM calendar_internal_slot_allocations
                 WHERE tenant_id = :tenant_id AND appointment_id = :appointment_id'
            );
            $query->execute(['tenant_id' => $tenantId, 'appointment_id' => $appointmentId]);
            $slotIds = array_values(array_filter(array_map('intval', $query->fetchAll(PDO::FETCH_COLUMN) ?: [])));
            if ($slotIds === []) {
                return ['attempted' => false, 'ok' => true, 'message' => null];
            }
            $delete = $pdo->prepare(
                'DELETE FROM calendar_internal_slot_allocations
                 WHERE tenant_id = :tenant_id AND appointment_id = :appointment_id'
            );
            $delete->execute(['tenant_id' => $tenantId, 'appointment_id' => $appointmentId]);
            foreach ($slotIds as $slotId) {
                $this->syncSlotStatus($tenantId, $slotId, $pdo);
            }
            Audit::log('calendar.internal_slot_released', ['appointment_id' => $appointmentId, 'slot_ids' => $slotIds], $tenantId);
            return ['attempted' => true, 'ok' => true, 'message' => 'Vaga devolvida à capacidade do horário publicado.'];
        } catch (Throwable $exception) {
            return ['attempted' => true, 'ok' => false, 'message' => 'Não foi possível liberar a vaga: ' . $exception->getMessage()];
        }
    }

    private function releaseInactiveAllocations(int $tenantId, int $appointmentId = 0): int
    {
        $pdo = Database::connection();
        try {
            $sql = 'SELECT DISTINCT al.slot_id
                    FROM calendar_internal_slot_allocations al
                    INNER JOIN calendar_appointments a ON a.id = al.appointment_id AND a.tenant_id = al.tenant_id
                    WHERE al.tenant_id = :tenant_id
                      AND a.status IN ("cancelled","rejected","rescheduled")';
            $params = ['tenant_id' => $tenantId];
            if ($appointmentId > 0) {
                $sql .= ' AND a.id = :appointment_id';
                $params['appointment_id'] = $appointmentId;
            }
            $select = $pdo->prepare($sql);
            $select->execute($params);
            $slotIds = array_values(array_filter(array_map('intval', $select->fetchAll(PDO::FETCH_COLUMN) ?: [])));
            if ($slotIds === []) {
                return 0;
            }

            $deleteSql = 'DELETE al FROM calendar_internal_slot_allocations al
                          INNER JOIN calendar_appointments a ON a.id = al.appointment_id AND a.tenant_id = al.tenant_id
                          WHERE al.tenant_id = :tenant_id
                            AND a.status IN ("cancelled","rejected","rescheduled")';
            $deleteParams = ['tenant_id' => $tenantId];
            if ($appointmentId > 0) {
                $deleteSql .= ' AND a.id = :appointment_id';
                $deleteParams['appointment_id'] = $appointmentId;
            }
            $delete = $pdo->prepare($deleteSql);
            $delete->execute($deleteParams);
            $released = $delete->rowCount();
            foreach ($slotIds as $slotId) {
                $this->syncSlotStatus($tenantId, $slotId, $pdo);
            }
            if ($released > 0) {
                Audit::log('calendar.internal_slots_inactive_released', [
                    'appointment_id' => $appointmentId > 0 ? $appointmentId : null,
                    'released' => $released,
                ], $tenantId);
            }
            return $released;
        } catch (Throwable) {
            return 0;
        }
    }

    /** @return array{held:int,booked:int,occupied:int} */
    private function allocationCounts(int $tenantId, int $slotId, ?PDO $pdo = null): array
    {
        $pdo ??= Database::connection();
        $statement = $pdo->prepare(
            'SELECT
                SUM(CASE WHEN state = "held" AND (expires_at IS NULL OR expires_at >= NOW()) THEN 1 ELSE 0 END) AS held_count,
                SUM(CASE WHEN state = "booked" THEN 1 ELSE 0 END) AS booked_count
             FROM calendar_internal_slot_allocations
             WHERE tenant_id = :tenant_id AND slot_id = :slot_id'
        );
        $statement->execute(['tenant_id' => $tenantId, 'slot_id' => $slotId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        $held = (int) ($row['held_count'] ?? 0);
        $booked = (int) ($row['booked_count'] ?? 0);
        return ['held' => $held, 'booked' => $booked, 'occupied' => $held + $booked];
    }

    private function syncSlotStatus(int $tenantId, int $slotId, ?PDO $pdo = null): void
    {
        if ($slotId < 1) {
            return;
        }
        $pdo ??= Database::connection();
        $slotQuery = $pdo->prepare(
            'SELECT capacity_total, status FROM calendar_internal_slots
             WHERE id = :id AND tenant_id = :tenant_id LIMIT 1'
        );
        $slotQuery->execute(['id' => $slotId, 'tenant_id' => $tenantId]);
        $slot = $slotQuery->fetch(PDO::FETCH_ASSOC);
        if (!$slot || in_array((string) ($slot['status'] ?? ''), ['cancelled', 'blocked', 'expired'], true)) {
            return;
        }
        $capacity = max(1, (int) ($slot['capacity_total'] ?? 1));
        $counts = $this->allocationCounts($tenantId, $slotId, $pdo);
        $status = $counts['occupied'] < $capacity
            ? 'available'
            : ($counts['held'] > 0 ? 'held' : 'booked');

        $legacyAppointmentId = null;
        $legacyExpiresAt = null;
        if ($capacity === 1 && $counts['occupied'] > 0) {
            $legacy = $pdo->prepare(
                'SELECT appointment_id, expires_at FROM calendar_internal_slot_allocations
                 WHERE tenant_id = :tenant_id AND slot_id = :slot_id
                   AND state IN ("held","booked")
                 ORDER BY CASE WHEN state = "booked" THEN 0 ELSE 1 END, id ASC LIMIT 1'
            );
            $legacy->execute(['tenant_id' => $tenantId, 'slot_id' => $slotId]);
            $row = $legacy->fetch(PDO::FETCH_ASSOC) ?: [];
            $legacyAppointmentId = !empty($row['appointment_id']) ? (int) $row['appointment_id'] : null;
            $legacyExpiresAt = !empty($row['expires_at']) ? (string) $row['expires_at'] : null;
        }

        $pdo->prepare(
            'UPDATE calendar_internal_slots
             SET status = :status,
                 hold_appointment_id = :hold_appointment_id,
                 hold_expires_at = :hold_expires_at,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND tenant_id = :tenant_id'
        )->execute([
            'status' => $status,
            'hold_appointment_id' => $legacyAppointmentId,
            'hold_expires_at' => $legacyExpiresAt,
            'id' => $slotId,
            'tenant_id' => $tenantId,
        ]);
    }

    private function normalizeModality(string $modality): string
    {
        $modality = strtolower(trim($modality));
        return in_array($modality, ['online', 'presencial', 'telefone'], true) ? $modality : 'indefinida';
    }

    private function ownerBelongsToTenant(int $tenantId, int $ownerUserId): bool
    {
        try {
            $statement = Database::connection()->prepare('SELECT 1 FROM users WHERE id = :id AND tenant_id = :tenant_id AND status = "active" LIMIT 1');
            $statement->execute(['id' => $ownerUserId, 'tenant_id' => $tenantId]);
            return (bool) $statement->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }
}
