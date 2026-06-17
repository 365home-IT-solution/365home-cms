<?php

declare(strict_types=1);

namespace App\Http\Concerns;

use Carbon\Carbon;
use Modules\Product\App\Models\Product;
use Modules\Product\App\Models\TimeSlot;

trait BuildsRoomCard
{
    private function mapRoom(Product $room, ?bool $wishlistStatus = null, ?string $timeFrom = null, ?string $timeTo = null): array
    {
        $badge    = $room->badge;
        $roomType = $room->relationLoaded('roomType') ? $room->roomType : null;
        $isHourly = $roomType?->slug === 'theo_gio';

        if ($isHourly) {
            $timeSlots = $this->buildTimeSlots($room, $timeFrom, $timeTo);
            $firstSlot = collect($timeSlots)->first();
            $price     = $firstSlot
                ? ['amount' => $firstSlot['amount'], 'unit_label' => '/ ' . ($firstSlot['label'] ?? 'khung giờ')]
                : null;
        } else {
            $price = [
                'amount'     => (float) $room->price,
                'unit_label' => $this->priceUnitLabel($room->price_unit) ?? '/ ngày',
            ];
        }

        return [
            'id'              => $room->id,
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
            'price'           => $price,
            'rating'          => $room->rating_score !== null ? (float) $room->rating_score : null,
            'wishlist_status' => $wishlistStatus,
            'is_available'    => $room->is_in_stock,
            'latitude'        => $room->latitude  ? (string) $room->latitude  : null,
            'longitude'       => $room->longitude ? (string) $room->longitude : null,
        ];
    }

    private function getMainImageUrl(Product $room): ?string
    {
        $media = $room->getFirstMedia('Ảnh bìa')
              ?? $room->getFirstMedia('Ảnh chính')
              ?? $room->getFirstMedia();

        return $media?->getUrl();
    }

    private function buildTimeSlots(Product $room, ?string $timeFrom = null, ?string $timeTo = null): array
    {
        $slots = $room->roomTimeSlots
            ->whereNull('date')
            ->whereNotIn('status', ['booked']);

        if ($timeFrom !== null && $timeTo !== null) {
            $slots = $slots->filter(function ($roomSlot) use ($timeFrom, $timeTo) {
                if ($roomSlot->over_night) {
                    return false;
                }
                $ts = $roomSlot->timeSlot;
                if (! $ts || ! $ts->start_time || ! $ts->end_time) {
                    return false;
                }
                return substr($ts->start_time, 0, 5) >= $timeFrom
                    && substr($ts->end_time, 0, 5) <= $timeTo;
            });
        }

        return $slots
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
