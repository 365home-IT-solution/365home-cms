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
use Filament\Forms\Set;
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
                        ->live()
                        ->required(),
                    Select::make('category')
                        ->label('Hạng mục')
                        ->options([
                            Transaction::CATEGORY_REPAIR         => 'Sửa chữa',
                            Transaction::CATEGORY_OPERATION      => 'Vận hành',
                            Transaction::CATEGORY_DEPOSIT_REFUND => 'Hoàn cọc',
                            Transaction::CATEGORY_OTHER          => 'Khác',
                        ])
                        ->visible(fn (Get $get) => $get('type') === Transaction::TYPE_OUT),
                    TextInput::make('amount')
                        ->label('Số tiền')
                        ->numeric()
                        ->minValue(0.01)
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
                        ->preload()
                        ->live()
                        // Tự điền Toà nhà theo đúng phòng của hợp đồng — chỉ điền khi đang trống,
                        // không ghi đè nếu nhân viên đã tự chọn khác (giao dịch chung nhiều
                        // phòng/không đúng theo hợp đồng này).
                        ->afterStateUpdated(function (Get $get, Set $set, $state) {
                            if (blank($get('building_id'))) {
                                $set('building_id', Contract::find($state)?->room?->building_id);
                            }
                        }),
                    // Bắt buộc — đây là cột dùng để lọc thu chi theo toà nhà (xem
                    // ActiveBuildingScope/ScopedToActiveBuildingId) — không có toà nhà thì giao dịch
                    // sẽ bị ẩn khỏi mọi tài khoản bị giới hạn quản lý theo toà.
                    Select::make('building_id')
                        ->label('Toà nhà')
                        ->relationship('building', 'name')
                        ->searchable()
                        ->preload()
                        ->required()
                        ->helperText('Tự điền theo hợp đồng nếu có chọn — vẫn sửa được cho giao dịch chung của cả toà (sửa chữa, vận hành...) không gắn hợp đồng cụ thể.'),
                    Textarea::make('note')
                        ->label('Ghi chú')
                        ->columnSpanFull(),
                    FileUpload::make('receipt_image')
                        ->label('Ảnh biên lai / hoá đơn')
                        ->image()
                        ->imageEditor()
                        ->maxSize(5120)
                        ->directory('minihouse/transactions')
                        ->disk('public')
                        ->visible(fn (Get $get) => $get('type') === Transaction::TYPE_OUT)
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
