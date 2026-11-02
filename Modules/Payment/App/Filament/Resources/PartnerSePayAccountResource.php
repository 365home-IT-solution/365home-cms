<?php

namespace Modules\Payment\App\Filament\Resources;

use App\Models\Partner;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Modules\Minihouse\App\Support\HomestayBridge;
use Modules\Payment\App\Filament\Resources\PartnerSePayAccountResource\Pages;
use Modules\Category\Entities\Category;
use Modules\Payment\Entities\BranchPayOsAccount;
use Modules\Payment\Entities\PartnerPayOsAccount;
use Modules\Payment\Entities\PartnerSePayAccount;

// "SePay / Pay2S theo đối tác" — tài khoản ngân hàng nhận tiền đặt phòng của đối tác Homestay, kèm cổng nhận biến động số dư (SePay hoặc Pay2S, đối tác chọn một).
// Đối tác bật thì mọi đơn của đối tác (trừ chi nhánh có "PayOS theo chi nhánh") thanh toán bằng chuyển khoản vào tài khoản này và được xác nhận tự động qua
// webhook của cổng đã chọn; không bật thì dùng PayOS như cũ. Chỉ super_admin — đây là nơi đổi tiền chảy về tài khoản nào. Việc liên kết tài
// khoản ngân hàng với SePay làm trực tiếp trên dashboard SePay.
class PartnerSePayAccountResource extends Resource
{
    protected static ?string $model = PartnerSePayAccount::class;

    protected static ?string $navigationIcon = 'heroicon-o-qr-code';

    protected static ?string $navigationLabel = 'SePay / Pay2S theo đối tác';

    protected static ?string $modelLabel = 'tài khoản SePay / Pay2S đối tác';

    protected static ?string $pluralModelLabel = 'SePay / Pay2S theo đối tác';

