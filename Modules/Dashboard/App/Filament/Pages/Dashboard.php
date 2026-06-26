<?php

declare(strict_types=1);

namespace Modules\Dashboard\App\Filament\Pages;

use Carbon\Carbon;
use Filament\Pages\Dashboard as FilamentDashboard;
use Illuminate\Database\Eloquent\Builder;
use Modules\Dashboard\App\Services\KpiService;
use Modules\Dashboard\App\Services\RoomCardsService;
use Illuminate\Support\Facades\DB;
use Modules\Payment\Entities\Order;
use Modules\Payment\Entities\OrderItem;
use Modules\Product\App\Models\Product;

class Dashboard extends FilamentDashboard
{
    public string $period = '30d';

    /** Chỉ dùng khi period === 'custom' */
    public ?string $customStart = null;
    public ?string $customEnd   = null;

    public function getHeading(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return '';
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();
        if (! $user) {
            return false;
        }

        if ($user->hasRole('super_admin')) {
            return true;
        }

        return $user->can('page_Dashboard');
    }

    public function getView(): string
    {
        return 'dashboard::pages.dashboard';
    }

    public function getViewData(): array
    {
        $query = $this->baseQuery();

        [$startDate, $endDate, $prevStart, $prevEnd, $dateRange, $prevDateRange] = $this->getPeriodDates();

        $currentQuery  = (clone $query)->whereBetween('created_at', [$startDate, $endDate]);
        $previousQuery = (clone $query)->whereBetween('created_at', [$prevStart, $prevEnd]);

        $total     = (clone $currentQuery)->count();
        $prevTotal = (clone $previousQuery)->count();
        $totalDelta = $prevTotal > 0 ? round((($total - $prevTotal) / $prevTotal) * 100, 1) : 0;

        $revenue = static::sumOrderAmount((clone $currentQuery)->where('status', 'paid'))
                 + (clone $currentQuery)->where('status', 'deposit')->whereNotNull('money_deposit')->sum('money_deposit');
        $prevRevenue = static::sumOrderAmount((clone $previousQuery)->where('status', 'paid'))
                     + (clone $previousQuery)->where('status', 'deposit')->whereNotNull('money_deposit')->sum('money_deposit');
        $revenueDelta = $prevRevenue > 0 ? round((($revenue - $prevRevenue) / $prevRevenue) * 100, 1) : 0;

        $revenuePayos = static::sumOrderAmount((clone $currentQuery)->where('status', 'paid')->where('payment_method', 'PayOS'));
        $prevRevenuePayos = static::sumOrderAmount((clone $previousQuery)->where('status', 'paid')->where('payment_method', 'PayOS'));
        $revenuePayosDelta = $prevRevenuePayos > 0 ? round((($revenuePayos - $prevRevenuePayos) / $prevRevenuePayos) * 100, 1) : 0;

        $revenueCod = static::sumOrderAmount((clone $currentQuery)->where('status', 'paid')->where('payment_method', 'cod'));
        $prevRevenueCod = static::sumOrderAmount((clone $previousQuery)->where('status', 'paid')->where('payment_method', 'cod'));
        $revenueCodDelta = $prevRevenueCod > 0 ? round((($revenueCod - $prevRevenueCod) / $prevRevenueCod) * 100, 1) : 0;

        $revenueDepositPayos = (clone $currentQuery)->where('status', 'deposit')->where('payment_method', 'PayOS')->sum('money_deposit');
        $prevRevenueDepositPayos = (clone $previousQuery)->where('status', 'deposit')->where('payment_method', 'PayOS')->sum('money_deposit');
        $revenueDepositPayosDelta = $prevRevenueDepositPayos > 0 ? round((($revenueDepositPayos - $prevRevenueDepositPayos) / $prevRevenueDepositPayos) * 100, 1) : 0;

        $revenueDepositCod = (clone $currentQuery)->where('status', 'deposit')->where('payment_method', 'cod')->sum('money_deposit');
        $prevRevenueDepositCod = (clone $previousQuery)->where('status', 'deposit')->where('payment_method', 'cod')->sum('money_deposit');
        $revenueDepositCodDelta = $prevRevenueDepositCod > 0 ? round((($revenueDepositCod - $prevRevenueDepositCod) / $prevRevenueDepositCod) * 100, 1) : 0;

        $paidCount = (clone $currentQuery)->whereIn('status', ['paid', 'completed'])->count();
        $prevPaid  = (clone $previousQuery)->whereIn('status', ['paid', 'completed'])->count();
        $paidDelta = $prevPaid > 0 ? round((($paidCount - $prevPaid) / $prevPaid) * 100, 1) : 0;

        $statusLabels = [
            'pending'   => 'Chờ xác nhận',
            'deposit'   => 'Đặt cọc',
            'paid'      => 'Đã thanh toán',
            'shipping'  => 'Đang xử lý',
            'completed' => 'Hoàn thành',
            'cancelled' => 'Đã huỷ',
            'failed'    => 'Thất bại',
        ];
        $statusColors = [
            'pending'   => '#e8c275',
            'deposit'   => '#d97757',
            'paid'      => '#9ab87a',
            'shipping'  => '#7a9cb5',
            'completed' => '#b58ab5',
            'cancelled' => '#c96f57',
            'failed'    => '#ef4444',
        ];

        $byStatus = (clone $currentQuery)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $sources = collect($statusLabels)
            ->map(function ($label, $key) use ($byStatus, $statusColors, $total) {
                $count = $byStatus[$key] ?? 0;
                $pct   = $total > 0 ? round(($count / $total) * 100, 1) : 0;
                return ['key' => $key, 'name' => $label, 'color' => $statusColors[$key], 'count' => $count, 'pct' => $pct];
            })
            ->filter(fn($s) => $s['count'] > 0)
            ->sortByDesc('count')
            ->values()
            ->toArray();

        $donutData = collect($sources)->map(fn($s) => [
            'value' => $s['count'], 'name' => $s['name'], 'hex' => $s['color'],
        ])->values()->toArray();

        $trendDays = $trendPending = $trendPaid = $trendCancel = [];
        for ($i = 6; $i >= 0; $i--) {
            $day           = Carbon::today()->subDays($i);
            $trendDays[]   = $day->isoFormat('dd D/M');
            $dq            = (clone $query)->whereDate('created_at', $day);
            $trendPending[] = (clone $dq)->where('status', 'pending')->count();
            $trendPaid[]   = (clone $dq)->whereIn('status', ['paid', 'completed'])->count();
            $trendCancel[] = (clone $dq)->where('status', 'cancelled')->count();
        }

        $barQuery = (clone $query)->where('created_at', '>=', Carbon::now()->subDays(30));
        $barData  = collect($statusLabels)
            ->map(fn($label, $key) => [
                'name' => $label, 'value' => (clone $barQuery)->where('status', $key)->count(), 'color' => $statusColors[$key],
            ])
            ->filter(fn($d) => $d['value'] > 0)
            ->sortBy('value')
            ->values()
            ->toArray();

        $roomCards      = static::getRoomCardsData();
        $roomRevenue    = static::getRoomRevenueData();
        $monthlyRevenue = static::getMonthlyRevenueData();

        $user            = auth()->user();
        $branchesQuery   = \Modules\Category\Entities\Category::whereNull('parent_id')
            ->where('category_type', 'product')
            ->orderBy('name');
        if ($user && ! $user->isSuperAdmin()) {
            $allowedIds = $user->allowedCategoryIds() ?? [];
            if (! empty($allowedIds)) {
                $branchesQuery->whereIn('id', $allowedIds)
                    ->orWhereIn('id', \Modules\Category\Entities\Category::whereIn('id', $allowedIds)->pluck('parent_id')->filter()->toArray());
            }
        }
        $branches = $branchesQuery->get(['id', 'name']);

        return compact(
            'total', 'totalDelta',
            'revenue', 'revenueDelta',
            'revenuePayos', 'revenuePayosDelta',
            'revenueCod', 'revenueCodDelta',
            'revenueDepositPayos', 'revenueDepositPayosDelta',
            'revenueDepositCod', 'revenueDepositCodDelta',
            'paidCount', 'paidDelta',
            'sources', 'donutData',
            'trendDays', 'trendPending', 'trendPaid', 'trendCancel',
            'barData', 'dateRange', 'prevDateRange',
            'roomCards', 'roomRevenue', 'monthlyRevenue',
            'branches'
        );
    }

