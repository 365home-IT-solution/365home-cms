<?php

declare(strict_types=1);

namespace App\Services\ContractSigning;

use App\Services\ContractSigning\Contracts\DigitalSignatureProvider;
use Illuminate\Support\Manager;

// Chọn provider ký số theo config/contract_signing.php (mặc định đọc từ .env
// CONTRACT_SIGNING_PROVIDER) — đây là ĐIỂM DUY NHẤT cần đổi khi chuyển từ ký test (local) sang ký
// số thật (VNPT SmartCA/MISA) đã có tài khoản doanh nghiệp: chỉ đổi biến môi trường, không sửa
// code gọi ký (ContractSignController, PartnerForm).
class ContractSigningManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return $this->config->get('contract_signing.default', 'local');
    }

    public function createLocalDriver(): DigitalSignatureProvider
    {
        return new LocalSelfSignedProvider();
    }

    public function createVnptSmartcaDriver(): DigitalSignatureProvider
    {
        return new VnptSmartCaProvider(
            baseUrl: $this->config->get('contract_signing.providers.vnpt_smartca.base_url'),
            clientId: $this->config->get('contract_signing.providers.vnpt_smartca.client_id'),
            clientSecret: $this->config->get('contract_signing.providers.vnpt_smartca.client_secret'),
            subscriberUserId: $this->config->get('contract_signing.providers.vnpt_smartca.subscriber_user_id'),
            subscriberPassword: $this->config->get('contract_signing.providers.vnpt_smartca.subscriber_password'),
        );
    }
}
