<?php

namespace Modules\Dashboard\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Category\Entities\Category;
use Modules\Dashboard\App\Services\KpiService;
use Modules\Dashboard\App\Services\OccupancyService;
use Modules\Dashboard\App\Services\OverviewService;
use Modules\Dashboard\App\Services\RankingService;

class DashboardController extends Controller
{
    private const KPI_FILTERS = [
        'today', 'yesterday', '7d', '30d', '90d',
        'this_month', 'last_month', 'this_year', 'last_year', 'custom',
    ];

    /**
     * GET /api/admin/dashboard/kpi-stats
     * Thống kê BOOKING dạng thẻ tổng quan: Tổng đơn đặt phòng, Đơn hoàn thành, Doanh thu
     * (thực thu), Chuyển khoản, Tiền mặt, Chuyển khoản - Đặt cọc, Tiền mặt - Đặt cọc — mỗi số
     * kèm %delta so với kỳ trước (xem KpiService::getData()).
     *
     * Query params:
     *  - filter: today | yesterday | 7d | 30d | 90d | this_month | last_month | this_year |
     *            last_year | custom (mặc định: today)
     *  - start_date, end_date: bắt buộc khi filter=custom, định dạng yyyy-mm-dd
     *  - categories: danh sách slug chi nhánh cách nhau bởi dấu phẩy (khớp field 'categories'
     *                trả về ở POST /api/admin/login), vd: categories=chi-nhanh-q1,chi-nhanh-q3
     *  - branch_id: id chi nhánh (category gốc); chỉ dùng khi KHÔNG truyền 'categories'
     *  Không truyền categories/branch_id → tất cả chi nhánh user được phép xem
     */
    public function kpiStats(Request $request): JsonResponse
    {
        $filter = in_array($request->query('filter'), self::KPI_FILTERS, true)
            ? $request->query('filter')
            : 'today';

        $data = KpiService::getData(
            $filter,
            $request->user(),
            $filter === 'custom' ? $request->query('start_date') : null,
            $filter === 'custom' ? $request->query('end_date') : null,
            $this->resolveBranchCategoryIds($request)
        );

        return response()->json(['data' => $data]);
    }

    /**
     * GET /api/admin/dashboard/rankings
     * Dữ liệu phân tích theo NĂM (khác kpi-stats — lọc theo period): Xếp hạng phòng, Top khách
     * đặt phòng nhiều nhất, Doanh thu theo tháng, Doanh thu từng chi nhánh theo tháng.
     * (xem RankingService::getData()).
     *
     * Query params:
     *  - year: mặc định năm hiện tại
     *  - categories: danh sách slug chi nhánh cách nhau bởi dấu phẩy (khớp field 'categories'
     *                trả về ở POST /api/admin/login), vd: categories=chi-nhanh-q1,chi-nhanh-q3
     *  - branch_id: id chi nhánh (category gốc); chỉ dùng khi KHÔNG truyền 'categories'
     *  - limit: số dòng top cho room_ranking/top_customers, mặc định 10
     *  Không truyền categories/branch_id → tất cả chi nhánh user được phép xem
     */
    public function rankings(Request $request): JsonResponse
    {
        $year = $request->filled('year') && ctype_digit((string) $request->query('year'))
            ? (int) $request->query('year')
            : (int) date('Y');

        $limit = $request->filled('limit') && ctype_digit((string) $request->query('limit'))
            ? max(1, min(50, (int) $request->query('limit')))
            : 10;

        $data = RankingService::getData($request->user(), $year, $this->resolveBranchCategoryIds($request), $limit);

        return response()->json(['data' => $data]);
    }

    /**
     * GET /api/admin/dashboard/occupancy
     * Công suất phòng (tỉ lệ lấp đầy): % tổng trong kỳ + chuỗi theo ngày/tháng để vẽ biểu đồ
     * đường, kèm breakdown theo từng chi nhánh (xem OccupancyService::getData()).
     *
     * Query params:
     *  - filter: today | yesterday | 7d | 30d | 90d | this_month | last_month | this_year |
     *            last_year | custom (mặc định: this_month)
     *  - start_date, end_date: bắt buộc khi filter=custom, định dạng yyyy-mm-dd
     *  - categories: danh sách slug chi nhánh cách nhau bởi dấu phẩy — bỏ trống = tất cả chi
     *                nhánh user được phép xem
     *  - branch_id: id chi nhánh (category gốc); chỉ dùng khi KHÔNG truyền 'categories'
     */
    public function occupancy(Request $request): JsonResponse
    {
        $filter = in_array($request->query('filter'), self::KPI_FILTERS, true)
            ? $request->query('filter')
            : 'this_month';

        $data = OccupancyService::getData(
            $request->user(),
            $filter,
            $filter === 'custom' ? $request->query('start_date') : null,
            $filter === 'custom' ? $request->query('end_date') : null,
            $this->resolveBranchCategoryIds($request)
        );

        return response()->json(['data' => $data]);
    }

