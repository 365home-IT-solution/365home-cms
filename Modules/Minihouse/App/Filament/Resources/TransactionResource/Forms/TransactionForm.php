<?php

namespace Modules\Minihouse\App\Filament\Resources\TransactionResource\Forms;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Modules\Minihouse\App\Models\Transaction;

class TransactionForm
{
    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Thông tin thu chi')
                ->columns(2)
                ->schema([
                    Select::make('type')
                        ->label('Loại')
                        ->options([
                            Transaction::TYPE_IN  => 'Thu',
                            Transaction::TYPE_OUT => 'Chi',
                        ])
                        ->live()
                        ->required(),
                    Select::make('category')
                        ->label('Hạng mục')
                        ->options([
                            Transaction::CATEGORY_REPAIR    => 'Sửa chữa',
                            Transaction::CATEGORY_OPERATION => 'Vận hành',
                            Transaction::CATEGORY_OTHER     => 'Khác',
                        ])
                        ->visible(fn (Get $get) => $get('type') === Transaction::TYPE_OUT),
                    TextInput::make('amount')
                        ->label('Số tiền')
                        ->numeric()
                        ->required()
                        ->prefix('đ'),
                    DatePicker::make('transaction_date')
                        ->label('Ngày giao dịch')
                        ->required(),
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
                    Textarea::make('note')
                        ->label('Ghi chú')
                        ->columnSpanFull(),
                    FileUpload::make('receipt_image')
                        ->label('Ảnh biên lai / hoá đơn')
                        ->image()
                        ->imageEditor()
                        ->directory('minihouse/transactions')
                        ->disk('public')
                        ->visible(fn (Get $get) => $get('type') === Transaction::TYPE_OUT)
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
