<?php

namespace Modules\Payment\App\Filament\Resources\PartnerPayOsAccountResource\Pages;

use App\Models\Partner;
use App\Services\Payment\PartnerPayOsChannelService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Modules\Payment\App\Filament\Resources\PartnerPayOsAccountResource;

class CreatePartnerPayOsAccount extends CreateRecord
{
    protected static string $resource = PartnerPayOsAccountResource::class;

    // Lưu qua service dùng chung với API: gọi thử PayOS, đối chiếu chủ tài khoản, ghi lịch sử hồ sơ, đăng ký webhook.
    protected function handleRecordCreation(array $data): Model
    {
        return app(PartnerPayOsChannelService::class)->save(Partner::findOrFail($data['partner_id']), $data, auth()->user());
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
