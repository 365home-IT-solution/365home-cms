<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CameraSetting;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

// Gọi thẳng REST API của Frigate (lịch sử ghi hình + tạo/kết thúc sự kiện thủ công) — KHÁC
// Go2RtcClient (chỉ nói chuyện với go2rtc để đăng ký nguồn RTSP) và KHÁC WS proxy trong
// websocket/server.js (chỉ lo luồng xem trực tiếp qua giao thức MSE nhị phân). Endpoint và tham số
// lấy đúng từ mã nguồn thật của Frigate (frigate/api/record.py, frigate/api/event.py,
// frigate/api/defs/request/events_body.py — bản dev, tháng 9/2026), KHÔNG đoán tên field.
//
// Dùng chung cơ chế đăng nhập cookie với FrigateSessionClient (Frigate không có API key tĩnh).
// MỖI ĐỐI TÁC 1 server Frigate riêng (App\Models\CameraSetting) — tạo qua forPartner(), KHÔNG còn
// resolve qua DI container như 1 service dùng chung toàn hệ thống nữa.
class FrigateApiClient
{
    public function __construct(
        private readonly CameraSetting $settings,
        private readonly FrigateSessionClient $session,
    ) {
    }

    public static function forPartner(?string $partnerId): self
    {
        return new self(CameraSetting::forPartner($partnerId), FrigateSessionClient::forPartner($partnerId));
    }

    // GET /api/{camera_name}/recordings/summary?timezone= — tổng hợp THEO GIỜ: có ghi hình/motion/
    // object gì trong từng giờ của camera — dùng để vẽ lịch/thanh thời gian chọn giờ xem lại.
    public function recordingsSummary(string $cameraName, string $timezone = 'Asia/Ho_Chi_Minh'): array
    {
        return $this->get("/api/{$cameraName}/recordings/summary", ['timezone' => $timezone]);
    }

    // GET /api/{camera_name}/recordings?after=&before= — danh sách đoạn ghi hình (segment) trong
    // khoảng thời gian (unix timestamp, giây). Không truyền gì thì Frigate tự lấy 1 giờ gần nhất.
    public function recordings(string $cameraName, ?float $after = null, ?float $before = null): array
    {
        return $this->get("/api/{$cameraName}/recordings", array_filter([
            'after'  => $after,
            'before' => $before,
        ], fn ($v) => $v !== null));
    }

    // POST /api/events/{camera_name}/{label}/create — tạo 1 "sự kiện thủ công", cách THẬT SỰ để
    // "bắt đầu ghi hình theo yêu cầu" trong Frigate (Frigate không có API bật/tắt ghi hình kiểu công
    // tắc — camera ghi liên tục theo cấu hình sẵn có, "sự kiện" là đơn vị Frigate dùng để đánh dấu +
    // trích xuất 1 đoạn clip cụ thể). include_recording=true (mặc định của Frigate) để đoạn ghi hình
    // THẬT được cắt ra gắn với sự kiện này, không chỉ là 1 dòng ghi chú không có video kèm theo.
    public function createManualEvent(
        string $cameraName,
        string $label = 'manual',
        ?int $duration = 30,
        bool $includeRecording = true,
        ?int $preCapture = null,
    ): array {
        // "duration" PHẢI luôn có mặt trong JSON, KỂ CẢ khi null — theo đúng Pydantic model của
        // Frigate (EventsCreateBody), null tường minh nghĩa là "ghi tới khi gọi .../end" (khác hẳn
        // KHÔNG truyền field này, lúc đó Frigate tự lấy mặc định 30 giây). Trước đây dùng
        // array_filter(fn($v) => $v !== null) xoá mất field này khi $duration=null — khiến gọi
        // startRecording(..., duration: null) từ CameraMonitor (ý định "ghi không giới hạn") thực
        // tế lại tạo ra sự kiện CHỈ 30 GIÂY rồi Frigate tự kết thúc, người dùng tưởng đang ghi tiếp
        // nhưng thật ra đã dừng từ lâu. "pre_capture" mới là field thật sự nên BỎ HẲN nếu null (API
        // cho app ngoài không truyền field này).
        $body = ['duration' => $duration, 'include_recording' => $includeRecording];

        if ($preCapture !== null) {
            $body['pre_capture'] = $preCapture;
        }

        return $this->post("/api/events/{$cameraName}/{$label}/create", $body);
    }

    // PUT /api/events/{event_id}/end — kết thúc sự kiện thủ công ĐANG MỞ (tạo với duration=null ở
    // trên) — bắt buộc gọi nếu lúc tạo không truyền duration, không thì Frigate không biết khi nào
    // dừng ghi.
    public function endManualEvent(string $eventId, ?float $endTime = null): array
    {
        return $this->put("/api/events/{$eventId}/end", array_filter([
            'end_time' => $endTime,
        ], fn ($v) => $v !== null));
    }

