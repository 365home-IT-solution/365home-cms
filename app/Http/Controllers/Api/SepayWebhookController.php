<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Payment\SepayWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Payment\Entities\Order;

// Webhook SePay (tiền vào tài khoản ngân hàng của đối tác) + thông tin VietQR cho khách — xem App\Services\Payment\SepayWebhookService.
class SepayWebhookController extends Controller
{
    public function __construct(private SepayWebhookService $sepay) {}

    // POST /api/webhook/sepay — công khai, xác thực bằng header "Authorization: Apikey <khoá>".
    // SePay coi HTTP 2xx + {"success": true} là đã nhận; lỗi khác thì tự gửi lại (tối đa 7 lần) — nên chỉ trả 500 khi ghi nhận lỗi
    // thật (để được gửi lại), còn giao dịch không khớp đơn vẫn trả 200 vì đã lưu lại để đối soát.
    public function handle(Request $request): JsonResponse
    {
        if (! SepayWebhookService::ipAllowed($request->ip())) {
            Log::warning('SePay webhook: IP không nằm trong danh sách cho phép', ['ip' => $request->ip()]);

            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $auth = $this->sepay->authenticate($request->header('Authorization'));

        if (! $auth) {
            Log::warning('SePay webhook: sai khoá API', ['ip' => $request->ip(), 'id' => $request->input('id')]);

            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        try {
            $txn = $this->sepay->handle($request->all(), $auth['channel']);
        } catch (\Throwable $e) {
            Log::error('SePay webhook: lỗi xử lý', ['id' => $request->input('id'), 'error' => $e->getMessage()]);

            return response()->json(['success' => false, 'message' => 'Internal error'], 500);
        }

        return response()->json(['success' => true, 'status' => $txn?->status ?? 'ignored'], 200);
    }

    // GET /api/payment/bank-transfer/{order_code}[?phone=…] — thông tin chuyển khoản cho khoản cần thu tiếp theo của đơn, kèm link trang
    // chuyển khoản (có chữ ký). Chỉ chủ đơn: khách đăng nhập sở hữu đơn, hoặc khách vãng lai gửi đúng SĐT đặt phòng (như /api/guest/orders).
    // Không phải chủ đơn → 404 như đơn không tồn tại, không để dò mã đơn. available=false: đối tác không dùng kênh chuyển khoản
    // (app dùng PayOS như cũ) hoặc đơn không còn gì cần thu.
    public function transferInfo(Request $request, string $orderCode): JsonResponse
    {
        $request->validate(['phone' => ['nullable', 'string', 'max:20']]);

        $order = Order::withoutGlobalScopes()->where('order_code', $orderCode)->first();

        abort_unless($order && $this->ownsOrder($request, $order), 404, 'Đơn hàng không tồn tại.');

        $info = SepayWebhookService::transferInfo($order);

        return response()->json([
            'available' => $info !== null,
            'status'    => $order->status,
            'data'      => $info ? $info + ['page_url' => SepayWebhookService::pageUrl($order)] : null,
        ])->header('Cache-Control', 'no-store, private');
    }

    private function ownsOrder(Request $request, Order $order): bool
    {
        $customer = auth('sanctum')->user();

        if ($customer instanceof \App\Models\Customer && $order->customer_id !== null && (int) $order->customer_id === (int) $customer->id) {
            return true;
        }

        $phone = trim((string) $request->query('phone'));

        return $phone !== '' && filled($order->buyer_phone) && hash_equals(trim((string) $order->buyer_phone), $phone);
    }
}
