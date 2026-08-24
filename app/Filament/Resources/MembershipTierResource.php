<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\MembershipTierResource\Pages;
use App\Models\MembershipTier;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Group;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
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
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Modules\Promotion\App\Models\Coupon;

class MembershipTierResource extends Resource
{
    protected static ?string $model = MembershipTier::class;

    protected static ?string $navigationIcon   = 'heroicon-o-trophy';
    protected static ?string $navigationGroup  = 'Phân quyền';
    protected static ?string $navigationLabel  = 'Hạng thành viên';
    protected static ?string $modelLabel       = 'Hạng thành viên';
    protected static ?string $pluralModelLabel = 'Hạng thành viên';
    protected static ?int    $navigationSort   = 25;

    // Trước đây hardcode isSuperAdmin() — bỏ qua MembershipTierPolicy (đã đúng, kiểm tra
    // view_any_membership::tier), khiến tick/bỏ tick quyền này ở Roles & Permissions vô tác dụng.
    public static function canViewAny(): bool
    {
        return auth()->user()?->can('view_any_membership::tier') ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Group::make([
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

                FileUpload::make('image')
                    ->label('Ảnh đại diện hạng')
                    ->image()
                    ->disk('public')
                    ->directory('membership')
                    ->maxSize(10240)
                    ->imagePreviewHeight('150')
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/jpg', 'image/webp'])
                    ->helperText('Hiển thị làm thẻ/huy hiệu của hạng trên trang tài khoản khách hàng. Tối đa 10MB.'),

                TextInput::make('min_spending')
                    ->label('Tổng chi tiêu tối thiểu (VNĐ)')
                    ->numeric()
                    ->default(0)
                    ->suffix('VNĐ')
                    ->helperText('Đặt 0 = hạng mặc định khi đăng ký'),

                Toggle::make('is_active')
                    ->label('Kích hoạt')
                    ->default(true),
            ])->columnSpanFull(),

            Section::make('Mã giảm giá gắn thêm cho hạng')->schema([
                Select::make('manual_coupon_ids')
                    ->label('Mã giảm giá')
                    ->options(fn () => Coupon::query()
                        ->orderByDesc('created_at')
                        ->limit(200)
                        ->get()
                        ->mapWithKeys(fn (Coupon $c) => [$c->id => $c->code . ' — ' . $c->name]))
                    ->getSearchResultsUsing(fn (string $search) => Coupon::query()
                        ->where(fn ($q) => $q->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"))
                        ->limit(50)
                        ->get()
                        ->mapWithKeys(fn (Coupon $c) => [$c->id => $c->code . ' — ' . $c->name]))
                    ->getOptionLabelsUsing(fn (array $values) => Coupon::whereIn('id', $values)
                        ->get()
                        ->mapWithKeys(fn (Coupon $c) => [$c->id => $c->code . ' — ' . $c->name]))
                    ->multiple()
                    ->searchable()
                    ->helperText('Gắn tay 1 hoặc nhiều mã có sẵn (tạo ở mục "Mã giảm giá") cho TRƯỜNG HỢP NGOẠI LỆ/cứu cháy — KHÔNG dùng cho voucher chính thức của hạng (đã cấu hình ở "Coupon tự động cấp" phía dưới). Toàn bộ khách đang giữ hạng này sẽ được phát các mã đã chọn.'),
            ])->columnSpanFull(),

            Section::make('Tự động tạo mã khuyến mãi định kỳ')->schema([
                Toggle::make('auto_issue_enabled')
                    ->label('Bật thưởng đăng nhập định kỳ')
                    ->helperText('Mỗi khi khách đăng nhập vào app, nếu đã đủ chu kỳ (xem "Lặp lại mỗi") kể từ lần được tặng gần nhất, hệ thống tự tạo 1 mã khuyến mãi riêng cho khách đó và gửi thông báo ngay lúc đăng nhập — không chạy theo giờ cố định, không cấp hàng loạt cho cả hạng cùng lúc.')
                    ->live()
                    ->default(false),

                TextInput::make('auto_issue_interval_weeks')
                    ->label('Lặp lại mỗi (tuần)')
                    ->numeric()
                    ->minValue(1)
                    ->default(1)
                    ->suffix('tuần')
                    ->helperText('Khách chỉ được tặng lại sau đúng N tuần kể từ lần được tặng gần nhất (tính từ lúc đăng nhập, không phải theo lịch cố định). VD: 1 = mỗi tuần, 2 = 2 tuần/lần.')
                    ->required(fn (Get $get) => $get('auto_issue_enabled'))
                    ->visible(fn (Get $get) => $get('auto_issue_enabled')),

                Select::make('auto_issue_coupon_type')
                    ->label('Loại giảm giá')
                    ->options([
                        'percentage' => 'Phần trăm (%)',
                        'fixed'      => 'Số tiền cố định (VNĐ)',
                    ])
                    ->default('fixed')
                    ->live()
                    ->visible(fn (Get $get) => $get('auto_issue_enabled')),

                Grid::make(3)->schema([
                    TextInput::make('auto_issue_coupon_value')
                        ->label(fn (Get $get) => $get('auto_issue_coupon_type') === 'percentage' ? 'Giá trị tối thiểu / mặc định (%)' : 'Giá trị tối thiểu / mặc định (VNĐ)')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(fn (Get $get) => $get('auto_issue_coupon_type') === 'percentage' ? 100 : null)
                        ->required(fn (Get $get) => $get('auto_issue_enabled'))
                        ->visible(fn (Get $get) => $get('auto_issue_enabled')),

                    TextInput::make('auto_issue_coupon_value_max')
                        ->label(fn (Get $get) => $get('auto_issue_coupon_type') === 'percentage' ? 'Giá trị tối đa (%)' : 'Giá trị tối đa (VNĐ)')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(fn (Get $get) => $get('auto_issue_coupon_type') === 'percentage' ? 100 : null)
                        ->helperText('Để trống = luôn cấp đúng giá trị tối thiểu. Có nhập thì mỗi lần cấp sẽ random 1 giá trị trong khoảng.')
                        ->visible(fn (Get $get) => $get('auto_issue_enabled')),

                    TextInput::make('auto_issue_coupon_days')
                        ->label('Hiệu lực mã (ngày)')
                        ->numeric()
                        ->minValue(1)
                        ->default(7)
                        ->suffix('ngày')
                        ->helperText('Tính từ lúc cấp.')
                        ->visible(fn (Get $get) => $get('auto_issue_enabled')),
                ]),

                TextInput::make('auto_issue_coupon_usage_limit')
                    ->label('Số lần sử dụng')
                    ->numeric()
                    ->minValue(1)
                    ->placeholder('Không giới hạn')
                    ->visible(fn (Get $get) => $get('auto_issue_enabled')),

                TextInput::make('auto_issue_notify_title')
                    ->label('Tiêu đề thông báo')
                    ->maxLength(255)
                    ->placeholder('Ưu đãi dành cho hạng ' . '{tên hạng}')
                    ->helperText('Để trống sẽ dùng tiêu đề mặc định.')
                    ->visible(fn (Get $get) => $get('auto_issue_enabled')),

                Textarea::make('auto_issue_notify_body')
                    ->label('Nội dung thông báo')
                    ->rows(2)
                    ->maxLength(500)
                    ->helperText('Để trống sẽ dùng nội dung mặc định.')
                    ->visible(fn (Get $get) => $get('auto_issue_enabled')),
            ])->columnSpanFull(),
            ])->columnSpan(1),

