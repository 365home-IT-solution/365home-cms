<?php

declare(strict_types=1);

namespace Modules\Tag\App\Filament\Resources\TagResource\Tables\Actions;

use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Illuminate\Database\Eloquent\Model;

class TagAction
{
    public static function action()
    {
        return [
            ActionGroup::make([
                EditAction::make()
                ->label('Cập nhật')
                ->mutateFormDataUsing(function (array $data): array {
                    // Nếu người dùng xóa ảnh hoặc không có ảnh
                    if (blank($data['image'])) {
                        $data['image'] = 'no-image.jpg'; // Hoặc đường dẫn ảnh mặc định trong disk
                    }
                    return $data;
                }),
                DeleteAction::make('Xóa')
            ])
        ];
    }
}