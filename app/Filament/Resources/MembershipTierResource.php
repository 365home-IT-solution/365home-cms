<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\MembershipTierResource\Pages;
use App\Models\MembershipTier;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MembershipTierResource extends Resource
{
    protected static ?string $model = MembershipTier::class;

    protected static ?string $navigationIcon   = 'heroicon-o-trophy';
    protected static ?string $navigationGroup  = 'Phân quyền';
    protected static ?string $navigationLabel  = 'Hạng thành viên';
    protected static ?string $modelLabel       = 'Hạng thành viên';
    protected static ?string $pluralModelLabel = 'Hạng thành viên';
    protected static ?int    $navigationSort   = 25;

    public static function canViewAny(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Thông tin hạng')->schema([
                Grid::make(2)->schema([
                    TextInput::make('name')
                        ->label('Tên hạng')
                        ->required()
                        ->maxLength(100),

                    TextInput::make('slug')
                        ->label('Slug (mã định danh)')
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(50)
                        ->helperText('Ví dụ: bronze, silver, gold, diamond'),
                ]),

                Textarea::make('description')
                    ->label('Mô tả')
                    ->rows(2)
                    ->maxLength(500),

                Grid::make(3)->schema([
                    ColorPicker::make('color')
                        ->label('Màu hiển thị')
                        ->default('#6B7280'),

                    TextInput::make('icon')
                        ->label('Icon (heroicon)')
                        ->default('heroicon-o-star')
                        ->maxLength(100)
                        ->helperText('Ví dụ: heroicon-o-trophy'),

                    TextInput::make('sort_order')
                        ->label('Thứ tự hiển thị')
                        ->numeric()
                        ->default(0),
                ]),

                TextInput::make('min_spending')
                    ->label('Tổng chi tiêu tối thiểu (VNĐ)')
                    ->numeric()
                    ->default(0)
                    ->suffix('VNĐ')
                    ->helperText('Đặt 0 = hạng mặc định khi đăng ký'),

                Toggle::make('is_active')
                    ->label('Kích hoạt')
                    ->default(true),
            ])->columnSpan(1),

            Section::make('Coupon tự động cấp')->schema([
                TextInput::make('welcome_coupon_prefix')
                    ->label('Tiền tố mã coupon')
                    ->maxLength(20)
                    ->placeholder('VD: SILVER, GOLD')
                    ->helperText('Coupon sẽ được tạo: SILVER + 6 ký tự ngẫu nhiên'),

                Grid::make(2)->schema([
                    Select::make('welcome_coupon_type')
                        ->label('Loại giảm giá')
                        ->options([
                            'percentage' => 'Phần trăm (%)',
                            'fixed'      => 'Số tiền cố định (VNĐ)',
                        ])
                        ->default('percentage')
                        ->live(),

                    TextInput::make('welcome_coupon_value')
                        ->label(fn (Get $get) => $get('welcome_coupon_type') === 'percentage' ? 'Giá trị (%)' : 'Giá trị (VNĐ)')
                        ->numeric()
                        ->default(0)
                        ->minValue(0)
                        ->maxValue(fn (Get $get) => $get('welcome_coupon_type') === 'percentage' ? 100 : null)
                        ->suffix(fn (Get $get) => $get('welcome_coupon_type') === 'percentage' ? '%' : 'VNĐ'),
                ]),

                TextInput::make('welcome_coupon_days')
                    ->label('Hiệu lực coupon (ngày)')
                    ->numeric()
                    ->default(30)
                    ->suffix('ngày')
                    ->helperText('Tính từ thời điểm cấp. Đặt 0 để không hết hạn.'),
            ])->columnSpan(1),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ColorColumn::make('color')
                    ->label('Màu')
                    ->copyable(false),

                TextColumn::make('name')
                    ->label('Tên hạng')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('min_spending')
                    ->label('Chi tiêu tối thiểu')
                    ->money('VND')
                    ->sortable(),

                TextColumn::make('welcome_coupon_value')
                    ->label('Coupon tặng')
                    ->formatStateUsing(function ($state, MembershipTier $record): string {
                        if (! $state || $state <= 0) {
                            return '—';
                        }
                        return $record->welcome_coupon_type === 'percentage'
                            ? $state . '%'
                            : number_format((float) $state, 0, ',', '.') . ' VNĐ';
                    }),

                TextColumn::make('welcome_coupon_days')
                    ->label('Hiệu lực')
                    ->formatStateUsing(fn ($state) => $state ? $state . ' ngày' : 'Không giới hạn'),

                TextColumn::make('customers_count')
                    ->label('Thành viên')
                    ->counts('customers')
                    ->sortable(),

                IconColumn::make('is_active')
                    ->label('Kích hoạt')
                    ->boolean(),

                TextColumn::make('sort_order')
                    ->label('Thứ tự')
                    ->sortable(),
            ])
            ->defaultSort('sort_order')
            ->actions([
                EditAction::make()->label('Sửa'),
                DeleteAction::make()->label('Xoá'),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListMembershipTiers::route('/'),
            'create' => Pages\CreateMembershipTier::route('/create'),
            'edit'   => Pages\EditMembershipTier::route('/{record}/edit'),
        ];
    }
}
