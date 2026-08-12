<?php

declare(strict_types=1);

namespace Modules\Dashboard\App\Services;

use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;
use Modules\Category\Entities\Category;
use Modules\Payment\Entities\Order;
use Modules\Payment\Entities\OrderItem;
use Modules\Product\App\Models\Product;
use Modules\Product\App\Models\RoomType;

/**
 * Tổng hợp dữ liệu cho trang "Tổng quan" admin — 7 block, MỖI block lọc kỳ
 * (hôm nay/tuần này/tháng này/năm nay...) ĐỘC LẬP với nhau, nhưng vẫn gộp
 * chung trong 1 API duy nhất:
 *   Kinh doanh, Lễ tân, Đặt phòng, Công suất phòng, Top 5 công suất,
 *   Doanh thu, Top 5 doanh thu.
 */
class OverviewService
{
    private const ACTIVE_ORDER_STATUSES = ['pending', 'deposit', 'paid', 'shipping', 'completed'];
    private const PAID_STATUSES = ['paid', 'completed'];
    private const CHANNEL_LABELS = ['PayOS' => 'PayOS', 'cod' => 'Tiền mặt (COD)'];

    /** Số ngày tối đa để vẫn chia theo TỪNG NGÀY; dài hơn thì gộp theo THÁNG (vd: năm nay/năm trước) */
    private const MAX_DAYS_FOR_DAILY_BREAKDOWN = 62;

    public static function getOverview($user = null, array $params = []): array
    {
        if ($user === null) {
            $user = auth()->user();
        }

        $branchId          = isset($params['branch_id']) ? (int) $params['branch_id'] : null;
        $branchCategoryIds = $branchId ? static::expandBranchIds($branchId) : null;
        $productIds        = static::scopedProductIds($user, $branchCategoryIds);

        [$businessStart, $businessEnd]     = static::resolveRange($params['business_period'] ?? 'today', $params['business_start'] ?? null, $params['business_end'] ?? null);
        [$frontDeskStart, $frontDeskEnd]   = static::resolveRange($params['front_desk_period'] ?? 'today', $params['front_desk_start'] ?? null, $params['front_desk_end'] ?? null);
        [$bookingStart, $bookingEnd]       = static::resolveRange($params['booking_period'] ?? 'today', $params['booking_start'] ?? null, $params['booking_end'] ?? null);
        [$occupancyStart, $occupancyEnd]   = static::resolveRange($params['occupancy_period'] ?? 'this_month', $params['occupancy_start'] ?? null, $params['occupancy_end'] ?? null);
        [$occTopStart, $occTopEnd]         = static::resolveRange($params['occupancy_top_period'] ?? 'this_month', $params['occupancy_top_start'] ?? null, $params['occupancy_top_end'] ?? null);
        [$revenueStart, $revenueEnd]       = static::resolveRange($params['revenue_period'] ?? 'this_month', $params['revenue_start'] ?? null, $params['revenue_end'] ?? null);
        [$revTopStart, $revTopEnd]         = static::resolveRange($params['revenue_top_period'] ?? 'this_month', $params['revenue_top_start'] ?? null, $params['revenue_top_end'] ?? null);

        return [
            'branches'      => static::branchOptions($user),
            'business'      => static::business($user, $branchCategoryIds, $businessStart, $businessEnd),
            'front_desk'    => static::frontDesk($productIds, $frontDeskStart, $frontDeskEnd),
            'booking'       => static::booking($user, $branchCategoryIds, $bookingStart, $bookingEnd),
            'occupancy'     => static::occupancyTrend($productIds, $occupancyStart, $occupancyEnd),
            'occupancy_top' => static::occupancyTop($productIds, $occTopStart, $occTopEnd),
            'revenue'       => static::revenueTotal($productIds, $revenueStart, $revenueEnd),
            'revenue_top'   => static::revenueTop($productIds, $revTopStart, $revTopEnd),
        ];
    }

