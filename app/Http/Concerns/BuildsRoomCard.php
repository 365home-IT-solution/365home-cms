<?php

declare(strict_types=1);

namespace App\Http\Concerns;

use Carbon\Carbon;
use Modules\Product\App\Models\Product;
use Modules\Product\App\Models\TimeSlot;

trait BuildsRoomCard
{
    private function mapRoom(Product $room, ?bool $wishlistStatus = null): array
    {
        $badge     = $room->badge;
        $timeSlots = $this->buildTimeSlots($room);

        if (! empty($timeSlots)) {
            $basePrice = collect($timeSlots)->sum('amount');
            $baseLabel = '/ ngày';
            $baseUnit  = 'per_day';
        } else {
            $basePrice = (float) $room->price;
            $baseLabel = $this->priceUnitLabel($room->price_unit);
            $baseUnit  = $room->price_unit;
        }

        $roomType = $room->relationLoaded('roomType') ? $room->roomType : null;

        return [
            'slug'            => $room->slug,
            'name'            => $room->name,
            'thumbnail_url'   => $this->getMainImageUrl($room),
            'room_type_id'    => $roomType?->id,
            'room_type_slug'  => $roomType?->slug,
            'badge'           => $badge ? [
                'label'      => $badge['label'] ?? null,
                'type'       => $badge['type'] ?? null,
                'bg_color'   => $badge['bg_color'] ?? '#FFFFFF',
                'text_color' => $badge['text_color'] ?? '#1F2937',
            ] : null,
            'price' => [
                'amount'     => $basePrice,
                'currency'   => 'VND',
                'unit'       => $baseUnit,
                'unit_label' => $baseLabel,
                'time_slots' => $timeSlots,
            ],
            'rating'          => $room->rating_score !== null ? [
                'score'     => (float) $room->rating_score,
                'show_star' => true,
            ] : null,
            'wishlist_status' => $wishlistStatus,
            'is_available'    => $room->is_in_stock,
        ];
    }

    private function getMainImageUrl(Product $room): ?string
    {
        $media = $room->getFirstMedia('Ảnh bìa')
              ?? $room->getFirstMedia('Thư viện')
              ?? $room->getFirstMedia();

        return $media?->getUrl();
    }

    private function buildTimeSlots(Product $room): array
    {
        return $room->roomTimeSlots
            ->whereNull('date')
            ->whereNotIn('status', ['booked'])
            ->groupBy('timeslot_id')
            ->map(function ($group) {
                $slot  = $group->sortBy('price')->first();
                $price = (int) $slot->price;
                $label = $this->formatDurationLabel($slot->timeSlot);

                return $price > 0 && $label !== ''
                    ? ['amount' => $price, 'label' => $label]
                    : null;
            })
            ->filter()
            ->unique('amount')
            ->sortBy('amount')
            ->values()
            ->toArray();
    }

    private function formatDurationLabel(?TimeSlot $slot): string
    {
        if (! $slot) {
            return '';
        }

        $label = $slot->label ?? '';

        if (! preg_match('/\d{1,2}:\d{2}\s*-\s*\d{1,2}:\d{2}/', $label)) {
            return $label;
        }

        if ($slot->start_time && $slot->end_time) {
            $start = Carbon::parse($slot->start_time);
            $end   = Carbon::parse($slot->end_time);

            if ($end->lte($start)) {
                $end->addDay();
            }

            $minutes = $start->diffInMinutes($end);
            $hours   = intdiv($minutes, 60);
            $mins    = $minutes % 60;

            return $mins > 0 ? "{$hours}h{$mins}" : "{$hours}h";
        }

        return $label;
    }

    private function priceUnitLabel(?string $unit): ?string
    {
        return match ($unit) {
            'per_hour'  => '/ giờ',
            'per_night' => '/ đêm',
            'per_day'   => '/ ngày',
            default     => null,
        };
    }

}
