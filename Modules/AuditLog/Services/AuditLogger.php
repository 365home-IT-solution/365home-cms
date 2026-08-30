<?php

declare(strict_types=1);

namespace Modules\AuditLog\Services;

use Illuminate\Database\Eloquent\Model;
use Modules\AuditLog\Entities\AuditLog;

class AuditLogger
{
    /**
     * Ghi một bản ghi audit log.
     *
     * @param  string  $action   create | update | delete
     * @param  string  $module   Role | User | UserBranchPermission
     * @param  Model   $record   bản ghi bị tác động
     * @param  array   $old      giá trị cũ (chỉ các field thay đổi)
     * @param  array   $new      giá trị mới (chỉ các field thay đổi)
     * @param  string  $label    tên hiển thị của bản ghi
     */
    public static function log(
        string $action,
        string $module,
        Model $record,
        array $old = [],
        array $new = [],
        string $label = ''
    ): void {
        $user = auth()->user();

        // Chỉ ghi audit log cho hành động của staff (App\Models\User có trait HasRoles).
        // Các model khác (vd. App\Models\Customer khi API đặt phòng cập nhật used_count của
        // coupon) không có getRoleNames() và trước đây làm crash 500 toàn bộ request.
        if (! $user instanceof \App\Models\User) {
            return;
        }

        AuditLog::create([
            'user_id'        => $user->id,
            'user_name'      => $user->fullname ?? $user->email,
            'user_email'     => $user->email,
            'performer_role' => $user->getRoleNames()->first() ?? 'unknown',
            'action'         => $action,
            'module'         => $module,
            'target_id'      => (string) $record->getKey(),
            'target_label'   => $label,
            'old_values'     => empty($old) ? null : $old,
            'new_values'     => empty($new) ? null : $new,
            'ip_address'     => request()->ip(),
            'user_agent'     => request()->userAgent(),
            'created_at'     => now(),
        ]);
    }
}
