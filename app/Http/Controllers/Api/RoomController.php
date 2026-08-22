<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\BuildsRoomCard;
use App\Support\MediaThumbnailUrls;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Modules\Payment\Entities\OrderItem;
use Modules\Product\App\Models\Product;
use Modules\Product\App\Models\RoomTimeSlot;

class RoomController extends Controller
{
    use BuildsRoomCard;

    public function show(string $id): JsonResponse
    {
        $room = Product::where('id', $id)
            ->where('is_activated', true)
            ->with([
                'roomType',
                'roomTimeSlots.timeSlot',
                'roomTimeSlots.promotions',
                'additionalServices',
                'tags',
                'specials',
                'media',
            ])
            ->first();

        if (! $room) {
            return response()->json(['message' => 'Phòng không tồn tại.'], 404);
        }

        $authUser       = auth('sanctum')->user();
        $wishlistStatus = $authUser
            ? $authUser->wishlists()->where('product_id', $room->id)->exists()
            : null;

        return response()->json([
            'data' => $this->buildRoomDetail($room, $wishlistStatus),
        ]);
    }

    // GET /api/slots?room_id=...&date=2026-06-04
    public function slots(Request $request): JsonResponse
    {
        $roomId = $request->query('room_id');
        if (! $roomId) {
            return response()->json(['message' => 'Thiếu tham số room_id.'], 422);
        }

        $room = Product::where('id', $roomId)
            ->where('is_activated', true)
            ->with([
                'roomTimeSlots.timeSlot',
                'roomTimeSlots.promotions' => fn ($q) => $q->where('is_active', true),
            ])
            ->first();

        if (! $room) {
            return response()->json(['message' => 'Phòng không tồn tại.'], 404);
        }

        $dates = $this->resolveSlotsDates($request);
        if ($dates instanceof JsonResponse) {
            return $dates;
        }

        $sessionId = $request->query('session_id');

        // All template slots for this room (keep $rts reference for promotions/blocked check) —
        // tính 1 LẦN DUY NHẤT, dùng chung cho mọi ngày trong $dates (không phụ thuộc ngày).
        $templateSlots = $room->roomTimeSlots
            ->whereNull('date')
            ->map(function ($rts) {
                $ts = $rts->timeSlot;
                if (! $ts) return null;
                return [
                    'rts'         => $rts,
                    'timeslot_id' => $rts->timeslot_id,
                    'start_time'  => $ts->start_time,
                    'end_time'    => $ts->end_time,
                    'time'        => substr($ts->start_time, 0, 5) . ' - ' . substr($ts->end_time, 0, 5),
                    'price'       => (int) $rts->price,
                    'over_night'  => (bool) $rts->over_night,
                ];
            })
            ->filter()
            ->values();

        $days = [];
        foreach ($dates as $dateStr) {
            $days[] = [
                'date'  => $dateStr,
                'slots' => $this->buildSlotsForDate($room, $templateSlots, $dateStr, $sessionId),
            ];
        }

        // Giữ nguyên shape cũ {date, slots} khi gọi kiểu 1-ngày (?date=) — không phá client hiện có.
        // Gọi kiểu nhiều ngày (from/to hoặc dates[]) trả {days: [{date, slots}, ...]}.
        if (count($days) === 1 && $request->filled('date')) {
            return response()->json($days[0]);
        }

        return response()->json(['days' => $days]);
    }

