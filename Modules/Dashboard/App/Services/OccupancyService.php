<?php

declare(strict_types=1);

namespace Modules\Dashboard\App\Services;

use Carbon\Carbon;
use Modules\Category\Entities\Category;

/**
 * CÔNG SUẤT PHÒNG (tỉ lệ lấp đầy) theo kỳ — % tổng + chuỗi theo ngày/tháng để vẽ biểu đồ đường,
 * kèm breakdown theo từng chi nhánh. Lọc theo `filter` (cùng vocabulary với KpiService) thay vì
 * theo NĂM như RankingService. Tính toán cốt lõi tái dùng nguyên OverviewService::occupancyTrend()
 * (logic đã dùng cho block "Công suất phòng" ở GET /api/admin/dashboard/overview) — không viết lại.
 */
class OccupancyService
{
    public static function getData($user, string $period, ?string $customStart = null, ?string $customEnd = null, ?array $branchCategoryIds = null): array
    {
        [$start, $end] = OverviewService::resolveRange($period, $customStart, $customEnd);

        $productIds = OverviewService::scopedProductIds($user, $branchCategoryIds);
        $trend      = OverviewService::occupancyTrend($productIds, $start, $end);

        return [
            'filter'      => $period,
            'date_range'  => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
            'total_rooms' => count($productIds),
            'rate_pct'    => $trend['rate_pct'],
            'series'      => $trend['series'],
            'by_branch'   => static::byBranch($user, $branchCategoryIds, $start, $end),
        ];
    }

    /** Công suất riêng từng chi nhánh (category gốc) trong cùng khoảng [start, end] đã chọn ở trên */
    private static function byBranch($user, ?array $branchCategoryIds, Carbon $start, Carbon $end): array
    {
        $branchesQuery = Category::whereNull('parent_id')->where('category_type', 'product')->orderBy('name');

        if ($user && ! $user->isSuperAdmin()) {
            if (empty($user->partner_id)) {
                return [];
            }
            $branchesQuery->where('partner_id', $user->partner_id);
        }

        if ($branchCategoryIds !== null) {
            $branchesQuery->whereIn('id', $branchCategoryIds);
        }

        $branches = $branchesQuery->get();

        if ($user && ! $user->isSuperAdmin()) {
            $allowedIds = $user->allowedCategoryIds() ?? [];
            // allowedCategoryIds chỉ thu hẹp thêm (nhân viên chỉ được giao 1 vài chi nhánh cụ
            // thể) — không áp dụng khi rỗng, vì đã lọc theo partner_id ở trên rồi.
            if (! empty($allowedIds)) {
                $branches = $branches->filter(function ($b) use ($allowedIds) {
                    $childIds = Category::where('parent_id', $b->id)->pluck('id')->toArray();

                    return count(array_intersect(array_merge([$b->id], $childIds), $allowedIds)) > 0;
                })->values();
            }
        }

        $result = [];
        foreach ($branches as $branch) {
            $childIds = Category::where('parent_id', $branch->id)->pluck('id')->toArray();
            $catIds   = array_merge([$branch->id], $childIds);

            // Truyền $catIds làm branchCategoryIds để scopedProductIds() tự thu hẹp đúng phòng
            // của riêng chi nhánh này (đã gồm sẵn logic phân quyền super_admin/partner/allowed).
            $productIds = OverviewService::scopedProductIds($user, $catIds);
            $totalRooms = count($productIds);

            $ratePct = $totalRooms > 0 ? OverviewService::occupancyTrend($productIds, $start, $end)['rate_pct'] : 0;

            $result[] = [
                'id'          => $branch->id,
                'name'        => $branch->name,
                'total_rooms' => $totalRooms,
                'rate_pct'    => $ratePct,
            ];
        }

        return $result;
    }
}
