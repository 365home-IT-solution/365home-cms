<?php

namespace App\Listeners;

use Illuminate\Auth\Events\Login;
use Z3d0X\FilamentLogger\Loggers\AccessLogger;

// filament-logger's AccessLogger đăng ký thẳng vào sự kiện Login TOÀN CỤC (Illuminate\Auth\Events\Login,
// bắn ra cho MỌI guard, không riêng Filament) và giả định $event->user luôn là user quản trị có cột
// "name" — vỡ với TypeError ngay khi bất kỳ guard nào KHÁC đăng nhập (VD guard "tenant" của Portal
// khách thuê, xem Modules/Minihouse/Http/Controllers/Portal/TenantAuthController) vì Tenant không có
// cột "name" (chỉ có "fullname") và không implement Filament\Models\Contracts\HasName. Mọi panel
// Filament trong app này đều dùng authGuard('web') (xem AdminPanelProvider/MinihouseAdminPanelProvider)
// nên chỉ log truy cập khi đúng guard đó, các guard khác bỏ qua an toàn.
class FilamentAccessLoginLogger
{
    public function handle(Login $event): void
    {
        if ($event->guard !== 'web') {
            return;
        }

        app(AccessLogger::class)->handle($event);
    }
}
