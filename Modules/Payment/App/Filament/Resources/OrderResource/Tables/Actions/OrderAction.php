<?php

namespace Modules\Payment\App\Filament\Resources\OrderResource\Tables\Actions;

use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Actions\DeleteAction;
use Modules\Payment\App\Filament\Resources\OrderResource\Forms\OrderForm;

class OrderAction
{
    public static function action()
    {
        return [
            AssignAccessCodeAction::make(),
            OpenGateAction::make(),
            ActionGroup::make([
                // ViewAction dùng chung OrderForm::form() nhưng KHÔNG đi qua EditRecord (không có
                // mutateFormDataBeforeFill()) — Repeater 'orderItems' không còn ->relationship('items')
                // nên phải tự đổ dữ liệu 'orderItems' vào đây, nếu không modal sẽ hiện trống dù
                // order_item vẫn còn nguyên trong DB (xem OrderForm::buildOrderItemsFormState()).
                ViewAction::make()
                    ->label('Xem chi tiết')
                    ->mutateRecordDataUsing(function (array $data, $record) {
                        $data['orderItems'] = OrderForm::buildOrderItemsFormState($record);

                        // Cùng lý do như EditOrder::mutateFormDataBeforeFill() — 'booking_partner_id'
                        // là field ảo không dehydrate, modal "Xem chi tiết" cũng phải tự điền lại
                        // để "Chi nhánh"/"Phòng" hiện đúng tên thay vì số ID thô.
                        $data['booking_partner_id'] = $record->partner_id;

                        return $data;
                    }),
                EditAction::make()->label('Cập nhật'),
                DeleteAction::make('Xóa')
            ])
        ];
    }
}