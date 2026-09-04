<?php

namespace Modules\Minihouse\App\Filament\Resources\ContractResource\Forms;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Modules\Minihouse\App\Models\Contract;

class ContractForm
{
    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Thông tin hợp đồng')
                ->columns(2)
                ->schema([
                    Select::make('room_id')
                        ->label('Phòng')
                        ->relationship('room', 'code')
                        ->searchable()
                        ->preload()
                        ->required(),
                    Select::make('tenant_id')
                        ->label('Khách thuê')
                        ->relationship('tenant', 'fullname')
                        ->searchable()
                        ->preload()
                        ->required(),
                    DatePicker::make('start_date')
                        ->label('Ngày bắt đầu')
                        ->required(),
                    DatePicker::make('end_date')
                        ->label('Ngày kết thúc'),
                    TextInput::make('monthly_price')
                        ->label('Giá thuê / tháng')
                        ->numeric()
                        ->required()
                        ->prefix('đ'),
                    TextInput::make('deposit_amount')
                        ->label('Tiền cọc')
                        ->numeric()
                        ->prefix('đ'),
                    Select::make('status')
                        ->label('Trạng thái')
                        ->options([
                            Contract::STATUS_ACTIVE    => 'Đang hiệu lực',
                            Contract::STATUS_EXPIRED   => 'Hết hạn',
                            Contract::STATUS_CANCELLED => 'Đã huỷ',
                        ])
                        ->default(Contract::STATUS_ACTIVE)
                        ->required(),
                ]),

            Section::make('Nội dung hợp đồng')
                ->schema([
                    RichEditor::make('contract_content')
                        ->label('Nội dung tuỳ chỉnh')
                        ->columnSpanFull(),
                ]),

            Section::make('Lưu trữ giấy tờ')
                ->columns(3)
                ->schema([
                    FileUpload::make('contract_file')
                        ->label('Hợp đồng (file)')
                        ->directory('minihouse/contracts')
                        ->disk('public')
                        ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png']),
                    FileUpload::make('handover_file')
                        ->label('Biên bản bàn giao')
                        ->directory('minihouse/contracts')
                        ->disk('public')
                        ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png']),
                    FileUpload::make('deposit_receipt_file')
                        ->label('Biên bản đặt cọc')
                        ->directory('minihouse/contracts')
                        ->disk('public')
                        ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png']),
                ]),
        ]);
    }
}
