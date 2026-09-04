<?php

namespace Modules\Minihouse\App\Filament\Resources\InvoiceResource\Forms;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Modules\Minihouse\App\Models\Contract;
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
                        ->options(fn () => Contract::query()->with(['room', 'tenant'])->get()
                            ->mapWithKeys(fn (Contract $c) => [$c->id => "{$c->room?->code} - {$c->tenant?->fullname}"]))
                        ->searchable()
                        ->required(),
                    DatePicker::make('month')
                        ->label('Tháng hoá đơn')
                        ->required(),
                    TextInput::make('electric_amount')
                        ->label('Tiền điện')
                        ->numeric()
                        ->prefix('đ'),
                    TextInput::make('water_amount')
                        ->label('Tiền nước')
                        ->numeric()
                        ->prefix('đ'),
                    TextInput::make('service_amount')
                        ->label('Phí dịch vụ khác')
                        ->numeric()
                        ->prefix('đ'),
                    TextInput::make('total_amount')
                        ->label('Tổng tiền')
                        ->numeric()
                        ->required()
                        ->prefix('đ'),
                    Select::make('status')
                        ->label('Trạng thái')
                        ->options([
                            Invoice::STATUS_UNPAID => 'Chưa thanh toán',
                            Invoice::STATUS_PAID   => 'Đã thanh toán',
                        ])
                        ->default(Invoice::STATUS_UNPAID)
                        ->required(),
                ]),
        ]);
    }
}
