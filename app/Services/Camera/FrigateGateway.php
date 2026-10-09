<?php

declare(strict_types=1);

namespace App\Services\Camera;

use App\Models\Camera;
use App\Models\CameraSetting;
use App\Services\FrigateApiClient;
use App\Services\FrigateSessionClient;
use App\Services\Go2RtcClient;
use App\Support\CameraMediaUrl;

// Gateway mặc định: Frigate (kèm go2rtc bên trong) — đủ live, ảnh, sự kiện, lịch sử, phát lại.
class FrigateGateway extends Go2RtcBackedGateway
{
    public function type(): string
    {
        return CameraSetting::GATEWAY_FRIGATE;
    }

    public function capabilities(Camera $camera): array
    {
        return $this->capabilityMap(self::CAPABILITIES);
    }

    public function latestImageUrl(Camera $camera, ?string &$error = null): ?string
    {
        return CameraMediaUrl::signed($camera, '/api/'.rawurlencode($camera->frigateCameraName()), 'latest.jpg', 300);
    }

    public function health(Camera $camera): array
    {
        $settings = $camera->resolveCameraSettings();

        if ($settings->isGo2RtcConfigured()) {
            return (new Go2RtcClient($settings))->checkStream($camera->stream_key);
        }

        // Chưa khai báo go2rtc URL riêng: hỏi go2rtc bên trong Frigate qua phiên đăng nhập Frigate.
        if (! $settings->hasFrigateCredentials() || ! $settings->isConfigured()) {
            return ['online' => false, 'message' => 'Chưa cấu hình máy chủ Frigate hoặc go2rtc cho camera này.'];
        }

        $result = (new FrigateApiClient($settings, new FrigateSessionClient($settings)))->go2rtcStreams();

        if (! $result['success']) {
            return ['online' => false, 'message' => (string) $result['error']];
        }

        $producers = $result['data'][$camera->stream_key]['producers'] ?? [];
        $online = is_array($producers) && count($producers) > 0;

        return [
            'online' => $online,
            'message' => $online ? 'Nguồn đang phát qua Frigate.' : 'Kết nối được Frigate nhưng nguồn chưa có producer hoạt động.',
        ];
    }
}
