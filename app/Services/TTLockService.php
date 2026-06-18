<?php

namespace App\Services;

use App\Models\TtlockAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * TTLock Cloud API Service
 * Docs: https://cnapi.ttlock.com
 */
class TTLockService
{

    private string $clientId;
    private string $clientSecret;
    private string $username;
    private string $password;
    private string $apiBase;
    private string $cachePrefix;

    public function __construct(
        string $clientId     = '',
        string $clientSecret = '',
        string $username     = '',
        string $password     = '',
        string $apiBase      = '',
        string $cachePrefix  = 'ttlock_env'
    ) {
        $this->clientId     = $clientId     ?: (config('services.ttlock.client_id') ?? '');
        $this->clientSecret = $clientSecret ?: (config('services.ttlock.client_secret') ?? '');
        $this->username     = $username     ?: (config('services.ttlock.username') ?? '');
        $this->password     = $password     ?: (config('services.ttlock.password') ?? '');
        $this->apiBase      = $apiBase      ?: (config('services.ttlock.api_base', 'https://cnapi.ttlock.com'));
        $this->cachePrefix  = $cachePrefix;
    }

    // =========================================================
    // Factory: lấy instance theo chi nhánh
    // Trả về null nếu không có account nào được cấu hình cho chi nhánh
    // =========================================================

    public static function forCategory(?int $categoryId): ?self
    {
        if (!$categoryId) {
            return null;
        }

        $account = TtlockAccount::whereHas(
                'categories',
                fn ($q) => $q->where('categories.id', $categoryId)
            )
            ->where('is_active', true)
            ->first();

        if (!$account) {
            return null;
        }

        return new self(
            $account->client_id,
            $account->client_secret,
            $account->username,
            $account->password_md5,
            $account->api_base,
            "ttlock_acct_{$account->id}"
        );
    }

    // =========================================================
    // PUBLIC: Lấy access token (tự động refresh nếu hết hạn)
    // =========================================================

    public function getAccessToken(): ?string
    {
        $tokenKey   = "{$this->cachePrefix}_access_token";
        $refreshKey = "{$this->cachePrefix}_refresh_token";

        $token        = Cache::get($tokenKey);
        $refreshToken = Cache::get($refreshKey);

        if ($token) {
            return $token;
        }

        if ($refreshToken) {
            $result = $this->refreshAccessToken($refreshToken);
            if ($result) {
                return $result['access_token'] ?? null;
            }
        }

        $result = $this->fetchNewToken();
        return $result['access_token'] ?? null;
    }

    // =========================================================
    // Lấy access token bằng username + password (Resource Owner)
    // =========================================================

    public function fetchNewToken(): ?array
    {
        try {
            $response = Http::timeout(12)->withOptions([
                'verify' => false,
            ])->asForm()->post($this->apiBase . '/oauth2/token', [
                'clientId'     => $this->clientId,
                'clientSecret' => $this->clientSecret,
                'username'     => $this->username,
                'password'     => $this->password,
            ]);

            $data = $response->json();

            Log::info('TTLock fetchNewToken response', [
                'status' => $response->status(),
                'body'   => $data,
            ]);

            if ($response->successful() && isset($data['access_token'])) {
                $this->storeTokens($data);
                return $data;
            }

            Log::error('TTLock fetchNewToken failed', ['response' => $data]);
            return null;

        } catch (\Exception $e) {
            Log::error('TTLock fetchNewToken exception', ['error' => $e->getMessage()]);
            return null;
        }
    }

    // =========================================================
    // Refresh access token bằng refresh_token
    // =========================================================

