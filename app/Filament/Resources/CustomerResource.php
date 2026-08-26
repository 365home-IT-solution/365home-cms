<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\CustomerResource\Pages;
use App\Filament\Resources\CustomerResource\RelationManagers\AssignedCouponsRelationManager;
use App\Filament\Resources\CustomerResource\RelationManagers\MembershipLogsRelationManager;
use App\Filament\Resources\CustomerResource\RelationManagers\PersonalCouponsRelationManager;
use App\Models\Customer;
use App\Models\MembershipTier;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ForceDeleteAction;
use Filament\Tables\Actions\RestoreAction;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\HtmlString;
use Modules\Promotion\App\Models\Coupon;

class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static ?string $navigationIcon   = 'heroicon-o-users';
    protected static ?string $navigationGroup  = 'Quản lý';
    protected static ?string $navigationLabel  = 'Khách hàng';
    protected static ?string $modelLabel       = 'Khách hàng';
    protected static ?string $pluralModelLabel = 'Khách hàng';
    protected static ?int    $navigationSort   = 20;

    // Trước đây hardcode isSuperAdmin() — bỏ qua CustomerPolicy (đã đúng, kiểm tra
    // view_any_customer), khiến tick/bỏ tick quyền này ở Roles & Permissions vô tác dụng.
    public static function canViewAny(): bool
    {
        return auth()->user()?->can('view_any_customer') ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Grid::make(['default' => 1, 'lg' => 3])->schema([

                // ── Cột 1: Thông tin cơ bản + CCCD ──────────────────────
                Section::make('Thông tin cơ bản')
                    ->schema([
                        TextInput::make('fullname')
                            ->label('Họ và tên')
                            ->maxLength(255),

                        TextInput::make('phone')
                            ->label('Số điện thoại')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(20),

                        DatePicker::make('date_of_birth')
                            ->label('Ngày sinh')
                            ->displayFormat('d/m/Y'),

                        Toggle::make('phone_verified_at')
                            ->label('Đã xác thực SĐT')
                            ->onIcon('heroicon-o-check')
                            ->offIcon('heroicon-o-x-mark')
                            ->formatStateUsing(fn ($record) => ! is_null($record?->phone_verified_at))
                            ->dehydrated(false),

                        Toggle::make('status')
                            ->label('Hoạt động')
                            ->onIcon('heroicon-o-check')
                            ->offIcon('heroicon-o-x-mark')
                            ->onColor('success')
                            ->offColor('danger')
                            ->formatStateUsing(fn ($state) => $state !== 'inactive')
                            ->dehydrateStateUsing(fn (bool $state) => $state ? 'active' : 'inactive')
                            ->default(true),

                        FileUpload::make('cccd_front')
                            ->label('Mặt trước CCCD')
                            ->image()
                            ->disk('public')
                            ->directory('cccd')
                            ->maxSize(10240)
                            ->imagePreviewHeight('300')
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/jpg', 'image/avif', 'image/webp', 'image/heic', 'image/heif'])
                            ->helperText('Upload ảnh gốc không crop/resize để quét QR được. Tối đa 10MB.'),

                        FileUpload::make('cccd_back')
                            ->label('Mặt sau CCCD')
                            ->image()
                            ->disk('public')
                            ->directory('cccd')
                            ->maxSize(10240)
                            ->imagePreviewHeight('300')
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/jpg', 'image/avif', 'image/webp', 'image/heic', 'image/heif'])
                            ->helperText('Upload ảnh gốc không crop/resize để quét QR được. Tối đa 10MB.'),

                        KeyValue::make('cccd_data')
                            ->label('Dữ liệu CCCD (sau khi quét)')
                            ->keyLabel('Trường')
                            ->valueLabel('Giá trị')
                            ->disabled()
                            ->hidden(fn ($record) => blank($record?->cccd_data)),
                    ])
                    ->columns(1)
                    ->columnSpan(fn (?Customer $record): int => $record === null ? 3 : 1),

                // ── Cột 2: Hạng thành viên & quyền lợi ─────────────────
                Section::make('Hạng thành viên')
                    ->schema([
                        Select::make('membership_tier_id')
                            ->label('Hạng')
                            ->options(MembershipTier::where('is_active', true)->orderBy('sort_order')->pluck('name', 'id'))
                            ->searchable()
                            ->placeholder('— Chưa có hạng —')
                            ->afterStateUpdated(function ($state, $record): void {
                                if (! $record || ! $state) {
                                    return;
                                }
                                \App\Models\CustomerMembershipLog::create([
                                    'customer_id'        => $record->id,
                                    'from_tier_id'       => $record->getOriginal('membership_tier_id'),
                                    'to_tier_id'         => $state,
                                    'reason'             => 'manual',
                                    'spending_at_change' => $record->total_spending ?? 0,
                                ]);
                            })
                            ->live(),

                        TextInput::make('total_spending')
                            ->label('Tổng chi tiêu')
                            ->numeric()
                            ->suffix('VNĐ'),

                        Placeholder::make('personal_coupons_display')
                            ->label('Mã giảm giá thành viên')
                            ->content(function (?Customer $record): HtmlString {
                                if (! $record) {
                                    return new HtmlString('<p class="text-sm text-gray-400 italic">—</p>');
                                }

                                $coupons = $record->personalCoupons()
                                    ->orderByDesc('created_at')
                                    ->get();

                                if ($coupons->isEmpty()) {
                                    return new HtmlString('<p class="text-sm text-gray-400 italic">Chưa có mã nào.</p>');
                                }

                                $html = '<div class="space-y-2">';
                                foreach ($coupons as $coupon) {
                                    $expired = $coupon->end_at && now()->gt($coupon->end_at);
                                    $used    = $coupon->usage_limit && $coupon->used_count >= $coupon->usage_limit;
                                    $inactive = ! $coupon->is_active;

                                    if ($expired || $used || $inactive) {
                                        $wrap = 'rounded-lg bg-gray-100 dark:bg-gray-800 px-3 py-2 opacity-50';
                                        $code = '<s class="font-mono font-semibold text-sm text-gray-400">' . e($coupon->code) . '</s>';
                                    } else {
                                        $wrap = 'rounded-lg bg-emerald-50 dark:bg-emerald-900/20 px-3 py-2 border border-emerald-200 dark:border-emerald-800';
                                        $code = '<code class="font-mono font-semibold text-sm text-emerald-700 dark:text-emerald-300 select-all">' . e($coupon->code) . '</code>';
                                    }

                                    $value = $coupon->type === 'percentage'
                                        ? $coupon->value . '%'
                                        : number_format((float) $coupon->value, 0, ',', '.') . ' VNĐ';

                                    $expire = $coupon->end_at
                                        ? ' · HH: ' . \Carbon\Carbon::parse($coupon->end_at)->format('d/m/Y')
                                        : '';

                                    $html .= '<div class="flex items-center justify-between gap-2 ' . $wrap . '">';
                                    $html .= $code;
                                    $html .= '<span class="text-xs text-gray-500 dark:text-gray-400 whitespace-nowrap">' . e($value) . $expire . '</span>';
                                    $html .= '</div>';
                                }
                                $html .= '</div>';

                                return new HtmlString($html);
                            })
                            ->hiddenOn('create'),

                        Placeholder::make('tier_benefits_display')
                            ->label('Quyền lợi hạng')
                            ->content(function (?Customer $record): HtmlString {
                                $tier = $record?->membershipTier;

                                if (! $tier) {
                                    return new HtmlString(
                                        '<p class="text-sm text-gray-400 dark:text-gray-500 italic">Chưa được phân hạng.</p>'
                                    );
                                }

                                $html = '<div class="space-y-3 text-sm">';

                                if ($tier->description) {
                                    $html .= '<p class="text-gray-600 dark:text-gray-300">' . e($tier->description) . '</p>';
                                }

                                $html .= '<div class="grid grid-cols-2 gap-2">';

                                // Ngưỡng chi tiêu
                                $html .= '<div class="rounded-lg bg-gray-50 dark:bg-white/5 p-3">';
                                $html .= '<div class="text-xs uppercase tracking-wide text-gray-400 mb-1">Ngưỡng chi tiêu</div>';
                                $html .= '<div class="font-semibold text-gray-800 dark:text-gray-100">'
                                    . number_format((float) $tier->min_spending, 0, ',', '.') . ' VNĐ'
                                    . '</div>';
                                $html .= '</div>';

                                // Coupon chào mừng
                                if ((float) $tier->welcome_coupon_value > 0) {
                                    $val = $tier->welcome_coupon_type === 'percentage'
                                        ? $tier->welcome_coupon_value . '%'
                                        : number_format((float) $tier->welcome_coupon_value, 0, ',', '.') . ' VNĐ';

                                    $html .= '<div class="rounded-lg bg-emerald-50 dark:bg-emerald-900/20 p-3">';
                                    $html .= '<div class="text-xs uppercase tracking-wide text-emerald-600 dark:text-emerald-400 mb-1">Coupon chào mừng</div>';
                                    $html .= '<div class="font-semibold text-emerald-700 dark:text-emerald-300">'
                                        . e($val) . ' · ' . $tier->welcome_coupon_days . ' ngày'
                                        . '</div>';
                                    $html .= '</div>';
                                }

                                $html .= '</div>'; // end grid

                                // Hạng tiếp theo
                                $nextTier = MembershipTier::where('is_active', true)
                                    ->where('min_spending', '>', $tier->min_spending)
                                    ->orderBy('min_spending')
                                    ->first();

                                if ($nextTier) {
                                    $remaining = max(0, (float) $nextTier->min_spending - (float) ($record->total_spending ?? 0));
                                    $html .= '<div class="border-t border-gray-200 dark:border-gray-700 pt-3">';
                                    $html .= '<div class="text-xs text-gray-500">Hạng tiếp theo: <span class="font-medium text-gray-700 dark:text-gray-300">' . e($nextTier->name) . '</span></div>';
                                    if ($remaining > 0) {
                                        $html .= '<div class="text-xs text-gray-400 mt-1">Còn cần chi thêm <span class="font-semibold text-amber-600 dark:text-amber-400">'
                                            . number_format($remaining, 0, ',', '.') . ' VNĐ</span></div>';
                                    } else {
                                        $html .= '<div class="text-xs text-emerald-600 dark:text-emerald-400 mt-1">Đủ điều kiện lên hạng ✓</div>';
                                    }
                                    $html .= '</div>';
                                }

                                $html .= '</div>';

                                return new HtmlString($html);
                            }),
                    ])
                    ->columns(1)
                    ->columnSpan(1)
                    ->hiddenOn('create'),

                // ── Cột 3: Mã giảm giá được gán ────────────────────────
                Section::make('Mã giảm giá')
                    ->schema([
                        Select::make('coupons')
                            ->label('Mã giảm giá')
                            ->multiple()
                            ->relationship('coupons', 'code')
                            ->getOptionLabelFromRecordUsing(
                                fn (Coupon $record): string => "[{$record->code}] {$record->name}"
                            )
                            ->searchable()
                            ->preload()
                            ->placeholder('Tìm và chọn mã giảm giá...')
                            ->helperText('Bỏ chọn để xoá, chọn thêm để gán mã mới cho khách hàng này.'),
                    ])
                    ->columns(1)
                    ->columnSpan(1)
                    ->hiddenOn('create'),

            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('fullname')
                    ->label('Họ và tên')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),

                TextColumn::make('phone')
                    ->label('Số điện thoại')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('date_of_birth')
                    ->label('Ngày sinh')
                    ->date('d/m/Y')
                    ->placeholder('—'),

                IconColumn::make('phone_verified_at')
                    ->label('Đã xác thực')
                    ->boolean()
                    ->getStateUsing(fn ($record) => ! is_null($record->phone_verified_at)),

                IconColumn::make('cccd_front')
                    ->label('CCCD')
                    ->boolean()
                    ->getStateUsing(fn ($record) => ! is_null($record->cccd_front)),

                ToggleColumn::make('status')
                    ->label('Hoạt động')
                    ->onIcon('heroicon-o-check-circle')
                    ->offIcon('heroicon-o-x-circle')
                    ->onColor('success')
                    ->offColor('danger')
                    ->getStateUsing(fn ($record) => $record->status === 'active')
                    ->updateStateUsing(function ($record, bool $state): void {
                        $record->update(['status' => $state ? 'active' : 'inactive']);
                        if (! $state) {
                            $record->tokens()->delete();
                        }
                    }),

                TextColumn::make('membershipTier.name')
                    ->label('Hạng thành viên')
                    ->badge()
                    ->color(fn ($record) => $record->membershipTier?->color ?? 'gray')
                    ->placeholder('—'),

                TextColumn::make('total_spending')
                    ->label('Chi tiêu')
                    ->money('VND')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Ngày đăng ký')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('deleted_at')
                    ->label('Đã xoá')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                TrashedFilter::make(),
            ])
            ->actions([
                ViewAction::make()->label('Chi tiết'),
                Action::make('assign_tier')
                    ->label('Gán hạng')
                    ->icon('heroicon-o-trophy')
                    ->color('warning')
                    ->form([
                        Select::make('membership_tier_id')
                            ->label('Hạng thành viên')
                            ->options(MembershipTier::where('is_active', true)->orderBy('sort_order')->pluck('name', 'id'))
                            ->required()
                            ->searchable(),
                    ])
                    ->fillForm(fn (Customer $record) => ['membership_tier_id' => $record->membership_tier_id])
                    ->action(function (Customer $record, array $data): void {
                        $from = $record->membership_tier_id;
                        $to   = (int) $data['membership_tier_id'];

                        if ($from === $to) {
                            return;
                        }

                        app(\App\Services\MembershipService::class)->assignManually($record, $to, $from);

                        \Filament\Notifications\Notification::make()
                            ->title('Đã gán hạng thành công')
                            ->body('Voucher chính thức của hạng (nếu có cấu hình) đã được cấp cho khách.')
                            ->success()
                            ->send();
                    })
                    ->modalHeading('Gán hạng thành viên')
                    ->modalSubmitActionLabel('Lưu'),
                EditAction::make()->label('Sửa'),
                DeleteAction::make()->label('Xoá'),
                RestoreAction::make()->label('Khôi phục'),
                ForceDeleteAction::make()->label('Xoá vĩnh viễn'),
            ])
            ->bulkActions([
                BulkAction::make('assign_coupons')
                    ->label('Gán mã giảm giá')
                    ->icon('heroicon-o-ticket')
                    ->color('success')
                    ->form([
                        Select::make('coupon_ids')
                            ->label('Chọn mã giảm giá')
                            ->options(
                                Coupon::where('is_active', true)
                                    ->orderByDesc('created_at')
                                    ->get()
                                    ->mapWithKeys(fn (Coupon $c) => [
                                        $c->id => "[{$c->code}] {$c->name}",
                                    ])
                            )
                            ->multiple()
                            ->required()
                            ->searchable()
                            ->preload(),
                    ])
                    ->action(function (Collection $records, array $data): void {
                        $now       = now();
                        $pivotData = collect($data['coupon_ids'])
                            ->mapWithKeys(fn ($id) => [$id => ['assigned_at' => $now]])
                            ->toArray();

                        foreach ($records as $customer) {
                            $customer->coupons()->syncWithoutDetaching($pivotData);
                        }
                    })
                    ->modalHeading('Gán mã giảm giá cho khách hàng đã chọn')
                    ->modalSubmitActionLabel('Gán')
                    ->successNotificationTitle('Đã gán mã giảm giá thành công')
                    ->deselectRecordsAfterCompletion(),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getRelationManagers(): array
    {
        return [
            MembershipLogsRelationManager::class,
            PersonalCouponsRelationManager::class,
            AssignedCouponsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListCustomers::route('/'),
            'create' => Pages\CreateCustomer::route('/create'),
            'edit'   => Pages\EditCustomer::route('/{record}/edit'),
            'view'   => Pages\ViewCustomer::route('/{record}'),
        ];
    }
}
