<?php

namespace Modules\Minihouse\App\Filament\Resources\BuildingResource\Pages;

use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Modules\Minihouse\App\Filament\Resources\BuildingResource;

class EditBuilding extends EditRecord
{
    protected static string $resource = BuildingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Building dùng SoftDeletes — xoá thẳng khi vẫn còn Phòng thuộc toà này sẽ để Room.
            // building_id trỏ về 1 Building đã "biến mất" (xem Building::booted() — cùng
            // exists()-check, chặn cả đường gọi delete() khác ngoài đây, ->before() ở đây chỉ để hiện
            // thông báo thân thiện thay vì lỗi chung chung).
            Actions\DeleteAction::make()
                ->before(function (Actions\DeleteAction $action) {
                    if ($this->record->rooms()->exists()) {
                        Notification::make()
                            ->title('Không thể xoá')
                            ->body('Toà nhà này vẫn còn Phòng thuộc về nó — hãy chuyển hoặc xoá các Phòng đó trước.')
                            ->danger()
                            ->send();

                        $action->halt();
                    }
                }),
        ];
    }
}
