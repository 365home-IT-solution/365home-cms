<?php

namespace Modules\Minihouse\App\Filament\Resources\TenantFeedbackResource\Forms;

use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Modules\Minihouse\App\Models\TenantFeedback;

class TenantFeedbackForm
{
    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Nội dung khách gửi')
                ->description('Khách thuê tự gửi — nội dung không sửa được ở đây, chỉ có thể gỡ hoặc bổ sung ảnh đính kèm.')
                ->columns(2)
                ->schema([
                    Placeholder::make('room_view')
                        ->label('Phòng')
                        ->content(fn (TenantFeedback $record) => $record->room?->code ?? 'Không gắn phòng cụ thể'),
                    Placeholder::make('rating_view')
                        ->label('Đánh giá')
                        ->content(fn (TenantFeedback $record) => str_repeat('★', $record->rating) . str_repeat('☆', 5 - $record->rating)),
                    Placeholder::make('tenant_name_view')
                        ->label('Họ tên khách')
                        ->content(fn (TenantFeedback $record) => $record->tenant_name ?: 'Không ghi'),
                    Placeholder::make('tenant_phone_view')
                        ->label('Số điện thoại')
                        ->content(fn (TenantFeedback $record) => $record->tenant_phone ?: 'Không ghi'),
                    Placeholder::make('content_view')
                        ->label('Nội dung góp ý')
                        ->columnSpanFull()
                        ->content(fn (TenantFeedback $record) => $record->content ?: '(Không có nội dung)'),
                    SpatieMediaLibraryFileUpload::make('images')
                        ->label('Ảnh đính kèm')
                        ->collection(TenantFeedback::IMAGE_COLLECTION)
                        ->conversion('thumb')
                        ->multiple()
                        ->reorderable()
                        ->image()
                        ->openable()
                        ->maxFiles(TenantFeedback::MAX_IMAGES)
                        ->maxSize(TenantFeedback::MAX_IMAGE_KB)
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                        ->helperText('Tối đa ' . TenantFeedback::MAX_IMAGES . ' ảnh, mỗi ảnh ≤ ' . (TenantFeedback::MAX_IMAGE_KB / 1024) . 'MB.')
                        ->columnSpanFull(),
                ]),

            Section::make('Xử lý nội bộ')
                ->columns(1)
                ->schema([
                    Toggle::make('is_reviewed')
                        ->label('Đã xử lý'),
                    Textarea::make('staff_note')
                        ->label('Ghi chú nội bộ')
                        ->rows(3),
                ]),
        ]);
    }
}
