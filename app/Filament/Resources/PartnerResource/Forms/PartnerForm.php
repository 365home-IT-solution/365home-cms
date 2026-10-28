<?php

declare(strict_types=1);

namespace App\Filament\Resources\PartnerResource\Forms;

use App\Filament\Resources\PartnerResource;
use App\Mail\LockNotificationMail;
use App\Models\Partner;
use App\Models\PartnerContractVersion;
use App\Models\User;
use App\Services\ContractSigning\Contracts\DigitalSignatureProvider;
use App\Services\PartnerLegalDocumentService;
use App\Services\PdfSigning\ContractPdfSigningService;
use App\Support\PartnerContractRenderer;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Category\Entities\Category;
use Modules\DataPermission\Entities\UserBranchPermission;
use Modules\Minihouse\App\Filament\Resources\BuildingResource;
use Modules\Payment\Entities\Order;
use Modules\Product\App\Models\Product;
use Symfony\Component\HttpFoundation\StreamedResponse;

// Form "Xác minh đối tác" cho super_admin — 7 tab đúng theo mockup: Người đại diện / Doanh
// nghiệp / Tài chính / Cơ sở lưu trú / Hợp đồng / Tài liệu & Xác minh / Lịch sử. Dùng component
// Filament chuẩn (đồng bộ theme với toàn bộ admin), không phải clone HTML/CSS riêng.
class PartnerForm
{
    public static function form(Form $form): Form
    {
        $isMinihouse = Filament::getCurrentPanel()?->getId() === 'minihouse-admin';

        return $form->schema([
            self::dossierScanSection($isMinihouse),

            Forms\Components\Tabs::make('partner_tabs')
                ->tabs([
                    self::representativeTab(),
                    self::subscriptionTab($isMinihouse),
                    self::businessTab(),
                    self::financialTab(),
                    ...($isMinihouse ? [] : [self::propertiesTab()]),
                    self::branchAssignmentTab(),
                    self::userAssignmentTab(),
                    // MiniHouse chỉ mua gói: ẩn Hợp đồng + Tài liệu & Xác minh (code giữ nguyên, bật lại bằng MINIHOUSE_CONTRACT_ENABLED).
                    ...(($isMinihouse && ! config('partner_flow.minihouse_contract_enabled')) ? [] : [self::contractTab(), self::verificationTab()]),
                    self::historyTab(),
                ])
                ->columnSpanFull(),
        ]);
    }

    // ── TAB: Gói dịch vụ (CHỈ lúc tạo) — chọn gói dùng thử cho đối tác mới ──────
    private static function subscriptionTab(bool $isMinihouse): Forms\Components\Tabs\Tab
    {
        $type = $isMinihouse ? Partner::TYPE_MINIHOUSE : Partner::TYPE_HOMESTAY;

        return Forms\Components\Tabs\Tab::make('Gói dịch vụ')
            ->icon('heroicon-o-credit-card')
            ->visible(fn (string $operation) => $operation === 'create' && $type === Partner::TYPE_MINIHOUSE)
            ->schema([
                Forms\Components\Section::make('Gói dùng thử')
                    ->description('Đối tác mới được dùng thử miễn phí theo số tháng ghi trên gói đã chọn; sau đó phải thanh toán để tiếp tục. Đổi gói/hạn sau này ở Quản lý → Gói của đối tác.')
                    ->schema([
                        Forms\Components\Select::make('trial_plan_id')
                            ->label('Gói dùng thử')
                            ->options(fn () => \App\Models\SubscriptionPlan::query()->where('is_active', true)->where('partner_type', $type)->orderBy('sort_order')->get()
                                ->mapWithKeys(fn ($p) => [$p->id => $p->name . ' — dùng thử ' . ($p->trial_months > 0 ? $p->trial_months : config('subscription.default_trial_months', 3)) . ' tháng' . ($p->is_default ? ' (mặc định)' : '')])->all())
                            ->default(fn () => \App\Models\SubscriptionPlan::query()->where('is_active', true)->where('is_default', true)->where('partner_type', $type)->value('id'))
                            ->native(false)
                            ->helperText('Mặc định là gói đang đặt "Gói mặc định cho đối tác mới". Có thể chọn gói khác.'),
                    ]),
            ]);
    }

