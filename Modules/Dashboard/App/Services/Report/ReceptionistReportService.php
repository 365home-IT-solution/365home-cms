<?php

declare(strict_types=1);

namespace Modules\Dashboard\App\Services\Report;

use Carbon\Carbon;
use Modules\Dashboard\App\Services\OverviewService;
use Modules\Payment\Entities\Order;
use Modules\Payment\Entities\OrderItem;
use Modules\Product\App\Models\Product;

/**
 * BÁO CÁO LỄ TÂN: phòng trống / đang sử dụng / dự kiến trả / dự kiến nhận + công suất sử dụng,
 * trong kỳ đã chọn (mặc định hôm nay). "Đang sử dụng"/"trống" là ảnh chụp tại thời điểm CUỐI kỳ
 * (hoặc hiện tại nếu kỳ đang diễn ra) — vì đây là số liệu TỒN (đang có khách hay không), không
 * cộng dồn được qua nhiều ngày như "dự kiến nhận/trả" (số liệu PHÁT SINH, đếm theo cả kỳ).
 */
class ReceptionistReportService
{
    private const ACTIVE_STATUSES_EXCLUDE = ['cancelled_payment', 'failed'];

    public static function getData(
        $user,
        string $filter,
        ?string $customStart,
        ?string $customEnd,
        ?array $branchCategoryIds,
        string $type = 'capacity'
    ): array {
        [$start, $end] = OverviewService::resolveRange($filter, $customStart, $customEnd);

        $productIds = ReportScope::productIds($user, $branchCategoryIds);
        $totalRooms = count($productIds);

        if ($totalRooms === 0) {
            $data = [
                'period'             => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
                'total_rooms'        => 0,
                'available_rooms'    => 0,
                'occupied_rooms'     => 0,
                'expected_checkin'   => 0,
                'expected_checkout'  => 0,
                'capacity_pct'       => 0,
            ];

            return $type === 'overbooking' ? $data + ['overbooking' => ['count' => 0, 'items' => []]] : $data;
        }

        // "Đang sử dụng"/"trống" là ảnh chụp tức thời — chốt tại cuối kỳ, hoặc hiện tại nếu kỳ đang
        // diễn ra/đã qua (kỳ tương lai không có nghĩa để hỏi "đang sử dụng").
        $asOf = Carbon::now()->lt($end) ? Carbon::now() : $end;

        $orderScope = fn ($q) => $q->whereIn('product_id', $productIds)
            ->whereHas('order', fn ($o) => $o->where('exclude_from_stats', false)
                ->whereNotIn('status', self::ACTIVE_STATUSES_EXCLUDE));

        $occupiedRooms = OrderItem::query()
            ->tap($orderScope)
            ->where('checkin_date', '<=', $asOf)
            ->where('checkout_date', '>=', $asOf)
            ->distinct('product_id')
            ->count('product_id');

        $cleaningRooms = Product::whereIn('id', $productIds)
            ->where('housekeeping_status', 'cleaning')
            ->count();

        $availableRooms = max(0, $totalRooms - $occupiedRooms - $cleaningRooms);

        $expectedCheckin = OrderItem::query()
            ->tap($orderScope)
            ->whereBetween('checkin_date', [$start, $end])
            ->distinct('order_id')
            ->count('order_id');

        $expectedCheckout = OrderItem::query()
            ->tap($orderScope)
            ->whereBetween('checkout_date', [$start, $end])
            ->distinct('order_id')
            ->count('order_id');

        // Công suất sử dụng KHÔNG tính phòng đang dọn vào mẫu số — phòng đang dọn tạm thời không
        // sẵn sàng để đón khách nên không nên bị coi là "bỏ trống lãng phí".
        $capacityBase = $totalRooms - $cleaningRooms;
        $capacityPct  = $capacityBase > 0 ? round(($occupiedRooms / $capacityBase) * 100, 2) : 0;

        $data = [
            'period'            => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
            'total_rooms'       => $totalRooms,
            'available_rooms'   => $availableRooms,
            'occupied_rooms'    => $occupiedRooms,
            'expected_checkin'  => $expectedCheckin,
            'expected_checkout' => $expectedCheckout,
            'capacity_pct'      => $capacityPct,
        ];

        if ($type === 'overbooking') {
            $data['overbooking'] = static::overbooking($productIds, $start, $end);
        }

        return $data;
    }

    /**
     * Đơn phòng bị trùng: cùng 1 phòng nhưng có >=2 order KHÁC NHAU có khung ngày chồng lấn nhau
     * trong kỳ. Dữ liệu số lượng nhỏ (số order_items trong kỳ của 1 chi nhánh) nên so khớp cặp
     * bằng vòng lặp PHP đơn giản, không cần query chồng lấn phức tạp ở DB.
     */
    private static function overbooking(array $productIds, Carbon $start, Carbon $end): array
    {
        $items = OrderItem::query()
            ->whereIn('product_id', $productIds)
            ->whereHas('order', fn ($o) => $o->where('exclude_from_stats', false)
                ->whereNotIn('status', self::ACTIVE_STATUSES_EXCLUDE))
            ->whereNotNull('checkin_date')
            ->whereNotNull('checkout_date')
            ->where('checkin_date', '<', $end)
            ->where('checkout_date', '>', $start)
            ->with('order:id,order_code')
            ->get(['id', 'order_id', 'product_id', 'checkin_date', 'checkout_date']);

        $conflicts = [];
        $byProduct = $items->groupBy('product_id');

        foreach ($byProduct as $productId => $group) {
            $sorted = $group->sortBy('checkin_date')->values();

            for ($i = 0; $i < $sorted->count(); $i++) {
                for ($j = $i + 1; $j < $sorted->count(); $j++) {
                    $a = $sorted[$i];
                    $b = $sorted[$j];

                    if ($a->order_id === $b->order_id) {
                        continue;
                    }

                    if ($a->checkin_date->lt($b->checkout_date) && $b->checkin_date->lt($a->checkout_date)) {
                        $conflicts[] = [
                            'product_id' => $productId,
                            'orders'     => [
                                ['order_id' => $a->order_id, 'order_code' => $a->order?->order_code, 'checkin_date' => $a->checkin_date->toDateTimeString(), 'checkout_date' => $a->checkout_date->toDateTimeString()],
                                ['order_id' => $b->order_id, 'order_code' => $b->order?->order_code, 'checkin_date' => $b->checkin_date->toDateTimeString(), 'checkout_date' => $b->checkout_date->toDateTimeString()],
                            ],
                        ];
                    }
                }
            }
        }

        return ['count' => count($conflicts), 'items' => $conflicts];
    }
}
