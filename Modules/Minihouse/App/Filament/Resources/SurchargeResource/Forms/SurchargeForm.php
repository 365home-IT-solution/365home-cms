<?php

namespace Modules\Minihouse\App\Filament\Resources\SurchargeResource\Forms;

use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;

class SurchargeForm
{
    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Thông tin phụ thu')
                ->columns(2)
                ->schema([
                    Select::make('building_id')
                        ->label('Toà nhà')
                        ->relationship('building', 'name')
                        ->searchable()
                        ->preload()
                        ->required(),
                    TextInput::make('name')
                        ->label('Tên phụ thu')
                        ->placeholder('Phí rác, gửi xe, internet, quản lý...')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('amount')
                        ->label('Số tiền mặc định')
                        ->numeric()
                        ->prefix('đ')
                        ->required(),
                    Toggle::make('is_active')
                        ->label('Đang áp dụng')
                        ->default(true)
                        ->helperText('Tắt nếu không muốn hiện phụ thu này khi lập hoá đơn nữa (không xoá, các hoá đơn cũ đã dùng vẫn giữ nguyên).'),
                    Textarea::make('note')
                        ->label('Ghi chú')
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
