<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Camera;
use App\Settings\CameraSettings;
use Filament\Pages\Page;

// Trang xem trực tiếp camera công ty — nhúng luồng phát ra từ go2rtc (chạy độc lập hoặc lõi bên
// trong Frigate) qua thẻ <video>, KHÔNG chứa logic ghi hình/AI gì ở đây. Chỉ liệt kê camera đang
// "status = true", lọc theo chi nhánh đang active y hệt các trang Warehouse (BelongsToBranch tự
// áp global scope). Cần CameraSettings::$base_url (Cấu hình web > Camera) đã được điền — chưa có
// giá trị thật thì trang chỉ hiện hướng dẫn, không có link camera nào để nhúng bừa.
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
}
