<?php

declare(strict_types=1);

namespace Modules\Category\App\Filament\Resources\CategoryResource\Tables\Actions;

use Filament\Support\Enums\MaxWidth;
use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Actions\DeleteAction;
use Illuminate\Database\Eloquent\Model;

class CategoryAction
{
    public static function action()
    {
        return [
            ActionGroup::make([
                ViewAction::make()->label('Xem chi tiết')
                    ->modalWidth(MaxWidth::FourExtraLarge),
                EditAction::make()->label('Cập nhật')
                    ->url(fn (Model $record): string => route('filament.admin.resources.categories.edit', $record)),
                DeleteAction::make()->label('Xóa')
            ])
        ];
    }
}