    /** KINH DOANH: số hoá đơn + doanh thu, thu - chi, trung bình phòng/ngày */
    private static function business($user, ?array $branchCategoryIds, Carbon $start, Carbon $end): array
    {
        $query = static::baseOrderQuery($user, $branchCategoryIds)
            ->whereBetween('created_at', [$start, $end]);

        $invoiceQuery   = (clone $query)->whereIn('status', self::PAID_STATUSES);
        $invoiceCount   = (clone $invoiceQuery)->count();
        $invoiceAmount  = static::sumOrderAmount(clone $invoiceQuery);

        $thu = static::sumOrderAmount((clone $query)->where('status', 'paid'))
             + (clone $query)->where('status', 'deposit')->whereNotNull('money_deposit')->sum('money_deposit');

        // Không có sổ chi phí riêng trong hệ thống; "chi" tạm tính bằng tổng tiền hoàn (refund).
        $chi = (int) (clone $query)->where('status', 'refunded')->sum(DB::raw('COALESCE(full_amount, amount)'));

        $days = max(1, (int) $start->diffInDays($end) + 1);
        $avgRoomsPerDay = round((clone $query)
            ->whereIn('status', self::ACTIVE_ORDER_STATUSES)
            ->whereHas('items')
            ->withCount('items')
            ->get()
            ->sum('items_count') / $days, 1);

        return [
            'period'             => static::periodMeta($start, $end),
            'invoice_count'      => $invoiceCount,
            'invoice_amount'     => $invoiceAmount,
            'thu'                => (int) $thu,
            'chi'                => $chi,
            'thu_chi'            => (int) $thu - $chi,
            'avg_rooms_per_day'  => $avgRoomsPerDay,
        ];
    }

    /**
     * LỄ TÂN: đã nhận / đã trả trong kỳ đã chọn; "có khách"/"ở quá giờ" luôn là
     * ảnh chụp TỨC THỜI tại thời điểm gọi API — chọn kỳ khác "hôm nay" không đổi 2 số này,
     * vì bản chất chúng là trạng thái đang diễn ra, không phải số liệu lịch sử.
     */
    private static function frontDesk(array $productIds, Carbon $start, Carbon $end): array
    {
        $totalRooms = count($productIds);
        if ($totalRooms === 0) {
            return ['period' => static::periodMeta($start, $end), 'checked_in_count' => 0, 'checked_out_count' => 0, 'occupied_rooms' => 0, 'total_rooms' => 0, 'overstay_rooms' => 0];
        }

        $today = Carbon::today();
        $now   = Carbon::now();

        $orderScope = fn ($q) => $q->whereIn('product_id', $productIds)
            ->whereHas('order', fn ($o) => $o->where('exclude_from_stats', false));

        $checkedInCount = Order::query()
            ->where('exclude_from_stats', false)
            ->whereHas('items', fn ($q) => $q->whereIn('product_id', $productIds))
            ->whereBetween('checked_in_at', [$start, $end])
            ->count();

        // Chưa có cột "checked_out_at"/"order_status" (migration lifecycle chưa chạy trên DB này),
        // nên "đã trả" tạm tính theo lịch checkout_date của order_items rơi vào trong kỳ.
        $checkedOutCount = OrderItem::query()
            ->tap($orderScope)
            ->whereBetween('checkout_date', [$start, $end])
            ->distinct('order_id')
            ->count('order_id');

        $occupiedRooms = OrderItem::query()
            ->tap($orderScope)
            ->where('checkin_date', '<=', $now)
            ->where('checkout_date', '>=', $now)
            ->distinct('product_id')
            ->count('product_id');

        // "Ở quá giờ": đã nhận phòng, giờ trả phòng dự kiến hôm nay đã qua nhưng vẫn còn hiệu lực đơn.
        $overstayRooms = OrderItem::query()
            ->tap($orderScope)
            ->where('checkin_date', '<=', $now)
            ->where('checkout_date', '>=', $today->copy()->startOfDay())
            ->where('checkout_date', '<', $now)
            ->distinct('product_id')
            ->count('product_id');

        return [
            'period'            => static::periodMeta($start, $end),
            'checked_in_count'  => $checkedInCount,
            'checked_out_count' => $checkedOutCount,
            'occupied_rooms'    => $occupiedRooms,
            'total_rooms'       => $totalRooms,
            'overstay_rooms'    => $overstayRooms,
        ];
    }

