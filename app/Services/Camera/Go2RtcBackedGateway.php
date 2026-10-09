<?php

declare(strict_types=1);

namespace App\Services\Camera;

use App\Models\Camera;
use App\Services\Go2RtcClient;

// Phần dùng chung của gateway có go2rtc ở giữa (Frigate hoặc go2rtc trần): các URL phát lấy từ go2rtc,
// đăng ký/xoá nguồn qua API go2rtc. Khác nhau ở khả năng (Frigate có lịch sử/sự kiện), ảnh mới nhất
// và cách kiểm tra camera.
abstract class Go2RtcBackedGateway implements CameraGateway
{
    public function liveOptions(Camera $camera): array
    {
        $go2rtcUrl = rtrim((string) $camera->resolveCameraSettings()->go2rtcBaseUrl(), '/');
        $src = rawurlencode($camera->stream_key);
        $ws = $camera->wsProxyUrl();
        $hls = $go2rtcUrl !== '' ? "{$go2rtcUrl}/api/stream.m3u8?src={$src}" : null;

        return [
            'gateway_type' => $this->type(),
            'preferred' => $ws !== null ? 'mse' : ($hls !== null ? 'hls' : null),
            'capabilities' => $this->capabilities($camera),
            'mse_ws_url' => $ws,
            'webview_url' => $go2rtcUrl !== '' ? "{$go2rtcUrl}/stream.html?src={$src}&mode=webrtc,mse,hls" : null,
            'hls_url' => $hls,
            'mjpeg_url' => $go2rtcUrl !== '' ? "{$go2rtcUrl}/api/stream.mjpeg?src={$src}" : null,
            'rtsp_url' => $go2rtcUrl !== '' ? $this->rtspUrl($go2rtcUrl, $camera->stream_key) : null,
            'latest_image_url' => $this->latestImageUrl($camera),
            'expires_in' => null,
            'message' => $ws === null && $hls === null ? 'Chưa cấu hình máy chủ camera cho đối tác/toà nhà này.' : null,
        ];
    }

    public function sync(Camera $camera, ?string $oldStreamKey = null, ?Camera $oldCamera = null): ?string
    {
        $source = $camera->sourceUrl();

        if (! $camera->isManagedSource() || blank($source)) {
            return null;
        }

        if ($oldStreamKey && ($oldStreamKey !== $camera->stream_key || $oldCamera?->branch_id !== $camera->branch_id)) {
            (new Go2RtcClient(($oldCamera ?? $camera)->resolveCameraSettings()))->deleteStream($oldStreamKey);
        }

        return (new Go2RtcClient($camera->resolveCameraSettings()))->addStream($camera->stream_key, $source);
    }

    /**
     * @param  list<string>  $enabled
     * @return array<string, bool>
     */
    protected function capabilityMap(array $enabled): array
    {
        return array_combine(
            self::CAPABILITIES,
            array_map(fn (string $capability): bool => in_array($capability, $enabled, true), self::CAPABILITIES),
        );
    }

    private function rtspUrl(string $go2rtcUrl, string $streamKey): string
    {
        $host = parse_url($go2rtcUrl)['host'] ?? 'localhost';

        return 'rtsp://'.$host.':8554/'.rawurlencode($streamKey);
    }
}
