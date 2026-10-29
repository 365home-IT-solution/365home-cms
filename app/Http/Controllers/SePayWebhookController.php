<?php

namespace App\Http\Controllers;

use App\Services\Payment\SePayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Webhook SePay "tiền vào": xác thực HMAC rồi giao SePayService::handleIncoming() — cùng một bộ kiểm tra với đường kéo giao dịch từ API SePay:
// tra yêu cầu thanh toán theo mã DH{code}, đối chiếu tài khoản nhận tiền, số tiền, trạng thái đơn → tự xác nhận đơn (đủ / cọc / còn lại / phát sinh).
// Giao dịch không hợp lệ được LƯU "rejected" kèm lý do (không đổi đơn, vẫn trả 200 để SePay không gửi lại) cho Super Admin đối soát/hoàn tiền ở
// CMS → "Giao dịch SePay". Chỉ lỗi xử lý nội bộ mới trả 500 để SePay tự thử lại.
class SePayWebhookController extends Controller
{
    public function __invoke(Request $request, SePayService $sePay): JsonResponse
    {
        abort_unless($sePay->verifySignature($request), 401, 'Invalid SePay signature.');
        $payload = $request->validate([
            'id' => ['required', 'integer', 'min:0'],
            'accountNumber' => ['required', 'string'],
            'subAccount' => ['nullable', 'string'],
            'transferType' => ['required', 'in:in,out'],
            'transferAmount' => ['required', 'integer', 'min:0'],
            'transactionDate' => ['nullable', 'date_format:Y-m-d H:i:s'],
            'referenceCode' => ['nullable', 'string'],
            'code' => ['nullable', 'string'],
            'content' => ['nullable', 'string'],
        ]);

        // "Gửi thử" trên SePay luôn có id = 0 và dữ liệu giả; tiền ra không liên quan: chỉ xác nhận đã nhận, không lưu/xử lý.
        if ((int) $payload['id'] === 0 || $payload['transferType'] !== 'in') {
            return response()->json(['success' => true]);
        }

        $result = $sePay->handleIncoming($payload, $request->all());

        return $result['status'] === 'failed'
            ? response()->json(['success' => false], 500)
            : response()->json(['success' => true]);
    }
}