    /** ĐẶT PHÒNG: đặt phòng mới, đặt phòng huỷ (theo created_at trong kỳ) */
    private static function booking($user, ?array $branchCategoryIds, Carbon $start, Carbon $end): array
    {
        $query = static::baseOrderQuery($user, $branchCategoryIds)
            ->whereBetween('created_at', [$start, $end]);

        $newCount       = (clone $query)->whereNotIn('status', ['cancelled', 'failed'])->count();
        $cancelledCount = (clone $query)->whereIn('status', ['cancelled', 'failed'])->count();

        return [
            'period'          => static::periodMeta($start, $end),
            'new_count'       => $newCount,
            'cancelled_count' => $cancelledCount,
        ];
    }

    /** CÔNG SUẤT PHÒNG: tỉ lệ lấp đầy tổng + theo ngày/tháng trong kỳ (không gồm top 5 — xem occupancyTop) */
    private static function occupancyTrend(array $productIds, Carbon $start, Carbon $end): array
    {
        $totalRooms = count($productIds);
        $rangeEnd   = Carbon::now()->lt($end) ? Carbon::now()->endOfDay() : $end;

        if ($totalRooms === 0 || $rangeEnd->lt($start)) {
            return ['period' => static::periodMeta($start, $end), 'rate_pct' => 0, 'series' => []];
        }

        [$occupiedDays, $totalDays] = static::computeOccupiedDays($productIds, $start, $rangeEnd);

        $dailyCounts = [];
        foreach ($occupiedDays as $days) {
            foreach (array_keys($days) as $date) {
                $dailyCounts[$date] = ($dailyCounts[$date] ?? 0) + 1;
            }
        }

        $byMonth = $totalDays > self::MAX_DAYS_FOR_DAILY_BREAKDOWN;
        $series  = [];
        $sumOccupied = 0;

        if ($byMonth) {
            // Gộp theo tháng: % lấp đầy = tổng ngày-phòng đã đặt / (số phòng * số ngày trong tháng)
            $monthBuckets = [];
            foreach (CarbonPeriod::create($start->copy()->startOfMonth(), '1 month', $rangeEnd) as $m) {
                $bucketStart = $m->copy()->startOfMonth()->max($start);
                $bucketEnd   = $m->copy()->endOfMonth()->min($rangeEnd);
                $monthBuckets[$m->format('Y-m')] = ['occupied' => 0, 'days' => (int) $bucketStart->diffInDays($bucketEnd) + 1];
            }
            foreach ($dailyCounts as $date => $count) {
                $key = substr($date, 0, 7);
                if (isset($monthBuckets[$key])) {
                    $monthBuckets[$key]['occupied'] += $count;
                }
                $sumOccupied += $count;
            }
            foreach ($monthBuckets as $key => $bucket) {
                $series[] = [
                    'date' => $key,
                    'pct'  => $bucket['days'] > 0 ? round(($bucket['occupied'] / ($totalRooms * $bucket['days'])) * 100, 2) : 0,
                ];
            }
        } else {
            foreach (CarbonPeriod::create($start, '1 day', $rangeEnd) as $day) {
                $count = $dailyCounts[$day->toDateString()] ?? 0;
                $sumOccupied += $count;
                $series[] = [
                    'date' => $day->toDateString(),
                    'pct'  => round(($count / $totalRooms) * 100, 2),
                ];
            }
        }

        $ratePct = round(($sumOccupied / ($totalRooms * $totalDays)) * 100, 2);

        return [
            'period'   => static::periodMeta($start, $end),
            'rate_pct' => $ratePct,
            'series'   => $series,
        ];
    }

