<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Camera;
use App\Services\CameraRecordingService;
use App\Settings\CameraSettings;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

// Trang xem trực tiếp camera công ty — nhúng luồng phát ra từ go2rtc (chạy độc lập hoặc lõi bên
// trong Frigate) qua thẻ <video>, KHÔNG chứa logic ghi hình/AI gì ở đây. Chỉ liệt kê camera đang
// "status = true", lọc theo chi nhánh đang active y hệt các trang Warehouse (BelongsToBranch tự
// áp global scope). Cần CameraSettings::$base_url (Cấu hình web > Camera) đã được điền — chưa có
// giá trị thật thì trang chỉ hiện hướng dẫn, không có link camera nào để nhúng bừa.
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
    protected static string  $view            = 'filament.pages.camera-monitor';
    protected static ?string $navigationGroup = 'Quản lý';
    protected static ?string $navigationIcon  = 'heroicon-o-video-camera';
    protected static ?string $navigationLabel = 'Xem camera';
    protected static ?string $title           = 'Xem camera trực tiếp';
    protected static ?int    $navigationSort  = 21;

    public function getCameras(): \Illuminate\Support\Collection
    {
        return Camera::query()
            ->where('status', true)
            ->orderBy('name')
            ->get();
    }

    public function getGo2rtcConfigured(): bool
    {
        return app(CameraSettings::class)->isConfigured();
    }

    // Gọi từ Alpine qua $wire.loadPlayback(...) (Livewire 3 hỗ trợ await trực tiếp kết quả trả về)
    // — trả về URL HLS dùng ngay cho modal xem lại lịch sử, hoặc null kèm thông báo lỗi nếu Frigate
    // từ chối (VD ngoài khoảng còn lưu trữ).
    public function loadPlayback(int $cameraId, float $after, float $before): ?string
    {
        $camera = Camera::find($cameraId);

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
