<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\ActivePartner;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminApiAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! ($request->user() instanceof User)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        // super_admin gửi header X-Partner-Id = chỉ làm việc trong 1 đối tác (xem
        // App\Support\ActivePartner). Tài khoản khác bỏ qua header — luôn bị khoá theo đối tác của mình.
        $partnerId = $request->header(ActivePartner::HEADER);
        if (filled($partnerId) && ! ActivePartner::apply($request->user(), (string) $partnerId)) {
            return response()->json(['message' => 'Đối tác không tồn tại.'], 422);
        }

        // Gói dịch vụ: hết hạn → 402, tính năng ngoài gói → 403 (xem App\Support\SubscriptionGate).
        if ($blocked = \App\Support\SubscriptionGate::checkRequest($request, $request->user())) {
            return $blocked;
        }

        \App\Support\ApprovalContext::reset();

        $response = $next($request);

        // Thao tác của đối tác cần 365home duyệt (bảng giá, phòng): thay đổi chưa có hiệu lực → trả kèm "approval" để app báo "đã gửi duyệt".
        if (\App\Support\ApprovalContext::hasNotices() && $response instanceof \Illuminate\Http\JsonResponse) {
            $data = $response->getData(true);

            if (is_array($data)) {
                $data['approval'] = ['submitted' => true, 'requests' => \App\Support\ApprovalContext::notices()];
                $response->setData($data);
            }
        }

        return $response;
    }
}
