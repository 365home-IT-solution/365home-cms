<?php

declare(strict_types=1);

namespace Modules\Product\App\Filament\Resources\ManualLockPasswordResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Product\App\Filament\Resources\ManualLockPasswordResource;

class CreateManualLockPassword extends CreateRecord
{
    protected static string $resource = ManualLockPasswordResource::class;

    protected function afterCreate(): void
    {
        $this->markProductsAsManualLock();
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
