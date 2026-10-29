<?php

namespace Modules\Payment\Http\Controllers;

use App\Services\Payment\SePayService;
use Illuminate\Routing\Controller;
use Modules\Payment\Entities\Order;
use Modules\Payment\Entities\SePayPaymentLink;

// Trang QR thanh toán SePay (checkoutUrl của cổng SePay): hiện QR + thông tin chuyển khoản, tự hỏi trạng thái và báo thành công khi webhook đã xác nhận.
class SePayCheckoutController extends Controller
{
    public function show(int $code, SePayService $sePay)
    {
        $link = SePayPaymentLink::query()->where('code', $code)->firstOrFail();
        $order = Order::withoutGlobalScopes()->findOrFail($link->order_id);

        return view('payment::sepay-checkout', [
            'payment' => $sePay->payloadFor($link) + ['order_status' => $order->status],
            'statusUrl' => route('api.sepay.payments.show', ['code' => $link->code]),
            'detailUrl' => route('booking.detail', ['code' => $order->order_code]),
        ]);
    }
}
