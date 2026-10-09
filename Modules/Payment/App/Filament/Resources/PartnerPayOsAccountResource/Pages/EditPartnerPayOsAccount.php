<?php

namespace Modules\Payment\App\Filament\Resources\PartnerPayOsAccountResource\Pages;

use App\Services\Payment\PartnerPayOsChannelService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Modules\Payment\App\Filament\Resources\PartnerPayOsAccountResource;

class EditPartnerPayOsAccount extends EditRecord
{
    protected static string $resource = PartnerPayOsAccountResource::class;

    // Không đưa khoá bí mật đã lưu ra form — để trống khi lưu = giữ nguyên.
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return [...$data, 'client_id' => $this->record->client_id, 'api_key' => null, 'checksum_key' => null];
    }

    // Lưu qua service dùng chung với API: đổi khoá thì gọi thử PayOS, đối chiếu chủ tài khoản, ghi lịch sử hồ sơ.
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(PartnerPayOsChannelService::class)->save($record->partner, $data, auth()->user());
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
