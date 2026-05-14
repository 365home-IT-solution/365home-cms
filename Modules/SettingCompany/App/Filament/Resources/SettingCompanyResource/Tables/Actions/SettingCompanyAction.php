<?php

declare(strict_types=1);

namespace Modules\SettingCompany\App\Filament\Resources\SettingCompanyResource\Tables\Actions;

use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ViewAction;

class SettingCompanyAction
{
    public static function action()
    {
        return [
            ActionGroup::make([
                ViewAction::make()
                    ->label('Xem chi tiết'),
                EditAction::make()
                    ->label('Cập nhật'),
            ])
        ];
    }
}