    private function getPeriodDates(): array
    {
        $end = Carbon::now()->endOfDay();

        if ($this->period === 'custom') {
            $start = $this->customStart
                ? Carbon::parse($this->customStart)->startOfDay()
                : Carbon::now()->subDays(29)->startOfDay();
            $end = $this->customEnd
                ? Carbon::parse($this->customEnd)->endOfDay()
                : Carbon::now()->endOfDay();
            if ($start->gt($end)) {
                [$start, $end] = [$end, $start];
            }
        } elseif ($this->period === 'today') {
            $start = Carbon::today()->startOfDay();
            $end   = Carbon::today()->endOfDay();
        } elseif ($this->period === 'yesterday') {
            $start = Carbon::yesterday()->startOfDay();
            $end   = Carbon::yesterday()->endOfDay();
        } elseif ($this->period === 'this_month') {
            $start = Carbon::now()->startOfMonth()->startOfDay();
            $end   = Carbon::now()->endOfDay();
        } elseif ($this->period === 'last_month') {
            $start = Carbon::now()->subMonthNoOverflow()->startOfMonth()->startOfDay();
            $end   = Carbon::now()->subMonthNoOverflow()->endOfMonth()->endOfDay();
        } elseif ($this->period === 'last_year') {
            $start = Carbon::now()->subYear()->startOfYear()->startOfDay();
            $end   = Carbon::now()->subYear()->endOfYear()->endOfDay();
        } elseif ($this->period === 'ytd') {
            $start = Carbon::today()->startOfYear()->startOfDay();
        } else {
            $days  = match ($this->period) { '7d' => 7, '90d' => 90, default => 30 };
            $start = Carbon::now()->subDays($days - 1)->startOfDay();
        }

        // Kỳ so sánh: tháng này → tháng trước cùng kỳ; tháng trước / năm trước → cùng kỳ năm trước
        if ($this->period === 'this_month') {
            $prevStart = Carbon::now()->subMonthNoOverflow()->startOfMonth()->startOfDay();
            $prevEnd   = $prevStart->copy()->addDays(Carbon::now()->day - 1)->endOfDay();
        } elseif ($this->period === 'last_month') {
            $prevStart = Carbon::now()->subMonths(2)->startOfMonth()->startOfDay();
            $prevEnd   = Carbon::now()->subMonths(2)->endOfMonth()->endOfDay();
        } elseif ($this->period === 'last_year') {
            $prevStart = Carbon::now()->subYears(2)->startOfYear()->startOfDay();
            $prevEnd   = Carbon::now()->subYears(2)->endOfYear()->endOfDay();
        } else {
            $periodDays = max(1, (int) $start->diffInDays($end));
            $prevEnd    = $start->copy()->subSecond();
            $prevStart  = $prevEnd->copy()->subDays($periodDays - 1)->startOfDay();
        }

        $dateRange     = $start->format('j/n') . ' – ' . $end->format('j/n');
        $prevDateRange = $prevStart->format('j/n') . ' – ' . $prevEnd->format('j/n');

        return [$start, $end, $prevStart, $prevEnd, $dateRange, $prevDateRange];
    }