    // Áp dụng gói dùng thử đã chọn (nếu khác gói mặc định đã tự gán lúc tạo).
    public static function applyTrialPlan(\Illuminate\Database\Eloquent\Model $partner, ?int $planId): void
    {
        if (! $planId) {
            return;
        }

        try {
            app(\App\Services\SubscriptionService::class)->startTrialWithPlan($partner, \App\Models\SubscriptionPlan::findOrFail($planId));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    // ── TAB 1: Người đại diện ────────────────────────────────────────────────
    // ── Tải hồ sơ để TỰ ĐIỀN (chỉ khi tạo đối tác Homestay) ────────────────────────────────────────────
    // GPKD + CCCD hai mặt + giấy An ninh trật tự → App\Services\PartnerDossierScanner (OCR.space cho GPKD/ANTT, mã QR cho CCCD)
    // → điền nháp các ô của hồ sơ, kèm cảnh báo đối chiếu chéo. Chỉ gợi ý: người nhập kiểm tra rồi mới bấm Tạo.
    private static function dossierScanSection(bool $isMinihouse): Forms\Components\Section
    {
        $upload = fn (string $name, string $label, array $types) => Forms\Components\FileUpload::make($name)
            ->label($label)
            ->acceptedFileTypes($types)
            ->maxSize(10240)
            ->storeFiles(false)
            ->dehydrated(false);

        $image = ['image/jpeg', 'image/png', 'image/webp'];

        return Forms\Components\Section::make('Tải hồ sơ để tự điền')
            ->description('Tải Giấy phép kinh doanh, CCCD (2 mặt) và Giấy An ninh trật tự rồi bấm "Quét và điền": hệ thống đọc giấy tờ và điền nháp các ô bên dưới. Chỉ là gợi ý — kiểm tra lại từng ô trước khi tạo đối tác.')
            ->icon('heroicon-o-document-magnifying-glass')
            ->collapsible()
            ->visible(fn (string $operation) => $operation === 'create' && ! $isMinihouse)
            ->schema([
                Forms\Components\Grid::make(2)->schema([
                    $upload('dossier_gpkd', 'Giấy phép kinh doanh', [...$image, 'application/pdf']),
                    $upload('dossier_antt', 'Giấy An ninh trật tự', [...$image, 'application/pdf']),
                    $upload('dossier_cccd_front', 'CCCD — mặt trước', $image),
                    $upload('dossier_cccd_back', 'CCCD — mặt sau', $image),
                ]),

                Forms\Components\Actions::make([
                    Forms\Components\Actions\Action::make('scanDossier')
                        ->label('Quét và điền')
                        ->icon('heroicon-o-sparkles')
                        ->action(function (Forms\Get $get, Forms\Set $set) {
                            $file = fn (string $key) => self::firstUploadedFile($get($key));
                            $files = array_filter([
                                'gpkd'       => $file('dossier_gpkd'),
                                'cccd_front' => $file('dossier_cccd_front'),
                                'cccd_back'  => $file('dossier_cccd_back'),
                                'antt'       => $file('dossier_antt'),
                            ]);

                            if ($files === []) {
                                Notification::make()->title('Tải lên ít nhất một giấy tờ trước.')->warning()->send();

                                return;
                            }

                            $scanner = app(\App\Services\PartnerDossierScanner::class);

                            if (! $scanner->isConfigured() && (isset($files['gpkd']) || isset($files['antt']))) {
                                Notification::make()->title('Chưa cấu hình OCR.space nên chưa quét được GPKD / An ninh trật tự. Có thể quét riêng CCCD hoặc tự nhập.')->warning()->send();

                                return;
                            }

                            $result = $scanner->scan($files);
                            $filled = [];

                            foreach ($result['fields'] as $field => $value) {
                                if (filled($value)) {
                                    $set($field, $value);
                                    $filled[] = $result['labels'][$field] ?? $field;
                                }
                            }

                            $html = '<div style="font-size:0.85rem;line-height:1.5">'
                                . '<strong>Đã tự điền ' . count($filled) . '/' . count($result['fields']) . ' ô:</strong> ' . e(implode(', ', $filled) ?: '—');

                            if ($result['warnings'] !== []) {
                                $html .= '<ul style="margin:8px 0 0 18px;list-style:disc;color:#b45309">'
                                    . implode('', array_map(fn (string $w) => '<li>' . e($w) . '</li>', $result['warnings'])) . '</ul>';
                            }

                            $set('dossier_report', $html . '</div>');
                            // Giữ kết quả quét từng giấy để lúc bấm Tạo đưa giấy tờ vào hồ sơ pháp lý (không phải quét lại).
                            $set('dossier_scans', json_encode($result['scans'] ?? [], JSON_UNESCAPED_UNICODE));

                            Notification::make()
                                ->title('Đã quét hồ sơ: điền ' . count($filled) . ' ô')
                                ->body($result['warnings'] === [] ? 'Kiểm tra lại các ô trước khi tạo đối tác.' : 'Có ' . count($result['warnings']) . ' cảnh báo — xem bên dưới khu vực tải hồ sơ.')
                                ->color($result['warnings'] === [] ? 'success' : 'warning')
                                ->send();
                        }),
                ]),

                Forms\Components\Hidden::make('dossier_report')->dehydrated(false),
                Forms\Components\Hidden::make('dossier_scans')->dehydrated(false),
                Forms\Components\Placeholder::make('dossier_report_view')
                    ->hiddenLabel()
                    ->content(fn (Forms\Get $get) => new HtmlString((string) ($get('dossier_report') ?? '')))
                    ->visible(fn (Forms\Get $get) => filled($get('dossier_report'))),
            ]);
    }

    /** Tệp đầu tiên của ô FileUpload (state có thể là TemporaryUploadedFile hoặc chuỗi "livewire-file:…"). */
    public static function firstUploadedFile(mixed $state): ?\Illuminate\Http\UploadedFile
    {
        $item = is_array($state) ? reset($state) : $state;

        if ($item instanceof \Illuminate\Http\UploadedFile) {
            return $item;
        }

        if (is_string($item) && str_starts_with($item, 'livewire-file:')) {
            return \Livewire\Features\SupportFileUploads\TemporaryUploadedFile::createFromLivewire(substr($item, 14));
        }

        return null;
    }

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
                                ->displayFormat('d/m/Y')
                                ->maxDate(now()->subYears(18))
                                ->validationMessages(['before_or_equal' => 'Người đại diện phải đủ 18 tuổi.']),

                            Forms\Components\TextInput::make('representative_position')
                                ->label('Chức vụ')
                                ->placeholder('Chủ cơ sở')
                                ->maxLength(100),

                            Forms\Components\TextInput::make('representative_id_number')
                                ->label('Số CMND/CCCD/Hộ chiếu')
                                ->maxLength(20)
                                ->regex('/^[A-Za-z0-9]{6,20}$/')
                                ->validationMessages(['regex' => 'Số giấy tờ gồm 6–20 chữ/số, không dấu cách (CMND 9 số, CCCD 12 số).']),

                            Forms\Components\DatePicker::make('representative_id_issued_at')
                                ->label('Ngày cấp CMND/CCCD')
                                ->native(false)
                                ->displayFormat('d/m/Y')
                                ->maxDate(now())
                                ->validationMessages(['before_or_equal' => 'Ngày cấp không được ở tương lai.']),

                            Forms\Components\TextInput::make('representative_id_issued_place')
                                ->label('Nơi cấp CMND/CCCD')
                                ->placeholder('Cục Cảnh sát QLHC về TTXH')
                                ->maxLength(255),

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
                                ->maxLength(20)
                                ->regex('/^(0|\+84)[0-9]{9,10}$/')
                                ->validationMessages(['regex' => 'Số điện thoại phải dạng 0xxxxxxxxx hoặc +84xxxxxxxxx.']),

                            Forms\Components\TextInput::make('representative_phone_secondary')
                                ->label('Số điện thoại - phụ')
                                ->tel()
                                ->maxLength(20)
                                ->regex('/^(0|\+84)[0-9]{9,10}$/')
                                ->validationMessages(['regex' => 'Số điện thoại phải dạng 0xxxxxxxxx hoặc +84xxxxxxxxx.']),
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

                            Forms\Components\TextInput::make('common_name')
                                ->label('Tên thường gọi')
                                ->placeholder('Vd: RinBon Home')
                                ->helperText('Nhập tay, hiện ở cột "Tên thường gọi" của danh sách đối tác.')
                                ->maxLength(255),

                            Forms\Components\TextInput::make('manual_contract_no')
                                ->label('Số hợp đồng (nhập tay)')
                                ->helperText('Nhập tay để tra cứu ở danh sách đối tác. Không liên quan đến mã hợp đồng do hệ thống cấp ở tab Hợp đồng.')
                                ->maxLength(100),

                            Forms\Components\TextInput::make('tax_code')
                                ->label('Mã số thuế')
                                ->maxLength(14)
                                ->regex('/^(\d{10}(-?\d{3})?|\d{12})$/')
                                ->validationMessages(['regex' => 'Mã số thuế gồm 10 số (doanh nghiệp), 13 số (đơn vị phụ thuộc, vd 0312345678-001) hoặc 12 số (cá nhân / hộ kinh doanh dùng số CCCD).']),

                            Forms\Components\DatePicker::make('business_license_date')
                                ->label('Ngày cấp GPKD')
                                ->native(false)
                                ->displayFormat('d/m/Y')
                                ->maxDate(now())
                                ->validationMessages(['before_or_equal' => 'Ngày cấp GPKD không được ở tương lai.']),

                            Forms\Components\TextInput::make('business_license_issuer')
                                ->label('Nơi cấp')
                                ->maxLength(255),
                        ]),