    /**
     * TOP 5 CÔNG SUẤT: tỉ lệ lấp đầy theo hạng phòng (room_type) / khu vực trong kỳ (lọc kỳ riêng
     * với occupancyTrend). Public — tái dùng bởi DashboardController::occupancyTop()
     * (GET /api/admin/dashboard/occupancy-top) để có endpoint riêng kèm categories/sort, không
     * phải gọi cả getOverview() (tính thêm 6 block khác không cần).
     */
    public static function occupancyTop(array $productIds, Carbon $start, Carbon $end, bool $descending = true): array
    {
        $totalRooms = count($productIds);
        $rangeEnd   = Carbon::now()->lt($end) ? Carbon::now()->endOfDay() : $end;

        if ($totalRooms === 0 || $rangeEnd->lt($start)) {
            return ['period' => static::periodMeta($start, $end), 'top_by_room_type' => [], 'top_by_area' => []];
        }

        [$occupiedDays, $totalDays] = static::computeOccupiedDays($productIds, $start, $rangeEnd);

        $productMeta = Product::with(['roomType', 'categories.parent'])
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        $roomTypeAgg = [];
        $areaAgg = [];
        foreach ($productMeta as $productId => $product) {
            $occupiedDayCount = count($occupiedDays[$productId] ?? []);

            $roomTypeName = $product->roomType->name ?? 'Khác';
            $roomTypeAgg[$roomTypeName]['occupied'] = ($roomTypeAgg[$roomTypeName]['occupied'] ?? 0) + $occupiedDayCount;
            $roomTypeAgg[$roomTypeName]['rooms']     = ($roomTypeAgg[$roomTypeName]['rooms'] ?? 0) + 1;

            $category   = $product->categories->first();
            $parent     = $category?->parent;
            $areaName   = $parent->name ?? $category->name ?? 'Chưa phân loại';
            $areaAgg[$areaName]['occupied'] = ($areaAgg[$areaName]['occupied'] ?? 0) + $occupiedDayCount;
            $areaAgg[$areaName]['rooms']    = ($areaAgg[$areaName]['rooms'] ?? 0) + 1;
        }

        $buildTop = function (array $agg) use ($totalDays, $descending) {
            $rows = collect($agg)->map(fn ($v, $name) => [
                'name' => $name,
                'pct'  => $v['rooms'] > 0 ? round(($v['occupied'] / ($v['rooms'] * $totalDays)) * 100, 2) : 0,
            ])->values();

            return ($descending ? $rows->sortByDesc('pct') : $rows->sortBy('pct'))->take(5)->values()->toArray();
        };

        return [
            'period'           => static::periodMeta($start, $end),
            'top_by_room_type' => $buildTop($roomTypeAgg),
            'top_by_area'      => $buildTop($areaAgg),
        ];
    }

    /**
     * Tính tập ngày mỗi phòng đã có khách trong [$start, $rangeEnd].
     *
     * @return array{0: array<int, array<string, bool>>, 1: int} [occupiedDays[product_id][Y-m-d]=true, totalDays]
     */
    private static function computeOccupiedDays(array $productIds, Carbon $start, Carbon $rangeEnd): array
    {
        $items = OrderItem::query()
            ->whereIn('product_id', $productIds)
            ->whereHas('order', fn ($o) => $o->where('exclude_from_stats', false)->whereNotIn('status', ['cancelled', 'failed']))
            ->whereNotNull('checkin_date')
            ->whereNotNull('checkout_date')
            ->where('checkin_date', '<', $rangeEnd)
            ->where('checkout_date', '>', $start)
            ->get(['product_id', 'checkin_date', 'checkout_date']);

        // occupiedDays[product_id][Y-m-d] = true — dùng để loại trùng ngày cùng 1 phòng bị đặt nhiều lần.
        $occupiedDays = [];
        foreach ($items as $item) {
            $from = $item->checkin_date->max($start)->startOfDay();
            $to   = $item->checkout_date->min($rangeEnd->copy()->addDay())->startOfDay();
            if ($to->lte($from)) {
                continue;
            }
            foreach (CarbonPeriod::create($from, '1 day', $to->copy()->subDay()) as $day) {
                $occupiedDays[$item->product_id][$day->toDateString()] = true;
            }
        }

        $totalDays = max(1, (int) $start->diffInDays($rangeEnd) + 1);

        return [$occupiedDays, $totalDays];
    }

