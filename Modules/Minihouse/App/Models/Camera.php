<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Models;

use App\Models\Camera as BaseCamera;

// Kế thừa App\Models\Camera (dùng chung bảng cameras, không tạo bảng riêng) — dùng làm model tường
// minh cho panel MiniHouse (CameraResource/CameraMonitor/CreateCamera/EditCamera). KHÔNG cần override
// gì thêm: App\Models\Camera::resolveCameraSettings() đã tự nhận diện partner_id cố định của
// MiniHouse (HomestayBridge::PARTNER_ID) và lọc server go2rtc/Frigate theo building_id ngay ở lớp
// cha — áp dụng luôn cho MỌI đường lấy dữ liệu (Filament lẫn App\Http\Controllers\Api\Admin\
// CameraController dùng chung), không riêng gì model này.
class Camera extends BaseCamera
{
}