    /**
     * Suy danh sách ngày cần tính slot từ query params — 3 cách, ưu tiên theo thứ tự:
     *  - dates[]=2026-08-20&dates[]=2026-08-22  → đúng các ngày liệt kê (không cần liền kề)
     *  - from=2026-08-20&to=2026-08-25          → cả khoảng, tối đa 31 ngày
     *  - date=2026-08-20                        → 1 ngày (hành vi cũ, giữ tương thích ngược)
     *
     * @return array<int, string>|JsonResponse
     */
    private function resolveSlotsDates(Request $request)
    {
        if ($request->filled('dates')) {
            $raw   = (array) $request->query('dates');
            $dates = [];
            foreach ($raw as $d) {
                try {
                    $dates[] = Carbon::createFromFormat('Y-m-d', (string) $d)->toDateString();
                } catch (\Throwable) {
                    return response()->json(['message' => "Định dạng ngày không hợp lệ trong dates[]: {$d}. Dùng YYYY-MM-DD."], 422);
                }
            }
            $dates = array_values(array_unique($dates));
            sort($dates);
            if (count($dates) > 31) {
                return response()->json(['message' => 'Tối đa 31 ngày mỗi lần gọi.'], 422);
            }
            return $dates;
        }

        if ($request->filled('from') || $request->filled('to')) {
            if (! $request->filled('from') || ! $request->filled('to')) {
                return response()->json(['message' => 'Cần truyền đủ cả from và to.'], 422);
            }
            try {
                $start = Carbon::createFromFormat('Y-m-d', $request->query('from'))->startOfDay();
                $end   = Carbon::createFromFormat('Y-m-d', $request->query('to'))->startOfDay();
            } catch (\Throwable) {
                return response()->json(['message' => 'Định dạng from/to không hợp lệ. Dùng YYYY-MM-DD.'], 422);
            }
            if ($end->lt($start)) {
                return response()->json(['message' => 'to phải >= from.'], 422);
            }
            if ($start->diffInDays($end) > 30) {
                return response()->json(['message' => 'Khoảng from-to tối đa 31 ngày.'], 422);
            }
            $dates   = [];
            $cursor  = $start->copy();
            while ($cursor->lte($end)) {
                $dates[] = $cursor->toDateString();
                $cursor->addDay();
            }
            return $dates;
        }

        $dateStr = $request->query('date');
        if (! $dateStr) {
            return response()->json(['message' => 'Thiếu tham số date (YYYY-MM-DD), hoặc dùng from/to hoặc dates[].'], 422);
        }
        try {
            $dateStr = Carbon::createFromFormat('Y-m-d', $dateStr)->toDateString();
        } catch (\Throwable) {
            return response()->json(['message' => 'Định dạng date không hợp lệ. Dùng YYYY-MM-DD.'], 422);
        }

        return [$dateStr];
    }