    /** DOANH THU: tổng trong kỳ + theo ngày/tháng (không gồm top 5 — xem revenueTop) */
    private static function revenueTotal(array $productIds, Carbon $start, Carbon $end): array
    {
        if (empty($productIds)) {
            return ['period' => static::periodMeta($start, $end), 'invoice_count' => 0, 'total_amount' => 0, 'daily' => []];
        }

        $ordersQuery = static::paidOrdersQuery($productIds, $start, $end);

        $invoiceCount = (clone $ordersQuery)->count();
        $totalAmount  = static::sumOrderAmount(clone $ordersQuery);

        $totalDays = max(1, (int) $start->diffInDays($end) + 1);
        $byMonth   = $totalDays > self::MAX_DAYS_FOR_DAILY_BREAKDOWN;

        if ($byMonth) {
            $rows = (clone $ordersQuery)
                ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as d, SUM(COALESCE(full_amount, amount)) as amount")
                ->groupBy('d')
                ->pluck('amount', 'd');

            $daily = [];
            foreach (CarbonPeriod::create($start->copy()->startOfMonth(), '1 month', $end) as $m) {
                $key = $m->format('Y-m');
                $daily[] = ['date' => $key, 'amount' => (int) ($rows[$key] ?? 0)];
            }
        } else {
            $rows = (clone $ordersQuery)
                ->selectRaw('DATE(created_at) as d, SUM(COALESCE(full_amount, amount)) as amount')
                ->groupBy('d')
                ->pluck('amount', 'd');

            $daily = [];
            foreach (CarbonPeriod::create($start, '1 day', $end) as $day) {
                $daily[] = ['date' => $day->toDateString(), 'amount' => (int) ($rows[$day->toDateString()] ?? 0)];
            }
        }

        return [
            'period'        => static::periodMeta($start, $end),
            'invoice_count' => $invoiceCount,
            'total_amount'  => $totalAmount,
            'daily'         => $daily,
        ];
    }

    /** TOP 5 DOANH THU: theo hạng phòng / kênh bán trong kỳ (lọc kỳ riêng với revenueTotal) */
    private static function revenueTop(array $productIds, Carbon $start, Carbon $end): array
    {
        if (empty($productIds)) {
            return ['period' => static::periodMeta($start, $end), 'top_by_room_type' => [], 'top_by_channel' => []];
        }

        $ordersQuery = static::paidOrdersQuery($productIds, $start, $end);

        $channelRows = (clone $ordersQuery)
            ->selectRaw('payment_method, SUM(COALESCE(full_amount, amount)) as amount')
            ->groupBy('payment_method')
            ->pluck('amount', 'payment_method');

        $topByChannel = collect(self::CHANNEL_LABELS)
            ->map(fn ($label, $key) => ['name' => $label, 'amount' => (int) ($channelRows[$key] ?? 0)])
            ->filter(fn ($c) => $c['amount'] > 0)
            ->sortByDesc('amount')
            ->take(5)
            ->values()
            ->toArray();

        $itemTable = (new OrderItem)->getTable();
        $ordTable  = (new Order)->getTable();
        $prodTable = (new Product)->getTable();
        $prefix    = DB::getTablePrefix();

        $inner = DB::table("{$itemTable} as oi")
            ->join("{$ordTable} as o", 'oi.order_id', '=', 'o.id')
            ->join("{$prodTable} as p", 'oi.product_id', '=', 'p.id')
            ->where('o.status', 'paid')
            ->whereIn('o.payment_method', array_keys(self::CHANNEL_LABELS))
            ->where('o.exclude_from_stats', false)
            ->whereBetween('o.created_at', [$start, $end])
            ->whereIn('oi.product_id', $productIds)
            ->selectRaw("{$prefix}p.room_type_id, {$prefix}o.id as order_id, COALESCE({$prefix}o.full_amount, {$prefix}o.amount) as order_amount")
            ->distinct();

        $roomTypeRows = DB::table(DB::raw("({$inner->toSql()}) as sub"))
            ->mergeBindings($inner)
            ->selectRaw('room_type_id, SUM(order_amount) as revenue')
            ->groupBy('room_type_id')
            ->orderByDesc('revenue')
            ->limit(5)
            ->get();

        $roomTypeNames = RoomType::whereIn('id', $roomTypeRows->pluck('room_type_id')->filter())->pluck('name', 'id');

        $topByRoomType = $roomTypeRows->map(fn ($r) => [
            'name'   => $roomTypeNames[$r->room_type_id] ?? 'Khác',
            'amount' => (int) $r->revenue,
        ])->values()->toArray();

        return [
            'period'           => static::periodMeta($start, $end),
            'top_by_room_type' => $topByRoomType,
            'top_by_channel'   => $topByChannel,
        ];
    }

