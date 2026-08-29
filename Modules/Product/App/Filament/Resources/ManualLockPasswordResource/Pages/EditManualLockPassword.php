<?php

declare(strict_types=1);

namespace Modules\Product\App\Filament\Resources\ManualLockPasswordResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Product\App\Filament\Resources\ManualLockPasswordResource;
use Modules\Product\App\Models\Product;

class EditManualLockPassword extends EditRecord
{
    protected static string $resource = ManualLockPasswordResource::class;

    /** Danh sách product_id cũ trước khi lưu */
    protected array $oldProductIds = [];

    protected function beforeSave(): void
    {
        $this->oldProductIds = $this->record->products()->pluck('products.id')->toArray();
    }

    protected function afterSave(): void
    {
        $record = $this->record->fresh(['products']);
        $newProductIds = $record->products->pluck('id')->toArray();

        // Phòng vừa được thêm vào → đánh dấu has_manual_lock = true
        $added = array_diff($newProductIds, $this->oldProductIds);
        if ($added) {
            Product::whereIn('id', $added)->update(['has_manual_lock' => true]);
        }

        // Phòng bị gỡ ra → kiểm tra còn record nào khác không, nếu không thì bỏ cờ
        $removed = array_diff($this->oldProductIds, $newProductIds);
        foreach ($removed as $productId) {
            $stillLinked = \Modules\Product\App\Models\ManualLockPassword::whereHas(
                'products',
                fn ($q) => $q->where('products.id', $productId)
            )->exists();

            if (! $stillLinked) {
                Product::where('id', $productId)->update(['has_manual_lock' => false]);
            }
        }

        // 'products' là Select ->relationship() — pivot được Filament tự đồng bộ ở
        // saveRelationships(), không đi qua $record->update() nên không có Eloquent event nào bắn
        // ra để ghi log (cùng lỗi đã gặp ở Product tags/services). Ghi thủ công ở đây.
        if (empty($added) && empty($removed)) {
            return;
        }

        $old = [];
        $new = [];

        if (! empty($removed)) {
            $old['phong_da_bo'] = Product::whereIn('id', $removed)->pluck('name')->implode(', ');
        }
        if (! empty($added)) {
            $new['phong_da_them'] = Product::whereIn('id', $added)->pluck('name')->implode(', ');
        }

        AuditLogger::log(
            action: 'update',
            module: 'ManualLockPassword',
            record: $record,
            old: $old,
            new: $new,
            label: ($record->name ?? '#' . $record->id) . ' — Cập nhật phòng áp dụng',
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->before(function () {
                    // Khi xóa record, gỡ cờ cho các phòng không còn liên kết
                    $productIds = $this->record->products()->pluck('products.id')->toArray();
                    foreach ($productIds as $productId) {
                        $otherLinked = \Modules\Product\App\Models\ManualLockPassword::where('id', '!=', $this->record->id)
                            ->whereHas('products', fn ($q) => $q->where('products.id', $productId))
                            ->exists();

                        if (! $otherLinked) {
                            Product::where('id', $productId)->update(['has_manual_lock' => false]);
                        }
                    }
                }),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
