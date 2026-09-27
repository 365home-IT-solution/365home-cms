<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Filament\Resources\CameraResource\Pages;

use App\Services\Go2RtcClient;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Modules\Minihouse\App\Filament\Resources\CameraResource;
use Modules\Minihouse\App\Models\CameraSetting;

// Mirror App\Filament\Resources\CameraResource\Pages\CreateCamera (Home), khác đúng 1 chỗ: server
// go2rtc lấy theo TOÀ NHÀ (branch_id) thay vì đối tác — xem Modules\Minihouse\App\Models\Camera::
// resolveCameraSettings() (MiniHouse chỉ có 1 đối tác nội bộ cố định nên không lọc được theo đối tác
// như Home). App\Services\Go2RtcClient dùng chung nguyên vẹn — constructor nhận thẳng 1 instance
// CameraSetting, không bắt buộc phải qua forPartner().
class CreateCamera extends CreateRecord
{
    protected static string $resource = CameraResource::class;

    protected function afterCreate(): void
    {
        if (blank($this->record->rtsp_url)) {
            return;
        }

        $error = (new Go2RtcClient(CameraSetting::forBuilding($this->record->branch_id)))
            ->addStream($this->record->stream_key, $this->record->rtsp_url);

        if ($error !== null) {
            Notification::make()
                ->title('Đã lưu camera, nhưng chưa khai báo được với server go2rtc')
                ->body($error)
                ->warning()
                ->send();
        }
    }
}