    /** Orders đã thanh toán (PayOS/COD) trong kỳ, giới hạn theo product scope — dùng chung cho revenueTotal/revenueTop */
    private static function paidOrdersQuery(array $productIds, Carbon $start, Carbon $end)
    {
        return Order::query()
            ->where('exclude_from_stats', false)
            ->where('status', 'paid')
            ->whereIn('payment_method', array_keys(self::CHANNEL_LABELS))
            ->whereHas('items', fn ($q) => $q->whereIn('product_id', $productIds))
            ->whereBetween('created_at', [$start, $end]);
    }

    private static function periodMeta(Carbon $start, Carbon $end): array
    {
        return ['start' => $start->toDateString(), 'end' => $end->toDateString()];
    }

    private static function baseOrderQuery($user, ?array $branchCategoryIds)
    {
        $query = Order::query()->where('exclude_from_stats', false);

        if ($user && ! $user->isSuperAdmin()) {
            $allowedCategoryIds = $user->allowedCategoryIds();
            if (empty($allowedCategoryIds)) {
                return $query->whereRaw('1 = 0');
            }
            $query->whereIn('category_id', $allowedCategoryIds);
        }

        if ($branchCategoryIds !== null) {
            $query->whereIn('category_id', $branchCategoryIds);
        }

        return $query;
    }

    /**
     * Danh sách product_id mà user được phép xem, giới hạn thêm theo chi nhánh nếu có chọn.
     * Public — tái dùng bởi OccupancyService để tính công suất riêng từng chi nhánh.
     */
    public static function scopedProductIds($user, ?array $branchCategoryIds): array
    {
        $query = Product::query()->where('is_activated', true);

        if ($user && ! $user->isSuperAdmin()) {
            if (empty($user->partner_id)) {
                return [];
            }

            // Mặc định thấy TOÀN BỘ chi nhánh của đối tác mình (partner_id) — KHÔNG bắt buộc phải
            // có bản ghi branchPermissions riêng (chủ đối tác thường không có bản ghi này, chỉ
            // nhân viên được giao hạn chế mới có). allowedCategoryIds() rỗng KHÔNG có nghĩa là
            // "không được xem gì" — trước đây return [] ở đây khiến chủ đối tác luôn thấy 0 phòng.
            $partnerCategoryIds = Category::where('partner_id', $user->partner_id)->pluck('id')->toArray();
            if (empty($partnerCategoryIds)) {
                return [];
            }
            $query->whereHas('categories', fn ($q) => $q->whereIn('categories.id', $partnerCategoryIds));

            $allowedCategoryIds = $user->allowedCategoryIds() ?? [];
            if (! empty($allowedCategoryIds)) {
                $query->whereHas('categories', fn ($q) => $q->whereIn('categories.id', $allowedCategoryIds));
            }
        }

        if ($branchCategoryIds !== null) {
            $query->whereHas('categories', fn ($q) => $q->whereIn('categories.id', $branchCategoryIds));
        }

        return $query->pluck('id')->toArray();
    }

