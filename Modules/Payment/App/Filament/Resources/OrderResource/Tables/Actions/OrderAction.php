<?php

namespace Modules\Payment\App\Filament\Resources\OrderResource\Tables\Actions;

use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Actions\DeleteAction;

class OrderAction
{
    public static function action()
    {
        return [
            AssignAccessCodeAction::make(),
            OpenGateAction::make(),
            ActionGroup::make([
                ViewAction::make()->label('Xem chi tiết'),
                EditAction::make()->label('Cập nhật'),
                DeleteAction::make('Xóa')
            ])
        ];
    }
}