<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\ContractSigningToken;
use App\Services\ContractSigning\ContractSigningManager;
use App\Services\ContractSigning\CscRemoteSigningProvider;
use App\Services\ContractSigning\VnptSmartCaProvider;
use Filament\Forms;
use Filament\Forms\Get;
use Filament\Notifications\Notification;

// Phần form chọn nhà cung cấp chữ ký số + nhập thông tin từng nhà cung cấp — DÙNG CHUNG cho 2 trang cấu hình TÁCH BIỆT:
//   · Homestay: App\Filament\Pages\Setting\ManageSigning (lưu SigningSettings)
//   · MiniHouse: Modules\Minihouse\...\ManageSigningSettings (lưu MinihouseSigningSettings)
// Hai trang có cùng tên trường nhưng LƯU Ở 2 NƠI khác nhau — đổi cấu hình bên này không ảnh hưởng bên kia.
class SigningProviderForm
{
    public static function providerSelect(string $label): Forms\Components\Select
    {
        return Forms\Components\Select::make('provider')->label($label)->native(false)
            ->options(ContractSigningManager::PROVIDERS)->placeholder('Theo cấu hình .env')->live();
    }

    /** @return array<int, Forms\Components\Section> khối nhập thông tin của nhà cung cấp đang được chọn ở ô `provider`. */
    public static function sections(): array
    {
        return [
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

            self::cscSection('misa', 'MISA eSign', 'Tài khoản/tài liệu tích hợp do MISA cấp cho đối tác (chuẩn CSC). Thông tin được mã hoá khi lưu — bấm "Kiểm tra kết nối" trước khi dùng.', 'misa_esign'),

            self::cscSection('csc', 'Nhà cung cấp khác (chuẩn CSC)', 'Dùng cho Viettel mySign, FPT eSign, Savis… có mở API ký số từ xa chuẩn Cloud Signature Consortium. Thông tin được mã hoá khi lưu.', 'csc_custom'),
        ];
    }

    private static function cscSection(string $prefix, string $title, string $description, string $providerKey): Forms\Components\Section
    {
        return Forms\Components\Section::make($title)
            ->description($description)
            ->visible(fn (Get $get) => $get('provider') === $providerKey)
            ->schema([
                Forms\Components\TextInput::make("{$prefix}_label")->label('Tên hiển thị')->maxLength(80)
                    ->visible($prefix === 'csc')->placeholder('Viettel mySign / FPT eSign…'),
                Forms\Components\TextInput::make("{$prefix}_base_url")->label('Domain gốc API (Base URL)')->url()->placeholder('https://…')
                    ->helperText('Địa chỉ gốc của dịch vụ ký số từ xa (không kèm /oauth2/token, /credentials…) do nhà cung cấp cấp cho đối tác tích hợp.'),
                Forms\Components\Select::make("{$prefix}_grant_type")->label('Cách đăng nhập API')->native(false)
                    ->options(['client_credentials' => 'Chỉ client id/secret (client_credentials)', 'password' => 'Kèm tài khoản thuê bao (password)'])
                    ->placeholder('Chỉ client id/secret (mặc định)')->live(),
                Forms\Components\TextInput::make("{$prefix}_client_id")->label('Client ID'),
                Forms\Components\TextInput::make("{$prefix}_client_secret")->label('Client Secret')->password()->revealable(),
                Forms\Components\TextInput::make("{$prefix}_username")->label('Tài khoản thuê bao')
                    ->visible(fn (Get $get) => $get("{$prefix}_grant_type") === 'password'),
                Forms\Components\TextInput::make("{$prefix}_password")->label('Mật khẩu thuê bao')->password()->revealable()
                    ->visible(fn (Get $get) => $get("{$prefix}_grant_type") === 'password'),
                Forms\Components\TextInput::make("{$prefix}_credential_id")->label('Mã chứng thư số (credentialID)')
                    ->helperText('Để trống = dùng chứng thư đầu tiên của thuê bao.'),
                Forms\Components\TextInput::make("{$prefix}_pin")->label('PIN chứng thư (nếu nhà cung cấp yêu cầu)')->password()->revealable(),
            ])->columns(2);
    }

    /** Giá trị mặc định khi không giải mã được cấu hình đã lưu (APP_KEY khác): form trống, không làm sập trang. */
    public static function emptyState(): array
    {
        $data = array_fill_keys(['provider', 'base_url', 'client_id', 'client_secret', 'subscriber_user_id', 'subscriber_password'], null);
        foreach (['misa', 'csc'] as $profile) {
            foreach (['label', 'base_url', 'grant_type', 'client_id', 'client_secret', 'username', 'password', 'credential_id', 'pin'] as $key) {
                $data["{$profile}_{$key}"] = null;
            }
        }

        return $data;
    }

    /** Kiểm tra kết nối nhà cung cấp đang chọn bằng thông tin ĐANG NHẬP (chưa lưu) — không tốn lượt ký, không gửi xác nhận về điện thoại. */
    public static function test(array $state, string $side = ContractSigningManager::SIDE_HOMESTAY): void
    {
        $provider = ($state['provider'] ?? null) ?: config('contract_signing.default', 'local');
        $label = ContractSigningManager::PROVIDERS[$provider] ?? $provider;

        if ($provider === 'local') {
            Notification::make()->title('Ký thử nghiệm (local) — không cần kết nối nhà cung cấp.')->success()->send();

            return;
        }

        if ($provider === 'vnpt_smartca') {
            $driver = new VnptSmartCaProvider(
                baseUrl: ($state['base_url'] ?? null) ?: config('contract_signing.providers.vnpt_smartca.base_url'),
                clientId: ($state['client_id'] ?? null) ?: config('contract_signing.providers.vnpt_smartca.client_id'),
                clientSecret: ($state['client_secret'] ?? null) ?: config('contract_signing.providers.vnpt_smartca.client_secret'),
                subscriberUserId: ($state['subscriber_user_id'] ?? null) ?: config('contract_signing.providers.vnpt_smartca.subscriber_user_id'),
                subscriberPassword: ($state['subscriber_password'] ?? null) ?: config('contract_signing.providers.vnpt_smartca.subscriber_password'),
                tokenKey: ContractSigningManager::vnptTokenKey($side),
            );
            // Kiểm tra bằng thông tin đang nhập (chưa lưu) nên xoá token cũ CỦA BÊN NÀY để không dùng nhầm token của cấu hình trước.
            ContractSigningToken::query()->where('provider', ContractSigningManager::vnptTokenKey($side))->delete();
        } else {
            $prefix = $provider === 'misa_esign' ? 'misa' : 'csc';
            $config = [];
            foreach (['base_url', 'client_id', 'client_secret', 'username', 'password', 'credential_id', 'pin', 'grant_type'] as $key) {
                // Ô ẩn theo ngữ cảnh (vd tài khoản thuê bao khi chỉ dùng client id/secret) không có trong state của form → coi như trống.
                $config[$key] = ($state["{$prefix}_{$key}"] ?? null) ?: config("contract_signing.providers.{$provider}.{$key}");
            }
            $driver = new CscRemoteSigningProvider($provider, array_filter($config, fn ($value) => filled($value)));
        }

        $result = $driver->testConnection();

        Notification::make()->title("{$label}: {$result['message']}")->{$result['ok'] ? 'success' : 'danger'}()->persistent($result['ok'] === false)->send();
    }
}
