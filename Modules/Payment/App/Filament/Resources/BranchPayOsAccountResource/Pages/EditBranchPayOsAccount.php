<?php

namespace Modules\Payment\App\Filament\Resources\BranchPayOsAccountResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
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

    // Đổi sang kênh PayOS khác thì webhook đã đăng ký trước đó không còn áp dụng.
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (($data['client_id'] ?? null) !== $this->record->client_id) {
            $data['webhook_confirmed_at'] = null;
        }

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
