<?php

namespace App\Filament\Pages\Setting;

use App\Models\Partner;
use App\Services\Payment\Pay2SCardService;
use App\Settings\Pay2SCardSettings;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\SettingsPage;
use Illuminate\Support\HtmlString;
use Modules\Category\Entities\Category;
use Modules\Minihouse\App\Support\HomestayBridge;

// Thanh toán THẺ (Visa / Mastercard / JCB / AMEX / UnionPay) qua Pay2S cho CHI NHÁNH của 365home — không áp dụng cho đối tác. Tiền thẻ về tài khoản Pay2S của 365home.
// Khoá lấy ở Pay2S → Thẻ & Ví điện tử → Kết nối. Khoá bí mật được mã hoá khi lưu và không hiện lại — để trống ô khi lưu là giữ nguyên.
class ManagePay2SCardSettings extends SettingsPage
{
    use HasPageShield;

    protected static string $settings = Pay2SCardSettings::class;

    protected static ?int $navigationSort = 97;

    protected static ?string $navigationIcon = 'heroicon-o-credit-card';

    protected static ?string $title = 'Pay2S — thanh toán thẻ';

    public static function getNavigationGroup(): ?string
    {
        return 'Cấu hình web';
    }

    public static function getNavigationLabel(): string
    {
        return 'Pay2S — thanh toán thẻ';
    }

    public static function canAccess(): bool
    {
        return (auth()->user()?->isSuperAdmin() ?? false) && parent::canAccess();
    }

    protected function getHeaderActions(): array
    {
        return [
            // Nút BẬT/TẮT: lưu ngay, không cần bấm "Lưu thay đổi". Tắt ⇒ khách và admin không thấy gì về thẻ, như chưa có tính năng.
            Action::make('toggleCard')
                ->label(fn () => app(Pay2SCardService::class)->status()['enabled'] ? 'Tắt thanh toán thẻ' : 'Bật thanh toán thẻ')
                ->icon(fn () => app(Pay2SCardService::class)->status()['enabled'] ? 'heroicon-o-pause-circle' : 'heroicon-o-play-circle')
                ->color(fn () => app(Pay2SCardService::class)->status()['enabled'] ? 'danger' : 'success')
                ->requiresConfirmation()
                ->modalHeading(fn () => app(Pay2SCardService::class)->status()['enabled'] ? 'Tắt thanh toán thẻ?' : 'Bật thanh toán thẻ?')
                ->modalDescription(fn () => app(Pay2SCardService::class)->status()['enabled']
                    ? 'Khách sẽ không còn thấy lựa chọn thẻ. Các phiên thẻ đã tạo vẫn được xác nhận nếu tiền về.'
                    : 'Khách ở các chi nhánh đã chọn sẽ thấy lựa chọn thanh toán thẻ.')
                ->action(function () {
                    $service = app(Pay2SCardService::class);
                    $status = $service->setEnabled(! $service->status()['enabled']);
                    $this->fillForm();

                    Notification::make()->success()->title('Thanh toán thẻ đang ' . ($status['enabled'] ? 'BẬT' : 'TẮT'))->send();
                }),
            Action::make('testConnection')
                ->label('Kiểm tra kết nối')
                ->icon('heroicon-o-signal')
                ->color('gray')
                ->action(function () {
                    $result = app(Pay2SCardService::class)->testConnection();

                    Notification::make()->title($result['message'])->{$result['ok'] ? 'success' : 'danger'}()->persistent(! $result['ok'])->send();
                }),
        ];
    }

    /** Chi nhánh của 365home (chưa thuộc đối tác thường). */
    private static function branchOptions(): array
    {
        $ownIds = Partner::query()->where('is_platform_partner', true)->pluck('id')->push(HomestayBridge::PARTNER_ID)->all();

        return Category::query()->whereNull('parent_id')->where('category_type', 'product')
            ->where(fn ($q) => $q->whereNull('partner_id')->orWhereIn('partner_id', $ownIds))
            ->orderBy('name')->pluck('name', 'id')->all();
    }

