<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Camera;
use App\Support\CameraMediaToken;

// Điểm dùng CHUNG cho "xem lại lịch sử" + "ghi hình thủ công" — cả App\Http\Controllers\Api\Admin\
// CameraRecordingController (API cho app ngoài) LẪN App\Filament\Pages\CameraMonitor (giao diện
// CMS) đều gọi qua đây, tránh viết lại 2 lần cùng 1 logic nghiệp vụ (map Camera -> tên Frigate, ký
// token phát lại...). Bản thân service này chỉ là lớp mỏng gọi FrigateApiClient — xem class đó để
// biết chính xác endpoint Frigate thật.
class CameraRecordingService
{
    public function __construct(private readonly FrigateApiClient $frigate)
    {
    }

    public function recordingsSummary(Camera $camera, string $timezone = 'Asia/Ho_Chi_Minh'): array
    {
        return $this->frigate->recordingsSummary($camera->frigateCameraName(), $timezone);
    }

    public function recordings(Camera $camera, ?float $after = null, ?float $before = null): array
    {
        return $this->frigate->recordings($camera->frigateCameraName(), $after, $before);
    }

    /**
     * Trả về URL video DÙNG ĐƯỢC NGAY đi qua proxy của chính server này
     * (CameraMediaProxyController) — KHÔNG bao giờ trả thẳng URL Frigate (đòi hỏi cookie đăng nhập
     * mà bên gọi không có).
     *
     * TỰ KIỂM TRA CODEC trước khi trả URL: đa số camera IP ghi hình bằng H.265/HEVC để tiết kiệm ổ
     * cứng (đã xác nhận thực tế — trình duyệt desktop thông thường KHÔNG giải mã được H.265 qua
     * MSE/hls.js, dù proxy chuyển tiếp đúng 100% file, video vẫn đen với lỗi "NotSupportedError").
     * Phát hiện HEVC thì trả URL trỏ tới nhánh chuyển mã (ffmpeg, H.264) thay vì phát thẳng HLS gốc.
     */
    public function playbackUrl(Camera $camera, float $after, float $before): string
    {
        $frigatePathPrefix = "/vod/{$camera->frigateCameraName()}/start/{$after}/end/{$before}";

        $isHevc = $this->isHevc($frigatePathPrefix);

        $token = CameraMediaToken::issue($frigatePathPrefix, ttlSeconds: 3 * 3600, transcode: $isHevc);

        $filename = $isHevc ? 'stream.mp4' : 'master.m3u8';

        return url("/api/camera-media/{$token}/{$filename}");
    }

    // Đọc thẳng nội dung master.m3u8 để soi dòng CODECS="hev1..."/"hvc1..." (HEVC) — không dò được
    // (lỗi kết nối/Frigate chưa cấu hình) thì coi như KHÔNG phải HEVC (an toàn hơn: cứ thử phát HLS
    // thẳng trước, tệ nhất là lặp lại đúng lỗi "NotSupportedError" đã biết, còn hơn bắt mọi lần lỗi
    // mạng đều phải chuyển mã tốn CPU oan).
    private function isHevc(string $frigatePathPrefix): bool
    {
        // frigatePathPrefix dạng "/vod/{camera}/start/{a}/end/{b}" — tách lại tham số để gọi đúng
        // chữ ký FrigateApiClient::masterPlaylistText(cameraName, after, before).
        if (! preg_match('#^/vod/(.+)/start/([0-9.]+)/end/([0-9.]+)$#', $frigatePathPrefix, $m)) {
            return false;
        }

        $result = $this->frigate->masterPlaylistText($m[1], (float) $m[2], (float) $m[3]);

        if (! $result['success']) {
            return false;
        }

        return str_contains($result['data'], 'hev1') || str_contains($result['data'], 'hvc1');
    }

    public function startRecording(
        Camera $camera,
        string $label = 'manual',
        ?int $duration = null,
        bool $includeRecording = true,
        ?int $preCapture = null,
    ): array {
        return $this->frigate->createManualEvent($camera->frigateCameraName(), $label, $duration, $includeRecording, $preCapture);
    }

    public function stopRecording(string $eventId): array
    {
        return $this->frigate->endManualEvent($eventId);
    }
}
