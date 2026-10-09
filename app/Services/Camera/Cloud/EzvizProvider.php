<?php

declare(strict_types=1);

namespace App\Services\Camera\Cloud;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

// EZVIZ Open Platform: POST form tới {api_host}/api/lapp/... — lấy accessToken (hiệu lực 7 ngày) bằng
// appKey + appSecret, sau đó gọi lấy địa chỉ xem (HLS) và chụp ảnh với accessToken + deviceSerial.
// Phản hồi dạng {"code":"200","msg":"...","data":{...}}.
class EzvizProvider implements CloudProvider
{
    private const DEFAULT_HOST = 'https://open.ezvizlife.com';

    private const LIVE_TTL_SECONDS = 3600;

    public function key(): string
    {
        return 'ezviz';
    }

    public function label(): string
    {
        return 'EZVIZ';
    }

    public function credentialFields(): array
    {
        return [
            ['key' => 'app_key', 'label' => 'App Key', 'secret' => false, 'required' => true, 'default' => null, 'options' => null],
            ['key' => 'app_secret', 'label' => 'App Secret', 'secret' => true, 'required' => true, 'default' => null, 'options' => null],
            ['key' => 'api_host', 'label' => 'Máy chủ API', 'secret' => false, 'required' => false, 'default' => self::DEFAULT_HOST, 'options' => null],
        ];
    }

    public function defaultChannel(): string
    {
        return '1';
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
        $data = $this->call($credentials, '/api/lapp/v2/live/address/get', [
            'accessToken' => $this->accessToken($credentials),
            'deviceSerial' => strtoupper($deviceId),
            'channelNo' => $channel,
            'protocol' => 2, // 2 = HLS
            'quality' => 1,
            'expireTime' => self::LIVE_TTL_SECONDS,
        ]);

        $url = $data['url'] ?? null;

        if (! is_string($url) || $url === '') {
            throw new CloudProviderException('EZVIZ không trả về địa chỉ xem cho camera này.');
        }

        return ['hls_url' => $url, 'expires_in' => self::LIVE_TTL_SECONDS, 'cover_url' => null];
    }

    public function snapshot(array $credentials, string $deviceId, string $channel): array
    {
        $data = $this->call($credentials, '/api/lapp/device/capture', [
            'accessToken' => $this->accessToken($credentials),
            'deviceSerial' => strtoupper($deviceId),
            'channelNo' => $channel,
        ]);

        $url = $data['picUrl'] ?? null;

        if (! is_string($url) || $url === '') {
            throw new CloudProviderException('EZVIZ không trả về ảnh chụp cho camera này.');
        }

        // Ảnh hiệu lực 2 giờ theo tài liệu hãng; báo ngắn hơn để app chủ động lấy ảnh mới.
        return ['url' => $url, 'expires_in' => 300];
    }

    /** @param array<string, mixed> $credentials */
    private function accessToken(array $credentials): string
    {
        $cacheKey = $this->tokenCacheKey($credentials);
        $cached = Cache::get($cacheKey);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $data = $this->call($credentials, '/api/lapp/token/get', [
            'appKey' => (string) ($credentials['app_key'] ?? ''),
            'appSecret' => (string) ($credentials['app_secret'] ?? ''),
        ], withToken: false);

        $token = $data['accessToken'] ?? null;

        if (! is_string($token) || $token === '') {
            throw new CloudProviderException('EZVIZ không trả về accessToken.');
        }

        // expireTime là mốc thời gian (mili giây); token sống 7 ngày, làm mới sớm 10 phút.
        $expiresAtMs = (int) ($data['expireTime'] ?? 0);
        $ttl = $expiresAtMs > 0 ? (int) floor($expiresAtMs / 1000) - time() - 600 : 6 * 86400;
        Cache::put($cacheKey, $token, max(60, $ttl));

        return $token;
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    private function call(array $credentials, string $path, array $form, bool $withToken = true): array
    {
        if (blank($credentials['app_key'] ?? null) || blank($credentials['app_secret'] ?? null)) {
            throw new CloudProviderException('Chưa nhập App Key/App Secret của EZVIZ.');
        }

        try {
            $response = Http::timeout(15)->asForm()->acceptJson()->post($this->host($credentials).$path, $form);
        } catch (\Throwable $e) {
            throw new CloudProviderException('Không kết nối được tới EZVIZ: '.$e->getMessage());
        }

        if ($response->failed()) {
            throw new CloudProviderException("EZVIZ trả HTTP {$response->status()}.");
        }

        $code = (string) $response->json('code');

        if ($code !== '200') {
            // Token hết hạn/sai (10001, 10002) — xoá cache để lần sau đăng nhập lại.
            if ($withToken && in_array($code, ['10001', '10002'], true)) {
                Cache::forget($this->tokenCacheKey($credentials));
            }

            throw new CloudProviderException('EZVIZ từ chối: '.(string) $response->json('msg').' (mã '.$code.').');
        }

        $data = $response->json('data');

        return is_array($data) ? $data : [];
    }

    /** @param array<string, mixed> $credentials */
    private function tokenCacheKey(array $credentials): string
    {
        return 'cloud:ezviz:token:'.hash('sha256', ($credentials['app_key'] ?? '').'|'.$this->host($credentials));
    }

    /** @param array<string, mixed> $credentials */
    private function host(array $credentials): string
    {
        $host = rtrim((string) ($credentials['api_host'] ?? ''), '/');

        return $host !== '' ? $host : self::DEFAULT_HOST;
    }
}
