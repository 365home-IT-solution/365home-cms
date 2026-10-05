<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

// CCCD NGƯỜI ĐI CÙNG trong request đặt phòng: guests[{n}][front|back|qr_image].
// Các client KHÔNG thống nhất cách đánh số {n}: web gửi theo VỊ TRÍ 0-based (guests[0] = người đi cùng đầu tiên),
// app mobile gửi theo SỐ THỨ TỰ KHÁCH (guests[2] = khách thứ 2 — log production 05/10/2026: file_field_paths có "guests.2.front"
// trong khi server chờ "guests.0.front" → báo thiếu CCCD dù ảnh đã gửi). Server nhận cả hai (và cả kiểu 1-based) bằng cách
// tự dò độ lệch từ chính các khoá có trong request.
class CompanionCccdKeys
{
    /**
     * Độ lệch giữa vị trí 0-based của người đi cùng và số {n} client dùng: 0 (guests[0]), 1 (guests[1]) hoặc 2 (guests[2]) cho người ĐẦU TIÊN cần upload.
     * $firstPosition = vị trí 0-based của người đi cùng đầu tiên PHẢI upload trong request này (các vị trí trước đã có sẵn trong hồ sơ).
     * Dùng: "guests." . ($position + $offset) . ".front".
     */
    public static function offset(Request $request, int $firstPosition = 0): int
    {
        $keys = array_values(array_filter(array_keys((array) $request->file('guests', [])), fn ($key) => is_numeric($key)));
        if ($keys === []) {
            return 0;
        }

        $offset = (int) min($keys) - $firstPosition;

        return in_array($offset, [0, 1, 2], true) ? $offset : 0;
    }
}
