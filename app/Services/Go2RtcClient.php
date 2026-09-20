<?php

declare(strict_types=1);

namespace App\Services;

use App\Settings\CameraSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Gọi HTTP API của go2rtc (hoặc lõi go2rtc bên trong Frigate) để khai báo/xoá nguồn camera từ xa —
// mục tiêu: người dùng chỉ thao tác trên website 365home-cms, KHÔNG cần SSH vào server go2rtc để
// sửa file YAML mỗi khi thêm/đổi camera. Endpoint theo API chuẩn của go2rtc:
//   PUT  /api/streams?name=<key>&src=<rtsp-url>   (thêm/ghi đè 1 nguồn)
//   DELETE /api/streams?src=<key>                 (xoá 1 nguồn)
// Nếu server go2rtc thay đổi API (VD phiên bản Frigate khác), chỉ cần sửa 2 hàm trong lớp này.
class Go2RtcClient
{
    public function __construct(private readonly CameraSettings $settings)
    {
    }

    public function isConfigured(): bool
    {
        return $this->settings->isConfigured();
    }

    // Trả về null nếu thành công, hoặc chuỗi lỗi để hiển thị cho người dùng ngay trên form — KHÔNG
    // throw exception, vì lỗi phổ biến nhất (server go2rtc đang tắt/sai địa chỉ) không nên chặn
    // việc lưu thông tin camera vào CSDL web (người dùng có thể sửa lại RTSP sau, hoặc server chỉ
    // tạm thời offline).
    public function addStream(string $streamKey, string $rtspUrl): ?string
    {
        if (! $this->isConfigured()) {
            return 'Chưa cấu hình địa chỉ server go2rtc (vào Cấu hình web > Camera để khai báo).';
        }

        try {
            $response = $this->request()
                ->put('/api/streams', ['name' => $streamKey, 'src' => $rtspUrl]);

            if ($response->failed()) {
                return "Server go2rtc từ chối (HTTP {$response->status()}): " . $response->body();
            }
        } catch (\Throwable $e) {
            Log::warning('Go2RtcClient::addStream thất bại', ['stream_key' => $streamKey, 'error' => $e->getMessage()]);

            return 'Không kết nối được tới server go2rtc: ' . $e->getMessage();
        }

        return null;
    }

    public function deleteStream(string $streamKey): ?string
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $response = $this->request()->delete('/api/streams', ['src' => $streamKey]);

            if ($response->failed()) {
                return "Server go2rtc từ chối xoá (HTTP {$response->status()}): " . $response->body();
            }
        } catch (\Throwable $e) {
            Log::warning('Go2RtcClient::deleteStream thất bại', ['stream_key' => $streamKey, 'error' => $e->getMessage()]);

            return 'Không kết nối được tới server go2rtc: ' . $e->getMessage();
        }

        return null;
    }

    private function request()
    {
        $http = Http::baseUrl(rtrim((string) $this->settings->base_url, '/'))->timeout(5);

        if (filled($this->settings->api_key)) {
            $http = $http->withHeader('Authorization', 'Bearer ' . $this->settings->api_key);
        }

        return $http;
    }
}
