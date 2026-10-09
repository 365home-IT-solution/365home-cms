<?php

declare(strict_types=1);

namespace App\Services\Camera;

use App\Models\Camera;
use App\Models\CameraSetting;

// Chọn gateway cho từng camera: camera cloud (source_type = cloud) hoặc đối tác đặt gateway = cloud thì
// đi đường cloud; còn lại theo gateway của đối tác/toà nhà (frigate mặc định, hoặc go2rtc trần).
class CameraGatewayManager
{
    public function for(Camera $camera): CameraGateway
    {
        return match ($camera->gatewayType()) {
            CameraSetting::GATEWAY_CLOUD => app(CloudGateway::class),
            CameraSetting::GATEWAY_GO2RTC => app(Go2RtcGateway::class),
            default => app(FrigateGateway::class),
        };
    }

    public function supports(Camera $camera, string $capability): bool
    {
        return $this->for($camera)->capabilities($camera)[$capability] ?? false;
    }
}
