<?php

namespace App\Observers;

use Modules\AuditLog\Services\AuditLogger;
use Modules\SettingCompany\Entities\Branch;

class BranchObserver
{
    public function created(Branch $branch): void
    {
        AuditLogger::log(
            action: 'create',
            module: 'Branch',
            record: $branch,
            new: $branch->only(['name', 'address', 'phone', 'email', 'status']),
            label: $branch->name,
        );
    }

    public function updated(Branch $branch): void
    {
        $changed = array_keys($branch->getChanges());

        if (empty($changed)) {
            return;
        }

        AuditLogger::log(
            action: 'update',
            module: 'Branch',
            record: $branch,
            old: array_intersect_key($branch->getOriginal(), array_flip($changed)),
            new: array_intersect_key($branch->getChanges(), array_flip($changed)),
            label: $branch->name,
        );
    }

    public function deleted(Branch $branch): void
    {
        AuditLogger::log(
            action: 'delete',
            module: 'Branch',
            record: $branch,
            old: $branch->only(['name', 'address', 'phone']),
            label: $branch->name,
        );
    }
}
