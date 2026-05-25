<?php

namespace App\Observers;

use Modules\AuditLog\Services\AuditLogger;
use Modules\Promotion\App\Models\Coupon;

class CouponObserver
{
    private const TRACKED_FIELDS = ['code', 'name', 'type', 'value', 'max_discount', 'usage_limit', 'start_at', 'end_at', 'is_active'];

    public function created(Coupon $coupon): void
    {
        AuditLogger::log(
            action: 'create',
            module: 'Coupon',
            record: $coupon,
            new: $coupon->only(['code', 'name', 'type', 'value', 'is_active']),
            label: "{$coupon->code} — {$coupon->name}",
        );
    }

    public function updated(Coupon $coupon): void
    {
        $changed = array_keys($coupon->getChanges());
        $tracked = array_intersect($changed, self::TRACKED_FIELDS);

        if (empty($tracked)) {
            return;
        }

        AuditLogger::log(
            action: 'update',
            module: 'Coupon',
            record: $coupon,
            old: array_intersect_key($coupon->getOriginal(), array_flip($tracked)),
            new: array_intersect_key($coupon->getChanges(), array_flip($tracked)),
            label: "{$coupon->code} — {$coupon->name}",
        );
    }

    public function deleted(Coupon $coupon): void
    {
        AuditLogger::log(
            action: 'delete',
            module: 'Coupon',
            record: $coupon,
            old: $coupon->only(['code', 'name', 'type', 'value']),
            label: "{$coupon->code} — {$coupon->name}",
        );
    }
}