    /**
     * Tính danh sách slot (giá/khuyến mãi/trạng thái/hold) cho ĐÚNG 1 ngày — tách khỏi slots() để
     * dùng chung được cho cả kiểu 1-ngày lẫn nhiều-ngày (xem resolveSlotsDates()), không tính 2 công
     * thức lệch nhau.
     *
     * @param  \Illuminate\Support\Collection  $templateSlots
     * @return array<int, array<string, mixed>>
     */
    private function buildSlotsForDate(Product $room, $templateSlots, string $dateStr, ?string $sessionId): array
    {
        $date = Carbon::createFromFormat('Y-m-d', $dateStr)->startOfDay();

        // Hold "đang chọn" tạm thời — hiện tại CHỈ admin tạo được (route hold()/release() public
        // cho khách vãng lai đã bị gỡ vì lỗ hổng DoS, xem docblock routes/api.php), nên holding=true
        // ở đây nghĩa là "1 admin đang thao tác tạo đơn cho khung này", không phải khách khác. Vẫn
        // hữu ích để hiển thị cảnh báo "có thể sắp hết chỗ". session_id (tuỳ chọn) cho phép FE tự
        // biết đây có phải hold CỦA CHÍNH MÌNH không — hiện luôn false với khách vì khách không tạo
        // được hold nào để so khớp, giữ lại param cho tương lai (nếu mở lại hold khách vãng lai) mà
        // không phải đổi lại response shape.
        // getActiveHolds() lưu 'date' theo d-m-Y (khớp lưới admin) — phải đổi khớp định dạng trước
        // khi so sánh với $dateStr (Y-m-d), KHÔNG đổi ở TimeSlotHoldController (nơi khác vẫn cần
        // nguyên d-m-Y).
        $dateDmy      = $date->format('d-m-Y');
        $holdsForDate = array_values(array_filter(
            TimeSlotHoldController::getActiveHolds((string) $room->id),
            fn ($h) => $h['date'] === $dateDmy
        ));

        // Active order items that could overlap with the given date
        $activeItems = OrderItem::query()
            ->where('product_id', $room->id)
            ->whereNotNull('checkin_date')
            ->whereNotNull('checkout_date')
            ->where('checkout_date', '>', $date)
            ->where('checkin_date', '<', $date->copy()->addDay())
            ->whereHas('order', fn ($q) => $q->whereIn('status', ['pending', 'paid', 'deposit', 'confirmed']))
            ->with('order:id,status')
            ->get(['id', 'order_id', 'checkin_date', 'checkout_date']);

        // Build a map of timeslot_id => order_status for booked slots on this date
        $bookedMap = [];
        foreach ($activeItems as $item) {
            $checkin     = Carbon::parse($item->checkin_date);
            $checkout    = Carbon::parse($item->checkout_date);
            $orderStatus = $item->order?->status ?? 'pending';

            foreach ($templateSlots as $slot) {
                $slotStart = Carbon::parse("{$dateStr} {$slot['start_time']}");
                if ($slotStart->gte($checkin) && $slotStart->lt($checkout)) {
                    $id = $slot['timeslot_id'];
                    if (! isset($bookedMap[$id]) || $orderStatus !== 'pending') {
                        $bookedMap[$id] = $orderStatus;
                    }
                }
            }
        }

        return $templateSlots->map(function ($slot) use ($bookedMap, $dateStr, $holdsForDate, $sessionId) {
            $rts       = $slot['rts'];
            $basePrice = $slot['price'];

            // Blocked date check (from roomTimeSlot settings)
            $isBlocked = $rts->isBlockedOn($dateStr);

            $heldEntry = collect($holdsForDate)->first(
                fn ($h) => (int) $h['timeslot_id'] === (int) $slot['timeslot_id']
            );
            $holding  = $heldEntry !== null;
            $heldByMe = $holding && $sessionId !== null && ($heldEntry['session_id'] ?? null) === $sessionId;

            // Promotions applicable to this slot on this date
            $slotStart = Carbon::parse("{$dateStr} {$slot['start_time']}");
            $slotEnd   = Carbon::parse("{$dateStr} {$slot['end_time']}");
            if ($slotEnd->lte($slotStart)) {
                $slotEnd->addDay(); // overnight slot
            }

            $priceAfterIncrease = $basePrice;
            $finalPrice         = $basePrice;
            $activePromos       = [];

            // Pass 1: apply increases first (same order as blade)
            foreach ($rts->promotions as $promo) {
                if (! $this->promotionOverlapsSlot($promo, $slotStart, $slotEnd)) continue;
                if ($promo->type === 'increase_fixed') {
                    $priceAfterIncrease += (float) $promo->value;
                } elseif ($promo->type === 'increase_percentage') {
                    $priceAfterIncrease += $basePrice * ($promo->value / 100);
                } else {
                    continue;
                }
                $finalPrice     = $priceAfterIncrease;
                $activePromos[] = $promo;
            }

            // Pass 2: apply discounts
            foreach ($rts->promotions as $promo) {
                if (! $this->promotionOverlapsSlot($promo, $slotStart, $slotEnd)) continue;
                if ($promo->type === 'fixed') {
                    $finalPrice -= (float) $promo->value;
                } elseif ($promo->type === 'percentage') {
                    $finalPrice -= $priceAfterIncrease * ($promo->value / 100);
                } else {
                    continue;
                }
                if (! collect($activePromos)->contains('id', $promo->id)) {
                    $activePromos[] = $promo;
                }
            }

            $finalPrice = max(0, (int) $finalPrice);

            $promotionData = collect($activePromos)->map(fn ($p) => [
                'id'    => $p->id,
                'name'  => $p->name,
                'type'  => $p->type,
                'value' => $p->value,
                'label' => $p->lable_client,
                'image' => $p->image ? Storage::disk('public')->url($p->image) : null,
            ])->values()->toArray();

            return [
                'timeslot_id'  => $slot['timeslot_id'],
                'time'         => $slot['time'],
                'price'        => $basePrice,
                'final_price'  => $finalPrice !== $basePrice ? $finalPrice : null,
                'over_night'   => $slot['over_night'],
                'is_blocked'   => $isBlocked,
                'order_status' => $bookedMap[$slot['timeslot_id']] ?? null,
                'holding'      => $holding,
                'held_by_me'   => $heldByMe,
                'promotions'   => $promotionData,
            ];
        })->values()->toArray();
    }

