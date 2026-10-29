<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Payment\SePayService;
use Illuminate\Http\JsonResponse;
use Modules\Payment\Entities\Order;
use Modules\Payment\Entities\SePayPaymentLink;

// Dữ liệu thanh toán SePay công khai cho app / trang QR: số tiền, nội dung chuyển khoản, ngân hàng, ảnh QR, trạng thái.
// Không trả gì ngoài thông tin khách cần để chuyển khoản. Mỗi lần hỏi khi yêu cầu còn chờ, hệ thống tự hỏi SePay (nếu có SEPAY_API_TOKEN) nên đơn
// được xác nhận ngay sau khi khách chuyển dù webhook chậm — xem SePayService::pull().
class SePayPaymentController extends Controller
{
    // GET /api/public/sepay/orders/{code}/payment — yêu cầu thanh toán đang chờ MỚI NHẤT của đơn (cọc, phần còn lại hoặc phát sinh).
    public function show(string $code, SePayService $sePay): JsonResponse
    {
        $order = Order::withoutGlobalScopes()->where('order_code', $code)->firstOrFail();

        $link = SePayPaymentLink::query()->where('order_id', $order->id)->where('status', SePayPaymentLink::PENDING)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->latest('id')->first();

        abort_unless($link, 404, 'Đơn chưa có yêu cầu thanh toán SePay đang chờ.');

        $sePay->pull($link);

        return response()->json(['data' => $sePay->payloadFor($link->fresh()) + ['order_status' => $order->fresh()->status]]);
    }

    // GET /api/public/sepay/payments/{code} — 1 yêu cầu thanh toán theo mã (trang QR dùng để hiển thị và hỏi trạng thái).
    public function link(int $code, SePayService $sePay): JsonResponse
    {
        $link = SePayPaymentLink::query()->where('code', $code)->firstOrFail();

        if ($link->status === SePayPaymentLink::PENDING) {
            $sePay->pull($link);
            $link = $link->fresh();
        }

        $order = Order::withoutGlobalScopes()->findOrFail($link->order_id);

        return response()->json(['data' => $sePay->payloadFor($link) + ['order_status' => $order->status]]);
    }
}
