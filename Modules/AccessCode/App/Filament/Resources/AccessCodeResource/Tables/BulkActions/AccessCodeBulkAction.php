<?php

declare(strict_types=1);

namespace Modules\AccessCode\App\Filament\Resources\AccessCodeResource\Tables\BulkActions;

use Filament\Tables;
use Filament\Tables\Actions\BulkAction;

class AccessCodeBulkAction
{
    public static function bulkActions(): array
    {
        return [
            Tables\Actions\BulkActionGroup::make([
                Tables\Actions\DeleteBulkAction::make(),
                BulkAction::make('mark_expired')
                    ->label('Đánh dấu hết hạn')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(fn($records) => $records->each->update(['status' => 'expired']))
                    ->deselectRecordsAfterCompletion(),
            ])
        ];
    }
}
