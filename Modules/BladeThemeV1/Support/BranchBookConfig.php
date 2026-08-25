<?php

namespace Modules\BladeThemeV1\Support;

use Modules\Category\Entities\Category;
use Modules\Product\App\Models\Product;

class BranchBookConfig
{
    /**
     * Chi nhánh (Category gốc) + config cho Livewire Book component (danh sách phòng khả dụng
     * theo khung giờ). Dùng chung cho trang chi tiết chi nhánh (/branch/{slug}) và panel đặt
     * lịch inline ở trang tìm kiếm (?view=branches) để 2 nơi luôn lọc phòng giống hệt nhau.
     */
    public static function build(string $slug): ?array
    {
        $branch = Category::where('slug', $slug)->first();

        if (! $branch) {
            return null;
        }

        $categoryIds = array_merge(
            [$branch->id],
            Category::where('parent_id', $branch->id)->pluck('id')->toArray()
        );

        $productIds = Product::where('is_activated', true)
            ->where('is_in_stock', true)
            ->where(function ($q) {
                $q->where('styles', 1)->orWhereNull('styles');
            })
            ->whereHas('categories', fn ($q) => $q->whereIn('categories.id', $categoryIds))
            ->whereHas('roomTimeSlots.timeSlot')
            ->orderBy('sort_order')
            ->get(['id'])
            ->pluck('id')
            ->values()
            ->toArray();

        return [
            'branch' => $branch,
            'bookConfig' => [
                'bookable_room_count' => count($productIds),
                'component' => [
                    'choose_room' => json_encode([
                        'categories' => [
                            [
                                'category_id' => $branch->id,
                                'products' => $productIds,
                            ],
                        ],
                    ], JSON_UNESCAPED_UNICODE),
                    'title_booking' => 'Đặt phòng ' . $branch->name,
                    'sub_title_booking' => 'Chọn phòng và mốc thời gian phù hợp tại chi nhánh này.',
                    'image_event' => '',
                ],
            ],
        ];
    }

    /**
     * Config rỗng để mount trước Book component khi chưa có chi nhánh nào được chọn
     * (panel đặt lịch inline ở trang ?view=branches).
     */
    public static function empty(): array
    {
        return [
            'bookable_room_count' => 0,
            'component' => [
                'choose_room' => json_encode(['categories' => []], JSON_UNESCAPED_UNICODE),
                'title_booking' => '',
                'sub_title_booking' => '',
                'image_event' => '',
            ],
        ];
    }
}
