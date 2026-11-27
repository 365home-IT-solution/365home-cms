<?php

namespace App\Filament\Pages\Setting;

use App\Services\Payment\Pay2SService;
use App\Services\Payment\PaymentGatewaySettingsService;
use App\Settings\Pay2SSettings;
use App\Support\Pay2SConfig;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\SettingsPage;
use Illuminate\Support\HtmlString;

// Cấu hình CHUNG của Pay2S ngay trên CMS (không cần sửa .env): bật/tắt Pay2S và API Token (để hệ thống tự hỏi Pay2S khi webhook chậm/lỡ). Giá trị nhập ở đây được
// ưu tiên hơn biến PAY2S_* trong .env; để trống = theo .env. API Token được mã hoá khi lưu và không bao giờ hiện lại — để trống ô khi lưu là giữ nguyên token cũ.
// Token webhook là của TỪNG tài khoản ngân hàng nên nhập ở "SePay / Pay2S theo đối tác".
class ManagePay2SSettings extends SettingsPage
{
    use HasPageShield;

    protected static string $settings = Pay2SSettings::class;

    protected static ?int $navigationSort = 96;

    protected static ?string $navigationIcon = 'heroicon-o-qr-code';

    protected static ?string $title = 'Pay2S — cấu hình chung';

    public static function getNavigationGroup(): ?string
    {
        return 'Cấu hình web';
    }

    public static function getNavigationLabel(): string
    {
        return 'Pay2S — cấu hình chung';
    }

