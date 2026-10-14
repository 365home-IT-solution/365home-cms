<?php

declare(strict_types=1);

namespace Modules\Dashboard\App\Services\Report;

use App\Models\Partner;
use Illuminate\Support\Facades\DB;
use Modules\Category\Entities\Category;
use Modules\Dashboard\App\Services\OverviewService;
use Modules\Payment\Entities\Order;
use Modules\Product\App\Models\Product;

/**
 * BÁO CÁO THEO ĐỐI TÁC (chỉ Super Admin): bảng so sánh các đối tác Homestay trong kỳ — số đơn, doanh thu, chi phí kho,
 * lợi nhuận. Cùng định nghĩa với RevenueReportService (doanh số = đơn đã thanh toán, doanh thu = SUM(amount),
 * lợi nhuận = doanh thu − chi phí xuất kho) để số liệu tổng khớp báo cáo doanh thu.
 */
class PartnerReportService
{
    public const SORTS = ['revenue', 'sales_count', 'profit', 'expense', 'name'];

    /** Các đối tác Homestay có thể xuất hiện trong báo cáo (không phải MiniHouse/hệ thống). */
    public static function partners(?array $ids = null)
    {
        return Partner::query()
            ->where('partner_type', Partner::TYPE_HOMESTAY)
            ->where('id', '!=', \Modules\Minihouse\App\Support\HomestayBridge::PARTNER_ID)
            ->when($ids, fn ($q) => $q->whereIn('id', $ids))
            ->orderBy('name')
            ->get(['id', 'name', 'legal_name', 'is_platform_partner', 'verification_status', 'payment_flow_effective_at'])
            ->reject(fn (Partner $p) => $p->isSystemPartner())
            ->values();
    }

    /** @return array<string, mixed> */
    public static function info(Partner $p): array
    {
        return [
            'id'                  => $p->id,
            'name'                => $p->name,
            'legal_name'          => $p->legal_name,
            'is_platform_partner' => (bool) $p->is_platform_partner,
            'verification_status' => $p->verification_status,
        ];
    }

    /**
     * @return array{period: array<string, string>, data: array<int, array<string, mixed>>, total: array<string, int>}
     */
    public static function summary(string $filter, ?string $customStart, ?string $customEnd, string $search = '', string $sort = 'revenue', string $direction = 'desc', bool $hideEmpty = false): array
    {
        [$start, $end] = OverviewService::resolveRange($filter, $customStart, $customEnd);

        $orders = Order::query()->countedInStats()
            ->where('status', 'paid')
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('partner_id, COUNT(*) as sales_count, SUM(COALESCE(amount, full_amount)) as revenue')
            ->groupBy('partner_id')->get()->keyBy('partner_id');

        // Đơn đã thanh toán nhưng category của đơn không nằm trong cây chi nhánh (chi nhánh gốc loại 'product' + khu vực con): chi nhánh đã
        // bị xoá hoặc là danh mục cũ không còn là chi nhánh. Vẫn tính cho đối tác (có partner_id) nhưng báo cáo doanh thu theo chi nhánh
        // (RevenueReportService) không gán được vào chi nhánh nào — tách riêng để 2 báo cáo đối chiếu được với nhau.
        $rootIds = Category::whereNull('parent_id')->where('category_type', 'product')->pluck('id');
        $treeIds = $rootIds->merge(Category::whereIn('parent_id', $rootIds)->pluck('id'));

        $orphans = Order::query()->countedInStats()
            ->where('status', 'paid')
            ->whereBetween('created_at', [$start, $end])
            ->whereNotIn('category_id', $treeIds)
            ->selectRaw('partner_id, COUNT(*) as c, SUM(COALESCE(amount, full_amount)) as revenue')
            ->groupBy('partner_id')->get()->keyBy('partner_id');

        $prefix = DB::getTablePrefix();
        $expense = DB::table('warehouse_stock_out_items as wsoi')
            ->join('warehouse_stock_outs as wso', 'wso.id', '=', 'wsoi.warehouse_stock_out_id')
            ->join('warehouse_items as wi', 'wi.id', '=', 'wsoi.warehouse_item_id')
            ->whereBetween('wso.issued_at', [$start, $end])
            ->selectRaw("{$prefix}wso.partner_id as partner_id, SUM({$prefix}wsoi.quantity * {$prefix}wi.unit_price) as expense")
            ->groupBy('wso.partner_id')->get()->keyBy('partner_id');

        $branches = Category::withoutGlobalScopes()->whereNull('parent_id')->where('category_type', 'product')
            ->selectRaw('partner_id, COUNT(*) as c')->groupBy('partner_id')->pluck('c', 'partner_id');
        $rooms = Product::withoutGlobalScopes()->selectRaw('partner_id, COUNT(*) as c')->groupBy('partner_id')->pluck('c', 'partner_id');

        $search = mb_strtolower(trim($search));

        $rows = self::partners()->map(function (Partner $p) use ($orders, $expense, $branches, $rooms, $orphans) {
            $sales = (int) ($orders[$p->id]->sales_count ?? 0);
            $revenue = (int) ($orders[$p->id]->revenue ?? 0);
            $cost = (int) ($expense[$p->id]->expense ?? 0);

            return [
                'partner'         => self::info($p),
                'branches_count'  => (int) ($branches[$p->id] ?? 0),
                'rooms_count'     => (int) ($rooms[$p->id] ?? 0),
                'sales_count'     => $sales,
                'revenue'         => $revenue,
                // Trong sales_count/revenue ở trên: phần đơn thuộc chi nhánh đã bị xoá (không có trong báo cáo doanh thu theo chi nhánh).
                'orders_without_branch'         => (int) ($orphans[$p->id]->c ?? 0),
                'revenue_without_branch'        => (int) ($orphans[$p->id]->revenue ?? 0),
                'expense'         => $cost,
                'profit'          => $revenue - $cost,
                'avg_order_value' => $sales > 0 ? (int) round($revenue / $sales) : 0,
            ];
        })
            ->when($search !== '', fn ($c) => $c->filter(fn ($r) => str_contains(mb_strtolower(($r['partner']['name'] ?? '') . ' ' . ($r['partner']['legal_name'] ?? '')), $search)))
            ->when($hideEmpty, fn ($c) => $c->filter(fn ($r) => $r['sales_count'] > 0 || $r['expense'] > 0));

        $sort = in_array($sort, self::SORTS, true) ? $sort : 'revenue';
        $rows = $sort === 'name'
            ? $rows->sortBy(fn ($r) => mb_strtolower((string) $r['partner']['name']), SORT_REGULAR, $direction === 'desc')
            : $rows->sortBy($sort, SORT_REGULAR, $direction !== 'asc');

        $rows = $rows->values();
        $totalRevenue = (int) $rows->sum('revenue');

        $data = $rows->map(fn ($r) => $r + ['revenue_share' => $totalRevenue > 0 ? round($r['revenue'] * 100 / $totalRevenue, 1) : 0.0])->all();

        return [
            'period' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
            'data'   => $data,
            'total'  => [
                'partners_count' => $rows->count(),
                'sales_count'    => (int) $rows->sum('sales_count'),
                'revenue'        => $totalRevenue,
                'orders_without_branch'  => (int) $rows->sum('orders_without_branch'),
                'revenue_without_branch' => (int) $rows->sum('revenue_without_branch'),
                'expense'        => (int) $rows->sum('expense'),
                'profit'         => (int) $rows->sum('profit'),
            ],
        ];
    }
}
