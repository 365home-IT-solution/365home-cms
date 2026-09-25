<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Filament\Pages;

use App\Services\CameraRecordingService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Modules\Minihouse\App\Models\Camera;
use Modules\Minihouse\App\Support\ActiveBuildingScope;
use Modules\Minihouse\App\Support\HomestayBridge;

// Mirror App\Filament\Pages\CameraMonitor (Home) — dùng Modules\Minihouse\App\Models\Camera (kế thừa
// App\Models\Camera, override cách tìm server go2rtc/Frigate theo building_id) + CameraRecordingService
// dùng chung. BẮT BUỘC tự lọc partner_id/branch_id ở getCameras() giống hệt lý do đã ghi ở
// CameraResource: global scope trên Camera chỉ chạy trong panel "admin" (Home), không chạy ở panel
// "minihouse-admin".
class CameraMonitor extends Page
{
    protected static string  $view            = 'minihouse::filament.pages.camera-monitor';
    protected static ?string $navigationGroup = 'Quản lý';
    protected static ?string $navigationIcon  = 'heroicon-o-video-camera';
    protected static ?string $navigationLabel = 'Xem camera';
    protected static ?string $title           = 'Xem camera trực tiếp';
    protected static ?int    $navigationSort  = 71;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user?->isSuperAdmin() || ($user?->can('page_camera_monitor') ?? false);
    }

    public function getCameras(): \Illuminate\Support\Collection
    {
        return Camera::query()
            ->where('partner_id', HomestayBridge::PARTNER_ID)
            ->whereIn('branch_id', ActiveBuildingScope::permittedBuildingIds())
            ->where('status', true)
            ->orderBy('name')
            ->get();
    }

    public function loadPlayback(int $cameraId, float $after, float $before): ?string
    {
        $camera = Camera::query()
            ->where('partner_id', HomestayBridge::PARTNER_ID)
            ->whereIn('branch_id', ActiveBuildingScope::permittedBuildingIds())
            ->find($cameraId);

        if (! $camera) {
            return null;
        }

        if ($before <= $after) {
            Notification::make()->title('Khoảng thời gian không hợp lệ')->danger()->send();

            return null;
        }

        return app(CameraRecordingService::class)->playbackUrl($camera, $after, $before);
    }
}
