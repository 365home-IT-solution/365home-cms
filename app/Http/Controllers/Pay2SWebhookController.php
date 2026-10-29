<?php

namespace App\Http\Controllers;

use App\Services\Payment\Pay2SService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Webhook Pay2S "biến động số dư": xác thực token Bearer của webhook rồi giao Pay2SService → SePayService::handleIncoming() (cùng bộ kiểm tra với SePay).
// Giao dịch không hợp lệ được LƯU "rejected" kèm lý do (không đổi đơn, vẫn trả 200 để Pay2S không gửi lại) cho Super Admin đối soát ở CMS → "Giao dịch SePay / Pay2S".
// Chỉ lỗi xử lý nội bộ mới trả 500 để Pay2S gửi lại (tối đa 5 lần cách nhau 60 giây; sau đó lệnh sepay:sync kéo bù qua API).
class Pay2SWebhookController extends Controller
{
    public function __invoke(Request $request, Pay2SService $pay2s): JsonResponse
    {
        abort_unless($pay2s->verifyToken($request), 401, 'Invalid Pay2S token.');

        $data = $request->validate(['transactions' => ['required', 'array']]);
        $result = $pay2s->handleWebhook($data['transactions']);

        return $result['failed'] > 0
            ? response()->json(['success' => false], 500)
            : response()->json(['success' => true]);
    }
}
