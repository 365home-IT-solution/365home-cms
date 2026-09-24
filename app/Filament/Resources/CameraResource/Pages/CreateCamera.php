<?php

declare(strict_types=1);

namespace App\Filament\Resources\CameraResource\Pages;

use App\Filament\Resources\CameraResource;
use App\Services\Go2RtcClient;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateCamera extends CreateRecord
{
    protected static string $resource = CameraResource::class;

    // Đa số camera đã có sẵn trong Frigate (chỉ cần đúng "Tên nguồn" là xem được ngay) — CHỈ gọi
    // API khai báo nguồn mới khi người dùng có điền RTSP (camera thật sự chưa tồn tại bên Frigate).
    // Lỗi kết nối tới server go2rtc CHỈ cảnh báo (warning), không chặn việc lưu — camera vẫn có
    // trong danh sách, sửa lại RTSP/thử lại khi server go2rtc online là xong, không mất dữ liệu vừa
    // nhập.
    protected function afterCreate(): void
    {
        if (blank($this->record->rtsp_url)) {
            return;
        }

        $error = Go2RtcClient::forPartner($this->record->partner_id)->addStream($this->record->stream_key, $this->record->rtsp_url);

        if ($error !== null) {
            Notification::make()
                ->title('Đã lưu camera, nhưng chưa khai báo được với server go2rtc')
                ->body($error)
                ->warning()
                ->send();
        }
    }
}
