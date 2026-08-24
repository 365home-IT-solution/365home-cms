<?php

declare(strict_types=1);

namespace Modules\Dashboard\App\Services\Report;

use Illuminate\Database\Eloquent\Builder;
use Modules\Payment\Entities\Order;
use Modules\Product\App\Models\Product;

/**
 * Scope dữ liệu theo user đang đăng nhập + chi nhánh được chọn (nếu có) — dùng chung cho mọi Report
 * Service. Cùng nguyên tắc phân quyền với Modules\Dashboard\App\Services\OverviewService/KpiService
 * (route API Sanctum không chạy trong Filament panel nên các global scope BelongsToPartner/
 * BelongsToBranch không tự áp dụng — phải lọc partner_id/branch thủ công ở đây).
 */
class ReportScope
{
    /**
     * Orders mà user được phép xem, giới hạn thêm theo chi nhánh nếu có chọn (branchCategoryIds
     * gồm cả category con — xem ResolvesReportFilters::resolveBranchCategoryIds()).
     */
    public static function orderQuery($user, ?array $branchCategoryIds): Builder
    {
        $query = Order::query()->where('exclude_from_stats', false);

        if ($user && ! $user->isSuperAdmin()) {
            if (empty($user->partner_id)) {
                return $query->whereRaw('1 = 0');
            }

            $query->where('partner_id', $user->partner_id);

            // allowedCategoryIds() rỗng KHÔNG có nghĩa "không được xem gì" — chủ đối tác thường
            // không có bản ghi UserBranchPermission riêng, chỉ nhân viên bị giao hạn chế mới có
            // (xem giải thích tương tự ở OverviewService::scopedProductIds()).
            $allowedCategoryIds = $user->allowedCategoryIds();
            if (! empty($allowedCategoryIds)) {
                $query->whereIn('category_id', $allowedCategoryIds);
            }
        }

        if ($branchCategoryIds !== null) {
            $query->whereIn('category_id', $branchCategoryIds);
        }

        return $query;
    }

    /** Danh sách product_id (phòng) mà user được phép xem, giới hạn thêm theo chi nhánh nếu có chọn. */
    public static function productIds($user, ?array $branchCategoryIds): array
    {
        return \Modules\Dashboard\App\Services\OverviewService::scopedProductIds($user, $branchCategoryIds);
    }

    public static function productQuery($user, ?array $branchCategoryIds): Builder
    {
        return Product::query()->whereIn('id', static::productIds($user, $branchCategoryIds));
    }

    /**
     * Id CHI NHÁNH GỐC mà user được phép xem, giới hạn thêm theo $explicitRootIds nếu FE có chọn
     * (rỗng = không chọn gì cụ thể, dùng toàn bộ chi nhánh được phép). Dùng cho các bảng kho
     * (warehouse_items.branch_id...) chỉ lưu id chi nhánh gốc, không lưu category con.
     */
    public static function branchIds($user, array $explicitRootIds): array
    {
        $allowed = $user ? $user->rootProductCategoryIds() : [];

        if (empty($explicitRootIds)) {
            return $allowed;
        }

        return array_values(array_intersect($explicitRootIds, $allowed));
    }
}
