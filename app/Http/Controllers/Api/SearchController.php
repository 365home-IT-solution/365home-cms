<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesProvince;
use App\Services\RoomSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Modules\Category\Entities\Category;
use Modules\Product\App\Models\Product;

class SearchController extends Controller
{
    use ResolvesProvince;

    // ─── GET /v1/search/suggestions ─────────────────────────────────────────

    public function suggestions(): JsonResponse
    {
        $nearby = [
            'id'          => 'nearby',
            'name'        => 'Lân cận',
            'description' => 'Tìm phòng xung quanh bạn',
            'type'        => 'nearby',
            'icon'        => 'navigate-outline',
            'icon_color'  => '#4A90D9',
            'bg_color'    => '#E8F0FE',
            'query'       => null,
            'latitude'    => null,
            'longitude'   => null,
        ];

        $locations = Product::where('is_activated', true)
            ->where('is_in_stock', true)
            ->whereNotNull('address')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->selectRaw('address, MIN(latitude) as latitude, MIN(longitude) as longitude, COUNT(*) as room_count')
            ->groupBy('address')
            ->orderByDesc('room_count')
            ->limit(10)
            ->get()
            ->map(fn ($row, $index) => [
                'id'          => (string) ($index + 1),
                'name'        => $row->address,
                'description' => $row->room_count . ' phòng',
                'type'        => 'location',
                'icon'        => 'location-outline',
                'icon_color'  => '#4CAF50',
                'bg_color'    => '#E8F5E9',
                'query'       => $row->address,
                'latitude'    => (string) $row->latitude,
                'longitude'   => (string) $row->longitude,
            ])
            ->values();

        return response()->json(['data' => collect([$nearby])->merge($locations)->values()]);
    }

