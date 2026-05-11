<?php

declare(strict_types=1);

namespace Modules\User\App\Filament\Resources\UserResource\Tables\BulkAction;

use Filament\Tables;

class UserBulkAction
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
