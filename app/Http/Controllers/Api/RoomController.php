<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\BuildsRoomCard;
use App\Models\Wishlist;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
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
                'amenities',
                'services',
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
            'main'              => $this->buildMainImages($room),
            'gallery'           => $this->buildGallery($room),
            'wishlist_status'   => $wishlistStatus,
            'is_available'      => $room->is_in_stock,
            'room_type'         => $room->roomType?->slug,
            'amenities'         => $this->buildAmenities($room),
            'additional_services' => $this->buildServices($room),
            'specials'          => $this->buildSpecials($room),
            'prices'            => $this->buildPrices($room),
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
    // AMENITIES — grouped by amenity_type
    // ─────────────────────────────────────────────

    private function buildAmenities(Product $room): array
    {
        return $room->amenities
            ->groupBy('amenity_type')
            ->map(fn ($items, $type) => [
                'type'  => $type,
                'items' => $items->map(fn ($a) => [
                    'id'   => $a->id,
                    'icon' => $a->icon,
                    'name' => $a->name,
                ])->values()->toArray(),
            ])
            ->values()
            ->toArray();
    }

    // ─────────────────────────────────────────────
    // SERVICES
    // ─────────────────────────────────────────────

    private function buildServices(Product $room): array
    {
        return $room->services->map(fn ($s) => [
            'id'          => $s->id,
            'name'        => $s->name,
            'description' => $s->description,
            'price'       => $s->price,
            'unit'        => $s->unit,
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
}
