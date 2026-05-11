<?php

declare(strict_types=1);

namespace Modules\Form\App\Filament\Resources\FormResource\Tables\BulkActions;

use Filament\Tables;

class FormBulkAction
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