<?php

namespace Modules\BladeThemeV1\Livewire;

use Livewire\Component;

class ChartComponent extends Component
{
    public $chartData;

    public function mount()
    {
        $this->chartData = [
            'labels' => ['THÁNG 11/2024', 'THÁNG 12/2024', 'THÁNG 01/2025', 'THÁNG 02/2025', 'THÁNG 03/2025', 'THÁNG 04/2025'],
            'datasets' => [
                [
                    'type' => 'bar',
                    'label' => 'Duyên Hải Miền Trung - Bình 12KG',
                    'data' => [350000, 355000, 360000, 350000, 345000, 340000],
                    'backgroundColor' => 'blue',
                ],
                [
                    'type' => 'bar',
                    'label' => 'Tây Nguyên - Bình 12KG',
                    'data' => [340000, 345000, 350000, 340000, 335000, 330000],
                    'backgroundColor' => 'green',
                ],
                [
                    'type' => 'bar',
                    'label' => 'Tây Nam Bộ - Bình 12KG',
                    'data' => [360000, 365000, 370000, 360000, 355000, 350000],
                    'backgroundColor' => 'red',
                ],
                [
                    'type' => 'bar',
                    'label' => 'Đông Nam Bộ - Bình 12KG',
                    'data' => [355000, 360000, 365000, 355000, 350000, 345000],
                    'backgroundColor' => 'yellow',
                ],
                [
                    'type' => 'line',
                    'label' => 'Duyên Hải Miền Trung - Bình 45KG',
                    'data' => [1200000, 1200000, 1200000, 1200000, 1200000, 1200000],
                    'borderColor' => 'blue',
                    'fill' => false,
                    'pointRadius' => 5,
                ],
                [
                    'type' => 'line',
                    'label' => 'Tây Nguyên - Bình 45KG',
                    'data' => [1150000, 1150000, 1150000, 1150000, 1150000, 1150000],
                    'borderColor' => 'green',
                    'fill' => false,
                    'pointRadius' => 5,
                ],
                [
                    'type' => 'line',
                    'label' => 'Tây Nam Bộ - Bình 45KG',
                    'data' => [1687581, 1687581, 1687581, 1687581, 1687581, 1687581],
                    'borderColor' => 'red',
                    'fill' => false,
                    'pointRadius' => 5,
                ],
                [
                    'type' => 'line',
                    'label' => 'Đông Nam Bộ - Bình 45KG',
                    'data' => [1220000, 1220000, 1220000, 1220000, 1220000, 1220000],
                    'borderColor' => 'yellow',
                    'fill' => false,
                    'pointRadius' => 5,
                ],
            ],
        ];
    }

    public function render()
    {
        return view('bladethemev1::livewire.chart-component');
    }
}