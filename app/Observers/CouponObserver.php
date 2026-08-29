<?php

namespace App\Observers;

use App\Support\AuditFieldFilter;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Promotion\App\Models\Coupon;

class CouponObserver
{
    public function created(Coupon $coupon): void
    {
        AuditLogger::log(
            action: 'create',
            module: 'Coupon',
            record: $coupon,
            new: AuditFieldFilter::filter($coupon->getAttributes()),
            label: "{$coupon->code} — {$coupon->name}",
        );
    }

    public function updated(Coupon $coupon): void
    {
        $changed = AuditFieldFilter::filter($coupon->getChanges());

        if (empty($changed)) {
            return;
        }

        AuditLogger::log(
            action: 'update',
            module: 'Coupon',
            record: $coupon,
            old: array_intersect_key($coupon->getOriginal(), $changed),
            new: $changed,
            label: "{$coupon->code} — {$coupon->name}",
        );
    }

    public function deleted(Coupon $coupon): void
    {
        AuditLogger::log(
            action: 'delete',
            module: 'Coupon',
            record: $coupon,
            old: AuditFieldFilter::filter($coupon->getAttributes()),
            label: "{$coupon->code} — {$coupon->name}",
        );
    }
}
