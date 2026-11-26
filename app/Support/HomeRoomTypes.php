<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Modules\Minihouse\App\Models\Building;
use Modules\Product\App\Models\Product;
use Modules\Product\App\Models\RoomType;

/**
 * "Loại hình dịch vụ" ở trang chủ web — chỉ hiện loại hình đang thật sự có hàng để bán:
 *  - mini_house: trỏ sang trang /minihouse (thuê theo tháng), hiện khi còn ít nhất 1 toà nhà
 *    MiniHouse đang bật (chi nhánh tồn tại) — phòng của MiniHouse không gắn room_type mini_house.
 *  - các loại còn lại: còn ít nhất 1 phòng đang bán (is_activated + is_in_stock) thuộc chi nhánh
 *    đang hoạt động (Product::activeBranch()).
 * Trước đây trang chủ in mọi RoomType is_active, nên loại chưa có phòng nào (Nhà nghỉ, Chung cư)
 * vẫn hiện, bấm vào ra trang trống.
 */
final class HomeRoomTypes
{
    private const CACHE_KEY = 'home:visible-room-types:v1';

    public const MINI_HOUSE_SLUG = 'mini_house';

    /** @return array<int, array{id:int, slug:string, name:string, icon:?string, icon_url:?string}> */
    public static function visible(): array
    {
        $resolve = static function (): array {
            $types = RoomType::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get(['id', 'slug', 'name', 'icon', 'icon_url']);

            $typeIdsWithRooms = Product::query()
                ->where('is_activated', true)
                ->where('is_in_stock', true)
                ->whereNotNull('room_type_id')
                ->activeBranch()
                ->distinct()
                ->pluck('room_type_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $hasMinihouseBuilding = null;

            return $types
                ->filter(function (RoomType $type) use ($typeIdsWithRooms, &$hasMinihouseBuilding): bool {
                    if (in_array((int) $type->id, $typeIdsWithRooms, true)) {
                        return true;
                    }

                    if ($type->slug === self::MINI_HOUSE_SLUG) {
                        return $hasMinihouseBuilding ??= Building::withoutGlobalScope('activeBuilding')
                            ->where('status', true)
                            ->exists();
                    }

                    return false;
                })
                ->map(fn (RoomType $type) => $type->only(['id', 'slug', 'name', 'icon', 'icon_url']))
                ->values()
                ->all();
        };

        try {
            return Cache::remember(self::CACHE_KEY, now()->addMinutes(5), $resolve);
        } catch (\Throwable) {
            return $resolve();
        }
    }
}
