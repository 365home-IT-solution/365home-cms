<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Xác thực đơn giản bằng 1 token cố định cho app Flutter "Đọc thẻ TTLock" — KHÔNG dùng tài khoản
// đăng nhập panel (app này chạy độc lập trên điện thoại, không có phiên đăng nhập Filament). Token
// nhúng sẵn trong app lúc build, so khớp với services.ttlock.card_app_token (env
// TTLOCK_CARD_APP_TOKEN) — đủ dùng vì app chỉ nội bộ, không phát hành công khai.
class AuthorizeTtlockCardApp
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('services.ttlock.card_app_token');
        $given = $request->header('X-Card-App-Token');

        if (! $expected || ! $given || ! hash_equals($expected, $given)) {
            abort(401, 'Unauthorized');
        }

        return $next($request);
    }
}
