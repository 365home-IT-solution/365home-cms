<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\GameTesterSetting;
use App\Models\User;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Cổng chung cho các trang thử mini game (/game-tester, /tien-len-tester):
//  - Tắt ở CMS (Filament → Quản lý API → Thử mini game) ⇒ 404.
//  - Chưa đăng nhập CMS ⇒ chuyển tới trang đăng nhập CMS, đăng nhập xong quay lại đúng trang.
//  - Đăng nhập nhưng không phải Super Admin ⇒ 403.
class EnsureGameTesterAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(GameTesterSetting::enabled(), 404);

        $user = auth('web')->user();
        if (! $user) {
            return redirect()->guest(Filament::getPanel('admin')->getLoginUrl());
        }

        abort_unless($user instanceof User && $user->isSuperAdmin(), 403, 'Chỉ Super Admin được dùng trang thử mini game.');

        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
