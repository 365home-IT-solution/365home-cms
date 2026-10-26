<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Modules\Product\App\Models\Product;

/**
 * Dịch vụ đi kèm (additional_services) gắn theo CHI NHÁNH (bảng additional_service_branches —
 * category gốc, category_type=product), phòng tự kế thừa toàn bộ dịch vụ của chi nhánh nó thuộc về.
 *
 * Toàn bộ luồng đặt phòng (API khách, API admin, Livewire Book, Filament OrderForm...) vẫn đọc
 * $room->additionalServices qua bảng room_additional_service_assigns — thay vì sửa ~15 chỗ đọc đó,
 * bảng này được giữ làm dữ liệu DẪN XUẤT: class này ghi lại nó mỗi khi dịch vụ đổi chi nhánh hoặc
 * phòng đổi chi nhánh. KHÔNG ghi tay vào room_additional_service_assigns ở chỗ nào khác nữa — sẽ bị
 * ghi đè ở lần đồng bộ kế tiếp.
 *
 * Phòng có thể gắn vào category CON (khu vực) chứ không chỉ category gốc — luôn đi ngược parent_id
 * lên tới gốc mới ra chi nhánh thật (cùng quy ước expandCategoryIds() ở ProductController).
 */
class BranchServiceSync
{
    /** Đồng bộ lại dịch vụ cho 1 phòng theo chi nhánh hiện tại của nó. */
    public function syncRoom(Product|string $room): void
    {
        $roomId = $room instanceof Product ? (string) $room->getKey() : $room;

        $serviceIds = $this->serviceIdsForBranches($this->rootBranchIdsForRoomId($roomId));

        DB::transaction(function () use ($roomId, $serviceIds) {
            DB::table('room_additional_service_assigns')
                ->where('room_id', $roomId)
                ->when($serviceIds, fn ($q) => $q->whereNotIn('additional_service_id', $serviceIds))
                ->delete();

            $existing = DB::table('room_additional_service_assigns')
                ->where('room_id', $roomId)
                ->pluck('additional_service_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $missing = array_diff($serviceIds, $existing);

            if ($missing) {
                DB::table('room_additional_service_assigns')->insert(array_map(
                    fn ($serviceId) => ['room_id' => $roomId, 'additional_service_id' => $serviceId],
                    array_values($missing)
                ));
            }
        });

        // Phòng mới / đổi chi nhánh: các bảng giá phạm vi chi nhánh / toàn đối tác nhận phòng này ngay
        // (cùng điểm móc với dịch vụ theo chi nhánh). Lỗi giá không được chặn việc lưu phòng.
        try {
            $product = $room instanceof Product ? $room : Product::withoutGlobalScopes()->find($roomId);

            if ($product) {
                app(\App\Services\PriceBoards\PriceBoardV2Service::class)->syncRoom($product);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Đồng bộ lại mọi phòng thuộc các chi nhánh $branchIds (kể cả phòng gắn vào category con). */
    public function syncBranches(array $branchIds): void
    {
        $branchIds = array_values(array_unique(array_filter(array_map('intval', $branchIds))));
        if (! $branchIds) {
            return;
        }

        $categoryIds = [];
        foreach ($branchIds as $branchId) {
            $categoryIds = array_merge($categoryIds, $this->expandCategoryIds($branchId));
        }

        $this->roomIdsInCategories(array_unique($categoryIds))->each(fn (string $roomId) => $this->syncRoom($roomId));
    }

    /** Đồng bộ lại TOÀN BỘ phòng — dùng 1 lần khi migrate, hoặc chạy tay khi nghi dữ liệu lệch. */
    public function syncAll(): void
    {
        $roomIds = DB::table('categorizables')
            ->where('categorizable_type', (new Product)->getMorphClass())
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('products')->whereColumn('products.id', 'categorizables.categorizable_id'))
            ->distinct()
            ->pluck('categorizable_id')
            ->merge(DB::table('room_additional_service_assigns')->distinct()->pluck('room_id'))
            ->map(fn ($id) => (string) $id)
            ->unique();

        $roomIds->each(fn (string $roomId) => $this->syncRoom($roomId));
    }

    /** Chi nhánh gốc của các category $categoryIds (category gắn phòng có thể là khu vực con). */
    public function rootBranchIdsForCategoryIds(array $categoryIds): array
    {
        $roots = [];
        foreach (array_filter(array_map('intval', $categoryIds)) as $categoryId) {
            if ($root = $this->rootOf($categoryId)) {
                $roots[] = $root;
            }
        }

        return array_values(array_unique($roots));
    }

    /** Chi nhánh (category gốc, category_type=product) của 1 phòng — thường chỉ 1, trả mảng phòng
     *  trường hợp dữ liệu cũ gắn phòng vào nhiều chi nhánh (khi đó phòng nhận HỢP dịch vụ). */
    public function rootBranchIdsForRoomId(string $roomId): array
    {
        $categoryIds = DB::table('categorizables')
            ->join('categories', 'categories.id', '=', 'categorizables.category_id')
            ->where('categorizables.categorizable_type', (new Product)->getMorphClass())
            ->where('categorizables.categorizable_id', $roomId)
            ->where('categories.category_type', 'product')
            ->whereNull('categories.deleted_at')
            ->pluck('categories.id')
            ->all();

        $roots = [];
        foreach ($categoryIds as $categoryId) {
            if ($rootId = $this->rootOf((int) $categoryId)) {
                $roots[$rootId] = $rootId;
            }
        }

        return array_values($roots);
    }

    /**
     * Dịch vụ (đang bật) dùng được cho các phòng $roomIds — đọc thẳng theo chi nhánh (nguồn gốc thật),
     * không qua room_additional_service_assigns, để danh sách luôn đúng chi nhánh kể cả khi bảng dẫn
     * xuất chưa kịp đồng bộ lại. Bỏ scope đối tác: nơi gọi tự kiểm tra quyền trên đơn/phòng.
     */
    public function activeServicesQueryForRooms(array $roomIds)
    {
        $branchIds = [];
        foreach (array_filter(array_map('strval', $roomIds)) as $roomId) {
            $branchIds = array_merge($branchIds, $this->rootBranchIdsForRoomId($roomId));
        }

        return \Modules\BladeThemeV1\App\Models\AdditionService::withoutGlobalScope('partner')
            ->where('additional_services.is_active', true)
            ->whereIn('additional_services.id', $this->serviceIdsForBranches(array_unique($branchIds)));
    }

    private function serviceIdsForBranches(array $branchIds): array
    {
        if (! $branchIds) {
            return [];
        }

        // Chỉ dịch vụ CÙNG đối tác với chi nhánh — liên kết chéo đối tác (dữ liệu cũ, hoặc super admin
        // chọn nhầm chi nhánh của đối tác khác) không được lan sang phòng, nếu không khách đặt phòng
        // đối tác này thấy dịch vụ của đối tác khác.
        return DB::table('additional_service_branches')
            ->join('additional_services', 'additional_services.id', '=', 'additional_service_branches.additional_service_id')
            ->join('categories', 'categories.id', '=', 'additional_service_branches.category_id')
            ->whereIn('additional_service_branches.category_id', $branchIds)
            ->whereColumn('additional_services.partner_id', 'categories.partner_id')
            ->distinct()
            ->pluck('additional_service_branches.additional_service_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function roomIdsInCategories(array $categoryIds)
    {
        return DB::table('categorizables')
            ->where('categorizable_type', (new Product)->getMorphClass())
            ->whereIn('category_id', $categoryIds)
            // categorizables không có FK nên còn sót dòng trỏ tới phòng đã xoá hẳn khỏi products.
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('products')->whereColumn('products.id', 'categorizables.categorizable_id'))
            ->distinct()
            ->pluck('categorizable_id')
            ->map(fn ($id) => (string) $id);
    }

    private function rootOf(int $categoryId): ?int
    {
        $seen = [];

        while ($categoryId && ! isset($seen[$categoryId])) {
            $seen[$categoryId] = true;

            $parentId = DB::table('categories')->where('id', $categoryId)->value('parent_id');
            if ($parentId === null) {
                return $categoryId;
            }

            $categoryId = (int) $parentId;
        }

        return null;
    }

    private function expandCategoryIds(int $categoryId): array
    {
        $allIds       = [$categoryId];
        $currentLevel = [$categoryId];

        while ($currentLevel) {
            $children = DB::table('categories')->whereIn('parent_id', $currentLevel)->pluck('id')->map(fn ($id) => (int) $id)->all();
            $children = array_diff($children, $allIds);

            $allIds       = array_merge($allIds, $children);
            $currentLevel = $children;
        }

        return $allIds;
    }
}