    public function form(Form $form): Form
    {
        $service = app(Pay2SCardService::class);

        return $form->schema([
            Forms\Components\Section::make('Bật / tắt')
                ->description('Khách ở chi nhánh được chọn bên dưới sẽ thấy nút "Thanh toán thẻ" cạnh QR chuyển khoản. Chuyển khoản của đối tác không bị ảnh hưởng.')
                ->schema([
                    Forms\Components\Toggle::make('enabled')->label('Cho phép thanh toán thẻ'),
                    Forms\Components\Toggle::make('sandbox_only')->label('Chế độ thử (sandbox)')
                        ->helperText('Bật: chỉ thẻ giả lập, nhưng đơn vẫn được đánh dấu đã thanh toán — chỉ dùng khi thử. Tắt khi Pay2S đã duyệt chạy thật.'),
                    Forms\Components\CheckboxList::make('category_ids')->label('Chi nhánh được thanh toán thẻ')
                        ->options(fn () => self::branchOptions())->columns(2)->bulkToggleable()
                        ->helperText('Gồm cả các khu vực con. Không chọn chi nhánh nào = không ai thấy nút thẻ.')
                        ->columnSpanFull(),
                ])->columns(2),

            Forms\Components\Section::make('Kết nối Pay2S')
                ->description('Lấy ở Pay2S → Thẻ & Ví điện tử → Kết nối. Cần gói Basic còn hạn; chạy thật cần Pay2S duyệt địa chỉ web (Origin) bên dưới.')
                ->schema([
                    Forms\Components\TextInput::make('partner_code')->label('Partner Code')->autocomplete('off')->maxLength(100),
                    // Trình duyệt coi ô chữ đứng trước ô mật khẩu là "tên đăng nhập" và tự điền email vào đây ⇒ ô mật khẩu bên dưới dùng new-password để chặn.
                    Forms\Components\TextInput::make('connection_id')->label('Connection ID')->autocomplete('off')->maxLength(100)
                        ->helperText('Để trống = kết nối mặc định của tài khoản Pay2S.'),
                    Forms\Components\TextInput::make('access_key')->label('Access Key')->password()->revealable()->autocomplete('new-password')->maxLength(500)
                        ->placeholder('Để trống = giữ nguyên')->helperText(fn () => $service->status()['access_key']['configured'] ? 'Đã lưu.' : 'CHƯA cấu hình.'),
                    Forms\Components\TextInput::make('secret_key')->label('Secret Key')->password()->revealable()->autocomplete('new-password')->maxLength(500)
                        ->placeholder('Để trống = giữ nguyên')->helperText(fn () => $service->status()['secret_key']['configured'] ? 'Đã lưu.' : 'CHƯA cấu hình.'),
                    Forms\Components\TextInput::make('merchant_origin')->label('Origin web (HTTPS)')->url()->maxLength(255)->placeholder(config('app.url'))
                        ->helperText('Gốc HTTPS đã đăng ký với Pay2S, không có đường dẫn (vd https://365home.vn). Để trống = APP_URL.'),
                    Forms\Components\TextInput::make('api_url')->label('API URL')->url()->maxLength(255)
                        ->placeholder('https://payment.pay2s.vn/api/card-popup-session.php')->helperText('Để trống = địa chỉ chính thức.'),
                    Forms\Components\Placeholder::make('webhook_url')->label('Webhook URL')
                        ->content(new HtmlString('<code>' . e(route('webhook.pay2s-card')) . '</code>'))
                        ->helperText('Tự gửi kèm mỗi phiên thanh toán, không cần khai báo ở Pay2S. Phải là HTTPS công khai.'),
                ])->columns(2),
        ]);
    }

    // Khoá bí mật không bao giờ đưa ra form.
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['access_key'] = null;
        $data['secret_key'] = null;
        $data['category_ids'] = array_map('strval', (array) ($data['category_ids'] ?? []));

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $current = app(Pay2SCardSettings::class);

        foreach (['access_key', 'secret_key'] as $key) {
            $data[$key] = filled($data[$key] ?? null) ? trim((string) $data[$key]) : $current->{$key};
        }
        foreach (['merchant_origin', 'api_url'] as $key) {
            $data[$key] = filled($data[$key] ?? null) ? rtrim(trim((string) $data[$key]), '/') : null;
        }
        $data['category_ids'] = array_values(array_map('intval', (array) ($data['category_ids'] ?? [])));

        return $data;
    }

    public function getSavedNotification(): ?Notification
    {
        $enabled = app(Pay2SCardService::class)->status()['enabled'];

        return Notification::make()->success()->title('Đã lưu — thanh toán thẻ đang ' . ($enabled ? 'BẬT' : 'TẮT'));
    }
}
