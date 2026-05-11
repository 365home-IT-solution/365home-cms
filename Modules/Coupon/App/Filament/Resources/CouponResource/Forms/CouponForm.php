<?php

declare(strict_types=1);

namespace Modules\Coupon\App\Filament\Resources\CouponResource\Forms;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Grid;
use Filament\Forms\Get;
use Filament\Forms\Components\TextInput;
use Modules\Product\App\Models\Product;

class CouponForm
{
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Thông tin cơ bản')
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
                    ]),

                Section::make('Giá trị giảm giá')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                Select::make('type')
                                    ->label('Loại giảm giá')
                                    ->required()
                                    ->options([
                                        'percentage' => 'Phần trăm (%)',
                                        'fixed' => 'Số tiền cố định (VNĐ)',
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
                                    ->visible(fn (Get $get) => $get('type') === 'percentage'),
                            ]),

                        TextInput::make('min_order_value')
                            ->label('Giá trị đơn hàng tối thiểu (VNĐ)')
                            ->numeric()
                            ->minValue(0)
                            ->suffix('VNĐ'),
                    ]),

                Section::make('Phạm vi áp dụng')
                    ->schema([
                        Select::make('apply_type')
                            ->label('Áp dụng cho')
                            ->required()
                            ->options([
                                'all_rooms' => '🌐 Tất cả khung giờ của tất cả phòng',
                                'specific_room' => '🏠 Tất cả khung giờ của 1 phòng cụ thể',
                                'specific_slot' => '🎯 Các khung giờ cụ thể',
                            ])
                            ->default('all_rooms')
                            ->live(),

                        Select::make('room_id')
                            ->label('Chọn phòng')
                            ->options(Product::where('is_activated', true)->pluck('name', 'id'))
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
                    ]),

                Section::make('Giới hạn sử dụng & Thời gian')
                    ->schema([
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

                        Grid::make(2)
                            ->schema([
                                DateTimePicker::make('start_at')
                                    ->label('Ngày bắt đầu')
                                    ->required()
                                    ->default(now())
                                    ->seconds(false),

                                DateTimePicker::make('end_at')
                                    ->label('Ngày kết thúc')
                                    ->after('start_at')
                                    ->seconds(false),
                            ]),

                        Toggle::make('is_active')
                            ->label('Kích hoạt')
                            ->default(true),
                    ]),
            ]);
    }
}