    public static function canAccess(): bool
    {
        return (auth()->user()?->isSuperAdmin() ?? false) && parent::canAccess();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('testApi')
                ->label('Kiểm tra kết nối API Pay2S')
                ->icon('heroicon-o-signal')
                ->color('gray')
                ->action(function () {
                    $result = app(Pay2SService::class)->testApi();

                    Notification::make()->title($result['message'])->{$result['ok'] ? 'success' : 'danger'}()->persistent(! $result['ok'])->send();
                }),
            Action::make('testPartnerApi')
                ->label('Kiểm tra khoá Partner API')
                ->icon('heroicon-o-key')
                ->color('gray')
                ->action(function () {
                    $result = app(\App\Services\Payment\Pay2SBankHubService::class)->testConnection();

                    Notification::make()->title($result['message'])->{$result['ok'] ? 'success' : 'danger'}()->persistent(! $result['ok'])->send();
                }),
        ];
    }

    private static function sourceLabel(string $key): string
    {
        return match (Pay2SConfig::source($key)) {
            'cms' => 'đang dùng giá trị nhập ở CMS',
            'env' => 'chưa nhập ở CMS — đang dùng .env của máy chủ',
            default => 'CHƯA cấu hình',
        };
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Bật / tắt Pay2S')
                ->description('Tắt: mọi đối tác dùng Pay2S quay về PayOS ngay (không xoá dữ liệu); các yêu cầu Pay2S đã tạo vẫn được xử lý nếu tiền về. Đối tác nào dùng tài khoản riêng của mình do mục "SePay / Pay2S theo đối tác" quyết định; khoản 365 thu hộ theo mục "Cổng chuyển khoản mặc định" bên dưới.')
                ->schema([
                    Forms\Components\Toggle::make('enabled')->label('Cho phép dùng Pay2S')->columnSpanFull(),
                ]),

            Forms\Components\Section::make('Cổng chuyển khoản mặc định (PayOS ⇄ Pay2S)')
                ->description('Chọn cổng cho khoản thu MỚI: đơn đặt phòng không có kênh riêng của đối tác, gói dịch vụ, ký quỹ, nộp hoa hồng đối soát, thuê xe, hoá đơn MiniHouse. Chọn Pay2S cần khai báo đủ tài khoản ngân hàng của 365home bên dưới. Đổi lại PayOS bất cứ lúc nào để quay về như cũ — yêu cầu thanh toán đã tạo ở cổng nào vẫn nhận tiền ở cổng đó. Đối tác / toà nhà đang có PayOS riêng giữ PayOS cho tới khi được chuyển sang Pay2S.')
                ->schema([
                    Forms\Components\Select::make('default_gateway')->label('Cổng đang dùng')->native(false)->selectablePlaceholder(false)
                        ->options(['payos' => 'PayOS (như trước đây)', 'pay2s' => 'Pay2S — chuyển khoản vào tài khoản 365home'])->live(),
                    Forms\Components\Select::make('platform_bank_bin')->label('Ngân hàng của 365home')->searchable()->native(false)->live()
                        ->required(fn (\Filament\Forms\Get $get) => $get('default_gateway') === 'pay2s')
                        ->options(fn () => \Modules\Minihouse\App\Support\VietnameseBanks::options())
                        ->afterStateUpdated(fn (\Filament\Forms\Set $set, $state) => $set('platform_bank_code', \Modules\Minihouse\App\Support\VietnameseBanks::shortName($state))),
                    Forms\Components\Hidden::make('platform_bank_code'),
                    Forms\Components\TextInput::make('platform_account_number')->label('Số tài khoản')->maxLength(50)
                        ->required(fn (\Filament\Forms\Get $get) => $get('default_gateway') === 'pay2s'),
                    Forms\Components\TextInput::make('platform_account_holder')->label('Tên chủ tài khoản')->helperText('Không dấu, in hoa — đúng như trên tài khoản ngân hàng.')->maxLength(255)
                        ->required(fn (\Filament\Forms\Get $get) => $get('default_gateway') === 'pay2s'),
                    Forms\Components\TextInput::make('platform_webhook_token')->label('Token webhook của tài khoản này')->password()->revealable()->autocomplete(false)
                        ->placeholder('Để trống = giữ nguyên token hiện tại')->maxLength(1000)
                        ->helperText('Pay2S sinh một token riêng cho webhook của tài khoản này (my.pay2s.vn → Webhooks, URL ở mục bên dưới).'),
                ])->columns(2),

            Forms\Components\Section::make('Webhook Pay2S')
                ->description('Tạo webhook cho TỪNG tài khoản ngân hàng ở my.pay2s.vn → Webhooks → Thêm webhook: chọn tài khoản, loại "Nhận tiền", URL bên dưới. Pay2S hiện token một lần — chép token vào tài khoản đối tác ở "SePay / Pay2S theo đối tác". Tiền tố mã thanh toán dùng chung với SePay (mặc định DH).')
                ->schema([
                    Forms\Components\Placeholder::make('webhook_url')
                        ->label('Webhook URL')
                        ->content(fn () => new HtmlString('<code>' . e(Pay2SConfig::get('webhook_url') ?: route('webhook.pay2s')) . '</code>')),
                ]),

            Forms\Components\Section::make('API Pay2S (xác nhận ngay cả khi webhook chậm)')
                ->description('Pay2S chỉ gửi lại webhook 5 lần cách nhau 60 giây. Có API Token thì khi khách mở trang QR / app hỏi trạng thái, hệ thống tự hỏi Pay2S và xác nhận đơn ngay; lệnh đồng bộ chạy mỗi phút làm dự phòng khi webhook lỡ.')
                ->schema([
                    Forms\Components\TextInput::make('api_token')
                        ->label('API Token')
                        ->password()->revealable()
                        ->autocomplete(false)
                        ->placeholder('Để trống = giữ nguyên token hiện tại')
                        ->maxLength(1000)
                        ->helperText(fn () => 'Trạng thái: ' . self::sourceLabel('api_token') . '. Lấy ở my.pay2s.vn → Tích hợp Web/App (token từ SecretKey). Lưu rồi bấm "Kiểm tra kết nối API Pay2S" ở đầu trang.'),
                ]),

            Forms\Components\Section::make('Partner API — đối tác tự liên kết ngân hàng')
                ->description('Khoá để đối tác tự liên kết ngân hàng với Pay2S ngay trên web/app của mình (Hosted Link — OTP nhập trên trang Pay2S, hệ thống không chạm OTP). Cả 4 ô lấy ở merchant.pay2s.vn → Bankhub → Thiết lập (KHÁC API Token ở mục trên, lấy ở my.pay2s.vn). Trước khi dùng, ở đó cần: bật "Cho phép tạo liên kết ngân hàng", lưu địa chỉ HTTPS của web/app vào danh sách return URL, và chọn ngân hàng hiển thị trên Hosted Link. Khoá nhập ở đây được ưu tiên hơn .env; để trống ô khoá = giữ nguyên khoá hiện tại.')
                ->schema([
                    Forms\Components\TextInput::make('partner_access_key')->label('Access Key')->password()->revealable()->autocomplete(false)->maxLength(500)
                        ->placeholder('Để trống = giữ nguyên')->helperText(fn () => 'Trạng thái: ' . self::sourceLabel('partner_access_key') . '.'),
                    Forms\Components\TextInput::make('partner_secret_key')->label('Secret Key')->password()->revealable()->autocomplete(false)->maxLength(500)
                        ->placeholder('Để trống = giữ nguyên')->helperText(fn () => 'Trạng thái: ' . self::sourceLabel('partner_secret_key') . '.'),
                    Forms\Components\TextInput::make('partner_api_url')->label('API URL')->url()->maxLength(255)
                        ->placeholder('https://api-partner.pay2s.vn')->helperText('Để trống = bản chính thức. Thử nghiệm (UAT): https://api-partner-uat.pay2s.vn (OTP cố định 123456). UAT có Access/Secret Key riêng — đổi URL thì phải đổi cả cặp khoá.'),
                    Forms\Components\TextInput::make('partner_parent_origin')->label('Origin web/app')->url()->maxLength(255)
                        ->placeholder(config('app.url'))->helperText('Gốc HTTPS của web/app, không có đường dẫn (vd https://365home.vn), phải trùng gốc của một return URL đã lưu ở Bankhub → Thiết lập. Để trống = APP_URL.'),
                ])->columns(2),
        ]);
    }

    // Công tắc hiện đúng trạng thái đang có hiệu lực; token không bao giờ đưa ra form.
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['enabled'] = Pay2SConfig::enabled();
        $data['api_token'] = null;
        $data['partner_access_key'] = null;
        $data['partner_secret_key'] = null;
        $data['platform_webhook_token'] = null;
        $data['default_gateway'] = Pay2SConfig::gateway();

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Cùng một bộ chuẩn hoá với API admin (PaymentGatewaySettingsService): token để trống = giữ nguyên token cũ.
        return app(PaymentGatewaySettingsService::class)->normalize('pay2s', $data, app(Pay2SSettings::class));
    }

    public function getSavedNotification(): ?Notification
    {
        return Notification::make()->success()->title('Đã lưu cấu hình Pay2S — Pay2S đang ' . (Pay2SConfig::enabled() ? 'BẬT' : 'TẮT'));
    }
}
