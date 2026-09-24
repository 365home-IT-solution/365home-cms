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

    private ?string $originalPartnerId = null;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    protected function beforeSave(): void
    {
        // Ghi nhớ stream_key CŨ trước khi Filament ghi đè — nếu người dùng đổi tên nguồn, phải xoá
        // đăng ký cũ trên go2rtc, không thì server go2rtc tồn đọng 1 nguồn "mồ côi" không ai dùng.
        // Ghi nhớ luôn partner_id CŨ — mỗi đối tác có thể dùng server go2rtc RIÊNG (App\Models\
        // CameraSetting), nếu người dùng đổi camera sang chi nhánh của đối tác KHÁC thì nguồn cũ
        // phải xoá trên server go2rtc CŨ (của partner cũ), không phải server mới.
        $this->originalStreamKey = $this->record->getOriginal('stream_key');
        $this->originalPartnerId = $this->record->getOriginal('partner_id');
    }

    protected function afterSave(): void
    {
        // CHỈ động vào go2rtc khi bản ghi này CÓ RTSP (nghĩa là do chính web này khai báo nguồn) —
        // đa số camera là tham chiếu tới nguồn ĐÃ CÓ SẴN trong Frigate (rtsp_url để trống), TUYỆT
        // ĐỐI không được gọi xoá/ghi đè nguồn đó, nếu không sẽ làm gãy luồng thật Frigate đang chạy.
        if (blank($this->record->rtsp_url)) {
            return;
        }

        if ($this->originalStreamKey !== null && $this->originalStreamKey !== $this->record->stream_key) {
            Go2RtcClient::forPartner($this->originalPartnerId)->deleteStream($this->originalStreamKey);
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
