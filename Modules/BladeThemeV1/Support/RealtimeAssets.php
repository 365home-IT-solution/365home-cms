<?php

namespace Modules\BladeThemeV1\Support;

// Cờ "trang này có ô khung giờ đặt phòng" — các view render ô khung giờ (livewire/book,
// livewire/product-detail, components/book/style_3) gọi need() khi render; layouts/master chỉ nạp
// echo-client.js + ws-client.js (realtime giữ chỗ/đổi giá, ~57KB JS + 2 kết nối WebSocket) khi cờ
// đã bật. Trước đây nạp cho MỌI trang trừ trang chủ — kể cả bài viết, trang chính sách... vốn
// không có ô khung giờ nào để cập nhật.
//
// Hoạt động được vì các view đó nằm trong @section('content'): Blade render section của trang con
// TRƯỚC khi render layout (@extends), nên lúc master tới phần <head> thì cờ đã có giá trị.
class RealtimeAssets
{
    private static bool $needed = false;

    public static function need(): void
    {
        self::$needed = true;
    }

    public static function needed(): bool
    {
        return self::$needed;
    }
}
