<?php

namespace App\Observers;

use App\Support\AuditFieldFilter;
use Modules\AuditLog\Services\AuditLogger;
use Spatie\Permission\Models\Role;

class RoleObserver
{
    public function created(Role $role): void
    {
        AuditLogger::log(
            action: 'create',
            module: 'Role',
            record: $role,
            new: AuditFieldFilter::filter($role->getAttributes()),
            label: $role->name,
        );
    }

    public function updated(Role $role): void
    {
        $changed = AuditFieldFilter::filter($role->getChanges());

        if (empty($changed)) {
            return;
        }

        AuditLogger::log(
            action: 'update',
            module: 'Role',
            record: $role,
            old: array_intersect_key($role->getOriginal(), $changed),
            new: $changed,
            label: $role->name,
        );
    }

    public function deleted(Role $role): void
    {
        AuditLogger::log(
            action: 'delete',
            module: 'Role',
            record: $role,
            old: AuditFieldFilter::filter($role->getAttributes()),
            label: $role->name,
        );
    }
}