            Group::make([
            Section::make('Coupon tự động cấp')->schema([
                Repeater::make('voucher_templates')
                    ->label('')
                    ->addActionLabel('+ Thêm voucher')
                    ->helperText('Voucher chính thức phát cho MỌI khách khi lên hạng này. Mỗi dòng = 1 voucher riêng biệt — cần 2 voucher 20K thì thêm 2 dòng giá trị 20.000. Toàn bộ khách đang giữ hạng này — kể cả khách đã lên hạng từ trước — sẽ được phát ngay các voucher vừa thêm khi lưu.')
                    ->schema([
                        Hidden::make('template_id'),

                        Grid::make(3)->schema([
                            TextInput::make('prefix')
                                ->label('Tiền tố mã coupon')
                                ->required()
                                ->maxLength(20)
                                ->placeholder('VD: BAC20, VANG10')
                                ->helperText('Coupon cấp cho khách sẽ là: TIỀN TỐ + 6 ký tự ngẫu nhiên.'),

                            Select::make('type')
                                ->label('Loại giảm giá')
                                ->options([
                                    'percentage' => 'Phần trăm (%)',
                                    'fixed'      => 'Số tiền cố định (VNĐ)',
                                ])
                                ->default('fixed')
                                ->required()
                                ->live(),

                            TextInput::make('value')
                                ->label(fn (Get $get) => $get('type') === 'percentage' ? 'Giá trị (%)' : 'Giá trị (VNĐ)')
                                ->required()
                                ->numeric()
                                ->minValue(0)
                                ->maxValue(fn (Get $get) => $get('type') === 'percentage' ? 100 : null)
                                ->suffix(fn (Get $get) => $get('type') === 'percentage' ? '%' : 'VNĐ'),
                        ]),

                        Grid::make(3)->schema([
                            TextInput::make('min_order_value')
                                ->label('Đơn hàng tối thiểu (VNĐ)')
                                ->numeric()
                                ->minValue(0)
                                ->suffix('VNĐ')
                                ->helperText('Để trống = không giới hạn.'),

                            TextInput::make('validity_days')
                                ->label('Hiệu lực coupon (ngày)')
                                ->numeric()
                                ->minValue(1)
                                ->default(30)
                                ->required()
                                ->suffix('ngày')
                                ->helperText('Tính từ thời điểm cấp cho từng khách.'),

                            TextInput::make('usage_limit')
                                ->label('Số lần sử dụng')
                                ->numeric()
                                ->minValue(1)
                                ->placeholder('Không giới hạn')
                                ->helperText('Để trống = không giới hạn.'),
                        ]),

                        Toggle::make('is_exclusive')
                            ->label('Mã độc quyền (không dùng chung đơn với mã khác)')
                            ->default(true)
                            ->helperText('Đúng quy định "1 đơn chỉ áp dụng 1 voucher" — nên để bật.'),

                        Select::make('category_ids')
                            ->label('Áp dụng cho chi nhánh')
                            ->multiple()
                            ->searchable()
                            ->options(fn () => \Modules\Category\Entities\Category::query()
                                ->whereNull('parent_id')
                                ->where('category_type', 'product')
                                ->orderBy('name')
                                ->pluck('name', 'id'))
                            ->helperText('Để trống = áp dụng ở TẤT CẢ chi nhánh. Chọn 1 hoặc nhiều chi nhánh để voucher chỉ dùng được ở đó (gồm cả khu vực con của chi nhánh đã chọn).'),
                    ])
                    ->itemLabel(fn (array $state): ?string => trim(
                        ($state['prefix'] ?? '') . ' — ' .
                        number_format((float) ($state['value'] ?? 0), 0, ',', '.') .
                        ($state['type'] === 'percentage' ? '%' : 'đ')
                    ))
                    ->collapsible()
                    ->reorderable(false)
                    ->defaultItems(0),
            ])->columnSpanFull(),
            ])->columnSpan(1),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('coupons'))
            ->columns([
                ImageColumn::make('image')
                    ->label('Ảnh')
                    ->disk('public')
                    ->circular(),

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

                TextColumn::make('auto_issue_schedule')
                    ->label('Thưởng đăng nhập định kỳ')
                    ->state(function (MembershipTier $record): string {
                        if (! $record->auto_issue_enabled || ! $record->auto_issue_coupon_value || $record->auto_issue_coupon_value <= 0) {
                            return '—';
                        }

                        $unit  = $record->auto_issue_coupon_type === 'percentage' ? '%' : 'đ';
                        $min   = number_format((float) $record->auto_issue_coupon_value, 0, ',', '.');
                        $value = $record->auto_issue_coupon_value_max && $record->auto_issue_coupon_value_max > $record->auto_issue_coupon_value
                            ? $min . '–' . number_format((float) $record->auto_issue_coupon_value_max, 0, ',', '.')
                            : $min;

                        return "Mỗi {$record->auto_issue_interval_weeks} tuần ({$value}{$unit})";
                    })
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('auto_voucher_count')
                    ->label('Coupon tự động cấp')
                    ->state(function (MembershipTier $record): string {
                        $count = $record->coupons->where('pivot.source', 'auto')->count();

                        return $count > 0 ? "{$count} voucher" : '—';
                    }),

                TextColumn::make('manual_coupon_count')
                    ->label('Gắn tay (cứu cháy)')
                    ->state(function (MembershipTier $record): string {
                        $count = $record->coupons->where('pivot.source', 'manual')->count();

                        return $count > 0 ? "{$count} mã" : '—';
                    })
                    ->toggleable(isToggledHiddenByDefault: true),

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

    /**
     * Logic đọc/ghi voucher mẫu ('auto') và mã gắn tay ('manual') giờ dùng chung ở
     * App\Services\MembershipService (voucherTemplatesToFormState / syncVoucherTemplates /
     * manualCouponIdsToFormState / syncManualCoupons) — để REST API admin (Api\Admin\
     * MembershipTierController) dùng lại được, không lặp code riêng cho Filament. Xem
     * MembershipTierResource\Pages\CreateMembershipTier / EditMembershipTier.
     */
}
