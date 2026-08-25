<?php

declare(strict_types=1);

namespace Modules\Dashboard\App\Filament\Widgets;

use Carbon\Carbon;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Builder;
use Modules\Payment\Entities\Order;

class TrafficAnalyticsWidget extends Widget
{
    protected static string $view = 'dashboard::widgets.traffic-analytics';

    protected int | string | array $columnSpan = 'full';

    protected static ?int $sort = -1;

    public string $period = '30d';

    public function getViewData(): array
    {
        $query = $this->baseQuery();

        [$startDate, $endDate, $prevStart, $prevEnd] = $this->getPeriodDates();

        $currentQuery  = (clone $query)->whereBetween('created_at', [$startDate, $endDate]);
        $previousQuery = (clone $query)->whereBetween('created_at', [$prevStart, $prevEnd]);

        $total    = (clone $currentQuery)->count();
        $prevTotal = (clone $previousQuery)->count();
        $totalDelta = $prevTotal > 0 ? round((($total - $prevTotal) / $prevTotal) * 100, 1) : 0;

        // Revenue (paid + deposit)
        $revenue = (clone $currentQuery)
            ->whereIn('status', ['paid', 'deposit', 'shipping', 'completed'])
            ->sum('amount');
        $prevRevenue = (clone $previousQuery)
            ->whereIn('status', ['paid', 'deposit', 'shipping', 'completed'])
            ->sum('amount');
        $revenueDelta = $prevRevenue > 0 ? round((($revenue - $prevRevenue) / $prevRevenue) * 100, 1) : 0;

        // Paid orders
        $paidCount = (clone $currentQuery)->whereIn('status', ['paid', 'completed'])->count();
        $prevPaid  = (clone $previousQuery)->whereIn('status', ['paid', 'completed'])->count();
        $paidDelta = $prevPaid > 0 ? round((($paidCount - $prevPaid) / $prevPaid) * 100, 1) : 0;

        // Conversion rate (paid / total)
        $convRate     = $total > 0 ? round(($paidCount / $total) * 100, 1) : 0;
        $prevConvRate = $prevTotal > 0 ? round(($prevPaid / $prevTotal) * 100, 1) : 0;
        $convDelta    = round($convRate - $prevConvRate, 1);

        // Breakdown by status
        $statusLabels = [
            'pending'   => 'Chờ xác nhận',
            'deposit'   => 'Đặt cọc',
            'paid'      => 'Đã thanh toán',
            'shipping'  => 'Đang xử lý',
            'completed' => 'Hoàn thành',
            'cancelled' => 'Đã huỷ',
        ];
        $statusColors = [
            'pending'   => '#e8c275',
            'deposit'   => '#d97757',
            'paid'      => '#9ab87a',
            'shipping'  => '#7a9cb5',
            'completed' => '#b58ab5',
            'cancelled' => '#c96f57',
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
                return [
                    'key'   => $key,
                    'name'  => $label,
                    'color' => $statusColors[$key],
                    'count' => $count,
                    'pct'   => $pct,
                ];
            })
            ->filter(fn($s) => $s['count'] > 0)
            ->sortByDesc('count')
            ->values()
            ->toArray();

        // Donut data
        $donutData = collect($sources)->map(fn($s) => [
            'value' => $s['count'],
            'name'  => $s['name'],
            'hex'   => $s['color'],
        ])->values()->toArray();

        // Trend 7 days
        $trendDays    = [];
        $trendPending = [];
        $trendPaid    = [];
        $trendCancel  = [];
        for ($i = 6; $i >= 0; $i--) {
            $day         = Carbon::today()->subDays($i);
            $trendDays[] = $day->isoFormat('dd D/M');
            $dayQuery    = (clone $query)->whereDate('created_at', $day);

            $trendPending[] = (clone $dayQuery)->where('status', 'pending')->count();
            $trendPaid[]    = (clone $dayQuery)->whereIn('status', ['paid', 'completed'])->count();
            $trendCancel[]  = (clone $dayQuery)->where('status', 'cancelled')->count();
        }

        // Bar data (by status last 30d)
        $barQuery = (clone $query)->where('created_at', '>=', Carbon::now()->subDays(30));
        $barData  = collect($statusLabels)
            ->map(fn($label, $key) => [
                'name'  => $label,
                'value' => (clone $barQuery)->where('status', $key)->count(),
                'color' => $statusColors[$key],
            ])
            ->filter(fn($d) => $d['value'] > 0)
            ->sortBy('value')
            ->values()
            ->toArray();

        return compact(
            'total', 'totalDelta',
            'revenue', 'revenueDelta',
            'paidCount', 'paidDelta',
            'convRate', 'convDelta',
            'sources',
            'donutData',
            'trendDays', 'trendPending', 'trendPaid', 'trendCancel',
            'barData',
            'startDate', 'endDate'
        );
    }

    private function getPeriodDates(): array
    {
        $days = match ($this->period) {
            '7d'  => 7,
            '90d' => 90,
            'ytd' => now()->dayOfYear,
            default => 30,
        };

        $end      = Carbon::now()->endOfDay();
        $start    = Carbon::now()->subDays($days - 1)->startOfDay();
        $prevEnd  = $start->copy()->subSecond();
        $prevStart = $prevEnd->copy()->subDays($days - 1)->startOfDay();

        return [$start, $end, $prevStart, $prevEnd];
    }

    private function baseQuery(): Builder
    {
        $query = Order::query()->where('exclude_from_stats', false);
        $user  = auth()->user();

        if (! $user || $user->isSuperAdmin()) {
            return $query;
        }

        $allCategoryIds = $user->allowedCategoryIds();
        // Order đã tự lọc theo partner_id (BelongsToPartner); allowedCategoryIds chỉ thu hẹp thêm.
        if (empty($allCategoryIds)) {
            return $query;
        }

        return $query->whereIn('category_id', $allCategoryIds);
    }
}
