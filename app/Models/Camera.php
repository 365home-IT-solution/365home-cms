<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\BelongsToPartner;
use App\Services\Go2RtcClient;
use App\Settings\CameraSettings;
use App\Support\CameraWsToken;
use Illuminate\Database\Eloquent\Model;

class Camera extends Model
{
    use BelongsToBranch, BelongsToPartner;

    protected static function booted(): void
    {
        // Xoá bản ghi ở BẤT KỲ đâu (nút Xoá trên trang sửa, bulk action trên danh sách...) đều dọn
        // luôn nguồn tương ứng trên go2rtc — CHỈ khi camera này có rtsp_url (nghĩa là do CHÍNH web
        // này khai báo nguồn qua API). Đa số camera chỉ THAM CHIẾU tới nguồn ĐÃ CÓ SẴN trong Frigate
        // (rtsp_url để trống) — xoá bản ghi ở web KHÔNG được phép động tới go2rtc trong trường hợp
        // đó, nếu không sẽ làm gãy luồng thật Frigate đang chạy dù chỉ đang xoá 1 dòng tham chiếu.
        static::deleted(function (Camera $camera) {
            if (filled($camera->rtsp_url)) {
                app(Go2RtcClient::class)->deleteStream($camera->stream_key);
            }
        });
    }

    protected $fillable = [
        'partner_id',
        'branch_id',
        'name',
        'stream_key',
        'rtsp_url',
        'note',
        'status',
    ];

    protected $casts = [
        'status'   => 'boolean',
        // Mã hoá bằng APP_KEY — địa chỉ RTSP thường chứa sẵn tài khoản/mật khẩu camera
        // (rtsp://admin:matkhau@192.168.x.x/...), không lưu dạng thô trong CSDL.
        'rtsp_url' => 'encrypted',
    ];

    // URL WebSocket để nhúng vào <video-rtc> (public/vendor/go2rtc/video-rtc.js, client chính thức
    // của go2rtc) — trỏ về Node proxy CỦA CHÍNH DỰ ÁN NÀY (websocket/server.js, endpoint
    // /camera-proxy), KHÔNG trỏ thẳng sang Frigate: luồng video thật của Frigate chạy qua WebSocket
    // nhị phân (giao thức MSE riêng của go2rtc, xác nhận thực tế qua DevTools —
    // wss://.../live/mse/api/ws?src=...), yêu cầu cookie phiên đăng nhập Frigate mà trình duyệt
    // không tự có (khác domain). Node proxy tự xin cookie đó từ Laravel (FrigateSessionClient qua
    // route nội bộ /internal/frigate-session) rồi làm cầu nối 2 chiều. Token ký ngắn hạn
    // (CameraWsToken) để Node xác minh yêu cầu hợp lệ mà không cần tự truy vấn CSDL/CameraSettings.
    public function wsProxyUrl(): ?string
    {
        $settings = app(CameraSettings::class);

        if (! $settings->isConfigured()) {
            return null;
        }

        $publicUrl = rtrim((string) config('services.websocket.public_url'), '/');

        if ($publicUrl === '') {
            return null;
        }

        $wsBase = preg_replace('/^http/', 'ws', $publicUrl);
        // 60 giây (mặc định của CameraWsToken) là QUÁ NGẮN cho URL này: <video-rtc> chỉ lấy 1 URL
        // DUY NHẤT lúc trang tải xong rồi TỰ ĐỘNG kết nối lại đúng URL đó mỗi khi rớt mạng (không
        // bao giờ tự xin URL/token mới) — hết hạn là mọi lần kết nối lại sau đó đều bị từ chối vĩnh
        // viễn cho tới khi người dùng tự tải lại trang. Đã tự xác nhận đúng lỗi này qua log thật:
        // "token không hợp lệ/hết hạn" xuất hiện đúng sau khoảng 1 phút xem camera. 12 tiếng đủ cho
        // 1 ca làm việc xem liên tục, hết hạn thì tải lại trang "Xem camera" là có token mới.
        $token = CameraWsToken::issue($this->stream_key, (string) $settings->base_url, ttlSeconds: 12 * 3600);

        return "{$wsBase}/camera-proxy?token=" . urlencode($token);
    }
}
