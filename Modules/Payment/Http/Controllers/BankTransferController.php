<?php

namespace Modules\Payment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Payment\SepayWebhookService;
use App\Support\VietQr;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Modules\Payment\Entities\Order;

// Trang chuyển khoản VietQR vào tài khoản đối tác (kênh SePay) — thay trang PayOS khi đối tác dùng kênh chuyển khoản, xem
// App\Services\Payment\SepayWebhookService. Chỉ mở được bằng link có chữ ký + hạn dùng (SepayWebhookService::pageUrl()), nên không
// dò được đơn của người khác bằng cách thử mã đơn. Trang không hiện thông tin khách, không cache, không index, không nhúng iframe.
class BankTransferController extends Controller
{
    private const SECURITY_HEADERS = [
        'Cache-Control'   => 'no-store, private',
        'X-Robots-Tag'    => 'noindex, nofollow',
        'Referrer-Policy' => 'no-referrer',
        'X-Frame-Options' => 'DENY',
    ];

    // GET /chuyen-khoan/{code}?expires=…&signature=…
    public function show(string $code): Response|RedirectResponse
    {
        $order = Order::where('order_code', $code)->firstOrFail();
        $info  = SepayWebhookService::transferInfo($order);

        // Không còn gì cần chuyển (đã thanh toán / đối tác không dùng kênh này / đơn hết hạn) thì về trang đơn.
        if (! $info) {
            return in_array($order->status, ['paid', 'deposit'], true)
                ? redirect()->route('payment.success', ['orderCode' => $order->order_code])
                : redirect()->route('booking.detail', ['code' => $order->order_code]);
        }

        return response()->view('payment::bank-transfer', [
            'order'     => $order,
            'info'      => $info,
            'qrImage'   => VietQr::imageDataUri($info['qr_payload']),
            'statusUrl' => SepayWebhookService::statusUrl($order),
        ])->withHeaders(self::SECURITY_HEADERS);
    }

    // GET /chuyen-khoan/{code}/trang-thai?expires=…&signature=… — trang tự hỏi lại trạng thái; chỉ trả trạng thái, không kèm dữ liệu đơn.
    public function status(string $code): JsonResponse
    {
        $status = Order::where('order_code', $code)->value('status');

        abort_unless($status, 404);

        return response()->json(['status' => $status])->withHeaders(self::SECURITY_HEADERS);
    }
}
