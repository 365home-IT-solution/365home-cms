<?php

declare(strict_types=1);

namespace App\Services\Camera\Cloud;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

// Imou Open Platform (open.imoulife.com): mọi lệnh là POST JSON tới https://openapi-{vùng}.easy4ip.com/
// openapi/{method}, kèm khối "system" (appId, time, nonce, sign). sign = Base64(HMAC-SHA256(
// "time:{time},nonce:{nonce},appSecret:{appSecret}", key = hex(SHA-256(appSecret)))). Lấy accessToken
// bằng method "accessToken", các lệnh sau gửi token trong params. Link xem là HLS do "bindDeviceLive"
// tạo (hoặc "getLiveStreamInfo" nếu đã tạo trước đó).
class ImouProvider implements CloudProvider
{
    private const REGIONS = ['sg', 'fk', 'or'];

    public function key(): string
    {
        return 'imou';
    }

    public function label(): string
    {
        return 'Imou';
    }

    public function credentialFields(): array
    {
        return [
            ['key' => 'app_id', 'label' => 'App ID', 'secret' => false, 'required' => true, 'default' => null, 'options' => null],
            ['key' => 'app_secret', 'label' => 'App Secret', 'secret' => true, 'required' => true, 'default' => null, 'options' => null],
            ['key' => 'region', 'label' => 'Vùng máy chủ', 'secret' => false, 'required' => false, 'default' => 'sg', 'options' => self::REGIONS],
        ];
    }

    public function defaultChannel(): string
    {
        return '0';
    }

    public function supportsSnapshot(): bool
    {
        return true;
    }

    public function verifyCredentials(array $credentials): void
    {
        Cache::forget($this->tokenCacheKey($credentials));
        $this->accessToken($credentials);
    }

    public function liveStream(array $credentials, string $deviceId, string $channel): array
    {
        $token = $this->accessToken($credentials);
        $params = ['token' => $token, 'deviceId' => $deviceId, 'channelId' => $channel];

        $result = $this->call($credentials, 'bindDeviceLive', $params + ['streamId' => 0]);

        // Địa chỉ đã tạo từ trước (hoặc bind lỗi) thì lấy lại bằng getLiveStreamInfo.
        if ($result['code'] !== '0' || $this->pickStream($result['data']) === null) {
            $result = $this->call($credentials, 'getLiveStreamInfo', $params);
        }

        $stream = $result['code'] === '0' ? $this->pickStream($result['data']) : null;

        if ($stream === null) {
            throw new CloudProviderException('Imou không trả về địa chỉ xem: '.$result['msg'].' (mã '.$result['code'].').');
        }

        return ['hls_url' => (string) $stream['hls'], 'expires_in' => null, 'cover_url' => $stream['coverUrl'] ?? null];
    }

    public function snapshot(array $credentials, string $deviceId, string $channel): array
    {
        // Ảnh bìa do Imou tự cập nhật theo chu kỳ — dùng lại luồng đã tạo, không phát sinh lệnh chụp riêng.
        $stream = $this->liveStream($credentials, $deviceId, $channel);

        if (blank($stream['cover_url'])) {
            throw new CloudProviderException('Imou chưa có ảnh bìa cho camera này.');
        }

        return ['url' => (string) $stream['cover_url'], 'expires_in' => 60];
    }

    /** @param array<string, mixed> $credentials */
    private function accessToken(array $credentials): string
    {
        $cacheKey = $this->tokenCacheKey($credentials);

        $cached = Cache::get($cacheKey);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $result = $this->call($credentials, 'accessToken', []);
        $token = $result['data']['accessToken'] ?? null;

        if ($result['code'] !== '0' || ! is_string($token) || $token === '') {
            throw new CloudProviderException('Imou từ chối đăng nhập: '.$result['msg'].' (mã '.$result['code'].'). Kiểm tra lại App ID, App Secret và vùng máy chủ.');
        }

        $ttl = max(60, (int) ($result['data']['expireTime'] ?? 3600) - 300);
        Cache::put($cacheKey, $token, $ttl);

        return $token;
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>  $params
     * @return array{code: string, msg: string, data: array<string, mixed>}
     */
    private function call(array $credentials, string $method, array $params): array
    {
        $appId = (string) ($credentials['app_id'] ?? '');
        $secret = (string) ($credentials['app_secret'] ?? '');

        if ($appId === '' || $secret === '') {
            throw new CloudProviderException('Chưa nhập App ID/App Secret của Imou.');
        }

        $time = time();
        $nonce = Str::replace('-', '', (string) Str::uuid());
        $source = "time:{$time},nonce:{$nonce},appSecret:{$secret}";
        $sign = base64_encode(hash_hmac('sha256', $source, hash('sha256', $secret), true));

        try {
            $response = Http::timeout(15)->acceptJson()->asJson()->post(
                "https://openapi-{$this->region($credentials)}.easy4ip.com:443/openapi/{$method}",
                [
                    'system' => ['ver' => '1.0', 'appId' => $appId, 'sign' => $sign, 'time' => $time, 'nonce' => $nonce],
                    'id' => (string) Str::uuid(),
                    'params' => (object) $params,
                ],
            );
        } catch (\Throwable $e) {
            throw new CloudProviderException('Không kết nối được tới Imou: '.$e->getMessage());
        }

        if ($response->failed()) {
            throw new CloudProviderException("Imou trả HTTP {$response->status()}.");
        }

        $result = $response->json('result');

        if (! is_array($result)) {
            throw new CloudProviderException('Imou trả dữ liệu không đúng định dạng.');
        }

        return [
            'code' => (string) ($result['code'] ?? ''),
            'msg' => (string) ($result['msg'] ?? ''),
            'data' => is_array($result['data'] ?? null) ? $result['data'] : [],
        ];
    }

    /**
     * Ưu tiên luồng HLS chất lượng cao (streamId 0) qua HTTPS nếu có.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private function pickStream(array $data): ?array
    {
        $streams = array_values(array_filter(
            is_array($data['streams'] ?? null) ? $data['streams'] : [],
            fn ($stream): bool => is_array($stream) && filled($stream['hls'] ?? null),
        ));

        if ($streams === []) {
            return null;
        }

        usort($streams, function (array $a, array $b): int {
            $score = fn (array $s): int => ((int) ($s['streamId'] ?? 1) === 0 ? 2 : 0) + (str_starts_with((string) $s['hls'], 'https') ? 1 : 0);

            return $score($b) <=> $score($a);
        });

        return $streams[0];
    }

    /** @param array<string, mixed> $credentials */
    private function tokenCacheKey(array $credentials): string
    {
        return 'cloud:imou:token:'.hash('sha256', ($credentials['app_id'] ?? '').'|'.$this->region($credentials));
    }

    /** @param array<string, mixed> $credentials */
    private function region(array $credentials): string
    {
        $region = (string) ($credentials['region'] ?? 'sg');

        return in_array($region, self::REGIONS, true) ? $region : 'sg';
    }
}
