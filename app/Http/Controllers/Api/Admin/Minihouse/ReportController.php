<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin\Minihouse;

use App\Http\Controllers\Api\Admin\Minihouse\Concerns\ScopesToMinihouseBuilding;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Minihouse\App\Services\Report\ContractReportService;
use Modules\Minihouse\App\Services\Report\FinancialReportService;
use Modules\Minihouse\App\Services\Report\MinihouseReportPeriod;
use Modules\Minihouse\App\Services\Report\RoomReportService;

// Bộ API báo cáo MiniHouse — mirror đúng tinh thần Modules\Dashboard\Http\Controllers\ReportController
// bên Home (1 endpoint/1 báo cáo, cùng convention tham số filter/start_date/end_date/building_id),
// đổi tên nghiệp vụ cho khớp cho thuê dài hạn (financial/occupancy/debts/contracts thay vì revenue/
// room/customer/booking). Toàn bộ endpoint yêu cầu quyền 'view_any_reports' (đã có sẵn, dùng chung
// với trang Filament FinanceReports — xem MinihousePermissions::EXTRA_PERMISSIONS) và luôn giới hạn
// trong permittedBuildingIds() của user đang gọi (ScopesToMinihouseBuilding) — KHÔNG dựa vào global
// scope ActiveBuildingScope vì scope đó tắt hoàn toàn khi gọi qua API (xem trait).
class ReportController extends Controller
{
    use ScopesToMinihouseBuilding;

    // GET /api/admin/minihouse/reports/financial?filter=&start_date=&end_date=&building_id=
    // Thu/chi/lợi nhuận/công nợ tổng quan trong kỳ + phân theo từng toà (chỉ khi phạm vi >1 toà).
    public function financial(Request $request): JsonResponse
    {
        if (! $this->hasPermission($request, 'view_any_reports')) {
            return response()->json(['message' => 'Không có quyền xem báo cáo.'], 403);
        }

        $buildingIds = $this->resolveBuildingIds($request);

        if ($buildingIds === null) {
            return response()->json(['message' => 'Không có quyền xem toà nhà này.'], 403);
        }

        if (empty($buildingIds)) {
            return response()->json(['data' => $this->emptyFinancialResponse($request)]);
        }

        [$start, $end] = $this->resolvePeriod($request);

        return response()->json(['data' => [
            'period'      => $this->periodPayload($request, $start, $end),
            'stats'       => FinancialReportService::stats($buildingIds, $start, $end),
            'by_building' => FinancialReportService::byBuilding($buildingIds, $start, $end),
        ]]);
    }

    // GET /api/admin/minihouse/reports/debts?building_id=
    // Công nợ HIỆN TẠI theo từng hợp đồng/khách thuê — không phụ thuộc kỳ báo cáo (nợ cũ vẫn là nợ).
    public function debts(Request $request): JsonResponse
    {
        if (! $this->hasPermission($request, 'view_any_reports')) {
            return response()->json(['message' => 'Không có quyền xem báo cáo.'], 403);
        }

        $buildingIds = $this->resolveBuildingIds($request);

        if ($buildingIds === null) {
            return response()->json(['message' => 'Không có quyền xem toà nhà này.'], 403);
        }

        return response()->json(['data' => empty($buildingIds) ? [] : FinancialReportService::tenantDebts($buildingIds)]);
    }

    // GET /api/admin/minihouse/reports/occupancy?building_id=
    // Ảnh chụp tức thời tình trạng phòng (trống/đặt cọc/đang thuê/bảo trì) — không theo kỳ báo cáo.
    public function occupancy(Request $request): JsonResponse
    {
        if (! $this->hasPermission($request, 'view_any_reports')) {
            return response()->json(['message' => 'Không có quyền xem báo cáo.'], 403);
        }

        $buildingIds = $this->resolveBuildingIds($request);

        if ($buildingIds === null) {
            return response()->json(['message' => 'Không có quyền xem toà nhà này.'], 403);
        }

        if (empty($buildingIds)) {
            return response()->json(['data' => ['total_rooms' => 0, 'by_status' => [], 'by_building' => []]]);
        }

        return response()->json(['data' => RoomReportService::occupancy($buildingIds)]);
    }

    // GET /api/admin/minihouse/reports/contracts?filter=&start_date=&end_date=&building_id=&expiring_soon_days=
    // Hợp đồng mới ký/kết thúc/huỷ trong kỳ + danh sách hợp đồng sắp hết hạn (luôn tính từ hôm nay).
    public function contracts(Request $request): JsonResponse
    {
        if (! $this->hasPermission($request, 'view_any_reports')) {
            return response()->json(['message' => 'Không có quyền xem báo cáo.'], 403);
        }

        $buildingIds = $this->resolveBuildingIds($request);

        if ($buildingIds === null) {
            return response()->json(['message' => 'Không có quyền xem toà nhà này.'], 403);
        }

        [$start, $end] = $this->resolvePeriod($request);
        $expiringSoonDays = max(1, min(365, (int) $request->integer('expiring_soon_days', 30)));

        if (empty($buildingIds)) {
            return response()->json(['data' => [
                'period'  => $this->periodPayload($request, $start, $end),
                'summary' => [
                    'active_contracts' => 0, 'new_contracts' => 0, 'ended_contracts' => 0,
                    'cancelled_contracts' => 0, 'expiring_soon' => [],
                ],
            ]]);
        }

        return response()->json(['data' => [
            'period'  => $this->periodPayload($request, $start, $end),
            'summary' => ContractReportService::summary($buildingIds, $start, $end, $expiringSoonDays),
        ]]);
    }

    /**
     * Giao (intersect) giữa toà được phép của user và ?building_id= (nếu có truyền) — trả về:
     * - null: có truyền building_id nhưng KHÔNG nằm trong phạm vi được phép (403)
     * - []  : không có toà nào trong phạm vi (tài khoản chưa được gán toà nào — trả rỗng, không lỗi)
     * - mảng id cụ thể: phạm vi thực sự áp dụng
     *
     * @return array<int>|null
     */
    private function resolveBuildingIds(Request $request): ?array
    {
        $permitted = $this->permittedBuildingIds($request);

        if (! $request->filled('building_id')) {
            return $permitted;
        }

        $buildingId = (int) $request->integer('building_id');

        if (! in_array($buildingId, $permitted, true)) {
            return null;
        }

        return [$buildingId];
    }

    /**
     * @return array{0: \Illuminate\Support\Carbon, 1: \Illuminate\Support\Carbon}
     */
    private function resolvePeriod(Request $request): array
    {
        return MinihouseReportPeriod::resolve(
            (string) $request->string('filter', 'this_month'),
            $request->string('start_date')->toString() ?: null,
            $request->string('end_date')->toString() ?: null,
        );
    }

    private function periodPayload(Request $request, \Illuminate\Support\Carbon $start, \Illuminate\Support\Carbon $end): array
    {
        return [
            'filter'     => (string) $request->string('filter', 'this_month'),
            'start_date' => $start->toDateString(),
            'end_date'   => $end->toDateString(),
        ];
    }

    private function emptyFinancialResponse(Request $request): array
    {
        [$start, $end] = $this->resolvePeriod($request);

        return [
            'period'      => $this->periodPayload($request, $start, $end),
            'stats'       => [
                'collected' => 0, 'invoiced_total' => 0, 'uncollected' => 0, 'expense' => 0,
                'profit' => 0, 'total_rooms' => 0, 'rented_rooms' => 0, 'occupancy_rate' => 0,
            ],
            'by_building' => [],
        ];
    }
}
