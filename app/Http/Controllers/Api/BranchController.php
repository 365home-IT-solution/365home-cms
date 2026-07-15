<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\BladeThemeV1\Livewire\Book;
use Modules\Category\Entities\Category;
use Modules\Product\App\Models\Product;

class BranchController extends Controller
{
    /**
     * GET /api/v1/branches/{slug}/time-slots?days=15&session_id=...
     *
     * Lịch đặt phòng đầy đủ của 1 chi nhánh: mọi phòng theo khung giờ (styles=1/null) đang hoạt
     * động, cho $days ngày kể từ hôm nay (mặc định/tối đa giống hệt lịch web —
     * Book::INITIAL_VISIBLE_DAYS/MAX_VISIBLE_DAYS), kèm giá/khuyến mãi/trạng thái đã đặt cho từng
     * ô ngày x khung giờ. Tái dùng Book::calculateSlotPrice()/getDatesForOneMonth() (Livewire
     * component dùng cho lịch web) để không lặp lại logic tính giá/khuyến mãi ở 2 nơi — chỉ gọi
     * như 1 object PHP thuần (không qua vòng đời Livewire), tương tự cách BranchBookConfig dùng
     * chung cho /branch/{slug} và panel tìm kiếm.
     *
     * session_id (tuỳ chọn): truyền cùng session_id đã dùng ở
     * POST/DELETE /api/rooms/{id}/time-slot-hold để mỗi ô trả thêm held/held_by_me — client biết
     * ô nào ĐANG bị người khác chọn (held=true, held_by_me=false → is_selectable luôn false) để
     * khoá lại trên giao diện, tránh 2 người cùng chọn trùng 1 ô rồi 1 người bị từ chối lúc thanh
     * toán. Không truyền session_id thì vẫn hoạt động bình thường, chỉ không phân biệt được
     * held_by_me (mọi hold coi như "của người khác").
     */
    public function timeSlots(Request $request, string $slug): JsonResponse
    {
        $branch = Category::where('slug', $slug)->first();

        if (! $branch) {
            return response()->json(['message' => 'Chi nhánh không tồn tại.'], 404);
        }

        $days = (int) $request->query('days', Book::INITIAL_VISIBLE_DAYS);
        $days = max(1, min($days, Book::MAX_VISIBLE_DAYS));
        $sessionId = $request->query('session_id');

        $categoryIds = array_merge(
            [$branch->id],
            Category::where('parent_id', $branch->id)->pluck('id')->toArray()
        );

        $endDate   = Carbon::now()->addMonth()->endOfDay();
        $startDate = Carbon::now()->startOfDay();

        $rooms = Product::where('is_activated', true)
            ->where('is_in_stock', true)
            ->where(fn ($q) => $q->where('styles', 1)->orWhereNull('styles'))
            ->whereHas('categories', fn ($q) => $q->whereIn('categories.id', $categoryIds))
            ->whereHas('roomTimeSlots.timeSlot')
            ->with([
                'roomTimeSlots' => function ($query) {
                    $query->join('time_slots', 'room_time_slots.timeslot_id', '=', 'time_slots.id')
                        ->select('room_time_slots.*')
                        ->orderBy('time_slots.start_time', 'asc');
                },
                'roomTimeSlots.timeSlot',
                'roomTimeSlots.promotions' => fn ($q) => $q->where('is_active', true),
                'orderItems' => function ($query) use ($endDate, $startDate) {
                    $query->where('checkout_date', '>', $startDate)
                        ->where('checkin_date', '<=', $endDate)
                        ->whereHas('order', fn ($sub) => $sub->whereIn('status', ['pending', 'paid', 'shipped', 'confirmed']));
                },
                'orderItems.order',
            ])
            ->orderBy('sort_order')
            ->get();

        // Instance thuần (không mount qua Livewire) — chỉ dùng các method tính toán thuần PHP
        // (calculateSlotPrice/getDatesForOneMonth), không đụng tới vòng đời request Livewire.
        $book = new Book();
        $book->visibleDaysCount = $days;
        $dates = $book->getDatesForOneMonth();
        $today = Carbon::today();

        $roomsData = $rooms->map(function (Product $room) use ($book, $dates, $today, $sessionId) {
            // 1 lần/phòng (không phải 1 lần/ô) — số hold đang hoạt động của 1 phòng luôn rất nhỏ
            // (vài khách đang xem cùng lúc), lookup tuyến tính trong buildSlotStatus() là đủ rẻ.
            $holds = TimeSlotHoldController::getActiveHolds((string) $room->id);

            return [
                'id'   => $room->id,
                'name' => $room->name,
                'slug' => $room->slug,
                // Đang có khuyến mãi giảm giá hiệu lực ngay bây giờ ở bất kỳ khung giờ nào — dùng
                // để hiện icon flash-sale trang trí, giống điều kiện $roomHasDiscount ở
                // book/_desktop-grid.blade.php.
                'has_discount' => $room->roomTimeSlots->contains(
                    fn ($rts) => $rts->promotions->contains(
                        fn ($p) => in_array($p->type, ['percentage', 'fixed'])
                            && $p->is_active
                            && Carbon::parse($p->start_at)->lte(now())
                            && Carbon::parse($p->end_at)->gte(now())
                    )
                ),
                'time_slots' => $room->roomTimeSlots->map(fn ($rts) => [
                    'timeslot_id' => $rts->timeslot_id,
                    'time'        => substr($rts->timeSlot->start_time, 0, 5) . ' - ' . substr($rts->timeSlot->end_time, 0, 5),
                    'over_night'  => (bool) $rts->over_night,
                ])->values(),
                'days' => collect($dates)->map(fn ($date) => [
                    'date'     => $date['date'],
                    'day'      => $date['day'],
                    'is_today' => $date['is_today'],
                    'slots'    => $room->roomTimeSlots
                        ->map(fn ($rts) => $this->buildSlotStatus($room, $rts, $date, $book, $today, $holds, $sessionId))
                        ->values(),
                ])->values(),
            ];
        })->values();

        return response()->json([
            'branch' => [
                'id'   => $branch->id,
                'slug' => $branch->slug,
                'name' => $branch->name,
            ],
            'dates' => $dates,
            'rooms' => $roomsData,
        ]);
    }