    /**
     * GET /api/admin/dashboard/occupancy-top
     * TOP 5 công suất (tỉ lệ lấp đầy) theo Hạng phòng (room_type) VÀ theo Khu vực — cùng dữ liệu,
     * trả cả 2 để FE tự chuyển tab (xem OverviewService::occupancyTop()).
     *
     * Query params:
     *  - filter: today | yesterday | 7d | 30d | 90d | this_month | last_month | this_year |
     *            last_year | custom (mặc định: this_month)
     *  - start_date, end_date: bắt buộc khi filter=custom, định dạng yyyy-mm-dd
     *  - categories: danh sách slug chi nhánh cách nhau bởi dấu phẩy — bỏ trống = tất cả chi
     *                nhánh user được phép xem
     *  - branch_id: id chi nhánh (category gốc); chỉ dùng khi KHÔNG truyền 'categories'
     *  - sort: desc (cao → thấp, mặc định) | asc (thấp → cao)
     */
    public function occupancyTop(Request $request): JsonResponse
    {
        $filter = in_array($request->query('filter'), self::KPI_FILTERS, true)
            ? $request->query('filter')
            : 'this_month';

        [$start, $end] = OverviewService::resolveRange(
            $filter,
            $filter === 'custom' ? $request->query('start_date') : null,
            $filter === 'custom' ? $request->query('end_date') : null,
        );

        $productIds = OverviewService::scopedProductIds($request->user(), $this->resolveBranchCategoryIds($request));

        $sort       = $request->query('sort') === 'asc' ? 'asc' : 'desc';
        $data       = OverviewService::occupancyTop($productIds, $start, $end, $sort === 'desc');

        return response()->json(['data' => $data]);
    }

    /**
     * GET /api/admin/dashboard/overview
     * Trả về toàn bộ số liệu tổng quan admin — 7 block, MỖI block lọc kỳ ĐỘC LẬP với nhau:
     *   Kinh doanh, Lễ tân, Đặt phòng, Công suất phòng, Top 5 công suất, Doanh thu, Top 5 doanh thu.
     * (Lễ tân: 2 số "có khách"/"ở quá giờ" luôn là ảnh chụp tức thời, không đổi theo kỳ chọn.)
     *
     * Giá trị period hợp lệ cho mọi block:
     *   today | yesterday | this_week | last_week | this_month | last_month |
     *   this_year | last_year | 7d | 30d | 90d | custom (kèm *_start/*_end dạng yyyy-mm-dd)
     *
     * Query params:
     *  - branch_id: id chi nhánh (category_type=product, parent_id=null); bỏ trống = tất cả chi nhánh được phép xem
     *  - business_period,      business_start,      business_end      (mặc định: today)      — Kinh doanh
     *  - front_desk_period,    front_desk_start,    front_desk_end    (mặc định: today)      — Lễ tân
     *  - booking_period,       booking_start,       booking_end       (mặc định: today)      — Đặt phòng
     *  - occupancy_period,     occupancy_start,     occupancy_end     (mặc định: this_month) — Công suất phòng
     *  - occupancy_top_period, occupancy_top_start, occupancy_top_end (mặc định: this_month) — Top 5 công suất
     *  - revenue_period,       revenue_start,       revenue_end       (mặc định: this_month) — Doanh thu
     *  - revenue_top_period,   revenue_top_start,   revenue_top_end   (mặc định: this_month) — Top 5 doanh thu
     */
    public function overview(Request $request): JsonResponse
    {
        $data = OverviewService::getOverview($request->user(), $request->only([
            'branch_id',
            'business_period', 'business_start', 'business_end',
            'front_desk_period', 'front_desk_start', 'front_desk_end',
            'booking_period', 'booking_start', 'booking_end',
            'occupancy_period', 'occupancy_start', 'occupancy_end',
            'occupancy_top_period', 'occupancy_top_start', 'occupancy_top_end',
            'revenue_period', 'revenue_start', 'revenue_end',
            'revenue_top_period', 'revenue_top_start', 'revenue_top_end',
        ]));

        return response()->json(['data' => $data]);
    }

    /**
     * Chuyển 'categories' (danh sách slug chi nhánh cách nhau bởi dấu phẩy) hoặc 'branch_id'
     * (1 id) thành danh sách category_id để lọc orders theo chi nhánh — gồm cả category con
     * (khu vực/tầng...) của mỗi chi nhánh được chọn. 'categories' được ưu tiên nếu có.
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
}
