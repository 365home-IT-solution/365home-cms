<?php

declare(strict_types=1);

namespace App\Support;

// Cho phép OrderObserver::updated() TẠM DỪNG ghi log ngay lập tức khi đang trong luồng lưu của
// EditOrder::handleRecordUpdate() — gom vào đây, để EditOrder::afterSave() lấy ra và gộp chung với
// chi tiết phòng/khung giờ/dịch vụ thành 1 dòng audit log DUY NHẤT thay vì 2 dòng rời rạc cho cùng
// 1 lần bấm "Lưu".
//
// CHỈ ảnh hưởng ĐÚNG 1 request đang xử lý (state trong RAM, không persist) — mọi nơi KHÁC gọi
// $order->update() mà KHÔNG gọi suppress() trước đó (API webhook, quick-action ngoài EditOrder,
// cron ExpirePaymentOrders...) không bật cờ này nên OrderObserver vẫn ghi log NGAY LẬP TỨC như cũ,
// không đổi hành vi — tránh rủi ro 1 nơi gọi update() nào đó bị "nuốt mất" log vì quên gọi pull().
class OrderAuditBuffer
{
    private static array $suppressed = [];

    private static array $buffered = [];

    public static function suppress(string $orderId): void
    {
        self::$suppressed[$orderId] = true;
    }

    public static function isSuppressed(string $orderId): bool
    {
        return ! empty(self::$suppressed[$orderId]);
    }

    public static function note(string $orderId, array $old, array $new): void
    {
        if (! isset(self::$buffered[$orderId])) {
            self::$buffered[$orderId] = ['old' => [], 'new' => []];
        }

        self::$buffered[$orderId]['old'] = array_merge(self::$buffered[$orderId]['old'], $old);
        self::$buffered[$orderId]['new'] = array_merge(self::$buffered[$orderId]['new'], $new);
    }

    // Lấy ra + xoá luôn (mỗi lần Lưu chỉ dùng 1 lần) — gọi cả khi không suppress() gì để dọn sạch
    // state, tránh rò rỉ sang request/lần lưu sau nếu có lỗi giữa chừng.
    public static function pull(string $orderId): array
    {
        $data = self::$buffered[$orderId] ?? ['old' => [], 'new' => []];
        unset(self::$buffered[$orderId], self::$suppressed[$orderId]);

        return $data;
    }
}
