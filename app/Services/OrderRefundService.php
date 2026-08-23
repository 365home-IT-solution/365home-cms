<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Modules\Payment\Entities\Order;

/**
 * Hoàn tiền TOÀN BỘ đơn khi huỷ (đơn đã 'paid'/'deposit') — nguồn xử lý DUY NHẤT dùng chung cho CẢ
 * API (Api\Admin\OrderPaymentController::refund()) LẪN trang admin Filament
 * (OrderResource\Pages\EditOrder::getHeaderActions() — action 'refundOrder'), đảm bảo 2 nơi luôn ghi
 * đúng cùng 1 bộ dữ liệu thay vì Filament chỉ đổi status qua dropdown mà bỏ trống amount/method/reason.
 *
 * Không có API hoàn tiền thật qua PayOS (chỉ có cancelPaymentLink() cho link CHƯA thanh toán) nên
 * đây LUÔN LÀ xác nhận THỦ CÔNG — tiền được hoàn tiền mặt/chuyển khoản NGOÀI hệ thống. Khác
 * extra_refund_* (ExtraChargeService::markRefundAsDone(), chỉ dành cho phần chênh lệch khi admin sửa
 * đơn giảm giá) — đây áp dụng cho TOÀN BỘ đơn.
 *
 * Chuyển order->status sang 'refunded' — KHÔNG cần làm thêm gì khác vì OrderObserver đã tự xử lý
 * toàn bộ tác dụng phụ khi status đổi sang 'refunded': giải phóng slot phòng, trừ điểm membership đã
 * tích, gửi thông báo Telegram cho admin + FCM cho khách hàng (app/Observers/OrderObserver.php).
 */
class OrderRefundService
{
    /**
     * @throws \RuntimeException nếu đơn không ở trạng thái 'paid'/'deposit' (chưa thu tiền thật)
     */
    public function refund(Order $order, int $amount, string $method, ?string $reason, ?string $refundedBy): void
    {
        if (! in_array($order->status, ['paid', 'deposit'], true)) {
            throw new \RuntimeException('Chỉ áp dụng cho đơn đã thanh toán (đủ hoặc đặt cọc).');
        }

        $order->update([
            'status'        => 'refunded',
            'refund_amount' => $amount,
            'refund_method' => $method,
            'refund_reason' => $reason,
            'refunded_at'   => now(),
            'refunded_by'   => $refundedBy,
        ]);

        Log::info('Order refunded', [
            'order_id'   => $order->id,
            'order_code' => $order->order_code,
            'amount'     => $amount,
            'method'     => $method,
            'refunded_by' => $refundedBy,
        ]);
    }
}
