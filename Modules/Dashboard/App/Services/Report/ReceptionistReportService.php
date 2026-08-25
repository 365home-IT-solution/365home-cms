<?php

declare(strict_types=1);

namespace Modules\Dashboard\App\Services\Report;

use Carbon\Carbon;
use Modules\Dashboard\App\Services\OverviewService;
use Modules\Payment\Entities\Order;
use Modules\Payment\Entities\OrderItem;
use Modules\Product\App\Models\Product;
use Modules\Product\App\Models\RoomTimeSlot;

/**
 * BÁO CÁO LỄ TÂN: phòng trống / đang sử dụng / dự kiến trả / dự kiến nhận + công suất sử dụng,
 * trong kỳ đã chọn (mặc định hôm nay). "Đang sử dụng"/"trống" chốt tại asOf (cuối kỳ, hoặc hiện tại
 * nếu kỳ đang diễn ra/đã qua — KHÔNG bao giờ vượt quá kỳ đang lọc, tránh lọc period=hôm qua nhưng
 * lại trả về trạng thái LIVE của hôm nay). Khớp đúng quy tắc "chiếm chỗ" chuẩn đang dùng ở lưới đặt
 * phòng (xem RoomController::buildSlotStatus() / OrderObserver::broadcastSlotStatusChanged()): CHỈ
 * đơn pending/paid coi là chiếm phòng — đơn deposit (đặt cọc chưa thanh toán đủ) KHÔNG coi là
 * chiếm, khác với "overbooking" bên dưới (vẫn coi deposit là 1 đơn thực sự để phát hiện xung đột,
 * khớp BuildsRoomBooking lúc tạo đơn mới).
 *
 * "Phòng trống": phòng theo GIỜ (styles=1) tính là trống nếu CÒN ÍT NHẤT 1 khung giờ (room_time_
 * slots) trong ngày của asOf chưa quá giờ VÀ chưa có đơn pending/paid nào chiếm khung đó — dù các
 * khung khác trong ngày đã có đơn (KHÔNG cần cả ngày trống mới tính trống). Phòng theo NGÀY
 * (styles=2) không có khái niệm nhiều khung/ngày nên vẫn dùng luật cũ: hết chỗ nếu có bất kỳ đơn
 * nào giao với phần còn lại của ngày.
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
        // trong phạm vi, có giao với "từ asOf đến hết ngày của asOf" — dùng cho cả "đang sử dụng"
        // (đang chiếm NGAY tại asOf) lẫn để đối chiếu từng khung giờ khi quét "phòng trống" bên dưới.
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

        $itemsByRoom = $occupyingItems->groupBy('product_id');

        $rooms = Product::whereIn('id', $productIds)->get(['id', 'styles', 'housekeeping_status']);

        // Khung giờ mẫu (date IS NULL — lặp lại mỗi ngày) của các phòng THEO GIỜ trong phạm vi —
        // 1 phòng theo giờ có thể có NHIỀU khung/ngày, chỉ 1 khung bị đặt không có nghĩa cả ngày hết
        // chỗ, nên phải quét từng khung thay vì chỉ nhìn tổng quan đơn như phòng theo ngày.
        $slotsByRoom = RoomTimeSlot::whereIn('room_id', $rooms->where('styles', '!=', 2)->pluck('id'))
            ->whereNull('date')
            ->with('timeSlot')
            ->get()
            ->filter(fn ($rts) => $rts->timeSlot)
            ->groupBy('room_id');

        $asOfDate = $asOf->toDateString();

        // "Phòng trống": phòng theo NGÀY (styles=2) — hết chỗ nếu có bất kỳ đơn nào giao với phần
        // còn lại của ngày (không có khái niệm "khung giờ khác còn trống"). Phòng theo GIỜ — vẫn
        // tính là trống nếu CÒN ÍT NHẤT 1 khung giờ hôm nay chưa quá giờ (so với asOf) VÀ chưa có
        // đơn pending/paid nào chiếm khung đó, dù các khung khác trong ngày đã có đơn.
        $unavailableIds = collect();

        foreach ($rooms as $room) {
            if ($room->housekeeping_status === 'cleaning') {
                $unavailableIds->push($room->id);
                continue;
            }

            $roomItems = $itemsByRoom->get($room->id, collect());
            $slots     = (int) $room->styles === 2 ? collect() : $slotsByRoom->get($room->id, collect());

            // Phòng theo ngày, hoặc phòng theo giờ chưa cấu hình khung nào (lỗi dữ liệu) — fallback
            // về luật "có đơn giao với phần còn lại của ngày là hết chỗ".
            if ($slots->isEmpty()) {
                if ($roomItems->isNotEmpty()) {
                    $unavailableIds->push($room->id);
                }
                continue;
            }

            $hasOpenSlot = $slots->contains(function ($rts) use ($asOfDate, $asOf, $roomItems) {
                $slotStart = Carbon::parse($asOfDate . ' ' . $rts->timeSlot->start_time);
                $slotEnd   = Carbon::parse($asOfDate . ' ' . $rts->timeSlot->end_time);
                if ($slotEnd->lte($slotStart)) {
                    $slotEnd->addDay();
                }

                // Khung đã quá giờ (kể cả đang diễn ra dở — cùng mốc cắt với isSelectable ở
                // RoomController::buildSlotStatus) thì không còn để chào khách được nữa.
                if ($slotEnd->lte($asOf)) {
                    return false;
                }

                return ! $roomItems->contains(
                    fn ($item) => $item->checkin_date->lt($slotEnd) && $item->checkout_date->gt($slotStart)
                );
            });

            if (! $hasOpenSlot) {
                $unavailableIds->push($room->id);
            }
        }

        $occupiedRooms   = $occupiedProductIds->count();
        $availableRooms  = max(0, $totalRooms - $unavailableIds->unique()->count());
        $cleaningRooms   = $rooms->where('housekeeping_status', 'cleaning')->count();

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
