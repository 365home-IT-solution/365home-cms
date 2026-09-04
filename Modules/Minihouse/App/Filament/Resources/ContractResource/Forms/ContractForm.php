<?php

namespace Modules\Minihouse\App\Filament\Resources\ContractResource\Forms;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\Room;
use Modules\Minihouse\App\Models\Tenant;

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
                        ->options(fn () => Room::query()->pluck('code', 'id'))
                        ->searchable()
                        ->required(),
                    Select::make('tenant_id')
                        ->label('Khách thuê')
                        ->options(fn () => Tenant::query()->pluck('fullname', 'id'))
                        ->searchable()
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
        ]);
    }
}
