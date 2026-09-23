<?php

namespace Modules\Minihouse\App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

// Sinh + xác minh mã OTP đăng nhập Portal khách thuê — lưu tạm trong Cache (TỰ HẾT HẠN, không cần
// bảng riêng/dọn dẹp định kỳ), KHÔNG liên quan gì tới OTP đăng nhập của Home (App\Services nếu có,
// tách biệt hoàn toàn theo khoá cache riêng "minihouse_tenant_otp:{phone}").
//
// $purpose tách namespace cache theo MỤC ĐÍCH dùng OTP — mặc định 'login' PHẢI giữ NGUYÊN VĂN khoá
// cache CŨ "minihouse_tenant_otp:{phone}" (không thêm đoạn purpose vào), vì cả web
// (TenantAuthController) lẫn nhiều test (PortalTenantAuthTest/TenantPortalTest/
// TenantRememberCookieTest...) tự Cache::put() thẳng theo đúng khoá cũ này để giả lập trạng thái —
// đổi khoá sẽ âm thầm làm login bằng OTP không bao giờ khớp được nữa (đã tự gây ra regression này,
// xác nhận qua chạy lại `php artisan test --filter=Minihouse` trước khi chốt). CHỈ purpose khác
// 'login' (VD 'contract_sign', xem ContractDocumentService — docs/be-minihouse-contract-signing.md
// mục 5.2) mới thêm đoạn purpose vào khoá, tách hẳn namespace với OTP đăng nhập.
class TenantOtpService
{
    private const TTL_MINUTES = 5;
    private const MAX_ATTEMPTS = 5;
    private const DEFAULT_PURPOSE = 'login';

    // Chặn gửi lại QUÁ NHANH (spam SMS tốn phí) — phải đợi hết khoảng này mới gửi mã MỚI cho cùng 1
    // số điện thoại.
    private const RESEND_COOLDOWN_SECONDS = 60;

    private static function cacheKey(string $phone, string $purpose): string
    {
        return $purpose === self::DEFAULT_PURPOSE
            ? 'minihouse_tenant_otp:' . $phone
            : 'minihouse_tenant_otp:' . $purpose . ':' . $phone;
    }

    private static function cooldownKey(string $phone, string $purpose): string
    {
        return $purpose === self::DEFAULT_PURPOSE
            ? 'minihouse_tenant_otp_cooldown:' . $phone
            : 'minihouse_tenant_otp_cooldown:' . $purpose . ':' . $phone;
    }

    // Ghi lại kênh THẬT SỰ đã gửi thành công vào đúng bản ghi cache vừa tạo — verify() trả lại giá
    // trị này để nơi gọi (VD ContractDocumentService::signAsTenant()) biết ghi đúng auth_method
    // (otp_zalo/otp_sms) vào ContractSignature, không phải đoán.
    private static function rememberChannel(string $phone, string $purpose, string $channel, \Illuminate\Support\Carbon $expiresAt): void
    {
        $data = Cache::get(self::cacheKey($phone, $purpose));

        if (! $data) {
            return;
        }

        $data['channel'] = $channel;
        Cache::put(self::cacheKey($phone, $purpose), $data, $expiresAt);
    }

