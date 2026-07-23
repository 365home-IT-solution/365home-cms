<?php

declare(strict_types=1);

namespace App\Filament\Resources\PartnerResource\Forms;

use App\Mail\LockNotificationMail;
use App\Models\Partner;
use App\Models\PartnerContractVersion;
use App\Models\User;
use App\Support\PartnerContractRenderer;
use Modules\DataPermission\Entities\UserBranchPermission;
use Filament\Forms;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Form;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Modules\Category\Entities\Category;
use Modules\Product\App\Models\Product;

// Form "Xác minh đối tác" cho super_admin — 7 tab đúng theo mockup: Người đại diện / Doanh
// nghiệp / Tài chính / Cơ sở lưu trú / Hợp đồng / Tài liệu & Xác minh / Lịch sử. Dùng component
// Filament chuẩn (đồng bộ theme với toàn bộ admin), không phải clone HTML/CSS riêng.
class PartnerForm
{
    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Tabs::make('partner_tabs')
                ->tabs([
                    self::representativeTab(),
                    self::businessTab(),
                    self::financialTab(),
                    self::propertiesTab(),
                    self::branchAssignmentTab(),
                    self::userAssignmentTab(),
                    self::contractTab(),
                    self::verificationTab(),
                    self::historyTab(),
                ])
                ->columnSpanFull(),
        ]);
    }

    // ── TAB 1: Người đại diện ────────────────────────────────────────────────
    private static function representativeTab(): Forms\Components\Tabs\Tab
    {
        return Forms\Components\Tabs\Tab::make('Người đại diện')
            ->icon('heroicon-o-user')
            ->schema([
                Forms\Components\Section::make('Thông tin cá nhân')
                    ->schema([
                        Forms\Components\Grid::make(4)->schema([
                            Forms\Components\TextInput::make('representative_name')
                                ->label('Họ và tên đầy đủ')
                                ->required()
                                ->maxLength(255),

                            Forms\Components\DatePicker::make('representative_dob')
                                ->label('Ngày sinh')
                                ->native(false)
                                ->displayFormat('d/m/Y'),

                            Forms\Components\TextInput::make('representative_id_number')
                                ->label('Số CMND/CCCD/Hộ chiếu')
                                ->maxLength(50),

                            Forms\Components\Placeholder::make('login_email')
                                ->label('Email đăng nhập hệ thống')
                                ->content(fn (?Partner $record) => $record?->owner()?->email ?? '— Chưa có tài khoản đăng nhập —'),
                        ]),

                        Forms\Components\Grid::make(3)->schema([
                            Forms\Components\TextInput::make('email')
                                ->label('Email liên hệ')
                                ->email()
                                ->maxLength(255),

                            Forms\Components\TextInput::make('phone')
                                ->label('Số điện thoại - chính')
                                ->tel()
                                ->maxLength(20),

                            Forms\Components\TextInput::make('representative_phone_secondary')
                                ->label('Số điện thoại - phụ')
                                ->tel()
                                ->maxLength(20),
                        ]),
                    ]),

                Forms\Components\Section::make('Giấy tờ định danh')
                    ->schema([
                        Forms\Components\Grid::make(2)->schema([
                            SpatieMediaLibraryFileUpload::make('representative_id_front')
                                ->label('Mặt trước CCCD')
                                ->collection('representative_id_front')
                                ->image()
                                ->imageEditor()
                                ->hintColor('success')
                                ->hint(fn (?Partner $record) => $record?->getFirstMedia('representative_id_front') ? '● Sẵn sàng quét' : null),

                            SpatieMediaLibraryFileUpload::make('representative_id_back')
                                ->label('Mặt sau CCCD')
                                ->collection('representative_id_back')
                                ->image()
                                ->imageEditor()
                                ->hintColor('success')
                                ->hint(fn (?Partner $record) => $record?->getFirstMedia('representative_id_back') ? '● Sẵn sàng quét' : null),
                        ]),
                    ]),
            ]);
    }

    // ── TAB 2: Doanh nghiệp ──────────────────────────────────────────────────
    private static function businessTab(): Forms\Components\Tabs\Tab
    {
        return Forms\Components\Tabs\Tab::make('Doanh nghiệp')
            ->icon('heroicon-o-building-office-2')
            ->schema([
                Forms\Components\Section::make('Thông tin pháp lý')
                    ->schema([
                        Forms\Components\Grid::make(2)->schema([
                            Forms\Components\TextInput::make('legal_name')
                                ->label('Tên doanh nghiệp (Tiếng Việt)')
                                ->required()
                                ->maxLength(255),

                            Forms\Components\TextInput::make('tax_code')
                                ->label('Mã số thuế')
                                ->maxLength(50),

                            Forms\Components\DatePicker::make('business_license_date')
                                ->label('Ngày cấp GPKD')
                                ->native(false)
                                ->displayFormat('d/m/Y'),

                            Forms\Components\TextInput::make('business_license_issuer')
                                ->label('Nơi cấp')
                                ->maxLength(255),
                        ]),

                        Forms\Components\Textarea::make('address')
                            ->label('Địa chỉ trụ sở chính')
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    // ── TAB 3: Tài chính ─────────────────────────────────────────────────────
    private static function financialTab(): Forms\Components\Tabs\Tab
    {
        return Forms\Components\Tabs\Tab::make('Tài chính')
            ->icon('heroicon-o-banknotes')
            ->schema([
                Forms\Components\Section::make('Thông tin Ngân hàng')
                    ->schema([
                        Forms\Components\Grid::make(2)->schema([
                            Forms\Components\Grid::make(1)->schema([
                                Forms\Components\TextInput::make('bank_name')->label('Tên ngân hàng')->maxLength(255),
                                Forms\Components\TextInput::make('bank_branch')->label('Chi nhánh')->maxLength(255),
                                Forms\Components\TextInput::make('bank_account_number')->label('Số tài khoản')->maxLength(50),
                                Forms\Components\TextInput::make('bank_account_holder')->label('Tên chủ tài khoản')->maxLength(255),
                            ]),

                            SpatieMediaLibraryFileUpload::make('bank_card_image')
                                ->label('Ảnh chụp sổ phụ / Thẻ ngân hàng')
                                ->collection('bank_card_image')
                                ->image(),
                        ]),
                    ]),

                Forms\Components\Section::make('Ví điện tử')
                    ->schema([
                        Forms\Components\Grid::make(3)->schema([
                            Forms\Components\TextInput::make('momo_phone')
                                ->label('Số điện thoại MoMo')
                                ->tel(),
                            Forms\Components\TextInput::make('zalopay_id')
                                ->label('Số điện thoại / ID ZaloPay'),
                            Forms\Components\TextInput::make('vnpay_id')
                                ->label('Mã định danh VNPay'),
                        ]),
                    ]),

                Forms\Components\Section::make('Thanh toán quốc tế')
                    ->schema([
                        Forms\Components\Grid::make(3)->schema([
                            Forms\Components\TextInput::make('paypal_email')
                                ->label('PayPal (Email)')
                                ->email(),
                            Forms\Components\TextInput::make('wise_account')
                                ->label('Wise Account / Email'),
                            Forms\Components\TextInput::make('swift_code')
                                ->label('SWIFT Code / IBAN'),
                        ]),
                    ]),

                Forms\Components\Section::make('Chu kỳ thanh toán')
                    ->schema([
                        Forms\Components\Radio::make('payment_cycle')
                            ->hiddenLabel()
                            ->options([
                                'weekly'   => 'Hàng tuần (Thứ 2)',
                                'biweekly' => '2 tuần một lần (Ngày 1 và 15)',
                                'monthly'  => 'Hàng tháng (Ngày cuối tháng)',
                            ])
                            ->default('biweekly')
                            ->inline()
                            ->helperText('Chu kỳ thanh toán được áp dụng sau khi đối soát thành công các đơn hàng trong kỳ. Yêu cầu thanh toán tối thiểu từ 500.000 VNĐ.'),
                    ]),
            ]);
    }

    // ── TAB 4: Cơ sở lưu trú ─────────────────────────────────────────────────
    // Chi nhánh/phòng vốn đã có sẵn (Category/Product, quản lý ở resource riêng) — tab này chỉ
    // tổng hợp + liên kết sang đó, không xây lại CRUD chi nhánh/phòng trùng lặp.
    private static function propertiesTab(): Forms\Components\Tabs\Tab
    {
        return Forms\Components\Tabs\Tab::make('Cơ sở lưu trú')
            ->icon('heroicon-o-building-storefront')
            ->schema([
                Forms\Components\Placeholder::make('properties_note')
                    ->hiddenLabel()
                    ->visible(fn (?Partner $record) => ! $record)
                    ->content('Lưu đối tác trước để có thể thêm cơ sở lưu trú.'),

                Forms\Components\Actions::make([
                    Forms\Components\Actions\Action::make('addBranch')
                        ->label('+ Thêm cơ sở mới')
                        ->icon('heroicon-o-plus')
                        ->modalHeading('Thêm cơ sở lưu trú mới')
                        ->modalSubmitActionLabel('Tạo cơ sở')
                        ->form([
                            Forms\Components\TextInput::make('name')
                                ->label('Tên cơ sở')
                                ->required()
                                ->maxLength(255)
                                ->live(onBlur: true)
                                ->afterStateUpdated(fn ($state, callable $set) => $set('slug', \Illuminate\Support\Str::slug($state))),
                            Forms\Components\TextInput::make('slug')
                                ->label('Slug')
                                ->required()
                                ->maxLength(255),
                            Forms\Components\Textarea::make('description')
                                ->label('Địa chỉ / Mô tả')
                                ->rows(2),
                            Forms\Components\Toggle::make('status')
                                ->label('Hoạt động')
                                ->default(true),
                        ])
                        ->action(function (array $data, ?Partner $record, $livewire) {
                            Category::create([
                                ...$data,
                                'category_type' => 'product',
                                'partner_id'    => $record->id,
                            ]);

                            \Filament\Notifications\Notification::make()
                                ->title('Đã thêm cơ sở lưu trú mới')
                                ->success()
                                ->send();

                            $livewire->redirect(\App\Filament\Resources\PartnerResource::getUrl('edit', ['record' => $record]));
                        }),
                ])
                    ->visible(fn (?Partner $record) => (bool) $record)
                    ->columnSpanFull(),

                Forms\Components\Placeholder::make('properties_summary')
                    ->hiddenLabel()
                    ->visible(fn (?Partner $record) => (bool) $record)
                    ->content(function (?Partner $record) {
                        $branches = Category::where('partner_id', $record->id)
                            ->where('category_type', 'product')
                            ->withCount(['products'])
                            ->get();

                        $totalRooms  = Product::where('partner_id', $record->id)->count();
                        $activeCount = $branches->where('status', true)->count();

                        return new HtmlString(self::renderPropertiesSummary($branches, $totalRooms, $activeCount));
                    })
                    ->columnSpanFull(),

            ]);
    }

    private static function renderPropertiesSummary($branches, int $totalRooms, int $activeCount): string
    {
        $statCard = fn (string $label, $value) => <<<HTML
            <div style="flex:1;padding:16px 20px;border:1px solid #e5e7eb;border-radius:12px;">
                <div style="font-size:0.78rem;color:#6b7280;">{$label}</div>
                <div style="font-size:1.5rem;font-weight:700;margin-top:4px;">{$value}</div>
            </div>
        HTML;

        $stats = $statCard('Tổng số cơ sở', $branches->count())
            . $statCard('Đang hoạt động', $activeCount)
            . $statCard('Tổng số phòng', $totalRooms);

        if ($branches->isEmpty()) {
            $rows = '<tr><td colspan="6" style="padding:16px;text-align:center;color:#9ca3af;">Chưa có cơ sở lưu trú nào.</td></tr>';
        } else {
            $rows = $branches->map(function ($branch) {
                $status  = $branch->status
                    ? '<span style="color:#059669;font-weight:600;">● Hoạt động</span>'
                    : '<span style="color:#9ca3af;font-weight:600;">● Tạm dừng</span>';

                // 'amount' là tổng thực thu cuối cùng (gồm cả phụ phí phát sinh sau khi đặt/thanh
                // toán, vd dịch vụ thêm) — 'full_amount' chỉ là tổng giá gốc CỐ ĐỊNH lúc đặt, không
                // phản ánh phát sinh sau này. Ưu tiên 'amount', fallback full_amount nếu amount rỗng
                // (dữ liệu cũ) — cùng quy ước với KpiService/Dashboard, tránh doanh thu hiển thị ở
                // đây lệch với doanh thu ở Dashboard cho cùng kỳ/cùng đối tác.
                $revenue = \Modules\Payment\Entities\Order::where('category_id', $branch->id)
                    ->where('status', 'paid')
                    ->whereMonth('created_at', now()->month)
                    ->whereYear('created_at', now()->year)
                    ->sum(\Illuminate\Support\Facades\DB::raw('COALESCE(amount, full_amount)'));
                $revenueFmt = number_format((float) $revenue, 0, ',', '.') . 'đ';

                $detailUrl = \App\Filament\Resources\PartnerResource::getUrl('branch-detail', [
                    'record' => $branch->partner_id,
                    'branch' => $branch->id,
                ]);

                return <<<HTML
                    <tr style="border-bottom:1px solid #f1f5f9;">
                        <td style="padding:10px 8px;font-weight:600;">{$branch->name}</td>
                        <td style="padding:10px 8px;color:#6b7280;">{$branch->description}</td>
                        <td style="padding:10px 8px;text-align:center;">{$branch->products_count}</td>
                        <td style="padding:10px 8px;">{$status}</td>
                        <td style="padding:10px 8px;text-align:right;">{$revenueFmt}</td>
                        <td style="padding:10px 8px;"><a href="{$detailUrl}" style="color:#2563eb;text-decoration:underline;">Sửa</a></td>
                    </tr>
                HTML;
            })->implode('');
        }

        return <<<HTML
            <div style="display:flex;gap:16px;margin-bottom:20px;">{$stats}</div>
            <div style="overflow-x:auto;">
                <table style="width:100%;font-size:0.85rem;text-align:left;">
                    <thead>
                        <tr style="border-bottom:2px solid #e5e7eb;color:#6b7280;">
                            <th style="padding:8px;">Cơ sở lưu trú</th>
                            <th style="padding:8px;">Vị trí & địa chỉ</th>
                            <th style="padding:8px;text-align:center;">Số phòng</th>
                            <th style="padding:8px;">Trạng thái</th>
                            <th style="padding:8px;text-align:right;">Doanh thu (tháng)</th>
                            <th style="padding:8px;">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>{$rows}</tbody>
                </table>
            </div>
        HTML;
    }

    // ── TAB: Chi nhánh ───────────────────────────────────────────────────────
    // Gán nhanh các chi nhánh (Category type=product) ĐÃ CÓ SẴN cho đối tác này — kể cả chi nhánh
    // đang thuộc đối tác khác (chọn ở đây sẽ CHUYỂN chi nhánh đó sang đối tác hiện tại). Hoạt động
    // được ngay cả khi TẠO MỚI đối tác (áp dụng ở afterCreate/afterSave, xem CreatePartner/
    // EditPartner) — không cần lưu đối tác trước rồi mới quay lại gán như tab "Cơ sở lưu trú".
    private static function branchAssignmentTab(): Forms\Components\Tabs\Tab
    {
        return Forms\Components\Tabs\Tab::make('Chi nhánh')
            ->icon('heroicon-o-map-pin')
            ->schema([
                Forms\Components\Placeholder::make('branch_assignment_note')
                    ->hiddenLabel()
                    ->content('Chọn các chi nhánh đã có sẵn trong hệ thống để gán cho đối tác này. Có thể chọn cả chi nhánh đang thuộc đối tác khác — khi lưu, chi nhánh đó (và toàn bộ phòng/lịch đặt/mã cổng bên trong) sẽ được CHUYỂN sang đối tác hiện tại.'),

                Forms\Components\CheckboxList::make('branch_ids')
                    ->hiddenLabel()
                    ->options(fn () => Category::query()
                        ->where('category_type', 'product')
                        ->whereNull('parent_id')
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->afterStateHydrated(fn (Forms\Components\CheckboxList $component, ?Partner $record) => $component->state(
                        $record
                            ? Category::where('category_type', 'product')->whereNull('parent_id')->where('partner_id', $record->id)->pluck('id')->all()
                            : []
                    ))
                    ->searchable()
                    ->bulkToggleable()
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }

    // ── TAB: Người dùng ──────────────────────────────────────────────────────
    // Gán nhanh các tài khoản ĐÃ CÓ SẴN vào đối tác này — kể cả tài khoản đang thuộc đối tác
    // khác (chọn ở đây sẽ CHUYỂN tài khoản đó sang đối tác hiện tại).
    private static function userAssignmentTab(): Forms\Components\Tabs\Tab
    {
        return Forms\Components\Tabs\Tab::make('Người dùng')
            ->icon('heroicon-o-users')
            ->schema([
                Forms\Components\Placeholder::make('user_assignment_note')
                    ->hiddenLabel()
                    ->content('Chọn các tài khoản đã có sẵn trong hệ thống để gán vào đối tác này. Có thể chọn cả tài khoản đang thuộc đối tác khác — khi lưu, tài khoản đó sẽ được CHUYỂN sang đối tác hiện tại.'),

                Forms\Components\CheckboxList::make('user_ids')
                    ->hiddenLabel()
                    ->options(fn () => User::query()
                        ->whereDoesntHave('roles', fn ($q) => $q->where('name', config('filament-shield.super_admin.name')))
                        ->orderBy('fullname')
                        ->get()
                        ->mapWithKeys(fn (User $user) => [$user->id => ($user->fullname ?: '(chưa đặt tên)') . ' — ' . $user->email])
                        ->all())
                    ->afterStateHydrated(fn (Forms\Components\CheckboxList $component, ?Partner $record) => $component->state(
                        $record ? User::where('partner_id', $record->id)->pluck('id')->all() : []
                    ))
                    ->searchable()
                    ->bulkToggleable()
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }

    // Áp dụng lựa chọn ở 2 tab "Chi nhánh"/"Người dùng" — dùng CHUNG cho CreatePartner::afterCreate()
    // và EditPartner::afterSave(). Chi nhánh phải gán TỪNG CÁI MỘT qua ->update() trên từng model
    // (không dùng query builder mass update) để trigger CategoryObserver::saved(), cascade đúng
    // partner_id xuống Product/Order/AccessCode + dọn rác UserBranchPermission bên trong.
    public static function syncAssignments(Partner $record, array $branchIds, array $userIds): void
    {
        // CheckboxList trả về id dạng string (Livewire); Category::id là int nên phải ép kiểu
        // trước khi so sánh strict, nếu không in_array(..., true) luôn false → mất gán chi nhánh.
        $branchIds = array_map('intval', $branchIds);

        Category::query()
            ->where('category_type', 'product')
            ->whereNull('parent_id')
            ->where(fn ($q) => $q->where('partner_id', $record->id)->orWhereIn('id', $branchIds))
            ->get()
            ->each(function (Category $category) use ($record, $branchIds) {
                $newPartnerId = in_array($category->id, $branchIds, true) ? $record->id : null;

                if ($category->partner_id !== $newPartnerId) {
                    $category->update(['partner_id' => $newPartnerId]);
                }
            });

        User::query()
            ->where(fn ($q) => $q->where('partner_id', $record->id)->orWhereIn('id', $userIds))
            ->get()
            ->each(function (User $user) use ($record, $userIds) {
                $newPartnerId = in_array($user->id, $userIds, true) ? $record->id : null;

                if ($user->partner_id === $newPartnerId) {
                    return;
                }

                $user->update(['partner_id' => $newPartnerId]);

                // Đổi đối tác — "quyền chi nhánh" (UserBranchPermission) đã gán cho tài khoản này ở
                // các chi nhánh KHÔNG thuộc đối tác mới trở thành rác (tương tự lý do dọn rác trong
                // CategoryObserver::saved()), xóa để không gây khó hiểu khi xem lại bảng phân quyền.
                $query = UserBranchPermission::where('user_id', $user->id);

                if ($newPartnerId === null) {
                    $query->delete();
                } else {
                    $query->whereHas('branch', fn ($bq) => $bq->where('partner_id', '!=', $newPartnerId))->delete();
                }
            });
    }

    // ── TAB 5: Hợp đồng ──────────────────────────────────────────────────────
    private const CONTRACT_STATUS_LABELS = [
        'draft'      => 'Bản nháp (Draft)',
        'pending'    => 'Chờ ký (Pending)',
        'active'     => 'Đang hiệu lực (Active)',
        'expired'    => 'Hết hạn (Expired)',
        'terminated' => 'Chấm dứt (Terminated)',
    ];

    private const CONTRACT_STATUS_COLORS = [
        'draft'      => '#9ca3af',
        'pending'    => '#f59e0b',
        'active'     => '#10b981',
        'expired'    => '#ef4444',
        'terminated' => '#6b7280',
    ];

    // Section "Chỉnh sửa thông tin hợp đồng" — dùng chung cho 2 vị trí trong contractTab() (trước
    // hoặc sau khu vực xem trước/ký), mỗi nơi tự gắn ->visible() riêng theo việc đã có phiên bản
    // hợp đồng hay chưa. Tách ra 1 hàm để không lặp lại y hệt field 2 lần trong code.
    private static function contractEditableFieldsSection(): Forms\Components\Section
    {
        return Forms\Components\Section::make('Chỉnh sửa thông tin hợp đồng')
            ->description('Các trường này là DỮ LIỆU NGUỒN để sinh nội dung hợp đồng — sửa xong cần bấm "Tạo & gửi hợp đồng ký" (hoặc "Tạo lại & gửi ký" nếu đã có) để áp dụng vào phiên bản hợp đồng.')
            ->collapsible()
            ->schema([
                Forms\Components\Grid::make(2)->schema([
                    Forms\Components\TextInput::make('contract_code')
                        ->label('Mã số hợp đồng')
                        ->maxLength(100),

                    Forms\Components\Select::make('contract_type')
                        ->label('Hình thức hợp đồng')
                        ->options([
                            'e_contract' => 'Hợp đồng điện tử (E-Contract)',
                            'paper'      => 'Hợp đồng giấy',
                        ]),

                    Forms\Components\DatePicker::make('contract_signed_at')
                        ->label('Ngày ký kết')
                        ->native(false)
                        ->displayFormat('d/m/Y'),

                    Forms\Components\DatePicker::make('contract_expires_at')
                        ->label('Ngày hết hạn')
                        ->native(false)
                        ->displayFormat('d/m/Y'),
                ]),

                Forms\Components\Select::make('contract_status')
                    ->label('Trạng thái hợp đồng')
                    ->options(self::CONTRACT_STATUS_LABELS)
                    ->default('draft'),

                SpatieMediaLibraryFileUpload::make('contract_file')
                    ->label('Tệp hợp đồng (bản giấy/scan, nếu có)')
                    ->collection('contract_file')
                    ->acceptedFileTypes(['application/pdf', 'image/*'])
                    ->columnSpanFull(),

                Forms\Components\Grid::make(2)->schema([
                    Forms\Components\TextInput::make('commission_rate')
                        ->label('Tỷ lệ hoa hồng (%)')
                        ->helperText('Áp dụng cho tất cả các loại phòng và dịch vụ cộng thêm.'),

                    Forms\Components\Textarea::make('cancellation_policy')
                        ->label('Chính sách hủy/hoàn tiền')
                        ->rows(3)
                        ->placeholder("Hủy trước 48h: Miễn phí\nHủy trong vòng 24h-48h: Phí 50%\nHủy dưới 24h: Phí 100%"),
                ]),
            ]);
    }

    private static function contractTab(): Forms\Components\Tabs\Tab
    {
        return Forms\Components\Tabs\Tab::make('Hợp đồng')
            ->icon('heroicon-o-document-text')
            ->schema([
                Forms\Components\Placeholder::make('legal_notice')
                    ->hiddenLabel()
                    ->content(new HtmlString(
                        '<div style="display:flex;gap:10px;padding:14px 16px;border-radius:10px;background:#ecfeff;border:1px solid #a5f3fc;font-size:0.85rem;color:#155e75;">'
                        . '<strong>Lưu ý về tính pháp lý:</strong> Hợp đồng là căn cứ pháp lý duy nhất để bảo vệ quyền lợi của đối tác. '
                        . 'Mọi giao dịch và cam kết cần được văn bản hóa chính thức tại tab này.'
                        . '</div>'
                    ))
                    ->columnSpanFull(),

                // Khi CHƯA có phiên bản hợp đồng nào (tạo mới đối tác, hoặc đối tác cũ chưa từng
                // tạo hợp đồng) — đặt phần NHẬP THÔNG TIN NGUỒN lên TRƯỚC khu vực xem trước/ký,
                // vì khu vực đó lúc này chỉ toàn placeholder "chưa có dữ liệu". Điền đủ ở đây
                // trước để hợp đồng sinh ra sau (bấm "Tạo & gửi hợp đồng ký") đã đầy đủ ngay,
                // không phải quay lại sửa thêm lần nữa. Khi ĐÃ CÓ hợp đồng rồi, bản sao Ở DƯỚI
                // (sau khu vực xem trước) mới hiện — xem điều kiện ->visible() ngược nhau ở 2 nơi.
                self::contractEditableFieldsSection()
                    ->visible(fn (?Partner $record) => ! self::latestVersion($record)),

                Forms\Components\Grid::make(3)->schema([
                    Forms\Components\Section::make('Xem trước tài liệu')
                        ->columnSpan(2)
                        ->headerActions([
                            Forms\Components\Actions\Action::make('viewContractContentZoom')
                                ->label('Phóng to')
                                ->icon('heroicon-o-magnifying-glass-plus')
                                ->color('gray')
                                ->visible(fn (?Partner $record) => (bool) self::latestVersion($record))
                                ->modalWidth(\Filament\Support\Enums\MaxWidth::Large)
                                ->modalHeading('Toàn văn hợp đồng điện tử')
                                ->modalSubmitAction(false)
                                ->modalCancelActionLabel('Đóng')
                                ->modalContent(fn (?Partner $record) => new HtmlString(self::renderContractContentModal($record, self::latestVersion($record)))),

                            Forms\Components\Actions\Action::make('exportContract')
                                ->label('Tải xuống')
                                ->icon('heroicon-o-arrow-down-tray')
                                ->color('gray')
                                ->visible(fn (?Partner $record) => (bool) $record?->getFirstMedia('contract_file'))
                                ->url(fn (?Partner $record) => $record?->getFirstMedia('contract_file')?->getUrl())
                                ->openUrlInNewTab(),
                        ])
                        ->schema([
                            Forms\Components\Placeholder::make('document_preview')
                                ->hiddenLabel()
                                ->content(fn (?Partner $record) => new HtmlString(
                                    '<div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:28px;font-size:0.85rem;max-height:640px;overflow-y:auto;">'
                                    . self::renderDocumentPreview($record)
                                    . '</div>'
                                )),
                        ]),

                    Forms\Components\Group::make()
                        ->columnSpan(1)
                        ->schema([
                            Forms\Components\Section::make('Trình tự ký kết')
                                ->schema([
                                    Forms\Components\Placeholder::make('signing_timeline')
                                        ->hiddenLabel()
                                        ->content(fn (?Partner $record) => new HtmlString(self::renderSigningTimeline($record ? self::latestVersion($record) : null))),
                                ]),

                            Forms\Components\Section::make('Bảng điều khiển ký')
                                ->schema([
                                    Forms\Components\Placeholder::make('contract_status_badge')
                                        ->hiddenLabel()
                                        ->content(function (?Partner $record) {
                                            $status = $record?->contract_status ?? 'draft';
                                            $label  = self::CONTRACT_STATUS_LABELS[$status] ?? $status;
                                            $color  = self::CONTRACT_STATUS_COLORS[$status] ?? '#9ca3af';
                                            $daysLeft = $record?->contract_expires_at
                                                ? ' (còn ' . max(0, (int) now()->diffInDays($record->contract_expires_at, false)) . ' ngày)'
                                                : '';

                                            return new HtmlString(
                                                '<span style="display:inline-block;padding:4px 12px;border-radius:9999px;background:' . $color . '22;color:' . $color . ';font-weight:700;font-size:0.85rem;">'
                                                . mb_strtoupper($label) . $daysLeft
                                                . '</span>'
                                            );
                                        }),

                                    Forms\Components\Actions::make([
                                        Forms\Components\Actions\Action::make('createAndSendContract')
                                            ->label(fn (?Partner $record) => self::latestVersion($record) ? 'Tạo lại & gửi ký' : 'Tạo & gửi hợp đồng ký')
                                            ->icon('heroicon-o-paper-airplane')
                                            ->color('primary')
                                            ->visible(fn (?Partner $record) => $record && ! self::latestVersion($record)?->isFullySigned())
                                            ->requiresConfirmation()
                                            ->modalDescription('Hệ thống sẽ tạo bản hợp đồng điện tử từ đúng thông tin đối tác hiện tại (điều khoản hoa hồng, chính sách hủy...) và gửi link ký cho đối tác qua email. Kiểm tra kỹ thông tin trước khi gửi — mỗi lần gửi sẽ tạo 1 phiên bản mới, hủy hiệu lực link ký cũ.')
                                            ->action(fn (?Partner $record) => self::createAndSendContract($record)),

                                        Forms\Components\Actions\Action::make('signAndExportContract')
                                            ->label('Ký số & xuất PDF hợp đồng')
                                            ->icon('heroicon-o-shield-check')
                                            ->color('success')
                                            ->visible(fn (?Partner $record) => self::latestVersion($record)?->isPartnerConfirmed() && ! self::latestVersion($record)?->isPlatformSigned())
                                            ->requiresConfirmation()
                                            ->modalDescription('Đối tác đã xác nhận đồng ý qua email — bấm để NỀN TẢNG ký số THẬT niêm phong hợp đồng (chỉ 1 lượt ký duy nhất) và TẢI VỀ MÁY file PDF đã nhúng chữ ký (chuẩn PAdES, nộp được lên neac.gov.vn) — file KHÔNG lưu lại trên server, đây là lần duy nhất tải được bản này nên hãy lưu cẩn thận. Nếu đang dùng chữ ký số thật (VNPT SmartCA), hệ thống sẽ gửi thông báo tới điện thoại của thuê bao — cần MỞ ĐIỆN THOẠI BẤM XÁC NHẬN trong ít phút để hoàn tất.')
                                            ->action(fn (?Partner $record) => self::signAndExportContract($record)),

                                        Forms\Components\Actions\Action::make('reExportSignedPdf')
                                            ->label('Xuất lại PDF (bản mới)')
                                            ->icon('heroicon-o-document-arrow-down')
                                            ->color('gray')
                                            ->visible(fn (?Partner $record) => self::latestVersion($record)?->isPlatformSigned())
                                            ->requiresConfirmation()
                                            ->modalDescription('Hợp đồng đã ký số rồi — TẢI VỀ MÁY thêm 1 bản PDF khác (vd để gửi lại cho đối tác). LƯU Ý: tốn thêm 1 lượt ký thật MỚI (không tái dùng được chữ ký cũ, vì mỗi file PDF có hash riêng), và file cũng KHÔNG lưu trên server.')
                                            ->action(fn (?Partner $record) => self::signAndExportContract($record, forceNewSignature: true)),
                                    ])->fullWidth(),

                                    Forms\Components\Placeholder::make('e_signature_status')
                                        ->hiddenLabel()
                                        ->content(fn (?Partner $record) => new HtmlString(self::renderSignatureStatus($record ? self::latestVersion($record) : null))),
                                ]),

                            Forms\Components\Section::make('Thông tin hợp đồng')
                                ->schema([
                                    Forms\Components\Placeholder::make('contract_info_card')
                                        ->hiddenLabel()
                                        ->content(fn (?Partner $record) => new HtmlString(self::renderContractInfoCard($record, $record ? self::latestVersion($record) : null))),
                                ]),
                        ]),
                ]),

                // Đã có ít nhất 1 phiên bản hợp đồng — quay lại vị trí gốc (NẰM DƯỚI khu vực xem
                // trước/ký phía trên). Vẫn cùng 1 Section/field, chỉ khác điều kiện ->visible() với
                // bản sao ở trên — sửa xong bấm "Tạo lại & gửi ký" để áp dụng vào phiên bản mới
                // (không sửa thẳng vào bản đã ký, giữ đúng tính pháp lý của chữ ký điện tử).
                self::contractEditableFieldsSection()
                    ->visible(fn (?Partner $record) => (bool) self::latestVersion($record)),

                Forms\Components\Section::make('Lịch sử phiên bản hợp đồng')
                    ->headerActions([
                        Forms\Components\Actions\Action::make('addContractVersion')
                            ->label('Tải lên phụ lục mới')
                            ->icon('heroicon-o-plus')
                            ->visible(fn (?Partner $record) => (bool) $record)
                            ->form([
                                Forms\Components\TextInput::make('version_label')
                                    ->label('Phiên bản')
                                    ->required()
                                    ->maxLength(50),
                                Forms\Components\Textarea::make('change_note')
                                    ->label('Nội dung thay đổi')
                                    ->rows(2),
                                Forms\Components\FileUpload::make('document')
                                    ->label('Tài liệu')
                                    ->disk('public')
                                    ->acceptedFileTypes(['application/pdf', 'image/*']),
                            ])
                            ->action(function (array $data, ?Partner $record) {
                                $version = $record->contractVersions()->create([
                                    'version_label' => $data['version_label'],
                                    'change_note'   => $data['change_note'] ?? null,
                                    'changed_by'    => auth()->id(),
                                ]);

                                if (! empty($data['document'])) {
                                    $version->addMediaFromDisk($data['document'], 'public')
                                        ->toMediaCollection('document');
                                }

                                \Filament\Notifications\Notification::make()
                                    ->title('Đã thêm phiên bản hợp đồng mới')
                                    ->success()
                                    ->send();
                            }),

                        Forms\Components\Actions\Action::make('renewContract')
                            ->label('Gia hạn hợp đồng')
                            ->icon('heroicon-o-arrow-path')
                            ->color('gray')
                            ->visible(fn (?Partner $record) => (bool) $record)
                            ->requiresConfirmation()
                            ->modalDescription('Gia hạn thêm 12 tháng kể từ ngày hết hạn hiện tại (hoặc từ hôm nay nếu đã hết hạn)?')
                            ->action(function (?Partner $record) {
                                $base = $record->contract_expires_at && $record->contract_expires_at->isFuture()
                                    ? $record->contract_expires_at
                                    : now();

                                $record->update([
                                    'contract_expires_at' => $base->copy()->addYear(),
                                    'contract_status'      => 'active',
                                ]);

                                \Filament\Notifications\Notification::make()
                                    ->title('Đã gia hạn hợp đồng thêm 12 tháng')
                                    ->success()
                                    ->send();
                            }),

                        Forms\Components\Actions\Action::make('terminateContract')
                            ->label('Yêu cầu chấm dứt')
                            ->icon('heroicon-o-x-circle')
                            ->color('danger')
                            ->visible(fn (?Partner $record) => (bool) $record)
                            ->requiresConfirmation()
                            ->modalDescription('Chấm dứt hợp đồng với đối tác này? Hành động này nên đi kèm quy trình pháp lý ngoài hệ thống.')
                            ->action(function (?Partner $record) {
                                $record->update(['contract_status' => 'terminated']);

                                \Filament\Notifications\Notification::make()
                                    ->title('Đã đánh dấu hợp đồng chấm dứt')
                                    ->danger()
                                    ->send();
                            }),
                    ])
                    ->schema([
                        Forms\Components\Placeholder::make('contract_versions_table')
                            ->hiddenLabel()
                            ->content(function (?Partner $record) {
                                if (! $record) {
                                    return new HtmlString('<div class="text-sm text-gray-500 py-4">Lưu đối tác trước để xem lịch sử hợp đồng.</div>');
                                }

                                return new HtmlString(self::renderContractVersions($record->contractVersions()->with(['changedBy', 'media'])->get()));
                            })
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Section::make('Các trạng thái hợp đồng')
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        Forms\Components\Placeholder::make('contract_status_legend')
                            ->hiddenLabel()
                            ->content(new HtmlString(self::renderContractStatusLegend())),
                    ]),
            ]);
    }

    private static function renderContractVersions($versions): string
    {
        if ($versions->isEmpty()) {
            return '<div style="padding:16px;text-align:center;color:#9ca3af;font-size:0.85rem;">Chưa có phiên bản hợp đồng nào.</div>';
        }

        $rows = $versions->map(function ($version) {
            $date = $version->created_at?->format('d/m/Y');
            $who  = e($version->changedBy?->fullname ?? 'Hệ thống');
            $note = e($version->change_note ?? '—');
            $docUrl = $version->getFirstMediaUrl('document');
            $docLink = $docUrl
                ? '<a href="' . $docUrl . '" target="_blank" style="color:#2563eb;">Xem tài liệu</a>'
                : '—';

            return <<<HTML
                <tr style="border-bottom:1px solid #f1f5f9;">
                    <td style="padding:8px;font-weight:600;">{$version->version_label}</td>
                    <td style="padding:8px;white-space:nowrap;">{$date}</td>
                    <td style="padding:8px;color:#6b7280;">{$note}</td>
                    <td style="padding:8px;">{$who}</td>
                    <td style="padding:8px;">{$docLink}</td>
                </tr>
            HTML;
        })->implode('');

        return <<<HTML
            <div style="overflow-x:auto;">
                <table style="width:100%;font-size:0.85rem;text-align:left;">
                    <thead>
                        <tr style="border-bottom:2px solid #e5e7eb;color:#6b7280;">
                            <th style="padding:8px;">Phiên bản</th>
                            <th style="padding:8px;">Ngày cập nhật</th>
                            <th style="padding:8px;">Nội dung thay đổi</th>
                            <th style="padding:8px;">Người thực hiện</th>
                            <th style="padding:8px;">Tài liệu</th>
                        </tr>
                    </thead>
                    <tbody>{$rows}</tbody>
                </table>
            </div>
        HTML;
    }

    // Phiên bản hợp đồng MỚI NHẤT (dùng cho toàn bộ luồng ký điện tử — mỗi lần "Tạo & gửi hợp
    // đồng ký" tạo 1 phiên bản mới, chỉ phiên bản mới nhất mới có hiệu lực ký).
    private static function latestVersion(?Partner $record): ?PartnerContractVersion
    {
        if (! $record) {
            return null;
        }

        return $record->contractVersions()->first();
    }

    // Sinh nội dung hợp đồng từ thông tin đối tác HIỆN TẠI, tạo 1 phiên bản mới (bất biến —
    // content/content_hash không đổi sau khi tạo) kèm link ký công khai, gửi email cho đối tác.
    // Chuyển contract_status về 'pending' — đúng ý nghĩa "đang đợi các bên ký xác nhận" đã có sẵn
    // ở CONTRACT_STATUS_LABELS.
    private static function createAndSendContract(?Partner $record): void
    {
        if (! $record) {
            return;
        }

        $content = PartnerContractRenderer::render($record);
        $token   = Str::random(48);

        $record->contractVersions()->create([
            'version_label' => 'Hợp đồng điện tử — ' . now()->format('d/m/Y H:i'),
            'change_note'   => 'Tạo tự động để ký điện tử',
            'changed_by'    => auth()->id(),
            'content'       => $content,
            'content_hash'  => hash('sha256', $content),
            'signing_token' => $token,
        ]);

        $record->update(['contract_status' => 'pending']);

        // Ưu tiên email LIÊN HỆ của đối tác (record->email, khai báo ở tab Doanh nghiệp) — đây
        // mới là email giao dịch/pháp lý thật của đối tác. Email đăng nhập hệ thống (owner()->email)
        // chỉ dùng khi đối tác chưa khai báo email liên hệ riêng.
        $email   = $record->email ?? $record->owner()?->email;
        $signUrl = route('contract.sign.show', $token);

        $mailSent = false;

        if (filled($email)) {
            try {
                Mail::to($email)->send(new LockNotificationMail(
                    'Yêu cầu ký hợp đồng điện tử',
                    "<p>Vui lòng bấm vào link bên dưới để xem toàn văn và ký hợp đồng hợp tác:</p><p><a href=\"{$signUrl}\">{$signUrl}</a></p>"
                ));
                $mailSent = true;
            } catch (\Throwable $e) {
                // Bỏ qua — admin vẫn xem được link để gửi thủ công qua "Xem toàn văn hợp đồng".
            }
        }

        \Filament\Notifications\Notification::make()
            ->title($mailSent
                ? "Đã tạo hợp đồng & gửi link ký tới {$email}"
                : 'Đã tạo hợp đồng — chưa gửi được email, xem "Xem toàn văn hợp đồng" để lấy link gửi thủ công')
            ->success()
            ->send();
    }

    // Luồng ký DUY NHẤT — sau khi đối tác đã xác nhận qua OTP (partner_confirmed_at), NỀN TẢNG
    // dùng chữ ký số THẬT (VNPT SmartCA) niêm phong hợp đồng (chỉ 1 lượt ký), đồng thời xuất luôn
    // PDF đã nhúng chữ ký (PAdES) — không còn khái niệm "ký thay đối tác" (xem thảo luận: dùng
    // chứng thư số CỦA NỀN TẢNG để "ký thay" đối tác là không chính xác về bản chất pháp lý).
    //
    // File PDF được TẢI THẲNG VỀ MÁY (streamDownload) — KHÔNG lưu vào storage server, theo đúng
    // yêu cầu (tránh tích lũy file nhạy cảm trên server nếu không cần thiết).
    //
    // $forceNewSignature: dùng cho "Xuất lại PDF" khi đã ký rồi nhưng muốn có thêm 1 bản PDF khác —
    // vẫn tốn 1 lượt ký MỚI (không tránh được, mỗi PDF có hash riêng), chỉ khác là không cập nhật
    // lại platform_signed_at/contract_status (đã set từ lần ký đầu, không ghi đè).
    private static function signAndExportContract(?Partner $record, bool $forceNewSignature = false): ?\Symfony\Component\HttpFoundation\StreamedResponse
    {
        $version = self::latestVersion($record);

        if (! $record || ! $version || ! $version->isPartnerConfirmed()) {
            return null;
        }

        if (! $forceNewSignature && $version->isPlatformSigned()) {
            return null;
        }

        // QUAN TRỌNG: PDF được render/ký NGAY TRONG signAndEmbed() bên dưới, TRƯỚC KHI trạng thái
        // "đã ký" được lưu vào DB — nếu không gán tạm các giá trị này vào $version TRONG BỘ NHỚ
        // (chưa save) trước khi render, khung ký "Nền tảng" trong PDF xuất ra sẽ hiện SAI thành
        // "Chưa ký" (vì PartnerContractRenderer::renderFramed() đọc thẳng $version->isPlatformSigned()
        // tại đúng lúc render). Chỉ áp dụng cho lần ký ĐẦU (forceNewSignature=false); lần "xuất lại"
        // thì $version đã có sẵn trạng thái đã ký thật từ trước, không cần gán tạm gì thêm.
        $signingUser = auth()->user();
        $signingTime = now();

        if (! $forceNewSignature) {
            $version->platform_signed_at = $signingTime;
            $version->setRelation('platformSignedBy', $signingUser);
        }

        try {
            $service = app(\App\Services\PdfSigning\ContractPdfSigningService::class);
            $result = $service->signAndEmbed($version, [
                'role'    => 'platform',
                'name'    => $signingUser?->name,
                'user_id' => $signingUser?->id,
            ]);
        } catch (\Throwable $e) {
            report($e);
            \Filament\Notifications\Notification::make()
                ->title('Ký số & xuất PDF thất bại')
                ->body($e->getMessage())
                ->danger()
                ->send();
            return null;
        }

        if (! $forceNewSignature) {
            $version->update([
                'platform_signing_provider' => app(\App\Services\ContractSigning\Contracts\DigitalSignatureProvider::class)->name(),
                'platform_signed_at'        => $signingTime,
                'platform_signed_by'        => $signingUser?->id,
                'platform_signed_ip'        => request()->ip(),
                'platform_signed_user_agent' => (string) request()->userAgent(),
                // KHÔNG lưu platform_signature/platform_signature_certificate ở đây — chữ ký này
                // được ký trên hash của FILE PDF (ByteRange), không phải content_hash, nên không
                // khớp với cơ chế verifyPlatformSignature() cũ (tránh hiện badge "KHÔNG khớp" sai).
                // Chữ ký thật đã tự-chứa (self-contained) NGAY TRONG file PDF — kiểm tra độc lập
                // bằng NEAC/Adobe/openssl, không cần verify qua DB nữa. File không lưu server nên
                // đây cũng là LẦN DUY NHẤT có thể tải bản gốc — nhắc admin lưu lại cẩn thận.
            ]);

            $record->update([
                'contract_status'    => 'active',
                'contract_signed_at' => $signingTime,
            ]);
        }

        $fileName = "hop-dong-{$version->id}-" . $signingTime->format('YmdHis') . '.pdf';

        return response()->streamDownload(
            fn () => print($result['pdf']),
            $fileName,
            ['Content-Type' => 'application/pdf']
        );
    }

    // Tên hiển thị cho từng provider ký số — xem config/contract_signing.php. 'local' luôn phải
    // ghi rõ "chưa có giá trị pháp lý" để không ai nhầm tưởng đây là chữ ký số thật do CA cấp.
    private const SIGNING_PROVIDER_LABELS = [
        'local'        => 'Local — chữ ký test, KHÔNG có giá trị pháp lý',
        'vnpt_smartca' => 'VNPT SmartCA — chữ ký số thật',
    ];

    private static function renderSignatureStatus(?PartnerContractVersion $version): string
    {
        if (! $version) {
            return '<div style="font-size:0.8rem;color:#9ca3af;">Chưa có hợp đồng điện tử nào được tạo.</div>';
        }

        $partnerRow = $version->isPartnerConfirmed()
            ? '<span style="color:#0369a1;font-weight:700;">✓ Đã xác nhận (OTP)</span> lúc ' . e($version->partner_confirmed_at->format('H:i d/m/Y'))
                . ' bởi ' . e($version->partner_signed_by_name) . ' (IP: ' . e($version->partner_signed_ip) . ')'
            : '<span style="color:#f59e0b;font-weight:700;">Chưa xác nhận</span> — đang chờ đối tác xác nhận qua link đã gửi';

        $platformRow = $version->isPlatformSigned()
            ? '<span style="color:#10b981;font-weight:700;">✓ Đã ký số & phát hành PDF</span> lúc ' . e($version->platform_signed_at->format('H:i d/m/Y'))
                . ' bởi ' . e($version->platformSignedBy?->fullname ?? '—')
                . ' — <span style="color:#6b7280;">' . e(self::SIGNING_PROVIDER_LABELS[$version->platform_signing_provider] ?? $version->platform_signing_provider ?? '—') . '</span>'
                . '<div style="margin-top:2px;font-size:0.72rem;color:#6b7280;">🔏 File PDF đã tải về máy lúc ký — mở lại file đó để kiểm tra chữ ký độc lập (Adobe/Foxit/NEAC). File KHÔNG lưu trên server; nếu cần thêm bản, bấm "Xuất lại PDF".</div>'
            : '<span style="color:#f59e0b;font-weight:700;">Chưa ký</span>';

        return <<<HTML
            <div style="display:flex;flex-direction:column;gap:6px;font-size:0.82rem;padding:10px 0;border-top:1px dashed #e5e7eb;margin-top:8px;">
                <div><strong>Bên Đối tác:</strong> {$partnerRow}</div>
                <div><strong>Bên Nền tảng:</strong> {$platformRow}</div>
            </div>
        HTML;
    }

    private static function renderContractContentModal(?Partner $record, ?PartnerContractVersion $version): string
    {
        if (! $record || ! $version) {
            return '<div style="padding:16px;text-align:center;color:#9ca3af;">Chưa có hợp đồng nào được tạo.</div>';
        }

        $signUrl = $version->isPartnerConfirmed() ? null : route('contract.sign.show', $version->signing_token);
        $linkRow = $signUrl
            ? '<div style="margin-top:12px;padding:10px;background:#f9fafb;border-radius:8px;font-size:0.8rem;word-break:break-all;"><strong>Link ký cho đối tác:</strong> ' . e($signUrl) . '</div>'
            : '';

        $framed = PartnerContractRenderer::renderFramed($version->content, $record, $version);

        return <<<HTML
            <div style="font-size:0.9rem;">
                {$framed}
                <div style="margin-top:12px;font-size:0.72rem;color:#9ca3af;word-break:break-all;">Mã xác thực nội dung (SHA-256): {$version->content_hash}</div>
                {$linkRow}
            </div>
        HTML;
    }

    // Panel "Xem trước tài liệu" ở tab Hợp đồng — bản xem trước LUÔN LẤY ĐÚNG NGUỒN như link ký
    // thật: nếu đã tạo phiên bản (có $version) thì hiện đúng nội dung đã "chốt" (bất biến) của
    // phiên bản đó; nếu chưa từng tạo (draft, chưa gửi ký lần nào) thì hiện bản xem trước "sống"
    // sinh từ dữ liệu hiện tại của form, để admin biết trước nội dung sẽ ra sao khi bấm gửi.
    private static function renderDocumentPreview(?Partner $record): string
    {
        if (! $record) {
            return '<div style="padding:32px;text-align:center;color:#9ca3af;font-size:0.85rem;">Lưu đối tác trước để xem trước tài liệu hợp đồng.</div>';
        }

        $version = self::latestVersion($record);
        $body    = $version?->content ?? PartnerContractRenderer::render($record);

        return PartnerContractRenderer::renderFramed($body, $record, $version);
    }

    private static function renderSigningTimeline(?PartnerContractVersion $version): string
    {
        $platformNode = self::renderTimelineNode(
            'Bên A (Nền tảng)',
            $version?->isPlatformSigned() ?? false,
            $version?->isPlatformSigned() ? 'Đã ký lúc ' . $version->platform_signed_at->format('H:i, d/m/Y') : 'Đang chờ ký'
        );

        $partnerMeta = $version?->isPartnerConfirmed()
            ? 'Đã xác nhận (OTP) lúc ' . $version->partner_confirmed_at->format('H:i, d/m/Y')
            : 'ĐANG CHỜ BẠN XÁC NHẬN';

        $partnerNode = self::renderTimelineNode(
            'Bên B (Đối tác)',
            $version?->isPartnerConfirmed() ?? false,
            $partnerMeta
        );

        return "<div>{$platformNode}{$partnerNode}</div>";
    }

    private static function renderTimelineNode(string $label, bool $done, string $meta): string
    {
        $iconColor = $done ? '#10b981' : '#111827';
        $icon      = $done ? '✓' : '✎';

        return <<<HTML
            <div style="display:flex;gap:10px;align-items:flex-start;padding:6px 0;">
                <span style="width:22px;height:22px;flex-shrink:0;border-radius:9999px;background:{$iconColor};color:#fff;display:flex;align-items:center;justify-content:center;font-size:0.7rem;">{$icon}</span>
                <div>
                    <div style="font-weight:600;font-size:0.85rem;">{$label}</div>
                    <div style="font-size:0.72rem;color:#6b7280;">{$meta}</div>
                </div>
            </div>
        HTML;
    }

    private static function renderContractInfoCard(?Partner $record, ?PartnerContractVersion $version): string
    {
        if (! $record) {
            return '<div style="font-size:0.8rem;color:#9ca3af;">—</div>';
        }

        $typeLabels = ['e_contract' => 'Hợp đồng điện tử', 'paper' => 'Hợp đồng giấy'];
        $rows = [
            'Mã hợp đồng'     => e($record->contract_code ?? '(chưa cấp mã)'),
            'Loại'            => e($typeLabels[$record->contract_type] ?? '—'),
            'Tỷ lệ hoa hồng'  => self::formatCommissionRate($record->commission_rate),
            'Đối tác'         => e($record->legal_name ?? $record->name),
            'Đại diện'        => e($record->representative_name ?? '—'),
            'Thời hạn'        => ($record->contract_signed_at && $record->contract_expires_at)
                ? $record->contract_signed_at->format('d/m/Y') . ' — ' . $record->contract_expires_at->format('d/m/Y')
                : '—',
            'Ngày tạo'        => $version?->created_at?->format('d/m/Y') ?? '—',
        ];

        $rowsHtml = collect($rows)->map(fn ($value, $label) => <<<HTML
            <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #f1f5f9;font-size:0.82rem;">
                <span style="color:#6b7280;">{$label}</span>
                <span style="font-weight:600;text-align:right;">{$value}</span>
            </div>
        HTML)->implode('');

        return "<div>{$rowsHtml}</div>";
    }

    // commission_rate lưu dạng chuỗi tự do (vd "10", "10%") — tránh hiện lặp "%%" khi giá trị đã
    // có sẵn ký hiệu %.
    private static function formatCommissionRate(?string $rate): string
    {
        if (blank($rate)) {
            return '—';
        }

        return e(str_contains($rate, '%') ? $rate : $rate . '%');
    }

    private static function renderContractStatusLegend(): string
    {
        $descriptions = [
            'draft'      => 'Hợp đồng đang được soạn thảo',
            'pending'    => 'Đang đợi các bên ký xác nhận',
            'active'     => 'Hợp đồng có giá trị pháp lý hiện hành',
            'expired'    => 'Đã vượt quá ngày hết hạn ký kết',
            'terminated' => 'Đã bị hủy bỏ trước thời hạn',
        ];

        $rows = collect(self::CONTRACT_STATUS_LABELS)->map(function ($label, $key) use ($descriptions) {
            $color = self::CONTRACT_STATUS_COLORS[$key];

            return <<<HTML
                <div style="display:flex;gap:10px;align-items:flex-start;padding:8px 0;">
                    <span style="width:8px;height:8px;border-radius:9999px;background:{$color};margin-top:6px;flex-shrink:0;"></span>
                    <div>
                        <div style="font-weight:600;font-size:0.85rem;">{$label}</div>
                        <div style="font-size:0.78rem;color:#6b7280;">{$descriptions[$key]}</div>
                    </div>
                </div>
            HTML;
        })->implode('');

        return "<div>{$rows}</div>";
    }

    // ── TAB 6: Tài liệu & Xác minh ───────────────────────────────────────────
    private static function verificationTab(): Forms\Components\Tabs\Tab
    {
        return Forms\Components\Tabs\Tab::make('Tài liệu & Xác minh')
            ->icon('heroicon-o-document-check')
            ->schema([
                Forms\Components\Section::make('Trạng thái xác minh hồ sơ')
                    ->description('Tổng trạng thái xác minh — đổi qua nút "Phê duyệt chính thức" / "Tạm dừng hồ sơ" ở đầu trang, không sửa trực tiếp ở đây.')
                    ->schema([
                        Forms\Components\Placeholder::make('verification_status_badge')
                            ->hiddenLabel()
                            ->content(function (?Partner $record) {
                                $labels = [
                                    'pending'   => ['Chờ phê duyệt', '#f59e0b'],
                                    'approved'  => ['Đang hoạt động', '#10b981'],
                                    'suspended' => ['Ngừng hoạt động', '#9ca3af'],
                                    'rejected'  => ['Từ chối', '#ef4444'],
                                ];
                                $status = $record?->verification_status ?? 'pending';
                                [$label, $color] = $labels[$status] ?? [$status, '#9ca3af'];

                                return new HtmlString(
                                    '<span style="display:inline-block;padding:4px 12px;border-radius:9999px;background:' . $color . '22;color:' . $color . ';font-weight:700;font-size:0.85rem;">'
                                    . mb_strtoupper($label) . '</span>'
                                );
                            }),
                    ]),

                Forms\Components\Section::make('Tài liệu xác minh doanh nghiệp')
                    ->description('Xác minh thủ công bởi super_admin dựa trên tài liệu tải lên — chưa tích hợp quét OCR tự động.')
                    ->schema([
                        Forms\Components\Placeholder::make('verification_checklist')
                            ->hiddenLabel()
                            ->content(new HtmlString(
                                '<ul style="font-size:0.85rem;color:#4b5563;margin:0;padding-left:18px;list-style:disc;">'
                                . '<li>Giấy phép kinh doanh (GPKD)</li>'
                                . '<li>Giấy chứng nhận đăng ký thuế</li>'
                                . '<li>Giấy tờ pháp lý khác (nếu có)</li>'
                                . '</ul>'
                            )),

                        SpatieMediaLibraryFileUpload::make('verification_documents')
                            ->label('Tải lên tài liệu')
                            ->collection('verification_documents')
                            ->multiple()
                            ->reorderable()
                            ->acceptedFileTypes(['application/pdf', 'image/*'])
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    // ── TAB 7: Lịch sử ───────────────────────────────────────────────────────
    private static function historyTab(): Forms\Components\Tabs\Tab
    {
        return Forms\Components\Tabs\Tab::make('Lịch sử')
            ->icon('heroicon-o-clock')
            ->schema([
                Forms\Components\Placeholder::make('status_history')
                    ->hiddenLabel()
                    ->content(function (?Partner $record) {
                        if (! $record) {
                            return new HtmlString('<div class="text-sm text-gray-500 py-4">Lưu đối tác trước để xem lịch sử.</div>');
                        }

                        $logs = $record->statusLogs()->with('changedBy')->limit(30)->get();

                        if ($logs->isEmpty()) {
                            return new HtmlString('<div class="text-sm text-gray-500 py-4">Chưa có thay đổi trạng thái nào được ghi nhận.</div>');
                        }

                        $rows = $logs->map(function ($log) {
                            $time = $log->created_at?->format('H:i d/m/Y');
                            $who  = e($log->changedBy?->fullname ?? 'Hệ thống');
                            $note = e($log->note ?? '');

                            return <<<HTML
                                <tr style="border-bottom:1px solid #f1f5f9;">
                                    <td style="padding:8px;white-space:nowrap;">{$time}</td>
                                    <td style="padding:8px;">{$log->from_status} → <strong>{$log->to_status}</strong></td>
                                    <td style="padding:8px;">{$who}</td>
                                    <td style="padding:8px;color:#6b7280;">{$note}</td>
                                </tr>
                            HTML;
                        })->implode('');

                        return new HtmlString(<<<HTML
                            <div style="overflow-x:auto;">
                                <table style="width:100%;font-size:0.85rem;text-align:left;">
                                    <thead>
                                        <tr style="border-bottom:2px solid #e5e7eb;color:#6b7280;">
                                            <th style="padding:8px;">Thời gian</th>
                                            <th style="padding:8px;">Thay đổi trạng thái</th>
                                            <th style="padding:8px;">Người thực hiện</th>
                                            <th style="padding:8px;">Ghi chú</th>
                                        </tr>
                                    </thead>
                                    <tbody>{$rows}</tbody>
                                </table>
                            </div>
                        HTML);
                    })
                    ->columnSpanFull(),
            ]);
    }
}
