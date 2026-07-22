<?php

declare(strict_types=1);

namespace Modules\User\App\Filament\Resources\UserResource\Tables\Actions;

use App\Models\User;
use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Actions\DeleteAction;
use Modules\User\App\Filament\Resources\UserResource\Forms\UserForm;

class UserAction
{
    public static function action()
    {
        return [
            ActionGroup::make([
                // Popup "Xem chi tiết" dùng lại đúng UserForm::form() (form Sửa) nhưng chỉ
                // fill từ $record->toArray() theo mặc định của Filament — không tự lấy field
                // của Employee liên kết (mã NV, lương, chi nhánh...) vì đó là model khác. Cần
                // ->mutateRecordDataUsing() để nạp thêm, giống hệt EditUser::mutateFormDataBeforeFill.
                ViewAction::make()
                    ->label('Xem chi tiết')
                    ->mutateRecordDataUsing(fn (array $data, User $record): array => UserForm::fillEmployeeData($record, $data)),
                EditAction::make()->label('Cập nhật'),
                DeleteAction::make('Xóa')
            ])
        ];
    }
}
