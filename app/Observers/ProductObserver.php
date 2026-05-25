<?php

namespace App\Observers;

use Modules\AuditLog\Services\AuditLogger;
use Modules\Product\App\Models\Product;

class ProductObserver
{
    // Các field nhạy cảm liên quan đến doanh thu cần theo dõi khi update
    private const TRACKED_FIELDS = [
        'name', 'price', 'discount', 'is_activated', 'is_in_stock',
        'full_booking_discount', 'deposit_1_night', 'deposit_multi_night',
        'deposit_min_nights', 'default_checkin', 'default_checkout',
    ];

    public function created(Product $product): void
    {
        AuditLogger::log(
            action: 'create',
            module: 'Product',
            record: $product,
            new: $product->only(['name', 'price', 'discount', 'is_activated']),
            label: $product->name,
        );
    }

    public function updated(Product $product): void
    {
        $changed = array_keys($product->getChanges());
        $tracked = array_intersect($changed, self::TRACKED_FIELDS);

        if (empty($tracked)) {
            return;
        }

        AuditLogger::log(
            action: 'update',
            module: 'Product',
            record: $product,
            old: array_intersect_key($product->getOriginal(), array_flip($tracked)),
            new: array_intersect_key($product->getChanges(), array_flip($tracked)),
            label: $product->name,
        );
    }

    public function deleted(Product $product): void
    {
        AuditLogger::log(
            action: 'delete',
            module: 'Product',
            record: $product,
            old: $product->only(['name', 'price', 'is_activated']),
            label: $product->name,
        );
    }
}
