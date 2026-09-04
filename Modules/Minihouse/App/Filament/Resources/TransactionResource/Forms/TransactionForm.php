<?php

namespace Modules\Minihouse\App\Filament\Resources\TransactionResource\Forms;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Form;
use Modules\Minihouse\App\Models\Contract;
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
                        ->required(),
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
                        ->options(fn () => Contract::query()->with(['room', 'tenant'])->get()
                            ->mapWithKeys(fn (Contract $c) => [$c->id => "{$c->room?->code} - {$c->tenant?->fullname}"]))
                        ->searchable(),
                    Textarea::make('note')
                        ->label('Ghi chú')
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
