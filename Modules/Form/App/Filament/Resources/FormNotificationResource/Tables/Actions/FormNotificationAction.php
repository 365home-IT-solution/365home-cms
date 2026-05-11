<?php

declare(strict_types=1);

namespace Modules\Form\App\Filament\Resources\FormNotificationResource\Tables\Actions;

use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Actions\DeleteAction;

class FormNotificationAction
{
    public static function action()
    {
        return [
            ActionGroup::make([
                ViewAction::make()->label('Xem chi tiết'),
                EditAction::make()->label('Cập nhật'),
                DeleteAction::make()->label('Xóa'),
            ])
        ];
    }
}
