<?php

declare(strict_types=1);

namespace Modules\Dashboard\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use App\Models\Partner;
use Modules\Dashboard\App\Services\Report\PartnerReportService;
use Modules\Dashboard\App\Services\Report\BookingReportService;
use Modules\Dashboard\App\Services\Report\CustomerReportService;
use Modules\Dashboard\App\Services\Report\EndOfDayReportService;
use Modules\Dashboard\App\Services\Report\FinancialReportService;
use Modules\Dashboard\App\Services\Report\ReceptionistReportService;
use Modules\Dashboard\App\Services\Report\RevenueReportService;
use Modules\Dashboard\App\Services\Report\RoomReportService;
use Modules\Dashboard\Http\Controllers\Concerns\ResolvesReportFilters;

/**
 * "Tất cả báo cáo" — 7 màn hình báo cáo cho admin/nhân viên: Lễ tân, Cuối ngày, Đặt phòng, Doanh
 * thu, Phòng, Khách hàng, Tài chính. Cùng quy ước lọc/phân quyền với DashboardController (xem
 * Modules\Dashboard\App\Services\OverviewService, ReportScope): dữ liệu luôn giới hạn theo chi
 * nhánh nhân viên được phân công (trừ super_admin/chủ đối tác thấy toàn bộ).
 *
 * Query params dùng chung cho mọi endpoint bên dưới:
 *  - filter: today | 7d | custom (mỗi báo cáo có filter mặc định khác nhau, xem docblock riêng)
 *  - start_date, end_date: bắt buộc khi filter=custom, định dạng yyyy-mm-dd
 *  - categories: danh sách slug chi nhánh cách nhau bởi dấu phẩy (khớp field 'categories' trả về ở
 *                POST /api/admin/login), vd: categories=chi-nhanh-q1,chi-nhanh-q3
 *  - branch_id: id chi nhánh (category gốc); chỉ dùng khi KHÔNG truyền 'categories'
 *  Không truyền categories/branch_id → tất cả chi nhánh user được phép xem
 *
 * Chiều ĐỐI TÁC (chỉ Super Admin; tài khoản đối tác luôn bị khoá theo đối tác của mình):
 *  - partner_id: xem 1 đối tác — response thêm "partner", "data" cùng cấu trúc báo cáo thường
 *                (tương đương header X-Partner-Id)
 *  - group_by=partner: tách báo cáo theo TỪNG đối tác — trả "partners": [{partner, data}], mỗi "data" cùng cấu trúc báo cáo thường
 *  - partner_ids: (kèm group_by=partner) danh sách id đối tác cách nhau dấu phẩy để giới hạn; bỏ trống = mọi đối tác Homestay
 *  - GET /api/admin/reports/partners: bảng so sánh các đối tác (doanh số, doanh thu, chi phí, lợi nhuận)
 *  - GET /api/admin/reports/partners/{partner}: chi tiết 1 đối tác — gồm nhiều báo cáo cùng lúc (param sections)
 */
class ReportController extends Controller
{
    use ResolvesReportFilters;

    private const FILTERS = ['today', '7d', 'custom'];

    /**
     * GET /api/admin/reports/receptionist
     * BÁO CÁO LỄ TÂN: phòng trống/đang sử dụng/dự kiến trả/dự kiến nhận + công suất sử dụng.
     *
     * Query params riêng:
     *  - filter: mặc định 'today'
     *  - type: capacity (mặc định) | overbooking — overbooking trả kèm danh sách đơn phòng bị trùng
     */
    public function receptionist(Request $request): JsonResponse
    {
        $filter = in_array($request->query('filter'), self::FILTERS, true) ? $request->query('filter') : 'today';
        $type   = $request->query('type') === 'overbooking' ? 'overbooking' : 'capacity';

        return $this->partnerAware($request, fn () => ReceptionistReportService::getData(
            $request->user(),
            $filter,
            $filter === 'custom' ? $request->query('start_date') : null,
            $filter === 'custom' ? $request->query('end_date') : null,
            $this->resolveBranchCategoryIds($request),
            $type
        ));
    }

    /**
     * GET /api/admin/reports/end-of-day
     * BÁO CÁO CUỐI NGÀY: tổng kết thu chi + phương thức thanh toán + tổng kết bán hàng.
     *
     * Query params riêng:
     *  - filter: mặc định '7d'
     */
    public function endOfDay(Request $request): JsonResponse
    {
        $filter = in_array($request->query('filter'), self::FILTERS, true) ? $request->query('filter') : '7d';

        return $this->partnerAware($request, fn () => EndOfDayReportService::getData(
            $request->user(),
            $filter,
            $filter === 'custom' ? $request->query('start_date') : null,
            $filter === 'custom' ? $request->query('end_date') : null,
            $this->resolveBranchCategoryIds($request),
            $this->resolveRootBranchIds($request)
        ));
    }

