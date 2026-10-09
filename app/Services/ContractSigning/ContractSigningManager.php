<?php

declare(strict_types=1);

namespace App\Services\ContractSigning;

use App\Services\ContractSigning\Contracts\DigitalSignatureProvider;
use App\Settings\MinihouseSigningSettings;
use App\Settings\SigningSettings;
use Illuminate\Support\Manager;

// Chọn provider ký số. Cấu hình ưu tiên lấy từ trang web "Cấu hình web > Chữ ký số" (SigningSettings, lưu DB, thông tin nhạy cảm mã hoá);
// trường nào để trống thì dùng lại config/contract_signing.php (.env). Code gọi ký (ContractSignController, PartnerForm, MiniHouse
// ContractDocumentService) chỉ phụ thuộc interface DigitalSignatureProvider.
//
// Chọn nhà cung cấp THEO TỪNG BÊN, mỗi bên có cấu hình RIÊNG (nhà cung cấp + tài khoản): Homestay đọc SigningSettings, MiniHouse đọc
// MinihouseSigningSettings — xem forSide().
class ContractSigningManager extends Manager
{
    public const SIDE_HOMESTAY = 'homestay';

    public const SIDE_MINIHOUSE = 'minihouse';

    /** Nhà cung cấp chọn được ở trang Chữ ký số: mã => tên hiển thị. */
    public const PROVIDERS = [
        'local'        => 'Ký thử nghiệm (local) — tự sinh khoá, KHÔNG có giá trị pháp lý',
        'vnpt_smartca' => 'VNPT SmartCA (chữ ký số thật)',
        'misa_esign'   => 'MISA eSign (chữ ký số từ xa, chuẩn CSC)',
        'csc_custom'   => 'Nhà cung cấp khác theo chuẩn CSC (Viettel mySign, FPT, Savis…)',
    ];

    public function getDefaultDriver(): string
    {
        return $this->providerNameForSide(self::SIDE_HOMESTAY);
    }

    /** Tên nhà cung cấp của 1 bên — cấu hình của Homestay và MiniHouse lưu RIÊNG (SigningSettings / MinihouseSigningSettings). */
    public function providerNameForSide(string $side): string
    {
        return $this->setting($side, 'provider') ?: $this->config->get('contract_signing.default', 'local');
    }

    public function forSide(string $side): DigitalSignatureProvider
    {
        return $this->build($this->providerNameForSide($side), $side);
    }

    /** MiniHouse có bắt chủ trọ ký SỐ (Mức C) khi ký chốt hợp đồng thuê không — tắt thì chỉ ký tay + OTP (Mức A) như trước. */
    public function minihousePkiEnabled(): bool
    {
        try {
            return (bool) app(MinihouseSigningSettings::class)->pki_enabled;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Khoá lưu token OAuth của VNPT SmartCA theo bên — 2 bên có thể dùng 2 tài khoản VNPT khác nhau. */
    public static function vnptTokenKey(string $side): string
    {
        return $side === self::SIDE_MINIHOUSE ? 'vnpt_smartca:minihouse' : 'vnpt_smartca';
    }

    // Laravel Manager gọi các hàm create*Driver() cho Homestay (driver mặc định); MiniHouse đi qua forSide()/build() với cấu hình riêng.
    public function createLocalDriver(): DigitalSignatureProvider
    {
        return $this->build('local', self::SIDE_HOMESTAY);
    }

    public function createVnptSmartcaDriver(): DigitalSignatureProvider
    {
        return $this->build('vnpt_smartca', self::SIDE_HOMESTAY);
    }

    public function createMisaEsignDriver(): DigitalSignatureProvider
    {
        return $this->build('misa_esign', self::SIDE_HOMESTAY);
    }

    public function createCscCustomDriver(): DigitalSignatureProvider
    {
        return $this->build('csc_custom', self::SIDE_HOMESTAY);
    }

    private function build(string $name, string $side): DigitalSignatureProvider
    {
        return match ($name) {
            'vnpt_smartca' => new VnptSmartCaProvider(
                baseUrl: $this->value($side, 'base_url'),
                clientId: $this->value($side, 'client_id'),
                clientSecret: $this->value($side, 'client_secret'),
                subscriberUserId: $this->value($side, 'subscriber_user_id'),
                subscriberPassword: $this->value($side, 'subscriber_password'),
                tokenKey: self::vnptTokenKey($side),
            ),
            'misa_esign' => $this->cscProvider('misa_esign', 'misa', $side),
            'csc_custom' => $this->cscProvider('csc_custom', 'csc', $side),
            default => new LocalSelfSignedProvider(),
        };
    }

    /** Hồ sơ chuẩn CSC của 1 bên: giá trị nhập ở web của bên đó → ngược lại config/.env (providers.<name>.<key>). */
    public function cscProvider(string $name, string $prefix, string $side = self::SIDE_HOMESTAY): CscRemoteSigningProvider
    {
        $profile = [];
        foreach (['base_url', 'client_id', 'client_secret', 'username', 'password', 'credential_id', 'pin', 'grant_type'] as $key) {
            $profile[$key] = $this->setting($side, "{$prefix}_{$key}") ?: $this->config->get("contract_signing.providers.{$name}.{$key}");
        }

        return new CscRemoteSigningProvider($name, array_filter($profile, fn ($value) => filled($value)));
    }

    /** Giá trị nhập trong web của bên đó (nếu có) → ngược lại giá trị từ config/.env. */
    private function value(string $side, string $key): ?string
    {
        return $this->setting($side, $key) ?: $this->config->get("contract_signing.providers.vnpt_smartca.{$key}");
    }

    private function setting(string $side, string $key): ?string
    {
        try {
            $settings = $side === self::SIDE_MINIHOUSE ? app(MinihouseSigningSettings::class) : app(SigningSettings::class);
            $value = $settings->{$key};
        } catch (\Throwable $e) {
            // Chưa chạy migration settings hoặc APP_KEY không giải mã được → dùng .env
            return null;
        }

        return filled($value) ? (string) $value : null;
    }
}
