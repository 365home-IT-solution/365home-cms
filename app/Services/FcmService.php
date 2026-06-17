<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\FcmToken;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FcmService
{
    private string $projectId;
    private string $clientEmail;
    private string $privateKey;

    public function __construct()
    {
        $credentials    = json_decode(file_get_contents(storage_path('firebase/service-account.json')), true);
        $this->projectId    = $credentials['project_id'];
        $this->clientEmail  = $credentials['client_email'];
        $this->privateKey   = $credentials['private_key'];
    }

    /**
     * Gửi push notification đến tất cả thiết bị của một danh sách user.
     */
    public function sendToUsers(\Illuminate\Support\Collection $users, string $title, string $body, array $data = []): void
    {
        $tokens = FcmToken::whereIn('user_id', $users->pluck('id'))
            ->pluck('token')
            ->all();

        if (empty($tokens)) {
            return;
        }

        $accessToken = $this->getAccessToken();

        foreach ($tokens as $token) {
            $this->send($accessToken, $token, $title, $body, $data);
        }
    }

    /**
     * Xác thực token trước khi lưu vào DB.
     * - Expo token → validate bằng regex (không cần gọi API)
     * - FCM token  → gửi dry-run đến Firebase
     */
    public function validateToken(string $token): bool
    {
        if ($this->isExpoToken($token)) {
            return (bool) preg_match('/^ExponentPushToken\[[A-Za-z0-9_\-]+\]$/', $token);
        }

        return $this->validateFcmToken($token);
    }

    private function validateFcmToken(string $token): bool
    {
        $url = "https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send";

        $payload = [
            'validate_only' => true,
            'message'       => [
                'token' => $token,
                'data'  => ['ping' => '1'],
            ],
        ];

        try {
            $response = Http::withToken($this->getAccessToken())
                ->timeout(5)
                ->post($url, $payload);

            if ($response->successful()) {
                return true;
            }

            $errorCode = $response->json('error.details.0.errorCode') ?? '';

            return ! in_array($errorCode, ['UNREGISTERED', 'INVALID_ARGUMENT']);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Gửi push notification trực tiếp đến 1 token bất kỳ (dùng cho guest).
     */
    public function sendToToken(string $token, string $title, string $body, array $data = []): void
    {
        if (empty($token)) {
            return;
        }

        if ($this->isExpoToken($token)) {
            $this->sendViaExpo($token, $title, $body, $data);
        } else {
            $this->sendMobile($this->getAccessToken(), $token, $title, $body, $data);
        }
    }

    /**
     * Gửi push notification đến thiết bị của khách hàng.
     * Tự detect Expo token hay FCM token để gửi đúng API.
     */
    public function sendToCustomer(Customer $customer, string $title, string $body, array $data = []): void
    {
        if (empty($customer->token_device)) {
            return;
        }

        if ($this->isExpoToken($customer->token_device)) {
            $this->sendViaExpo($customer->token_device, $title, $body, $data);
        } else {
            $this->sendMobile($this->getAccessToken(), $customer->token_device, $title, $body, $data);
        }
    }

    private function isExpoToken(string $token): bool
    {
        return str_starts_with($token, 'ExponentPushToken[');
    }

    private function sendViaExpo(string $token, string $title, string $body, array $data): void
    {
        $payload = [
            'to'    => $token,
            'title' => $title,
            'body'  => $body,
            'sound' => 'default',
            'data'  => $data,
        ];

        try {
            $response = Http::withHeaders([
                'Accept'       => 'application/json',
                'Content-Type' => 'application/json',
            ])
                ->timeout(10)
                ->post('https://exp.host/--/api/v2/push/send', $payload);

            $result = $response->json('data');
            $status = $result['status'] ?? '';

            if ($status === 'error') {
                $errorCode = $result['details']['error'] ?? '';

                if ($errorCode === 'DeviceNotRegistered') {
                    Customer::where('token_device', $token)->update(['token_device' => null]);
                } else {
                    Log::warning('Expo push failed', ['error' => $errorCode, 'token_prefix' => substr($token, 0, 30)]);
                }
            }
        } catch (\Throwable $e) {
            Log::error('Expo push exception', ['message' => $e->getMessage()]);
        }
    }

    private function sendMobile(string $accessToken, string $token, string $title, string $body, array $data): void
    {
        $url = "https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send";

        $payload = [
            'message' => [
                'token'        => $token,
                'notification' => ['title' => $title, 'body' => $body],
                'android'      => [
                    'notification' => ['sound' => 'default'],
                ],
                'apns' => [
                    'payload' => ['aps' => ['sound' => 'default', 'badge' => 1]],
                ],
                'data' => empty($data) ? new \stdClass() : array_map('strval', $data),
            ],
        ];

        try {
            $response = Http::withToken($accessToken)
                ->timeout(10)
                ->post($url, $payload);

            if ($response->failed()) {
                $error = $response->json('error.details.0.errorCode') ?? $response->body();

                if (in_array($error, ['UNREGISTERED', 'INVALID_ARGUMENT'])) {
                    Customer::where('token_device', $token)->update(['token_device' => null]);
                } else {
                    Log::warning('FCM mobile send failed', ['error' => $error, 'token_prefix' => substr($token, 0, 20)]);
                }
            }
        } catch (\Throwable $e) {
            Log::error('FCM mobile exception', ['message' => $e->getMessage()]);
        }
    }

    private function send(string $accessToken, string $token, string $title, string $body, array $data): void
    {
        $url = "https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send";

        $payload = [
            'message' => [
                'token'        => $token,
                'notification' => ['title' => $title, 'body' => $body],
                'webpush'      => [
                    'notification' => [
                        'title' => $title,
                        'body'  => $body,
                        'icon'  => '/favicon.ico',
                        'badge' => '/favicon.ico',
                        'requireInteraction' => false,
                    ],
                    'fcm_options' => [
                        'link' => config('app.url') . '/admin',
                    ],
                ],
                'data' => empty($data) ? new \stdClass() : array_map('strval', $data),
            ],
        ];

        try {
            $response = Http::withToken($accessToken)
                ->timeout(10)
                ->post($url, $payload);

            if ($response->failed()) {
                $error = $response->json('error.details.0.errorCode') ?? $response->body();

                // Token không còn hợp lệ → xoá khỏi DB
                if (in_array($error, ['UNREGISTERED', 'INVALID_ARGUMENT'])) {
                    FcmToken::where('token', $token)->delete();
                } else {
                    Log::warning('FCM send failed', ['error' => $error, 'token_prefix' => substr($token, 0, 20)]);
                }
            }
        } catch (\Throwable $e) {
            Log::error('FCM exception', ['message' => $e->getMessage()]);
        }
    }

    /**
     * Lấy OAuth2 access token từ Google (cache 50 phút).
     */
    private function getAccessToken(): string
    {
        return Cache::remember('fcm_oauth2_token', 3000, function () {
            $jwt      = $this->makeJwt();
            $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ]);

            if ($response->failed()) {
                throw new \RuntimeException('FCM OAuth2 failed: ' . $response->body());
            }

            return $response->json('access_token');
        });
    }

    /**
     * Tạo JWT assertion để đổi lấy Google OAuth2 token.
     */
    private function makeJwt(): string
    {
        $now = time();

        $header = $this->base64UrlEncode(json_encode([
            'alg' => 'RS256',
            'typ' => 'JWT',
        ]));

        $claims = $this->base64UrlEncode(json_encode([
            'iss'   => $this->clientEmail,
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud'   => 'https://oauth2.googleapis.com/token',
            'iat'   => $now,
            'exp'   => $now + 3600,
        ]));

        $signingInput = $header . '.' . $claims;

        openssl_sign($signingInput, $signature, $this->privateKey, 'SHA256');

        return $signingInput . '.' . $this->base64UrlEncode($signature);
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
