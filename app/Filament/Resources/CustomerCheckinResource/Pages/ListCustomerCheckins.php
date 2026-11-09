<?php

declare(strict_types=1);

namespace App\Filament\Resources\CustomerCheckinResource\Pages;

use App\Filament\Resources\CustomerCheckinResource;
use Filament\Resources\Pages\ListRecords;

class ListCustomerCheckins extends ListRecords
{
    protected static string $resource = CustomerCheckinResource::class;

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\Action::make('voucherScope')
                ->label('Đối tác / chi nhánh áp dụng mã')
                ->icon('heroicon-o-map-pin')
                ->color('gray')
                ->visible(fn () => \App\Filament\Pages\Setting\ManageVoucherScopeSettings::canAccess())
                ->url(\App\Filament\Pages\Setting\ManageVoucherScopeSettings::getUrl()),
        ];
    }
}