    public function refreshAccessToken(string $refreshToken): ?array
    {
        try {
            $response = Http::timeout(10)->withOptions([
                'verify' => false,
            ])->asForm()->post($this->apiBase . '/oauth2/token', [
                'clientId'      => $this->clientId,
                'clientSecret'  => $this->clientSecret,
                'grant_type'    => 'refresh_token',
                'refresh_token' => $refreshToken,
            ]);

            $data = $response->json();

            Log::info('TTLock refreshAccessToken response', [
                'status' => $response->status(),
                'body'   => $data,
            ]);

            if ($response->successful() && isset($data['access_token'])) {
                $this->storeTokens($data);
                return $data;
            }

            Log::error('TTLock refreshAccessToken failed', ['response' => $data]);
            return null;

        } catch (\Exception $e) {
            Log::error('TTLock refreshAccessToken exception', ['error' => $e->getMessage()]);
            return null;
        }
    }

    // =========================================================
    // Lưu tokens vào cache (cache key riêng theo account)
    // =========================================================

    private function storeTokens(array $data): void
    {
        $expiresIn    = (int) ($data['expires_in'] ?? 7776000);
        $refreshToken = $data['refresh_token'] ?? null;
        $accessToken  = $data['access_token'];

        Cache::put("{$this->cachePrefix}_access_token", $accessToken, now()->addSeconds($expiresIn - 300));

        if ($refreshToken) {
            Cache::put("{$this->cachePrefix}_refresh_token", $refreshToken, now()->addYears(10));
        }

        Log::info('TTLock tokens stored in cache', [
            'cache_prefix' => $this->cachePrefix,
            'expires_in'   => $expiresIn,
            'uid'          => $data['uid'] ?? null,
        ]);
    }

    // =========================================================
    // Xóa token cache (dùng khi muốn force re-auth)
    // =========================================================

    public function clearTokenCache(): void
    {
        Cache::forget("{$this->cachePrefix}_access_token");
        Cache::forget("{$this->cachePrefix}_refresh_token");
    }

    // =========================================================
    // Lấy danh sách khóa của tài khoản
    // GET /v3/lock/list
    // =========================================================

    public function getLockList(): array
    {
        $token = $this->getAccessToken();

        if (!$token) {
            Log::error('TTLock getLockList: no access token');
            return [];
        }

        $pageNo   = 1;
        $pageSize = 100;
        $locks    = [];

        do {
            try {
                $response = Http::timeout(20)->withOptions([
                    'verify' => false,
                ])->get("{$this->apiBase}/v3/lock/list", [
                    'clientId'    => $this->clientId,
                    'accessToken' => $token,
                    'pageNo'      => $pageNo,
                    'pageSize'    => $pageSize,
                    'date'        => (int) round(microtime(true) * 1000),
                ]);

                $data = $response->json();

                if (!$response->successful() || !isset($data['list'])) {
                    Log::error('TTLock getLockList failed', [
                        'status' => $response->status(),
                        'body'   => $data,
                    ]);
                    break;
                }

                $locks  = array_merge($locks, $data['list']);
                $pages  = (int) ($data['pages'] ?? 1);
                $pageNo++;

            } catch (\Exception $e) {
                Log::error('TTLock getLockList exception', ['error' => $e->getMessage()]);
                break;
            }
        } while ($pageNo <= $pages);

        return $locks;
    }

    // =========================================================
    // Cấp mã passcode cho 1 khóa
    // POST /v3/keyboardPwd/get
    // =========================================================

    public function generatePasscode(
        int    $lockId,
        int    $startDate,
        int    $endDate = 0,
        string $name    = 'Khách đặt phòng',
        int    $pwdType = 3
    ): ?array {
        $token = $this->getAccessToken();

        if (!$token) {
            Log::error('TTLock generatePasscode: no access token');
            return null;
        }

        $now       = (int) round(microtime(true) * 1000);
        $startDate = $startDate - (30 * 60 * 1000);
        if ($endDate > 0) {
            $endDate = $endDate + (30 * 60 * 1000);
        }

        $params = [
            'clientId'        => $this->clientId,
            'accessToken'     => $token,
            'lockId'          => $lockId,
            'keyboardPwdType' => $pwdType,
            'keyboardPwdName' => $name,
            'startDate'       => $startDate,
            'date'            => $now,
        ];

        if ($endDate > 0) {
            $params['endDate'] = $endDate;
        }

        try {
            $response = Http::timeout(20)->withOptions([
                'verify' => false,
            ])->asForm()->post("{$this->apiBase}/v3/keyboardPwd/get", $params);

            $data = $response->json();

            Log::info('TTLock generatePasscode response', [
                'lockId' => $lockId,
                'status' => $response->status(),
                'data'   => $data,
            ]);

            if ($response->successful() && isset($data['keyboardPwd'])) {
                return [
                    'code'          => $data['keyboardPwd'],
                    'keyboardPwdId' => (int) ($data['keyboardPwdId'] ?? 0),
                ];
            }

            Log::error('TTLock generatePasscode failed', ['lockId' => $lockId, 'response' => $data]);
            return null;

        } catch (\Exception $e) {
            Log::error('TTLock generatePasscode exception', ['lockId' => $lockId, 'error' => $e->getMessage()]);
            return null;
        }
    }

