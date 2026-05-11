<?php

declare(strict_types=1);

namespace Modules\Page\App\Filament\Resources\PageResource\Tables\BulkActions;

use Filament\Tables;

class PageBulkAction
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