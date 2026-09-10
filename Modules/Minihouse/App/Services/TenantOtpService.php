<?php

namespace Modules\Minihouse\App\Services;

use Illuminate\Support\Facades\Cache;

// Sinh + xác minh mã OTP đăng nhập Portal khách thuê — lưu tạm trong Cache (TỰ HẾT HẠN, không cần
// bảng riêng/dọn dẹp định kỳ), KHÔNG liên quan gì tới OTP đăng nhập của Home (App\Services nếu có,
// tách biệt hoàn toàn theo khoá cache riêng "minihouse_tenant_otp:{phone}").
class TenantOtpService
{
    private const TTL_MINUTES = 5;
    private const MAX_ATTEMPTS = 5;

    // Chặn gửi lại QUÁ NHANH (spam SMS tốn phí) — phải đợi hết khoảng này mới gửi mã MỚI cho cùng 1
    // số điện thoại.
    private const RESEND_COOLDOWN_SECONDS = 60;

    private static function cacheKey(string $phone): string
    {
        return 'minihouse_tenant_otp:' . $phone;
    }

    private static function cooldownKey(string $phone): string
    {
        return 'minihouse_tenant_otp_cooldown:' . $phone;
    }

    // @return array{sent: bool, reason?: string} — sent=false kèm reason khi đang trong thời gian
    // chờ gửi lại (KHÔNG phải lỗi gửi thật) hoặc khi CẢ 2 kênh đều gửi thất bại/chưa cấu hình.
    //
    // Ưu tiên gửi qua ZALO trước (2026-09-09: SMS Brandname chưa đăng ký xong, chưa dùng được) — nếu
    // Zalo chưa cấu hình/chưa có mẫu OTP thì rơi xuống SMS (nếu SMS đã cấu hình). $recipientName chỉ
    // dùng để ghi log (ZaloNotification/SmsNotification), không ảnh hưởng nội dung mã gửi đi.
    public function generateAndSend(string $phone, ?string $recipientName = null): array
    {
        if (Cache::has(self::cooldownKey($phone))) {
            $secondsLeft = Cache::get(self::cooldownKey($phone)) - now()->timestamp;

            return ['sent' => false, 'reason' => 'Vui lòng đợi ' . max(1, $secondsLeft) . ' giây trước khi gửi lại mã.'];
        }

        $code = (string) random_int(100000, 999999);
        $expiresAt = now()->addMinutes(self::TTL_MINUTES);

        // Lưu SẴN mốc hết hạn tuyệt đối trong data — verify() ghi lại cache khi đếm sai lần thử phải
        // dùng ĐÚNG mốc này (không phải làm mới 1 khoảng TTL_MINUTES mới), nếu không mỗi lần đoán
        // sai sẽ vô tình "gia hạn" thêm 5 phút, khách/kẻ tấn công đoán cách nhau ~4 phút/lần có thể
        // kéo dài hiệu lực 1 mã OTP ra xa hơn nhiều so với 5 phút dự kiến.
        Cache::put(self::cacheKey($phone), ['code' => $code, 'attempts' => 0, 'expires_at' => $expiresAt->timestamp], $expiresAt);

        $zaloResult = app(MinihouseZaloService::class)->sendOtp($phone, $code, $recipientName);

        if ($zaloResult['success']) {
            Cache::put(self::cooldownKey($phone), now()->addSeconds(self::RESEND_COOLDOWN_SECONDS)->timestamp, self::RESEND_COOLDOWN_SECONDS);

            return ['sent' => true, 'channel' => 'zalo'];
        }

        // Zalo chưa cấu hình/chưa có mẫu OTP/gửi lỗi — thử SMS nếu đã cấu hình, KHÔNG throw để lộ
        // chi tiết kỹ thuật ra ngoài cho khách thuê.
        $smsContent = 'Ma xac thuc dang nhap MiniHouse cua ban la: ' . $code . '. Ma co hieu luc trong ' . self::TTL_MINUTES . ' phut, khong chia se ma nay cho bat ky ai.';
        $smsResult = app(MinihouseSmsService::class)->sendRaw($phone, $smsContent, $recipientName);

        if ($smsResult['success']) {
            Cache::put(self::cooldownKey($phone), now()->addSeconds(self::RESEND_COOLDOWN_SECONDS)->timestamp, self::RESEND_COOLDOWN_SECONDS);

            return ['sent' => true, 'channel' => 'sms'];
        }

        // Cả 2 kênh đều thất bại/chưa cấu hình — xoá mã vừa tạo, KHÔNG để khách kẹt ở màn "Nhập mã"
        // cho 1 mã họ không bao giờ nhận được, cũng không giữ cooldown (cho thử lại ngay).
        Cache::forget(self::cacheKey($phone));

        return ['sent' => false, 'reason' => 'Chưa gửi được mã xác thực qua Zalo hoặc SMS — vui lòng liên hệ chủ nhà để được hỗ trợ.'];
    }

    // @return array{valid: bool, reason?: string}
    public function verify(string $phone, string $code): array
    {
        $data = Cache::get(self::cacheKey($phone));

        if (! $data) {
            return ['valid' => false, 'reason' => 'Mã đã hết hạn hoặc chưa được gửi — vui lòng bấm gửi lại mã.'];
        }

        if ($data['attempts'] >= self::MAX_ATTEMPTS) {
            Cache::forget(self::cacheKey($phone));

            return ['valid' => false, 'reason' => 'Bạn đã nhập sai quá nhiều lần — vui lòng bấm gửi lại mã mới.'];
        }

        if (! hash_equals($data['code'], $code)) {
            $data['attempts']++;
            // Giữ NGUYÊN mốc hết hạn ban đầu (đã lưu lúc generateAndSend()) — không addMinutes() mới
            // ở đây, nếu không mỗi lần đoán sai sẽ tự gia hạn thêm TTL_MINUTES, kéo dài hiệu lực mã
            // OTP xa hơn 5 phút dự kiến ban đầu.
            $ttl = isset($data['expires_at']) ? \Illuminate\Support\Carbon::createFromTimestamp($data['expires_at']) : now()->addMinutes(self::TTL_MINUTES);
            Cache::put(self::cacheKey($phone), $data, $ttl);

            $remaining = self::MAX_ATTEMPTS - $data['attempts'];

            return ['valid' => false, 'reason' => 'Mã xác thực không đúng — còn ' . max(0, $remaining) . ' lần thử.'];
        }

        // Dùng 1 lần — xoá ngay sau khi xác minh đúng, không cho dùng lại mã cũ.
        Cache::forget(self::cacheKey($phone));

        return ['valid' => true];
    }
}
