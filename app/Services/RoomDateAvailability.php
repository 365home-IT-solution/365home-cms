<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\TimeslotHold;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Modules\Payment\Entities\OrderItem;
use Modules\Product\App\Models\Product;

/**
 * Phòng còn trống trong MỘT ngày — dùng cho GET /v1/search?ver=1.1&date=YYYY-MM-DD (RoomSearchRoomsService).
 *
 * Mirror đúng các kiểm tra của luồng đặt phòng web (ProductDetail::checkSlotConflicts / checkDateRangeConflicts):
 *  - Phòng theo giờ (styles=1): còn ÍT NHẤT MỘT khung giờ trống trong ngày — chưa có đơn, không bị admin giữ chỗ
 *    (timeslot_holds), không bị khoá (room_time_slots.settings.blocked_dates).
 *  - Phòng theo ngày (styles=2): đêm $date (nhận $date, trả $date + 1) còn trống — không đơn chồng lịch, không nằm
 *    trong khoảng khoá trọn phòng (room_config.blocked_ranges); biến thể phòng tính theo tồn phòng (RoomInventory).
 *
 * Mỗi hàm chạy vài query gộp cho cả tập phòng (không query từng phòng) rồi trả danh sách id để service lọc bằng whereIn.
 */
class RoomDateAvailability
{
    private const DAY_ORDER_STATUSES = ['paid', 'deposit', 'confirmed', 'shipping', 'completed'];

    public function __construct(private readonly RoomInventory $inventory)
    {
    }

    /** @return array<int|string, int|string> id các phòng theo giờ còn ít nhất 1 khung giờ trống trong $date. */
    public function availableHourlyIds(string $date): array
    {
        $rooms = Product::query()
            ->where('styles', 1)
            ->where('is_activated', true)
            ->where('is_in_stock', true)
            ->with(['roomTimeSlots' => fn ($q) => $q->whereNull('date')->where('status', '!=', 'booked')->with('timeSlot')])
            ->get();

        if ($rooms->isEmpty()) {
            return [];
        }

        $dayStart = Carbon::parse($date)->startOfDay();

        // Đơn có giờ nhận phòng rơi trong ngày $date → tập "H:i:s" đã bị đặt, theo từng phòng.
        $bookedStarts = $this->activeItems($rooms->pluck('id')->all(), $dayStart, $dayStart->copy()->addDay(), false)
            ->groupBy('product_id')
            ->map(fn (Collection $items) => $items->map(fn ($i) => Carbon::parse($i->checkin_date)->format('H:i:s'))->flip());

        $holds = TimeslotHold::query()
            ->where('date', $date)
            ->where('expires_at', '>', now())
            ->pluck('room_time_slot_id')
            ->flip();

        return $rooms
            ->filter(fn (Product $room) => $room->roomTimeSlots->contains(
                fn ($rts) => $this->hourlySlotFree($room, $rts, $date, $bookedStarts->get($room->id), $holds)
            ))
            ->pluck('id')
            ->all();
    }

    /** @return array<int|string, int|string> id các phòng theo ngày KHÔNG còn trống đêm $date. */
    public function unavailableDayIds(string $date): array
    {
        $start = Carbon::parse($date)->startOfDay();
        $end   = $start->copy()->addDay();

        $rooms = Product::query()->where('styles', 2)->where('is_activated', true)->get();

        if ($rooms->isEmpty()) {
            return [];
        }

        $unavailable = [];

        // Khoá trọn phòng qua CMS: khoá cả 2 đầu [start, end] — đêm $date nằm trong khoảng nếu start <= $date <= end.
        foreach ($rooms as $room) {
            foreach ($room->fullBlockedRanges() as $range) {
                if (Carbon::parse($range['start'])->startOfDay()->lte($start) && Carbon::parse($range['end'])->startOfDay()->gte($start)) {
                    $unavailable[$room->id] = true;
                    break;
                }
            }
        }

        $remaining = $rooms->reject(fn (Product $r) => isset($unavailable[$r->id]))->keyBy('id');

        $itemsByRoom = $this->activeItems($remaining->keys()->all(), $start, $end, true)->groupBy('product_id');

        foreach ($remaining as $id => $room) {
            if ($room->is_variant) {
                // Có đơn hoặc khoá một phần số phòng → xét tồn phòng; không thì còn nguyên sức chứa.
                if ($itemsByRoom->has($id) || $room->partialBlockedRanges()) {
                    if ($this->inventory->remaining($room, $start, $end) < 1) {
                        $unavailable[$id] = true;
                    }
                }
            } elseif ($itemsByRoom->has($id)) {
                $unavailable[$id] = true;
            }
        }

        return array_keys($unavailable);
    }

    private function hourlySlotFree(Product $room, $rts, string $date, ?Collection $bookedStarts, Collection $holds): bool
    {
        if ($rts->isBlockedOn($date) || $holds->has($rts->id) || ! $rts->timeSlot) {
            return false;
        }

        $startTime = $rts->timeSlot->start_time;
        $startTime = strlen((string) $startTime) === 5 ? $startTime . ':00' : (string) $startTime;

        if ($room->is_variant) {
            $in  = Carbon::parse("{$date} {$startTime}");
            $out = Carbon::parse("{$date} {$rts->timeSlot->end_time}");
            if ($out->lte($in)) {
                $out->addDay();
            }

            return $this->inventory->hasRoom($room, $in, $out);
        }

        return $bookedStarts === null || ! $bookedStarts->has($startTime);
    }

    /**
     * Order item còn hiệu lực. $overlap = true: chồng khoảng [$from, $to) (phòng theo ngày); false: giờ nhận phòng nằm
     * trong [$from, $to) (khung giờ). Đơn pending hết hạn không còn giữ phòng.
     */
    private function activeItems(array $productIds, Carbon $from, Carbon $to, bool $overlap): Collection
    {
        if (empty($productIds)) {
            return collect();
        }

        return OrderItem::query()
            ->whereIn('product_id', $productIds)
            ->whereNotNull('checkin_date')
            ->when(
                $overlap,
                fn ($q) => $q->whereNotNull('checkout_date')->where('checkin_date', '<', $to)->where('checkout_date', '>', $from),
                fn ($q) => $q->where('checkin_date', '>=', $from)->where('checkin_date', '<', $to),
            )
            ->whereHas('order', fn ($q) => $q->where(fn ($inner) => $inner
                ->whereIn('status', self::DAY_ORDER_STATUSES)
                ->orWhere(fn ($p) => $p->where('status', 'pending')
                    ->where(fn ($e) => $e->whereNull('expired_at')->orWhere('expired_at', '>', now())))))
            ->get(['product_id', 'checkin_date', 'checkout_date']);
    }
}
