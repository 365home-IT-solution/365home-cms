<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\SubscriptionGate;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Đối tác có gói HẾT HẠN (hoặc bị huỷ/khoá) đăng nhập panel quản trị: mọi trang bị đưa về trang "Gói dịch vụ" (hiện popup hết hạn,
// chỉ còn chọn gói + chuyển khoản/QR thanh toán, gia hạn) và ẩn toàn bộ menu. Đăng xuất vẫn dùng được. Gói còn hạn → không ảnh hưởng.
// Cùng tiêu chí khoá với API (App\Support\SubscriptionGate).
class RedirectExpiredSubscription
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        $panel = Filament::getCurrentPanel();

        if (! $user || ! $panel || ! SubscriptionGate::stateFor($user)['locked']) {
            return $next($request);
        }

        $id = $panel->getId();

        // Ẩn menu điều hướng (chỉ còn trang Gói dịch vụ + menu tài khoản có Đăng xuất).
        $panel->navigation(false);

        if ($request->routeIs("filament.{$id}.pages.my-subscription", "filament.{$id}.auth.logout", 'livewire.*')) {
            return $next($request);
        }

        return redirect()->route("filament.{$id}.pages.my-subscription");
    }
}
