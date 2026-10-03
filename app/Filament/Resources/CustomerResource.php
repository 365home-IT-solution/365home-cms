<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\CustomerResource\Pages;
use App\Filament\Resources\CustomerResource\RelationManagers\AssignedCouponsRelationManager;
use App\Filament\Resources\CustomerResource\RelationManagers\CompanionsRelationManager;
use App\Filament\Resources\CustomerResource\RelationManagers\CouponUsagesRelationManager;
use App\Filament\Resources\CustomerResource\RelationManagers\MembershipLogsRelationManager;
use App\Filament\Resources\CustomerResource\RelationManagers\PersonalCouponsRelationManager;
use App\Models\Customer;
use App\Models\MembershipTier;
use App\Services\CccdIntakeService;
use App\Support\CccdIdentity;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Group;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
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
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\HtmlString;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Modules\Payment\App\Services\CccdScannerService;
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

    // Dữ liệu QR đọc được từ ảnh cccd_qr_image vừa tải lên (rule của field ghi vào lúc validate) —
    // trang Create/Edit lấy ra ghi vào cccd_data trong cùng request lưu.
    public static ?array $scannedCccdData = null;

    /**
     * Quét QR trên ảnh CCCD vừa tải lên (file tạm Livewire). Kết quả nhớ theo tên file tạm để lúc
     * lưu không phải quét lại ảnh đã quét khi tải lên.
     *
     * @return array{data: ?array, error: ?string}
     */
    public static function scanUploadedQr(TemporaryUploadedFile $file): array
    {
        $key = 'cccd-admin-scan:' . md5($file->getFilename());
        if ($cached = Cache::get($key)) {
            return $cached;
        }

        $data  = app(CccdIntakeService::class)->scanQr($file);
        $error = CccdIdentity::validate($data);
        if ($error && ! $data) {
            $error = 'Không đọc được mã QR trên ảnh CCCD nên không lưu được. Vui lòng chụp lại: đủ sáng, lấy nét vào thẻ, thẻ chiếm gần hết khung hình và dùng ảnh gốc (không nén, không chụp lại màn hình).';
        }

        $result = ['data' => $error ? null : $data, 'error' => $error];

        // Hết thời gian quét (server bận) là lỗi tạm thời — không nhớ, để lần lưu quét lại.
        if (! $error || CccdScannerService::lastFailure() !== 'timeout') {
            Cache::put($key, $result, now()->addMinutes(30));
        }

        return $result;
    }

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

                // ── Cột trái: Thông tin cơ bản + CCCD ───────────────────
                Group::make([
                    Section::make('Thông tin cơ bản')
                        ->icon('heroicon-o-user')
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

                            Toggle::make('status')
                                ->label('Hoạt động')
                                ->inline(false)
                                ->onIcon('heroicon-o-check')
                                ->offIcon('heroicon-o-x-mark')
                                ->onColor('success')
                                ->offColor('danger')
                                ->formatStateUsing(fn ($state) => $state !== 'inactive')
                                ->dehydrateStateUsing(fn (bool $state) => $state ? 'active' : 'inactive')
                                ->default(true),

                            Toggle::make('phone_verified_at')
                                ->label('Đã xác thực SĐT')
                                ->inline(false)
                                ->onIcon('heroicon-o-check')
                                ->offIcon('heroicon-o-x-mark')
                                ->formatStateUsing(fn ($record) => ! is_null($record?->phone_verified_at))
                                ->dehydrated(false),
                        ])
                        ->columns(['default' => 1, 'md' => 3]),

                    Section::make('Căn cước công dân')
                        ->icon('heroicon-o-identification')
                        ->description('Tải ảnh mặt có mã QR — hệ thống quét ngay và hiện thông tin bên cạnh. Ảnh không đọc được mã QR sẽ không lưu được.')
                        ->schema([
                            FileUpload::make('cccd_qr_image')
                                ->label('Ảnh mặt có mã QR')
                                ->image()
                                ->disk('public')
                                ->directory('cccd/qr')
                                ->maxSize(10240)
                                ->imagePreviewHeight('240')
                                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/jpg', 'image/avif', 'image/webp', 'image/heic', 'image/heif'])
                                // Quét QR NGAY khi ảnh tải lên xong: đọc được thì hiện dữ liệu CCCD luôn, không đọc
                                // được thì báo lỗi tại ô ảnh. Lúc lưu, rule bên dưới chặn lại nếu ảnh vẫn không đạt
                                // (dữ liệu CCCD chỉ lấy từ QR); đạt thì trang Create/Edit ghi cccd_data.
                                ->afterStateUpdated(function (FileUpload $component, mixed $state, Set $set, $livewire): void {
                                    $file = collect(\Illuminate\Support\Arr::wrap($state))->first(fn ($f) => $f instanceof TemporaryUploadedFile);
                                    if (! $file) {
                                        return;
                                    }

                                    ['data' => $data, 'error' => $error] = self::scanUploadedQr($file);

                                    if ($error) {
                                        $livewire->addError($component->getStatePath(), $error);
                                        Notification::make()->title('Không quét được QR CCCD')->body($error)->danger()->persistent()->send();

                                        return;
                                    }

                                    $livewire->resetErrorBag($component->getStatePath());
                                    $set('cccd_data', $data);

                                    Notification::make()
                                        ->title('Quét CCCD thành công')
                                        ->body(implode(' · ', array_filter([$data['cccd'] ?? null, $data['full_name'] ?? null, $data['dob'] ?? null])))
                                        ->success()
                                        ->send();
                                })
                                ->rule(fn () => function (string $attribute, mixed $value, \Closure $fail): void {
                                    if (! $value instanceof TemporaryUploadedFile) {
                                        return;
                                    }

                                    ['data' => $data, 'error' => $error] = self::scanUploadedQr($value);
                                    if ($error) {
                                        $fail($error);

                                        return;
                                    }

                                    self::$scannedCccdData = $data;
                                })
                                ->helperText('Chụp đủ sáng, lấy nét vào thẻ, thẻ chiếm gần hết khung hình. Dùng ảnh gốc, tối đa 10MB.'),

                            // Dữ liệu đọc từ QR (state cccd_data: nạp từ hồ sơ, hoặc vừa quét khi tải ảnh lên).
                            Placeholder::make('cccd_data_view')
                                ->label('Dữ liệu CCCD (sau khi quét)')
                                ->content(fn (Get $get): HtmlString => self::renderCccdData($get('cccd_data'))),

                            Section::make('Ảnh mặt trước / mặt sau (tuỳ chọn)')
                                ->description('Chỉ để lưu trữ — thông tin CCCD lấy từ ảnh mặt có mã QR ở trên.')
                                ->compact()
                                ->collapsible()
                                ->collapsed(fn (Get $get): bool => blank($get('cccd_front')) && blank($get('cccd_back')))
                                ->columns(['default' => 1, 'md' => 2])
                                ->columnSpanFull()
                                ->schema([
                                    FileUpload::make('cccd_front')
                                        ->label('Mặt trước CCCD')
                                        ->image()
                                        ->disk('public')
                                        ->directory('cccd')
                                        ->maxSize(10240)
                                        ->imagePreviewHeight('200')
                                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/jpg', 'image/avif', 'image/webp', 'image/heic', 'image/heif'])
                                        ->helperText('Tối đa 10MB.'),

                                    FileUpload::make('cccd_back')
                                        ->label('Mặt sau CCCD')
                                        ->image()
                                        ->disk('public')
                                        ->directory('cccd')
                                        ->maxSize(10240)
                                        ->imagePreviewHeight('200')
                                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/jpg', 'image/avif', 'image/webp', 'image/heic', 'image/heif'])
                                        ->helperText('Tối đa 10MB.'),
                                ]),

                            // Lịch sử khách tự xác thực CCCD (trang cá nhân) — hồ sơ dùng lần mới nhất;
                            // dòng "Khác CCCD lần đầu" là lúc khách đổi sang CCCD khác số, cần để ý.
                            Placeholder::make('cccd_verifications_history')
                                ->label('Lịch sử xác thực CCCD')
                                ->content(fn ($record) => self::renderCccdVerifications($record))
                                ->columnSpanFull()
                                ->hidden(fn ($record) => ! $record || ! $record->cccdVerifications()->exists()),
                        ])
                        ->columns(['default' => 1, 'md' => 2]),
                ])
                    ->columnSpan(fn (?Customer $record): int => $record === null ? 3 : 2),

                // ── Cột phải: Hạng thành viên & mã giảm giá ─────────────
                Group::make([
                    // Hạng thành viên & quyền lợi
                    Section::make('Hạng thành viên')
                        ->icon('heroicon-o-trophy')
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

                                    // Ngưỡng chi tiêu — khối "Coupon chào mừng" trước đây hiển thị ở đây đã bị bỏ:
                                    // welcome_coupon_* là cơ chế CŨ đã ngưng dùng (không sửa được qua form hạng,
                                    // không dùng để cấp coupon thật — xem MembershipService::grantTemplateCoupon()),
                                    // dữ liệu hiển thị chỉ là rác còn sót lại. Điều kiện/quyền lợi hạng thật sự nên
                                    // điền vào $tier->description (đã hiển thị ngay phía trên, qua trang Sửa hạng).
                                    $html .= '<div class="rounded-lg bg-gray-50 dark:bg-white/5 p-3">';
                                    $html .= '<div class="text-xs uppercase tracking-wide text-gray-400 mb-1">Ngưỡng chi tiêu</div>';
                                    $html .= '<div class="font-semibold text-gray-800 dark:text-gray-100">'
                                        . number_format((float) $tier->min_spending, 0, ',', '.') . ' VNĐ'
                                        . '</div>';
                                    $html .= '</div>';

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
                        ->icon('heroicon-o-ticket')
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

                ])
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
                    // Có CCCD dùng được để đặt phòng (cccd_data đúng cấu trúc), không chỉ là có ảnh.
                    ->getStateUsing(fn ($record) => \App\Support\CccdIdentity::validate($record->cccd_data, requireQr: false) === null),

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

                TextColumn::make('coupon_usages_count')
                    ->label('Số lượt dùng voucher')
                    ->counts('couponUsages')
                    ->sortable()
                    ->visible(fn () => auth()->user()?->can('view_customer_voucher_usage') ?? false),

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
                Filter::make('used_voucher')
                    ->label('Đã sử dụng voucher')
                    ->visible(fn () => auth()->user()?->can('view_customer_voucher_usage') ?? false)
                    ->form([
                        Toggle::make('is_active')
                            ->label('Chỉ khách đã dùng voucher'),
                        DatePicker::make('used_from')
                            ->label('Từ ngày')
                            ->native(false)
                            ->displayFormat('d/m/Y'),
                        DatePicker::make('used_until')
                            ->label('Đến ngày')
                            ->native(false)
                            ->displayFormat('d/m/Y'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        if (empty($data['is_active'])) {
                            return $query;
                        }

                        return $query->whereHas('couponUsages', function (Builder $q) use ($data) {
                            $q->when($data['used_from'] ?? null, fn (Builder $qq, $date) => $qq->whereDate('used_at', '>=', $date))
                              ->when($data['used_until'] ?? null, fn (Builder $qq, $date) => $qq->whereDate('used_at', '<=', $date));
                        });
                    })
                    ->indicateUsing(function (array $data): array {
                        if (empty($data['is_active'])) {
                            return [];
                        }

                        $indicators = ['is_active' => 'Đã sử dụng voucher'];

                        if (! empty($data['used_from'])) {
                            $indicators['used_from'] = 'Từ ' . \Carbon\Carbon::parse($data['used_from'])->format('d/m/Y');
                        }
                        if (! empty($data['used_until'])) {
                            $indicators['used_until'] = 'Đến ' . \Carbon\Carbon::parse($data['used_until'])->format('d/m/Y');
                        }

                        return $indicators;
                    }),
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

    public static function getRelations(): array
    {
        return [
            CompanionsRelationManager::class,
        ];
    }

    public static function getRelationManagers(): array
    {
        return [
            MembershipLogsRelationManager::class,
            PersonalCouponsRelationManager::class,
            AssignedCouponsRelationManager::class,
            CouponUsagesRelationManager::class,
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

    // Bảng "nhãn — giá trị" cho dữ liệu CCCD đọc từ QR (thay KeyValue hiện key thô cccd/dob/...).
    public static function renderCccdData(mixed $data): HtmlString
    {
        if (! is_array($data) || blank($data['cccd'] ?? null)) {
            return new HtmlString('<div class="rounded-lg bg-gray-50 dark:bg-white/5 p-3"><p class="text-sm text-gray-400 dark:text-gray-500 italic">Chưa có dữ liệu — tải ảnh mặt có mã QR để quét.</p></div>');
        }

        $rows = [
            'Số CCCD'     => $data['cccd'] ?? null,
            'Họ và tên'   => $data['full_name'] ?? null,
            'Ngày sinh'   => $data['dob'] ?? null,
            'Giới tính'   => $data['gender'] ?? null,
            'Địa chỉ'     => $data['address'] ?? null,
            'Ngày cấp'    => $data['issued_date'] ?? null,
            'Số CMND cũ'  => $data['old_id'] ?? null,
            'Nguồn'       => match ($data['source'] ?? null) {
                'qr'    => 'Mã QR',
                'ocr'   => 'Đọc chữ (OCR)',
                default => $data['source'] ?? null,
            },
        ];

        $html = '<div class="rounded-lg bg-gray-50 dark:bg-white/5 p-3 space-y-2 text-sm">';
        foreach ($rows as $label => $value) {
            if (blank($value)) {
                continue;
            }
            $html .= '<div class="flex items-start justify-between gap-3">'
                . '<span class="text-xs text-gray-500 dark:text-gray-400 whitespace-nowrap">' . e($label) . '</span>'
                . '<span class="font-semibold text-gray-800 dark:text-gray-100" style="text-align:right;">' . e((string) $value) . '</span>'
                . '</div>';
        }

        return new HtmlString($html . '</div>');
    }

    // Bảng lịch sử xác thực CCCD: lần, thời điểm, số CCCD, họ tên, ngày sinh, ảnh QR, trạng thái.
    private static function renderCccdVerifications(?Customer $record): \Illuminate\Support\HtmlString
    {
        $rows = '';
        $latest = $record?->cccdVerifications->max('attempt');
        foreach ($record?->cccdVerifications ?? [] as $v) {
            $data   = is_array($v->cccd_data) ? $v->cccd_data : [];
            $image  = $v->cccd_qr_image
                ? '<a href="' . e(\Illuminate\Support\Facades\Storage::disk('public')->url($v->cccd_qr_image)) . '" target="_blank" style="color:#2563eb;text-decoration:underline;">Xem ảnh</a>'
                : '—';
            $status = match (true) {
                $v->attempt === 1    => '<span style="color:#15803d;font-weight:600;">Lần đầu</span>',
                $v->same_as_first    => '<span style="color:#15803d;font-weight:600;">Cùng CCCD lần đầu</span>',
                default              => '<span style="color:#b91c1c;font-weight:600;">Khác CCCD lần đầu</span>',
            };
            if ($v->attempt === $latest) {
                $status .= ' <span style="font-size:.6875rem;color:#fff;background:#111827;padding:.05rem .4rem;border-radius:9999px;">Đang dùng</span>';
            }

            $rows .= '<tr style="border-top:1px solid #e5e7eb;">'
                . '<td style="padding:.35rem .5rem;">#' . $v->attempt . '</td>'
                . '<td style="padding:.35rem .5rem;white-space:nowrap;">' . e($v->created_at?->format('d/m/Y H:i')) . '</td>'
                . '<td style="padding:.35rem .5rem;font-family:monospace;">' . e($data['cccd'] ?? '') . '</td>'
                . '<td style="padding:.35rem .5rem;">' . e($data['full_name'] ?? '') . '</td>'
                . '<td style="padding:.35rem .5rem;">' . e($data['dob'] ?? '') . '</td>'
                . '<td style="padding:.35rem .5rem;">' . $image . '</td>'
                . '<td style="padding:.35rem .5rem;">' . $status . '</td>'
                . '</tr>';
        }

        return new \Illuminate\Support\HtmlString(
            '<table style="width:100%;font-size:.8125rem;border-collapse:collapse;">'
            . '<thead><tr style="text-align:left;color:#6b7280;">'
            . '<th style="padding:.35rem .5rem;">Lần</th><th style="padding:.35rem .5rem;">Thời điểm</th><th style="padding:.35rem .5rem;">Số CCCD</th>'
            . '<th style="padding:.35rem .5rem;">Họ tên</th><th style="padding:.35rem .5rem;">Ngày sinh</th><th style="padding:.35rem .5rem;">Ảnh</th><th style="padding:.35rem .5rem;">Trạng thái</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table>'
        );
    }
}
