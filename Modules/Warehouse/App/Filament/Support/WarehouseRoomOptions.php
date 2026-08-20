<?php

declare(strict_types=1);

namespace Modules\Warehouse\App\Filament\Support;

use Illuminate\Support\Collection;
use Modules\Category\Entities\Category;

// Chi nhánh + danh sách phòng mà user hiện tại được phép thao tác — dùng cho lưới "menu" chọn
// phòng theo cột chi nhánh ở WarehouseStockOutForm (xem
// warehouse::filament.forms.room-branch-picker). Tái dùng User::rootProductCategoryIds() — cùng
// nguồn xác thực quyền chi nhánh mà CouponResource/DataPermission đang dùng, không tự chế lại.
class WarehouseRoomOptions
{
    public static function branchesWithRooms(): Collection
    {
        $branchIds = auth()->user()?->rootProductCategoryIds() ?? [];

        if (empty($branchIds)) {
            return collect();
        }

        return Category::query()
            ->whereIn('id', $branchIds)
            ->where('category_type', 'product')
            ->orderBy('name')
            ->with(['products' => fn ($query) => $query
                ->where('is_activated', true)
                ->orderBy('name')
                ->select('products.id', 'products.name'),
            ])
            ->get(['id', 'name']);
    }
}
