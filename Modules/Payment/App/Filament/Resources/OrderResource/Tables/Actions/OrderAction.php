<?php

namespace Modules\Payment\App\Filament\Resources\OrderResource\Tables\Actions;

use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Actions\DeleteAction;
use Modules\Payment\App\Filament\Resources\OrderResource;

class OrderAction
{
    // $extraGroupActions — action bổ sung (vd AssignAccessCodeAction/OpenGateAction/
    // toggle_unlock_anytime ở OrderTable.php) gộp CHUNG vào đúng ActionGroup "..." này (theo yêu
    // cầu — không tạo thêm 1 icon/ActionGroup riêng nằm cạnh, tất cả action của 1 dòng phải nằm
    // trong CÙNG 1 dropdown "Xem chi tiết/Cập nhật/Xóa").
    public static function action(array $extraGroupActions = [])
    {
        return [
            ActionGroup::make([
                // Điều hướng thẳng vào trang chi tiết (EditOrder) thay vì mở modal — form đơn quá
                // lớn để hiển thị gọn trong popup.
                ViewAction::make()
                    ->label('Xem chi tiết')
                    ->url(fn ($record) => OrderResource::getUrl('edit', ['record' => $record])),
                EditAction::make()->label('Cập nhật'),
                DeleteAction::make('Xóa'),
                ...$extraGroupActions,
            ])
        ];
    }
}