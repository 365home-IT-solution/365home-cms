<?php

namespace Modules\Minihouse\App\Filament\Resources\RentalInquiryResource\Forms;

use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Form;
use Illuminate\Support\HtmlString;
use Modules\Minihouse\App\Models\RentalInquiry;

class RentalInquiryForm
{
    public static function form(Form $form): Form
    {
        return $form->schema([
            // Thông tin khách gửi — CHỈ ĐỌC, không cho sửa (đây là dữ liệu khách tự nhập từ trang
            // công khai, sửa lại sẽ sai lệch với thực tế họ đã gửi).
            Section::make('Thông tin khách quan tâm')
                ->schema([
                    Placeholder::make('full_name')
                        ->label('Họ tên')
                        ->content(fn (RentalInquiry $record) => $record->full_name),
                    Placeholder::make('phone')
                        ->label('Số điện thoại')
                        ->content(fn (RentalInquiry $record) => $record->phone),
                    Placeholder::make('email')
                        ->label('Email')
                        ->content(fn (RentalInquiry $record) => $record->email ?? '—'),
                    Placeholder::make('interest')
                        ->label('Quan tâm')
                        ->content(fn (RentalInquiry $record) => new HtmlString(
                            $record->room
                                ? 'Phòng ' . e($record->room->code) . ' — ' . e($record->room->building?->name ?? '—')
                                : ($record->building ? 'Toà nhà ' . e($record->building->name) : '—')
                        )),
                    Placeholder::make('preferred_move_in_date')
                        ->label('Ngày muốn vào ở')
                        ->content(fn (RentalInquiry $record) => $record->preferred_move_in_date?->format('d/m/Y') ?? '—'),
                    Placeholder::make('note')
                        ->label('Ghi chú của khách')
                        ->content(fn (RentalInquiry $record) => $record->note ?? '—')
                        ->columnSpanFull(),
                    Placeholder::make('created_at')
                        ->label('Thời điểm gửi')
                        ->content(fn (RentalInquiry $record) => $record->created_at?->format('d/m/Y H:i')),
                ])
                ->columns(2)
                ->compact(),

            // Phần nhân viên tự cập nhật khi xử lý.
            Section::make('Xử lý')
                ->schema([
                    Select::make('status')
                        ->label('Trạng thái')
                        ->options(RentalInquiry::STATUSES)
                        ->required(),
                    Textarea::make('staff_note')
                        ->label('Ghi chú nội bộ')
                        ->placeholder('VD: Đã gọi, khách hẹn xem phòng thứ 5...')
                        ->rows(3)
                        ->columnSpanFull(),
                ])
                ->columns(2)
                ->compact(),
        ]);
    }
}
