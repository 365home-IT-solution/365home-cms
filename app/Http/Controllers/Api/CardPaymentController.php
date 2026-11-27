<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Payment\Pay2SCardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Payment\Entities\Order;
use Modules\Payment\Entities\Pay2SCardPayment;
use RuntimeException;

// Thanh toán thẻ (Pay2S) cho khách ở chi nhánh của 365home — xem Pay2SCardService.
//   POST /api/orders/{order_code}/card-payment        (đăng nhập)   · POST /api/guest/orders/{order_code}/card-payment (khách vãng lai, phone)
//   GET  /api/orders/{order_code}/card-payment        (đăng nhập)   · GET  /api/guest/orders/{order_code}/card-payment?phone=
//   GET  /api/card-payment/options?category_id=       (công khai)   — có hiện lựa chọn thẻ ở bước đặt phòng không
// Tạo phiên → trả payment_url để app mở (webview/trình duyệt); kết quả đến bằng webhook, GET dùng để app hỏi lại (tự hỏi Pay2S nếu webhook chậm).
class CardPaymentController extends Controller
{
    public function __construct(private readonly Pay2SCardService $card)
    {
    }

    /**
     * GET /api/card-payment/options?category_id= — công khai, dùng Ở BƯỚC ĐẶT PHÒNG (chưa có đơn) để app biết có hiện lựa chọn "Thẻ quốc tế" cho chi nhánh này không.
     * Sau khi có đơn thì dùng khối card_payment trong chi tiết đơn (chính xác theo khoản đang cần thu).
     */
    public function options(Request $request): JsonResponse
    {
        $data = $request->validate(['category_id' => 'required|integer|min:1']);
        $available = $this->card->eligibleForCategory((int) $data['category_id']);

        return response()->json(['data' => $available
            ? ['available' => true, 'brands' => Pay2SCardService::BRANDS, 'min_amount' => Pay2SCardService::MIN_AMOUNT, 'sandbox' => (bool) $this->card->settings()->sandbox_only]
            : ['available' => false]]);
    }

    public function store(Request $request, string $orderCode): JsonResponse
    {
        return $this->create($request, $this->customerOrder($orderCode));
    }

    public function guestStore(Request $request, string $orderCode): JsonResponse
    {
        $request->validate(['phone' => 'required|string|max:20']);

        return $this->create($request, $this->guestOrder($request, $orderCode));
    }

    public function show(string $orderCode): JsonResponse
    {
        return $this->status($this->customerOrder($orderCode));
    }

    public function guestShow(Request $request, string $orderCode): JsonResponse
    {
        $request->validate(['phone' => 'required|string|max:20']);

        return $this->status($this->guestOrder($request, $orderCode));
    }

    private function create(Request $request, ?Order $order): JsonResponse
    {
        if (! $order) {
            return response()->json(['message' => 'Đơn hàng không tồn tại.'], 404);
        }

        $data = $request->validate([
            'card_brand' => 'nullable|string|in:' . implode(',', Pay2SCardService::BRANDS),
            'return_url' => 'nullable|url|max:500',
        ]);

        try {
            $payment = $this->card->createSession($order, $data['card_brand'] ?? 'visa', $data['return_url'] ?? null);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json(['data' => $this->payload($order, $payment)]);
    }

    private function status(?Order $order): JsonResponse
    {
        if (! $order) {
            return response()->json(['message' => 'Đơn hàng không tồn tại.'], 404);
        }

        $payment = Pay2SCardPayment::query()->where('order_id', $order->id)->latest('id')->first();

        if ($payment && $payment->status === Pay2SCardPayment::PENDING) {
            $payment = $this->card->sync($payment);
            $order->refresh();
        }

        return response()->json(['data' => $payment ? $this->payload($order, $payment) : null, 'card_payment' => $this->card->availability($order)]);
    }

    private function payload(Order $order, Pay2SCardPayment $payment): array
    {
        return [
            'order_code' => (string) $order->order_code,
            'order_status' => $order->status,
            'status' => $payment->status,
            'kind' => $payment->kind,
            'amount' => (int) $payment->amount,
            'card_brand' => $payment->card_brand,
            'payment_url' => $payment->status === Pay2SCardPayment::PENDING ? $payment->payment_url : null,
            'expires_at' => $payment->expires_at?->toIso8601String(),
            'environment' => $payment->environment,
        ];
    }

    private function customerOrder(string $orderCode): ?Order
    {
        return Order::with('items')->where('order_code', $orderCode)->where('customer_id', auth('sanctum')->id())->first();
    }

    private function guestOrder(Request $request, string $orderCode): ?Order
    {
        return Order::with('items')->where('order_code', $orderCode)->whereNull('customer_id')->where('buyer_phone', trim((string) $request->input('phone')))->first();
    }
}
