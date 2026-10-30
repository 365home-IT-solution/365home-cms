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
                ->description('Tắt: mọi đối tác dùng Pay2S quay về PayOS ngay (không xoá dữ liệu); các yêu cầu Pay2S đã tạo vẫn được xử lý nếu tiền về. Đối tác nào dùng Pay2S do mục "SePay / Pay2S theo đối tác" quyết định — đối tác chưa khai báo luôn dùng PayOS.')
                ->schema([
                    Forms\Components\Toggle::make('enabled')->label('Cho phép dùng Pay2S')->columnSpanFull(),
                ]),

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
        ]);
    }

    // Công tắc hiện đúng trạng thái đang có hiệu lực; token không bao giờ đưa ra form.
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['enabled'] = Pay2SConfig::enabled();
        $data['api_token'] = null;

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
