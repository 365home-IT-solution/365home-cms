<?php

declare(strict_types=1);

namespace Modules\Zns\App\Filament\Resources\ZnsNotificationResource\Forms;

use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Illuminate\Database\Eloquent\Model;

class ZnsNotificationForm
{
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Thông tin cơ bản')
                    ->schema([
                        Select::make('order_id')
                            ->label('Đơn phòng')
                            ->relationship('order', 'order_code', fn ($query) => $query->orderBy('order_code', 'desc'))
                            ->getOptionLabelFromRecordUsing(fn (Model $record): string => (string) ($record->order_code ?? ''))
                            ->getOptionLabelUsing(fn ($record): string => (string) ($record->order_code ?? ''))
                            ->searchable()
                            ->required()
                            ->preload(),
                        
                        Select::make('access_code_id')
                            ->label('Mã cổng')
                            ->relationship('accessCode', 'code')
                            ->getOptionLabelFromRecordUsing(fn (Model $record): string => (string) ($record->code ?? ''))
                            ->getOptionLabelUsing(fn ($record): string => (string) ($record->code ?? ''))
                            ->native(false)
                            ->nullable(),
                        
                        TextInput::make('phone_number')
                            ->label('Số điện thoại')
                            ->tel()
                            ->required()
                            ->maxLength(20),
                        
                        TextInput::make('recipient_name')
                            ->label('Tên người nhận')
                            ->maxLength(255),
                        
                        Select::make('notification_type')
                            ->label('Loại thông báo')
                            ->options([
                                'booking_success' => 'Đặt phòng thành công',
                                'booking_reminder' => 'Nhắc nhở',
                                'booking_cancelled' => 'Hủy đơn',
                            ])
                            ->required()
                            ->default('booking_success'),
                        
                        Select::make('status')
                            ->label('Trạng thái')
                            ->options([
                                'pending' => 'Chờ gửi',
                                'sent' => 'Đã gửi',
                                'delivered' => 'Đã nhận',
                                'read' => 'Đã đọc',
                                'failed' => 'Thất bại',
                            ])
                            ->required()
                            ->default('pending'),
                    ])
                    ->columns(2),
                
                Section::make('Template')
                    ->schema([
                        TextInput::make('template_id')
                            ->label('Template ID')
                            ->required()
                            ->maxLength(50),
                        
                        TextInput::make('template_name')
                            ->label('Tên template')
                            ->maxLength(255),
                        
                        KeyValue::make('template_data')
                            ->label('Dữ liệu template')
                            ->required()
                            ->columnSpanFull(),
                    ])->columns(2),
                
                Section::make('Ghi chú')
                    ->schema([
                        Textarea::make('admin_notes')
                            ->label('Ghi chú admin')
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->collapsible(),
            ]);
    }
}