<?php

declare(strict_types=1);

namespace Modules\Dashboard\App\Services;

use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Modules\Category\Entities\Category;
use Modules\Payment\Entities\OrderItem;

/**
 * CÔNG SUẤT PHÒNG (tỉ lệ lấp đầy) theo kỳ — tính theo SỐ ĐƠN: % phòng CÓ ÍT NHẤT 1 ĐƠN đặt
 * (created_at rơi vào kỳ/ngày đang xét, trạng thái hợp lệ) trên tổng số phòng — khác với cách
 * tính theo số NGÀY thực tế có khách ở (occupied room-nights) đang dùng ở
 * OverviewService::occupancyTrend() (block "Công suất phòng" của GET /dashboard/overview).
 * Chuỗi theo ngày/tháng KHÔNG cộng dồn — mỗi điểm là % phòng có đơn đặt riêng trong đúng
 * ngày/tháng đó; `rate_pct` tổng là % phòng có ít nhất 1 đơn trong TOÀN kỳ đã chọn.
 */
class OccupancyService
{
    // Trạng thái KHÔNG tính là "có đặt" — khớp enum thật của orders.status (xem migration
    // 2026_07_22_000001_add_order_status_lifecycle_to_orders_table): pending/deposit/paid vẫn
    // tính là phòng đã được đặt (dù chưa/đã thanh toán xong), chỉ loại các đơn không thành.
    private const EXCLUDED_STATUSES = ['failed', 'cancelled_payment', 'refunded'];

    /** Số ngày tối đa để vẫn chia theo TỪNG NGÀY; dài hơn thì gộp theo THÁNG (vd: năm nay/năm trước) */
    private const MAX_DAYS_FOR_DAILY_BREAKDOWN = 62;

    public static function getData($user, string $period, ?string $customStart = null, ?string $customEnd = null, ?array $branchCategoryIds = null): array
    {
        [$start, $end] = OverviewService::resolveRange($period, $customStart, $customEnd);

        $productIds = OverviewService::scopedProductIds($user, $branchCategoryIds);
        $trend      = static::occupancyByOrderCount($productIds, $start, $end);

        return [
            'filter'      => $period,
            'date_range'  => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
            'total_rooms' => count($productIds),
            'rate_pct'    => $trend['rate_pct'],
            'series'      => $trend['series'],
            'by_branch'   => static::byBranch($user, $branchCategoryIds, $start, $end),
        ];
    }

    /**
     * rate_pct = số phòng (trong $productIds) có ≥1 đơn hợp lệ trong [$start,$end] / tổng số phòng.
     * series[i].pct = số phòng có đơn đặt RIÊNG trong ngày/tháng đó / tổng số phòng (không cộng dồn).
     */
    private static function occupancyByOrderCount(array $productIds, Carbon $start, Carbon $end): array
    {
        $totalRooms = count($productIds);
        if ($totalRooms === 0) {
            return ['rate_pct' => 0, 'series' => []];
        }

        $items = OrderItem::query()
            ->whereIn('product_id', $productIds)
            ->whereHas('order', fn ($o) => $o->where('exclude_from_stats', false)
                ->whereNotIn('status', self::EXCLUDED_STATUSES)
                ->whereBetween('created_at', [$start, $end]))
            ->with(['order:id,created_at'])
            ->get(['id', 'product_id', 'order_id']);

        $allRoomIds    = [];
        $bucketRoomIds = []; // [bucketKey => [product_id => true]]
        $byMonth       = max(1, (int) $start->diffInDays($end) + 1) > self::MAX_DAYS_FOR_DAILY_BREAKDOWN;

        foreach ($items as $item) {
            $createdAt = $item->order?->created_at;
            if (! $createdAt) {
                continue;
            }

            $allRoomIds[$item->product_id] = true;

            $bucketKey = $byMonth ? $createdAt->format('Y-m') : $createdAt->toDateString();
            $bucketRoomIds[$bucketKey][$item->product_id] = true;
        }

        $series = [];
        if ($byMonth) {
            foreach (CarbonPeriod::create($start->copy()->startOfMonth(), '1 month', $end) as $m) {
                $key      = $m->format('Y-m');
                $count    = count($bucketRoomIds[$key] ?? []);
                $series[] = ['date' => $key, 'pct' => round(($count / $totalRooms) * 100, 2)];
            }
        } else {
            foreach (CarbonPeriod::create($start, '1 day', $end) as $day) {
                $key      = $day->toDateString();
                $count    = count($bucketRoomIds[$key] ?? []);
                $series[] = ['date' => $key, 'pct' => round(($count / $totalRooms) * 100, 2)];
            }
        }

        return [
            'rate_pct' => round((count($allRoomIds) / $totalRooms) * 100, 2),
            'series'   => $series,
        ];
    }

    /** Công suất (theo số đơn) riêng từng chi nhánh (category gốc) trong cùng khoảng [start, end] */
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

            $ratePct = $totalRooms > 0 ? static::occupancyByOrderCount($productIds, $start, $end)['rate_pct'] : 0;

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
