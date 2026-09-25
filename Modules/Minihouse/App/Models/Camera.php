<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Models;

use App\Models\Camera as BaseCamera;
use App\Models\CameraSetting as BaseCameraSetting;

// Kế thừa App\Models\Camera (dùng chung bảng cameras, không tạo bảng riêng) — CHỈ override
// resolveCameraSettings() để lọc server go2rtc/Frigate theo building_id (Toà nhà) thay vì partner_id
// như Home: MiniHouse chỉ có ĐÚNG 1 đối tác nội bộ cố định nên lọc theo đối tác vô nghĩa, mỗi Toà nhà
// mới là 1 địa điểm vật lý riêng có thể có server camera khác nhau. Phần dựng URL WebSocket còn lại
// (wsProxyUrl() ở lớp cha) dùng chung nguyên vẹn.
class Camera extends BaseCamera
{
    protected function resolveCameraSettings(): BaseCameraSetting
    {
        return CameraSetting::forBuilding($this->branch_id);
    }
}