    // GET /api/rooms/{id}/guest-surcharge-preview?guest_count=5&dates[]=2026-06-29&dates[]=2026-06-30
    // GET /api/rooms/{id}/guest-surcharge-preview?guest_count=5&checkin=2026-06-29&checkout=2026-07-02
    //
    // Xem trước phụ thu khi chọn số lượng khách, áp dụng cho cả phòng theo
    // khung giờ (styles=1, truyền dates[]) và phòng theo ngày (styles=2,
    // truyền checkin/checkout). Cấu hình phụ thu lấy từ room_config
    // (max_free_guests, extra_guest_fee) — set trong Hệ thống giá.
    public function guestSurchargePreview(Request $request, string $id): JsonResponse
    {
        $room = Product::where('id', $id)
            ->where('is_activated', true)
            ->first();

        if (! $room) {
            return response()->json(['message' => 'Phòng không tồn tại.'], 404);
        }

        $guestCount = (int) $request->query('guest_count', 0);
        if ($guestCount < 1) {
            return response()->json(['message' => 'Thiếu hoặc sai tham số guest_count.'], 422);
        }

        $isSlotType = (int) $room->styles === 1;

        if ($isSlotType) {
            $dates = array_filter((array) $request->query('dates', []));
            if (empty($dates)) {
                return response()->json(['message' => 'Thiếu tham số dates[] (danh sách ngày YYYY-MM-DD).'], 422);
            }

            try {
                $nights = collect($dates)
                    ->map(fn ($d) => Carbon::createFromFormat('Y-m-d', $d)->format('Y-m-d'))
                    ->unique()
                    ->count();
            } catch (\Throwable) {
                return response()->json(['message' => 'Định dạng dates không hợp lệ. Dùng YYYY-MM-DD.'], 422);
            }
        } else {
            $checkinStr  = $request->query('checkin');
            $checkoutStr = $request->query('checkout');
            if (! $checkinStr || ! $checkoutStr) {
                return response()->json(['message' => 'Thiếu tham số checkin/checkout (YYYY-MM-DD).'], 422);
            }

            try {
                $checkin  = Carbon::createFromFormat('Y-m-d', $checkinStr)->startOfDay();
                $checkout = Carbon::createFromFormat('Y-m-d', $checkoutStr)->startOfDay();
            } catch (\Throwable) {
                return response()->json(['message' => 'Định dạng checkin/checkout không hợp lệ. Dùng YYYY-MM-DD.'], 422);
            }

            if ($checkout->lte($checkin)) {
                return response()->json(['message' => 'checkout phải sau checkin.'], 422);
            }

            $nights = (int) $checkin->diffInDays($checkout);
        }

        $config    = $room->room_config ?? [];
        $fee       = (int) ($config['extra_guest_fee'] ?? 0);
        $threshold = (int) ($config['max_free_guests'] ?? 2);

        $guestSurcharge = null;
        if ($fee > 0 && $guestCount > $threshold) {
            $extraGuests = $guestCount - $threshold;
            $total       = $extraGuests * $fee * $nights;
            $label       = "Phụ thu {$extraGuests} người (trên {$threshold} người)";
            if ($nights > 1) {
                $label .= ' × ' . $nights . ' ' . ($isSlotType ? 'ngày' : 'đêm');
            }

            $guestSurcharge = [
                'guest_count'    => $guestCount,
                'threshold'      => $threshold,
                'extra_guests'   => $extraGuests,
                'fee_per_person' => $fee,
                'nights'         => $nights,
                'total'          => $total,
                'label'          => $label,
            ];
        }

        return response()->json([
            'room_id'         => $room->id,
            'type'            => $isSlotType ? 'slot' : 'daily',
            'nights'          => $nights,
            'guest_surcharge' => $guestSurcharge,
        ]);
    }

