<?php

declare(strict_types=1);

namespace Modules\Dashboard\App\Filament\Widgets;

use Filament\Widgets\ChartWidget;
use Illuminate\Database\Eloquent\Builder;
use Modules\Post\Entities\Post;
use Illuminate\Support\Carbon;
use Flowframe\Trend\Trend;
use Flowframe\Trend\TrendValue;

class PostStatsWidget extends ChartWidget
{
    public ?string $filter = null;

    public function mount(): void
    {
        Carbon::setLocale('vi');
    }

    public function getHeading(): string
    {
        return __('dashboard::dashboard.widgets.post_stats.heading');
    }

    protected function getFilters(): array
    {
        $months = $this->branchFilteredPostQuery()
            ->selectRaw('DISTINCT DATE_FORMAT(created_at, "%Y-%m") as month')
            ->orderBy('month', 'desc')
            ->pluck('month')
            ->take(12)
            ->map(function ($month) {
                $date = Carbon::createFromFormat('Y-m', $month);
                return [
                    'value' => $month,
                    'label' => __('dashboard::dashboard.widgets.form_stats.filters.month', [
                        'month' => $date->format('m'),
                        'year' => $date->format('Y')
                    ])
                ];
            })
            ->toArray();

        return [
            'all' => __('dashboard::dashboard.widgets.form_stats.filters.all')
        ] + collect($months)->pluck('label', 'value')->toArray();
    }

    protected function getData(): array
    {
        $selectedMonth = $this->filter ?? 'all';
        $statuses = ['draft', 'published', 'archived'];
        $datasets = [];

        foreach ($statuses as $status) {
            $query = $this->branchFilteredPostQuery()->where('status', $status);

            if ($selectedMonth !== 'all') {
                $startDate = Carbon::createFromFormat('Y-m', $selectedMonth)->startOfMonth();
                $endDate = $startDate->copy()->endOfMonth();

                $query->whereBetween('created_at', [$startDate, $endDate]);
                $data = Trend::query($query)
                    ->between(
                        start: $startDate->startOfDay(),
                        end: $endDate->endOfDay()
                    )
                    ->perDay()
                    ->count();
            } else {
                $data = Trend::query($query)
                    ->between(
                        start: now()->startOfYear(),
                        end: now()->endOfDay()
                    )
                    ->perMonth()
                    ->count();
            }

            $datasets[] = [
                'label' => $this->getStatusLabel($status),
                'data' => $data->map(fn (TrendValue $value) => $value->aggregate),
                'borderColor' => $this->getColorForStatus($status),
                'fill' => false,
            ];
        }

        return [
            'datasets' => $datasets,
            'labels' => $data->map(fn (TrendValue $value) => $selectedMonth === 'all'
                ? Carbon::parse($value->date)->isoFormat('MMMM YYYY')
                : Carbon::parse($value->date)->isoFormat('DD/MM/YYYY')),
        ];
    }

    private function branchFilteredPostQuery(): Builder
    {
        $query = Post::query();
        $user  = auth()->user();

        if (! $user || $user->isSuperAdmin()) {
            return $query;
        }

        $allowedPostCategoryIds = $user->allowedPostCategoryIds();

        if (empty($allowedPostCategoryIds)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('categories', function (Builder $q) use ($allowedPostCategoryIds) {
            $q->whereIn('categories.id', $allowedPostCategoryIds);
        });
    }

    protected function getStatusLabel($status): string
    {
        return __("dashboard::dashboard.widgets.post_stats.status.{$status}");
    }

    protected function getColorForStatus($status): string
    {
        return match ($status) {
            'draft' => 'rgb(255, 99, 132)',
            'published' => 'rgb(54, 162, 235)',
            'archived' => 'rgb(255, 205, 86)',
            default => 'rgb(201, 203, 207)',
        };
    }

    protected function getType(): string
    {
        return 'line';
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
}
