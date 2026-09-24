<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CameraSetting;
use App\Services\FrigateSessionClient;
use App\Support\CameraMediaToken;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Process\Process;

// Proxy HTTP công khai (KHÔNG qua auth:sanctum — trình phát HLS trong <video>/hls.js không tự gắn
// được Bearer token vào từng request đoạn .ts/.m4s) cho việc PHÁT LẠI lịch sử ghi hình — khác hẳn
// luồng xem TRỰC TIẾP (đi qua WebSocket, websocket/server.js). Bù lại bằng CameraMediaToken (ký
// riêng, hạn ngắn, chỉ dùng được cho ĐÚNG 1 khoảng thời gian ghi hình đã xin phép qua
// CameraRecordingController::playbackUrl() — không phải "mở public toàn bộ Frigate").
//
// 2 nhánh tuỳ token->transcode (quyết định 1 LẦN lúc phát hành token, xem CameraRecordingService):
//   false: chuyển tiếp NGUYÊN VĂN HLS gốc (camera ghi H.264 — trình duyệt tự phát được).
//   true : camera ghi H.265/HEVC — chuyển tiếp thẳng thì trình duyệt desktop không giải mã được
//          (đã tự xác nhận qua test thật: tải file đúng 100% nhưng "NotSupportedError"). Dùng
//          ffmpeg chuyển mã sang H.264 ngay khi phát, trả về 1 file MP4 phân mảnh (fragmented —
//          không cần "moov" ở cuối file nên phát được ngay khi đang stream qua pipe, không phải chờ
//          ffmpeg chạy xong).
class CameraMediaProxyController extends Controller
{
    // KHÔNG còn constructor-inject CameraSettings/FrigateSessionClient dùng chung 1 instance nữa —
    // mỗi đối tác có thể có server Frigate riêng (App\Models\CameraSetting), nên phải resolve LẠI
    // theo partner_id ký trong CameraMediaToken ở mỗi request (xem stream()).
    public function stream(Request $request, string $token, string $filename): StreamedResponse|Response
    {
        $claims = CameraMediaToken::verify($token);

        if ($claims === null) {
            return response('Token không hợp lệ hoặc đã hết hạn.', 401);
        }

        $settings = CameraSetting::forPartner($claims['partner_id']);
        $session  = FrigateSessionClient::forPartner($claims['partner_id']);

        $cookie = $session->getSessionCookie(error: $error);

        if ($cookie === null) {
            return response($error ?? 'Chưa cấu hình kết nối Frigate.', 502);
        }

        if ($claims['transcode']) {
            return $this->streamTranscoded($claims['path'], $cookie, $settings);
        }

        return $this->streamPassthrough($claims['path'], $filename, $cookie, $request->header('Range'), $settings, $session);
    }

    // Chuyển tiếp NGUYÊN VĂN — camera H.264, trình duyệt tự phát HLS được, không cần đụng gì thêm.
    // Forward nguyên header Range (bắt buộc để tua/seek hoạt động đúng) và trả nguyên Content-Range/
    // Accept-Ranges/206 Partial Content nếu Frigate/nginx trả về.
    private function streamPassthrough(string $pathPrefix, string $filename, string $cookie, ?string $range, CameraSetting $settings, FrigateSessionClient $session): StreamedResponse|Response
    {
        $url = rtrim((string) $settings->base_url, '/') . $pathPrefix . '/' . $filename;

        $upstream = $this->fetch($url, $cookie, $range);

        if ($upstream->status() === 401) {
            $retryCookie = $session->getSessionCookie(forceRelogin: true, error: $error);

            if ($retryCookie === null) {
                return response($error ?? 'Phiên đăng nhập Frigate hết hạn.', 502);
            }

            $upstream = $this->fetch($url, $retryCookie, $range);
        }

        if (! $upstream->successful() && $upstream->status() !== 206) {
            return response("Frigate trả lỗi {$upstream->status()} khi tải {$filename}.", 502);
        }

        $headers = array_filter([
            'Content-Type'   => $upstream->header('Content-Type') ?: 'application/octet-stream',
            'Content-Length' => $upstream->header('Content-Length'),
            'Content-Range'  => $upstream->header('Content-Range'),
            'Accept-Ranges'  => $upstream->header('Accept-Ranges') ?: 'bytes',
            'Cache-Control'  => 'no-store',
        ]);

        $body = $upstream->toPsrResponse()->getBody();

        return response()->stream(function () use ($body) {
            // Cùng lỗi BOM đã vá ở streamTranscoded() — áp dụng lại ở đây vì đây là nhánh RIÊNG,
            // BOM chèn vào đầu response phá luôn nội dung .m3u8 (text) lẫn segment nhị phân
            // (.mp4/.m4s), khiến hls.js/trình duyệt không đọc được dù request trả 200 OK — đã tự
            // xác nhận qua thực tế: camera H.264 (không qua nhánh transcode) vẫn đen dù master.m3u8
            // trả 200.
            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            while (! $body->eof()) {
                echo $body->read(1024 * 64);

                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            }
        }, $upstream->status(), $headers);
    }

