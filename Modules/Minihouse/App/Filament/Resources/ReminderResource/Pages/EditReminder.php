<?php

namespace Modules\Minihouse\App\Filament\Resources\ReminderResource\Pages;

use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Modules\Minihouse\App\Filament\Resources\ReminderResource;
use Modules\Minihouse\App\Models\Reminder;
use Modules\Minihouse\App\Services\MinihouseZaloService;

class EditReminder extends EditRecord
{
    protected static string $resource = ReminderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Cùng logic với nút "Gửi Zalo" ở danh sách (ReminderTable) — đặt thêm ở đây để sửa
            // xong 1 nhắc việc là gửi lại luôn được ngay tại chỗ, không cần quay ra danh sách.
            Actions\Action::make('sendZaloNow')
                ->label('Gửi Zalo')
                ->icon('heroicon-o-chat-bubble-left-right')
                ->color('gray')
                ->visible(fn () => $this->record->type !== Reminder::TYPE_OTHER)
                ->requiresConfirmation()
                ->modalDescription('Gửi ngay tin nhắc việc này qua Zalo cho khách thuê liên quan — không cần chờ tới ngày nhắc, có thể gửi lại nhiều lần.')
                ->action(function () {
                    $result = app(MinihouseZaloService::class)->sendReminderNotification($this->record);

                    if ($result['success']) {
                        Notification::make()
                            ->title('Đã gửi Zalo thành công')
                            ->success()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title(($result['skipped'] ?? false) ? 'Chưa gửi được' : 'Gửi Zalo thất bại')
                        ->body($result['reason'] ?? $result['error'] ?? 'Lỗi không xác định.')
                        ->danger()
                        ->send();
                }),
            Actions\DeleteAction::make(),
        ];
    }
}
