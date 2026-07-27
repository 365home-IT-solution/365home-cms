<?php

declare(strict_types=1);

namespace Modules\Dashboard\App\Services;

use Modules\Category\Entities\Category;
use Modules\Dashboard\App\Filament\Pages\Dashboard;
use Modules\Payment\Entities\Order;

/**
 * Dữ liệu "phân tích" ở khu vực dưới trang Tổng quan admin — lọc theo NĂM (khác KpiService,
 * vốn lọc theo kỳ/period): Xếp hạng phòng, Doanh thu theo tháng, Doanh thu từng chi nhánh
 * theo tháng, Top khách đặt phòng nhiều nhất.
 */
class RankingService
{
    public static function getData($user, int $year, ?array $branchCategoryIds = null, int $limit = 10): array
    {
        return [
            'year'                   => $year,
            'available_years'        => Dashboard::getAvailableYears($user),
            'room_ranking'           => static::roomRanking($user, $year, $branchCategoryIds, $limit),
            'top_customers'          => static::topCustomers($user, $year, $branchCategoryIds, $limit),
            'monthly_revenue'        => static::monthlyRevenue($user, $year, $branchCategoryIds),
            'branch_monthly_revenue' => static::branchMonthlyRevenue($user, $year, $branchCategoryIds),
        ];
    }

    /** XẾP HẠNG PHÒNG: top N phòng theo doanh thu trong năm — tái dùng logic panel "Doanh thu phòng" */
    private static function roomRanking($user, int $year, ?array $branchCategoryIds, int $limit): array
    {
        $data = Dashboard::getRoomRevenueData($user, $year, $branchCategoryIds);

        return [
            'rooms' => array_slice($data['rooms'], 0, $limit),
            'total' => $data['total'],
        ];
    }

    /** DOANH THU THEO THÁNG: mảng 12 tháng trong năm — tái dùng logic panel "Doanh thu theo tháng" */
    private static function monthlyRevenue($user, int $year, ?array $branchCategoryIds): array
    {
        $data = Dashboard::getMonthlyRevenueData($user, $year, $branchCategoryIds);

        return [
            'months' => $data['months'],
            'total'  => array_sum($data['months']),
        ];
    }

    /**
     * DOANH THU TỪNG CHI NHÁNH THEO THÁNG: mỗi chi nhánh (category gốc) → mảng 12 tháng.
     * Tách từ closure GET /admin/api/branch-monthly (routes/web.php:61-126) thành hàm dùng
     * chung, để cả route polling cũ (session) lẫn API token mới đều gọi cùng 1 nguồn.
     */
    public static function branchMonthlyRevenue($user, int $year, ?array $branchCategoryIds = null): array
    {
        $branchesQuery = Category::whereNull('parent_id')->where('category_type', 'product')->orderBy('name');

        if ($user && ! $user->isSuperAdmin()) {
            if (empty($user->partner_id)) {
                return ['branches' => []];
            }
            $branchesQuery->where('partner_id', $user->partner_id);
        }

        if ($branchCategoryIds !== null) {
            $branchesQuery->whereIn('id', $branchCategoryIds);
        }

        $branches = $branchesQuery->get();

        if ($user && ! $user->isSuperAdmin()) {
            $allowedIds = $user->allowedCategoryIds() ?? [];
            // allowedCategoryIds chỉ thu hẹp thêm (nhân viên chỉ được giao 1 vài chi nhánh cụ
            // thể) — không áp dụng khi rỗng, vì đã lọc theo partner_id ở trên rồi.
            if (! empty($allowedIds)) {
                $branches = $branches->filter(function ($b) use ($allowedIds) {
                    $childIds = Category::where('parent_id', $b->id)->pluck('id')->toArray();

                    return count(array_intersect(array_merge([$b->id], $childIds), $allowedIds)) > 0;
                })->values();
            }
        }

        $result = [];
        foreach ($branches as $branch) {
            $childIds  = Category::where('parent_id', $branch->id)->pluck('id')->toArray();
            $allCatIds = array_merge([$branch->id], $childIds);

            $rows = Order::query()
                ->where('exclude_from_stats', false)
                ->selectRaw('MONTH(created_at) as m, SUM(amount) as rev_paid, SUM(money_deposit) as rev_dep')
                ->whereYear('created_at', $year)
                ->whereIn('category_id', $allCatIds)
                ->where(function ($q) {
                    $q->where('status', 'paid')
                        ->orWhere(function ($q2) { $q2->where('status', 'deposit')->whereNotNull('money_deposit'); });
                })
                ->groupByRaw('MONTH(created_at)')
                ->get()
                ->keyBy('m');

            $monthlyData = [];
            for ($m = 1; $m <= 12; $m++) {
                $row = $rows->get($m);
                $monthlyData[] = $row ? (int) (($row->rev_paid ?? 0) + ($row->rev_dep ?? 0)) : 0;
            }

            $result[] = ['id' => $branch->id, 'name' => $branch->name, 'data' => $monthlyData];
        }

        return ['branches' => $result];
    }

    /**
     * TOP KHÁCH ĐẶT PHÒNG NHIỀU NHẤT: gộp theo buyer_phone trong năm, sắp theo tổng số lần đặt
     * giảm dần — cùng cách gộp số liệu với CustomerOrderCountExport (Modules/Payment/App/Exports),
     * rút gọn cho API (không xuất Excel, chỉ lấy top N).
     */
    private static function topCustomers($user, int $year, ?array $branchCategoryIds, int $limit): array
    {
        $query = Order::query()
            ->where('exclude_from_stats', false)
            ->whereYear('created_at', $year)
            ->whereNotNull('buyer_phone')
            ->where('buyer_phone', '!=', '');

        if ($user && ! $user->isSuperAdmin()) {
            if (empty($user->partner_id)) {
                return ['customers' => []];
            }
            $query->where('partner_id', $user->partner_id);

            $allowedIds = $user->allowedCategoryIds() ?? [];
            if (! empty($allowedIds)) {
                $query->whereIn('category_id', $allowedIds);
            }
        }

        if ($branchCategoryIds !== null) {
            $query->whereIn('category_id', $branchCategoryIds);
        }

        $rows = $query
            ->selectRaw('
                buyer_phone,
                MAX(buyer_name) as buyer_name,
                COUNT(*) as total_orders,
                SUM(CASE WHEN status IN ("paid","completed") THEN 1 ELSE 0 END) as paid_orders,
                SUM(CASE WHEN status = "paid" THEN COALESCE(amount, full_amount) ELSE 0 END) as total_spent,
                MIN(created_at) as first_order_at,
                MAX(created_at) as last_order_at
            ')
            ->groupBy('buyer_phone')
            ->orderByDesc('total_orders')
            ->limit($limit)
            ->get();

        return [
            'customers' => $rows->map(fn ($r) => [
                'buyer_phone'    => $r->buyer_phone,
                'buyer_name'     => $r->buyer_name,
                'total_orders'   => (int) $r->total_orders,
                'paid_orders'    => (int) $r->paid_orders,
                'total_spent'    => (int) $r->total_spent,
                'first_order_at' => $r->first_order_at,
                'last_order_at'  => $r->last_order_at,
            ])->values()->toArray(),
        ];
    }
}
