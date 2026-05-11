<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Modules\Payment\Entities\Order;
use Modules\Payment\Entities\OrderItem;
use Modules\Payment\Http\Controllers\CheckoutController;
use Modules\Payment\Http\Controllers\OrderController;
use Modules\Payment\Http\Controllers\PaymentController;

Route::get('/success', function (Request $request) {
    $orderCode = $request->query('orderCode');

    // Kiểm tra xem orderCode có tồn tại không
    if (!$orderCode) {
        return view('payment::success', ['order' => null, 'error' => 'Không tìm thấy mã đơn hàng']);
    }
    // Tìm order theo order_code trước (vì orderCode từ PayOS là order_code)
    $order = Order::where('order_code', $orderCode)->with('items')->first();

    // Nếu không tìm thấy và orderCode là số, thử tìm theo deposit_retry_payos_code
    if (!$order && is_numeric($orderCode)) {
        $order = Order::with('items')->where('deposit_retry_payos_code', $orderCode)->first();
    }

    // Nếu không tìm thấy và orderCode là số, thử tìm theo remaining_payos_code
    if (!$order && is_numeric($orderCode)) {
        $order = Order::with('items')->where('remaining_payos_code', $orderCode)->first();
    }

    // Fallback: thử tìm theo custom ?order_code= param (do createDepositPayment / createRemainingPayment đặt vào URL)
    if (!$order) {
        $customOrderCode = $request->query('order_code');
        if ($customOrderCode) {
            $order = Order::where('order_code', $customOrderCode)->with('items')->first();
        }
    }

    // Fallback cuối: tìm theo ID
    if (!$order && is_numeric($orderCode)) {
        $order = Order::with('items')->find($orderCode);
    }

    if (!$order) {
        return view('payment::success', ['order' => null, 'error' => 'Không tìm thấy đơn hàng']);
    }

    // Gọi checkPaymentStatus để cập nhật trạng thái từ PayOS
    // Truyền orderCode để controller biết đây là thanh toán nào (remaining / deposit-retry / gốc)
    try {
        $paymentController = app(PaymentController::class);
        $paymentController->checkPaymentStatus($order, $orderCode);
        $order->refresh();
    } catch (\Exception $e) {
        Log::error('checkPaymentStatus error on success page', ['error' => $e->getMessage()]);
    }

    // Nếu là đơn cọc → redirect sang trang booking-detail để khách thanh toán còn lại
    if ($order->status === 'deposit') {
        return redirect()->route('booking.detail', ['code' => $order->order_code])
            ->with('deposit_success', true);
    }

    return view('payment::success', compact('order'));
})->name('payment.success');

Route::get('/cancel', function (Request $request) {
    $orderCode = $request->query('orderCode');

    if (!$orderCode) {
        return view('payment::cancel', ['order' => null, 'error' => 'Không tìm thấy mã đơn hàng']);
    }

    // Tìm order theo order_code trước
    $order = Order::where('order_code', $orderCode)->with('items')->first();

    // Nếu không tìm thấy và orderCode là số, thử tìm theo ID
    if (!$order && is_numeric($orderCode)) {
        $order = Order::with('items')->find($orderCode);
    }

    if (!$order) {
        $order = Order::where('order_code', 'LIKE', '%' . $orderCode . '%')
            ->with('items')
            ->first();
    }

    // Kiểm tra xem order có tồn tại không
    if (!$order) {
        return view('payment::cancel', ['order' => null, 'error' => 'Không tìm thấy đơn hàng']);
    }

    try {
        $paymentController = app(PaymentController::class);
        $paymentController->checkPaymentStatus($order);
    } catch (\Exception $e) {}

    return view('payment::cancel', compact('order'));
})->name('payment.cancel');

Route::prefix('/order')->group(function () {
    Route::get('/{id}', [OrderController::class, 'getPaymentLinkInfoOfOrder']);
    Route::put('/{id}', [OrderController::class, 'cancelPaymentLinkOfOrder']);
});

Route::post('/webhook/payos', [PaymentController::class, 'handlePayOSWebhook'])
     ->name('webhook.payos');

// Tạo link PayOS cho phần tiền còn lại (sau khi đã đặt cọc)
Route::post('/dat-phong/tao-thanh-toan-con-lai/{code}', [PaymentController::class, 'createRemainingPayment'])
     ->name('payment.remaining.create');