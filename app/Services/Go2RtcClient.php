<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CameraSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Gọi HTTP API của go2rtc (hoặc lõi go2rtc bên trong Frigate) để khai báo/xoá nguồn camera từ xa —
// mục tiêu: người dùng chỉ thao tác trên website 365home-cms, KHÔNG cần SSH vào server go2rtc để
// sửa file YAML mỗi khi thêm/đổi camera. Endpoint theo API chuẩn của go2rtc:
//   PUT  /api/streams?name=<key>&src=<rtsp-url>   (thêm/ghi đè 1 nguồn)
//   DELETE /api/streams?src=<key>                 (xoá 1 nguồn)
// Nếu server go2rtc thay đổi API (VD phiên bản Frigate khác), chỉ cần sửa 2 hàm trong lớp này.
//
// MỖI ĐỐI TÁC 1 server go2rtc/Frigate riêng (App\Models\CameraSetting) — tạo qua forPartner().
class Go2RtcClient
{
    public function __construct(private readonly CameraSetting $settings) {}

    public static function forPartner(?string $partnerId): self
    {
        return new self(CameraSetting::forPartner($partnerId));
    }

    public function isConfigured(): bool
    {
        return $this->settings->isGo2RtcConfigured();
    }

    // Trả về null nếu thành công, hoặc chuỗi lỗi để hiển thị cho người dùng ngay trên form — KHÔNG
    // throw exception, vì lỗi phổ biến nhất (server go2rtc đang tắt/sai địa chỉ) không nên chặn
    // việc lưu thông tin camera vào CSDL web (người dùng có thể sửa lại RTSP sau, hoặc server chỉ
    // tạm thời offline).
    public function addStream(string $streamKey, string $sourceUrl): ?string
    {
        if (! $this->isConfigured()) {
            return 'Chưa cấu hình địa chỉ server go2rtc (vào Cấu hình web > Camera để khai báo).';
        }

        try {
            $response = $this->request()
                // go2rtc reads these values from r.URL.Query(), not from a JSON body.
                ->put('/api/streams?'.http_build_query([
                    'name' => $streamKey,
                    'src' => $sourceUrl,
                ]));

            if ($response->failed()) {
                return "Server go2rtc từ chối (HTTP {$response->status()}): ".$response->body();
            }
        } catch (\Throwable $e) {
            Log::warning('Go2RtcClient::addStream thất bại', ['stream_key' => $streamKey, 'error' => $e->getMessage()]);

            return 'Không kết nối được tới server go2rtc: '.$e->getMessage();
        }

        return null;
    }

    /** Kiểm tra go2rtc truy cập được và chấp nhận xác thực (không đụng tới stream cụ thể nào). */
    public function ping(): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'message' => 'Chưa cấu hình địa chỉ go2rtc.'];
        }

        try {
            $response = $this->request()->get('/api/streams');
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Không kết nối được tới go2rtc: '.$e->getMessage()];
        }

        if ($response->failed()) {
            return ['ok' => false, 'message' => "go2rtc trả HTTP {$response->status()}."];
        }

        return ['ok' => true, 'message' => 'Kết nối go2rtc thành công.'];
    }

    /** Check that go2rtc is reachable and knows the requested stream. */
    public function checkStream(string $streamKey): array
    {
        if (! $this->isConfigured()) {
            return ['online' => false, 'message' => 'Chưa cấu hình địa chỉ server go2rtc.'];
        }

        try {
            $response = $this->request()->get('/api/streams?'.http_build_query(['src' => $streamKey]));

            if ($response->failed()) {
                return ['online' => false, 'message' => "go2rtc trả HTTP {$response->status()}: ".$response->body()];
            }

            $payload = $response->json();
            $hasProducer = is_array($payload)
                && ((isset($payload['producers']) && count((array) $payload['producers']) > 0)
                    || (isset($payload[$streamKey]['producers']) && count((array) $payload[$streamKey]['producers']) > 0));

            return [
                'online' => $hasProducer,
                'message' => $hasProducer ? 'Nguồn đang phát qua go2rtc.' : 'Kết nối được go2rtc nhưng nguồn chưa có producer hoạt động.',
                'details' => $payload,
            ];
        } catch (\Throwable $e) {
            Log::warning('Go2RtcClient::checkStream thất bại', ['stream_key' => $streamKey, 'error' => $e->getMessage()]);

            return ['online' => false, 'message' => 'Không kết nối được tới server go2rtc: '.$e->getMessage()];
        }
    }

    public function deleteStream(string $streamKey): ?string
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $response = $this->request()
                ->delete('/api/streams?'.http_build_query(['src' => $streamKey]));

            if ($response->failed()) {
                return "Server go2rtc từ chối xoá (HTTP {$response->status()}): ".$response->body();
            }
        } catch (\Throwable $e) {
            Log::warning('Go2RtcClient::deleteStream thất bại', ['stream_key' => $streamKey, 'error' => $e->getMessage()]);

            return 'Không kết nối được tới server go2rtc: '.$e->getMessage();
        }

        return null;
    }

    private function request()
    {
        $http = Http::baseUrl(rtrim((string) $this->settings->go2rtcBaseUrl(), '/'))->timeout(5);

        if (filled($this->settings->api_key)) {
            $http = $http->withHeader('Authorization', 'Bearer '.$this->settings->api_key);
        }

        return $http;
    }
}