    /**
     * Trạng thái + giá 1 ô "khung giờ x ngày" — cùng logic với
     * book/_slot-cell.blade.php (dùng chung cho lịch web), để 2 nơi luôn khớp nhau.
     *
     * @param  array  $holds  Hold còn hạn của cả phòng (TimeSlotHoldController::getActiveHolds()) —
     *                        lọc đúng ô này (timeslot_id + date) bên trong, không phải lọc sẵn từ
     *                        ngoài vì hàm này chạy lặp lại cho từng ô/ngày của cùng 1 phòng.
     */
    private function buildSlotStatus(Product $room, $rts, array $date, Book $book, Carbon $today, array $holds = [], ?string $sessionId = null): array
    {
        $currentDateTime = Carbon::createFromFormat(
            'd-m-Y H:i:s',
            $date['date'] . ' ' . $rts->timeSlot->start_time,
        );

        $status = 'available';
        foreach ($room->orderItems as $orderItem) {
            $checkin  = Carbon::parse($orderItem->checkin_date);
            $checkout = Carbon::parse($orderItem->checkout_date);
            if ($currentDateTime->between($checkin, $checkout)) {
                if ($orderItem->order) {
                    $status = $orderItem->order->status;
                }
                break;
            }
        }

        $isSelectable = ! in_array($status, ['pending', 'paid', 'shipped', 'confirmed']);

        $slotDate   = Carbon::createFromFormat('d-m-Y', $date['date'])->startOfDay();
        $yesterday  = now()->subDay()->startOfDay();
        $cutoffTime = now()->startOfDay()->setTime(7, 30, 0);

        if ($slotDate->lt($yesterday)) {
            $isSelectable = false;
        } elseif ($slotDate->eq($yesterday)) {
            if (now()->gte($cutoffTime)) {
                $isSelectable = false;
            }
        } elseif ($slotDate->eq($today)) {
            $slotEndTimeParsed = Carbon::parse($rts->timeSlot->end_time);
            $isOvernightSlot   = $slotEndTimeParsed->lt(Carbon::parse($rts->timeSlot->start_time));
            $slotEndDateTime   = $slotDate->copy()->setTime(
                $slotEndTimeParsed->hour,
                $slotEndTimeParsed->minute,
                $slotEndTimeParsed->second
            );
            if ($isOvernightSlot) {
                $slotEndDateTime->addDay();
            }
            if (now()->gte($slotEndDateTime)) {
                $isSelectable = false;
            }
        }

        $rtsSettings  = is_array($rts->settings) ? $rts->settings : (json_decode($rts->settings, true) ?? []);
        $blockedDates = $rtsSettings['blocked_dates'] ?? [];
        $slotDateYmd  = $slotDate->toDateString();
        $isBlocked    = in_array($slotDateYmd, $blockedDates);
        if ($isBlocked) {
            $isSelectable = false;
        }

        // "Đang được chọn" bởi 1 session khác (xem TimeSlotHoldController) — khoá lại như 1 ô đã
        // đặt, TRỪ khi chính session gọi API này đang giữ ô đó (held_by_me: vẫn cho tương tác để
        // họ có thể bỏ chọn lại chính ô mình vừa chọn).
        $heldEntry = collect($holds)->first(
            fn ($h) => (int) $h['timeslot_id'] === (int) $rts->timeslot_id && $h['date'] === $date['date']
        );
        $held     = $heldEntry !== null;
        $heldByMe = $held && $sessionId !== null && $heldEntry['session_id'] === $sessionId;
        if ($held && ! $heldByMe) {
            $isSelectable = false;
        }

        $slotStartTime = Carbon::parse($rts->timeSlot->start_time)->format('H:i:s');
        $priceData     = $book->calculateSlotPrice($rts, $date['date'], $slotStartTime);

        $finalPrice    = (int) $priceData['final_price'];
        $originalPrice = (int) $priceData['original_price'];

        return [
            'timeslot_id'   => $rts->timeslot_id,
            'status'        => $status,
            'is_selectable' => $isSelectable,
            'is_blocked'    => $isBlocked,
            'held'          => $held,
            'held_by_me'    => $heldByMe,
            'price'         => $originalPrice,
            'final_price'   => $finalPrice !== $originalPrice ? $finalPrice : null,
            'has_promotion' => $priceData['has_promotion'],
            'is_increase'   => $priceData['is_increase'],
            'promotions'    => collect($priceData['promotions'])->map(fn ($p) => [
                'id'    => $p->id,
                'name'  => $p->name,
                'type'  => $p->type,
                'value' => $p->value,
                'label' => $p->lable_client,
            ])->values(),
        ];
    }
}
