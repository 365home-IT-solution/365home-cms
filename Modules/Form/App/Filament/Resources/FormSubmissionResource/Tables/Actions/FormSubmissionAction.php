<?php

declare(strict_types=1);

namespace Modules\Form\App\Filament\Resources\FormSubmissionResource\Tables\Actions;

use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\DeleteAction;

class FormSubmissionAction
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
