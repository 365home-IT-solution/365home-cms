<?php

namespace App\Observers;

use App\Support\AuditFieldFilter;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Promotion\App\Models\Promotion;

class PromotionObserver
{
    public function created(Promotion $promotion): void
    {
        AuditLogger::log(
            action: 'create',
            module: 'Promotion',
            record: $promotion,
            new: AuditFieldFilter::filter($promotion->getAttributes()),
            label: $promotion->name,
        );
    }

    public function updated(Promotion $promotion): void
    {
        $changed = AuditFieldFilter::filter($promotion->getChanges());

        if (empty($changed)) {
            return;
        }

        AuditLogger::log(
            action: 'update',
            module: 'Promotion',
            record: $promotion,
            old: array_intersect_key($promotion->getOriginal(), $changed),
            new: $changed,
            label: $promotion->name,
        );
    }

    public function deleted(Promotion $promotion): void
    {
        AuditLogger::log(
            action: 'delete',
            module: 'Promotion',
            record: $promotion,
            old: AuditFieldFilter::filter($promotion->getAttributes()),
            label: $promotion->name,
        );
    }
}
