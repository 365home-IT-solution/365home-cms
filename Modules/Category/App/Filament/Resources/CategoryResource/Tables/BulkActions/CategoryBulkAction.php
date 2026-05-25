<?php

declare(strict_types=1);

namespace Modules\Category\App\Filament\Resources\CategoryResource\Tables\BulkActions;

use Filament\Tables;
use Filament\Notifications\Notification;

class CategoryBulkAction
{
    public static function bulkActions(): array
    {
        return [
            Tables\Actions\BulkActionGroup::make([
                Tables\Actions\DeleteBulkAction::make()
                    ->action(function (\Illuminate\Database\Eloquent\Collection $records): void {
                        $protected = 0;

                        foreach ($records as $record) {
                            if ($record->isAssignedToUserPermission()) {
                                $protected++;
                                continue;
                            }
                            $record->delete();
                        }

                        if ($protected > 0) {
                            Notification::make()
                                ->warning()
                                ->title('Không thể xóa ' . $protected . ' danh mục')
                                ->body('Các danh mục đã được phân quyền chi nhánh cho nhân viên không thể xóa.')
                                ->send();
                        }
                    }),
            ]),
        ];
    }
}