    // =========================================================
    // Thêm mã passcode tùy chỉnh vào khóa
    // POST /v3/keyboardPwd/add
    // =========================================================

    public function addCustomPasscode(
        int    $lockId,
        string $code,
        int    $startDate,
        int    $endDate = 0,
        string $name    = 'Khách đặt phòng',
        int    $pwdType = 3,
        int    $addType = 2
    ): ?array {
        $token = $this->getAccessToken();

        if (!$token) {
            Log::error('TTLock addCustomPasscode: no access token');
            return null;
        }

        $now       = (int) round(microtime(true) * 1000);
        $startDate = $startDate - (30 * 60 * 1000);
        if ($endDate > 0) {
            $endDate = $endDate + (30 * 60 * 1000);
        }

        $params = [
            'clientId'        => $this->clientId,
            'accessToken'     => $token,
            'lockId'          => $lockId,
            'keyboardPwd'     => $code,
            'keyboardPwdType' => $pwdType,
            'keyboardPwdName' => $name,
            'startDate'       => $startDate,
            'addType'         => $addType,
            'date'            => $now,
        ];

        if ($endDate > 0) {
            $params['endDate'] = $endDate;
        }

        try {
            $response = Http::timeout(30)->withOptions([
                'verify' => false,
            ])->asForm()->post("{$this->apiBase}/v3/keyboardPwd/add", $params);

            $data = $response->json();

            Log::info('TTLock addCustomPasscode response', [
                'lockId' => $lockId,
                'code'   => $code,
                'status' => $response->status(),
                'data'   => $data,
            ]);

            if ($response->successful() && isset($data['keyboardPwdId'])) {
                return ['keyboardPwdId' => (int) $data['keyboardPwdId']];
            }

            Log::error('TTLock addCustomPasscode failed', ['lockId' => $lockId, 'response' => $data]);
            return null;

        } catch (\Exception $e) {
            Log::error('TTLock addCustomPasscode exception', ['lockId' => $lockId, 'error' => $e->getMessage()]);
            return null;
        }
    }

    // =========================================================
    // Cập nhật thời gian hiệu lực của mã passcode
    // POST /v3/keyboardPwd/change
    // =========================================================

    public function modifyPasscode(
        int    $lockId,
        int    $keyboardPwdId,
        int    $startDate,
        int    $endDate    = 0,
        string $name       = '',
        int    $changeType = 2
    ): bool {
        $token = $this->getAccessToken();

        if (!$token) {
            Log::error('TTLock modifyPasscode: no access token');
            return false;
        }

        $now = (int) round(microtime(true) * 1000);

        $params = [
            'clientId'      => $this->clientId,
            'accessToken'   => $token,
            'lockId'        => $lockId,
            'keyboardPwdId' => $keyboardPwdId,
            'startDate'     => $startDate,
            'changeType'    => $changeType,
            'date'          => $now,
        ];

        if ($endDate > 0) {
            $params['endDate'] = $endDate;
        }

        if ($name !== '') {
            $params['keyboardPwdName'] = $name;
        }

        try {
            $response = Http::timeout(20)->withOptions([
                'verify' => false,
            ])->asForm()->post("{$this->apiBase}/v3/keyboardPwd/change", $params);

            $data = $response->json();

            Log::info('TTLock modifyPasscode response', [
                'lockId'        => $lockId,
                'keyboardPwdId' => $keyboardPwdId,
                'status'        => $response->status(),
                'data'          => $data,
            ]);

            return $response->successful() && (($data['errcode'] ?? -1) === 0);

        } catch (\Exception $e) {
            Log::error('TTLock modifyPasscode exception', [
                'lockId'        => $lockId,
                'keyboardPwdId' => $keyboardPwdId,
                'error'         => $e->getMessage(),
            ]);
            return false;
        }
    }

