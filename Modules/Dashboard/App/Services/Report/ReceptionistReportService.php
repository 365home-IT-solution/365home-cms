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
 * trong kỳ đã chọn (mặc định hôm nay). "Đang sử dụng"/"trống" LUÔN là ảnh chụp REALTIME tại thời
 * điểm hiện tại thực sự (Carbon::now()) — KHÔNG phụ thuộc bộ lọc ngày đang chọn (kỳ chỉ ảnh hưởng
 * "dự kiến nhận/trả", số liệu PHÁT SINH đếm theo cả kỳ). Khớp đúng quy tắc "chiếm chỗ" chuẩn đang
 * dùng ở lưới đặt phòng (xem RoomController::buildSlotStatus() / OrderObserver::
 * broadcastSlotStatusChanged()): CHỈ đơn pending/paid coi là chiếm phòng — đơn deposit (đặt cọc
 * chưa thanh toán đủ) KHÔNG coi là chiếm, khác với "overbooking" bên dưới (vẫn coi deposit là 1
 * đơn thực sự để phát hiện xung đột, khớp BuildsRoomBooking lúc tạo đơn mới).
 */
class ReceptionistReportService
{
    private const ACTIVE_STATUSES_EXCLUDE = ['cancelled_payment', 'failed'];

    // "Chiếm phòng" cho mục đích phòng trống/đang sử dụng — whitelist, KHÔNG phải blacklist như
    // ACTIVE_STATUSES_EXCLUDE, vì deposit/confirmed/... đều không được coi là chiếm chỗ ở đây.
    private const OCCUPYING_STATUSES = ['pending', 'paid'];

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

        $orderScope = fn ($q) => $q->whereIn('product_id', $productIds)
            ->whereHas('order', fn ($o) => $o->where('exclude_from_stats', false)
                ->whereNotIn('status', self::ACTIVE_STATUSES_EXCLUDE));

        // "Đang sử dụng"/"trống" chốt tại thời điểm CUỐI kỳ, hoặc hiện tại nếu kỳ đang diễn ra/đã
        // qua (kỳ tương lai không có nghĩa để hỏi "đang sử dụng") — asOf luôn nằm trong kỳ đang
        // lọc, tránh lọc period=hôm qua nhưng lại âm thầm trả về trạng thái LIVE của hôm nay.
        $asOf   = Carbon::now()->lt($end) ? Carbon::now() : $end;
        $dayEnd = $asOf->copy()->endOfDay();

        // Lấy 1 lần mọi đơn CHIẾM CHỖ (chỉ pending/paid — xem OCCUPYING_STATUSES) của các phòng
        // trong phạm vi, có giao với "từ asOf đến hết ngày của asOf" — dùng chung để suy ra cả
        // "đang sử dụng" (đang chiếm NGAY tại asOf) lẫn "phòng trống" (không chiếm tại asOf VÀ
        // không còn khung giờ nào khác trong ngày đó đã có đơn — 1 phòng rảnh lúc asOf nhưng đã có
        // khách đặt khung giờ muộn hơn cùng ngày thì KHÔNG tính là phòng trống).
        $occupyingItems = OrderItem::query()
            ->tap($orderScope)
            ->whereHas('order', fn ($o) => $o->whereIn('status', self::OCCUPYING_STATUSES))
            ->where('checkin_date', '<', $dayEnd)
            ->where('checkout_date', '>', $asOf)
            ->get(['product_id', 'checkin_date', 'checkout_date']);

        $occupiedProductIds = $occupyingItems
            ->filter(fn ($item) => $item->checkin_date->lte($asOf) && $item->checkout_date->gte($asOf))
            ->pluck('product_id')
            ->unique();

        $busyTodayProductIds = $occupyingItems->pluck('product_id')->unique();

        $cleaningProductIds = Product::whereIn('id', $productIds)
            ->where('housekeeping_status', 'cleaning')
            ->pluck('id');

        $occupiedRooms   = $occupiedProductIds->count();
        $unavailableRooms = $busyTodayProductIds->merge($cleaningProductIds)->unique()->count();
        $availableRooms  = max(0, $totalRooms - $unavailableRooms);
        $cleaningRooms   = $cleaningProductIds->count();

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
        // sẵn sàng để đón khách nên không nên bị coi là "bỏ trống lãng phí". Chặn trần 100% — dữ
        // liệu thực tế có thể có phòng vừa được đánh dấu "đang dọn" nhưng vẫn còn đơn pending/paid
        // đang chiếm (housekeeping_status cập nhật trễ hơn đơn), khiến occupied_rooms > capacityBase
        // nếu không chặn.
        $capacityBase = $totalRooms - $cleaningRooms;
        $capacityPct  = $capacityBase > 0 ? min(100, round(($occupiedRooms / $capacityBase) * 100, 2)) : 0;

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
