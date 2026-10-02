<?php

declare(strict_types=1);

namespace Modules\AccessCode\App\Filament\Resources\AccessCodeResource\Tables\Actions;

use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Actions\DeleteAction;

class AccessCodeAction
{
    public static function action()
    {
        return [
            ActionGroup::make([
                ViewAction::make()->label('Xem chi tiết'),
                // Sang TRANG sửa — trong widget của trang Khóa cổng, EditAction mặc định mở modal
                // không có form (trống trơn).
                EditAction::make()->label('Cập nhật')
                    ->url(fn ($record) => \Modules\AccessCode\App\Filament\Resources\AccessCodeResource::getUrl('edit', ['record' => $record])),
                DeleteAction::make('Xóa'),
            ])
        ];
    }
}