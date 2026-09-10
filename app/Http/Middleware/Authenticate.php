<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    /**
     * Get the path the user should be redirected to when they are not authenticated.
     */
    protected function redirectTo(Request $request): ?string
    {
        if ($request->expectsJson()) {
            return null;
        }

        // Portal khách thuê MiniHouse dùng guard RIÊNG "tenant" (xem config/auth.php, middleware
        // "auth:tenant" ở Modules\Minihouse\Routes\web.php) — mặc định redirectTo() LUÔN trỏ về
        // route('login') của Home dù guard thất bại là guard nào, khiến khách thuê chưa đăng nhập
        // bị đá NHẦM sang trang đăng nhập ADMIN của Home thay vì trang đăng nhập Portal của họ.
        // $request->route() đã được match XONG ở giai đoạn routing (trước middleware), tên route
        // đọc được đáng tin cậy ở đây dù model binding (nếu có) chưa resolve.
        if (str_starts_with((string) $request->route()?->getName(), 'minihouse.portal.')) {
            return route('minihouse.portal.login');
        }

        return route('login');
    }
}
