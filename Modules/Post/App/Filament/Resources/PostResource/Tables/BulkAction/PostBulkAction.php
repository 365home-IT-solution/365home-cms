<?php

declare(strict_types=1);

namespace Modules\Post\App\Filament\Resources\PostResource\Tables\BulkAction;

use Filament\Tables;

class PostBulkAction
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
