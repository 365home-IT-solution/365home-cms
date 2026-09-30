<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Product\App\Models\Product;

class RoomEmergencyAccessService
{
    public function lock(Product $room, User $actor, string $reason): Product
    {
        $old = $this->snapshot($room);

        $room->forceFill([
            'emergency_locked_at'   => now(),
            'emergency_locked_by'   => (string) $actor->getKey(),
            'emergency_lock_reason' => trim($reason),
        ])->save();

        $this->audit($room, $actor, 'locked', $old);

        return $room->refresh();
    }

    public function release(Product $room, User $actor): Product
    {
        $old = $this->snapshot($room);

        $room->forceFill([
            'emergency_locked_at'   => null,
            'emergency_locked_by'   => null,
            'emergency_lock_reason' => null,
        ])->save();

        $this->audit($room, $actor, 'released', $old);

        return $room->refresh();
    }

    /** @return array<string, mixed> */
    public function payload(Product $room): array
    {
        return [
            'is_emergency_locked'  => $room->emergency_locked_at !== null,
            'emergency_locked_at'  => $room->emergency_locked_at?->toIso8601String(),
            'emergency_lock_reason'=> $room->emergency_lock_reason,
        ];
    }

    /** @return array<string, mixed> */
    private function snapshot(Product $room): array
    {
        return [
            'emergency_locked_at'   => $room->emergency_locked_at?->toIso8601String(),
            'emergency_locked_by'   => $room->emergency_locked_by,
            'emergency_lock_reason' => $room->emergency_lock_reason,
        ];
    }

    /** @param array<string, mixed> $old */
    private function audit(Product $room, User $actor, string $event, array $old): void
    {
        Log::warning('Room emergency access changed', [
            'event' => $event,
            'room_id' => $room->getKey(),
            'actor_id' => $actor->getKey(),
            'reason' => $room->emergency_lock_reason,
        ]);

        AuditLogger::log(
            'update',
            'RoomEmergencyAccess',
            $room,
            $old,
            $this->snapshot($room),
            (string) $room->name,
        );
    }
}
