<?php

declare(strict_types=1);

namespace App\Services\ContractSigning;

use App\Services\ContractSigning\Contracts\DigitalSignatureProvider;
use App\Settings\SigningSettings;
use Illuminate\Support\Manager;

// Chọn provider ký số. Cấu hình ưu tiên lấy từ trang web "Cấu hình web > Chữ ký số" (SigningSettings, lưu DB, thông tin nhạy cảm mã hoá);
// trường nào để trống thì dùng lại config/contract_signing.php (.env). Code gọi ký (ContractSignController, PartnerForm) không đổi.
class ContractSigningManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return $this->setting('provider') ?: $this->config->get('contract_signing.default', 'local');
    }

    public function createLocalDriver(): DigitalSignatureProvider
    {
        return new LocalSelfSignedProvider();
    }

    public function createVnptSmartcaDriver(): DigitalSignatureProvider
    {
        return new VnptSmartCaProvider(
            baseUrl: $this->value('base_url'),
            clientId: $this->value('client_id'),
            clientSecret: $this->value('client_secret'),
            subscriberUserId: $this->value('subscriber_user_id'),
            subscriberPassword: $this->value('subscriber_password'),
        );
    }

    /** Giá trị nhập trong web (nếu có) → ngược lại giá trị từ config/.env. */
    private function value(string $key): ?string
    {
        return $this->setting($key) ?: $this->config->get("contract_signing.providers.vnpt_smartca.{$key}");
    }

    private function setting(string $key): ?string
    {
        try {
            $value = app(SigningSettings::class)->{$key};
        } catch (\Throwable $e) {
            // Chưa chạy migration settings hoặc APP_KEY không giải mã được → dùng .env
            return null;
        }

        return filled($value) ? (string) $value : null;
    }
}