    // =========================================================
    // Mở khóa từ xa qua cloud (yêu cầu lock có gateway online)
    // POST https://api.sciener.com/v3/lock/unlock
    // Lưu ý: endpoint này dùng api.sciener.com, KHÔNG phải euapi/cnapi
    // Nếu nhận -4043: bật "Remote Unlock" trong Sciener APP > cài đặt khóa
    // =========================================================

    private const SCIENER_API = 'https://api.sciener.com';

    public function remoteUnlock(int $lockId): bool
    {
        $token = $this->getAccessToken();

        if (!$token) {
            Log::error('TTLock remoteUnlock: no access token');
            return false;
        }

        try {
            $response = Http::timeout(15)->withOptions([
                'verify' => false,
                'curl'   => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4],
            ])->asForm()->post(self::SCIENER_API . '/v3/lock/unlock', [
                'clientId'    => $this->clientId,
                'accessToken' => $token,
                'lockId'      => $lockId,
                'date'        => (int) round(microtime(true) * 1000),
            ]);

            $data = $response->json();

            Log::info('TTLock remoteUnlock response', [
                'lockId'   => $lockId,
                'status'   => $response->status(),
                'errcode'  => $data['errcode'] ?? null,
                'errmsg'   => $data['errmsg'] ?? null,
            ]);

            if (($data['errcode'] ?? -1) === -4043) {
                Log::warning('TTLock remoteUnlock: tính năng chưa bật trên khóa', [
                    'lockId' => $lockId,
                    'hint'   => 'Bật Remote Unlock trong Sciener APP > cài đặt khóa',
                ]);
            }

            return $response->successful() && (($data['errcode'] ?? -1) === 0);

        } catch (\Exception $e) {
            Log::error('TTLock remoteUnlock exception', [
                'lockId' => $lockId,
                'error'  => $e->getMessage(),
            ]);
            return false;
        }
    }

    // =========================================================
    // Xóa mã passcode khỏi khóa
    // POST /v3/keyboardPwd/delete
    // =========================================================

    public function deletePasscode(int $lockId, int $keyboardPwdId, int $deleteType = 2): bool
    {
        $token = $this->getAccessToken();

        if (!$token) {
            Log::error('TTLock deletePasscode: no access token');
            return false;
        }

        $now = (int) round(microtime(true) * 1000);

        try {
            $response = Http::timeout(10)->withOptions([
                'verify' => false,
            ])->asForm()->post("{$this->apiBase}/v3/keyboardPwd/delete", [
                'clientId'      => $this->clientId,
                'accessToken'   => $token,
                'lockId'        => $lockId,
                'keyboardPwdId' => $keyboardPwdId,
                'deleteType'    => $deleteType,
                'date'          => $now,
            ]);

            $data = $response->json();

            Log::info('TTLock deletePasscode response', [
                'lockId'        => $lockId,
                'keyboardPwdId' => $keyboardPwdId,
                'status'        => $response->status(),
                'data'          => $data,
            ]);

            return $response->successful() && (($data['errcode'] ?? -1) === 0);

        } catch (\Exception $e) {
            Log::error('TTLock deletePasscode exception', [
                'lockId'        => $lockId,
                'keyboardPwdId' => $keyboardPwdId,
                'error'         => $e->getMessage(),
            ]);
            return false;
        }
    }
}
