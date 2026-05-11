<?php

declare(strict_types=1);

namespace Modules\Form\App\Filament\Resources\FormNotificationResource\Tables\BulkActions;

use Filament\Tables;

class FormNotificationBulkAction
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