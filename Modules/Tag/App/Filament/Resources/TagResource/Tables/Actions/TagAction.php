<?php

declare(strict_types=1);

namespace Modules\Tag\App\Filament\Resources\TagResource\Tables\Actions;

use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Illuminate\Database\Eloquent\Model;

class TagAction
{
    // Mẫu hệ thống (partner_id NULL) chỉ super_admin (không chọn đối tác) được sửa/xoá.
    public static function editable(Model $record): bool
    {
        $user = auth()->user();

        return $user && ($user->seesAllPartners() || ($record->partner_id !== null && $record->partner_id === $user->partner_id));
    }

    public static function action()
    {
        return [
            ActionGroup::make([
                EditAction::make()
                ->label('Cập nhật')
                ->visible(fn (Model $record) => self::editable($record))
                ->mutateFormDataUsing(function (array $data): array {
                    // Nếu người dùng xóa ảnh hoặc không có ảnh
                    if (blank($data['image'])) {
                        $data['image'] = 'no-image.jpg'; // Hoặc đường dẫn ảnh mặc định trong disk
                    }
                    return $data;
                }),
                DeleteAction::make('Xóa')
                    ->visible(fn (Model $record) => self::editable($record))
            ])
        ];
    }
}