    // Chuyển mã H.265 -> H.264 real-time bằng ffmpeg: input là ĐÚNG URL HLS gốc của Frigate (ffmpeg
    // tự đọc master.m3u8 -> sub-playlist -> từng segment qua HTTP, gắn cookie Frigate qua "-headers"
    // cho MỌI request con — hành vi chuẩn của demuxer HLS trong ffmpeg, không cần tự lo từng file).
    // Output "-movflags frag_keyframe+empty_moov" — MP4 PHÂN MẢNH, phát được ngay khi đang ghi ra
    // pipe (không phải đợi ffmpeg chạy xong mới có "moov atom" như MP4 thường).
    private function streamTranscoded(string $pathPrefix, string $cookie, CameraSetting $settings): StreamedResponse|Response
    {
        $inputUrl = rtrim((string) $settings->base_url, '/') . $pathPrefix . '/master.m3u8';

        $binary = (string) config('services.ffmpeg.binary', 'ffmpeg');

        $process = new Process([
            $binary,
            '-headers', "Cookie: {$cookie}\r\n",
            '-i', $inputUrl,
            '-c:v', 'libx264',
            '-preset', 'veryfast',
            '-crf', '23',
            '-c:a', 'aac',
            '-movflags', 'frag_keyframe+empty_moov+default_base_moof',
            '-f', 'mp4',
            'pipe:1',
        ]);

        // Đoạn ghi hình có thể dài (đã cho phép tới khoảng vài chục phút ở modal lịch sử) — chuyển
        // mã KHÔNG chạy nhanh hơn thời lượng video bao nhiêu (thường ngang tốc độ thực với preset
        // veryfast trên CPU thường), không đặt timeout cố định kẻo bị Symfony tự kill giữa chừng.
        $process->setTimeout(null);
        $process->start();

        return response()->stream(function () use ($process) {
            // Dự án này có BOM UTF-8 (EF BB BF) bị rò vào đầu MỌI response (thấy ở cả JSON/HTML —
            // vô hại vì trình duyệt/JSON parser tự bỏ qua, nhưng với file nhị phân MP4 thì 3 byte
            // thừa này phá hỏng ngay box "ftyp" ở đầu file, khiến trình phát báo "moov atom not
            // found" dù ffmpeg tạo ra file hoàn toàn đúng — đã tự xác nhận bằng xxd so sánh byte
            // đầu file tải qua proxy vs file ffmpeg ghi thẳng ra đĩa. Dọn sạch output buffer NGAY
            // trước khi ghi byte nhị phân đầu tiên để cắt đứt nguồn BOM đó, không sửa toàn hệ thống
            // (rủi ro cao hơn nhiều so với lợi ích, ngoài phạm vi tính năng camera).
            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            foreach ($process->getIterator(Process::ITER_SKIP_ERR) as $chunk) {
                echo $chunk;

                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();

                // Trình duyệt đóng kết nối giữa chừng (đóng modal/tua sang đoạn khác) — dừng ngay
                // tiến trình ffmpeg thay vì để nó chạy tiếp lãng phí CPU cho 1 kết nối không ai nhận.
                if (connection_aborted()) {
                    $process->stop(3);
                    break;
                }
            }

            if (! $process->isSuccessful() && ! $process->isTerminated()) {
                Log::warning('CameraMediaProxyController: ffmpeg lỗi khi chuyển mã', [
                    'error_output' => $process->getErrorOutput(),
                ]);
            }
        }, 200, [
            'Content-Type'  => 'video/mp4',
            'Cache-Control' => 'no-store',
        ]);
    }

    private function fetch(string $url, string $cookie, ?string $range): \Illuminate\Http\Client\Response
    {
        return Http::withHeaders(array_filter([
            'Cookie' => $cookie,
            'Range'  => $range,
        ]))
            ->withOptions(['stream' => true])
            ->timeout(30)
            ->get($url);
    }
}
