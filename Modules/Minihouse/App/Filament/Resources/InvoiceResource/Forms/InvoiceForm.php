<?php

namespace Modules\Minihouse\App\Filament\Resources\InvoiceResource\Forms;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Modules\Minihouse\App\Models\Invoice;

class InvoiceForm
{
    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Thông tin hoá đơn')
                ->columns(2)
                ->schema([
                    Select::make('contract_id')
                        ->label('Hợp đồng')
                        ->relationship(
                            'contract',
                            'id',
                            fn ($query) => $query->with(['room', 'tenant']),
                        )
                        ->getOptionLabelFromRecordUsing(fn ($record) => "{$record->room?->code} - {$record->tenant?->fullname}")
                        ->searchable()
                        ->preload()
                        ->required(),
                    DatePicker::make('month')
                        ->label('Tháng hoá đơn')
                        ->displayFormat('m/Y')
                        ->required(),
                    TextInput::make('room_price')
                        ->label('Tiền phòng')
                        ->numeric()
                        ->prefix('đ')
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Get $get, Set $set) => static::recalcTotal($get, $set)),
                    Select::make('status')
                        ->label('Trạng thái thanh toán')
                        ->options([
                            Invoice::STATUS_UNPAID => 'Chưa thanh toán',
                            Invoice::STATUS_PAID   => 'Đã thanh toán',
                        ])
                        ->default(Invoice::STATUS_UNPAID)
                        ->required(),
                ]),

            Section::make('Chỉ số điện')
                ->columns(3)
                ->schema([
                    TextInput::make('electric_start')
                        ->label('Số đầu kỳ')
                        ->numeric()
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Get $get, Set $set) => static::recalcElectric($get, $set)),
                    TextInput::make('electric_end')
                        ->label('Số cuối kỳ')
                        ->numeric()
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Get $get, Set $set) => static::recalcElectric($get, $set)),
                    TextInput::make('electric_unit_price')
                        ->label('Đơn giá / số')
                        ->numeric()
                        ->prefix('đ')
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Get $get, Set $set) => static::recalcElectric($get, $set)),
                    TextInput::make('electric_amount')
                        ->label('Thành tiền điện')
                        ->numeric()
                        ->prefix('đ')
                        ->readOnly()
                        ->columnSpanFull(),
                ]),

            Section::make('Chỉ số nước')
                ->columns(3)
                ->schema([
                    TextInput::make('water_start')
                        ->label('Số đầu kỳ')
                        ->numeric()
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Get $get, Set $set) => static::recalcWater($get, $set)),
                    TextInput::make('water_end')
                        ->label('Số cuối kỳ')
                        ->numeric()
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Get $get, Set $set) => static::recalcWater($get, $set)),
                    TextInput::make('water_unit_price')
                        ->label('Đơn giá / số')
                        ->numeric()
                        ->prefix('đ')
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Get $get, Set $set) => static::recalcWater($get, $set)),
                    TextInput::make('water_amount')
                        ->label('Thành tiền nước')
                        ->numeric()
                        ->prefix('đ')
                        ->readOnly()
                        ->columnSpanFull(),
                ]),

            Section::make('Tổng cộng')
                ->columns(2)
                ->schema([
                    TextInput::make('service_amount')
                        ->label('Phí dịch vụ khác')
                        ->numeric()
                        ->prefix('đ')
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Get $get, Set $set) => static::recalcTotal($get, $set)),
                    TextInput::make('total_amount')
                        ->label('Tổng tiền')
                        ->numeric()
                        ->prefix('đ')
                        ->required()
                        ->helperText('Tự động cộng Tiền phòng + Điện + Nước + Dịch vụ khác — có thể sửa tay nếu cần.'),
                ]),
        ]);
    }

    private static function recalcElectric(Get $get, Set $set): void
    {
        $amount = (max(0, (float) $get('electric_end') - (float) $get('electric_start'))) * (float) $get('electric_unit_price');
        $set('electric_amount', round($amount, 2));
        static::recalcTotal($get, $set);
    }

    private static function recalcWater(Get $get, Set $set): void
    {
        $amount = (max(0, (float) $get('water_end') - (float) $get('water_start'))) * (float) $get('water_unit_price');
        $set('water_amount', round($amount, 2));
        static::recalcTotal($get, $set);
    }

    private static function recalcTotal(Get $get, Set $set): void
    {
        $total = (float) $get('room_price')
            + (float) $get('electric_amount')
            + (float) $get('water_amount')
            + (float) $get('service_amount');

        $set('total_amount', round($total, 2));
    }
}
