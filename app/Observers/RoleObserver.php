<?php

namespace App\Observers;

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
            new: $role->only(['name', 'guard_name']),
            label: $role->name,
        );
    }

    public function updated(Role $role): void
    {
        $changed = array_keys($role->getChanges());
        $tracked = array_intersect($changed, ['name', 'guard_name']);

        if (empty($tracked)) {
            return;
        }

        AuditLogger::log(
            action: 'update',
            module: 'Role',
            record: $role,
            old: array_intersect_key($role->getOriginal(), array_flip($tracked)),
            new: array_intersect_key($role->getChanges(), array_flip($tracked)),
            label: $role->name,
        );
    }

    public function deleted(Role $role): void
    {
        AuditLogger::log(
            action: 'delete',
            module: 'Role',
            record: $role,
            old: $role->only(['name', 'guard_name']),
            label: $role->name,
        );
    }
}
