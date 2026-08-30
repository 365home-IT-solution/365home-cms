<?php

declare(strict_types=1);

namespace Modules\Coupon\App\Filament\Resources\CouponUsageLogResource\Pages;

use Filament\Actions;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Pages\ListRecords;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Coupon\App\Exports\CouponUsageLogExport;
use Modules\Coupon\App\Filament\Resources\CouponUsageLogResource;

class ListCouponUsageLog extends ListRecords
{
    protected static string $resource = CouponUsageLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('export')
                ->label('Xuất Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription('Chọn khoảng thời gian cần xuất (bỏ trống để xuất toàn bộ lịch sử).')
                ->form([
                    DatePicker::make('date_from')
                        ->label('Từ ngày')
                        ->displayFormat('d/m/Y'),
                    DatePicker::make('date_to')
                        ->label('Đến ngày')
                        ->displayFormat('d/m/Y')
                        ->afterOrEqual('date_from'),
                ])
                ->action(function (array $data) {
                    $fileName = 'lich_su_dung_ma_giam_gia_' . now()->format('Y-m-d_His') . '.xlsx';

                    return Excel::download(new CouponUsageLogExport($data), $fileName);
                }),
        ];
    }
}
