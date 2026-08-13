<?php

declare(strict_types=1);

namespace Modules\Coupon\App\Filament\Resources\CouponResource\Forms;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Split;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Modules\Product\App\Models\Product;

class CouponForm
{
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Split::make([

                    // ── CỘT 1: Thông tin cơ bản + Giá trị giảm giá ──────────
                    Section::make('Thông tin mã giảm giá')
                        ->schema([
                            Grid::make(2)
                                ->schema([
                                    TextInput::make('code')
                                        ->label('Mã giảm giá')
                                        ->required()
                                        ->unique(ignoreRecord: true)
                                        ->maxLength(50)
                                        ->alphaNum(),

                                    TextInput::make('name')
                                        ->label('Tên mã giảm giá')
                                        ->required()
                                        ->maxLength(255),
                                ]),

                            Textarea::make('description')
                                ->label('Mô tả')
                                ->rows(3)
                                ->maxLength(500),

                            // 4 trường trên cùng 1 hàng
                            Grid::make(4)
                                ->schema([
                                    Select::make('type')
                                        ->label('Loại giảm giá')
                                        ->required()
                                        ->options([
                                            'percentage' => 'Phần trăm (%)',
                                            'fixed'      => 'Số tiền cố định (VNĐ)',
                                        ])
                                        ->default('percentage')
                                        ->live(),

                                    TextInput::make('value')
                                        ->label(fn (Get $get) => $get('type') === 'percentage' ? 'Giá trị (%)' : 'Giá trị (VNĐ)')
                                        ->required()
                                        ->numeric()
                                        ->minValue(0)
                                        ->maxValue(fn (Get $get) => $get('type') === 'percentage' ? 100 : null)
                                        ->suffix(fn (Get $get) => $get('type') === 'percentage' ? '%' : 'VNĐ'),

                                    TextInput::make('max_discount')
                                        ->label('Giảm tối đa (VNĐ)')
                                        ->numeric()
                                        ->minValue(0)
                                        ->suffix('VNĐ')
                                        ->placeholder(fn (Get $get) => $get('type') !== 'percentage' ? 'Chỉ áp dụng cho %' : null)
                                        ->disabled(fn (Get $get) => $get('type') !== 'percentage'),

                                    TextInput::make('min_order_value')
                                        ->label('Đơn hàng tối thiểu (VNĐ)')
                                        ->numeric()
                                        ->minValue(0)
                                        ->suffix('VNĐ'),
                                ]),
                        ])
                        ->grow(true),

                    // ── CỘT 2: Phạm vi áp dụng + Giới hạn & Thời gian ───────
                    Section::make('Phạm vi áp dụng & Thời gian')
                        ->schema([
                            Select::make('apply_type')
                                ->label('Áp dụng cho')
                                ->required()
                                ->options([
                                    'all_rooms'     => 'Tất cả khung giờ của tất cả phòng',
                                    'specific_room' => 'Tất cả khung giờ của 1 phòng cụ thể',
                                    'specific_slot' => 'Các khung giờ cụ thể',
                                ])
                                ->default('all_rooms')
                                ->live(),

                            Select::make('room_id')
                                ->label('Chọn phòng')
                                ->options(function () {
                                    $user  = auth()->user();
                                    $query = Product::where('is_activated', true)->orderBy('name');

                                    if ($user && ! $user->isSuperAdmin()) {
                                        $allowedIds = $user->allowedCategoryIds();
                                        if (! empty($allowedIds)) {
                                            $query->whereHas('categories', fn ($q) => $q->whereIn('categories.id', $allowedIds));
                                        }
                                        // Chưa gán quyền chi nhánh cụ thể thì không thu hẹp thêm —
                                        // Product đã tự lọc theo partner_id (BelongsToPartner).
                                    }

                                    return $query->pluck('name', 'id');
                                })
                                ->searchable()
                                ->preload()
                                ->required()
                                ->visible(fn (Get $get) => in_array($get('apply_type'), ['specific_room', 'specific_slot']))
                                ->live(),

                            Select::make('room_time_slot_ids')
                                ->label('Chọn khung giờ')
                                ->multiple()
                                ->searchable()
                                ->preload()
                                ->required()
                                ->relationship(
                                    name: 'roomTimeSlots',
                                    titleAttribute: 'id',
                                    modifyQueryUsing: fn ($query, Get $get) =>
                                        $query->where('room_id', $get('room_id'))
                                            ->with('timeSlot')
                                )
                                ->getOptionLabelFromRecordUsing(fn ($record) =>
                                    $record->timeSlot->label . ' - ' . number_format($record->price, 0, ',', '.') . ' VNĐ'
                                )
                                ->visible(fn (Get $get) => $get('apply_type') === 'specific_slot'),

                            Grid::make(2)
                                ->schema([
                                    TextInput::make('usage_limit')
                                        ->label('Giới hạn số lần sử dụng')
                                        ->numeric()
                                        ->minValue(1),

                                    TextInput::make('used_count')
                                        ->label('Đã sử dụng')
                                        ->numeric()
                                        ->default(0)
                                        ->disabled()
                                        ->dehydrated(false),
                                ]),

                            TextInput::make('validity_days')
                                ->label('Hiệu lực khi làm mẫu voucher hạng thành viên (ngày)')
                                ->numeric()
                                ->minValue(1)
                                ->maxValue(365)
                                ->suffix('ngày')
                                ->helperText('CHỈ có tác dụng khi mã này được gắn làm mẫu cho 1 Hạng thành viên (mục "Mã giảm giá gắn thêm cho hạng"). Khi đó mỗi khách lên hạng sẽ được cấp 1 bản sao riêng, hạn dùng = ngày lên hạng + số ngày này (thay vì mọi khách dùng chung hạn cố định ở trên).'),

                            Grid::make(2)
                                ->schema([
                                    DateTimePicker::make('start_at')
                                        ->label('Ngày bắt đầu')
                                        ->required()
                                        ->default(now())
                                        ->native(false)
                                        ->seconds(false)
                                        ->timezone('Asia/Ho_Chi_Minh')
                                        ->displayFormat('d/m/Y H:i'),

                                    DateTimePicker::make('end_at')
                                        ->label('Ngày kết thúc')
                                        ->after('start_at')
                                        ->native(false)
                                        ->seconds(false)
                                        ->timezone('Asia/Ho_Chi_Minh')
                                        ->displayFormat('d/m/Y H:i'),
                                ]),

                            Toggle::make('is_active')
                                ->label('Kích hoạt')
                                ->default(true),
                        ])
                        ->grow(false),

                ])
                ->from('lg')
                ->columnSpanFull(),
            ]);
    }
}