    public static function getNavigationGroup(): ?string
    {
        return __('payment::payment-configuration.resource.navigation_group');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    /** @return array<string,string> */
    public static function bankOptions(): array
    {
        return collect(config('banks.list', []))->mapWithKeys(fn (array $bank, string $code) => [$code => $bank['short_name'] . ' — ' . $bank['name']])->all();
    }

    /** Điền các cột suy ra từ form (BIN theo ngân hàng, tên chủ tài khoản in hoa) trước khi lưu. */
    public static function normalize(array $data): array
    {
        $bank = config('banks.list.' . ($data['bank_code'] ?? ''), []);

        $data['bank_bin'] = $bank['bin'] ?? ($data['bank_bin'] ?? '');
        $data['account_number'] = trim((string) ($data['account_number'] ?? ''));
        $data['account_holder'] = mb_strtoupper(trim((string) ($data['account_holder'] ?? '')));
        $data['provider'] = $data['provider'] ?? PartnerSePayAccount::PROVIDER_SEPAY;
        $data['category_id'] = filled($data['category_id'] ?? null) ? (int) $data['category_id'] : null;
        $data['updated_by'] = auth()->id();

        // Token Pay2S để trống = giữ nguyên token đang lưu (token không bao giờ hiện lại); SePay không dùng token này.
        if ($data['provider'] !== PartnerSePayAccount::PROVIDER_PAY2S) {
            unset($data['webhook_token']);
        } elseif (! filled($data['webhook_token'] ?? null)) {
            unset($data['webhook_token']);
        } else {
            $data['webhook_token'] = trim((string) $data['webhook_token']);
        }

        return $data;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Đối tác')
                ->schema([
                    Forms\Components\Select::make('partner_id')
                        ->label('Đối tác Homestay')
                        ->options(fn () => Partner::query()
                            ->where('partner_type', Partner::TYPE_HOMESTAY)
                            ->where('id', '!=', HomestayBridge::PARTNER_ID)
                            ->orderBy('name')
                            ->get()
                            ->mapWithKeys(fn (Partner $partner) => [$partner->id => $partner->legal_name ?: $partner->name]))
                        ->searchable()
                        ->required()
                        ->live()
                        ->disabledOn('edit')
                        // Mỗi đối tác tối đa 1 tài khoản mặc định + 1 tài khoản cho mỗi chi nhánh.
                        ->rules([
                            fn (Forms\Get $get, ?PartnerSePayAccount $record) => function (string $attribute, $value, \Closure $fail) use ($get, $record) {
                                $categoryId = filled($get('category_id')) ? (int) $get('category_id') : null;

                                if (PartnerSePayAccount::query()->where('partner_id', $value)->where('category_id', $categoryId)->when($record, fn ($q) => $q->whereKeyNot($record->getKey()))->exists()) {
                                    $fail($categoryId ? 'Chi nhánh này đã có tài khoản nhận tiền — sửa dòng đang có thay vì tạo mới.' : 'Đối tác này đã có tài khoản mặc định — sửa dòng đang có, hoặc chọn một chi nhánh để thêm tài khoản riêng cho chi nhánh.');
                                }
                            },
                        ]),

                    Forms\Components\Select::make('category_id')
                        ->label('Chi nhánh')
                        ->placeholder('Cả đối tác (tài khoản mặc định)')
                        ->options(fn (Forms\Get $get) => $get('partner_id') ? Category::query()->where('partner_id', $get('partner_id'))->orderBy('name')->pluck('name', 'id') : [])
                        ->searchable()
                        ->live()
                        ->disabledOn('edit')
                        ->helperText('Để trống = tài khoản dùng cho mọi chi nhánh của đối tác. Chọn chi nhánh = chi nhánh đó (và các khu vực con) nhận tiền vào tài khoản riêng bên dưới; chi nhánh không có tài khoản riêng dùng tài khoản mặc định.'),

                    Forms\Components\Select::make('provider')
                        ->label('Cổng nhận biến động số dư')
                        ->options(PartnerSePayAccount::PROVIDERS)
                        ->default(PartnerSePayAccount::PROVIDER_SEPAY)
                        ->required()
                        ->native(false)
                        ->live()
                        ->helperText('Đối tác dùng cổng nào thì liên kết ngân hàng ở cổng đó (SePay hoặc Pay2S). Đổi cổng thì cần liên kết lại và khai báo token (Pay2S). "Xác nhận thủ công": không cần cổng — khách vẫn chuyển khoản vào tài khoản bên dưới, đối tác tự bấm xác nhận đã nhận tiền ở "Chờ xác nhận chuyển khoản".'),

                    Forms\Components\Toggle::make('is_active')
                        ->label('Dùng chuyển khoản tự động làm kênh thu tiền')
                        ->default(true)
                        ->inline(false)
                        ->helperText('Bật = đơn của đối tác thanh toán qua cổng đã chọn (chuyển khoản vào tài khoản bên dưới). Tắt = dùng PayOS như cũ. Mã thanh toán đã tạo trước đó vẫn được xử lý bình thường.')
                        ->rules([
                            fn (Forms\Get $get) => function (string $attribute, $value, \Closure $fail) use ($get) {
                                if ($value && $get('partner_id') && PartnerPayOsAccount::query()->where('partner_id', $get('partner_id'))->where('is_active', true)->exists()) {
                                    $fail('Đối tác đang dùng kênh PayOS riêng. Tắt kênh đó ở "PayOS theo đối tác" trước, rồi mới bật SePay / Pay2S (mỗi đối tác chỉ dùng một kênh).');
                                }

                                if ($value && filled($get('category_id')) && BranchPayOsAccount::query()->where('category_id', $get('category_id'))->where('is_active', true)->exists()) {
                                    $fail('Chi nhánh này đang dùng kênh PayOS riêng. Tắt kênh đó ở "PayOS theo chi nhánh" trước.');
                                }
                            },
                        ]),

                    Forms\Components\Placeholder::make('flow_hint')
                        ->label('Điều kiện')
                        ->content(function (Forms\Get $get) {
                            $partner = $get('partner_id') ? Partner::find($get('partner_id')) : null;

                            if (! $partner) {
                                return 'Chọn đối tác để kiểm tra.';
                            }

                            return $partner->usesDirectPayment()
                                ? 'Đối tác đã có hợp đồng mẫu mới có hiệu lực — được nhận tiền trực tiếp qua SePay.'
                                : 'CHƯA ĐỦ ĐIỀU KIỆN: đối tác chưa có hợp đồng mẫu mới có hiệu lực (payment_flow_effective_at). Đến lúc đó 365home vẫn thu hộ qua PayOS chung, tài khoản SePay chưa được dùng.';
                        })
                        ->columnSpanFull(),

                    Forms\Components\TextInput::make('note')->label('Ghi chú')->maxLength(255)->columnSpanFull(),
                ])
                ->columns(2),

            Forms\Components\Section::make('Tài khoản ngân hàng nhận tiền')
                ->description('Phải GIỐNG TUYỆT ĐỐI tài khoản đã liên kết trên dashboard của cổng đã chọn (SePay hoặc Pay2S). Mỗi số tài khoản chỉ gắn cho một nơi (một đối tác, một chi nhánh).')
                ->schema([
                    Forms\Components\Select::make('bank_code')
                        ->label('Ngân hàng')
                        ->options(fn () => static::bankOptions())
                        ->searchable()
                        ->required(),
                    Forms\Components\TextInput::make('account_number')
                        ->label('Số tài khoản (số khách chuyển tới)')
                        ->helperText('Tài khoản thường: nhập số tài khoản. Ngân hàng chỉ nhận qua tài khoản ảo (VA) — thường gặp ở tài khoản doanh nghiệp (BIDV, OCB, MSB, KienLongBank...): nhập SỐ VA khách chuyển tới. Hệ thống nhận giao dịch dù SePay báo số tài khoản chính hay số VA.')
                        ->required()
                        ->maxLength(50)
                        ->regex('/^[0-9A-Za-z.-]+$/')
                        ->unique(ignoreRecord: true)
                        ->validationMessages(['unique' => 'Số tài khoản này đã gắn cho đối tác / chi nhánh khác.', 'regex' => 'Số tài khoản chỉ gồm chữ, số, dấu chấm hoặc gạch ngang.']),
                    Forms\Components\TextInput::make('account_holder')
                        ->label('Chủ tài khoản')
                        ->required()
                        ->maxLength(255)
                        ->helperText('Không dấu, viết in hoa như trên tài khoản ngân hàng.'),
                ])
                ->columns(3),

            Forms\Components\Section::make('Webhook Pay2S của tài khoản này')
                ->description('Pay2S sinh một token riêng cho MỖI webhook (mỗi tài khoản ngân hàng) và chỉ hiện một lần: tạo webhook ở my.pay2s.vn → Webhooks → Thêm webhook (chọn đúng tài khoản ngân hàng, loại "Nhận tiền", URL bên dưới), chép token dán vào ô này.')
                ->visible(fn (Forms\Get $get) => $get('provider') === PartnerSePayAccount::PROVIDER_PAY2S)
                ->schema([
                    Forms\Components\Placeholder::make('pay2s_webhook')
                        ->label('Webhook URL (nhập vào Pay2S)')
                        ->content(fn () => new HtmlString('<code>' . e(\App\Support\Pay2SConfig::get('webhook_url') ?: route('webhook.pay2s')) . '</code>')),
                    Forms\Components\TextInput::make('webhook_token')
                        ->label('Token webhook Pay2S')
                        ->password()->revealable()
                        ->autocomplete(false)
                        ->maxLength(500)
                        ->placeholder('Để trống = giữ nguyên token hiện tại')
                        ->helperText(fn (?PartnerSePayAccount $record) => $record?->webhook_token ? 'Đã có token. Chỉ nhập lại khi tạo webhook mới ở Pay2S.' : 'CHƯA có token — webhook của tài khoản này sẽ bị từ chối cho tới khi nhập. (Hệ thống vẫn xác nhận được qua API Token Pay2S nếu đã nhập ở "Pay2S — cấu hình chung".)'),
                ])
                ->columns(1),

            Forms\Components\Section::make('Cấu hình chung của cổng (làm một lần, dùng chung mọi đối tác)')
                ->description('Nhập ở CMS → Cấu hình web → "SePay — cấu hình chung" hoặc "Pay2S — cấu hình chung": khoá/token, API Token, tiền tố mã (SePay), công tắc bật/tắt.')
                ->schema([
                    Forms\Components\Placeholder::make('webhook')
                        ->label('Webhook URL (nhập vào SePay)')
                        ->content(fn () => new HtmlString('<code>' . e(\App\Support\SePayConfig::get('webhook_url') ?: route('webhook.sepay')) . '</code>')),
                    Forms\Components\Placeholder::make('prefix')
                        ->label('Tiền tố mã thanh toán')
                        ->content(fn () => new HtmlString('<code>' . e(strtoupper((string) \App\Support\SePayConfig::get('code_prefix', 'DH'))) . '</code> — nội dung chuyển khoản dạng <code>' . e(strtoupper((string) \App\Support\SePayConfig::get('code_prefix', 'DH'))) . '12345678</code>')),
                    Forms\Components\Placeholder::make('secret_state')
                        ->label('Khoá bí mật webhook')
                        ->content(fn () => filled(\App\Support\SePayConfig::get('webhook_secret')) ? 'Đã cấu hình.' : 'CHƯA cấu hình — webhook sẽ bị từ chối cho tới khi nhập ở "SePay — cấu hình chung".'),
                    Forms\Components\Placeholder::make('enabled_state')
                        ->label('Trạng thái SePay toàn hệ thống')
                        ->content(fn () => \App\Support\SePayConfig::enabled() ? 'Đang BẬT.' : 'Đang TẮT — mọi đối tác dùng PayOS cho tới khi bật lại ở "SePay — cấu hình chung".'),
                ])
                ->columns(1)
                ->collapsed(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['partner', 'category']))
            ->columns([
                Tables\Columns\TextColumn::make('partner.name')->label('Đối tác')->searchable()->wrap(),
                Tables\Columns\TextColumn::make('category.name')->label('Phạm vi')->placeholder('Cả đối tác (mặc định)')->wrap(),
                Tables\Columns\TextColumn::make('provider')->label('Cổng')->badge()
                    ->formatStateUsing(fn (?string $state) => PartnerSePayAccount::PROVIDERS[$state ?: 'sepay'] ?? $state)
                    ->color(fn (?string $state) => match ($state) { PartnerSePayAccount::PROVIDER_PAY2S => 'info', PartnerSePayAccount::PROVIDER_MANUAL => 'warning', default => 'success' }),
                Tables\Columns\TextColumn::make('bank_code')->label('Ngân hàng')->badge(),
                Tables\Columns\TextColumn::make('account_number')->label('Số tài khoản')->searchable()->copyable(),
                Tables\Columns\TextColumn::make('account_holder')->label('Chủ tài khoản')->wrap(),
                Tables\Columns\IconColumn::make('is_active')->label('Đang dùng')->boolean(),
                Tables\Columns\IconColumn::make('partner.payment_flow_effective_at')
                    ->label('Đủ điều kiện')
                    ->boolean()
                    ->getStateUsing(fn (PartnerSePayAccount $record) => (bool) $record->partner?->usesDirectPayment())
                    ->tooltip('Hợp đồng mẫu mới của đối tác đã có hiệu lực'),
                Tables\Columns\TextColumn::make('updated_at')->label('Cập nhật')->dateTime('d/m/Y H:i'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->modalDescription('Xoá tài khoản nhận tiền này? Chi nhánh (hoặc cả đối tác) sẽ dùng tài khoản mặc định hoặc PayOS. Yêu cầu thanh toán đã tạo vẫn được tra cứu.'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPartnerSePayAccounts::route('/'),
            'create' => Pages\CreatePartnerSePayAccount::route('/create'),
            'edit' => Pages\EditPartnerSePayAccount::route('/{record}/edit'),
        ];
    }
}
