<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Camera;
use App\Models\Partner;
use App\Services\CameraRecordingService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

// Trang xem trực tiếp camera công ty — nhúng luồng phát ra từ go2rtc (chạy độc lập hoặc lõi bên
// trong Frigate) qua thẻ <video>, KHÔNG chứa logic ghi hình/AI gì ở đây. Chỉ liệt kê camera đang
// "status = true", lọc theo chi nhánh đang active y hệt các trang Warehouse (BelongsToBranch tự
// áp global scope). MỖI ĐỐI TÁC có thể dùng server Frigate RIÊNG (App\Models\CameraSetting) — không
// còn 1 cờ "đã cấu hình go2rtc" chung cho CẢ TRANG (super_admin có thể xem camera của nhiều đối tác
// cùng lúc, mỗi đối tác cấu hình khác nhau); tình trạng "chưa cấu hình" giờ hiện RIÊNG từng ô camera
// (xem camera-monitor.blade.php, dựa vào Camera::wsProxyUrl() trả null hay không).
//
// Ghi hình thủ công (nút "Ghi hình" trên mỗi camera) đã đổi sang GHI HOÀN TOÀN Ở TRÌNH DUYỆT — dùng
// MediaRecorder bắt luồng từ chính thẻ <video> đang phát (video.captureStream()), tự tải file .webm
// thẳng về MÁY TÍNH ĐANG XEM trang này, KHÔNG gọi Frigate/CameraRecordingService nữa (trước đây tạo
// "sự kiện" Frigate, video ghi được lưu trên SERVER Frigate — yêu cầu 2026-09-23: phải lưu ở máy
// đang dùng, không lưu ở server). Xem resources/views/filament/pages/camera-monitor.blade.php —
// toàn bộ logic ghi hình giờ nằm ở Alpine, trang này không còn action PHP nào cho việc đó.
// Xem lại LỊCH SỬ (loadPlayback() bên dưới) vẫn dùng CameraRecordingService/Frigate như cũ — đó là
// kho lưu trữ liên tục của camera (24/7), khác hẳn thao tác "bấm ghi 1 đoạn thủ công" ở trên.
class CameraMonitor extends Page
{
    protected static string $view = 'filament.pages.camera-monitor';

    protected static ?string $navigationGroup = 'Quản lý';

    protected static ?string $navigationIcon = 'heroicon-o-video-camera';

    protected static ?string $navigationLabel = 'Xem camera';

    protected static ?string $title = 'Xem camera trực tiếp';

    protected static ?int $navigationSort = 21;

    // Chỉ super_admin hoặc tài khoản được tích quyền "Xem camera" (Shield: page_CameraMonitor).
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user?->isSuperAdmin() || ($user?->can('page_CameraMonitor') ?? false);
    }

    public function getCameras(): Collection
    {
        return Camera::query()
            // MiniHouse dùng panel và cấu hình Frigate riêng theo từng tòa nhà. Super admin không bị
            // global partner scope giới hạn nên nếu không loại trừ ở đây, cùng một camera MiniHouse
            // sẽ bị mở đồng thời ở cả trang Home lẫn MiniHouse, nhân đôi kết nối MSE tới go2rtc.
            ->whereHas('partner', fn ($query) => $query->where('partner_type', Partner::TYPE_HOMESTAY))
            ->where('status', true)
            ->orderBy('name')
            ->get();
    }

    // Gọi từ Alpine qua $wire.loadPlayback(...) (Livewire 3 hỗ trợ await trực tiếp kết quả trả về)
    // — trả về URL HLS dùng ngay cho modal xem lại lịch sử, hoặc null kèm thông báo lỗi nếu Frigate
    // từ chối (VD ngoài khoảng còn lưu trữ).
    public function loadPlayback(int $cameraId, float $after, float $before): ?string
    {
        $camera = Camera::query()
            ->whereHas('partner', fn ($query) => $query->where('partner_type', Partner::TYPE_HOMESTAY))
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
            ->whereHas('partner', fn ($query) => $query->where('partner_type', Partner::TYPE_HOMESTAY))
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
