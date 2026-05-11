<?php

declare(strict_types=1);

namespace Modules\Tag\App\Filament\Resources\TagResource\Tables\BulkActions;

use Filament\Tables;

class TagBulkAction
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