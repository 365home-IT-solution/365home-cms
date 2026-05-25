<?php

declare(strict_types=1);

namespace Modules\Category\App\Filament\Resources\CategoryResource\Tables\Actions;

use Filament\Support\Enums\MaxWidth;
use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Actions\DeleteAction;

class CategoryAction
{
    public static function action()
    {
        return [
            ActionGroup::make([
                ViewAction::make()->label('Xem chi tiết')
                    ->modalWidth(MaxWidth::Full),
                EditAction::make()->label('Cập nhật')
                    ->modalWidth(MaxWidth::Full),
                DeleteAction::make()->label('Xóa')
            ])
        ];
    }
}