    // GET /api/vod/{camera_name}/start/{after}/end/{before}/master.m3u8 — trả NGUYÊN VĂN nội dung
    // playlist (không phải JSON) để kiểm tra codec quảng cáo trong đó (CODECS="hev1..."/"avc1...")
    // TRƯỚC khi quyết định phát thẳng (H.264) hay phải chuyển mã (H.265/HEVC) — xem
    // CameraRecordingService::playbackUrl().
    public function masterPlaylistText(string $cameraName, float $after, float $before): array
    {
        $path = "/vod/{$cameraName}/start/{$after}/end/{$before}/master.m3u8";

        $cookie = $this->session->getSessionCookie(error: $error);

        if ($cookie === null) {
            return ['success' => false, 'error' => $error ?? 'Chưa cấu hình kết nối Frigate.'];
        }

        try {
            $response = Http::withHeaders(['Cookie' => $cookie])->timeout(15)->get($this->url($path));
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'Không kết nối được tới Frigate: ' . $e->getMessage()];
        }

        if ($response->status() === 401) {
            $retryCookie = $this->session->getSessionCookie(forceRelogin: true, error: $error);

            if ($retryCookie === null) {
                return ['success' => false, 'error' => $error ?? 'Phiên đăng nhập Frigate hết hạn.'];
            }

            try {
                $response = Http::withHeaders(['Cookie' => $retryCookie])->timeout(15)->get($this->url($path));
            } catch (\Throwable $e) {
                return ['success' => false, 'error' => 'Không kết nối được tới Frigate: ' . $e->getMessage()];
            }
        }

        if (! $response->successful()) {
            return ['success' => false, 'error' => "Frigate trả lỗi {$response->status()} cho {$path}: " . $response->body()];
        }

        return ['success' => true, 'data' => $response->body()];
    }

    private function get(string $path, array $query = []): array
    {
        return $this->handle(fn ($http) => $http->get($this->url($path), $query), $path);
    }

    private function post(string $path, array $body = []): array
    {
        return $this->handle(fn ($http) => $http->post($this->url($path), $this->jsonBody($body)), $path);
    }

    private function put(string $path, array $body = []): array
    {
        return $this->handle(fn ($http) => $http->put($this->url($path), $this->jsonBody($body)), $path);
    }

    // Frigate (Pydantic/FastAPI) từ chối body rỗng gửi dạng mảng JSON "[]" ("Input should be a
    // valid dictionary or object") — PHP json_encode([]) LUÔN ra "[]" chứ không phải "{}" khi mảng
    // rỗng không có key nào, dù bản chất là "không có field nào cần gửi". Ép về object rỗng "{}"
    // khi mảng rỗng — xác nhận qua lỗi 422 thật khi gọi PUT .../end không kèm end_time.
    private function jsonBody(array $body): array|\stdClass
    {
        return $body === [] ? new \stdClass() : $body;
    }

    private function url(string $path): string
    {
        return rtrim((string) $this->settings->base_url, '/') . $path;
    }

    /**
     * @param callable(\Illuminate\Http\Client\PendingRequest): Response $callback
     */
    private function handle(callable $callback, string $path): array
    {
        $cookie = $this->session->getSessionCookie(error: $error);

        if ($cookie === null) {
            return ['success' => false, 'error' => $error ?? 'Chưa cấu hình kết nối Frigate.'];
        }

        try {
            $response = $callback(Http::withHeaders(['Cookie' => $cookie])->timeout(15)->acceptJson());
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'Không kết nối được tới Frigate: ' . $e->getMessage()];
        }

        // Cookie hết hạn giữa chừng (Frigate trả 401) — thử đăng nhập lại 1 LẦN rồi gọi lại, tránh
        // bắt người dùng chờ tới chu kỳ tự làm mới cookie 6 tiếng của FrigateSessionClient.
        if ($response->status() === 401) {
            $retryCookie = $this->session->getSessionCookie(forceRelogin: true, error: $error);

            if ($retryCookie === null) {
                return ['success' => false, 'error' => $error ?? 'Phiên đăng nhập Frigate hết hạn.'];
            }

            try {
                $response = $callback(Http::withHeaders(['Cookie' => $retryCookie])->timeout(15)->acceptJson());
            } catch (\Throwable $e) {
                return ['success' => false, 'error' => 'Không kết nối được tới Frigate: ' . $e->getMessage()];
            }
        }

        if (! $response->successful()) {
            return [
                'success' => false,
                'error'   => "Frigate trả lỗi {$response->status()} cho {$path}: " . $response->body(),
            ];
        }

        $json = $response->json();

        return ['success' => true, 'data' => $json];
    }
}
