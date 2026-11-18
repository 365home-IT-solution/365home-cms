<?php

declare(strict_types=1);

namespace Modules\VehicleRental\App\Filament\Resources\VehicleResource\Pages;

use DomainException;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Modules\VehicleRental\App\Filament\Resources\VehicleResource;
use Modules\VehicleRental\App\Services\VehicleGpsLinker;
use Modules\VehicleRental\App\Services\VehicleRatePlanDefaults;

class CreateVehicle extends CreateRecord
{
    protected static string $resource = VehicleResource::class;

    // Xe mới có sẵn bộ gói giá mẫu (số giả) để admin chỉ việc sửa lại ở tab "Gói giá".
    protected function afterCreate(): void
    {
        VehicleRatePlanDefaults::seed($this->record);

        // Gắn GPS: theo thiết bị đã chọn; không chọn thì tự gắn nếu biển số khớp duy nhất một thiết bị chưa ghép.
        $linker = app(VehicleGpsLinker::class);
        try {
            $chosen = $this->data['motrak_device_id'] ?? null;
            if ($chosen) {
                $linker->link($this->record, (int) $chosen);
            } elseif ($linker->autoLinkByPlate($this->record)) {
                Notification::make()->success()->title('Đã tự gắn thiết bị GPS theo biển số')->send();
            }
        } catch (DomainException $e) {
            Notification::make()->warning()->title('Chưa gắn được GPS')->body($e->getMessage())->persistent()->send();
        }
    }
}
