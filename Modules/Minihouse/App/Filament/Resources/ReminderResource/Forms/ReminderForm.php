<?php

namespace Modules\Minihouse\App\Filament\Resources\ReminderResource\Forms;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Modules\Minihouse\App\Models\Reminder;

class ReminderForm
{
    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Thông tin nhắc việc')
                ->columns(2)
                ->schema([
                    TextInput::make('title')
                        ->label('Tiêu đề')
                        ->required()
                        ->maxLength(255),
                    Select::make('type')
                        ->label('Loại')
                        ->options([
                            Reminder::TYPE_PAYMENT     => 'Nhắc đóng tiền',
                            Reminder::TYPE_CONTRACT    => 'Nhắc hết hạn hợp đồng',
                            Reminder::TYPE_MAINTENANCE => 'Nhắc bảo trì',
                            Reminder::TYPE_OTHER       => 'Khác',
                        ])
                        ->default(Reminder::TYPE_OTHER)
                        ->required(),
                    DatePicker::make('remind_date')
                        ->label('Ngày nhắc')
                        ->required(),
                    Select::make('room_id')
                        ->label('Phòng liên quan')
                        ->relationship('room', 'code')
                        ->searchable()
                        ->preload(),
                    Select::make('contract_id')
                        ->label('Hợp đồng liên quan')
                        ->relationship(
                            'contract',
                            'id',
                            fn ($query) => $query->with(['room', 'tenant']),
                        )
                        ->getOptionLabelFromRecordUsing(fn ($record) => "{$record->room?->code} - {$record->tenant?->fullname}")
                        ->searchable()
                        ->preload(),
                    Toggle::make('is_done')
                        ->label('Đã xử lý'),
                    Textarea::make('content')
                        ->label('Nội dung')
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
