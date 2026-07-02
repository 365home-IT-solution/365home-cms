<?php

namespace Modules\BladeThemeV1\Livewire;

use App\Models\Province;
use Livewire\Component;
use Modules\Category\Entities\Category;
use Modules\Product\App\Models\Product;

class ProvinceList extends Component
{
    public array $provinces = [];

    public function mount(): void
    {
        $provinceModels = Province::whereHas('branches', fn ($q) => $q->where('status', true))
            ->with(['branches' => fn ($q) => $q->where('status', true)->select('id', 'province_id', 'categorie_id')])
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'lat', 'lng']);

        if ($provinceModels->isEmpty()) {
            $this->provinces = [];
            return;
        }

        $allBranchCatIds = $provinceModels
            ->flatMap(fn ($p) => $p->branches->pluck('categorie_id'))
            ->filter()->unique()->values()->toArray();

        $childToBranchMap = Category::whereIn('parent_id', $allBranchCatIds)
            ->pluck('parent_id', 'id')
            ->toArray();

        $allCatIds = array_merge($allBranchCatIds, array_keys($childToBranchMap));

        // Đếm phòng (1 query qua polymorphic)
        $roomCountByCatId = Category::whereIn('id', $allCatIds)
            ->withCount(['products as room_count' => fn ($q) =>
                $q->where('is_activated', true)->where('is_in_stock', true)
            ])
            ->get(['id'])
            ->pluck('room_count', 'id')
            ->toArray();

        $roomCountByBranchCat = [];
        foreach ($roomCountByCatId as $catId => $count) {
            $branchCatId = $childToBranchMap[$catId] ?? $catId;
            $roomCountByBranchCat[$branchCatId] = ($roomCountByBranchCat[$branchCatId] ?? 0) + $count;
        }

        // Lấy 1 ảnh sản phẩm đại diện cho mỗi branch (1 query)
        $productsWithMedia = Product::where('is_activated', true)
            ->whereHas('media')
            ->whereHas('categories', fn ($q) => $q->whereIn('categories.id', $allCatIds))
            ->with(['categories:id', 'media'])
            ->get(['id']);

        $imageByBranchCat = [];
        foreach ($productsWithMedia as $product) {
            $mediaFile = $product->media_file;
            if (!$mediaFile) continue;
            foreach ($product->categories as $cat) {
                $branchCatId = in_array($cat->id, $allBranchCatIds)
                    ? $cat->id
                    : ($childToBranchMap[$cat->id] ?? null);
                if ($branchCatId && !isset($imageByBranchCat[$branchCatId])) {
                    $imageByBranchCat[$branchCatId] = asset('storage/' . $mediaFile);
                }
            }
        }

        $this->provinces = $provinceModels->map(function ($province) use ($roomCountByBranchCat, $imageByBranchCat, $allBranchCatIds) {
            $provinceBranchCatIds = $province->branches->pluck('categorie_id')->toArray();

            $roomCount = array_sum(array_map(
                fn ($id) => $roomCountByBranchCat[$id] ?? 0,
                $provinceBranchCatIds
            ));

            $image = null;
            foreach ($provinceBranchCatIds as $bcId) {
                if (isset($imageByBranchCat[$bcId])) {
                    $image = $imageByBranchCat[$bcId];
                    break;
                }
            }

            return [
                'name'         => $province->name,
                'slug'         => $province->slug,
                'image'        => $image,
                'room_count'   => $roomCount,
                'branch_count' => $province->branches->count(),
            ];
        })->values()->toArray();
    }

    public function render()
    {
        return view('bladethemev1::livewire.province-list');
    }
}
