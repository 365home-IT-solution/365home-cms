<?php

declare(strict_types=1);

namespace App\Services\Camera;

use App\Models\Camera;
use App\Models\CameraSetting;
use App\Services\Camera\Cloud\CloudProvider;
use App\Services\Camera\Cloud\CloudProviderException;
use App\Services\Camera\Cloud\CloudProviderRegistry;
use Illuminate\Support\Facades\Cache;

// Camera chỉ có trên đám mây của hãng (Imou, EZVIZ...): link xem HLS và ảnh lấy qua API hãng bằng
// tài khoản developer của đối tác/toà nhà (CameraSetting::provider_credentials). Không có Frigate nên
// không có sự kiện/lịch sử ghi hình; không qua Node proxy nên mse_ws_url luôn null.
class CloudGateway implements CameraGateway
{
    private const LIVE_CACHE_SECONDS = 300;

    private const SNAPSHOT_CACHE_SECONDS = 30;

    public function __construct(private readonly CloudProviderRegistry $registry) {}

    public function type(): string
    {
        return CameraSetting::GATEWAY_CLOUD;
    }

    public function capabilities(Camera $camera): array
    {
        $provider = $this->registry->get($camera->provider);

        $enabled = $provider === null
            ? []
            : array_filter([
                self::CAP_LIVE,
                $provider->supportsSnapshot() ? self::CAP_SNAPSHOT : null,
                self::CAP_HEALTH_CHECK,
            ]);

        return array_combine(
            self::CAPABILITIES,
            array_map(fn (string $capability): bool => in_array($capability, $enabled, true), self::CAPABILITIES),
        );
    }

    public function liveOptions(Camera $camera): array
    {
        $options = [
            'gateway_type' => $this->type(),
            'preferred' => null,
            'capabilities' => $this->capabilities($camera),
            'mse_ws_url' => null,
            'webview_url' => null,
            'hls_url' => null,
            'mjpeg_url' => null,
            'rtsp_url' => null,
            'latest_image_url' => null,
            'expires_in' => null,
            'message' => null,
        ];

        try {
            $stream = $this->stream($camera);
        } catch (CloudProviderException $e) {
            return [...$options, 'message' => $e->getMessage()];
        }

        return [
            ...$options,
            'preferred' => 'hls',
            'hls_url' => $stream['hls_url'],
            'latest_image_url' => $stream['cover_url'],
            'expires_in' => $stream['expires_in'],
        ];
    }

    public function latestImageUrl(Camera $camera, ?string &$error = null): ?string
    {
        try {
            [$provider, $credentials, $deviceId, $channel] = $this->resolve($camera);

            if (! $provider->supportsSnapshot()) {
                $error = 'Hãng này không hỗ trợ ảnh mới nhất.';

                return null;
            }

            $snapshot = Cache::remember(
                $this->cacheKey('snapshot', $camera),
                self::SNAPSHOT_CACHE_SECONDS,
                fn (): array => $provider->snapshot($credentials, $deviceId, $channel),
            );

            return $snapshot['url'];
        } catch (CloudProviderException $e) {
            $error = $e->getMessage();

            return null;
        }
    }

    public function health(Camera $camera): array
    {
        try {
            $stream = $this->stream($camera, fresh: true);

            return ['online' => true, 'message' => 'Camera cloud sẵn sàng phát.', 'details' => ['expires_in' => $stream['expires_in']]];
        } catch (CloudProviderException $e) {
            return ['online' => false, 'message' => $e->getMessage()];
        }
    }

    public function sync(Camera $camera, ?string $oldStreamKey = null, ?Camera $oldCamera = null): ?string
    {
        return null;
    }

    /**
     * @return array{hls_url: string, cover_url: ?string, expires_in: ?int}
     *
     * @throws CloudProviderException
     */
    private function stream(Camera $camera, bool $fresh = false): array
    {
        [$provider, $credentials, $deviceId, $channel] = $this->resolve($camera);
        $key = $this->cacheKey('live', $camera);

        if (! $fresh && is_array($cached = Cache::get($key))) {
            return [
                'hls_url' => $cached['hls_url'],
                'cover_url' => $cached['cover_url'],
                'expires_in' => $cached['expires_at'] === null ? null : max(0, $cached['expires_at'] - time()),
            ];
        }

        $stream = $provider->liveStream($credentials, $deviceId, $channel);
        $ttl = min(self::LIVE_CACHE_SECONDS, max(30, ($stream['expires_in'] ?? self::LIVE_CACHE_SECONDS) - 60));

        Cache::put($key, [
            'hls_url' => $stream['hls_url'],
            'cover_url' => $stream['cover_url'],
            'expires_at' => $stream['expires_in'] === null ? null : time() + $stream['expires_in'],
        ], $ttl);

        return $stream;
    }

    /**
     * @return array{0: CloudProvider, 1: array<string, mixed>, 2: string, 3: string}
     *
     * @throws CloudProviderException
     */
    private function resolve(Camera $camera): array
    {
        $provider = $this->registry->get($camera->provider);

        if ($provider === null) {
            throw new CloudProviderException('Hãng "'.($camera->provider ?: 'generic').'" chưa hỗ trợ kết nối cloud.');
        }

        $credentials = $camera->resolveCameraSettings()->providerCredentials($provider->key());

        if (! $this->registry->isComplete($provider, $credentials)) {
            throw new CloudProviderException("Chưa cấu hình tài khoản developer {$provider->label()} (Cấu hình web > Camera).");
        }

        if (blank($camera->external_device_id)) {
            throw new CloudProviderException('Camera chưa có mã/serial thiết bị.');
        }

        return [
            $provider,
            $credentials,
            (string) $camera->external_device_id,
            filled($camera->external_channel) ? (string) $camera->external_channel : $provider->defaultChannel(),
        ];
    }

    private function cacheKey(string $kind, Camera $camera): string
    {
        $settings = $camera->resolveCameraSettings();

        return 'cloud:'.$kind.':'.hash('sha256', implode('|', [
            $camera->provider,
            $camera->external_device_id,
            $camera->external_channel,
            json_encode($settings->providerCredentials((string) $camera->provider)),
        ]));
    }
}
