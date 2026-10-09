<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Camera;

// Dựng URL media đi qua proxy của chính server này (CameraMediaProxyController), ký token ngắn hạn —
// dùng chung cho Frigate (ảnh mới nhất, snapshot/clip sự kiện) và go2rtc trần (ảnh khung hình).
class CameraMediaUrl
{
    public static function signed(Camera $camera, string $path, string $filename, int $ttl, string $gateway = 'frigate'): string
    {
        $token = CameraMediaToken::issue($path, (string) $camera->partner_id, $camera->branch_id, $ttl, gateway: $gateway);

        return url('/api/camera-media/'.rawurlencode($token).'/'.$filename);
    }
}