    // @return array{sent: bool, reason?: string, channel?: string, request_id?: string} — sent=false
    // kèm reason khi đang trong thời gian chờ gửi lại (KHÔNG phải lỗi gửi thật) hoặc khi CẢ 2 kênh
    // đều gửi thất bại/chưa cấu hình.
    //
    // Ưu tiên gửi qua ZALO trước (2026-09-09: SMS Brandname chưa đăng ký xong, chưa dùng được) — nếu
    // Zalo chưa cấu hình/chưa có mẫu OTP thì rơi xuống SMS (nếu SMS đã cấu hình). $recipientName chỉ
    // dùng để ghi log (ZaloNotification/SmsNotification), không ảnh hưởng nội dung mã gửi đi.
    //
    // $smsMessageBuilder: fn(string $code): string — dựng nội dung SMS tuỳ biến (VD nêu rõ số hợp
    // đồng đang ký) SAU khi đã biết mã thật (mã sinh NGẪU NHIÊN bên trong hàm này, nơi gọi không
    // biết trước) — CHỈ áp dụng khi rơi xuống kênh SMS; kênh Zalo dùng ĐÚNG 1 mẫu ZNS đã duyệt sẵn
    // cho OTP (biến "otp" cố định, xem MinihouseZaloService::sendOtp()) nên không tự chèn được nội
    // dung khác vào đó — muốn nêu rõ "mã ký hợp đồng ..." trên Zalo thì phải xin duyệt thêm 1 mẫu
    // ZNS riêng (việc nghiệp vụ, ngoài phạm vi sửa code này).
    public function generateAndSend(string $phone, ?string $recipientName = null, string $purpose = 'login', ?\Closure $smsMessageBuilder = null): array
    {
        if (Cache::has(self::cooldownKey($phone, $purpose))) {
            $secondsLeft = Cache::get(self::cooldownKey($phone, $purpose)) - now()->timestamp;

            return ['sent' => false, 'reason' => 'Vui lòng đợi ' . max(1, $secondsLeft) . ' giây trước khi gửi lại mã.'];
        }

        $code = (string) random_int(100000, 999999);
        $requestId = 'otp_' . Str::random(20);
        $expiresAt = now()->addMinutes(self::TTL_MINUTES);

        // Lưu SẴN mốc hết hạn tuyệt đối trong data — verify() ghi lại cache khi đếm sai lần thử phải
        // dùng ĐÚNG mốc này (không phải làm mới 1 khoảng TTL_MINUTES mới), nếu không mỗi lần đoán
        // sai sẽ vô tình "gia hạn" thêm 5 phút, khách/kẻ tấn công đoán cách nhau ~4 phút/lần có thể
        // kéo dài hiệu lực 1 mã OTP ra xa hơn nhiều so với 5 phút dự kiến.
        Cache::put(self::cacheKey($phone, $purpose), [
            'code'        => $code,
            'request_id'  => $requestId,
            'attempts'    => 0,
            'expires_at'  => $expiresAt->timestamp,
        ], $expiresAt);

        $zaloResult = app(MinihouseZaloService::class)->sendOtp($phone, $code, $recipientName);

        if ($zaloResult['success']) {
            Cache::put(self::cooldownKey($phone, $purpose), now()->addSeconds(self::RESEND_COOLDOWN_SECONDS)->timestamp, self::RESEND_COOLDOWN_SECONDS);
            self::rememberChannel($phone, $purpose, 'zalo', $expiresAt);

            return ['sent' => true, 'channel' => 'zalo', 'request_id' => $requestId];
        }

        // Zalo chưa cấu hình/chưa có mẫu OTP/gửi lỗi — thử SMS nếu đã cấu hình, KHÔNG throw để lộ
        // chi tiết kỹ thuật ra ngoài cho khách thuê.
        $smsContent = $smsMessageBuilder
            ? $smsMessageBuilder($code)
            : ('Ma xac thuc dang nhap MiniHouse cua ban la: ' . $code . '. Ma co hieu luc trong ' . self::TTL_MINUTES . ' phut, khong chia se ma nay cho bat ky ai.');
        $smsResult = app(MinihouseSmsService::class)->sendRaw($phone, $smsContent, $recipientName);

        if ($smsResult['success']) {
            Cache::put(self::cooldownKey($phone, $purpose), now()->addSeconds(self::RESEND_COOLDOWN_SECONDS)->timestamp, self::RESEND_COOLDOWN_SECONDS);
            self::rememberChannel($phone, $purpose, 'sms', $expiresAt);

            return ['sent' => true, 'channel' => 'sms', 'request_id' => $requestId];
        }

        // Cả 2 kênh đều thất bại/chưa cấu hình — xoá mã vừa tạo, KHÔNG để khách kẹt ở màn "Nhập mã"
        // cho 1 mã họ không bao giờ nhận được, cũng không giữ cooldown (cho thử lại ngay).
        Cache::forget(self::cacheKey($phone, $purpose));

        return ['sent' => false, 'reason' => 'Chưa gửi được mã xác thực qua Zalo hoặc SMS — vui lòng liên hệ chủ nhà để được hỗ trợ.'];
    }

    // @return array{valid: bool, reason?: string}
    //
    // $expectedRequestId: nếu truyền vào (đúng otp_request_id trả về lúc gửi), PHẢI khớp mã đang
    // lưu — chặn trường hợp gửi lại request_id của 1 lần gửi mã CŨ đã bị mã mới ghi đè (VD bấm "gửi
    // lại" rồi vẫn lỡ tay gửi kèm request_id lần trước). Bỏ trống thì bỏ qua kiểm tra này, giữ đúng
    // hành vi cũ cho luồng đăng nhập (app hiện tại không gửi request_id).
    public function verify(string $phone, string $code, string $purpose = 'login', ?string $expectedRequestId = null): array
    {
        $data = Cache::get(self::cacheKey($phone, $purpose));

        if (! $data) {
            return ['valid' => false, 'reason' => 'Mã đã hết hạn hoặc chưa được gửi — vui lòng bấm gửi lại mã.'];
        }

        if ($expectedRequestId !== null && ($data['request_id'] ?? null) !== $expectedRequestId) {
            return ['valid' => false, 'reason' => 'Mã xác thực không khớp lần gửi gần nhất — vui lòng bấm gửi lại mã.'];
        }

        if ($data['attempts'] >= self::MAX_ATTEMPTS) {
            Cache::forget(self::cacheKey($phone, $purpose));

            return ['valid' => false, 'reason' => 'Bạn đã nhập sai quá nhiều lần — vui lòng bấm gửi lại mã mới.'];
        }

        if (! hash_equals($data['code'], $code)) {
            $data['attempts']++;
            // Giữ NGUYÊN mốc hết hạn ban đầu (đã lưu lúc generateAndSend()) — không addMinutes() mới
            // ở đây, nếu không mỗi lần đoán sai sẽ tự gia hạn thêm TTL_MINUTES, kéo dài hiệu lực mã
            // OTP xa hơn 5 phút dự kiến ban đầu.
            $ttl = isset($data['expires_at']) ? \Illuminate\Support\Carbon::createFromTimestamp($data['expires_at']) : now()->addMinutes(self::TTL_MINUTES);
            Cache::put(self::cacheKey($phone, $purpose), $data, $ttl);

            $remaining = self::MAX_ATTEMPTS - $data['attempts'];

            return ['valid' => false, 'reason' => 'Mã xác thực không đúng — còn ' . max(0, $remaining) . ' lần thử.'];
        }

        // Dùng 1 lần — xoá ngay sau khi xác minh đúng, không cho dùng lại mã cũ.
        Cache::forget(self::cacheKey($phone, $purpose));

        return ['valid' => true, 'channel' => $data['channel'] ?? null];
    }
}
