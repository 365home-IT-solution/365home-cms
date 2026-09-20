<?php

declare(strict_types=1);

namespace App\Filament\Resources\CameraResource\Pages;

use App\Filament\Resources\CameraResource;
use App\Services\Go2RtcClient;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditCamera extends EditRecord
{
    protected static string $resource = CameraResource::class;

    private ?string $originalStreamKey = null;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    protected function beforeSave(): void
    {
        // Ghi nhớ stream_key CŨ trước khi Filament ghi đè — nếu người dùng đổi tên nguồn, phải xoá
        // đăng ký cũ trên go2rtc, không thì server go2rtc tồn đọng 1 nguồn "mồ côi" không ai dùng.
        $this->originalStreamKey = $this->record->getOriginal('stream_key');
    }

    protected function afterSave(): void
    {
        // CHỈ động vào go2rtc khi bản ghi này CÓ RTSP (nghĩa là do chính web này khai báo nguồn) —
        // đa số camera là tham chiếu tới nguồn ĐÃ CÓ SẴN trong Frigate (rtsp_url để trống), TUYỆT
        // ĐỐI không được gọi xoá/ghi đè nguồn đó, nếu không sẽ làm gãy luồng thật Frigate đang chạy.
        if (blank($this->record->rtsp_url)) {
            return;
        }

        $client = app(Go2RtcClient::class);

        if ($this->originalStreamKey !== null && $this->originalStreamKey !== $this->record->stream_key) {
            $client->deleteStream($this->originalStreamKey);
        }

        $error = $client->addStream($this->record->stream_key, $this->record->rtsp_url);

        if ($error !== null) {
            Notification::make()
                ->title('Đã lưu camera, nhưng chưa khai báo được với server go2rtc')
                ->body($error)
                ->warning()
                ->send();
        }
    }
}
