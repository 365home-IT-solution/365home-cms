<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Filament\Resources\CameraResource\Pages;

use App\Services\Go2RtcClient;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Modules\Minihouse\App\Filament\Resources\CameraResource;
use Modules\Minihouse\App\Models\CameraSetting;

// Mirror App\Filament\Resources\CameraResource\Pages\EditCamera (Home), khác đúng 1 chỗ: server
// go2rtc lấy theo TOÀ NHÀ (branch_id) thay vì đối tác — xem CreateCamera cùng thư mục. Đổi camera
// sang toà nhà khác (branch_id đổi) coi như đổi SERVER go2rtc luôn — phải xoá stream ở server CŨ
// (originalBranchId) trước khi khai báo lại ở server MỚI, giống hệt logic cũ xử lý đổi partner_id.
class EditCamera extends EditRecord
{
    protected static string $resource = CameraResource::class;

    private ?string $originalStreamKey = null;

    private ?int $originalBranchId = null;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    protected function beforeSave(): void
    {
        $this->originalStreamKey = $this->record->getOriginal('stream_key');
        $this->originalBranchId = $this->record->getOriginal('branch_id');
    }

    protected function afterSave(): void
    {
        if (blank($this->record->rtsp_url)) {
            return;
        }

        $streamKeyChanged = $this->originalStreamKey !== null && $this->originalStreamKey !== $this->record->stream_key;
        $branchChanged = $this->originalBranchId !== null && $this->originalBranchId !== $this->record->branch_id;

        if ($streamKeyChanged || $branchChanged) {
            (new Go2RtcClient(CameraSetting::forBuilding($this->originalBranchId)))
                ->deleteStream($this->originalStreamKey ?? $this->record->stream_key);
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
