<?php

declare(strict_types=1);

namespace App\Support;

// Đánh dấu request hiện tại có thực sự đang chạy trong admin panel Filament hay không.
// Không dùng Filament::getCurrentPanel() vì panel 'admin' được đăng ký ->default(), nên
// FilamentManager tự gán currentPanel = panel admin ngay khi service 'filament' được resolve
// LẦN ĐẦU trong request — bất kể request đó có liên quan gì tới panel hay không (đã kiểm chứng:
// một request tới trang client bất kỳ vẫn trả về getCurrentPanel()->getId() === 'admin'). Cờ này
// chỉ được bật bởi MarkAdminPanelContext middleware, middleware đó chỉ chạy thật sự (hoặc được
// Livewire persistent-middleware replay lại đúng cách trên các request /livewire/update) khi
// route đang xử lý thuộc panel admin.
class AdminPanelContext
{
    private static bool $active = false;

    public static function markActive(): void
    {
        self::$active = true;
    }

    public static function isActive(): bool
    {
        return self::$active;
    }
}
