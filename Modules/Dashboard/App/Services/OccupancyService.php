<?php

declare(strict_types=1);

namespace Modules\Dashboard\App\Services;

use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Modules\Category\Entities\Category;
use Modules\Payment\Entities\OrderItem;

/**
 * CÔNG SUẤT PHÒNG (tỉ lệ lấp đầy) theo kỳ — tính theo SỐ PHÒNG CÓ ĐƠN, dựa trên NGÀY Ở THỰC TẾ
 * (order_items.checkin_date/checkout_date), KHÔNG dùng orders.created_at (ngày đặt) — 1 đơn đặt
 * ngày 1/7 nhưng ở từ 20/7-25/7 phải tính là "có đặt" vào các ngày 20-25/7, không phải ngày 1/7.
 * Cùng cơ sở dữ liệu (checkin/checkout range overlap) với RoomController::dailyBookedDates() và
 * OverviewService::computeOccupiedDays() — nhưng khác 2 điểm:
 *  - KHÔNG cộng dồn theo room-nights như occupancyTrend() (occupied nights / tổng nights) — chỉ
 *    đếm SỐ PHÒNG có phủ ngày đó, mỗi phòng tính tối đa 1 lần/ngày dù có nhiều đơn.
 *  - KHÔNG bỏ sót đặt theo khung giờ không qua đêm (checkin/checkout cùng 1 ngày) như
 *    computeOccupiedDays() đang bị (do method đó ép cả 2 mốc về startOfDay() rồi so sánh <=).
 * Chuỗi theo ngày/tháng CỘNG DỒN từ đầu kỳ (mỗi điểm = luỹ kế đến hết ngày/tháng đó, không phải
 * riêng ngày/tháng đó) — đường luôn đi lên hoặc đi ngang, không giảm; điểm cuối cùng của series
 * luôn bằng đúng `rate_pct` tổng (= % phòng có ít nhất 1 ngày được đặt trong TOÀN kỳ đã chọn).
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

        // Không lọc theo categories/branch_id (branchCategoryIds === null) → gộp chung 1 mục "all"
        // (dùng luôn số tổng đã tính ở trên, không cần query lại) thay vì liệt kê từng chi nhánh —
        // chỉ khi có chọn cụ thể 1/nhiều chi nhánh mới trả breakdown thật theo TỪNG chi nhánh đó.
        $byBranch = $branchCategoryIds === null
            ? [[
                'id'          => null,
                'name'        => 'all',
                'total_rooms' => count($productIds),
                'rate_pct'    => $trend['rate_pct'],
            ]]
            : static::byBranch($user, $branchCategoryIds, $start, $end);

        return [
            'filter'      => $period,
            'date_range'  => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
            'total_rooms' => count($productIds),
            'rate_pct'    => $trend['rate_pct'],
            'series'      => $trend['series'],
            'by_branch'   => $byBranch,
        ];
    }

    /**
     * rate_pct = số phòng (trong $productIds) có ≥1 NGÀY được đặt phủ trong [$start,$end] / tổng
     *            số phòng — dựa theo checkin_date/checkout_date, không phải created_at.
     * series[i].pct = số phòng có đặt phủ đúng ngày/tháng đó / tổng số phòng (không cộng dồn).
     */
    private static function occupancyByOrderCount(array $productIds, Carbon $start, Carbon $end): array
    {
        $totalRooms = count($productIds);
        if ($totalRooms === 0) {
            return ['rate_pct' => 0, 'series' => []];
        }

        // Ngày trong tương lai chưa thể biết có khách hay không — giới hạn vế cuối về "hiện tại",
        // cùng nguyên tắc với OverviewService::occupancyTrend()/occupancyTop().
        $rangeEnd = Carbon::now()->lt($end) ? Carbon::now()->endOfDay() : $end;
        if ($rangeEnd->lt($start)) {
            return ['rate_pct' => 0, 'series' => []];
        }

        $items = OrderItem::query()
            ->whereIn('product_id', $productIds)
            ->whereNotNull('checkin_date')
            ->whereNotNull('checkout_date')
            // Overlap khoảng ở thực tế với [$start, $rangeEnd] — KHÔNG liên quan tới lúc đơn được tạo.
            ->where('checkin_date', '<', $rangeEnd->copy()->addDay())
            ->where('checkout_date', '>', $start)
            ->whereHas('order', fn ($o) => $o->where('exclude_from_stats', false)
                ->whereNotIn('status', self::EXCLUDED_STATUSES))
            ->get(['id', 'product_id', 'checkin_date', 'checkout_date']);

        $allRoomIds    = [];
        $bucketRoomIds = []; // [bucketKey => [product_id => true]]
        $byMonth       = max(1, (int) $start->diffInDays($rangeEnd) + 1) > self::MAX_DAYS_FOR_DAILY_BREAKDOWN;

        foreach ($items as $item) {
            $from = $item->checkin_date->max($start)->startOfDay();
            $to   = $item->checkout_date->min($rangeEnd->copy()->addDay())->startOfDay();

            if ($to->lt($from)) {
                continue;
            }
            if ($to->eq($from)) {
                // checkout cùng ngày checkin (đặt theo khung giờ, không qua đêm) — vẫn tính đúng
                // 1 ngày đó là "có đặt", khác với OverviewService::computeOccupiedDays() đang bỏ
                // sót trường hợp này do so sánh <=.
                $to = $from->copy()->addDay();
            }

            $allRoomIds[$item->product_id] = true;

            foreach (CarbonPeriod::create($from, '1 day', $to->copy()->subDay()) as $day) {
                $bucketKey = $byMonth ? $day->format('Y-m') : $day->toDateString();
                $bucketRoomIds[$bucketKey][$item->product_id] = true;
            }
        }

        // Luỹ kế theo thời gian: mỗi điểm = SỐ PHÒNG đã có đơn TÍNH TỪ ĐẦU KỲ đến hết ngày/tháng
        // đó (không phải riêng ngày/tháng đó) — nên đường luôn đi lên hoặc đi ngang, không bao giờ
        // giảm, và điểm sau luôn ≥ điểm ngay trước nó.
        $cumulativeRoomIds = [];
        $series            = [];

        if ($byMonth) {
            foreach (CarbonPeriod::create($start->copy()->startOfMonth(), '1 month', $rangeEnd) as $m) {
                $key = $m->format('Y-m');
                foreach ($bucketRoomIds[$key] ?? [] as $productId => $_) {
                    $cumulativeRoomIds[$productId] = true;
                }
                $series[] = ['date' => $key, 'pct' => round((count($cumulativeRoomIds) / $totalRooms) * 100, 2)];
            }
        } else {
            foreach (CarbonPeriod::create($start, '1 day', $rangeEnd) as $day) {
                $key = $day->toDateString();
                foreach ($bucketRoomIds[$key] ?? [] as $productId => $_) {
                    $cumulativeRoomIds[$productId] = true;
                }
                $series[] = ['date' => $key, 'pct' => round((count($cumulativeRoomIds) / $totalRooms) * 100, 2)];
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
