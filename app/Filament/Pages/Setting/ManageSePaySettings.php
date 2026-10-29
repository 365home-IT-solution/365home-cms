<?php

namespace App\Filament\Pages\Setting;

use App\Services\Payment\SePayService;
use App\Settings\SePaySettings;
use App\Support\SePayConfig;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Pages\SettingsPage;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

// Cấu hình CHUNG của SePay ngay trên CMS (không cần sửa .env): bật/tắt SePay, khoá bí mật webhook, tiền tố mã thanh toán, API Token (để hệ thống tự hỏi SePay
// khi webhook chậm), số tiền tối thiểu. Giá trị nhập ở đây được ưu tiên hơn biến SEPAY_* trong .env; để trống = theo .env. Khoá bí mật được mã hoá khi lưu
// và không bao giờ hiện lại — để trống ô khoá khi lưu là giữ nguyên khoá cũ. Tài khoản ngân hàng từng đối tác khai báo ở "SePay theo đối tác".
class ManageSePaySettings extends SettingsPage
{
    use HasPageShield;

    protected static string $settings = SePaySettings::class;

    protected static ?int $navigationSort = 95;

    protected static ?string $navigationIcon = 'heroicon-o-qr-code';

    protected static ?string $title = 'SePay — cấu hình chung';

    public static function getNavigationGroup(): ?string
    {
        return 'Cấu hình web';
    }

    public static function getNavigationLabel(): string
    {
        return 'SePay — cấu hình chung';
    }

