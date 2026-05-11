<?php

namespace Modules\ThemeSetting\App\Filament\Resources\ThemeResource\Tables\BulkActions;

use Filament\Tables;

class ThemeBulkAction
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