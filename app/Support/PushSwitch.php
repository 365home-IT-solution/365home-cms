<?php

namespace App\Support;

/**
 * Công tắc tạm thời chặn thông báo ĐẨY RA NGOÀI (push FCM tới thiết bị, tín hiệu "có thông báo mới" cho admin, tin Telegram) — dùng khi thử nghiệm trên DB có token thiết bị thật.
 * Đặt PUSH_NOTIFICATIONS_ENABLED=false trong .env (rồi php artisan config:clear). Mặc định BẬT.
 * KHÔNG chặn: API, đơn hàng, realtime cập nhật đơn cho client, thông báo lưu trong DB (chuông trong CMS) — client vẫn tương tác bình thường.
 */
class PushSwitch
{
    public static function enabled(): bool
    {
        return (bool) config('services.push_notifications', true);
    }
}
