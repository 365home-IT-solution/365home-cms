<?php

namespace App\Http\Middleware;

use App\Models\Customer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCustomerIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Trước đây chỉ kiểm tra status KHI $user là Customer — nếu request lỡ dùng token của
        // App\Models\User (admin, khác bảng/model hoàn toàn) thì điều kiện instanceof false, middleware
        // coi như "không có gì để chặn" rồi cho qua luôn, khiến token admin lọt được vào mọi API dành
        // riêng cho khách hàng (route này CHỈ gắn cho các route customer, xem routes/api.php) — code
        // phía sau giả định $request->user() luôn là Customer nên crash (TypeError) ở nơi nào đó thay
        // vì bị chặn sớm, gọn ở đây với 401 rõ ràng.
        if (! $user instanceof Customer) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($user->status === Customer::STATUS_INACTIVE) {
            return response()->json([
                'message' => 'Tài khoản của bạn đã bị vô hiệu hóa. Vui lòng liên hệ hỗ trợ để được kích hoạt lại.',
            ], 403);
        }

        return $next($request);
    }
}
