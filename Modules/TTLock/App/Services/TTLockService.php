<?php

namespace Modules\TTLock\App\Services;

use Modules\TTLock\Entities\TtlockAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * TTLock Cloud API Service
 * Docs: https://cnapi.ttlock.com
 */
class TTLockService
{
    // Remote unlock (mở khoá từ xa) LUÔN đi qua domain Sciener chung này, KHÁC với domain OAuth/
    // API còn lại (đi theo $this->apiBase riêng của từng account/khu vực — xem __construct()).
    private const SCIENER_API = 'https://api.sciener.com';

    private string $clientId;
    private string $clientSecret;
    private string $username;
    private string $password;
    private string $apiBase;
    private string $cachePrefix;

    public function __construct(
        string $clientId,
        string $clientSecret,
        string $username,
        string $password,
        string $apiBase      = 'https://euapi.ttlock.com',
        string $cachePrefix  = 'ttlock_db'
    ) {
        $this->clientId     = $clientId;
        $this->clientSecret = $clientSecret;
        $this->username     = $username;
        $this->password     = $password;
        $this->apiBase      = $apiBase;
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

    // Kiểm tra NHANH chi nhánh có tài khoản TTLock đang hoạt động hay không, không cần dựng cả
    // instance service (khỏi tạo object thừa khi chỉ dùng để quyết định ẩn/hiện UI theo chi nhánh
    // — vd cột Khóa ngoài/Khóa trong, action "Gán khóa TTLock", "Tình trạng phòng" ở Thiết lập
    // Phòng, và "Mã cổng"/"Mở cổng tự do" ở đơn đặt phòng).
    // Cache tĩnh trong 1 request — hàm này được gọi LẶP LẠI nhiều lần cho ĐÚNG 1 chi nhánh (mỗi
    // cột × mỗi dòng trong bảng "Thiết lập Phòng"/"Kiểm tra dọn phòng"), không cache sẽ query DB
    // thừa nhiều lần cho cùng 1 câu trả lời không đổi trong suốt request.
    private static array $accountExistsCache = [];

    public static function hasAccountForCategory(?int $categoryId): bool
    {
        if (!$categoryId) {
            return false;
        }

        return self::$accountExistsCache[$categoryId] ??= TtlockAccount::whereHas(
                'categories',
                fn ($q) => $q->where('categories.id', $categoryId)
            )
            ->where('is_active', true)
            ->exists();
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
            $response = Http::timeout(10)->withOptions([
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
    // Mở khóa từ xa (remote unlock) — 1 ổ
    // POST /v3/lock/unlock
    // =========================================================

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

    /**
     * Mở CẢ 2 ổ khóa của 1 cửa vật lý (Product::unlock_both_locks = true) — dùng khi 1 cửa gắn 2
     * ổ TTLock cần nhả CÙNG LÚC mới thật sự mở được, khác với remoteUnlock() thường (mở 1 ổ theo
     * đúng phase check-in/check-out). CHỈ coi là thành công khi CẢ 2 ổ đều mở được — 1 ổ lỗi thì
     * dù ổ kia đã nhả, cửa vẫn coi như CHƯA mở (đúng yêu cầu nghiệp vụ), để caller báo lỗi rõ ràng
     * thay vì báo "đã mở" trong khi cửa thực tế vẫn khóa.
     *
     * @return array{success: bool, checkin_success: bool, checkout_success: bool}
     */
    public function remoteUnlockBoth(int $checkinLockId, int $checkoutLockId): array
    {
        $checkinSuccess  = $this->remoteUnlock($checkinLockId);
        $checkoutSuccess = $this->remoteUnlock($checkoutLockId);

        if (! $checkinSuccess || ! $checkoutSuccess) {
            Log::error('TTLock remoteUnlockBoth: mở thiếu ổ — cửa coi như chưa mở', [
                'checkin_lock_id'  => $checkinLockId,
                'checkout_lock_id' => $checkoutLockId,
                'checkin_success'  => $checkinSuccess,
                'checkout_success' => $checkoutSuccess,
            ]);
        }

        return [
            'success'          => $checkinSuccess && $checkoutSuccess,
            'checkin_success'  => $checkinSuccess,
            'checkout_success' => $checkoutSuccess,
        ];
    }

    // =========================================================
    // Lịch sử mở/khoá của 1 ổ khóa (bao gồm cả sự kiện quẹt thẻ, kể cả thẻ CHƯA đăng ký bị từ chối) —
    // dùng để TEST xem quẹt 1 thẻ mới có báo được số thẻ (field keyboardPwd) về cloud hay không, mà
    // KHÔNG cần server public nhận callback (endpoint này chủ động HỎI cloud, không phải NHẬN đẩy).
    // POST /v3/lockRecord/list
    // =========================================================

    public function getLockRecords(int $lockId, int $pageNo = 1, int $pageSize = 20, int $startDate = 0, int $endDate = 0): array
    {
        $token = $this->getAccessToken();

        if (!$token) {
            Log::error('TTLock getLockRecords: no access token');
            return [];
        }

        try {
            $response = Http::timeout(20)->withOptions([
                'verify' => false,
            ])->get("{$this->apiBase}/v3/lockRecord/list", [
                'clientId'    => $this->clientId,
                'accessToken' => $token,
                'lockId'      => $lockId,
                'startDate'   => $startDate,
                'endDate'     => $endDate,
                'pageNo'      => $pageNo,
                'pageSize'    => $pageSize,
                'date'        => (int) round(microtime(true) * 1000),
            ]);

            $data = $response->json();

            if (!$response->successful() || !isset($data['list'])) {
                Log::error('TTLock getLockRecords failed', ['lockId' => $lockId, 'response' => $data]);
                return [];
            }

            return $data['list'];

        } catch (\Exception $e) {
            Log::error('TTLock getLockRecords exception', ['lockId' => $lockId, 'error' => $e->getMessage()]);
            return [];
        }
    }

    // =========================================================
    // Gọi 1 endpoint GET dạng danh sách (list) của TTLock và TỰ ĐỘNG duyệt hết mọi trang — mọi
    // endpoint list (thẻ, mã mở, vân tay, ekey) đều giới hạn tối đa pageSize=100/lần gọi, dùng chung
    // 1 vòng lặp ở đây thay vì lặp lại riêng từng hàm. Dừng khi trang trả về ít hơn pageSize (đã hết)
    // hoặc rỗng — không dựa vào field "pages" vì tên field không đồng nhất giữa các endpoint.
    // =========================================================

    private function paginateAll(string $path, array $extraParams, int $pageSize = 100): array
    {
        $token = $this->getAccessToken();

        if (!$token) {
            Log::error("TTLock paginateAll({$path}): no access token");
            return [];
        }

        $all = [];
        $pageNo = 1;

        do {
            try {
                $response = Http::timeout(20)->withOptions([
                    'verify' => false,
                ])->get("{$this->apiBase}{$path}", array_filter(array_merge($extraParams, [
                    'clientId'    => $this->clientId,
                    'accessToken' => $token,
                    'pageNo'      => $pageNo,
                    'pageSize'    => $pageSize,
                    'date'        => (int) round(microtime(true) * 1000),
                ]), fn ($v) => $v !== null));

                $data = $response->json();

                if (!$response->successful() || !isset($data['list'])) {
                    Log::error("TTLock paginateAll({$path}) failed", ['params' => $extraParams, 'response' => $data]);
                    break;
                }

                $all = array_merge($all, $data['list']);
                $gotCount = count($data['list']);
                $pageNo++;
            } catch (\Exception $e) {
                Log::error("TTLock paginateAll({$path}) exception", ['params' => $extraParams, 'error' => $e->getMessage()]);
                break;
            }
        } while ($gotCount >= $pageSize);

        return $all;
    }

    // =========================================================
    // Danh sách mã mở (passcode) đã tạo cho 1 khóa — TỰ ĐỘNG LẤY HẾT MỌI TRANG (API giới hạn
    // pageSize tối đa 100/lần gọi — phát hiện thật: 1 khóa dùng lâu ngày có > 100 mã, chỉ lấy trang 1
    // sẽ làm RỚT mất các mã cũ hơn, gây hiểu lầm "thiếu mã" dù thực ra API vẫn còn dữ liệu ở trang
    // sau). Xem paginateAll().
    // GET /v3/lock/listKeyboardPwd
    // =========================================================

    public function listKeyboardPwds(int $lockId, int $pageSize = 100): array
    {
        return $this->paginateAll('/v3/lock/listKeyboardPwd', [
            'lockId' => $lockId,
        ], $pageSize);
    }

    // =========================================================
    // Danh sách "ekey" (thành viên có quyền truy cập) — theo TOÀN BỘ tài khoản, mỗi bản ghi tự có
    // lockId/lockAlias riêng — lọc theo lockId phía gọi (API gốc chỉ lọc được theo lockAlias/groupId,
    // không lọc thẳng theo lockId).
    // GET /v3/key/list
    // =========================================================

    public function listEkeys(?string $lockAlias = null, int $pageSize = 100): array
    {
        return $this->paginateAll('/v3/key/list', [
            'lockAlias' => $lockAlias,
        ], $pageSize);
    }

    // =========================================================
    // Vân tay — CÙNG BẢN CHẤT với thẻ IC (xem addIcCard() bên dưới): fingerprintNumber PHẢI đã biết
    // trước, đọc được CHỈ qua SDK Bluetooth (app/thiết bị riêng), API này chỉ đăng ký + đồng bộ cloud.
    // Xác nhận qua doc thật https://euopen.ttlock.com/doc/api/v3/fingerprint/add — KHÔNG có tham số
    // addType (không có đường "qua Gateway" như thẻ/passcode), fingerprintType: 1=thường, 4=định kỳ.
    // POST /v3/fingerprint/add
    // =========================================================

    public function addFingerprint(
        int    $lockId,
        string $fingerprintNumber,
        int    $startDate = 0,
        int    $endDate   = 0,
        string $name      = '',
        int    $fingerprintType = 1
    ): ?array {
        $token = $this->getAccessToken();

        if (!$token) {
            Log::error('TTLock addFingerprint: no access token');
            return null;
        }

        $params = [
            'clientId'         => $this->clientId,
            'accessToken'      => $token,
            'lockId'           => $lockId,
            'fingerprintNumber' => $fingerprintNumber,
            'fingerprintType'  => $fingerprintType,
            'date'             => (int) round(microtime(true) * 1000),
        ];

        if ($name !== '') {
            $params['fingerprintName'] = $name;
        }
        if ($startDate > 0) {
            $params['startDate'] = $startDate;
        }
        if ($endDate > 0) {
            $params['endDate'] = $endDate;
        }

        try {
            $response = Http::timeout(20)->withOptions([
                'verify' => false,
            ])->asForm()->post("{$this->apiBase}/v3/fingerprint/add", $params);

            $data = $response->json();

            Log::info('TTLock addFingerprint response', [
                'lockId' => $lockId,
                'status' => $response->status(),
                'data'   => $data,
            ]);

            if ($response->successful() && (($data['errcode'] ?? -1) === 0 || isset($data['fingerprintId']))) {
                return ['fingerprintId' => (int) ($data['fingerprintId'] ?? 0)];
            }

            Log::error('TTLock addFingerprint failed', ['lockId' => $lockId, 'response' => $data]);
            return null;

        } catch (\Exception $e) {
            Log::error('TTLock addFingerprint exception', ['lockId' => $lockId, 'error' => $e->getMessage()]);
            return null;
        }
    }

    // =========================================================
    // Danh sách vân tay đã cấp cho 1 khóa
    // POST /v3/fingerprint/list
    // =========================================================

    public function listFingerprints(int $lockId, int $pageSize = 100): array
    {
        return $this->paginateAll('/v3/fingerprint/list', [
            'lockId' => $lockId,
        ], $pageSize);
    }

    // =========================================================
    // Xóa vân tay khỏi khóa — dùng fingerprintId (id bản ghi), không phải fingerprintNumber, cùng quy
    // ước với deleteIcCard().
    // POST /v3/fingerprint/delete
    // =========================================================

    public function deleteFingerprint(int $lockId, int $fingerprintId, int $deleteType = 2): bool
    {
        $token = $this->getAccessToken();

        if (!$token) {
            Log::error('TTLock deleteFingerprint: no access token');
            return false;
        }

        try {
            $response = Http::timeout(15)->withOptions([
                'verify' => false,
            ])->asForm()->post("{$this->apiBase}/v3/fingerprint/delete", [
                'clientId'      => $this->clientId,
                'accessToken'   => $token,
                'lockId'        => $lockId,
                'fingerprintId' => $fingerprintId,
                'deleteType'    => $deleteType,
                'date'          => (int) round(microtime(true) * 1000),
            ]);

            $data = $response->json();

            Log::info('TTLock deleteFingerprint response', [
                'lockId'        => $lockId,
                'fingerprintId' => $fingerprintId,
                'status'        => $response->status(),
                'data'          => $data,
            ]);

            return $response->successful() && (($data['errcode'] ?? -1) === 0);

        } catch (\Exception $e) {
            Log::error('TTLock deleteFingerprint exception', ['lockId' => $lockId, 'error' => $e->getMessage()]);
            return false;
        }
    }

    // =========================================================
    // Đổi thời hạn hiệu lực của vân tay đã cấp
    // POST /v3/fingerprint/changePeriod
    // =========================================================

    public function changeFingerprintPeriod(
        int $lockId,
        int $fingerprintId,
        int $startDate,
        int $endDate = 0,
        int $changeType = 2
    ): bool {
        $token = $this->getAccessToken();

        if (!$token) {
            Log::error('TTLock changeFingerprintPeriod: no access token');
            return false;
        }

        $params = [
            'clientId'      => $this->clientId,
            'accessToken'   => $token,
            'lockId'        => $lockId,
            'fingerprintId' => $fingerprintId,
            'startDate'     => $startDate,
            'changeType'    => $changeType,
            'date'          => (int) round(microtime(true) * 1000),
        ];

        if ($endDate > 0) {
            $params['endDate'] = $endDate;
        }

        try {
            $response = Http::timeout(20)->withOptions([
                'verify' => false,
            ])->asForm()->post("{$this->apiBase}/v3/fingerprint/changePeriod", $params);

            $data = $response->json();

            Log::info('TTLock changeFingerprintPeriod response', [
                'lockId'        => $lockId,
                'fingerprintId' => $fingerprintId,
                'status'        => $response->status(),
                'data'          => $data,
            ]);

            return $response->successful() && (($data['errcode'] ?? -1) === 0);

        } catch (\Exception $e) {
            Log::error('TTLock changeFingerprintPeriod exception', ['lockId' => $lockId, 'error' => $e->getMessage()]);
            return false;
        }
    }

    // =========================================================
    // Cấp thẻ từ (IC card) cho 1 khóa — số thẻ (cardNumber) PHẢI đã biết trước (đọc bằng app TTLock/
    // đầu đọc riêng) — API này KHÔNG có khả năng tự đọc thẻ mới, chỉ đăng ký 1 số thẻ đã biết vào
    // khóa + đặt thời hạn hiệu lực. Xác nhận qua doc thật: https://euopen.ttlock.com/doc/api/v3/identityCard/add
    // POST /v3/identityCard/add
    // =========================================================

    public function addIcCard(
        int    $lockId,
        string $cardNumber,
        int    $startDate = 0,
        int    $endDate   = 0,
        string $name      = '',
        int    $addType   = 2 // 2 = qua Gateway (không cần điện thoại đứng cạnh khóa lúc gọi API)
    ): ?array {
        $token = $this->getAccessToken();

        if (!$token) {
            Log::error('TTLock addIcCard: no access token');
            return null;
        }

        $params = [
            'clientId'    => $this->clientId,
            'accessToken' => $token,
            'lockId'      => $lockId,
            'cardNumber'  => $cardNumber,
            'addType'     => $addType,
            'date'        => (int) round(microtime(true) * 1000),
        ];

        if ($name !== '') {
            $params['cardName'] = $name;
        }
        if ($startDate > 0) {
            $params['startDate'] = $startDate;
        }
        if ($endDate > 0) {
            $params['endDate'] = $endDate;
        }

        try {
            $response = Http::timeout(20)->withOptions([
                'verify' => false,
            ])->asForm()->post("{$this->apiBase}/v3/identityCard/add", $params);

            $data = $response->json();

            Log::info('TTLock addIcCard response', [
                'lockId'     => $lockId,
                'cardNumber' => $cardNumber,
                'status'     => $response->status(),
                'data'       => $data,
            ]);

            if ($response->successful() && (($data['errcode'] ?? -1) === 0 || isset($data['cardId']))) {
                return ['cardId' => (int) ($data['cardId'] ?? 0)];
            }

            Log::error('TTLock addIcCard failed', ['lockId' => $lockId, 'response' => $data]);
            return null;

        } catch (\Exception $e) {
            Log::error('TTLock addIcCard exception', ['lockId' => $lockId, 'error' => $e->getMessage()]);
            return null;
        }
    }

    // =========================================================
    // Danh sách thẻ từ đã cấp cho 1 khóa
    // POST /v3/identityCard/list
    // =========================================================

    public function listIcCards(int $lockId, int $pageSize = 100): array
    {
        return $this->paginateAll('/v3/identityCard/list', [
            'lockId' => $lockId,
        ], $pageSize);
    }

    // =========================================================
    // Xóa thẻ từ khỏi khóa — CHÚ Ý: dùng cardId (id bản ghi, trả về từ addIcCard()/listIcCards()),
    // KHÔNG PHẢI cardNumber (số thẻ vật lý) — đã xác nhận thật qua doc
    // https://euopen.ttlock.com/doc/api/v3/identityCard/delete (khác addIcCard() nhận cardNumber).
    // POST /v3/identityCard/delete
    // =========================================================

    public function deleteIcCard(int $lockId, int $cardId, int $deleteType = 2): bool
    {
        $token = $this->getAccessToken();

        if (!$token) {
            Log::error('TTLock deleteIcCard: no access token');
            return false;
        }

        try {
            $response = Http::timeout(15)->withOptions([
                'verify' => false,
            ])->asForm()->post("{$this->apiBase}/v3/identityCard/delete", [
                'clientId'    => $this->clientId,
                'accessToken' => $token,
                'lockId'      => $lockId,
                'cardId'      => $cardId,
                'deleteType'  => $deleteType,
                'date'        => (int) round(microtime(true) * 1000),
            ]);

            $data = $response->json();

            Log::info('TTLock deleteIcCard response', [
                'lockId' => $lockId,
                'cardId' => $cardId,
                'status' => $response->status(),
                'data'   => $data,
            ]);

            return $response->successful() && (($data['errcode'] ?? -1) === 0);

        } catch (\Exception $e) {
            Log::error('TTLock deleteIcCard exception', ['lockId' => $lockId, 'error' => $e->getMessage()]);
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