    // ─── GET /v1/search/branches ─────────────────────────────────────────────
    // Dùng cho "Xem tất cả" của block Gợi ý điểm đến (loại Chi nhánh) trên mobile —
    // trả về danh sách chi nhánh của khu vực thay vì danh sách phòng.
    public function branches(Request $request): JsonResponse
    {
        $province = $this->resolveProvince($request);

        if ($province === null) {
            return response()->json([
                'data' => [],
                'meta' => ['province_name' => null, 'total' => 0],
            ]);
        }

        $branchRows = $province->branches()
            ->where('status', true)
            ->with('category')
            ->get()
            ->filter(fn ($branch) => $branch->category && $branch->category->status);

        // Đếm số phòng khả dụng theo từng chi nhánh (tính cả category con) — cùng pattern đã
        // dùng ở ProvinceList::mount() (Modules/BladeThemeV1/Livewire/ProvinceList.php).
        $branchCatIds = $branchRows->pluck('category.id')->filter()->values()->toArray();
        $childToBranchMap = Category::whereIn('parent_id', $branchCatIds)->pluck('parent_id', 'id')->toArray();
        $allCatIds = array_merge($branchCatIds, array_keys($childToBranchMap));
        $roomCountByCatId = Category::whereIn('id', $allCatIds)
            ->withCount(['products as room_count' => fn ($q) => $q->where('is_activated', true)->where('is_in_stock', true)])
            ->pluck('room_count', 'id');
        $roomCountByBranch = [];
        foreach ($roomCountByCatId as $catId => $count) {
            $branchCatId = $childToBranchMap[$catId] ?? $catId;
            $roomCountByBranch[$branchCatId] = ($roomCountByBranch[$branchCatId] ?? 0) + $count;
        }

        // Chi nhánh nào có phòng đang khuyến mãi giảm giá (percentage/fixed) hiệu lực ngay bây
        // giờ thì đẩy lên đầu danh sách — cùng cách xác định "có khuyến mãi" như đã dùng cho icon
        // flash-sale cạnh tên phòng (book/_mobile.blade.php, book/_desktop-grid.blade.php), chỉ
        // khác là gộp theo TOÀN BỘ chi nhánh (branch + category con) thay vì từng phòng riêng lẻ.
        $catIdsWithPromo = Product::whereHas('categories', fn ($q) => $q->whereIn('categories.id', $allCatIds))
            ->whereHas('roomTimeSlots.promotions', function ($q) {
                $q->whereIn('type', ['percentage', 'fixed'])
                    ->where('is_active', true)
                    ->where('start_at', '<=', now())
                    ->where('end_at', '>=', now());
            })
            ->with(['categories' => fn ($q) => $q->whereIn('categories.id', $allCatIds)])
            ->get()
            ->pluck('categories')
            ->flatten()
            ->pluck('id')
            ->unique();

        $branchHasPromoByBranch = [];
        foreach ($catIdsWithPromo as $catId) {
            $branchCatId = $childToBranchMap[$catId] ?? $catId;
            $branchHasPromoByBranch[$branchCatId] = true;
        }

        // Toạ độ để ghim trên bản đồ (?view=branches, search.blade.php) — Branch/Category không có
        // cột lat/lng riêng, toạ độ nằm ở từng sản phẩm (products.latitude/longitude); lấy đại diện
        // 1 sản phẩm có toạ độ của mỗi chi nhánh (gộp cả category con qua $childToBranchMap, cùng
        // cách nhóm đã dùng cho room_count/has_promotion ở trên).
        $coordsByBranch = [];
        Product::whereHas('categories', fn ($q) => $q->whereIn('categories.id', $allCatIds))
            ->whereNotNull('latitude')->whereNotNull('longitude')
            ->with(['categories' => fn ($q) => $q->whereIn('categories.id', $allCatIds)])
            ->get()
            ->each(function ($product) use (&$coordsByBranch, $childToBranchMap) {
                foreach ($product->categories as $category) {
                    $branchCatId = $childToBranchMap[$category->id] ?? $category->id;
                    if (!isset($coordsByBranch[$branchCatId])) {
                        $coordsByBranch[$branchCatId] = ['latitude' => $product->latitude, 'longitude' => $product->longitude];
                    }
                }
            });

        $branches = $branchRows
            ->map(fn ($branch) => [
                'id'            => $branch->category->id,
                'name'          => $branch->category->name,
                'slug'          => $branch->category->slug,
                'image_url'     => $branch->category->image
                    ? Storage::disk('public')->url($branch->category->image)
                    : null,
                'room_count'    => $roomCountByBranch[$branch->category->id] ?? 0,
                'has_promotion' => $branchHasPromoByBranch[$branch->category->id] ?? false,
                'latitude'      => $coordsByBranch[$branch->category->id]['latitude'] ?? null,
                'longitude'     => $coordsByBranch[$branch->category->id]['longitude'] ?? null,
            ])
            ->sortByDesc('has_promotion')
            ->values();

        return response()->json([
            'data' => $branches,
            'meta' => ['province_name' => $province->name, 'total' => $branches->count()],
        ]);
    }

    // ─── GET /v1/search ──────────────────────────────────────────────────────
    // Có từ khoá "q" (tìm kiếm tự do, vd màn tìm kiếm chính trên app) → trả về card CHI
    // NHÁNH (RoomSearchService::searchBranches()) để client nhóm kết quả theo chi nhánh.
    // Không có "q" (đã ở 1 khu vực/chi nhánh cụ thể, vd lịch đặt phòng theo tháng) → giữ
    // nguyên hành vi cũ, trả về danh sách PHÒNG (RoomSearchService::search()).

    public function index(Request $request, RoomSearchService $searchService): JsonResponse
    {
        $hasKeyword = trim((string) $request->query('q', '')) !== '';

        $result = $hasKeyword
            ? $searchService->searchBranches($request->all(), auth('sanctum')->user())
            : $searchService->search($request->all(), auth('sanctum')->user());

        return response()->json($result);
    }

    // ─── GET /v1/search/locations ────────────────────────────────────────────

    public function locations(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        if (strlen($q) < 2) {
            return response()->json(['data' => []]);
        }

        $results = Product::where('is_activated', true)
            ->where('is_in_stock', true)
            ->whereNotNull('address')
            ->where('address', 'like', "%{$q}%")
            ->orderBy('name')
            ->limit(10)
            ->get(['name', 'address', 'latitude', 'longitude'])
            ->map(fn ($p) => [
                'name'      => $p->address,
                'query'     => $p->address,
                'latitude'  => $p->latitude  ? (string) $p->latitude  : null,
                'longitude' => $p->longitude ? (string) $p->longitude : null,
            ])
            ->unique('name')
            ->values();

        return response()->json(['data' => $results]);
    }
}
