<?php

declare(strict_types=1);

namespace Modules\Tag\App\Filament\Resources\TagResource\Tables\BulkActions;

use Filament\Tables;
use Illuminate\Support\Collection;
use Modules\Tag\App\Filament\Resources\TagResource\Tables\Actions\TagAction;

class TagBulkAction
{
    public static function bulkActions(): array
    {
        return [
            Tables\Actions\BulkActionGroup::make([
                // Chỉ xoá các tiện ích người dùng được sửa — mẫu hệ thống lẫn trong lựa chọn bị bỏ qua.
                Tables\Actions\DeleteBulkAction::make()
                    ->action(fn (Collection $records) => $records->filter(fn ($record) => TagAction::editable($record))->each->delete()),
            ]),
        ];
    }
}