    /**
     * GET /api/admin/reports/booking
     * BÁO CÁO ĐẶT PHÒNG: đặt phòng theo thời gian, top nhân viên đặt phòng, tỷ lệ đơn bị huỷ theo
     * lý do (Khách không đến / Hủy vì lý do khác / Đặt phòng có lưu trú).
     *
     * Query params riêng:
     *  - filter: mặc định '7d'
     *  - limit: số nhân viên hiển thị ở top_staff, mặc định 5
     */
    public function booking(Request $request): JsonResponse
    {
        $filter = in_array($request->query('filter'), self::FILTERS, true) ? $request->query('filter') : '7d';
        $limit  = $request->filled('limit') && ctype_digit((string) $request->query('limit'))
            ? max(1, min(50, (int) $request->query('limit')))
            : 5;

        return $this->partnerAware($request, fn () => BookingReportService::getData(
            $request->user(),
            $filter,
            $filter === 'custom' ? $request->query('start_date') : null,
            $filter === 'custom' ? $request->query('end_date') : null,
            $this->resolveBranchCategoryIds($request),
            $limit
        ));
    }

    /**
     * GET /api/admin/reports/revenue
     * BÁO CÁO DOANH THU: doanh thu + doanh số + lợi nhuận theo từng chi nhánh.
     *
     * Query params riêng:
     *  - filter: mặc định '7d'
     */
    public function revenue(Request $request): JsonResponse
    {
        $filter = in_array($request->query('filter'), self::FILTERS, true) ? $request->query('filter') : '7d';

        return $this->partnerAware($request, fn () => RevenueReportService::getData(
            $request->user(),
            $filter,
            $filter === 'custom' ? $request->query('start_date') : null,
            $filter === 'custom' ? $request->query('end_date') : null,
            $this->resolveBranchCategoryIds($request),
            $this->resolveRootBranchIds($request)
        ));
    }

    /**
     * GET /api/admin/reports/room
     * BÁO CÁO PHÒNG: doanh thu theo hạng phòng, top 5 phòng đặt nhiều nhất, trạng thái buồng phòng.
     *
     * Query params riêng:
     *  - filter: mặc định '7d'
     */
    public function room(Request $request): JsonResponse
    {
        $filter = in_array($request->query('filter'), self::FILTERS, true) ? $request->query('filter') : '7d';

        return $this->partnerAware($request, fn () => RoomReportService::getData(
            $request->user(),
            $filter,
            $filter === 'custom' ? $request->query('start_date') : null,
            $filter === 'custom' ? $request->query('end_date') : null,
            $this->resolveBranchCategoryIds($request)
        ));
    }

    /**
     * GET /api/admin/reports/customer
     * BÁO CÁO KHÁCH HÀNG: khách hàng mới theo ngày, cơ cấu khách hàng (mới/quay lại), top 5 khách
     * chi tiêu nhiều nhất.
     *
     * Query params riêng:
     *  - filter: mặc định '7d'
     *  - limit: số khách hiển thị ở top_customers, mặc định 5
     */
    public function customer(Request $request): JsonResponse
    {
        $filter = in_array($request->query('filter'), self::FILTERS, true) ? $request->query('filter') : '7d';
        $limit  = $request->filled('limit') && ctype_digit((string) $request->query('limit'))
            ? max(1, min(50, (int) $request->query('limit')))
            : 5;

        return $this->partnerAware($request, fn () => CustomerReportService::getData(
            $request->user(),
            $filter,
            $filter === 'custom' ? $request->query('start_date') : null,
            $filter === 'custom' ? $request->query('end_date') : null,
            $this->resolveBranchCategoryIds($request),
            $limit
        ));
    }

    /**
     * GET /api/admin/reports/financial
     * BÁO CÁO TÀI CHÍNH: thu chi theo ngày (thu/chi/lợi nhuận) + cơ cấu chi phí theo nhóm vật tư.
     *
     * Query params riêng:
     *  - filter: mặc định '7d'
     */
    public function financial(Request $request): JsonResponse
    {
        $filter = in_array($request->query('filter'), self::FILTERS, true) ? $request->query('filter') : '7d';

        return $this->partnerAware($request, fn () => FinancialReportService::getData(
            $request->user(),
            $filter,
            $filter === 'custom' ? $request->query('start_date') : null,
            $filter === 'custom' ? $request->query('end_date') : null,
            $this->resolveBranchCategoryIds($request),
            $this->resolveRootBranchIds($request)
        ));
    }

