<?php

declare(strict_types=1);

namespace Modules\Product\App\Filament\Resources\ProductResource\Tables\Actions;

use Filament\Support\Enums\MaxWidth;
use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Actions\DeleteAction;

class ProductAction
{
    public static function action(): array
    {
        return [
            AssignLockAction::make(),
            ActionGroup::make([
                ViewAction::make()->label('Xem chi tiết')->modalWidth(MaxWidth::Full),
                EditAction::make()->label('Cập nhật'),
                DeleteAction::make('Xóa')
            ])
        ];
    }
}