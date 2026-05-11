<?php

declare(strict_types=1);

namespace Modules\Dashboard\App\Filament\Widgets;

use Filament\Widgets\ChartWidget;
use Modules\Form\Entities\Form;
use Modules\Form\Entities\FormSubmission;
use Illuminate\Support\Carbon;
use Flowframe\Trend\Trend;
use Flowframe\Trend\TrendValue;

class FormStatsWidget extends ChartWidget
{
    public ?string $filter = null;

    public function mount(): void
    {
        Carbon::setLocale('vi');
    }

    public function getHeading(): string
    {
        return __('dashboard::dashboard.widgets.form_stats.heading');
    }

    protected function getFilters(): array
    {
        $months = FormSubmission::selectRaw('DISTINCT DATE_FORMAT(created_at, "%Y-%m") as month')
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
        $forms = Form::all();
        $datasets = [];
        $labels = [];

        foreach ($forms as $index => $form) {
            $query = FormSubmission::where('form_id', $form->id);

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
                'label' => $form->name,
                'data' => $data->map(fn(TrendValue $value) => $value->aggregate),
                'borderColor' => $this->getColorForForm($index),
                'fill' => false,
            ];

            if (empty($labels)) {
                $labels = $data->map(fn(TrendValue $value) => $selectedMonth === 'all'
                    ? Carbon::parse($value->date)->isoFormat('MMMM YYYY')
                    : Carbon::parse($value->date)->isoFormat('DD/MM/YYYY'));
            }
        }

        return [
            'datasets' => $datasets,
            'labels' => $labels,
        ];
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

    protected function getColorForForm($index): string
    {
        $colors = [
            '#4CAF50',
            '#2196F3',
            '#FFC107',
            '#E91E63',
            '#9C27B0',
            '#FF5722',
            '#795548',
            '#607D8B',
            '#3F51B5',
            '#00BCD4',
        ];
        return $colors[$index % count($colors)];
    }

    public function getColumnSpan(): int | string | array
    {
        return 1;
    }
}
