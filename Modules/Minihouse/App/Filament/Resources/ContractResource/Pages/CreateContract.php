<?php

namespace Modules\Minihouse\App\Filament\Resources\ContractResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Minihouse\App\Filament\Resources\ContractResource;

class CreateContract extends CreateRecord
{
    protected static string $resource = ContractResource::class;

    protected function afterCreate(): void
    {
        $this->warnVehicleLimits();
    }

    // Vượt giới hạn số xe (tab Phương tiện) chỉ cảnh báo, không chặn — xem VehicleService::contractLimitWarnings().
    private function warnVehicleLimits(): void
    {
        foreach (\Modules\Minihouse\App\Services\VehicleService::contractLimitWarnings($this->record->fresh(['room'])) as $warning) {
            \Filament\Notifications\Notification::make()->title('Vượt giới hạn xe')->body($warning)->warning()->persistent()->send();
        }
    }
}
