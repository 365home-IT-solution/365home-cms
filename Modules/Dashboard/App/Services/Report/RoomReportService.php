<?php

declare(strict_types=1);

namespace Modules\Dashboard\App\Services\Report;

use Illuminate\Support\Facades\DB;
use Modules\Dashboard\App\Services\OverviewService;
use Modules\Payment\Entities\OrderItem;
use Modules\Product\App\Models\Product;
use Modules\Product\App\Models\RoomType;

/**
 * BÁO CÁO PHÒNG: doanh thu theo hạng phòng, top 5 phòng đặt nhiều nhất, trạng thái buồng phòng
 * (đang sử dụng/đang dọn/trống), trong kỳ đã chọn (mặc định 7 ngày gần nhất). Trạng thái buồng
 * phòng là ảnh chụp TỨC THỜI (không đổi theo kỳ) — số phòng thuộc về TỒN KHO hiện tại, không phải
 * số liệu phát sinh trong kỳ.
 */
class RoomReportService
{
    public static function getData($user, string $filter, ?string $customStart, ?string $customEnd, ?array $branchCategoryIds): array
    {
        [$start, $end] = OverviewService::resolveRange($filter, $customStart, $customEnd);

        $productIds = ReportScope::productIds($user, $branchCategoryIds);

        return [
            'period'              => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
            'revenue_by_room_type' => static::revenueByRoomType($productIds, $start, $end),
            'top_rooms'           => static::topRooms($productIds, $start, $end),
            'room_status'         => static::roomStatus($productIds),
        ];
    }

    private static function revenueByRoomType(array $productIds, $start, $end): array
    {
        if (empty($productIds)) {
            return [];
        }

        // Laravel tự thêm tiền tố bảng (table prefix 'cms_'...) vào CẢ alias khi wrap 'table as alias'
        // (Grammar::wrapAliasedTable()), nên 'selectRaw' (SQL thô) phải tự ghép tiền tố vào alias mới
        // khớp — xem giải thích chi tiết ở EndOfDayReportService::warehouseExpense().
        $prefix = DB::getTablePrefix();

        $rows = DB::table('order_items as oi')
            ->join('orders as o', 'oi.order_id', '=', 'o.id')
            ->join('products as p', 'oi.product_id', '=', 'p.id')
            ->where('o.status', 'paid')
            ->where('o.exclude_from_stats', false)
            ->whereBetween('o.created_at', [$start, $end])
            ->whereIn('oi.product_id', $productIds)
            ->selectRaw("{$prefix}p.room_type_id, {$prefix}o.id as order_id, COALESCE({$prefix}o.amount, {$prefix}o.full_amount) as order_amount")
            ->distinct()
            ->get();

        $byType = [];
        foreach ($rows->groupBy('room_type_id') as $roomTypeId => $group) {
            $byType[$roomTypeId] = $group->unique('order_id')->sum('order_amount');
        }

        $names = RoomType::whereIn('id', array_keys($byType))->pluck('name', 'id');

        return collect($byType)
            ->map(fn ($amount, $roomTypeId) => [
                'room_type_id' => $roomTypeId ?: null,
                'name'         => $names[$roomTypeId] ?? 'Chưa phân loại',
                'revenue'      => (int) $amount,
            ])
            ->sortByDesc('revenue')
            ->values()
            ->toArray();
    }

    private static function topRooms(array $productIds, $start, $end, int $limit = 5): array
    {
        if (empty($productIds)) {
            return [];
        }

        $rows = OrderItem::query()
            ->whereIn('product_id', $productIds)
            ->whereHas('order', fn ($o) => $o->where('exclude_from_stats', false)
                ->where('status', 'paid')
                ->whereBetween('created_at', [$start, $end]))
            ->selectRaw('product_id, COUNT(DISTINCT order_id) as bookings_count')
            ->groupBy('product_id')
            ->orderByDesc('bookings_count')
            ->limit($limit)
            ->get();

        $names = Product::whereIn('id', $rows->pluck('product_id'))->pluck('name', 'id');

        return $rows->map(fn ($r) => [
            'product_id'      => $r->product_id,
            'name'            => $names[$r->product_id] ?? 'N/A',
            'bookings_count'  => (int) $r->bookings_count,
        ])->toArray();
    }

    private static function roomStatus(array $productIds): array
    {
        $total = count($productIds);
        if ($total === 0) {
            return ['total_rooms' => 0, 'occupied' => ['count' => 0, 'pct' => 0], 'cleaning' => ['count' => 0, 'pct' => 0], 'available' => ['count' => 0, 'pct' => 0]];
        }

        $now = now();

        $occupied = OrderItem::query()
            ->whereIn('product_id', $productIds)
            ->whereHas('order', fn ($o) => $o->where('exclude_from_stats', false)->whereNotIn('status', ['cancelled_payment', 'failed']))
            ->where('checkin_date', '<=', $now)
            ->where('checkout_date', '>=', $now)
            ->distinct('product_id')
            ->count('product_id');

        $cleaning = Product::whereIn('id', $productIds)->where('housekeeping_status', 'cleaning')->count();

        $available = max(0, $total - $occupied - $cleaning);

        $pct = fn (int $count) => $total > 0 ? round(($count / $total) * 100, 2) : 0;

        return [
            'total_rooms' => $total,
            'occupied'    => ['count' => $occupied, 'pct' => $pct($occupied)],
            'cleaning'    => ['count' => $cleaning, 'pct' => $pct($cleaning)],
            'available'   => ['count' => $available, 'pct' => $pct($available)],
        ];
    }
}
