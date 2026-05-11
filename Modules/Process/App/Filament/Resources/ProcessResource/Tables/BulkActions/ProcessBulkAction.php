<?php

declare(strict_types=1);

namespace Modules\Process\App\Filament\Resources\ProcessResource\Tables\BulkActions;

use Filament\Tables;

class ProcessBulkAction
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