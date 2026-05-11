<?php

declare(strict_types=1);

namespace Modules\Page\App\Filament\Resources\PageResource\Tables\Actions;

use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\DeleteAction;

class PageAction
{
    public static function action()
    {
        return [
            ActionGroup::make([
                EditAction::make()->label('Cập nhật'),
                DeleteAction::make('Xóa')
            ])
        ];
    }
}