<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Filament\Resources\CameraResource\Pages;

use App\Models\Camera;
use App\Services\CameraSourceManager;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Modules\Minihouse\App\Filament\Resources\CameraResource;

// Mirror App\Filament\Resources\CameraResource\Pages\EditCamera (Home), khác đúng 1 chỗ: server
// go2rtc lấy theo TOÀ NHÀ (branch_id) thay vì đối tác — xem CreateCamera cùng thư mục. Đổi camera
// sang toà nhà khác (branch_id đổi) coi như đổi SERVER go2rtc luôn — phải xoá stream ở server CŨ
// (originalBranchId) trước khi khai báo lại ở server MỚI, giống hệt logic cũ xử lý đổi partner_id.
class EditCamera extends EditRecord
{
    protected static string $resource = CameraResource::class;

    private ?string $originalStreamKey = null;

    private ?int $originalBranchId = null;

    private ?Camera $originalCamera = null;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        if (blank($data['source_url'] ?? null) && filled($this->record->rtsp_url)) {
            $data['source_url'] = $this->record->rtsp_url;
            $data['source_type'] = 'rtsp';
        }

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    protected function beforeSave(): void
    {
        $this->originalStreamKey = $this->record->getOriginal('stream_key');
        $this->originalBranchId = $this->record->getOriginal('branch_id');
        $this->originalCamera = $this->record->replicate();
        $this->originalCamera->forceFill(['branch_id' => $this->originalBranchId]);
    }

    protected function afterSave(): void
    {
        if (! $this->record->isManagedSource()) {
            return;
        }

        $error = app(CameraSourceManager::class)->sync($this->record, $this->originalStreamKey, $this->originalCamera);

        if ($error !== null) {
            Notification::make()
                ->title('Đã lưu camera, nhưng chưa khai báo được với server go2rtc')
                ->body($error)
                ->warning()
                ->send();
        }
    }
}
