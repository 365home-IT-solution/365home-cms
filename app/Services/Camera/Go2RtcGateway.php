<?php

declare(strict_types=1);

namespace App\Services\Camera;

use App\Models\Camera;
use App\Models\CameraSetting;
use App\Services\Go2RtcClient;
use App\Support\CameraMediaUrl;

// Đối tác chỉ có go2rtc, không có Frigate: xem live (MSE qua Node proxy hoặc HLS/WebView/MJPEG/RTSP) và
// ảnh khung hình. Không có sự kiện/lịch sử ghi hình vì go2rtc không lưu trữ.
class Go2RtcGateway extends Go2RtcBackedGateway
{
    public function type(): string
    {
        return CameraSetting::GATEWAY_GO2RTC;
    }

    public function capabilities(Camera $camera): array
    {
        return $this->capabilityMap([
            self::CAP_LIVE,
            self::CAP_SNAPSHOT,
            self::CAP_HEALTH_CHECK,
            self::CAP_SOURCE_SYNC,
        ]);
    }

    public function latestImageUrl(Camera $camera, ?string &$error = null): ?string
    {
        if (! $camera->resolveCameraSettings()->isGo2RtcConfigured()) {
            $error = 'Chưa cấu hình địa chỉ go2rtc.';

            return null;
        }

        return CameraMediaUrl::signed(
            $camera,
            '/api/frame.jpeg?src='.rawurlencode($camera->stream_key),
            'frame.jpeg',
            300,
            'go2rtc',
        );
    }

    public function health(Camera $camera): array
    {
        return (new Go2RtcClient($camera->resolveCameraSettings()))->checkStream($camera->stream_key);
    }
}
