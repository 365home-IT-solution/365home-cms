<?php

namespace App\Observers;

use App\Support\AuditFieldFilter;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Product\App\Models\Product;

class ProductObserver
{
    public function created(Product $product): void
    {
        AuditLogger::log(
            action: 'create',
            module: 'Product',
            record: $product,
            new: AuditFieldFilter::filter($product->getAttributes()),
            label: $product->name,
        );
    }

    // Trước đây chỉ ghi log khi field đổi nằm trong 1 danh sách TRACKED_FIELDS cố định (giá, giảm
    // giá, kích hoạt...) — sửa mô tả, ảnh, cấu hình khác không nằm trong danh sách sẽ hoàn toàn
    // KHÔNG có log. Bỏ whitelist, ghi lại TOÀN BỘ field thực sự thay đổi.
    public function updated(Product $product): void
    {
        $changed = AuditFieldFilter::filter($product->getChanges());

        if (empty($changed)) {
            return;
        }

        AuditLogger::log(
            action: 'update',
            module: 'Product',
            record: $product,
            old: array_intersect_key($product->getOriginal(), $changed),
            new: $changed,
            label: $product->name,
        );
    }

    public function deleted(Product $product): void
    {
        AuditLogger::log(
            action: 'delete',
            module: 'Product',
            record: $product,
            old: AuditFieldFilter::filter($product->getAttributes()),
            label: $product->name,
        );
    }
}
