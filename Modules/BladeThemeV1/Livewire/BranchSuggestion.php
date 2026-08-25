<?php

namespace Modules\BladeThemeV1\Livewire;

use App\Models\Province;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Livewire\Component;

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
    public array $branches = [];

    public function setProvince($id, $name = null): void
    {
        $this->provinceId = $id ? (int) $id : null;
        $this->provinceName = (string) ($name ?? '');
        $this->loadBranches();
    }

    protected function loadBranches(): void
    {
        if (! $this->provinceId) {
            $this->branches = [];
            return;
        }

        $province = Province::find($this->provinceId);

        if (! $province) {
            $this->branches = [];
            return;
        }

        $this->branches = $province->branches()
            ->where('status', true)
            ->whereHas('category', fn ($q) => $q->where('status', true))
            ->with('category')
            ->get()
            ->sortBy(fn ($branch) => $branch->category->sort_order)
            ->values()
            ->map(fn ($branch) => [
                'id'        => $branch->category->id,
                'name'      => $branch->category->name,
                'slug'      => $branch->category->slug,
                // Prefer the pre-generated "card" preset (480px, avif) over the full-size original —
                // this card only renders at ~170-380px wide. Falls back to the original when the
                // preset hasn't been generated yet (see docs/be-image-thumbnails.md §4).
                'image_url' => $branch->category->thumbnail['card']
                    ?? ($branch->category->image ? Storage::disk('public')->url($branch->category->image) : null),
            ])
            ->values()
            ->toArray();

        $this->provinceName = $this->provinceName ?: $province->name;
    }

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