    /** Mở rộng 1 chi nhánh (branch category) ra chính nó + toàn bộ danh mục con (khu vực/tầng...) */
    private static function expandBranchIds(int $branchId): array
    {
        $allIds = [$branchId];
        $currentLevel = [$branchId];

        while (! empty($currentLevel)) {
            $children = Category::whereIn('parent_id', $currentLevel)->pluck('id')->toArray();
            $children = array_diff($children, $allIds);
            if (empty($children)) {
                break;
            }
            $allIds = array_merge($allIds, $children);
            $currentLevel = $children;
        }

        return $allIds;
    }

    private static function branchOptions($user): array
    {
        $query = Category::whereNull('parent_id')->where('category_type', 'product')->orderBy('name');

        if ($user && ! $user->isSuperAdmin()) {
            $allowedIds = $user->allowedBranchIds() ?? [];
            if (empty($allowedIds)) {
                return [];
            }
            $query->whereIn('id', $allowedIds);
        }

        return $query->get(['id', 'name'])->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->toArray();
    }

    /**
     * Quy đổi 1 tham số "period" (áp dụng riêng cho từng block) thành khoảng [start, end].
     * Hỗ trợ: today, yesterday, this_week, last_week, this_month, last_month,
     *         this_year, last_year, 7d, 30d, 90d, custom (kèm $customStart/$customEnd).
     * Public — tái dùng bởi OccupancyService (GET /api/admin/dashboard/occupancy) để không tính
     * lại logic quy đổi kỳ lần thứ 4 trong codebase (đã có ở đây, KpiService, Dashboard.php).
     */
    public static function resolveRange(string $period, ?string $customStart, ?string $customEnd): array
    {
        if ($period === 'custom') {
            $start = $customStart ? Carbon::parse($customStart)->startOfDay() : Carbon::now()->subDays(29)->startOfDay();
            $end   = $customEnd ? Carbon::parse($customEnd)->endOfDay() : Carbon::now()->endOfDay();
            if ($start->gt($end)) {
                [$start, $end] = [$end, $start];
            }
            return [$start, $end];
        }

        return match ($period) {
            'yesterday'  => [Carbon::yesterday()->startOfDay(), Carbon::yesterday()->endOfDay()],
            'this_week'  => [Carbon::now()->startOfWeek()->startOfDay(), Carbon::now()->endOfDay()],
            'last_week'  => [Carbon::now()->subWeek()->startOfWeek()->startOfDay(), Carbon::now()->subWeek()->endOfWeek()->endOfDay()],
            'this_month' => [Carbon::now()->startOfMonth()->startOfDay(), Carbon::now()->endOfDay()],
            'last_month' => [Carbon::now()->subMonthNoOverflow()->startOfMonth()->startOfDay(), Carbon::now()->subMonthNoOverflow()->endOfMonth()->endOfDay()],
            'this_year'  => [Carbon::now()->startOfYear()->startOfDay(), Carbon::now()->endOfDay()],
            'last_year'  => [Carbon::now()->subYear()->startOfYear()->startOfDay(), Carbon::now()->subYear()->endOfYear()->endOfDay()],
            'ytd'        => [Carbon::today()->startOfYear()->startOfDay(), Carbon::now()->endOfDay()],
            '7d'         => [Carbon::now()->subDays(6)->startOfDay(), Carbon::now()->endOfDay()],
            '30d'        => [Carbon::now()->subDays(29)->startOfDay(), Carbon::now()->endOfDay()],
            '90d'        => [Carbon::now()->subDays(89)->startOfDay(), Carbon::now()->endOfDay()],
            default      => [Carbon::today()->startOfDay(), Carbon::today()->endOfDay()], // today
        };
    }

    /** Tổng tiền: ưu tiên full_amount (tổng thực tế), fallback amount */
    private static function sumOrderAmount($query): int
    {
        return (int) ($query->selectRaw('SUM(COALESCE(full_amount, amount)) as total')->value('total') ?? 0);
    }
}
