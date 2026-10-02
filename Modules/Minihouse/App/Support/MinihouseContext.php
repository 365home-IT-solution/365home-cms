<?php

namespace Modules\Minihouse\App\Support;

use Filament\Facades\Filament;

// Cho biết lệnh truy vấn hiện tại đang chạy trong NGỮ CẢNH MiniHouse hay Homestay. Dùng cho Global Scope
// 'exclude_minihouse' của Category (chi nhánh): toà nhà MiniHouse mượn bảng categories nên mặc định phải bị ẩn khỏi
// MỌI nơi của Homestay (danh sách, dropdown, API, web, báo cáo...), chỉ lộ ra trong ngữ cảnh MiniHouse.
class MinihouseContext
{
    // Đặt true khi cần mô phỏng request web trong tiến trình CLI (kiểm thử).
    public static bool $simulateWeb = false;

    public static function active(): bool
    {
        // Lệnh artisan/queue/test: không lọc (migration, đồng bộ, lệnh quản trị cần thấy toàn bộ).
        if (! self::$simulateWeb && app()->runningInConsole()) {
            return true;
        }

        $panel = Filament::getCurrentPanel()?->getId();
        if ($panel !== null) {
            return $panel === 'minihouse-admin';
        }

        $request = request();
        if ($request->is('minihouse', 'minihouse/*', 'api/admin/minihouse', 'api/admin/minihouse/*', 'api/minihouse', 'api/minihouse/*')) {
            return true;
        }

        // Livewire/Filament upload... không có panel: dựa vào loại đối tác của tài khoản đang đăng nhập.
        return (bool) $request->user()?->partner?->isMinihouse();
    }
}
