<?php

namespace Modules\Minihouse\App\Filament\Resources\RoomResource\Pages;

use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Modules\Minihouse\App\Filament\Resources\RoomResource;

class EditRoom extends EditRecord
{
    protected static string $resource = RoomResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Room dùng SoftDeletes — xoá thẳng khi còn Hợp đồng (kể cả đã kết thúc) tham chiếu tới
            // phòng này sẽ để Contract.room_id trỏ về 1 Room đã "biến mất" (xem Room::booted() —
            // cùng exists()-check, chặn cả đường gọi delete() khác ngoài đây, ->before() ở đây chỉ để
            // hiện thông báo thân thiện).
            Actions\DeleteAction::make()
                ->before(function (Actions\DeleteAction $action) {
                    if ($this->record->contracts()->exists()) {
                        Notification::make()
                            ->title('Không thể xoá')
                            ->body('Phòng này vẫn còn Hợp đồng (kể cả đã kết thúc) tham chiếu tới — không thể xoá để giữ nguyên lịch sử hợp đồng/hoá đơn.')
                            ->danger()
                            ->send();

                        $action->halt();
                    }
                }),
        ];
    }
}
