<?php

declare(strict_types=1);

namespace Modules\Dashboard\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Modules\Category\Entities\Category;

/**
 * Chuyển tham số 'categories' (danh sách slug chi nhánh, phân cách bởi dấu phẩy) hoặc 'branch_id'
 * (1 id) trên query string thành danh sách category_id để lọc dữ liệu theo chi nhánh — dùng chung
 * bởi DashboardController và ReportController để không lặp lại logic này ở 2 nơi.
 */
trait ResolvesReportFilters
{
    /**
     * Category_id để lọc Order/Product — gồm cả category con (khu vực/tầng...) của mỗi chi nhánh.
     *
     * - Không truyền gì            → null (không lọc thêm, dùng toàn bộ chi nhánh user được phép xem)
     * - Truyền nhưng không khớp gì → [] (lọc về rỗng, KHÔNG âm thầm trả toàn bộ dữ liệu)
     */
    private function resolveBranchCategoryIds(Request $request): ?array
    {
        $slugs = collect(explode(',', (string) $request->query('categories', '')))
            ->map(fn ($s) => trim($s))
            ->filter()
            ->values();

        if ($slugs->isNotEmpty()) {
            $branchIds = Category::whereNull('parent_id')
                ->where('category_type', 'product')
                ->whereIn('slug', $slugs)
                ->pluck('id');

            $ids = [];
            foreach ($branchIds as $branchId) {
                $childIds = Category::where('parent_id', $branchId)->pluck('id')->toArray();
                $ids = array_merge($ids, [$branchId], $childIds);
            }

            return array_values(array_unique($ids));
        }

        $branchId = $request->query('branch_id');
        if ($branchId && ctype_digit((string) $branchId)) {
            $branchId = (int) $branchId;
            $childIds = Category::where('parent_id', $branchId)->pluck('id')->toArray();

            return array_merge([$branchId], $childIds);
        }

        return null;
    }

    /**
     * Chỉ lấy id CHI NHÁNH GỐC (parent_id=null) từ 'categories'/'branch_id' — dùng để lọc các bảng
     * kho (warehouse_items.branch_id...) vốn CHỈ lưu id chi nhánh gốc, không lưu category con.
     * Trả về [] nếu không truyền categories/branch_id (khác resolveBranchCategoryIds() trả null) —
     * ReportScope::branchIds() coi [] là "không giới hạn thêm, dùng toàn bộ chi nhánh user được phép".
     */
    private function resolveRootBranchIds(Request $request): array
    {
        $slugs = collect(explode(',', (string) $request->query('categories', '')))
            ->map(fn ($s) => trim($s))
            ->filter()
            ->values();

        if ($slugs->isNotEmpty()) {
            return Category::whereNull('parent_id')
                ->where('category_type', 'product')
                ->whereIn('slug', $slugs)
                ->pluck('id')
                ->toArray();
        }

        $branchId = $request->query('branch_id');
        if ($branchId && ctype_digit((string) $branchId)) {
            return [(int) $branchId];
        }

        return [];
    }
}
