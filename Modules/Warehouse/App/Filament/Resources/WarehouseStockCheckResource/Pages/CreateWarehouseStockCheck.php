<?php

declare(strict_types=1);

namespace Modules\Warehouse\App\Filament\Resources\WarehouseStockCheckResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;
use Modules\Warehouse\App\Filament\Resources\WarehouseStockCheckResource;
use Modules\Warehouse\App\Filament\Resources\WarehouseStockCheckResource\Forms\WarehouseStockCheckForm;

class CreateWarehouseStockCheck extends CreateRecord
{
    protected static string $resource = WarehouseStockCheckResource::class;

    // Tự liệt kê TOÀN BỘ vật tư (đúng đối tác đang đăng nhập, super_admin thấy tất cả — xem
    // WarehouseItem's BelongsToPartner) ngay khi mở trang tạo phiếu — xem giải thích đầy đủ ở
    // WarehouseStockCheckForm (không dùng Repeater::default() vì bị relationship() ghi đè mất).
    protected function afterFill(): void
    {
        foreach (WarehouseStockCheckForm::allActiveItemsAsRows() as $row) {
            $this->data['items'][(string) Str::uuid()] = $row;
        }
    }
}
