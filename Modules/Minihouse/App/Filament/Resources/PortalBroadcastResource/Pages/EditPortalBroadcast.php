<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Filament\Resources\PortalBroadcastResource\Pages;

use Filament\Actions;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Pages\EditRecord;
use Modules\Minihouse\App\Filament\Resources\PortalBroadcastResource;
use Modules\Minihouse\App\Services\PortalBroadcastDispatchService;

// Mirror App\Filament\Resources\NotificationFcmResource\Pages\EditNotificationFcm (Home) — chỉ cho
// sửa nội dung & lịch gửi (danh sách người nhận đã cố định lúc tạo). EditAction ở
// PortalBroadcastTable đã tự ẩn khi bản ghi đã gửi (sent_at !== null), nên record tới đây luôn CHƯA
// gửi — nếu bỏ lịch (scheduled_at về trống/quá khứ) thì gửi luôn, cùng hành vi
// PushNotificationController::update() (API) đang làm.
class EditPortalBroadcast extends EditRecord
{
    protected static string $resource = PortalBroadcastResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()->label('Xoá'),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Nội dung thông báo')->schema([
                TextInput::make('title')->label('Tiêu đề')->required()->maxLength(255),
                Textarea::make('body')->label('Nội dung')->required()->rows(4)->maxLength(1000),
                TextInput::make('link')->label('Đường dẫn mở khi bấm vào')->maxLength(500),
            ]),

            Section::make('Lịch gửi')->schema([
                DateTimePicker::make('scheduled_at')
                    ->label('Gửi vào lúc')
                    ->helperText('Xoá trống để gửi ngay khi lưu.')
                    ->nullable()
                    ->seconds(false)
                    ->native(false)
                    ->displayFormat('d/m/Y H:i')
                    ->timezone(config('app.timezone', 'Asia/Ho_Chi_Minh')),
            ]),
        ]);
    }

    protected function afterSave(): void
    {
        $record = $this->getRecord();

        $scheduledAt = $record->scheduled_at;
        $isScheduled = $scheduledAt !== null && $scheduledAt->isFuture();

        if ($isScheduled || $record->sent_at !== null) {
            return;
        }

        $tenantIds = PortalBroadcastDispatchService::sourceTenantIds($record);
        PortalBroadcastDispatchService::dispatchNow($record, $tenantIds);
    }
}
