<?php

namespace App\Observers;

use Modules\AuditLog\Services\AuditLogger;
use Modules\DataPermission\Entities\UserBranchPermission;

class UserBranchPermissionObserver
{
    public function created(UserBranchPermission $permission): void
    {
        $label = $this->buildLabel($permission);

        AuditLogger::log(
            action: 'create',
            module: 'UserBranchPermission',
            record: $permission,
            new: $permission->only(['user_id', 'category_id']),
            label: $label,
        );
    }

    public function deleted(UserBranchPermission $permission): void
    {
        $label = $this->buildLabel($permission);

        AuditLogger::log(
            action: 'delete',
            module: 'UserBranchPermission',
            record: $permission,
            old: $permission->only(['user_id', 'category_id']),
            label: $label,
        );
    }

    private function buildLabel(UserBranchPermission $permission): string
    {
        $userName   = $permission->user?->fullname ?? $permission->user?->email ?? $permission->user_id;
        $branchName = $permission->branch?->name ?? $permission->category_id;

        return "{$userName} → {$branchName}";
    }
}