    /** Proxy — dùng bởi routes/web.php và Livewire SSR */
    public static function getRoomCardsData($user = null): array
    {
        return RoomCardsService::getData($user);
    }

    /** Proxy — dùng bởi routes/web.php */
    public static function getKpiData(string $period, $user = null, ?string $customStart = null, ?string $customEnd = null): array
    {
        return KpiService::getData($period, $user, $customStart, $customEnd);
    }

    /** Doanh thu từng phòng trong năm chỉ định (top 10, status=paid, PayOS+cod) */
    public static function getRoomRevenueData($user = null, ?int $year = null, ?array $branchCategoryIds = null): array
    {
        if ($user === null) {
            $user = auth()->user();
        }
        $year      = $year ?? Carbon::now()->year;
        $itemTable = (new OrderItem)->getTable();
        $ordTable  = (new Order)->getTable();
        $prodTable = (new Product)->getTable();
        // Subquery: lấy cặp (order_id, product_id) duy nhất kèm orders.amount thực tế.
        // Dùng orders.amount thay vì order_items.price*quantity vì slot bookings có thể lưu base price = 0
        // trong khi orders.amount luôn là số tiền thực tế đã thanh toán.
        $prefix = DB::getTablePrefix();
        $inner = DB::table("{$itemTable} as oi")
            ->join("{$ordTable} as o", 'oi.order_id', '=', 'o.id')
            ->join("{$prodTable} as p", 'oi.product_id', '=', 'p.id')
            ->where('o.status', 'paid')
            ->whereIn('o.payment_method', ['PayOS', 'cod'])
            ->where('o.exclude_from_stats', false)
            ->whereYear('o.created_at', $year)
            ->whereNotNull('oi.product_id')
            ->selectRaw("{$prefix}oi.product_id, {$prefix}p.name as product_name, {$prefix}o.id as order_id, COALESCE({$prefix}o.full_amount, {$prefix}o.amount) as order_amount")
            ->distinct();

        if ($user && ! $user->isSuperAdmin()) {
            $categoryIds = $user->allowedCategoryIds() ?? [];
            if (empty($categoryIds)) {
                return ['rooms' => [], 'total' => 0, 'year' => $year, 'available_years' => [$year]];
            }
            $allowedProductIds = Product::whereHas('categories', function ($q) use ($categoryIds) {
                $q->whereIn('categories.id', $categoryIds);
            })->pluck('id')->toArray();
            if (empty($allowedProductIds)) {
                return ['rooms' => [], 'total' => 0, 'year' => $year, 'available_years' => [$year]];
            }
            $inner->whereIn('oi.product_id', $allowedProductIds);
        }

        if ($branchCategoryIds !== null) {
            $inner->whereIn('o.category_id', $branchCategoryIds);
        }

        $rooms = DB::table(DB::raw("({$inner->toSql()}) as sub"))
            ->mergeBindings($inner)
            ->selectRaw('product_id, product_name, SUM(order_amount) as revenue, COUNT(*) as order_count')
            ->groupBy('product_id', 'product_name')
            ->orderByDesc('revenue')
            ->limit(10)
            ->get();

        $total = $rooms->sum('revenue');

        return [
            'rooms' => $rooms->map(fn ($r) => [
                'product_id'  => $r->product_id,
                'name'        => $r->product_name,
                'revenue'     => (int) $r->revenue,
                'order_count' => (int) $r->order_count,
                'pct'         => $total > 0 ? round(($r->revenue / $total) * 100, 1) : 0,
            ])->values()->toArray(),
            'total'           => (int) $total,
            'year'            => $year,
            'available_years' => static::getAvailableYears($user),
        ];
    }

