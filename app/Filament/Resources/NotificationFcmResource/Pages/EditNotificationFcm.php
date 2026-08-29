<?php

namespace App\Filament\Resources\NotificationFcmResource\Pages;

use App\Filament\Resources\NotificationFcmResource;
use Filament\Actions;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Pages\EditRecord;

class EditNotificationFcm extends EditRecord
{
    protected static string $resource = NotificationFcmResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()->label('Xóa'),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }

    // Chỉ cho sửa nội dung & lịch gửi — không cho chọn lại người nhận (khác form tạo mới),
    // vì recipient_ids/customer_ids chỉ có ý nghĩa lúc tạo record, sửa lỗi chính tả không nên
    // bắt admin chọn lại người nhận.
    public function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Nội dung thông báo')->schema([
                TextInput::make('title')
                    ->label('Tiêu đề')
                    ->required()
                    ->maxLength(255),

                Textarea::make('body')
                    ->label('Nội dung')
                    ->required()
                    ->rows(4)
                    ->maxLength(1000),

                TextInput::make('url')
                    ->label('URL điều hướng')
                    ->helperText('Đường dẫn app/website sẽ mở khi khách bấm vào thông báo (không bắt buộc).')
                    ->maxLength(500),
            ]),

            Section::make('Lịch gửi')->schema([
                DateTimePicker::make('scheduled_at')
                    ->label('Gửi vào lúc')
                    ->helperText('Chỉ có tác dụng nếu thông báo chưa được gửi (đang ở trạng thái "Đã lên lịch"). Thông báo đã gửi rồi sửa mục này sẽ không gửi lại tự động — dùng nút "Gửi lại" ở danh sách.')
                    ->nullable()
                    ->seconds(false)
                    ->native(false)
                    ->displayFormat('d/m/Y H:i')
                    ->timezone(config('app.timezone', 'Asia/Ho_Chi_Minh')),
            ]),
        ]);
    }
}
