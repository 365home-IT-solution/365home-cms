<?php

declare(strict_types=1);

namespace Modules\Promotion\App\Filament\Resources\PromotionResource\Tables\BulkActions;

use Filament\Tables;

class PromotionBulkAction
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