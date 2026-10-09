<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Filament\Pages;

use App\Models\Partner;
use App\Services\CameraRecordingService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Modules\Minihouse\App\Models\Camera;
use Modules\Minihouse\App\Support\ActiveBuildingScope;

// Mirror App\Filament\Pages\CameraMonitor (Home) — dùng Modules\Minihouse\App\Models\Camera (kế thừa
// App\Models\Camera, override cách tìm server go2rtc/Frigate theo building_id) + CameraRecordingService
// dùng chung. BẮT BUỘC tự lọc partner_id/branch_id ở getCameras() giống hệt lý do đã ghi ở
// CameraResource: global scope trên Camera chỉ chạy trong panel "admin" (Home), không chạy ở panel
// "minihouse-admin".
class CameraMonitor extends Page
{
    protected static string $view = 'minihouse::filament.pages.camera-monitor';

    protected static ?string $navigationGroup = 'Quản lý';

    protected static ?string $navigationIcon = 'heroicon-o-video-camera';

    protected static ?string $navigationLabel = 'Xem camera';

    protected static ?string $title = 'Xem camera trực tiếp';

    protected static ?int $navigationSort = 71;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user?->isSuperAdmin() || ($user?->can('page_camera_monitor') ?? false);
    }

    public function getCameras(): Collection
    {
        return Camera::query()
            ->whereHas('partner', fn ($query) => $query->where('partner_type', Partner::TYPE_MINIHOUSE))
            ->whereIn('branch_id', ActiveBuildingScope::permittedBuildingIds())
            ->where('status', true)
            ->orderBy('name')
            ->get();
    }

    public function loadPlayback(int $cameraId, float $after, float $before): ?string
    {
        $camera = Camera::query()
            ->whereHas('partner', fn ($query) => $query->where('partner_type', Partner::TYPE_MINIHOUSE))
            ->whereIn('branch_id', ActiveBuildingScope::permittedBuildingIds())
            ->find($cameraId);

        if (! $camera) {
            return null;
        }

        if (! $camera->supportsCapability('playback')) {
            Notification::make()->title('Camera này không hỗ trợ xem lại lịch sử ghi hình')->warning()->send();

            return null;
        }

        if ($before <= $after) {
            Notification::make()->title('Khoảng thời gian không hợp lệ')->danger()->send();

            return null;
        }

        return app(CameraRecordingService::class)->playbackUrl($camera, $after, $before);
    }

    public function loadRecentEvents(int $cameraId): array
    {
        $camera = Camera::query()
            ->whereHas('partner', fn ($query) => $query->where('partner_type', Partner::TYPE_MINIHOUSE))
            ->whereIn('branch_id', ActiveBuildingScope::permittedBuildingIds())
            ->find($cameraId);

        if (! $camera) {
            return [];
        }

        if (! $camera->supportsCapability('events')) {
            return [];
        }

        $service = app(CameraRecordingService::class);
        $result = $service->events($camera, ['limit' => 50, 'timezone' => 'Asia/Ho_Chi_Minh']);

        if (! $result['success']) {
            Notification::make()->title($result['error'])->danger()->send();

            return [];
        }

        return collect($result['data'])->map(function (array $event) use ($camera, $service): array {
            $event['snapshot_url'] = ($event['has_snapshot'] ?? false) ? $service->eventSnapshotUrl($camera, (string) $event['id']) : null;
            $event['clip_url'] = ($event['has_clip'] ?? false) ? $service->eventClipUrl($camera, (string) $event['id']) : null;

            return $event;
        })->all();
    }
}
