<?php

namespace App\Observers;

use App\Models\User;
use Modules\AuditLog\Services\AuditLogger;

class UserObserver
{
    // Các field nhạy cảm cần theo dõi khi update
    private const TRACKED_FIELDS = ['fullname', 'email', 'phone', 'date_of_birth'];

    public function created(User $user): void
    {
        AuditLogger::log(
            action: 'create',
            module: 'User',
            record: $user,
            new: $user->only(['fullname', 'email', 'phone']),
            label: $user->fullname ?? $user->email,
        );
    }

    public function updated(User $user): void
    {
        $changed = array_keys($user->getChanges());
        $tracked = array_intersect($changed, self::TRACKED_FIELDS);

        if (empty($tracked)) {
            return;
        }

        AuditLogger::log(
            action: 'update',
            module: 'User',
            record: $user,
            old: array_intersect_key($user->getOriginal(), array_flip($tracked)),
            new: array_intersect_key($user->getChanges(), array_flip($tracked)),
            label: $user->fullname ?? $user->email,
        );
    }

    public function deleted(User $user): void
    {
        AuditLogger::log(
            action: 'delete',
            module: 'User',
            record: $user,
            old: $user->only(['fullname', 'email', 'phone']),
            label: $user->fullname ?? $user->email,
        );
    }
}
