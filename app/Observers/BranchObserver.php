<?php

namespace App\Observers;

use App\Support\AuditFieldFilter;
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
            new: AuditFieldFilter::filter($branch->getAttributes()),
            label: $branch->name,
        );
    }

    public function updated(Branch $branch): void
    {
        $changed = AuditFieldFilter::filter($branch->getChanges());

        if (empty($changed)) {
            return;
        }

        AuditLogger::log(
            action: 'update',
            module: 'Branch',
            record: $branch,
            old: array_intersect_key($branch->getOriginal(), $changed),
            new: $changed,
            label: $branch->name,
        );
    }

    public function deleted(Branch $branch): void
    {
        AuditLogger::log(
            action: 'delete',
            module: 'Branch',
            record: $branch,
            old: AuditFieldFilter::filter($branch->getAttributes()),
            label: $branch->name,
        );
    }
}
