<?php

namespace Modules\Dashboard\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Dashboard\App\Services\FrontDeskService;
use Modules\Dashboard\App\Services\KpiService;
use Modules\Dashboard\App\Services\OccupancyService;
use Modules\Dashboard\App\Services\OverviewService;
use Modules\Dashboard\App\Services\RankingService;
use Modules\Dashboard\Http\Controllers\Concerns\ResolvesReportFilters;

class DashboardController extends Controller
{
    use ResolvesReportFilters;

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
     * GET /api/admin/dashboard/commission-stats
     * THỐNG KÊ HOA HỒNG & DOANH THU của đối tác Homestay, tách hai góc nhìn (xem App\Services\CommissionStatsService):
     *  - scope=platform (mặc định với Super Admin): CHỦ NỀN TẢNG — hoa hồng, tiền 365home bù khuyến mãi, thu nhập ròng, theo đối tác / tháng /
     *    kênh thu tiền, công nợ đối soát, ký quỹ. Đối tác không được gọi (403).
     *  - scope=partner: ĐỐI TÁC — doanh thu, hoa hồng phải nộp, thực nhận, công nợ với nền tảng. Chủ đối tác luôn là đối tác của chính mình;
     *    Super Admin phải truyền partner_id.
     * Query: filter (today|yesterday|7d|30d|90d|this_month|last_month|this_year|last_year|custom; mặc định this_month), start_date, end_date
     * (khi custom), partner_id (lọc một đối tác ở scope=platform, hoặc chọn đối tác ở scope=partner), limit (số dòng by_partner, mặc định 10).
     */
    public function commissionStats(Request $request, \App\Services\CommissionStatsService $stats): JsonResponse
    {
        [$user, $scope, $partnerId, $from, $to] = $this->commissionContext($request, $stats);

        if ($scope === 'platform') {
            $data = $stats->forPlatform($user, $from, $to, $partnerId, (int) $request->query('limit', 10));
        } else {
            $data = $stats->forPartner(\App\Models\Partner::query()->findOrFail($partnerId), $from, $to);
        }

        return response()->json(['data' => $data]);
    }

    /**
     * GET /api/admin/dashboard/commission-stats/orders — từng đơn đã chốt hoa hồng trong kỳ (phân trang) để đối chiếu số tổng.
     * Query như commission-stats, thêm: per_page (≤ 100), collected_by (partner|platform|cash), waived (0|1).
     */
    public function commissionOrders(Request $request, \App\Services\CommissionStatsService $stats): JsonResponse
    {
        [$user, $scope, $partnerId, $from, $to] = $this->commissionContext($request, $stats);

        $page = $stats->ordersPage(
            $scope === 'platform' ? $user : null,
            $from,
            $to,
            $partnerId,
            (int) $request->query('per_page', 20),
            in_array($request->query('collected_by'), ['partner', 'platform', 'cash'], true) ? $request->query('collected_by') : null,
            $request->query('waived') === null ? null : filter_var($request->query('waived'), FILTER_VALIDATE_BOOLEAN),
        );

        return response()->json($page);
    }

