<?php

declare(strict_types=1);

namespace Modules\Component\App\Filament\Resources\ComponentResource\Tables\BulkActions;

use Filament\Tables;

class ComponentBulkAction
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