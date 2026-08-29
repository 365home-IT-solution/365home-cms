<?php

declare(strict_types=1);

namespace Modules\Book\App\Filament\Resources\PriceBoardResource\Concerns;

use App\Services\PriceBoardSyncService;
use Modules\Product\App\Models\PriceBoard;

/**
 * Uỷ quyền việc lưu dữ liệu phòng/giá của form PriceBoardResource cho
 * App\Services\PriceBoardSyncService::saveItems() — logic thật nằm ở service để các nút "Áp dụng
 * hàng loạt" trong PriceBoardForm cũng gọi được trực tiếp (ghi thẳng xuống DB, không qua vòng
 * Livewire dehydrate/submit của trang Sửa — xem ghi chú ở PriceBoardSyncService::saveItems()).
 */
trait SavesPriceBoardItems
{
    private function savePriceBoardItems(PriceBoard $board, array $data): void
    {
        app(PriceBoardSyncService::class)->saveItems($board, $data);
    }
}
