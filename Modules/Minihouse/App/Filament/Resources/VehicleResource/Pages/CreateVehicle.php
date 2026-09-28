<?php

namespace Modules\Minihouse\App\Filament\Resources\VehicleResource\Pages;

use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Modules\Minihouse\App\Filament\Resources\VehicleResource;
use Modules\Minihouse\App\Services\VehicleService;

class CreateVehicle extends CreateRecord
{
    protected static string $resource = VehicleResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['requested_by'] = 'staff';

        if (($data['status'] ?? null) === 'active') {
            $data['approved_by'] = auth()->id();
            $data['approved_at'] = now();
            $data['start_date']  = $data['start_date'] ?? now()->toDateString();
        }

        return $data;
    }

    // Vượt giới hạn CHỈ cảnh báo (không chặn) — xem VehicleService::limitWarnings().
    protected function afterCreate(): void
    {
        foreach (VehicleService::limitWarnings($this->record) as $warning) {
            Notification::make()->title('Vượt giới hạn')->body($warning)->warning()->persistent()->send();
        }
    }
}
