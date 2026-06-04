<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\BuildsRoomCard;
use App\Models\Wishlist;
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

    public function show(string $slug): JsonResponse
    {
        $room = Product::where('slug', $slug)
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

        $wishlistStatus = null;
        if (auth()->check()) {
            $wishlistStatus = Wishlist::where('user_id', auth()->id())
                ->where('product_id', $room->id)
                ->exists();
        }

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

        $dateStr = $request->query('date');
        if (! $dateStr) {
            return response()->json(['message' => 'Thiếu tham số date (YYYY-MM-DD).'], 422);
        }

        try {
            $date = Carbon::createFromFormat('Y-m-d', $dateStr)->startOfDay();
        } catch (\Throwable) {
            return response()->json(['message' => 'Định dạng date không hợp lệ. Dùng YYYY-MM-DD.'], 422);
        }

        // All template slots for this room (keep $rts reference for promotions/blocked check)
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

        // Active order items that could overlap with the given date
        $activeItems = OrderItem::query()
            ->where('product_id', $room->id)
            ->whereNotNull('checkin_date')
            ->whereNotNull('checkout_date')
            ->where('checkout_date', '>', $date)
            ->where('checkin_date', '<', $date->copy()->addDay())
            ->whereHas('order', fn ($q) => $q->whereIn('status', ['pending', 'paid', 'deposit', 'shipped', 'confirmed']))
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

        $slots = $templateSlots->map(function ($slot) use ($bookedMap, $dateStr) {
            $rts       = $slot['rts'];
            $basePrice = $slot['price'];

            // Blocked date check (from roomTimeSlot settings)
            $isBlocked = $rts->isBlockedOn($dateStr);

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
                'promotions'   => $promotionData,
            ];
        })->values()->toArray();

        return response()->json([
            'date'  => $dateStr,
            'slots' => $slots,
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
            'gallery'           => $this->buildGallery($room),
            'wishlist_status'   => $wishlistStatus,
            'is_available'      => $room->is_in_stock,
            'room_type'         => $room->roomType?->slug,
            'amenities'           => $this->buildAmenities($room),
            'additional_services' => $this->buildServices($room),
            'specials'            => $this->buildSpecials($room),
            'prices'              => $this->buildPrices($room),
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
        return $room->roomType?->slug === 'theo_gio'
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
