<?php

namespace Modules\Dashboard\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Category\Entities\Category;
use Modules\Dashboard\App\Services\KpiService;
use Modules\Dashboard\App\Services\OverviewService;

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
     *  - branch_id: id chi nhánh (category gốc, category_type=product, parent_id=null);
     *               bỏ trống = tất cả chi nhánh user được phép xem
     */
    public function kpiStats(Request $request): JsonResponse
    {
        $filter = in_array($request->query('filter'), self::KPI_FILTERS, true)
            ? $request->query('filter')
            : 'today';

        $branchCategoryIds = null;
        $branchId = $request->query('branch_id');
        if ($branchId && ctype_digit((string) $branchId)) {
            $branchId = (int) $branchId;
            $childIds = Category::where('parent_id', $branchId)->pluck('id')->toArray();
            $branchCategoryIds = array_merge([$branchId], $childIds);
        }

        $data = KpiService::getData(
            $filter,
            $request->user(),
            $filter === 'custom' ? $request->query('start_date') : null,
            $filter === 'custom' ? $request->query('end_date') : null,
            $branchCategoryIds
        );

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
}
