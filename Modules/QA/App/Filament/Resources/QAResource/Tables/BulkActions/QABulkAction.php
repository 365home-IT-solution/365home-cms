<?php

declare(strict_types=1);

namespace Modules\QA\App\Filament\Resources\QAResource\Tables\BulkActions;

use Filament\Tables;

class QABulkAction
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