<?php

namespace Modules\Payment\App\Filament\Resources\PaymentConfigurationResource\Tables\BulkActions;

use Filament\Tables;

class PaymentConfigurationBulkAction
{
    public static function bulkActions(): array
    {
        return [
            Tables\Actions\BulkActionGroup::make([
                Tables\Actions\DeleteBulkAction::make()
            ]),
        ];
    }
}