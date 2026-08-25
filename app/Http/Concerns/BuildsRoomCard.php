<?php

declare(strict_types=1);

namespace App\Http\Concerns;

use App\Models\ProvinceBranch;
use App\Support\MediaThumbnailUrls;
use Carbon\Carbon;
use Modules\Category\Entities\Category;
use Modules\Product\App\Models\Product;
use Modules\Product\App\Models\TimeSlot;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

trait BuildsRoomCard
{
    // Toàn bộ chi nhánh đang active trên hệ thống + map category con → category chi nhánh cha
    // (dùng khi không có bộ lọc tỉnh/ward cụ thể — vd trang yêu thích, tìm kiếm toàn quốc).
    private function globalBranchLookup(): array
    {
        $branchCatIds = ProvinceBranch::where('status', true)->pluck('categorie_id')->unique()->values()->toArray();

        if (empty($branchCatIds)) {
            return ['cats' => collect(), 'childMap' => []];
        }

        $childMap = Category::whereIn('parent_id', $branchCatIds)->get(['id', 'parent_id'])->pluck('parent_id', 'id')->toArray();
        $cats     = Category::whereIn('id', $branchCatIds)->get(['id', 'name', 'slug'])->keyBy('id');

        // Khu vực (province slug) của từng chi nhánh — FE dùng để dựng URL canonical
        // /{type}/{province_slug}/{branch_slug}/{room_slug} (xem window.roomCardHtml() trong
        // public/js/home-sections.js — {type} lấy từ 'type_slug' của chính phòng, xem
        // mapRoom() bên dưới), gắn thẳng lên từng Category làm thuộc tính động (không lưu DB) để
        // resolveBranch() trả ra cùng lúc với id/name/slug.
        $provinceSlugByCat = ProvinceBranch::whereIn('categorie_id', $branchCatIds)
            ->with('province:id,slug')
            ->get()
            ->pluck('province.slug', 'categorie_id');
        foreach ($cats as $catId => $cat) {
            $cat->province_slug = $provinceSlugByCat[$catId] ?? null;
        }

        return ['cats' => $cats, 'childMap' => $childMap];
    }

    // Chi nhánh mà 1 phòng thuộc về (trực tiếp hoặc qua category con) — $room->categories phải
    // được eager-load trước khi gọi.
    private function resolveBranch(Product $room, \Illuminate\Support\Collection $branchCats, array $branchChildMap): ?array
    {
        if ($branchCats->isEmpty()) {
            return null;
        }

        foreach ($room->categories as $cat) {
            $branchCatId = null;
            if ($branchCats->has($cat->id)) {
                $branchCatId = $cat->id;
            } elseif (isset($branchChildMap[$cat->id])) {
                $branchCatId = $branchChildMap[$cat->id];
            }
            if ($branchCatId && $branchCats->has($branchCatId)) {
                $branch = $branchCats->get($branchCatId);
                return ['id' => $branch->id, 'name' => $branch->name, 'slug' => $branch->slug, 'province_slug' => $branch->province_slug];
            }
        }

        return null;
    }
    private function mapRoom(Product $room, ?bool $wishlistStatus = null, ?string $timeFrom = null, ?string $timeTo = null): array
    {
        $badge    = $room->badge;
        $isHourly = (int) $room->styles === 1;

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

        $mainImage = $this->getMainImage($room);

        return [
            'id'              => $room->id,
            'slug'            => $room->slug,
            'name'            => $room->name,
            // RoomType.slug thật (hotel/homestay/villa/motel/mini_house/apartment) — FE map sang
            // slug URL đẹp qua window.__typeUrlMap (home-sections.js) để dựng URL canonical
            // /{type}/{province_slug}/{branch_slug}/{slug} (xem window.roomCardHtml()).
            'type_slug'       => $room->roomType?->slug,
            'thumbnail_url'   => $mainImage?->getUrl(),
            'thumbnail'       => MediaThumbnailUrls::build($mainImage),
            'room_style'      => match ((int) $room->styles) {
                1       => 'theo_gio',
                2       => 'theo_ngay',
                default => $room->nights ? 'qua_dem' : null,
            },
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

    private function getMainImage(Product $room): ?Media
    {
        return $room->getFirstMedia('Ảnh bìa')
            ?? $room->getFirstMedia('Ảnh chính')
            ?? $room->getFirstMedia();
    }

    private function buildTimeSlots(Product $room, ?string $timeFrom = null, ?string $timeTo = null): array
    {
        $slots = $room->roomTimeSlots
            ->whereNull('date')
            ->whereNotIn('status', ['booked']);

        if ($timeFrom !== null && $timeTo !== null) {
            // OVERLAP — khung giờ chỉ cần giao với [timeFrom, timeTo] là tính hợp lệ, không bắt
            // buộc nằm trọn trong khoảng tìm. Phải khớp đúng logic filter DB ở
            // RoomSearchService::search(), nếu không phòng khớp tìm kiếm (overlap) có thể rơi vào
            // đây bị lọc hết slot (containment), khiến card hiển thị giá null dù đã match search.
            $slots = $slots->filter(function ($roomSlot) use ($timeFrom, $timeTo) {
                if ($roomSlot->over_night) {
                    return false;
                }
                $ts = $roomSlot->timeSlot;
                if (! $ts || ! $ts->start_time || ! $ts->end_time) {
                    return false;
                }
                return substr($ts->start_time, 0, 5) < $timeTo
                    && substr($ts->end_time, 0, 5) > $timeFrom;
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
