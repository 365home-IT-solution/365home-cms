<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ApprovalRequest;

/**
 * Trạng thái "trong 1 request" của luồng duyệt:
 *  - bypass(): chạy một đoạn code KHÔNG bị chặn duyệt — dùng cho các ghi nội bộ hợp lệ (đồng bộ bảng giá đã duyệt xuống phòng, áp bản sửa đã duyệt, dữ liệu mẫu).
 *  - notices: các yêu cầu duyệt vừa được tạo/cập nhật trong request này, để API trả kèm trường "approval" và trang Filament báo "Đã gửi 365 duyệt".
 */
final class ApprovalContext
{
    private static int $bypassDepth = 0;

    /** @var array<int, array<string, mixed>> */
    private static array $notices = [];

    public static function bypassed(): bool
    {
        return self::$bypassDepth > 0;
    }

    /**
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    public static function bypass(callable $callback): mixed
    {
        self::$bypassDepth++;

        try {
            return $callback();
        } finally {
            self::$bypassDepth--;
        }
    }

    /** @return bool true nếu yêu cầu này CHƯA được ghi nhận trong request hiện tại (để chỉ báo 1 lần). */
    public static function notice(ApprovalRequest $request): bool
    {
        $fresh = ! isset(self::$notices[$request->id]);

        self::$notices[$request->id] = [
            'request_id' => $request->id,
            'kind'       => $request->kind,
            'kind_label' => $request->kindLabel(),
            'status'     => $request->status,
            'subject'    => $request->subject_label,
            'message'    => 'Đã gửi 365home duyệt — thay đổi sẽ có hiệu lực sau khi được duyệt.',
        ];

        return $fresh;
    }

    /** @return list<array<string, mixed>> */
    public static function notices(): array
    {
        return array_values(self::$notices);
    }

    public static function hasNotices(): bool
    {
        return self::$notices !== [];
    }

    public static function reset(): void
    {
        self::$notices = [];
    }
}
