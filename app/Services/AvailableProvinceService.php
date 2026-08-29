<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Province;
use App\Models\ProvinceBranch;
use Illuminate\Support\Facades\Cache;
use Modules\Category\Entities\Category;
use Modules\Product\App\Models\Product;

final class AvailableProvinceService
{
    private const CACHE_KEY = 'public:available-provinces:v1';

    /**
     * Provinces that have an active branch with at least one active, in-stock room.
     * The API and server-rendered web components must use this same source of truth.
     */
    public function get(): array
    {
        $resolve = function (): array {
            $activeBranches = ProvinceBranch::query()
                ->where('status', true)
                ->get(['province_id', 'categorie_id']);

            $branchCategoryIds = $activeBranches->pluck('categorie_id')->unique()->values();

            // ProvinceBranch.status chỉ nói tỉnh này có bật chi nhánh không — chi nhánh (Category gốc)
            // còn phải tự nó đang active thì mới cho hiển thị.
            $activeBranchCategoryIds = Category::query()
                ->whereIn('id', $branchCategoryIds)
                ->where('status', true)
                ->pluck('id');

            $activeBranches = $activeBranches->filter(
                fn (ProvinceBranch $branch): bool => $activeBranchCategoryIds->contains($branch->categorie_id)
            );

            $childCategoriesByParent = Category::query()
                ->whereIn('parent_id', $activeBranchCategoryIds)
                ->get(['id', 'parent_id'])
                ->groupBy('parent_id');

            $provinceIds = $activeBranches
                ->filter(function (ProvinceBranch $branch) use ($childCategoriesByParent): bool {
                    $categoryIds = collect([$branch->categorie_id])
                        ->merge($childCategoriesByParent->get($branch->categorie_id, collect())->pluck('id'));

                    return Product::query()
                        ->where('is_activated', true)
                        ->where('is_in_stock', true)
                        ->whereHas('categories', fn ($query) => $query->whereIn('category_id', $categoryIds))
                        ->exists();
                })
                ->pluck('province_id')
                ->unique();

            return Province::query()
                ->whereIn('id', $provinceIds)
                ->orderByRaw('code IS NULL, code ASC')
                ->orderBy('name')
                ->get(['id', 'name', 'slug', 'code', 'division_type', 'codename'])
                ->map(fn (Province $province) => [
                    'id' => $province->id,
                    'name' => $province->name,
                    'slug' => $province->slug,
                    'code' => $province->code,
                    'division_type' => $province->division_type,
                    'codename' => $province->codename,
                ])
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
