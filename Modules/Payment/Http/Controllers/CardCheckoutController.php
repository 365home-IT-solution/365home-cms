<?php

namespace Modules\Payment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Payment\Pay2SCardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Payment\Entities\Order;
use Modules\Payment\Entities\Pay2SCardPayment;
use RuntimeException;

// Thanh toán thẻ (Pay2S) ngay trên trang web khách /thong-tin-dat-phong/{mã đơn} — cùng Pay2SCardService với API app (Api\CardPaymentController).
// Trang này truy cập theo mã đơn (như nút "thanh toán phần còn lại"), nên 2 route chỉ trả đúng việc cần: tạo phiên thẻ và hỏi trạng thái.
class CardCheckoutController extends Controller
{
    public function __construct(private readonly Pay2SCardService $card)
    {
    }

    // POST /dat-phong/thanh-toan-the/{code}: tạo phiên → trả paymentUrl để trình duyệt chuyển sang trang nhập thẻ của Pay2S.
    public function store(Request $request, string $code): JsonResponse
    {
        $order = Order::with('items')->where('order_code', $code)->first();

        if (! $order) {
            return response()->json(['error' => 1, 'message' => 'Không tìm thấy đơn hàng.'], 404);
        }

        $data = $request->validate(['card_brand' => 'required|string|in:' . implode(',', Pay2SCardService::BRANDS)]);

        try {
            $payment = $this->card->createSession($order, $data['card_brand'], route('booking.detail', ['code' => $order->order_code]) . '?card_return=1');
        } catch (ValidationException $e) {
            return response()->json(['error' => 1, 'message' => collect($e->errors())->flatten()->first() ?? 'Đơn chưa hỗ trợ thanh toán bằng thẻ.'], 422);
        } catch (RuntimeException $e) {
            return response()->json(['error' => 1, 'message' => $e->getMessage()], 502);
        }

        return response()->json(['error' => 0, 'paymentUrl' => $payment->payment_url, 'expiresAt' => $payment->expires_at?->toIso8601String()]);
    }

    // GET /dat-phong/thanh-toan-the/{code}/trang-thai: trang gọi sau khi khách quay lại từ Pay2S (webhook chậm/không tới được máy local thì tự hỏi Pay2S).
    public function status(string $code): JsonResponse
    {
        $order = Order::where('order_code', $code)->first();

        if (! $order) {
            return response()->json(['error' => 1, 'message' => 'Không tìm thấy đơn hàng.'], 404);
        }

        $payment = Pay2SCardPayment::query()->where('order_id', $order->id)->latest('id')->first();

        if ($payment && $payment->status === Pay2SCardPayment::PENDING) {
            $payment = $this->card->sync($payment);
            $order->refresh();
        }

        return response()->json([
            'error' => 0,
            'status' => $payment?->status,           // pending | paid | failed | cancelled | expired | review | null (chưa có phiên)
            'orderStatus' => $order->status,
        ]);
    }
}
