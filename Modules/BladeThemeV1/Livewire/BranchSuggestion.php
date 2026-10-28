<?php

namespace Modules\BladeThemeV1\Livewire;

use App\Models\Province;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Livewire\Component;
use Modules\BladeThemeV1\Support\BranchBookConfig;
use Modules\Category\Entities\Category;
use Modules\Product\App\Models\Product;

/**
 * "Các chi nhánh tại {khu vực}" ở trang chủ — trước đây lấy dữ liệu qua API /api/v1/home dùng
 * chung với app mobile (suggestion_list, suggestion_type=branch). Giờ query thẳng DB, không qua
 * API đó nữa. Khu vực đang chọn chỉ tồn tại ở phía client (localStorage, xem
 * livewire/location-modal.blade.php) — component này không biết được lúc mount() (SSR ban đầu),
 * nên phải đợi phía client gọi setProvince() qua $wire (xem branch-suggestion.blade.php): 1 lần
 * ngay khi Alpine init (đọc localStorage sẵn có), và 1 lần mỗi khi có sự kiện 'province-selected'
 * (đổi khu vực) — cùng cơ chế 'province-selected' mà location-modal.blade.php đã dùng.
 */
class BranchSuggestion extends Component
{
    public ?int $provinceId = null;
    public string $provinceName = '';
    public string $provinceSlug = '';
    public array $branches = [];
    // Chi nhánh chia theo khu vực trong tỉnh (VD "Đà Lạt", "Phan Thiết" cùng thuộc Tỉnh Lâm Đồng sau
    // sáp nhập 2025) — mỗi nhóm 1 hàng riêng với tiêu đề "{khu vực}, {tỉnh}", xem groupByArea().
    public array $groups = [];

    public function setProvince($id, $name = null): void
    {
        $this->provinceId = $id ? (int) $id : null;
        $this->provinceName = (string) ($name ?? '');
        $this->loadBranches();
    }

    protected function loadBranches(): void
    {
        $this->branches = [];
        $this->groups = [];

        if (! $this->provinceId) {
            return;
        }

        $province = Province::find($this->provinceId);

        if (! $province) {
            return;
        }

        $this->branches = $province->branches()
            ->where('status', true)
            ->whereHas('category', fn ($q) => $q->where('status', true))
            ->with(['category', 'ward'])
            ->get()
            // Chi nhánh chưa có phòng nào đang mở bán thì không hiện (cùng điều kiện với các hàng phòng
            // theo chi nhánh ở trang chủ — HomeController::fetchBranchRows()).
            ->filter(fn ($branch) => $this->branchHasRooms((int) $branch->category->id))
            ->sortBy(fn ($branch) => $branch->category->sort_order)
            ->values()
            ->map(function ($branch) {
                // Loại hình của chi nhánh — mỗi chi nhánh trong khối này có thể khác loại hình
                // nhau (khối liệt kê CẢ TỈNH, không lọc theo 1 loại hình), nên phải resolve RIÊNG
                // từng chi nhánh (không dùng chung 1 loại cho cả khối) để mỗi card trỏ đúng URL
                // canonical /{type}/{location}/{slug} của chính chi nhánh đó.
                $loc = BranchBookConfig::resolveTypeAndLocationForBranch($branch->category);

                return [
                    'area'          => $branch->areaName(),
                    'id'            => $branch->category->id,
                    'name'          => $branch->category->name,
                    'slug'          => $branch->category->slug,
                    'type_url_slug' => $loc['type_url_slug'] ?? null,
                    // Prefer the pre-generated "card" preset (480px, avif) over the full-size original —
                    // this card only renders at ~170-380px wide. Falls back to the original when the
                    // preset hasn't been generated yet (see docs/be-image-thumbnails.md §4).
                    'image_url' => $branch->category->thumbnail['card']
                        ?? ($branch->category->image ? Storage::disk('public')->url($branch->category->image) : null),
                ];
            })
            ->values()
            ->toArray();

        $this->provinceName = $this->provinceName ?: $province->name;
        $this->provinceSlug = $province->slug;
        $this->groups = $this->groupByArea($this->branches);
    }

    /**
     * Nhóm theo khu vực, giữ thứ tự sort_order (nhóm xếp theo chi nhánh đầu tiên của nhóm); chi
     * nhánh chưa xác định được khu vực gom vào 1 nhóm cuối, tiêu đề chỉ có tên tỉnh như trước.
     */
    private function groupByArea(array $branches): array
    {
        $groups = collect($branches)
            ->groupBy(fn (array $branch) => $branch['area'] ? mb_strtolower($branch['area']) : '')
            ->sortBy(fn ($items, $key) => $key === '' ? 1 : 0, SORT_REGULAR)
            ->map(fn ($items) => [
                'area'     => $items->first()['area'],
                'title'    => $items->first()['area']
                    ? $items->first()['area'] . ', ' . $this->provinceName
                    : $this->provinceName,
                'branches' => $items->values()->all(),
            ])
            ->values();

        return $groups->all();
    }

    private function branchHasRooms(int $branchCategoryId): bool
    {
        $filterIds = Category::where('parent_id', $branchCategoryId)->pluck('id')->push($branchCategoryId)->all();

        return Product::where('is_activated', true)
            ->where('is_in_stock', true)
            ->activeBranch()
            ->whereHas('categories', fn ($q) => $q->whereIn('category_id', $filterIds))
            ->exists();
    }

    // Khối này liệt kê chi nhánh CẢ TỈNH (mọi loại hình trộn chung, xem loadBranches() ở trên) nên
    // "Xem tất cả" phải về đúng URL danh sách chi nhánh KHÔNG lọc loại hình (/s/{location}?view=
    // branches) — trước đây trỏ nhầm sang /homestay/{location} (chỉ đúng khi vô tình cả tỉnh chỉ
    // có chi nhánh homestay), gán sai ý nghĩa "khu vực" cho URL vốn dành riêng cho loại hình.
    public function getViewAllUrlProperty(): ?string
    {
        if (! $this->provinceId) {
            return null;
        }

        $province = Province::find($this->provinceId);

        return $province ? '/s/' . $province->slug . '?view=branches' : null;
    }

    public function render(): View
    {
        return view('bladethemev1::livewire.branch-suggestion');
    }
}