    // ─────────────────────────────────────────────
    // BUILD FULL ROOM DETAIL
    // ─────────────────────────────────────────────

    private function buildRoomDetail(Product $room, ?bool $wishlistStatus): array
    {
        return [
            'id'                => $room->id,
            'name'              => $room->name,
            'slug'              => $room->slug,
            'short_description' => $room->short_description,
            'description'       => $room->description,
            'address'           => $room->address,
            'latitude'          => $room->latitude,
            'longitude'         => $room->longitude,
            'main'              => $this->buildMainImages($room),
            'main_thumbnails'   => $this->buildMainImageThumbnails($room),
            'gallery'           => $this->buildGallery($room),
            'gallery_thumbnails' => $this->buildGalleryThumbnails($room),
            'wishlist_status'   => $wishlistStatus,
            'is_available'      => $room->is_in_stock,
            'room_type'         => $room->roomType?->slug,
            'rating' => $room->rating_score !== null ? (float) $room->rating_score : null,
            'video'               => $this->buildVideo($room),
            'amenities'           => $this->buildAmenities($room),
            'additional_services' => $this->buildServices($room),
            'specials'            => $this->buildSpecials($room),
            'prices'              => $this->buildPrices($room),
        ];
    }

    // ─────────────────────────────────────────────
    // VIDEO
    // ─────────────────────────────────────────────

    private function buildVideo(Product $room): ?array
    {
        $setting = is_array($room->setting_video_room) ? $room->setting_video_room : [];
        $url     = $setting['url'] ?? null;

        if (! $url) {
            return null;
        }

        return [
            'url'   => $url,
            'ratio' => $setting['ratio'] ?? '9:16',
            'title' => $setting['title'] ?? null,
        ];
    }

    // ─────────────────────────────────────────────
    // MAIN IMAGE — flat URL array
    // ─────────────────────────────────────────────

    private function buildMainImages(Product $room): array
    {
        return $room->getMedia('Ảnh bìa')
            ->map(fn ($m) => $m->getUrl())
            ->values()
            ->toArray();
    }

    // Index-matched với buildMainImages() — cùng thứ tự collection 'Ảnh bìa'.
    private function buildMainImageThumbnails(Product $room): array
    {
        return $room->getMedia('Ảnh bìa')
            ->map(fn ($m) => MediaThumbnailUrls::build($m))
            ->values()
            ->toArray();
    }

    // ─────────────────────────────────────────────
    // GALLERY — sections: [{title, description, images:[url,...]}]
    // ─────────────────────────────────────────────

    private function buildGallery(Product $room): array
    {
        return $room->getMedia('Thư viện')
            ->map(fn ($m) => $m->getUrl())
            ->values()
            ->toArray();
    }

    // Index-matched với buildGallery() — cùng thứ tự collection 'Thư viện'.
    private function buildGalleryThumbnails(Product $room): array
    {
        return $room->getMedia('Thư viện')
            ->map(fn ($m) => MediaThumbnailUrls::build($m))
            ->values()
            ->toArray();
    }

    // ─────────────────────────────────────────────
    // AMENITIES — grouped by tag type
    // ─────────────────────────────────────────────

    private function buildAmenities(Product $room): array
    {
        return $room->tags
            ->groupBy('type')
            ->map(fn ($items, $type) => [
                'type'  => $type,
                'items' => $items->map(fn ($tag) => [
                    'id'    => $tag->id,
                    'name'  => $tag->name,
                    'slug'  => $tag->slug,
                    'image' => $tag->image,
                ])->values()->toArray(),
            ])
            ->values()
            ->toArray();
    }

