<?php

namespace App\Filament\Pages\Setting;

use App\Models\ContractSigningToken;
use App\Services\ContractSigning\ContractSigningManager;
use App\Services\ContractSigning\VnptSmartCaProvider;
use App\Settings\SigningSettings;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\SettingsPage;
use Illuminate\Support\Facades\Log;

// Cấu hình CHỮ KÝ SỐ ngay trong web (thay cho sửa .env): chọn provider, nhập thông tin VNPT SmartCA, kiểm tra kết nối (không tốn lượt ký).
class ManageSigning extends SettingsPage
{
    use HasPageShield;

    protected static string $settings = SigningSettings::class;

    protected static ?int $navigationSort = 98;

    protected static ?string $navigationIcon = 'heroicon-o-finger-print';

    protected static ?string $title = 'Chữ ký số';

    public static function getNavigationGroup(): ?string
    {
        return 'Cấu hình web';
    }

    public static function getNavigationLabel(): string
    {
        return 'Chữ ký số';
    }

    // Dữ liệu mã hoá bằng APP_KEY khác (import DB từ máy khác...) không được làm sập trang: coi như chưa nhập.
    protected function fillForm(): void
    {
        $this->callHook('beforeFill');

        try {
            $data = app(static::getSettings())->toArray();
        } catch (\Throwable $e) {
            Log::warning('ManageSigning: không giải mã được cấu hình chữ ký số đã lưu — hiển thị form trống.', ['error' => $e->getMessage()]);
            $data = ['provider' => null, 'base_url' => null, 'client_id' => null, 'client_secret' => null, 'subscriber_user_id' => null, 'subscriber_password' => null];
        }

        $this->form->fill($this->mutateFormDataBeforeFill($data));

        $this->callHook('afterFill');
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Nhà cung cấp chữ ký số')
                ->description('Nhập trực tiếp tại đây — không cần sửa file .env. Để trống ô nào thì hệ thống dùng lại giá trị trong .env (nếu có).')
                ->schema([
                    Forms\Components\Select::make('provider')->label('Nhà cung cấp')->native(false)
                        ->options([
                            'local' => 'Ký thử nghiệm (local) — tự sinh khoá, KHÔNG có giá trị pháp lý',
                            'vnpt_smartca' => 'VNPT SmartCA (chữ ký số thật)',
                        ])->placeholder('Theo cấu hình .env')->live(),
                ]),

            Forms\Components\Section::make('VNPT SmartCA')
                ->description('Lấy từ cổng đối tác doitac-smartca.vnpt.vn. Thông tin dưới đây được mã hoá khi lưu.')
                ->visible(fn (Get $get) => $get('provider') === 'vnpt_smartca')
                ->schema([
                    Forms\Components\TextInput::make('base_url')->label('Domain gốc (Base URL)')->url()->placeholder('https://gwsca.vnpt.vn')
                        ->helperText('Không kèm đường dẫn /auth hay /sca. Môi trường thử: https://rmgateway.vnptit.vn'),
                    Forms\Components\TextInput::make('client_id')->label('Client ID'),
                    Forms\Components\TextInput::make('client_secret')->label('Client Secret')->password()->revealable(),
                    Forms\Components\TextInput::make('subscriber_user_id')->label('CCCD chủ chứng thư (tên đăng nhập thuê bao)'),
                    Forms\Components\TextInput::make('subscriber_password')->label('Mật khẩu thuê bao')->password()->revealable()
                        ->helperText('Chỉ dùng để đăng nhập lần đầu; các lần ký sau dùng refresh token (~3 tháng).'),
                ])->columns(2),
        ]);
    }

    public function getFormActions(): array
    {
        return [
            $this->getSaveFormAction(),
            Action::make('testConnection')->label('Kiểm tra kết nối')->color('gray')->icon('heroicon-o-signal')
                ->action(fn () => $this->testConnection()),
        ];
    }

    // Lưu xong thì xoá token đăng nhập cũ để lần sau đăng nhập lại bằng thông tin mới.
    protected function afterSave(): void
    {
        ContractSigningToken::query()->delete();
    }

    public function testConnection(): void
    {
        $state = $this->form->getState();
        $provider = $state['provider'] ?: config('contract_signing.default', 'local');

        if ($provider !== 'vnpt_smartca') {
            Notification::make()->title('Đang dùng ký thử nghiệm (local) — không cần kết nối nhà cung cấp.')->success()->send();

            return;
        }

        $driver = new VnptSmartCaProvider(
            baseUrl: $state['base_url'] ?: config('contract_signing.providers.vnpt_smartca.base_url'),
            clientId: $state['client_id'] ?: config('contract_signing.providers.vnpt_smartca.client_id'),
            clientSecret: $state['client_secret'] ?: config('contract_signing.providers.vnpt_smartca.client_secret'),
            subscriberUserId: $state['subscriber_user_id'] ?: config('contract_signing.providers.vnpt_smartca.subscriber_user_id'),
            subscriberPassword: $state['subscriber_password'] ?: config('contract_signing.providers.vnpt_smartca.subscriber_password'),
        );
        // Kiểm tra bằng thông tin đang nhập (chưa lưu) nên xoá token cũ để không dùng nhầm token của cấu hình trước.
        ContractSigningToken::query()->delete();
        $result = $driver->testConnection();

        Notification::make()->title($result['message'])->{$result['ok'] ? 'success' : 'danger'}()->persistent($result['ok'] === false)->send();
    }
}
