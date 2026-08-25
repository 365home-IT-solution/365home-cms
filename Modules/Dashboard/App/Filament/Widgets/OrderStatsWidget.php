<?php

namespace Modules\Dashboard\App\Filament\Widgets;

use Filament\Widgets\ChartWidget;
use Flowframe\Trend\Trend;
use Flowframe\Trend\TrendValue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Modules\Payment\Entities\Order;
use App\Settings\GeneralSettings;

class OrderStatsWidget extends ChartWidget
{
    protected static ?string $heading = 'Thống kê đơn hàng';

    public ?string $filter = null;

    public function mount(): void
    {
        Carbon::setLocale('vi');
    }

    protected function getFilters(): array
    {
        // Lấy 12 tháng gần nhất có đơn hàng (theo chi nhánh được phép)
        $months = $this->branchFilteredOrderQuery()
            ->selectRaw('DISTINCT DATE_FORMAT(created_at, "%Y-%m") as month')
            ->orderBy('month', 'desc')
            ->pluck('month')
            ->take(12)
            ->map(function ($month) {
                $date = Carbon::createFromFormat('Y-m', $month);
                return [
                    'value' => $month,
                    'label' => __('Tháng :month/:year', [
                        'month' => $date->format('m'),
                        'year' => $date->format('Y'),
                    ]),
                ];
            })
            ->toArray();

        return [
                'all' => __('Tất cả'),
            ] + collect($months)->pluck('label', 'value')->toArray();
    }

    protected function getData(): array
    {
        $theme = app(GeneralSettings::class)->site_theme;
        $primaryColor = $theme['primary'] ?? '#4CAF50';
        $selectedMonth = $this->filter ?? 'all';
        $labels = [];
        $data = [];

        if ($selectedMonth !== 'all') {
            $startDate = Carbon::createFromFormat('Y-m', $selectedMonth)->startOfMonth();
            $endDate = $startDate->copy()->endOfMonth();

            $trend = Trend::query($this->branchFilteredOrderQuery())
                ->between(
                    start: $startDate->startOfDay(),
                    end: $endDate->endOfDay()
                )
                ->perDay()
                ->count();

            $labels = $trend->map(fn(TrendValue $value) => Carbon::parse($value->date)->isoFormat('DD/MM'));
            $data = $trend->map(fn(TrendValue $value) => $value->aggregate);
        } else {
            $trend = Trend::query($this->branchFilteredOrderQuery())
                ->between(
                    start: now()->startOfYear(),
                    end: now()->endOfDay()
                )
                ->perMonth()
                ->count();

            $labels = $trend->map(fn(TrendValue $value) => Carbon::parse($value->date)->isoFormat('MMMM'));
            $data = $trend->map(fn(TrendValue $value) => $value->aggregate);
        }

        return [
            'datasets' => [
                [
                    'label' => 'BOOKING',
                    'data' => $data,
                    'backgroundColor' => $primaryColor,
                ],
            ],
            'labels' => $labels,
        ];
    }

    private function branchFilteredOrderQuery(): Builder
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

    protected function getType(): string
    {
        return 'bar'; // Hoặc 'line' nếu bạn muốn đổi
    }

    protected function getOptions(): array
    {
        return [
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                    'ticks' => [
                        'precision' => 0,
                    ],
                ],
            ],
        ];
    }

    public function getColumnSpan(): int|string|array
    {
        return 2; // Có thể đổi sang 1 hoặc 3 tùy layout
    }
}