    // ─────────────────────────────────────────────
    // SERVICES — room_additional_service_assigns
    // ─────────────────────────────────────────────

    private function buildServices(Product $room): array
    {
        return $room->additionalServices
            ->where('is_active', true)
            ->map(fn ($s) => [
                'id'    => $s->id,
                'name'  => $s->name,
                'price' => $s->price,
                'image' => $s->image ? Storage::disk('public')->url($s->image) : null,
            ])->values()->toArray();
    }

    // ─────────────────────────────────────────────
    // SPECIALS
    // ─────────────────────────────────────────────

    private function buildSpecials(Product $room): array
    {
        return $room->specials->map(fn ($s) => [
            'id'                => $s->id,
            'icon'              => $s->icon,
            'title'             => $s->title,
            'short_description' => $s->short_description,
        ])->values()->toArray();
    }

    // ─────────────────────────────────────────────
    // PRICES — dựa vào room_type slug, không dùa vào cột type của products
    // ─────────────────────────────────────────────

    private function buildPrices(Product $room): array
    {
        return (int) $room->styles === 1
            ? $this->buildHourlyPrices($room)
            : $this->buildDailyPrice($room);
    }

    private function buildHourlyPrices(Product $room): array
    {
        $slots = $room->roomTimeSlots
            ->whereNull('date')
            ->whereNotIn('status', ['booked'])
            ->sortBy('price')
            ->map(function (RoomTimeSlot $rts) {
                $slot = $rts->timeSlot;

                return [
                    'timeslot_id' => $rts->timeslot_id,
                    'time'        => $slot ? substr($slot->start_time, 0, 5) . ' - ' . substr($slot->end_time, 0, 5) : null,
                    'price'       => (int) $rts->price,
                    'over_night'  => (bool) $rts->over_night,
                    'promotions'  => $this->buildSlotPromotions($rts),
                ];
            })
            ->filter(fn ($s) => $s['price'] > 0)
            ->values()
            ->toArray();

        return ['slots' => $slots];
    }

    private function buildDailyPrice(Product $room): array
    {
        return [
            'amount'           => (float) $room->price,
            'default_checkin'  => $room->default_checkin,
            'default_checkout' => $room->default_checkout,
            'promotions'       => $this->buildDailyPromotions($room),
        ];
    }

    // ─────────────────────────────────────────────
    // PROMOTIONS
    // ─────────────────────────────────────────────

    private function buildSlotPromotions(RoomTimeSlot $rts): array
    {
        $now = now();

        return $rts->promotions
            ->where('is_active', true)
            ->filter(fn ($p) => $p->start_at <= $now && $p->end_at >= $now)
            ->map(fn ($p) => [
                'id'           => $p->id,
                'name'         => $p->name,
                'type'         => $p->type,
                'value'        => $p->value,
                'label'        => $p->lable_client,
                'custom_value' => $p->pivot->custom_value ?? null,
            ])
            ->values()
            ->toArray();
    }

    private function buildDailyPromotions(Product $room): array
    {
        $now = now();

        // Promotions liên kết với phòng này qua bất kỳ room_time_slot nào,
        // đang active và nằm trong khoảng thời gian hiện tại.
        return $room->roomTimeSlots
            ->flatMap(fn ($rts) => $rts->promotions)
            ->unique('id')
            ->where('is_active', true)
            ->filter(fn ($p) => $p->start_at <= $now && $p->end_at >= $now)
            ->map(fn ($p) => [
                'id'    => $p->id,
                'name'  => $p->name,
                'type'  => $p->type,
                'value' => $p->value,
                'label' => $p->lable_client,
            ])
            ->values()
            ->toArray();
    }

    // ─────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────

    private function promotionOverlapsSlot($promotion, Carbon $slotStart, Carbon $slotEnd): bool
    {
        if (! $promotion->start_at || ! $promotion->end_at) {
            return false;
        }
        return $slotStart->lt($promotion->end_at) && $slotEnd->gt($promotion->start_at);
    }
}
