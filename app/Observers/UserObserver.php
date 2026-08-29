<?php

namespace App\Observers;

use App\Models\User;
use App\Support\AuditFieldFilter;
use Modules\AuditLog\Services\AuditLogger;

class UserObserver
{
    public function created(User $user): void
    {
        AuditLogger::log(
            action: 'create',
            module: 'User',
            record: $user,
            new: AuditFieldFilter::filter($user->getAttributes()),
            label: $user->fullname ?? $user->email,
        );
    }

    // Trước đây chỉ theo dõi fullname/email/phone/date_of_birth/partner_id — đổi role_id, trạng
    // thái hoạt động, hay field khác không nằm trong whitelist sẽ không có log. Bỏ whitelist, ghi
    // lại toàn bộ field thay đổi (password đã bị AuditFieldFilter loại trừ mặc định, không lộ ra
    // log dù có đổi).
    public function updated(User $user): void
    {
        $changed = AuditFieldFilter::filter($user->getChanges());

        if (empty($changed)) {
            return;
        }

        AuditLogger::log(
            action: 'update',
            module: 'User',
            record: $user,
            old: array_intersect_key($user->getOriginal(), $changed),
            new: $changed,
            label: $user->fullname ?? $user->email,
        );
    }

    public function deleted(User $user): void
    {
        AuditLogger::log(
            action: 'delete',
            module: 'User',
            record: $user,
            old: AuditFieldFilter::filter($user->getAttributes()),
            label: $user->fullname ?? $user->email,
        );
    }
}