    public static function canAccess(): bool
    {
        return (auth()->user()?->isSuperAdmin() ?? false) && parent::canAccess();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('testApi')
                ->label('Kiểm tra kết nối API SePay')
                ->icon('heroicon-o-signal')
                ->color('gray')
                ->action(function () {
                    $result = app(SePayService::class)->testApi();

                    Notification::make()->title($result['message'])->{$result['ok'] ? 'success' : 'danger'}()->persistent(! $result['ok'])->send();
                }),
        ];
    }

    private static function sourceLabel(string $key): string
    {
        return match (SePayConfig::source($key)) {
            'cms' => 'đang dùng giá trị nhập ở CMS',
            'env' => 'chưa nhập ở CMS — đang dùng .env của máy chủ',
            default => 'CHƯA cấu hình',
        };
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Bật / tắt SePay')
                ->description('Tắt: mọi đối tác quay về PayOS ngay (không xoá dữ liệu); các yêu cầu SePay đã tạo vẫn được xử lý nếu tiền về. Đối tác nào dùng SePay do mục "SePay theo đối tác" quyết định — đối tác chưa khai báo luôn dùng PayOS.')
                ->schema([
                    Forms\Components\Toggle::make('enabled')->label('Cho phép dùng SePay')->columnSpanFull(),
                ]),

            Forms\Components\Section::make('Webhook SePay')
                ->description('Nhập đúng các giá trị này vào webhook trên SePay (Tích hợp → Webhooks): URL, kiểu bảo mật HMAC-SHA256 với khoá bí mật bên dưới, tiền tố mã thanh toán.')
                ->schema([
                    Forms\Components\Placeholder::make('webhook_url')
                        ->label('Webhook URL')
                        ->content(fn () => new HtmlString('<code>' . e(SePayConfig::get('webhook_url') ?: route('webhook.sepay')) . '</code>')),

                    Forms\Components\TextInput::make('webhook_secret')
                        ->label('Khoá bí mật webhook (Secret Key)')
                        ->password()->revealable()
                        ->autocomplete(false)
                        ->placeholder('Để trống = giữ nguyên khoá hiện tại')
                        ->minLength(16)->maxLength(200)
                        ->helperText(fn () => 'Trạng thái: ' . self::sourceLabel('webhook_secret') . '. Phải GIỐNG TUYỆT ĐỐI khoá nhập ở webhook trên SePay. Bấm biểu tượng bên phải để tạo khoá ngẫu nhiên (nhớ chép sang SePay).')
                        ->suffixAction(
                            Forms\Components\Actions\Action::make('generateSecret')
                                ->icon('heroicon-o-arrow-path')
                                ->tooltip('Tạo khoá ngẫu nhiên')
                                ->action(fn (Set $set) => $set('webhook_secret', Str::random(40)))
                        ),

                    Forms\Components\TextInput::make('code_prefix')
                        ->label('Tiền tố mã thanh toán')
                        ->placeholder('DH')
                        ->minLength(2)->maxLength(5)
                        ->regex('/^[A-Za-z0-9]+$/')
                        ->helperText(fn () => 'Trạng thái: ' . self::sourceLabel('code_prefix') . ' (mặc định DH). Phải trùng "Cấu trúc mã thanh toán" trên SePay (2–5 ký tự, hậu tố số 6–8). Đổi tiền tố thì chỉ yêu cầu thanh toán TẠO SAU có tiền tố mới.')
                        ->dehydrateStateUsing(fn (?string $state) => filled($state) ? Str::upper(trim($state)) : null),
                ])->columns(1),

            Forms\Components\Section::make('API SePay (xác nhận ngay cả khi webhook chậm)')
                ->description('Có API Token thì khi khách mở trang QR / app hỏi trạng thái, hệ thống tự hỏi SePay và xác nhận đơn ngay; lệnh đồng bộ chạy mỗi phút làm dự phòng khi webhook lỡ. Không có token thì chỉ dựa vào webhook.')
                ->schema([
                    Forms\Components\TextInput::make('api_token')
                        ->label('API Token')
                        ->password()->revealable()
                        ->autocomplete(false)
                        ->placeholder('Để trống = giữ nguyên token hiện tại')
                        ->maxLength(500)
                        ->helperText(fn () => 'Trạng thái: ' . self::sourceLabel('api_token') . '. Tạo ở SePay → Cấu hình Công ty → API Access. Lưu rồi bấm "Kiểm tra kết nối API SePay" ở đầu trang.'),
                ]),

            Forms\Components\Section::make('Số tiền')
                ->schema([
                    Forms\Components\TextInput::make('min_amount')
                        ->label('Số tiền tối thiểu mỗi lần thu (đ)')
                        ->numeric()->integer()->minValue(1)->maxValue(1000000)
                        ->placeholder('1000')
                        ->helperText(fn () => 'Trạng thái: ' . self::sourceLabel('min_amount') . ' (mặc định 1.000đ). Chỉ áp dụng cho SePay; PayOS luôn tối thiểu 2.000đ.'),
                ]),
        ]);
    }

    // Công tắc hiện đúng trạng thái đang có hiệu lực; khoá bí mật không bao giờ đưa ra form.
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['enabled'] = SePayConfig::enabled();
        $data['webhook_secret'] = null;
        $data['api_token'] = null;

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $current = app(SePaySettings::class);

        $data['enabled'] = (bool) ($data['enabled'] ?? false);
        // Để trống = giữ nguyên khoá cũ (khoá đã lưu không hiện lại nên không thể "lưu lại nguyên giá trị").
        $data['webhook_secret'] = filled($data['webhook_secret'] ?? null) ? trim((string) $data['webhook_secret']) : $current->webhook_secret;
        $data['api_token'] = filled($data['api_token'] ?? null) ? trim((string) $data['api_token']) : $current->api_token;
        $data['code_prefix'] = filled($data['code_prefix'] ?? null) ? Str::upper(trim((string) $data['code_prefix'])) : null;
        $data['min_amount'] = filled($data['min_amount'] ?? null) ? (int) $data['min_amount'] : null;

        return $data;
    }

    public function getSavedNotification(): ?Notification
    {
        return Notification::make()->success()->title('Đã lưu cấu hình SePay — SePay đang ' . (SePayConfig::enabled() ? 'BẬT' : 'TẮT'));
    }
}
