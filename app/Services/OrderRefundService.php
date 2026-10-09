<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PartnerEscrowEntry;
use App\Models\User;
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

        $actor = $refundedBy ? User::find($refundedBy) : null;
        $paidByPlatform = $this->refundedByPlatformForPartner($order, $actor);

        $order->update([
            'status'         => 'refunded',
            'refund_amount'  => $amount,
            'refund_method'  => $method,
            'refund_reason'  => $reason,
            'refunded_at'    => now(),
            'refunded_by'    => $refundedBy,
            'refund_paid_by' => $paidByPlatform ? 'platform' : 'partner',
        ]);

        // Đóng yêu cầu hoàn tiền đang mở của đơn (nếu có) — đối tác tự hoàn hay 365home hoàn thay.
        app(RefundClaimService::class)->closeForRefund($order, $paidByPlatform);
        try {
            app(OrderCommissionService::class)->flagRebookAfterRefund($order->fresh());
        } catch (\Throwable $e) {
            report($e);
        }

        // 365home hoàn tiền cho khách THAY đối tác (tiền đang nằm ở đối tác) → đề xuất trừ ký quỹ khoản đã ứng.
        if ($paidByPlatform && $amount > 0) {
            $this->proposeEscrowDeduction($order, $amount, $reason, $actor);
        }

        Log::info('Order refunded', [
            'order_id'   => $order->id,
            'order_code' => $order->order_code,
            'amount'     => $amount,
            'method'     => $method,
            'refunded_by' => $refundedBy,
        ]);
    }

    /**
     * Người hoàn là Super Admin / nhân viên đối tác nền tảng (365home) trong khi tiền của đơn đang nằm ở đối tác
     * (về thẳng đối tác hoặc thu tại quầy) = 365home ứng tiền hoàn thay đối tác. Đơn 365home đã thu hộ thì tiền nằm
     * ở 365home nên không phải khoản ứng — kỳ đối soát tự bù trừ.
     */
    private function refundedByPlatformForPartner(Order $order, ?User $actor): bool
    {
        if (! $actor || ! in_array($order->collected_by, [OrderCommissionService::COLLECTED_PARTNER, OrderCommissionService::COLLECTED_CASH], true)) {
            return false;
        }

        $commission = app(OrderCommissionService::class);
        $partner = $commission->partnerOf($order);

        return $partner !== null && $commission->applies($order, $partner) && ($actor->isSuperAdmin() || $actor->belongsToPlatformPartner());
    }

    private function proposeEscrowDeduction(Order $order, int $amount, ?string $reason, ?User $actor): void
    {
        try {
            app(EscrowService::class)->proposeDeduction(app(OrderCommissionService::class)->partnerOf($order), [
                'type'       => PartnerEscrowEntry::TYPE_DEDUCT_REFUND,
                'amount'     => $amount,
                'reason'     => "365home hoàn tiền khách đơn #{$order->order_code} thay đối tác" . ($reason ? ": {$reason}" : '.'),
                'order_code' => $order->order_code,
                'case_code'  => 'refund_not_returned',
            ], $actor);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