    /**
     * Áp chiều đối tác cho 1 báo cáo (xem docblock đầu class). $compute được gọi lại cho từng đối tác nên
     * mọi tham số lấy từ request phải nằm TRONG closure.
     */
    private function partnerAware(Request $request, \Closure $compute): JsonResponse
    {
        $user = $request->user();

        if ($user->seesAllPartners() && filled($request->query('partner_id'))) {
            $partner = Partner::withTrashed()->find((string) $request->query('partner_id'));
            abort_if(! $partner, 422, 'Đối tác không tồn tại.');

            $user->actAsPartner($partner->id);

            return response()->json(['partner' => PartnerReportService::info($partner), 'data' => $compute()]);
        }

        if ($user->seesAllPartners() && $request->query('group_by') === 'partner') {
            $ids = collect(explode(',', (string) $request->query('partner_ids', '')))->map(fn ($v) => trim($v))->filter()->values()->all();
            $rows = [];

            foreach (PartnerReportService::partners($ids ?: null) as $partner) {
                $user->actAsPartner($partner->id);
                $rows[] = ['partner' => PartnerReportService::info($partner), 'data' => $compute()];
            }

            $user->actAsPartner(null);

            return response()->json(['group_by' => 'partner', 'partners' => $rows]);
        }

        // Đã chọn đối tác qua header X-Partner-Id: nói rõ đang xem đối tác nào.
        if ($user->actingPartnerId()) {
            $partner = Partner::withTrashed()->find($user->actingPartnerId());

            return response()->json(['partner' => $partner ? PartnerReportService::info($partner) : null, 'data' => $compute()]);
        }

        return response()->json(['data' => $compute()]);
    }

    /**
     * GET /api/admin/reports/partners — BẢNG SO SÁNH CÁC ĐỐI TÁC (chỉ Super Admin).
     * Query: filter (today|7d|custom, mặc định 7d), start_date/end_date, search (tên), sort (revenue|sales_count|profit|expense|name),
     * direction (asc|desc), hide_empty=1 (ẩn đối tác không có đơn/chi phí trong kỳ).
     */
    public function partners(Request $request): JsonResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403, 'Chỉ Super Admin được xem báo cáo theo đối tác.');

        $filter = in_array($request->query('filter'), self::FILTERS, true) ? $request->query('filter') : '7d';

        $result = PartnerReportService::summary(
            $filter,
            $filter === 'custom' ? $request->query('start_date') : null,
            $filter === 'custom' ? $request->query('end_date') : null,
            (string) $request->query('search', ''),
            (string) $request->query('sort', 'revenue'),
            $request->query('direction') === 'asc' ? 'asc' : 'desc',
            $request->boolean('hide_empty'),
        );

        return response()->json($result);
    }

    /**
     * GET /api/admin/reports/partners/{partner} — CHI TIẾT 1 ĐỐI TÁC (chỉ Super Admin): nhiều báo cáo cùng lúc, mỗi mục cùng cấu trúc
     * với endpoint báo cáo riêng. Query: filter/start_date/end_date, categories|branch_id, sections (cách nhau dấu phẩy, mặc định
     * revenue,booking,room,customer,financial; cho phép thêm receptionist,end-of-day).
     */
    public function partnerDetail(Request $request, Partner $partner): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isSuperAdmin(), 403, 'Chỉ Super Admin được xem báo cáo theo đối tác.');
        abort_if($partner->partner_type !== Partner::TYPE_HOMESTAY || $partner->isSystemPartner(), 404);

        $all = ['revenue', 'booking', 'room', 'customer', 'financial', 'receptionist', 'end-of-day'];
        $sections = collect(explode(',', (string) $request->query('sections', 'revenue,booking,room,customer,financial')))
            ->map(fn ($v) => trim($v))->filter(fn ($v) => in_array($v, $all, true))->unique()->values();

        $filter = in_array($request->query('filter'), self::FILTERS, true) ? $request->query('filter') : '7d';
        $start  = $filter === 'custom' ? $request->query('start_date') : null;
        $end    = $filter === 'custom' ? $request->query('end_date') : null;

        $user->actAsPartner($partner->id);

        try {
            $branchIds = $this->resolveBranchCategoryIds($request);
            $rootIds   = $this->resolveRootBranchIds($request);

            $builders = [
                'revenue'      => fn () => RevenueReportService::getData($user, $filter, $start, $end, $branchIds, $rootIds),
                'booking'      => fn () => BookingReportService::getData($user, $filter, $start, $end, $branchIds, 5),
                'room'         => fn () => RoomReportService::getData($user, $filter, $start, $end, $branchIds),
                'customer'     => fn () => CustomerReportService::getData($user, $filter, $start, $end, $branchIds, 5),
                'financial'    => fn () => FinancialReportService::getData($user, $filter, $start, $end, $branchIds, $rootIds),
                'receptionist' => fn () => ReceptionistReportService::getData($user, $filter === 'custom' ? 'custom' : 'today', $start, $end, $branchIds, 'capacity'),
                'end-of-day'   => fn () => EndOfDayReportService::getData($user, $filter, $start, $end, $branchIds, $rootIds),
            ];

            $data = [];
            foreach ($sections as $section) {
                $data[$section] = $builders[$section]();
            }
        } finally {
            $user->actAsPartner(null);
        }

        return response()->json(['partner' => PartnerReportService::info($partner), 'filter' => $filter, 'data' => $data]);
    }
}
