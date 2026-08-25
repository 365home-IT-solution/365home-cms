<?php

declare(strict_types=1);

namespace Modules\Dashboard\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
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

        $data = ReceptionistReportService::getData(
            $request->user(),
            $filter,
            $filter === 'custom' ? $request->query('start_date') : null,
            $filter === 'custom' ? $request->query('end_date') : null,
            $this->resolveBranchCategoryIds($request),
            $type
        );

        return response()->json(['data' => $data]);
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

        $data = EndOfDayReportService::getData(
            $request->user(),
            $filter,
            $filter === 'custom' ? $request->query('start_date') : null,
            $filter === 'custom' ? $request->query('end_date') : null,
            $this->resolveBranchCategoryIds($request),
            $this->resolveRootBranchIds($request)
        );

        return response()->json(['data' => $data]);
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

        $data = BookingReportService::getData(
            $request->user(),
            $filter,
            $filter === 'custom' ? $request->query('start_date') : null,
            $filter === 'custom' ? $request->query('end_date') : null,
            $this->resolveBranchCategoryIds($request),
            $limit
        );

        return response()->json(['data' => $data]);
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

        $data = RevenueReportService::getData(
            $request->user(),
            $filter,
            $filter === 'custom' ? $request->query('start_date') : null,
            $filter === 'custom' ? $request->query('end_date') : null,
            $this->resolveBranchCategoryIds($request),
            $this->resolveRootBranchIds($request)
        );

        return response()->json(['data' => $data]);
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

        $data = RoomReportService::getData(
            $request->user(),
            $filter,
            $filter === 'custom' ? $request->query('start_date') : null,
            $filter === 'custom' ? $request->query('end_date') : null,
            $this->resolveBranchCategoryIds($request)
        );

        return response()->json(['data' => $data]);
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

        $data = CustomerReportService::getData(
            $request->user(),
            $filter,
            $filter === 'custom' ? $request->query('start_date') : null,
            $filter === 'custom' ? $request->query('end_date') : null,
            $this->resolveBranchCategoryIds($request),
            $limit
        );

        return response()->json(['data' => $data]);
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

        $data = FinancialReportService::getData(
            $request->user(),
            $filter,
            $filter === 'custom' ? $request->query('start_date') : null,
            $filter === 'custom' ? $request->query('end_date') : null,
            $this->resolveBranchCategoryIds($request),
            $this->resolveRootBranchIds($request)
        );

        return response()->json(['data' => $data]);
    }
}
