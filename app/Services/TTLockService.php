<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * TTLock Cloud API Service
 * Docs: https://cnapi.ttlock.com
 */
class TTLockService
{
    private const API_BASE   = 'https://cnapi.ttlock.com';
    private const TOKEN_KEY  = 'ttlock_access_token';
    private const EXPIRY_KEY = 'ttlock_token_expiry';

    private string $clientId;
    private string $clientSecret;
    private string $username;
    private string $password;

    public function __construct()
    {
        $this->clientId     = config('services.ttlock.client_id') ?? '';
        $this->clientSecret = config('services.ttlock.client_secret') ?? '';
        $this->username     = config('services.ttlock.username') ?? '';
        $this->password     = config('services.ttlock.password') ?? '';
    }

    // =========================================================
    // PUBLIC: Lấy access token (tự động refresh nếu hết hạn)
    // =========================================================

    public function getAccessToken(): ?string
    {
        // 1. Thử lấy từ cache trước
        $token        = Cache::get(self::TOKEN_KEY);
        $refreshToken = Cache::get('ttlock_refresh_token');

        if ($token) {
            return $token;
        }

        // 2. Nếu có refresh_token thì dùng để lấy token mới
        if ($refreshToken) {
            $result = $this->refreshAccessToken($refreshToken);
            if ($result) {
                return $result['access_token'] ?? null;
            }
        }

        // 3. Lấy token mới bằng username/password
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
            ])->asForm()->post(self::API_BASE . '/oauth2/token', [
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
            ])->asForm()->post(self::API_BASE . '/oauth2/token', [
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
    // Lưu tokens vào cache
    // =========================================================

    private function storeTokens(array $data): void
    {
        $expiresIn    = (int) ($data['expires_in'] ?? 7776000); // 90 ngày mặc định
        $refreshToken = $data['refresh_token'] ?? null;
        $accessToken  = $data['access_token'];

        // Lưu access_token, trừ 5 phút dự phòng trước khi hết hạn
        Cache::put(self::TOKEN_KEY, $accessToken, now()->addSeconds($expiresIn - 300));

        // Lưu refresh_token 10 năm (như TTLock quy định)
        if ($refreshToken) {
            Cache::put('ttlock_refresh_token', $refreshToken, now()->addYears(10));
        }

        Log::info('TTLock tokens stored in cache', [
            'expires_in' => $expiresIn,
            'uid'        => $data['uid'] ?? null,
        ]);
    }

    // =========================================================
    // Xóa token cache (dùng khi muốn force re-auth)
    // =========================================================

    public function clearTokenCache(): void
    {
        Cache::forget(self::TOKEN_KEY);
        Cache::forget('ttlock_refresh_token');
    }

    // =========================================================
    // Lấy danh sách khóa của tài khoản
    // GET /v3/lock/list
    // =========================================================

    /**
     * Lấy toàn bộ danh sách khóa (tất cả trang).
     *
     * @return array{lockId: int, lockName: string, lockAlias: string, lockMac: string,
     *               electricQuantity: int, hasGateway: int, groupId: int, groupName: string}[]
     */
    public function getLockList(): array
    {
        $token = $this->getAccessToken();

        if (!$token) {
            Log::error('TTLock getLockList: no access token');
            return [];
        }

        $apiBase  = config('services.ttlock.api_base', 'https://euapi.ttlock.com');
        $pageNo   = 1;
        $pageSize = 100;
        $locks    = [];

        do {
            try {
                $response = Http::timeout(20)->withOptions([
                    'verify' => false,
                ])->get("{$apiBase}/v3/lock/list", [
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
    //
    // keyboardPwdType:
    //   1 = One-time  2 = Permanent  3 = Period (từ startDate → endDate)
    // =========================================================

    /**
     * Cấp 1 mã passcode ngẫu nhiên cho khóa TTLock.
     *
     * @param  int    $lockId       TTLock lockId
     * @param  int    $startDate    Timestamp milliseconds (bắt đầu hiệu lực)
     * @param  int    $endDate      Timestamp milliseconds (hết hạn), 0 = Permanent
     * @param  string $name         Tên mã (hiển thị trong app TTLock)
     * @param  int    $pwdType      1=one-time, 2=permanent, 3=period
     * @return array{code: string, keyboardPwdId: int}|null
     */
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

        $apiBase = config('services.ttlock.api_base', 'https://euapi.ttlock.com');
        $now     = (int) round(microtime(true) * 1000);

        // Mở rộng hiệu lực: trước 30 phút checkin, sau 30 phút checkout
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
            ])->asForm()->post("{$apiBase}/v3/keyboardPwd/get", $params);

            $data = $response->json();

            Log::info('TTLock generatePasscode response', [
                'lockId' => $lockId,
                'status' => $response->status(),
                'data'   => $data,
            ]);

            if ($response->successful() && isset($data['keyboardPwd'])) {
                return [
                    'code'            => $data['keyboardPwd'],
                    'keyboardPwdId'   => (int) ($data['keyboardPwdId'] ?? 0),
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
    //
    // Dùng để cấp cùng 1 mã cho khóa checkout sau khi đã generate cho khóa checkin
    // =========================================================

    /**
     * Thêm mã passcode tùy chỉnh vào khóa TTLock.
     *
     * @param  int    $lockId      TTLock lockId
     * @param  string $code        Mã số (6-9 ký tự số)
     * @param  int    $startDate   Timestamp milliseconds
     * @param  int    $endDate     Timestamp milliseconds (0 = permanent)
     * @param  string $name        Tên mã
     * @param  int    $pwdType     1=one-time, 2=permanent, 3=period
     * @param  int    $addType     1=app add, 2=gateway add (default: 2)
     * @return array{keyboardPwdId: int}|null
     */
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

        $apiBase = config('services.ttlock.api_base', 'https://euapi.ttlock.com');
        $now     = (int) round(microtime(true) * 1000);

        // Mở rộng hiệu lực: trước 30 phút checkin, sau 30 phút checkout
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
            $response = Http::timeout(10)->withOptions([
                'verify' => false,
            ])->asForm()->post("{$apiBase}/v3/keyboardPwd/add", $params);

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

    /**
     * Thay đổi thời gian hiệu lực của mã passcode TTLock.
     *
     * @param  int    $lockId         TTLock lockId
     * @param  int    $keyboardPwdId  ID mã cần sửa
     * @param  int    $startDate      Timestamp milliseconds
     * @param  int    $endDate        Timestamp milliseconds (0 = permanent)
     * @param  string $name           Tên mã (tùy chọn)
     * @param  int    $changeType     1=app change, 2=gateway change
     * @return bool
     */
    public function modifyPasscode(
        int    $lockId,
        int    $keyboardPwdId,
        int    $startDate,
        int    $endDate = 0,
        string $name       = '',
        int    $changeType = 2
    ): bool {
        $token = $this->getAccessToken();

        if (!$token) {
            Log::error('TTLock modifyPasscode: no access token');
            return false;
        }

        $apiBase = config('services.ttlock.api_base', 'https://euapi.ttlock.com');
        $now     = (int) round(microtime(true) * 1000);

        $params = [
            'clientId'        => $this->clientId,
            'accessToken'     => $token,
            'lockId'          => $lockId,
            'keyboardPwdId'   => $keyboardPwdId,
            'startDate'       => $startDate,
            'changeType'      => $changeType,
            'date'            => $now,
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
            ])->asForm()->post("{$apiBase}/v3/keyboardPwd/change", $params);

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
    // Xóa mã passcode khỏi khóa
    // POST /v3/keyboardPwd/delete
    // =========================================================

    /**
     * Xóa mã passcode khỏi khóa TTLock.
     *
     * @param  int $lockId         TTLock lockId
     * @param  int $keyboardPwdId  ID của mã cần xóa (lấy từ generate/add)
     * @param  int $deleteType     1=app delete, 2=gateway delete (default: 2)
     * @return bool
     */
    public function deletePasscode(int $lockId, int $keyboardPwdId, int $deleteType = 2): bool
    {
        $token = $this->getAccessToken();

        if (!$token) {
            Log::error('TTLock deletePasscode: no access token');
            return false;
        }

        $apiBase = config('services.ttlock.api_base', 'https://euapi.ttlock.com');
        $now     = (int) round(microtime(true) * 1000);

        try {
            $response = Http::timeout(10)->withOptions([
                'verify' => false,
            ])->asForm()->post("{$apiBase}/v3/keyboardPwd/delete", [
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
