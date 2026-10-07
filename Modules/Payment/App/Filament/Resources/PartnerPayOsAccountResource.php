<?php

namespace Modules\Payment\App\Filament\Resources;

use App\Models\Partner;
use App\Services\Payment\PartnerPayOsChannelService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Modules\Minihouse\App\Support\HomestayBridge;
use Modules\Payment\App\Filament\Resources\PartnerPayOsAccountResource\Pages;
use Modules\Payment\Entities\PartnerPayOsAccount;

// "PayOS theo đối tác" — kênh PayOS RIÊNG của đối tác Homestay: khách đặt phòng ở mọi chi nhánh của đối tác thanh toán
// thẳng vào tài khoản đối tác thay vì tài khoản chung. Đây là nơi cấu hình CHÍNH; "PayOS theo chi nhánh" chỉ còn dùng để
// ghi đè cho chi nhánh có tài khoản khác. Chỉ super_admin — đây là nơi đổi tiền chảy về tài khoản nào. Lưu qua
// PartnerPayOsChannelService (dùng chung với API) để luôn gọi thử PayOS, đối chiếu chủ tài khoản và ghi lịch sử hồ sơ.
class PartnerPayOsAccountResource extends Resource
{
    protected static ?string $model = PartnerPayOsAccount::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'PayOS theo đối tác';

    protected static ?string $modelLabel = 'kênh PayOS đối tác';

    protected static ?string $pluralModelLabel = 'PayOS theo đối tác';

    public static function getNavigationGroup(): ?string
    {
        return __('payment::payment-configuration.resource.navigation_group');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
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
                        ->disabledOn('edit')
                        ->unique(ignoreRecord: true)
                        ->validationMessages(['unique' => 'Đối tác này đã có kênh PayOS — sửa dòng đang có thay vì tạo mới.'])
                        ->helperText('Áp dụng cho mọi chi nhánh của đối tác (trừ chi nhánh có ghi đè ở "PayOS theo chi nhánh").'),

                    Forms\Components\Toggle::make('is_active')
                        ->label('Đang dùng kênh riêng')
                        ->helperText('Tắt = 365home thu hộ qua tài khoản PayOS chung. Link đã tạo trước đó vẫn được tra cứu/xác nhận bình thường.')
                        ->default(true),

                    Forms\Components\TextInput::make('note')
                        ->label('Ghi chú')
                        ->maxLength(255),
                ])
                ->columns(2),

            Forms\Components\Section::make('Thông tin kênh thanh toán PayOS của đối tác')
                ->description('Lấy tại my.payos.vn → Kênh thanh toán → chọn kênh của đối tác. Khi lưu, hệ thống gọi thử PayOS và đối chiếu chủ tài khoản với tab Tài chính của đối tác; sai thì không lưu. Các khoá được mã hoá.')
                ->schema([
                    Forms\Components\TextInput::make('client_id')
                        ->label('Client ID')
                        ->password()->revealable()
                        ->required(fn (string $operation) => $operation === 'create'),
                    Forms\Components\TextInput::make('api_key')
                        ->label('API Key')
                        ->password()->revealable()
                        ->required(fn (string $operation) => $operation === 'create')
                        ->helperText(fn (string $operation) => $operation === 'edit' ? 'Để trống = giữ nguyên.' : null),
                    Forms\Components\TextInput::make('checksum_key')
                        ->label('Checksum Key')
                        ->password()->revealable()
                        ->required(fn (string $operation) => $operation === 'create')
                        ->helperText(fn (string $operation) => $operation === 'edit' ? 'Để trống = giữ nguyên.' : null),
                    Forms\Components\Placeholder::make('webhook_hint')
                        ->label('Webhook URL')
                        ->content(fn () => new HtmlString(
                            '<code>' . e(route('webhook.payos')) . '</code><br>'
                            . '<span style="font-size:0.8rem;color:#6b7280;">Hệ thống tự đăng ký URL này khi lưu; chưa được thì bấm "Đăng ký webhook" ở danh sách.</span>'
                        )),
                ])
                ->columns(1),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('partner'))
            ->columns([
                Tables\Columns\TextColumn::make('partner.name')
                    ->label('Đối tác')
                    ->searchable()
                    ->wrap(),
                Tables\Columns\TextColumn::make('account_holder')
                    ->label('Chủ tài khoản')
                    ->placeholder('—'),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Đang dùng')
                    ->boolean(),
                Tables\Columns\TextColumn::make('webhook_confirmed_at')
                    ->label('Webhook')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('Chưa đăng ký'),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Cập nhật')
                    ->dateTime('d/m/Y H:i'),
            ])
            ->actions([
                Tables\Actions\Action::make('confirmWebhook')
                    ->label('Đăng ký webhook')
                    ->icon('heroicon-o-link')
                    ->requiresConfirmation()
                    ->modalDescription(fn () => 'Đăng ký ' . route('webhook.payos') . ' làm webhook cho kênh PayOS này.')
                    ->action(function (PartnerPayOsAccount $record) {
                        app(PartnerPayOsChannelService::class)->confirmWebhook($record)
                            ? Notification::make()->success()->title('Đã đăng ký webhook thành công')->send()
                            : Notification::make()->danger()->title('Không đăng ký được webhook')
                                ->body('Kiểm tra lại Client ID/API Key, và URL phải truy cập được công khai (không chạy được trên máy local).')
                                ->persistent()->send();
                    }),
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListPartnerPayOsAccounts::route('/'),
            'create' => Pages\CreatePartnerPayOsAccount::route('/create'),
            'edit'   => Pages\EditPartnerPayOsAccount::route('/{record}/edit'),
        ];
    }
}
