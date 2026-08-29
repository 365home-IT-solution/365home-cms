<?php

namespace App\Observers;

use App\Models\User;
use Modules\AuditLog\Services\AuditLogger;

class UserObserver
{
    // Các field nhạy cảm cần theo dõi khi update
    // 'partner_id' thêm vào vì PartnerForm::syncAssignments() gán/gỡ user khỏi đối tác qua
    // $user->update(['partner_id' => ...]) — trước đây không track nên đổi đối tác sở hữu của 1
    // tài khoản (thay đổi quan trọng) hoàn toàn không để lại log nào.
    private const TRACKED_FIELDS = ['fullname', 'email', 'phone', 'date_of_birth', 'partner_id'];

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
