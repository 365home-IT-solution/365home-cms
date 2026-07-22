<?php

declare(strict_types=1);

namespace Modules\Dashboard\App\Services;

use Modules\Product\App\Models\Product;

// Tóm tắt tình trạng dọn vệ sinh để hiển thị ngay trên Dashboard — cùng luật phân quyền với
// RoomHousekeepingResource (Modules/Product/App/Filament/Resources/RoomHousekeepingResource.php):
// chủ đối tác/super_admin thấy toàn bộ phòng của đối tác, nhân viên không "làm tất cả chi
// nhánh" chỉ thấy phòng thuộc đúng chi nhánh mình phụ trách.
class HousekeepingSummaryService
{
    public static function getData($user = null): array
    {
        if ($user === null) {
            $user = auth()->user();
        }

        $query = Product::where('is_activated', true);

        if ($user && ! $user->isSuperAdmin() && ! $user->isPartnerOwner()) {
            $employee = $user->employee;

            if ($employee && ! $employee->works_all_branches) {
                $branchIds = $employee->workBranches()->pluck('categories.id');

                $query->whereHas('categories', function ($q) use ($branchIds) {
                    $q->where('category_type', 'product')->whereIn('categories.id', $branchIds);
                });
            }
        }

        $cleaningRooms = (clone $query)
            ->where('housekeeping_status', 'cleaning')
            ->with(['categories' => fn ($q) => $q->where('category_type', 'product')])
            ->get()
            ->map(function (Product $product) {
                $log = $product->cleaningLogs()->whereNull('cleaned_at')->first();

                return [
                    'id'        => $product->id,
                    'name'      => $product->name,
                    'branch'    => $product->categories->first()?->name ?? '—',
                    'since'     => $log?->marked_for_cleaning_at?->diffForHumans(),
                ];
            })
            ->values()
            ->toArray();

        return [
            'cleaning_count' => count($cleaningRooms),
            'cleaning_rooms' => $cleaningRooms,
        ];
    }
}
