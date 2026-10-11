<?php

namespace Modules\Payment\App\Filament\Resources\BranchPayOsAccountResource\Pages;

use Filament\Actions;
use App\Models\Partner;
use App\Services\Payment\PartnerPayOsChannelService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Modules\Payment\App\Filament\Resources\BranchPayOsAccountResource;

class EditBranchPayOsAccount extends EditRecord
{
    protected static string $resource = BranchPayOsAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    // Không đưa khoá bí mật đã lưu ra form — để trống khi lưu = giữ nguyên.
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return [...$data, 'client_id' => $this->record->client_id, 'api_key' => null, 'checksum_key' => null];
    }

    // Lưu qua service dùng chung với API: đổi khoá thì gọi thử PayOS, đối chiếu chủ tài khoản, ghi lịch sử hồ sơ và đăng ký lại webhook.
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $branch = $record->category;
        $partner = $branch?->partner_id ? Partner::query()->find($branch->partner_id) : null;

        if (! $partner) {
            // Chi nhánh của hệ thống: đổi kênh thì webhook cũ không còn áp dụng.
            if (blank($data['api_key'] ?? null)) {
                unset($data['api_key']);
            }
            if (blank($data['checksum_key'] ?? null)) {
                unset($data['checksum_key']);
            }
            if (($data['client_id'] ?? null) !== $record->client_id) {
                $data['webhook_confirmed_at'] = null;
            }

            return parent::handleRecordUpdate($record, $data);
        }

        return app(PartnerPayOsChannelService::class)->saveBranch($partner, $branch, $data, auth()->user());
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
