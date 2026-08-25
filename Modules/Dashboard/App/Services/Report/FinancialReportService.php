<?php

declare(strict_types=1);

namespace Modules\Dashboard\App\Services\Report;

use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Modules\Dashboard\App\Services\OverviewService;

/**
 * BÁO CÁO TÀI CHÍNH: thu chi theo ngày (thu/chi/lợi nhuận) + cơ cấu chi phí theo nhóm vật tư
 * (điện nước/vật tư/bảo trì.../khác — tuỳ tên Nhóm vật tư đối tác tự đặt ở Modules\Warehouse), trong
 * kỳ đã chọn (mặc định 7 ngày gần nhất).
 */
class FinancialReportService
{
    /** Số ngày tối đa để vẫn chia theo TỪNG NGÀY; dài hơn thì gộp theo THÁNG. */
    private const MAX_DAYS_FOR_DAILY_BREAKDOWN = 62;

    public static function getData($user, string $filter, ?string $customStart, ?string $customEnd, ?array $branchCategoryIds, array $rootBranchIds): array
    {
        [$start, $end] = OverviewService::resolveRange($filter, $customStart, $customEnd);

        $branchIds = ReportScope::branchIds($user, $rootBranchIds);
        $noWarehouseAccess = empty($branchIds) && $user && ! $user->isSuperAdmin();

        $revenueRows = ReportScope::orderQuery($user, $branchCategoryIds)
            ->where('status', 'paid')
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('DATE(created_at) as d, SUM(COALESCE(amount, full_amount)) as thu')
            ->groupBy('d')
            ->pluck('thu', 'd');

        // Dùng alias (wso/wi/wc) thay vì tên bảng thật — Laravel tự thêm tiền tố bảng (table prefix
        // 'cms_'...) vào CẢ alias khi wrap 'table as alias' (Grammar::wrapAliasedTable()), nên
        // 'selectRaw' (SQL thô) phải tự ghép tiền tố vào alias mới khớp — xem giải thích chi tiết ở
        // EndOfDayReportService::warehouseExpense().
        $prefix = \Illuminate\Support\Facades\DB::getTablePrefix();

        $expenseQuery = \Modules\Warehouse\App\Models\WarehouseStockOutItem::query()
            ->from('warehouse_stock_out_items as wsoi')
            ->join('warehouse_stock_outs as wso', 'wso.id', '=', 'wsoi.warehouse_stock_out_id')
            ->join('warehouse_items as wi', 'wi.id', '=', 'wsoi.warehouse_item_id')
            ->whereBetween('wso.issued_at', [$start, $end]);

        if (! empty($branchIds)) {
            $expenseQuery->whereIn('wso.branch_id', $branchIds);
        }
        if ($user && ! $user->isSuperAdmin() && ! empty($user->partner_id)) {
            $expenseQuery->where('wso.partner_id', $user->partner_id);
        }

        $expenseRows = $noWarehouseAccess ? collect() : (clone $expenseQuery)
            ->selectRaw("DATE({$prefix}wso.issued_at) as d, SUM({$prefix}wsoi.quantity * {$prefix}wi.unit_price) as chi")
            ->groupBy('d')
            ->pluck('chi', 'd');

        $daily = static::buildDailySeries($revenueRows, $expenseRows, $start, $end);

        $expenseByCategory = $noWarehouseAccess ? [] : (clone $expenseQuery)
            ->join('warehouse_categories as wc', 'wc.id', '=', 'wi.warehouse_category_id')
            ->selectRaw("{$prefix}wc.name as name, SUM({$prefix}wsoi.quantity * {$prefix}wi.unit_price) as amount")
            ->groupBy('wc.name')
            ->orderByDesc('amount')
            ->get()
            ->map(fn ($r) => ['name' => $r->name, 'amount' => (int) $r->amount])
            ->toArray();

        $totalThu = (int) $daily->sum('thu');
        $totalChi = (int) $daily->sum('chi');

        return [
            'period'  => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
            'daily'   => $daily->values()->toArray(),
            'total'   => ['thu' => $totalThu, 'chi' => $totalChi, 'loi_nhuan' => $totalThu - $totalChi],
            'expense_by_category' => $expenseByCategory,
        ];
    }

    private static function buildDailySeries($revenueRows, $expenseRows, Carbon $start, Carbon $end)
    {
        $totalDays = max(1, (int) $start->diffInDays($end) + 1);
        $byMonth   = $totalDays > self::MAX_DAYS_FOR_DAILY_BREAKDOWN;

        if ($byMonth) {
            $monthly = [];
            foreach (CarbonPeriod::create($start, '1 day', $end) as $day) {
                $key = $day->format('Y-m');
                $d   = $day->toDateString();
                $monthly[$key]['thu'] = ($monthly[$key]['thu'] ?? 0) + (int) ($revenueRows[$d] ?? 0);
                $monthly[$key]['chi'] = ($monthly[$key]['chi'] ?? 0) + (int) ($expenseRows[$d] ?? 0);
            }

            return collect($monthly)->map(function ($v, $key) {
                return ['date' => $key, 'thu' => $v['thu'], 'chi' => $v['chi'], 'loi_nhuan' => $v['thu'] - $v['chi']];
            })->values();
        }

        $series = [];
        foreach (CarbonPeriod::create($start, '1 day', $end) as $day) {
            $d   = $day->toDateString();
            $thu = (int) ($revenueRows[$d] ?? 0);
            $chi = (int) ($expenseRows[$d] ?? 0);
            $series[] = ['date' => $d, 'thu' => $thu, 'chi' => $chi, 'loi_nhuan' => $thu - $chi];
        }

        return collect($series);
    }
}
