<?php

namespace App\Http\Controllers;

use App\Services\Payment\Pay2SCardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Webhook thanh toán thẻ Pay2S (https://365home.vn/api/webhook/pay2s-card — URL này được gửi kèm mỗi phiên, không cần khai báo ở Pay2S).
// Xác thực chữ ký m2signature; Pay2S yêu cầu HTTP 200 + {"success":true} SAU KHI đã lưu bền vững. Sai chữ ký → 401; lỗi tạm thời → 500 để Pay2S gửi lại.
class Pay2SCardWebhookController extends Controller
{
    public function __invoke(Request $request, Pay2SCardService $card): JsonResponse
    {
        $result = $card->handleWebhook((array) $request->json()->all());

        return match ($result['result']) {
            'bad_signature' => response()->json(['success' => false, 'message' => 'Invalid signature.'], 401),
            'error' => response()->json(['success' => false], 500),
            default => response()->json(['success' => true]),
        };
    }
}
