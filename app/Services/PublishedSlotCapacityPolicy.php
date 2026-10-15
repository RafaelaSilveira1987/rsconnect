<?php

declare(strict_types=1);

namespace App\Services;

/** Pure rules; SQL locking and persistence belong to InternalCalendarSlotService. */
final class PublishedSlotCapacityPolicy
{
    /**
     * @return array{allowed:bool,code:string}
     */
    public function evaluateHold(int $capacity, int $occupiedByOtherAppointments, bool $alreadyBooked): array
    {
        if ($capacity < 1 || $occupiedByOtherAppointments < 0) {
            return ['allowed' => false, 'code' => 'invalid_capacity'];
        }
        // An exact retry of an already confirmed allocation must not consume
        // another seat, even when the class is otherwise full.
        if ($alreadyBooked) {
            return ['allowed' => true, 'code' => 'already_booked'];
        }
        if ($occupiedByOtherAppointments >= $capacity) {
            return ['allowed' => false, 'code' => 'capacity_full'];
        }
        return ['allowed' => true, 'code' => 'available'];
    }

    public function canConfirm(string $state, bool $holdStillValid): bool
    {
        return $state === 'booked' || ($state === 'held' && $holdStillValid);
    }
}
