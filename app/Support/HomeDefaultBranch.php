<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\Controllers\Api\SearchController;
use App\Models\Province;
use Modules\Category\Entities\Category;
use Modules\Product\App\Models\Product;

// Tỉnh + chi nhánh MẶC ĐỊNH của bảng đặt phòng trang chủ khi khách chưa chọn khu vực. Trước đây lấy
// thẳng tỉnh đầu tiên của AvailableProvinceService (sắp theo mã tỉnh => Lâm Đồng đứng trước Cần Thơ)
// và chi nhánh đầu tiên của tỉnh đó — Enjoy Villa chỉ cho thuê theo ngày, nên trang chủ mặc định
// hiện card villa thay vì lưới khung giờ. Giờ ưu tiên tỉnh/chi nhánh có phòng đặt theo KHUNG GIỜ
// (cùng điều kiện "bookable" với Modules\BladeThemeV1\Support\BranchBookConfig::build()); không tỉnh
// nào có thì giữ nguyên hành vi cũ. Thứ tự tỉnh trong danh sách chọn KHÔNG đổi (app dùng chung).
class HomeDefaultBranch
{
    /**
     * @param  array<int, array{id: int|string}>  $provinces  AvailableProvinceService::get()
     * @return array{province: ?Province, branches: array}  branches: cấu trúc của
     *         SearchController::branchesDataForProvince(), chi nhánh có khung giờ được đưa lên trước
     */
    public static function resolve(array $provinces): array
    {
        $fallback = null;

        foreach ($provinces as $row) {
            $province = Province::find($row['id']);
            if (! $province) {
                continue;
            }

            $data = SearchController::branchesDataForProvince($province);
            // branchesDataForProvince() trả 'data' là Collection — đưa về mảng thường để lọc/sắp lại.
            $branches = collect($data['data'] ?? [])->map(fn ($b) => (array) $b)->values()->all();
            $data['data'] = $branches;
            $fallback ??= ['province' => $province, 'branches' => $data];

            $hourly = array_values(array_filter($branches, fn ($b) => self::hasHourlyRooms((int) ($b['id'] ?? 0))));
            if ($hourly === []) {
                continue;
            }

            $others = array_values(array_filter($branches, fn ($b) => ! in_array($b, $hourly, true)));
            $data['data'] = array_merge($hourly, $others);

            return ['province' => $province, 'branches' => $data];
        }

        return $fallback ?? ['province' => null, 'branches' => ['data' => []]];
    }

    private static function hasHourlyRooms(int $branchId): bool
    {
        if ($branchId <= 0) {
            return false;
        }

        $categoryIds = array_merge([$branchId], Category::where('parent_id', $branchId)->pluck('id')->all());

        return Product::where('is_activated', true)
            ->where('is_in_stock', true)
            ->where(fn ($q) => $q->where('styles', 1)->orWhereNull('styles'))
            ->whereHas('categories', fn ($q) => $q->whereIn('categories.id', $categoryIds))
            ->whereHas('roomTimeSlots.timeSlot')
            ->exists();
    }
}