                        Forms\Components\Select::make('province_code')
                            ->label('Tỉnh/thành phố của cơ sở kinh doanh')
                            ->options(fn () => \App\Models\Province::query()->whereNotNull('code')->orderBy('name')->pluck('name', 'code')->all())
                            ->searchable()->preload()->native(false)
                            ->helperText('Lấy từ form đăng ký. Quyết định đối tác có được miễn phí tháng đầu không (xem Cấu hình web › Ký quỹ); sửa ở đây nếu đăng ký thiếu hoặc sai.')
                            ->columnSpanFull(),

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
                    ->description(fn () => Filament::getCurrentPanel()?->getId() === 'minihouse-admin'
                        ? 'Dùng cho đối soát và thanh toán giữa nền tảng với Partner. Tiền thuê của khách được cấu hình riêng tại từng tòa nhà.'
                        : 'Dùng cho đối soát và thanh toán giữa nền tảng với Partner Homestay.')
                    ->schema([
                        Forms\Components\Grid::make(2)->schema([
                            Forms\Components\Grid::make(1)->schema([
                                Forms\Components\Select::make('bank_name')
                                    ->label('Ngân hàng')
                                    ->options(fn () => \App\Support\Banks::options())
                                    ->searchable()
                                    ->native(false)
                                    ->placeholder('Chọn ngân hàng')
                                    ->helperText('Chọn trong danh sách ngân hàng (không nhập tay).'),
                                Forms\Components\TextInput::make('bank_branch')->label('Chi nhánh')->maxLength(255),
                                Forms\Components\TextInput::make('bank_account_number')->label('Số tài khoản')->maxLength(20)->regex('/^[0-9]{6,20}$/')->validationMessages(['regex' => 'Số tài khoản chỉ gồm 6–20 chữ số.']),
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
                                'weekly' => 'Hàng tuần (Thứ 2)',
                                'biweekly' => '2 tuần một lần (Ngày 1 và 15)',
                                'monthly' => 'Hàng tháng (Ngày cuối tháng)',
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
        return Forms\Components\Tabs\Tab::make(Filament::getCurrentPanel()?->getId() === 'minihouse-admin' ? 'Tòa nhà MiniHouse' : 'Cơ sở lưu trú')
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
                                ->afterStateUpdated(fn ($state, callable $set) => $set('slug', Str::slug($state))),
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
                                'partner_id' => $record->id,
                            ]);

                            Notification::make()
                                ->title('Đã thêm cơ sở lưu trú mới')
                                ->success()
                                ->send();

                            $resource = Filament::getCurrentPanel()?->getId() === 'minihouse-admin'
                                ? \Modules\Minihouse\App\Filament\Resources\PartnerResource::class
                                : PartnerResource::class;
                            $livewire->redirect($resource::getUrl('edit', ['record' => $record]));
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

                        $totalRooms = Product::where('partner_id', $record->id)->count();
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
            .$statCard('Đang hoạt động', $activeCount)
            .$statCard('Tổng số phòng', $totalRooms);

        if ($branches->isEmpty()) {
            $rows = '<tr><td colspan="6" style="padding:16px;text-align:center;color:#9ca3af;">Chưa có cơ sở lưu trú nào.</td></tr>';
        } else {
            $rows = $branches->map(function ($branch) {
                $status = $branch->status
                    ? '<span style="color:#059669;font-weight:600;">● Hoạt động</span>'
                    : '<span style="color:#9ca3af;font-weight:600;">● Tạm dừng</span>';

                // 'amount' là tổng thực thu cuối cùng (gồm cả phụ phí phát sinh sau khi đặt/thanh
                // toán, vd dịch vụ thêm) — 'full_amount' chỉ là tổng giá gốc CỐ ĐỊNH lúc đặt, không
                // phản ánh phát sinh sau này. Ưu tiên 'amount', fallback full_amount nếu amount rỗng
                // (dữ liệu cũ) — cùng quy ước với KpiService/Dashboard, tránh doanh thu hiển thị ở
                // đây lệch với doanh thu ở Dashboard cho cùng kỳ/cùng đối tác.
                $revenue = Order::where('category_id', $branch->id)
                    ->where('status', 'paid')
                    ->whereMonth('created_at', now()->month)
                    ->whereYear('created_at', now()->year)
                    ->sum(DB::raw('COALESCE(amount, full_amount)'));
                $revenueFmt = number_format((float) $revenue, 0, ',', '.').'đ';

                $detailUrl = Filament::getCurrentPanel()?->getId() === 'minihouse-admin'
                    ? BuildingResource::getUrl('edit', ['record' => $branch->id])
                    : PartnerResource::getUrl('branch-detail', ['record' => $branch->partner_id, 'branch' => $branch->id]);

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
        return Forms\Components\Tabs\Tab::make(Filament::getCurrentPanel()?->getId() === 'minihouse-admin' ? 'Gán tòa nhà' : 'Chi nhánh')
            ->icon('heroicon-o-map-pin')
            ->schema([
                Forms\Components\Placeholder::make('branch_assignment_note')
                    ->hiddenLabel()
                    ->content('Chọn các chi nhánh đã có sẵn trong hệ thống để gán cho đối tác này. Có thể chọn cả chi nhánh đang thuộc đối tác khác — khi lưu, chi nhánh đó (và toàn bộ phòng/lịch đặt/mã cổng bên trong) sẽ được CHUYỂN sang đối tác hiện tại.'),

                Forms\Components\CheckboxList::make('branch_ids')
                    ->hiddenLabel()
                    ->options(fn (?Partner $record) => ($record ?? new Partner([
                        'partner_type' => Filament::getCurrentPanel()?->getId() === 'minihouse-admin' ? Partner::TYPE_MINIHOUSE : Partner::TYPE_HOMESTAY,
                    ]))
                        ->assignableBranchesQuery()
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
                        ->where(function ($query) {
                            $type = Filament::getCurrentPanel()?->getId() === 'minihouse-admin' ? Partner::TYPE_MINIHOUSE : Partner::TYPE_HOMESTAY;
                            $query->whereNull('partner_id')->orWhereHas('partner', fn ($partnerQuery) => $partnerQuery->where('partner_type', $type));
                        })
                        ->orderBy('fullname')
                        ->get()
                        ->mapWithKeys(fn (User $user) => [$user->id => ($user->fullname ?: '(chưa đặt tên)').' — '.$user->email])
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
        $branchIds = $record->assignableBranchesQuery()->whereIn('id', $branchIds)
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
        // Chi nhánh bị bỏ gán: Homestay → chưa có chủ (null); MiniHouse → về đối tác MiniHouse nội
        // bộ (tòa nhà không được mồ côi). Chính đối tác nội bộ thì bỏ gán = giữ nguyên.
        $releasedPartnerId = $record->releasedBranchPartnerId();
        $userIds = User::query()->whereIn('id', $userIds)
            ->where(fn ($query) => $query->whereNull('partner_id')->orWhereHas('partner', fn ($partnerQuery) => $partnerQuery->where('partner_type', $record->partner_type)))
            ->pluck('id')->all();

        Category::query()
            ->where('category_type', 'product')
            ->whereNull('parent_id')
            ->where(fn ($q) => $q->where('partner_id', $record->id)->orWhereIn('id', $branchIds))
            ->get()
            ->each(function (Category $category) use ($record, $branchIds, $releasedPartnerId) {
                $newPartnerId = in_array((int) $category->id, $branchIds, true) ? $record->id : $releasedPartnerId;

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
                // Xoá từng bản ghi (không dùng ::delete() hàng loạt) để
                // UserBranchPermissionObserver::deleted() bắn đúng, ghi lại audit log.
                $query = UserBranchPermission::where('user_id', $user->id);

                if ($newPartnerId === null) {
                    $query->get()->each->delete();
                } else {
                    $query->whereHas('branch', fn ($bq) => $bq->where('partner_id', '!=', $newPartnerId))
                        ->get()->each->delete();
                }
            });
    }

    // ── TAB 5: Hợp đồng ──────────────────────────────────────────────────────
    private const CONTRACT_STATUS_LABELS = [
        'draft' => 'Bản nháp (Draft)',
        'pending' => 'Chờ ký (Pending)',
        'active' => 'Đang hiệu lực (Active)',
        'expired' => 'Hết hạn (Expired)',
        'terminated' => 'Chấm dứt (Terminated)',
    ];

    private const CONTRACT_STATUS_COLORS = [
        'draft' => '#9ca3af',
        'pending' => '#f59e0b',
        'active' => '#10b981',
        'expired' => '#ef4444',
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
                        ->placeholder('Để trống = tự sinh khi tạo hợp đồng')
                        ->disabled(fn (?Partner $record) => $record?->contract_status === 'active')
                        ->dehydrateStateUsing(fn (?string $state) => \App\Services\ContractCodeService::normalize($state))
                        ->rules([fn (?Partner $record) => function (string $attribute, $value, \Closure $fail) use ($record) {
                            if ($error = \App\Services\ContractCodeService::inputError($value, $record)) {
                                $fail($error);
                            }
                        }])
                        ->helperText('Nhập số thứ tự (vd 010) hoặc đủ mã 010/2026/HĐHT-365. Để trống thì hệ thống tự cấp số kế tiếp khi tạo hợp đồng. Đổi mã xong cần "Tạo lại & gửi ký" để áp dụng vào hợp đồng.'),

                    Forms\Components\Select::make('contract_type')
                        ->label('Hình thức hợp đồng')
                        ->options([
                            'e_contract' => 'Hợp đồng điện tử (E-Contract)',
                            'paper' => 'Hợp đồng giấy',
                        ]),

                    Forms\Components\DatePicker::make('contract_signed_at')
                        ->label('Ngày ký kết')
                        ->native(false)
                        ->displayFormat('d/m/Y'),

                    Forms\Components\DatePicker::make('contract_expires_at')
                        ->label('Ngày hết hạn')
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->helperText('Không bắt buộc — để trống thì hợp đồng có thời hạn 12 tháng kể từ ngày ký (Điều 7) và ngày hết hạn tự tính khi hợp đồng có hiệu lực.')
                        ->afterOrEqual('contract_signed_at')
                        ->validationMessages(['after_or_equal' => 'Ngày hết hạn phải sau hoặc bằng ngày ký kết.']),
                ]),

                Forms\Components\Select::make('contract_status')
                    ->label('Trạng thái hợp đồng')
                    ->options(self::CONTRACT_STATUS_LABELS)
                    ->default('draft')
                    ->disabled(fn (?Partner $record) => filled($record?->onboarding_token) && $record->contract_type !== 'paper')
                    ->helperText(fn (?Partner $record) => filled($record?->onboarding_token) && $record->contract_type !== 'paper' ? 'Hồ sơ đăng ký trên website: trạng thái do hệ thống cập nhật theo luồng ký (không chỉnh tay).' : null),

                SpatieMediaLibraryFileUpload::make('contract_file')
                    ->label('Tệp hợp đồng (bản giấy/scan, nếu có)')
                    ->collection('contract_file')
                    ->acceptedFileTypes(['application/pdf', 'image/*'])
                    ->columnSpanFull(),

                Forms\Components\Grid::make(2)->schema([
                    Forms\Components\TextInput::make('commission_rate')
                        ->label('Tỷ lệ hoa hồng (%)')
                        ->default(fn () => config('partner_flow.default_commission_rate'))
                        ->helperText('Số từ 0 đến 100 (vd 10 hoặc 10%). Mặc định 20%, bắt buộc với Homestay trước khi tạo hợp đồng. Áp dụng cho tất cả các loại phòng và dịch vụ cộng thêm.')
                        ->rules([fn () => function (string $attribute, $value, \Closure $fail) {
                            if (filled($value) && app(\App\Services\PartnerContractWorkflowService::class)->commissionValue((string) $value) === null) {
                                $fail('Tỷ lệ hoa hồng phải là số từ 0 đến 100 (vd 10 hoặc 10%).');
                            }
                        }]),

                    // Mức ký quỹ tối thiểu đi cùng điều khoản hoa hồng/thời hạn — lưu qua EscrowService::setMinAmount() (xem EditPartner::afterSave()), không ghi thẳng
                    // vào cột vì số dư/trạng thái khoá bán phải được tính lại. Để trống = bỏ áp dụng ký quỹ.
                    Forms\Components\TextInput::make('escrow_min_amount')
                        ->label('Mức ký quỹ tối thiểu (đ)')
                        ->numeric()->minValue(0)->suffix('đ')
                        ->visible(fn (?Partner $record) => $record !== null && ! $record->isMinihouse() && ! $record->isSystemPartner())
                        ->helperText(fn (?Partner $record) => $record ? 'Gợi ý theo số phòng đang bán: ' . number_format(app(\App\Services\EscrowService::class)->suggestedMinAmount($record), 0, ',', '.') . 'đ. Đối tác mới phải nạp đủ trước khi mở bán online; đối tác đang hoạt động có ' . config('escrow.initial_grace_days') . ' ngày. Số dư và sổ bút toán xem ở mục "Ký quỹ đối tác".' : null),

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
                        .'<strong>Lưu ý về tính pháp lý:</strong> Hợp đồng là căn cứ pháp lý duy nhất để bảo vệ quyền lợi của đối tác. '
                        .'Mọi giao dịch và cam kết cần được văn bản hóa chính thức tại tab này.'
                        .'</div>'
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
                                ->modalWidth(MaxWidth::Large)
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
                                    .self::renderDocumentPreview($record)
                                    .'</div>'
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
                                    Forms\Components\Placeholder::make('legal_document_verification')
                                        ->label('Xác minh giấy tờ')
                                        ->content(function (?Partner $record) {
                                            if (! $record) {
                                                return 'Chưa có hồ sơ đối tác.';
                                            }

                                            $readiness = app(PartnerLegalDocumentService::class)->readiness($record);
                                            if ($record->verification_status === 'approved' && $readiness['ready']) {
                                                return new HtmlString('<span style="color:#059669;font-weight:700;">✓ Super Admin đã duyệt — được phép chuyển sang ký hợp đồng</span>');
                                            }

                                            $reason = e(implode(' ', $readiness['problems'] ?: ['Đang chờ Super Admin phê duyệt hồ sơ.']));

                                            return new HtmlString('<span style="color:#dc2626;font-weight:700;">Chưa đạt</span><div style="font-size:0.78rem;color:#6b7280;margin-top:4px;">'.$reason.'</div>');
                                        }),

                                    Forms\Components\Placeholder::make('contract_status_badge')
                                        ->hiddenLabel()
                                        ->content(function (?Partner $record) {
                                            $status = $record?->contract_status ?? 'draft';
                                            $label = self::CONTRACT_STATUS_LABELS[$status] ?? $status;
                                            $color = self::CONTRACT_STATUS_COLORS[$status] ?? '#9ca3af';
                                            $daysLeft = $record?->contract_expires_at
                                                ? ' (còn '.max(0, (int) now()->diffInDays($record->contract_expires_at, false)).' ngày)'
                                                : '';

                                            return new HtmlString(
                                                '<span style="display:inline-block;padding:4px 12px;border-radius:9999px;background:'.$color.'22;color:'.$color.';font-weight:700;font-size:0.85rem;">'
                                                .mb_strtoupper($label).$daysLeft
                                                .'</span>'
                                            );
                                        }),

                                    Forms\Components\Actions::make([
                                        Forms\Components\Actions\Action::make('createAndSendContract')
                                            ->label(fn (?Partner $record) => self::latestVersion($record) ? 'Tạo lại & gửi ký' : 'Tạo & gửi hợp đồng ký')
                                            ->icon('heroicon-o-paper-airplane')
                                            // Luồng "đối tác ký trước" (Partner::signsBeforeReview): đối tác đã ký bản điều khoản chuẩn TRƯỚC khi duyệt, nên vẫn cho
                                            // tạo lại (vd sau khi sửa hoa hồng/thời hạn) cho tới khi nền tảng ký — chữ ký cũ mất hiệu lực, đối tác ký lại bản mới.
                                            ->color(fn (?Partner $record) => self::latestVersion($record)?->isPartnerConfirmed() ? 'warning' : 'primary')
                                            ->visible(fn (?Partner $record) => $record && ! self::latestVersion($record)?->isPlatformSigned()
                                                && (! self::latestVersion($record)?->isPartnerConfirmed() || $record->signsBeforeReview()))
                                            ->disabled(fn (?Partner $record) => ! $record || ! app(PartnerLegalDocumentService::class)->isContractEligible($record))
                                            ->requiresConfirmation()
                                            ->modalHeading(fn (?Partner $record) => self::latestVersion($record)?->isPartnerConfirmed() ? 'Đối tác ĐÃ KÝ bản hiện tại — tạo lại hợp đồng?' : 'Tạo & gửi hợp đồng ký')
                                            ->modalDescription('Hệ thống sẽ tạo bản hợp đồng điện tử từ đúng thông tin đối tác hiện tại (điều khoản hoa hồng, chính sách hủy...) và gửi link ký cho đối tác qua email. Kiểm tra kỹ thông tin trước khi gửi — mỗi lần gửi sẽ tạo 1 phiên bản mới, hủy hiệu lực link ký cũ. Mã số hợp đồng tự sinh; thiếu tỷ lệ hoa hồng (Homestay) hoặc ngày hết hạn thì hệ thống không cho tạo. Khi đối tác đã xác nhận, không tạo lại được nữa.')
                                            ->action(fn (?Partner $record) => self::createAndSendContract($record)),

                                        // Đối tác đã ký hợp đồng mẫu cũ (365home thu hộ): phụ lục "Ký quỹ và thanh toán" ký bằng đúng luồng OTP hiện có;
                                        // chỉ khi phụ lục có hiệu lực (nền tảng ký) đối tác mới chuyển sang luồng tiền đặt phòng về thẳng tài khoản của mình.
                                        Forms\Components\Actions\Action::make('createAddendum')
                                            ->label('Tạo & gửi phụ lục ký quỹ')
                                            ->icon('heroicon-o-document-plus')
                                            ->color('warning')
                                            ->visible(fn (?Partner $record) => $record && ! $record->isMinihouse() && $record->contract_status === 'active' && filled($record->contract_signed_at) && ! $record->usesDirectPayment())
                                            ->requiresConfirmation()
                                            ->modalHeading('Tạo phụ lục Ký quỹ & thanh toán')
                                            ->modalDescription('Phụ lục sửa điều thanh toán (tiền đặt phòng về thẳng đối tác, đối soát hoa hồng theo kỳ) và thêm điều Ký quỹ. Link ký được gửi qua email đối tác, ký bằng OTP như hợp đồng. Chỉ khi phụ lục có hiệu lực (nền tảng ký số) đối tác mới chuyển sang luồng tiền mới; trước đó 365home vẫn thu hộ.')
                                            ->action(fn (?Partner $record) => self::createAddendum($record)),

                                        // Riêng đối tác Về Nhà (ký hợp đồng offline): đưa các đơn tạo trước khi bật luồng đối soát vào đối soát,
                                        // sinh bảng NHÁP theo kỳ. Mặc định chỉ xem trước (không ghi) — bật "Ghi thật" để áp dụng.
                                        Forms\Components\Actions\Action::make('settleLegacyOrders')
                                            ->label('Đưa đơn cũ vào đối soát')
                                            ->icon('heroicon-o-arrow-path')
                                            ->color('warning')
                                            ->visible(fn (?Partner $record) => $record && auth()->user()?->isSuperAdmin() && \App\Services\LegacyOrderBackfillService::isEligiblePartner($record))
                                            ->modalHeading('Đưa đơn cũ vào đối soát')
                                            ->modalSubmitActionLabel('Thực hiện')
                                            ->form([
                                                Forms\Components\DatePicker::make('from')->label('Tính từ ngày tạo đơn')->required(),
                                                Forms\Components\DatePicker::make('to')->label('Đến ngày tạo đơn')->afterOrEqual('from'),
                                                Forms\Components\TextInput::make('rate')->label('Tỉ lệ hoa hồng (%)')->numeric()->minValue(0)->maxValue(100),
                                                Forms\Components\Toggle::make('apply')->label('Ghi thật (tắt = chỉ xem trước)')->default(false),
                                            ])
                                            ->action(fn (?Partner $record, array $data) => self::settleLegacyOrders($record, $data)),

                                        // Đối tác ký bằng chữ ký số (CKS) của họ thay cho OTP — admin thao tác hộ khi đối tác gửi file đã ký.
                                        Forms\Components\Actions\Action::make('downloadPartnerPdfToSign')
                                            ->label('Tải PDF để đối tác ký CKS')
                                            ->icon('heroicon-o-arrow-down-tray')
                                            ->color('gray')
                                            ->visible(fn (?Partner $record) => self::latestVersion($record) && ! self::latestVersion($record)?->isPartnerConfirmed())
                                            ->disabled(fn (?Partner $record) => ! $record || ! app(PartnerLegalDocumentService::class)->canPartnerSign($record))
                                            ->action(fn (?Partner $record) => self::downloadPartnerPdfToSign($record)),

                                        // Đối tác KÝ TAY trên trang đăng ký: tải bản scan / ảnh chụp đã nộp (nhiều tệp thì gộp ZIP) để đối chiếu.
                                        Forms\Components\Actions\Action::make('downloadPartnerManualSigned')
                                            ->label('Tải hợp đồng đối tác ký tay')
                                            ->icon('heroicon-o-arrow-down-tray')
                                            ->color('info')
                                            ->visible(fn (?Partner $record) => (bool) self::latestVersion($record)?->isPartnerSignedManually())
                                            ->action(fn (?Partner $record) => self::downloadPartnerManualSigned($record)),

                                        Forms\Components\Actions\Action::make('uploadPartnerSignedPdf')
                                            ->label('Tải lên PDF đối tác đã ký CKS')
                                            ->icon('heroicon-o-arrow-up-tray')
                                            ->color('gray')
                                            ->visible(fn (?Partner $record) => self::latestVersion($record) && ! self::latestVersion($record)?->isPartnerConfirmed())
                                            ->disabled(fn (?Partner $record) => ! $record || ! app(PartnerLegalDocumentService::class)->canPartnerSign($record))
                                            ->modalHeading('Tải lên PDF hợp đồng đối tác đã ký bằng chữ ký số')
                                            ->modalDescription('Chọn đúng file đã tải từ nút "Tải PDF để đối tác ký CKS" sau khi đối tác ký. Hệ thống kiểm tra chữ ký, nội dung và chứng thư có đúng MST/CCCD của đối tác không.')
                                            ->modalSubmitActionLabel('Kiểm tra & ghi nhận')
                                            ->form([
                                                Forms\Components\FileUpload::make('signed_pdf')->label('File PDF đối tác đã ký')->acceptedFileTypes(['application/pdf'])
                                                    ->disk('local')->directory('tmp/partner-signed')->maxSize(20480)->required(),
                                                Forms\Components\TextInput::make('signer_name')->label('Họ tên người ký (bỏ trống = lấy theo chứng thư)')->maxLength(255),
                                            ])
                                            ->action(fn (?Partner $record, array $data) => self::uploadPartnerSignedPdf($record, (string) ($data['signed_pdf'] ?? ''), $data['signer_name'] ?? null)),

                                        // Ký bằng USB token (mặc định, xem Cấu hình web > Chữ ký số): tải PDF về ký trên máy có USB rồi tải bản đã ký lên.
                                        Forms\Components\Actions\Action::make('downloadPdfToSign')
                                            ->label('Tải PDF về để ký (USB / CKS)')
                                            ->icon('heroicon-o-arrow-down-tray')
                                            ->color('success')
                                            ->visible(fn (?Partner $record) => self::latestVersion($record) && ! self::latestVersion($record)?->isPlatformSigned())
                                            ->disabled(fn (?Partner $record) => ! $record || ! app(PartnerLegalDocumentService::class)->isContractEligible($record))
                                            ->action(fn (?Partner $record) => self::downloadPdfToSign($record)),

                                        Forms\Components\Actions\Action::make('uploadSignedPdf')
                                            ->label('Tải lên PDF đã ký (USB / CKS)')
                                            ->icon('heroicon-o-arrow-up-tray')
                                            ->color('success')
                                            ->visible(fn (?Partner $record) => self::latestVersion($record) && ! self::latestVersion($record)?->isPlatformSigned())
                                            ->disabled(fn (?Partner $record) => ! $record || ! app(PartnerLegalDocumentService::class)->isContractEligible($record))
                                            ->modalHeading('Tải lên PDF hợp đồng đã ký bằng chữ ký số (USB token / CKS)')
                                            ->modalDescription('Chọn đúng file đã tải từ nút "Tải PDF về để ký (USB / CKS)" sau khi ký bằng chữ ký số của công ty (USB token hoặc CKS từ xa). Hệ thống kiểm tra chữ ký và nội dung hợp đồng rồi lưu lại bản đã ký; hợp đồng có hiệu lực khi cả hai bên đã ký, không phụ thuộc bên nào ký trước. Khi ký, chọn trang cuối và đặt hình chữ ký vào ô ĐẠI DIỆN BÊN A (công cụ ký thường mặc định ở góc dưới trái trang 1).')
                                            ->modalSubmitActionLabel('Kiểm tra & hoàn tất')
                                            ->form([
                                                Forms\Components\FileUpload::make('signed_pdf')->label('File PDF đã ký')->acceptedFileTypes(['application/pdf'])
                                                    ->disk('local')->directory('tmp/platform-signed')->maxSize(20480)->required(),
                                            ])
                                            ->action(fn (?Partner $record, array $data) => self::uploadSignedPdf($record, (string) ($data['signed_pdf'] ?? ''))),

                                        Forms\Components\Actions\Action::make('signAndExportContract')
                                            ->label('Ký số tự động & xuất PDF hợp đồng')
                                            ->icon('heroicon-o-shield-check')
                                            ->color(fn () => self::platformSignMethod() === 'auto' ? 'success' : 'gray')
                                            // Mặc định CHỈ tải file về ký; ký tự động chỉ hiện khi bật CONTRACT_ALLOW_AUTO_SIGN.
                                            ->visible(fn (?Partner $record) => \App\Services\ContractSigning\ContractSigningManager::platformAutoAllowed()
                                                && self::latestVersion($record) && ! self::latestVersion($record)?->isPlatformSigned())
                                            ->disabled(fn (?Partner $record) => ! $record || ! app(PartnerLegalDocumentService::class)->isContractEligible($record))
                                            ->requiresConfirmation()
                                            ->modalHeading(fn () => 'Ký số bằng: ' . self::signingProviderLabel())
                                            ->modalDescription(fn () => (self::isTestSigningProvider()
                                                ? '⚠ ĐANG Ở CHẾ ĐỘ KÝ THỬ NGHIỆM (Local) — chữ ký KHÔNG có giá trị pháp lý. Muốn ký thật, vào Cấu hình web › Chữ ký số và chọn VNPT SmartCA hoặc MISA eSign. '
                                                : 'Sẽ ký bằng ' . self::signingProviderLabel() . ' — nếu là SmartCA, hãy mở app và bấm xác nhận khi có yêu cầu. ')
                                                . 'Bấm để NỀN TẢNG ký số niêm phong hợp đồng (chỉ 1 lượt ký duy nhất; ký trước hay sau đối tác đều được) và TẢI VỀ MÁY file PDF đã nhúng chữ ký (chuẩn PAdES, nộp được lên neac.gov.vn) — file được lưu trên server, sau này bấm "Tải lại PDF đã ký" để tải lại mà không phải ký lại. Nếu đang dùng chữ ký số thật (VNPT SmartCA), hệ thống sẽ gửi thông báo tới điện thoại của thuê bao — cần MỞ ĐIỆN THOẠI BẤM XÁC NHẬN trong ít phút để hoàn tất.')
                                            ->action(fn (?Partner $record) => self::signAndExportContract($record)),

                                        // Hợp đồng đã ký THỬ NGHIỆM (Local) mà nay đã cấu hình nhà cung cấp thật: ký lại phần nền tảng (không đổi trạng thái/luồng tiền).
                                        Forms\Components\Actions\Action::make('resignPlatform')
                                            ->label('Ký lại bằng chữ ký số thật')
                                            ->icon('heroicon-o-arrow-path')
                                            ->color('warning')
                                            ->visible(fn (?Partner $record) => \App\Services\ContractSigning\ContractSigningManager::platformAutoAllowed()
                                                && self::latestVersion($record)?->isPlatformSigned()
                                                && self::latestVersion($record)?->platform_signing_provider === 'local')
                                            ->disabled(fn () => self::isTestSigningProvider())
                                            ->tooltip(fn () => self::isTestSigningProvider() ? 'Nhà cung cấp vẫn là Local — chọn VNPT SmartCA/MISA ở Cấu hình web › Chữ ký số trước.' : null)
                                            ->requiresConfirmation()
                                            ->modalHeading(fn () => 'Ký lại bằng: ' . self::signingProviderLabel())
                                            ->modalDescription('Hợp đồng này mới chỉ được ký THỬ NGHIỆM (Local, không có giá trị pháp lý). Bấm để nền tảng ký lại bằng chữ ký số thật — chỉ thay chữ ký và file PDF, không đổi trạng thái hợp đồng, ngày hiệu lực hay luồng tiền. Nếu là SmartCA, hãy mở app và bấm xác nhận.')
                                            ->action(fn (?Partner $record) => self::resignPlatformSignature($record)),

                                        // Bản PDF dựng đúng như màn hình xem hợp đồng (khung ký có tên và giờ ký) — chỉ để xem/in, không mang chữ ký số.
                                        Forms\Components\Actions\Action::make('downloadDisplayPdf')
                                            ->label('Tải bản hiển thị PDF (như trên web)')
                                            ->icon('heroicon-o-document-text')
                                            ->color('gray')
                                            ->visible(fn (?Partner $record) => (bool) config('contract_signing.allow_display_pdf', false) && (bool) self::latestVersion($record))
                                            ->tooltip('Bản để xem/in, giống màn hình xem hợp đồng; không mang chữ ký số. Bản có giá trị ký số là "Tải lại PDF đã ký".')
                                            ->action(fn (?Partner $record) => self::downloadDisplayPdf($record)),

                                        Forms\Components\Actions\Action::make('reExportSignedPdf')
                                            ->label('Tải lại PDF đã ký')
                                            ->icon('heroicon-o-document-arrow-down')
                                            ->color('gray')
                                            ->visible(fn (?Partner $record) => self::latestVersion($record)?->isPlatformSigned())
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
                                    'change_note' => $data['change_note'] ?? null,
                                    'changed_by' => auth()->id(),
                                ]);

                                if (! empty($data['document'])) {
                                    $version->addMediaFromDisk($data['document'], 'public')
                                        ->toMediaCollection('document');
                                }

                                Notification::make()
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
                                    'contract_status' => 'active',
                                ]);

                                Notification::make()
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
                                // Luồng tiền mới: ngưng bán, bắt đầu thời gian giữ ký quỹ nhận khiếu nại.
                                app(\App\Services\EscrowService::class)->onContractTerminated($record->fresh(), auth()->user());

                                Notification::make()
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
            $who = e($version->changedBy?->fullname ?? 'Hệ thống');
            $note = e($version->change_note ?? '—');
            $docUrl = $version->getFirstMediaUrl('document');
            $docLink = $docUrl
                ? '<a href="'.$docUrl.'" target="_blank" style="color:#2563eb;">Xem tài liệu</a>'
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

        try {
            $result = app(\App\Services\PartnerContractWorkflowService::class)->createAndSend($record, auth()->user());
        } catch (ValidationException $e) {
            Notification::make()
                ->title('Chưa thể tạo hợp đồng')
                ->body(collect($e->errors())->flatten()->map(fn ($m) => '• ' . $m)->implode("\n"))
                ->danger()->persistent()->send();

            return;
        }

        Notification::make()
            ->title($result['mailSent']
                ? "Đã tạo hợp đồng & gửi link ký tới {$result['email']}"
                : 'Đã tạo hợp đồng — chưa gửi được email, xem "Xem toàn văn hợp đồng" để lấy link gửi thủ công')
            ->success()
            ->send();
    }

    private static function settleLegacyOrders(?Partner $record, array $data): void
    {
        if (! $record || ! \App\Services\LegacyOrderBackfillService::isEligiblePartner($record)) {
            return;
        }

        $apply = (bool) ($data['apply'] ?? false);

        try {
            $report = app(\App\Services\LegacyOrderBackfillService::class)->run(
                $record,
                \Illuminate\Support\Carbon::parse((string) $data['from'])->startOfDay(),
                filled($data['to'] ?? null) ? \Illuminate\Support\Carbon::parse((string) $data['to'])->endOfDay() : null,
                filled($data['rate'] ?? null) ? (float) $data['rate'] : null,
                auth()->user(),
                $apply,
            );
        } catch (\InvalidArgumentException $e) {
            Notification::make()->title('Chưa thể thực hiện')->body($e->getMessage())->danger()->persistent()->send();

            return;
        }

        Notification::make()
            ->title($apply ? 'Đã đưa đơn cũ vào đối soát — các bảng đang ở trạng thái nháp' : 'Xem trước (chưa ghi gì)')
            ->body(nl2br(e(\App\Services\LegacyOrderBackfillService::describe($report))))
            ->success()->persistent()->send();
    }

    private static function createAddendum(?Partner $record): void
    {
        if (! $record) {
            return;
        }

        try {
            $result = app(\App\Services\PartnerContractWorkflowService::class)->createAddendumAndSend($record, auth()->user());
        } catch (ValidationException $e) {
            Notification::make()
                ->title('Chưa thể tạo phụ lục')
                ->body(collect($e->errors())->flatten()->map(fn ($m) => '• ' . $m)->implode("\n"))
                ->danger()->persistent()->send();

            return;
        }

        Notification::make()
            ->title($result['mailSent']
                ? "Đã tạo phụ lục & gửi link ký tới {$result['email']}"
                : 'Đã tạo phụ lục — chưa gửi được email, xem "Xem toàn văn hợp đồng" để lấy link gửi thủ công')
            ->success()
            ->send();
    }

    // Nền tảng ký số hợp đồng — trước hay sau đối tác đều được (hợp đồng có hiệu lực khi cả hai bên đã ký); NỀN TẢNG
    // dùng chữ ký số THẬT (VNPT SmartCA) niêm phong hợp đồng (chỉ 1 lượt ký), đồng thời xuất luôn
    // PDF đã nhúng chữ ký (PAdES) — không còn khái niệm "ký thay đối tác" (xem thảo luận: dùng
    // chứng thư số CỦA NỀN TẢNG để "ký thay" đối tác là không chính xác về bản chất pháp lý).
    //
    // File PDF đã ký được LƯU trên server (đĩa riêng tư, media 'signed_pdf') và tải về qua streamDownload.
    //
    // $forceNewSignature: dùng cho "Tải lại PDF đã ký" — trả lại đúng file đã lưu, KHÔNG ký lại. Chỉ hợp đồng ký
    // trước khi có tính năng lưu (chưa có file) mới ký lại 1 lần để lưu.
    private static function signAndExportContract(?Partner $record, bool $forceNewSignature = false): ?StreamedResponse
    {
        if (! $record) {
            return null;
        }

        try {
            $result = app(\App\Services\PartnerContractWorkflowService::class)->platformSign(
                $record,
                auth()->user(),
                (string) request()->ip(),
                (string) request()->userAgent(),
                reExport: $forceNewSignature,
            );
        } catch (ValidationException $e) {
            Notification::make()
                ->title('Chưa thể ký hợp đồng')
                ->body(collect($e->errors())->flatten()->map(fn ($m) => '• ' . $m)->implode("\n"))
                ->danger()->send();

            return null;
        } catch (\Throwable $e) {
            report($e);
            Notification::make()->title('Ký số & xuất PDF thất bại')->body($e->getMessage())->danger()->send();

            return null;
        }

        return response()->streamDownload(
            fn () => print ($result['pdf']),
            $result['file_name'],
            ['Content-Type' => 'application/pdf']
        );
    }

    private static function downloadDisplayPdf(?Partner $record): ?StreamedResponse
    {
        if (! $record) {
            return null;
        }

        try {
            $result = app(\App\Services\PartnerContractWorkflowService::class)->displayPdf($record);
        } catch (ValidationException $e) {
            Notification::make()->title('Chưa có hợp đồng để xuất')->body(collect($e->errors())->flatten()->implode(' '))->danger()->send();

            return null;
        }

        return response()->streamDownload(fn () => print ($result['pdf']), $result['file_name'], ['Content-Type' => 'application/pdf']);
    }

    private static function downloadPartnerPdfToSign(?Partner $record): ?StreamedResponse
    {
        $version = self::latestVersion($record);
        if (! $version) {
            return null;
        }

        try {
            $result = app(\App\Services\PartnerContractCksService::class)->preparePdf($version);
        } catch (ValidationException $e) {
            Notification::make()->title('Chưa thể tải PDF để đối tác ký')->body(collect($e->errors())->flatten()->map(fn ($m) => '• ' . $m)->implode("\n"))->danger()->send();

            return null;
        }

        return response()->streamDownload(fn () => print ($result['pdf']), $result['file_name'], ['Content-Type' => 'application/pdf']);
    }

    private static function downloadPartnerManualSigned(?Partner $record): ?\Symfony\Component\HttpFoundation\Response
    {
        $version = self::latestVersion($record);
        $file = $version ? app(\App\Services\PartnerContractCksService::class)->manualDownload($version) : null;
        if (! $file) {
            Notification::make()->title('Không tìm thấy tệp hợp đồng ký tay')->danger()->send();

            return null;
        }

        return response()->download($file['path'], $file['file_name'])->deleteFileAfterSend($file['temporary']);
    }

    private static function uploadPartnerSignedPdf(?Partner $record, string $path, ?string $signerName): void
    {
        $version = self::latestVersion($record);
        if (! $version || $path === '') {
            return;
        }

        $disk = \Illuminate\Support\Facades\Storage::disk('local');

        try {
            $result = app(\App\Services\PartnerContractCksService::class)->submit(
                $version,
                (string) $disk->get($path),
                $signerName,
                (string) request()->ip(),
                (string) request()->userAgent(),
            );
        } catch (ValidationException $e) {
            Notification::make()->title('PDF đối tác ký chưa hợp lệ')->body(collect($e->errors())->flatten()->map(fn ($m) => '• ' . $m)->implode("\n"))->danger()->persistent()->send();

            return;
        } catch (\Throwable $e) {
            report($e);
            Notification::make()->title('Không ghi nhận được chữ ký của đối tác')->body($e->getMessage())->danger()->send();

            return;
        } finally {
            $disk->delete($path);
        }

        Notification::make()->title('Đã kiểm tra chữ ký số của đối tác')
            ->body('Chứng thư: ' . ($result['certificate']['cert_subject'] ?? '—') . (filled($result['certificate']['cert_org'] ?? null) ? ' — ' . $result['certificate']['cert_org'] : '') . '. Nền tảng có thể ký xác nhận ngay.')
            ->success()->send();
    }

    private static function resignPlatformSignature(?Partner $record): ?StreamedResponse
    {
        if (! $record) {
            return null;
        }

        try {
            $result = app(\App\Services\PartnerContractWorkflowService::class)->platformResign($record, auth()->user(), (string) request()->ip(), (string) request()->userAgent());
        } catch (ValidationException $e) {
            Notification::make()->title('Chưa ký lại được')->body(collect($e->errors())->flatten()->map(fn ($m) => '• ' . $m)->implode("\n"))->danger()->send();

            return null;
        } catch (\Throwable $e) {
            report($e);
            Notification::make()->title('Ký lại thất bại')->body($e->getMessage())->danger()->send();

            return null;
        }

        Notification::make()->title('Đã ký lại bằng chữ ký số thật')->success()->send();

        return response()->streamDownload(fn () => print ($result['pdf']), $result['file_name'], ['Content-Type' => 'application/pdf']);
    }

    private static function isTestSigningProvider(): bool
    {
        return app(\App\Services\ContractSigning\ContractSigningManager::class)->isTestProvider();
    }

    // Tên nhà cung cấp sẽ dùng để nền tảng ký (hiển thị trong hộp xác nhận để không ký nhầm bằng chế độ thử nghiệm).
    private static function signingProviderLabel(): string
    {
        $manager = app(\App\Services\ContractSigning\ContractSigningManager::class);
        $name = $manager->providerNameForSide(\App\Services\ContractSigning\ContractSigningManager::SIDE_HOMESTAY);

        return \App\Services\ContractSigning\ContractSigningManager::PROVIDERS[$name] ?? $name;
    }

    private static function platformSignMethod(): string
    {
        return app(\App\Services\ContractSigning\ContractSigningManager::class)->platformSignMethod();
    }

    // Ký USB, bước 1: PDF CHƯA ký để người giữ chứng thư ký trên máy của họ (xem PartnerContractWorkflowService::preparePlatformPdf).
    private static function downloadPdfToSign(?Partner $record): ?StreamedResponse
    {
        if (! $record) {
            return null;
        }

        try {
            $result = app(\App\Services\PartnerContractWorkflowService::class)->preparePlatformPdf($record);
        } catch (ValidationException $e) {
            Notification::make()->title('Chưa thể tải PDF để ký')->body(collect($e->errors())->flatten()->map(fn ($m) => '• ' . $m)->implode("\n"))->danger()->send();

            return null;
        }

        return response()->streamDownload(fn () => print ($result['pdf']), $result['file_name'], ['Content-Type' => 'application/pdf']);
    }

    // Ký USB, bước 2: kiểm tra PDF đã ký rồi hoàn tất hợp đồng (cùng quy trình khi nền tảng ký tự động).
    private static function uploadSignedPdf(?Partner $record, string $path): void
    {
        if (! $record || $path === '') {
            return;
        }

        $disk = \Illuminate\Support\Facades\Storage::disk('local');

        try {
            $result = app(\App\Services\PartnerContractWorkflowService::class)->platformUpload(
                $record,
                auth()->user(),
                (string) request()->ip(),
                (string) request()->userAgent(),
                (string) $disk->get($path),
            );
        } catch (ValidationException $e) {
            Notification::make()->title('PDF đã ký chưa hợp lệ')->body(collect($e->errors())->flatten()->map(fn ($m) => '• ' . $m)->implode("\n"))->danger()->persistent()->send();

            return;
        } catch (\Throwable $e) {
            report($e);
            Notification::make()->title('Không hoàn tất được hợp đồng')->body($e->getMessage())->danger()->send();

            return;
        } finally {
            $disk->delete($path);
        }

        Notification::make()->title('Đã kiểm tra chữ ký và hoàn tất hợp đồng')
            ->body('Chứng thư: ' . ($result['certificate']['cert_subject'] ?? '—') . (filled($result['certificate']['cert_org'] ?? null) ? ' — ' . $result['certificate']['cert_org'] : ''))
            ->success()->send();
    }

    // Tên hiển thị cho từng provider ký số — xem config/contract_signing.php. 'local' luôn phải
    // ghi rõ "chưa có giá trị pháp lý" để không ai nhầm tưởng đây là chữ ký số thật do CA cấp.
    private const SIGNING_PROVIDER_LABELS = [
        'local' => 'Local — chữ ký test, KHÔNG có giá trị pháp lý',
        'vnpt_smartca' => 'VNPT SmartCA — chữ ký số thật',
        'usb_token' => 'USB token — chữ ký số thật (ký trên máy, tải bản đã ký lên)',
    ];

    private static function renderSignatureStatus(?PartnerContractVersion $version): string
    {
        if (! $version) {
            return '<div style="font-size:0.8rem;color:#9ca3af;">Chưa có hợp đồng điện tử nào được tạo.</div>';
        }

        $partnerRow = $version->isPartnerConfirmed() && $version->isPartnerSignedManually()
            ? '<span style="color:#0369a1;font-weight:700;">✓ Đã ký tay (bản scan)</span> lúc '.e($version->partner_confirmed_at->format('H:i d/m/Y'))
                .' bởi '.e($version->partner_signed_by_name).' — '.$version->getMedia('partner_manual_signed')->count().' tệp, bấm "Tải hợp đồng đối tác ký tay" để đối chiếu'
            : ($version->isPartnerConfirmed()
            ? ($version->isPartnerSignedByCks()
                ? '<span style="color:#0369a1;font-weight:700;">✓ Đã ký số (CKS)</span> lúc '.e($version->partner_confirmed_at->format('H:i d/m/Y'))
                    .' bởi '.e($version->partner_signed_by_name).' (IP: '.e($version->partner_signed_ip).')'
                    .(($pc = $version->partner_signature_certificate) && filled($pc['cert_subject'] ?? null)
                        ? '<div style="margin-top:2px;font-size:0.76rem;color:#374151;">Chứng thư số: <strong>'.e($pc['cert_subject']).'</strong>'
                            .(filled($pc['cert_org'] ?? null) ? ' — '.e($pc['cert_org']) : '').(filled($pc['cert_issuer'] ?? null) ? ' (CA: '.e($pc['cert_issuer']).')' : '')
                            .(filled($pc['tax_code'] ?? null) ? ' — MST '.e($pc['tax_code']) : '').'</div>'
                        : '')
                : '<span style="color:#0369a1;font-weight:700;">✓ Đã xác nhận (OTP)</span> lúc '.e($version->partner_confirmed_at->format('H:i d/m/Y'))
                    .' bởi '.e($version->partner_signed_by_name).' (IP: '.e($version->partner_signed_ip).')')
            : '<span style="color:#f59e0b;font-weight:700;">Chưa xác nhận</span> — đang chờ đối tác xác nhận qua link đã gửi');

        $platformRow = $version->isPlatformSigned()
            ? '<span style="color:#10b981;font-weight:700;">✓ Đã ký số & phát hành PDF</span> lúc '.e($version->platform_signed_at->format('H:i d/m/Y'))
                .' — người thao tác: '.e($version->platformSignedBy?->fullname ?: ($version->platformSignedBy?->email ?? '—'))
                .' — <span style="color:#6b7280;">'.e(self::SIGNING_PROVIDER_LABELS[$version->platform_signing_provider] ?? $version->platform_signing_provider ?? '—').'</span>'
                .(($cert = $version->platform_signature_certificate) && filled($cert['cert_subject'] ?? null)
                    ? '<div style="margin-top:2px;font-size:0.76rem;color:#374151;">Chứng thư số: <strong>'.e($cert['cert_subject']).'</strong>'
                        .(filled($cert['cert_org'] ?? null) ? ' — '.e($cert['cert_org']) : '').(filled($cert['cert_issuer'] ?? null) ? ' (CA: '.e($cert['cert_issuer']).')' : '').'</div>'
                    : '')
                .'<div style="margin-top:2px;font-size:0.72rem;color:#6b7280;">🔏 File PDF đã ký được lưu trên server — bấm "Tải lại PDF đã ký" để tải về (không ký lại).</div>'
            : '<span style="color:#f59e0b;font-weight:700;">Chưa ký</span> — ký bằng "Ký số tự động" hoặc tải PDF về ký rồi tải lên; ký trước hay sau đối tác đều được';

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
            ? '<div style="margin-top:12px;padding:10px;background:#f9fafb;border-radius:8px;font-size:0.8rem;word-break:break-all;"><strong>Link ký cho đối tác:</strong> '.e($signUrl).'</div>'
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
        $body = $version?->content ?? PartnerContractRenderer::render($record);

        return PartnerContractRenderer::renderFramed($body, $record, $version);
    }

    private static function renderSigningTimeline(?PartnerContractVersion $version): string
    {
        $platformNode = self::renderTimelineNode(
            'Bên A (Nền tảng)',
            $version?->isPlatformSigned() ?? false,
            $version?->isPlatformSigned() ? 'Đã ký lúc '.$version->platform_signed_at->format('H:i, d/m/Y') : 'Đang chờ ký'
        );

        $partnerMeta = $version?->isPartnerConfirmed()
            ? 'Đã xác nhận (OTP) lúc '.$version->partner_confirmed_at->format('H:i, d/m/Y')
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
        $icon = $done ? '✓' : '✎';

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
            'Mã hợp đồng' => e($record->contract_code ?? '(chưa cấp mã)'),
            'Loại' => e($typeLabels[$record->contract_type] ?? '—'),
            'Tỷ lệ hoa hồng' => self::formatCommissionRate($record->commission_rate),
            'Đối tác' => e($record->legal_name ?? $record->name),
            'Đại diện' => e($record->representative_name ?? '—'),
            'Thời hạn' => ($record->contract_signed_at && $record->contract_expires_at)
                ? $record->contract_signed_at->format('d/m/Y').' — '.$record->contract_expires_at->format('d/m/Y')
                : '—',
            'Ngày tạo' => $version?->created_at?->format('d/m/Y') ?? '—',
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

        return e(str_contains($rate, '%') ? $rate : $rate.'%');
    }

    private static function renderContractStatusLegend(): string
    {
        $descriptions = [
            'draft' => 'Hợp đồng đang được soạn thảo',
            'pending' => 'Đang đợi các bên ký xác nhận',
            'active' => 'Hợp đồng có giá trị pháp lý hiện hành',
            'expired' => 'Đã vượt quá ngày hết hạn ký kết',
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
                                    'pending' => ['Chờ phê duyệt', '#f59e0b'],
                                    'approved' => ['Đang hoạt động', '#10b981'],
                                    'suspended' => ['Ngừng hoạt động', '#9ca3af'],
                                    'rejected' => ['Từ chối', '#ef4444'],
                                ];
                                $status = $record?->verification_status ?? 'pending';
                                [$label, $color] = $labels[$status] ?? [$status, '#9ca3af'];

                                return new HtmlString(
                                    '<span style="display:inline-block;padding:4px 12px;border-radius:9999px;background:'.$color.'22;color:'.$color.';font-weight:700;font-size:0.85rem;">'
                                    .mb_strtoupper($label).'</span>'
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
                                .'<li>Giấy phép kinh doanh (GPKD)</li>'
                                .'<li>Giấy chứng nhận đăng ký thuế</li>'
                                .'<li>Giấy tờ pháp lý khác (nếu có)</li>'
                                .'</ul>'
                            )),

                        Forms\Components\Placeholder::make('structured_legal_documents_notice')
                            ->label('Quản lý giấy tờ')
                            ->content(new HtmlString('Thêm và xét duyệt từng giấy tờ tại bảng <strong>Hồ sơ pháp lý</strong> bên dưới trang. Mỗi giấy tờ có loại, thời hạn và kết quả duyệt riêng.'))
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
                            $who = e($log->changedBy?->fullname ?? 'Hệ thống');
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
