<?php

declare(strict_types=1);

namespace Modules\Coupon\App\Filament\Resources\CouponResource\Pages;

use Modules\Coupon\App\Filament\Resources\CouponResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListCoupon extends ListRecords
{
    protected static string $resource = CouponResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Tạo mã giảm giá mới')
                ->icon('heroicon-o-plus'),
        ];
    }
}
