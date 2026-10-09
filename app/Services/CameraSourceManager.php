<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Camera;
use App\Services\Camera\CameraGatewayManager;

/** Single orchestration point for syncing and health-checking a camera through its gateway. */
class CameraSourceManager
{
    private readonly CameraGatewayManager $gateways;

    public function __construct(?CameraGatewayManager $gateways = null)
    {
        $this->gateways = $gateways ?? app(CameraGatewayManager::class);
    }

    public function sync(Camera $camera, ?string $oldStreamKey = null, ?Camera $oldCamera = null): ?string
    {
        return $this->gateways->for($camera)->sync($camera, $oldStreamKey, $oldCamera);
    }

    public function health(Camera $camera): array
    {
        $result = $this->gateways->for($camera)->health($camera);
        $now = now();

        $camera->forceFill([
            'connection_status' => $result['online'] ? 'online' : 'offline',
            'connection_message' => $result['message'] ?? null,
            'last_checked_at' => $now,
            'last_online_at' => $result['online'] ? $now : $camera->last_online_at,
        ])->saveQuietly();

        return $result;
    }
}
