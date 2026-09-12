<?php

namespace Modules\Minihouse\App\Filament\Resources\ZoneResource\Pages;

use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Modules\Minihouse\App\Filament\Resources\ZoneResource;

class EditZone extends EditRecord
{
    protected static string $resource = ZoneResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Zone dùng SoftDeletes — xoá thẳng khi vẫn còn Toà nhà thuộc khu vực này sẽ để Building.
            // zone_id trỏ về 1 Zone đã "biến mất" (xem Zone::booted() — cùng exists()-check, chặn cả
            // đường gọi delete() khác ngoài đây, ->before() ở đây chỉ để hiện thông báo thân thiện).
            Actions\DeleteAction::make()
                ->before(function (Actions\DeleteAction $action) {
                    if ($this->record->buildings()->exists()) {
                        Notification::make()
                            ->title('Không thể xoá')
                            ->body('Khu vực này vẫn còn Toà nhà thuộc về nó — hãy chuyển hoặc xoá các Toà nhà đó trước.')
                            ->danger()
                            ->send();

                        $action->halt();
                    }
                }),
        ];
    }
}
