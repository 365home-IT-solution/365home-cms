<?php

declare(strict_types=1);

namespace Modules\Product\App\Filament\Resources\ManualLockPasswordResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Product\App\Filament\Resources\ManualLockPasswordResource;
use Modules\Product\App\Models\Product;

class CreateManualLockPassword extends CreateRecord
{
    protected static string $resource = ManualLockPasswordResource::class;

    protected function afterCreate(): void
    {
        $this->markProductsAsManualLock();
        $this->logAssignedProducts();
    }

    // 'products' là Select ->relationship() — pivot được Filament tự đồng bộ ở saveRelationships(),
    // sau khi record đã tồn tại, nên không có Eloquent event nào bắn ra để ghi log. Ghi 1 dòng tóm
    // tắt phòng được gán ngay từ lúc tạo, cùng nguyên tắc với EditManualLockPassword::afterSave().
    private function logAssignedProducts(): void
    {
        $record    = $this->record->fresh(['products']);
        $productIds = $record->products->pluck('id')->all();

        if (empty($productIds)) {
            return;
        }

        AuditLogger::log(
            action: 'update',
            module: 'ManualLockPassword',
            record: $record,
            old: [],
            new: ['phong_da_them' => Product::whereIn('id', $productIds)->pluck('name')->implode(', ')],
            label: ($record->name ?? '#' . $record->id) . ' — Gán phòng áp dụng',
        );
    }

    /**
     * Đánh dấu các phòng được chọn là dùng khóa thủ công.
     */
    private function markProductsAsManualLock(): void
    {
        $record = $this->record->fresh(['products']);

        $record->products->each(function ($product) {
            $product->update(['has_manual_lock' => true]);
        });
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
