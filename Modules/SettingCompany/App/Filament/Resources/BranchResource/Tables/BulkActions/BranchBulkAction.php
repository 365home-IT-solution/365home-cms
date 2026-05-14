<?php

declare(strict_types=1);

namespace Modules\SettingCompany\App\Filament\Resources\BranchResource\Tables\BulkActions;

use Filament\Tables;

class BranchBulkAction
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