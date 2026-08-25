<?php

namespace Modules\BladeThemeV1\Support;

use App\Models\ProvinceBranch;
use Modules\Category\Entities\Category;
use Modules\Product\App\Models\Product;

class BranchBookConfig
{
    /**
     * Chi nhánh + khu vực (province) mà 1 phòng thuộc về — dùng cho URL canonical
     * /homestay/{location}/{branch}/{slug} của trang chi tiết phòng (BladeThemeV1Controller::
     * renderProductDetail()). $product->categories phải được eager-load trước khi gọi. Mỗi phòng
     * chỉ nên thuộc đúng 1 chi nhánh (kiểm tra thực tế trên dữ liệu: 0/59 phòng gắn >1 chi nhánh) —
     * lấy chi nhánh ĐẦU TIÊN tìm thấy nếu lỡ có nhiều hơn.
     */
    public static function resolveLocationForProduct(Product $product): ?array
    {
        $branchCatIds = ProvinceBranch::pluck('categorie_id')->unique()->values();
        if ($branchCatIds->isEmpty()) {
            return null;
        }

        $childToBranch = Category::whereIn('parent_id', $branchCatIds)->pluck('parent_id', 'id');

        $branchCatId = null;
        foreach ($product->categories as $cat) {
            if ($branchCatIds->contains($cat->id)) {
                $branchCatId = $cat->id;
                break;
            }
            if (isset($childToBranch[$cat->id])) {
                $branchCatId = $childToBranch[$cat->id];
                break;
            }
        }

        if (! $branchCatId) {
            return null;
        }

        $provinceBranch = ProvinceBranch::where('categorie_id', $branchCatId)
            ->with(['province', 'category'])
            ->first();

        if (! $provinceBranch || ! $provinceBranch->province || ! $provinceBranch->category) {
            return null;
        }

        return [
            'province_slug' => $provinceBranch->province->slug,
            'branch_slug'   => $provinceBranch->category->slug,
        ];
    }

    /**
     * URL trang chi tiết 1 phòng (hoặc dịch vụ) — dùng chung cho mọi nơi render card sản phẩm
     * (components/products/{card,minimal,list-page,overlay}.blade.php). Sản phẩm type=service vẫn
     * đi route cũ (template.detail); phòng (type=simple) dùng URL canonical
     * /homestay/{location}/{branch}/{slug} khi xác định được chi nhánh, rơi về /room/{slug}/ khi
     * không — server tự 301 sang canonical nếu có (xem
     * BladeThemeV1Controller::renderProductDetail()). $product->categories phải được eager-load
     * trước khi gọi (bỏ qua với type=service, không cần).
     */
    public static function resolveProductUrl(Product $product): string
    {
        if ($product->type === 'service') {
            return route('template.detail', ['slug' => $product->slug]);
        }

        $loc = self::resolveLocationForProduct($product);

        return $loc
            ? url('/homestay/' . $loc['province_slug'] . '/' . $loc['branch_slug'] . '/' . $product->slug . '/')
            : url('/room/' . $product->slug . '/');
    }
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