    /** Doanh thu từng tháng trong năm chỉ định (mảng 12 phần tử, status=paid, PayOS+cod) */
    public static function getMonthlyRevenueData($user = null, ?int $year = null, ?array $branchCategoryIds = null): array
    {
        if ($user === null) {
            $user = auth()->user();
        }
        $year  = $year ?? Carbon::now()->year;
        $query = Order::query()
            ->where('exclude_from_stats', false)
            ->where('status', 'paid')
            ->whereIn('payment_method', ['PayOS', 'cod'])
            ->whereYear('created_at', $year);

        if ($user && ! $user->isSuperAdmin()) {
            $categoryIds = $user->allowedCategoryIds() ?? [];
            if (empty($categoryIds)) {
                return ['months' => array_fill(0, 12, 0), 'year' => $year, 'available_years' => [$year]];
            }
            $allowedProductIds = Product::whereHas('categories', function ($q) use ($categoryIds) {
                $q->whereIn('categories.id', $categoryIds);
            })->pluck('id')->toArray();
            if (empty($allowedProductIds)) {
                return ['months' => array_fill(0, 12, 0), 'year' => $year, 'available_years' => [$year]];
            }
            $query->whereHas('items', function ($q) use ($allowedProductIds) {
                $q->whereIn('product_id', $allowedProductIds);
            });
        }

        if ($branchCategoryIds !== null) {
            $query->whereIn('category_id', $branchCategoryIds);
        }

        $data = $query
            ->selectRaw('MONTH(created_at) as month, SUM(COALESCE(full_amount, amount)) as revenue')
            ->groupByRaw('MONTH(created_at)')
            ->pluck('revenue', 'month');

        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $months[] = (int) ($data[$m] ?? 0);
        }

        return [
            'months'          => $months,
            'year'            => $year,
            'available_years' => static::getAvailableYears($user),
        ];
    }

    /** Các năm có đơn hàng paid (PayOS + cod), mới nhất trước */
    public static function getAvailableYears($user = null): array
    {
        if ($user === null) {
            $user = auth()->user();
        }
        $query = Order::query()
            ->where('exclude_from_stats', false)
            ->where('status', 'paid')
            ->whereIn('payment_method', ['PayOS', 'cod'])
            ->selectRaw('YEAR(created_at) as year')
            ->distinct()
            ->orderByRaw('YEAR(created_at) DESC');

        if ($user && ! $user->isSuperAdmin()) {
            $categoryIds = $user->allowedCategoryIds() ?? [];
            if (empty($categoryIds)) {
                return [Carbon::now()->year];
            }
            $allowedProductIds = Product::whereHas('categories', function ($q) use ($categoryIds) {
                $q->whereIn('categories.id', $categoryIds);
            })->pluck('id')->toArray();
            if (empty($allowedProductIds)) {
                return [Carbon::now()->year];
            }
            $query->whereHas('items', function ($q) use ($allowedProductIds) {
                $q->whereIn('product_id', $allowedProductIds);
            });
        }

        $years = $query->pluck('year')->map(fn ($y) => (int) $y)->toArray();

        return empty($years) ? [Carbon::now()->year] : $years;
    }

    private function baseQuery(): Builder
    {
        $query = Order::query()->where('exclude_from_stats', false);
        $user  = auth()->user();
        if (! $user || $user->isSuperAdmin()) {
            return $query;
        }
        $allCategoryIds = $user->allowedCategoryIds();
        if (empty($allCategoryIds)) {
            return $query->whereRaw('1 = 0');
        }
        return $query->whereIn('category_id', $allCategoryIds);
    }

    /** Tổng tiền đơn đã thanh toán: ưu tiên full_amount (tổng thực tế), fallback amount */
    private static function sumOrderAmount($query): int
    {
        return (int) ($query->selectRaw('SUM(COALESCE(full_amount, amount)) as total')->value('total') ?? 0);
    }
}
