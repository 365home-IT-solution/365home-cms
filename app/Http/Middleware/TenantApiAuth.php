<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Minihouse\App\Models\Tenant;
use Symfony\Component\HttpFoundation\Response;

// Cùng vai trò App\Http\Middleware\AdminApiAuth (đảm bảo request đã xác thực ĐÚNG loại tài khoản dự
// kiến qua Sanctum) — riêng cho API Portal khách thuê. Token Sanctum của Tenant và của
// App\Models\User dùng CHUNG 1 bảng vật lý (personal_access_tokens, đa hình) nên PHẢI tự kiểm tra
// $request->user() đúng là Tenant, không phải User lỡ dùng nhầm token admin gọi vào API khách thuê
// (hoặc ngược lại) — auth:sanctum đứng trước middleware này chỉ đảm bảo token HỢP LỆ, không đảm bảo
// hợp lệ CHO ĐÚNG API nào.
class TenantApiAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! ($request->user() instanceof Tenant)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        return $next($request);
    }
}
