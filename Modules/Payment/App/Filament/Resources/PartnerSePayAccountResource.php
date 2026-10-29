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
use Modules\Payment\Entities\PartnerPayOsAccount;
use Modules\Payment\Entities\PartnerSePayAccount;

// "SePay theo đối tác" — tài khoản ngân hàng nhận tiền đặt phòng qua SePay của đối tác Homestay. Đối tác bật SePay thì mọi đơn của
// đối tác (trừ chi nhánh có "PayOS theo chi nhánh") thanh toán bằng chuyển khoản vào tài khoản này và được xác nhận tự động qua
// webhook SePay; không bật thì dùng PayOS như cũ. Chỉ super_admin — đây là nơi đổi tiền chảy về tài khoản nào. Việc liên kết tài
// khoản ngân hàng với SePay làm trực tiếp trên dashboard SePay.
class PartnerSePayAccountResource extends Resource
{
    protected static ?string $model = PartnerSePayAccount::class;

    protected static ?string $navigationIcon = 'heroicon-o-qr-code';

    protected static ?string $navigationLabel = 'SePay theo đối tác';

    protected static ?string $modelLabel = 'tài khoản SePay đối tác';

    protected static ?string $pluralModelLabel = 'SePay theo đối tác';

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
        $data['updated_by'] = auth()->id();

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
                        ->unique(ignoreRecord: true)
                        ->validationMessages(['unique' => 'Đối tác này đã có tài khoản SePay — sửa dòng đang có thay vì tạo mới.']),

                    Forms\Components\Toggle::make('is_active')
                        ->label('Dùng SePay làm kênh thu tiền')
                        ->default(true)
                        ->inline(false)
                        ->helperText('Bật = đơn của đối tác thanh toán qua SePay (chuyển khoản vào tài khoản bên dưới). Tắt = dùng PayOS như cũ. Mã thanh toán đã tạo trước đó vẫn được xử lý bình thường.')
                        ->rules([
                            fn (Forms\Get $get) => function (string $attribute, $value, \Closure $fail) use ($get) {
                                if ($value && $get('partner_id') && PartnerPayOsAccount::query()->where('partner_id', $get('partner_id'))->where('is_active', true)->exists()) {
                                    $fail('Đối tác đang dùng kênh PayOS riêng. Tắt kênh đó ở "PayOS theo đối tác" trước, rồi mới bật SePay (mỗi đối tác chỉ dùng một kênh).');
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
                ->description('Phải GIỐNG TUYỆT ĐỐI tài khoản đã liên kết trên dashboard SePay. Mỗi số tài khoản chỉ gắn cho một đối tác.')
                ->schema([
                    Forms\Components\Select::make('bank_code')
                        ->label('Ngân hàng')
                        ->options(fn () => static::bankOptions())
                        ->searchable()
                        ->required(),
                    Forms\Components\TextInput::make('account_number')
                        ->label('Số tài khoản')
                        ->required()
                        ->maxLength(50)
                        ->regex('/^[0-9A-Za-z.-]+$/')
                        ->unique(ignoreRecord: true)
                        ->validationMessages(['unique' => 'Số tài khoản này đã gắn cho đối tác khác.', 'regex' => 'Số tài khoản chỉ gồm chữ, số, dấu chấm hoặc gạch ngang.']),
                    Forms\Components\TextInput::make('account_holder')
                        ->label('Chủ tài khoản')
                        ->required()
                        ->maxLength(255)
                        ->helperText('Không dấu, viết in hoa như trên tài khoản ngân hàng.'),
                ])
                ->columns(3),

            Forms\Components\Section::make('Cấu hình chung của SePay (làm một lần, dùng chung mọi đối tác)')
                ->description('Nhập ở CMS → Cấu hình web → "SePay — cấu hình chung": khoá bí mật webhook, API Token, tiền tố mã, công tắc bật/tắt.')
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
            ->modifyQueryUsing(fn (Builder $query) => $query->with('partner'))
            ->columns([
                Tables\Columns\TextColumn::make('partner.name')->label('Đối tác')->searchable()->wrap(),
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
