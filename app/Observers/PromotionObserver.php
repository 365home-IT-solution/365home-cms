<?php

namespace App\Observers;

use Modules\AuditLog\Services\AuditLogger;
use Modules\Promotion\App\Models\Promotion;

class PromotionObserver
{
    private const TRACKED_FIELDS = ['name', 'type', 'value', 'start_at', 'end_at', 'is_active'];

    public function created(Promotion $promotion): void
    {
        AuditLogger::log(
            action: 'create',
            module: 'Promotion',
            record: $promotion,
            new: $promotion->only(['name', 'type', 'value', 'is_active']),
            label: $promotion->name,
        );
    }

    public function updated(Promotion $promotion): void
    {
        $changed = array_keys($promotion->getChanges());
        $tracked = array_intersect($changed, self::TRACKED_FIELDS);

        if (empty($tracked)) {
            return;
        }

        AuditLogger::log(
            action: 'update',
            module: 'Promotion',
            record: $promotion,
            old: array_intersect_key($promotion->getOriginal(), array_flip($tracked)),
            new: array_intersect_key($promotion->getChanges(), array_flip($tracked)),
            label: $promotion->name,
        );
    }

    public function deleted(Promotion $promotion): void
    {
        AuditLogger::log(
            action: 'delete',
            module: 'Promotion',
            record: $promotion,
            old: $promotion->only(['name', 'type', 'value']),
            label: $promotion->name,
        );
    }
}