    /**
     * GET /api/admin/dashboard/commission-stats/partners — BẢNG SO SÁNH TẤT CẢ ĐỐI TÁC trong kỳ (chỉ Super Admin).
     * Query: filter/start_date/end_date như commission-stats, search (tên, SĐT, email), sort (commission|revenue_base|orders|name|
     * platform_subsidy|platform_net_income|partner_receives|settlement_net|open_to_collect|escrow_balance), direction (asc|desc).
     */
    public function commissionPartners(Request $request, \App\Services\CommissionStatsService $stats): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isSuperAdmin(), 403, 'Chỉ chủ nền tảng được xem so sánh các đối tác.');

        $filter = in_array($request->query('filter'), \App\Services\CommissionStatsService::FILTERS, true) ? $request->query('filter') : 'this_month';
        [$from, $to] = $stats->range($filter, $request->query('start_date'), $request->query('end_date'));

        return response()->json(['data' => $stats->partnersTable(
            $user, $from, $to,
            trim((string) $request->query('search', '')),
            (string) $request->query('sort', 'commission'),
            $request->query('direction') === 'asc' ? 'asc' : 'desc',
        )]);
    }

    /** @return array{0: \App\Models\User, 1: string, 2: ?string, 3: \Carbon\Carbon, 4: \Carbon\Carbon} */
    private function commissionContext(Request $request, \App\Services\CommissionStatsService $stats): array
    {
        $user = $request->user();
        $filter = in_array($request->query('filter'), \App\Services\CommissionStatsService::FILTERS, true) ? $request->query('filter') : 'this_month';
        [$from, $to] = $stats->range($filter, $request->query('start_date'), $request->query('end_date'));

        if ($user->isSuperAdmin()) {
            $scope = $request->query('scope') === 'partner' ? 'partner' : 'platform';
            $partnerId = $request->query('partner_id') ? (string) $request->query('partner_id') : null;
            abort_if($scope === 'partner' && $partnerId === null, 422, 'Xem góc nhìn đối tác cần truyền partner_id.');
        } else {
            // Chủ/nhân viên đối tác: chỉ thấy đối tác của chính mình, không có góc nhìn nền tảng.
            abort_if($request->query('scope') === 'platform', 403, 'Chỉ chủ nền tảng được xem thống kê của toàn hệ thống.');
            // Giống Dashboard: số liệu tài chính chỉ dành cho CHỦ đối tác, không phải nhân viên.
            abort_unless($user->isPartnerOwner() && ! empty($user->partner_id), 403, 'Chỉ chủ đối tác được xem thống kê hoa hồng.');
            $scope = 'partner';
            $partnerId = (string) $user->partner_id;
        }

        return [$user, $scope, $partnerId, $from, $to];
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
     * GET /api/admin/dashboard/front-desk
     * LỄ TÂN: Đã nhận / Đã trả (trong kỳ) + Có khách/Tổng số phòng + Cần dọn/Tổng số phòng (2 số
     * sau luôn là ảnh chụp tức thời, không đổi theo kỳ — xem docblock OverviewService::frontDesk()).
     *
     * Query params:
     *  - filter: today | yesterday | 7d | 30d | 90d | this_month | last_month | this_year |
     *            last_year | custom (mặc định: today)
     *  - start_date, end_date: bắt buộc khi filter=custom, định dạng yyyy-mm-dd
     *  - categories: danh sách slug chi nhánh cách nhau bởi dấu phẩy — bỏ trống = tất cả chi
     *                nhánh user được phép xem
     *  - branch_id: id chi nhánh (category gốc); chỉ dùng khi KHÔNG truyền 'categories'
     */
    public function frontDesk(Request $request): JsonResponse
    {
        $filter = in_array($request->query('filter'), self::KPI_FILTERS, true)
            ? $request->query('filter')
            : 'today';

        [$start, $end] = OverviewService::resolveRange(
            $filter,
            $filter === 'custom' ? $request->query('start_date') : null,
            $filter === 'custom' ? $request->query('end_date') : null,
        );

        $productIds = OverviewService::scopedProductIds($request->user(), $this->resolveBranchCategoryIds($request));
        $data       = OverviewService::frontDesk($productIds, $start, $end);

        return response()->json(['data' => $data]);
    }

    /**
     * GET /api/admin/dashboard/front-desk/{type}
     * Danh sách CHI TIẾT khi bấm vào 1 thẻ LỄ TÂN — type: checked-in (Đã nhận) | checked-out (Đã
     * trả) | has-guest (Có khách) | overstay (Ở quá giờ). "Cần dọn" dùng GET /api/admin/products
     * ?front_desk_status=needs_cleaning. meta.total luôn bằng đúng số tương ứng ở GET front-desk
     * (cùng query, xem FrontDeskService) khi truyền cùng filter/categories/branch_id và không search.
     *
     * Query params:
     *  - filter, start_date, end_date: như GET front-desk — CHỈ có tác dụng với checked-in/
     *    checked-out; has-guest/overstay luôn là ảnh chụp tức thời (meta.period = null)
     *  - categories / branch_id: như GET front-desk
     *  - search: mã đơn / tên khách / SĐT khách / tên phòng
     *  - per_page (mặc định 20, tối đa 100), page
     */
    public function frontDeskDetails(Request $request, string $type): JsonResponse
    {
        $type = str_replace('-', '_', $type);

        $filter = in_array($request->query('filter'), self::KPI_FILTERS, true)
            ? $request->query('filter')
            : 'today';

        [$start, $end] = OverviewService::resolveRange(
            $filter,
            $filter === 'custom' ? $request->query('start_date') : null,
            $filter === 'custom' ? $request->query('end_date') : null,
        );

        $productIds = OverviewService::scopedProductIds($request->user(), $this->resolveBranchCategoryIds($request));
        $perPage    = max(1, min(100, (int) $request->query('per_page', 20)));
        $page       = max(1, (int) $request->query('page', 1));
        $isSnapshot = in_array($type, FrontDeskService::SNAPSHOT_TYPES, true);

        ['paginator' => $paginator, 'summary' => $summary] = FrontDeskService::details(
            $type,
            $productIds,
            $start,
            $end,
            trim((string) $request->query('search', '')) ?: null,
            $perPage,
            $page,
        );

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'type'         => $type,
                'label'        => FrontDeskService::TYPE_LABELS[$type],
                'is_snapshot'  => $isSnapshot,
                'period'       => $isSnapshot ? null : ['start' => $start->toDateString(), 'end' => $end->toDateString()],
                'generated_at' => now(),
                'total'        => $summary['total'],
                'total_slots'  => $summary['total_slots'],
                'current_page' => $paginator->currentPage(),
                'per_page'     => $paginator->perPage(),
                'last_page'    => $paginator->lastPage(),
            ],
        ]);
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

}
