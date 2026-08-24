<?php

declare(strict_types=1);

namespace Modules\Dashboard\App\Services\Report;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Dashboard\App\Services\OverviewService;
use Modules\Warehouse\App\Models\WarehouseStockOutItem;

/**
 * BÁO CÁO CUỐI NGÀY: tổng kết thu chi + phương thức thanh toán + tổng kết bán hàng, trong kỳ đã
 * chọn (mặc định 7 ngày gần nhất). Lọc theo created_at của đơn (giống toàn bộ OverviewService/
 * KpiService), KHÔNG theo paid_at/issued_at thực tế — nhất quán với quy ước tính "kỳ" đã dùng
 * xuyên suốt module Dashboard.
 */
class EndOfDayReportService
{
    public static function getData(
        $user,
        string $filter,
        ?string $customStart,
        ?string $customEnd,
        ?array $branchCategoryIds,
        array $rootBranchIds
    ): array {
        [$start, $end] = OverviewService::resolveRange($filter, $customStart, $customEnd);

        $paidQuery = ReportScope::orderQuery($user, $branchCategoryIds)
            ->where('status', 'paid')
            ->whereBetween('created_at', [$start, $end]);

        $depositQuery = ReportScope::orderQuery($user, $branchCategoryIds)
            ->where('status', 'deposit')
            ->whereNotNull('money_deposit')
            ->whereBetween('created_at', [$start, $end]);

        $totalActualCollected = (int) ((clone $paidQuery)->sum(DB::raw('COALESCE(amount, full_amount)')))
            + (int) (clone $depositQuery)->sum('money_deposit');

        $chi = static::warehouseExpense($user, $rootBranchIds, $start, $end);

        $cash     = (int) (clone $paidQuery)->where('payment_method', 'cod')->sum(DB::raw('COALESCE(amount, full_amount)'));
        $transfer = (int) (clone $paidQuery)->where('payment_method', 'PayOS')->sum(DB::raw('COALESCE(amount, full_amount)'));

        return [
            'period'  => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
            'summary' => [
                'thu'     => $totalActualCollected,
                'chi'     => $chi,
                'thu_chi' => $totalActualCollected - $chi,
            ],
            'payment_methods' => [
                'cash'     => $cash,
                'transfer' => $transfer,
            ],
            'sales_summary' => [
                'total_orders'           => (clone $paidQuery)->count(),
                'total_revenue'          => (int) (clone $paidQuery)->sum('full_amount'),
                'total_amount'           => (int) (clone $paidQuery)->sum(DB::raw('COALESCE(amount, full_amount)')),
                'total_actual_collected' => $totalActualCollected,
            ],
        ];
    }

    /** Tổng chi phí xuất kho (vật tư sử dụng) trong kỳ, giới hạn theo chi nhánh gốc được phép xem. */
    private static function warehouseExpense($user, array $rootBranchIds, Carbon $start, Carbon $end): int
    {
        $branchIds = ReportScope::branchIds($user, $rootBranchIds);
        if (empty($branchIds) && $user && ! $user->isSuperAdmin()) {
            return 0;
        }

        // Dùng alias (wso/wi) thay vì tên bảng thật trong join/where — Laravel tự thêm tiền tố bảng
        // (table prefix 'cms_'...) vào CẢ alias khi wrap 'table as alias' (Grammar::wrapAliasedTable()),
        // nên 'selectRaw'/'sum(DB::raw())' (SQL thô, Laravel không tự sửa) phải tự ghép tiền tố vào
        // alias mới khớp — giống cách OverviewService::revenueTop() xử lý bằng DB::getTablePrefix().
        $prefix = DB::getTablePrefix();

        $query = WarehouseStockOutItem::query()
            ->from('warehouse_stock_out_items as wsoi')
            ->join('warehouse_stock_outs as wso', 'wso.id', '=', 'wsoi.warehouse_stock_out_id')
            ->join('warehouse_items as wi', 'wi.id', '=', 'wsoi.warehouse_item_id')
            ->whereBetween('wso.issued_at', [$start, $end]);

        if (! empty($branchIds)) {
            $query->whereIn('wso.branch_id', $branchIds);
        }

        if ($user && ! $user->isSuperAdmin() && ! empty($user->partner_id)) {
            $query->where('wso.partner_id', $user->partner_id);
        }

        return (int) $query->sum(DB::raw("{$prefix}wsoi.quantity * {$prefix}wi.unit_price"));
    }
}
