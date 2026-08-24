<?php

declare(strict_types=1);

namespace Modules\Dashboard\App\Services\Report;

use Modules\Category\Entities\Category;
use Modules\Dashboard\App\Services\OverviewService;
use Modules\Warehouse\App\Models\WarehouseStockOutItem;

/**
 * BÁO CÁO DOANH THU: doanh thu + doanh số + lợi nhuận của TỪNG chi nhánh (và tổng cộng), trong kỳ
 * đã chọn (mặc định 7 ngày gần nhất). "Doanh số" = số đơn đã thanh toán, "doanh thu" = tổng tiền
 * thực thu, "lợi nhuận" = doanh thu - chi phí xuất kho trong kỳ của đúng chi nhánh đó.
 */
class RevenueReportService
{
    public static function getData($user, string $filter, ?string $customStart, ?string $customEnd, ?array $branchCategoryIds, array $rootBranchIds): array
    {
        [$start, $end] = OverviewService::resolveRange($filter, $customStart, $customEnd);

        $branchIds = ReportScope::branchIds($user, $rootBranchIds);

        if (empty($branchIds)) {
            return [
                'period'   => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
                'branches' => [],
                'total'    => ['sales_count' => 0, 'revenue' => 0, 'expense' => 0, 'profit' => 0],
            ];
        }

        $branches = Category::whereIn('id', $branchIds)->pluck('name', 'id');

        // orders.category_id có thể là chính chi nhánh gốc HOẶC 1 category con (khu vực/tầng...)
        // của chi nhánh đó — phải gộp doanh thu của category con vào đúng chi nhánh gốc chứa nó,
        // không thể lọc/group thẳng theo category_id = branchIds (sẽ bỏ sót toàn bộ đơn được gán
        // cho category con).
        $rootByCategoryId = [];
        foreach ($branchIds as $branchId) {
            $rootByCategoryId[$branchId] = $branchId;
        }
        foreach (Category::whereIn('parent_id', $branchIds)->get(['id', 'parent_id']) as $child) {
            $rootByCategoryId[$child->id] = $child->parent_id;
        }

        $revenueRows = ReportScope::orderQuery($user, array_keys($rootByCategoryId))
            ->where('status', 'paid')
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('category_id, COUNT(*) as sales_count, SUM(COALESCE(amount, full_amount)) as revenue')
            ->groupBy('category_id')
            ->get();

        $revenueByRoot = [];
        foreach ($revenueRows as $row) {
            $rootId = $rootByCategoryId[$row->category_id] ?? null;
            if ($rootId === null) {
                continue;
            }
            $revenueByRoot[$rootId]['sales_count'] = ($revenueByRoot[$rootId]['sales_count'] ?? 0) + (int) $row->sales_count;
            $revenueByRoot[$rootId]['revenue']      = ($revenueByRoot[$rootId]['revenue'] ?? 0) + (int) $row->revenue;
        }

        // Dùng alias (wso/wi) thay vì tên bảng thật — Laravel tự thêm tiền tố bảng (table prefix
        // 'cms_'...) vào CẢ alias khi wrap 'table as alias' (Grammar::wrapAliasedTable()), nên
        // 'selectRaw' (SQL thô) phải tự ghép tiền tố vào alias mới khớp — xem giải thích chi tiết ở
        // EndOfDayReportService::warehouseExpense().
        $prefix = \Illuminate\Support\Facades\DB::getTablePrefix();

        $expenseRows = WarehouseStockOutItem::query()
            ->from('warehouse_stock_out_items as wsoi')
            ->join('warehouse_stock_outs as wso', 'wso.id', '=', 'wsoi.warehouse_stock_out_id')
            ->join('warehouse_items as wi', 'wi.id', '=', 'wsoi.warehouse_item_id')
            ->whereBetween('wso.issued_at', [$start, $end])
            ->whereIn('wso.branch_id', $branchIds)
            ->selectRaw("{$prefix}wso.branch_id, SUM({$prefix}wsoi.quantity * {$prefix}wi.unit_price) as expense")
            ->groupBy('wso.branch_id')
            ->get()
            ->keyBy('branch_id');

        $rows = [];
        $totalSales = $totalRevenue = $totalExpense = 0;

        foreach ($branchIds as $branchId) {
            $salesCount = (int) ($revenueByRoot[$branchId]['sales_count'] ?? 0);
            $revenue    = (int) ($revenueByRoot[$branchId]['revenue'] ?? 0);
            $expense    = (int) ($expenseRows[$branchId]->expense ?? 0);

            $totalSales   += $salesCount;
            $totalRevenue += $revenue;
            $totalExpense += $expense;

            $rows[] = [
                'branch_id'   => $branchId,
                'branch_name' => $branches[$branchId] ?? 'N/A',
                'sales_count' => $salesCount,
                'revenue'     => $revenue,
                'expense'     => $expense,
                'profit'      => $revenue - $expense,
            ];
        }

        return [
            'period'   => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
            'branches' => $rows,
            'total'    => [
                'sales_count' => $totalSales,
                'revenue'     => $totalRevenue,
                'expense'     => $totalExpense,
                'profit'      => $totalRevenue - $totalExpense,
            ],
        ];
    }
}
