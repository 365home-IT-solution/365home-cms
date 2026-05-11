<?php

declare(strict_types=1);

namespace Modules\Themestudio\App\Filament\Resources\ThemeStudioResource\Tables\BulkActions;

use Filament\Tables;

class ThemeStudioBulkAction
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