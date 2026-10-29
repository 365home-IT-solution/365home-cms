<?php

namespace App\Services\Payment;

use Carbon\Carbon;
use LogicException;
use Modules\Payment\Entities\Order;
use Modules\Payment\Entities\PartnerSePayAccount;
use Modules\Payment\Entities\SePayPaymentLink;

// "Cổng" SePay có cùng giao diện PayOsGateway để MỌI luồng đang gọi PayOsAccountResolver::forOrder()->createPaymentLink()/
// getPaymentLinkInformation()/cancelPaymentLink() tự chạy qua SePay mà không phải sửa từng chỗ:
//   - createPaymentLink: lưu yêu cầu thanh toán (mã, số tiền, hạn) và trả checkoutUrl = trang QR công khai của 365home;
//   - getPaymentLinkInformation: đọc trạng thái từ DB (webhook SePay đã đánh dấu paid);
//   - cancelPaymentLink: huỷ yêu cầu chưa trả (tiền chuyển nhầm vào mã đã huỷ bị webhook từ chối, ghi để đối soát).
// Tiền luôn về tài khoản ngân hàng của đối tác nên collectedBy() = 'partner'.
class SePayGateway extends PayOsGateway
{
    public function __construct(
        private readonly Order $order,
        private readonly PartnerSePayAccount $account,
        private readonly SePayService $sePay,
    ) {
        parent::__construct('sepay', 'sepay', 'sepay', [], null, (string) $account->partner_id);
    }

    public function isSePay(): bool
    {
        return true;
    }

    public function collectedBy(): string
    {
        return 'partner';
    }

    public function createPaymentLink(array $paymentData): array
    {
        $expiresAt = isset($paymentData['expiredAt']) ? Carbon::createFromTimestamp((int) $paymentData['expiredAt']) : null;
        $link = $this->sePay->createLink($this->order, (int) ($paymentData['orderCode'] ?? 0), (int) ($paymentData['amount'] ?? 0), $expiresAt);

        return [
            'checkoutUrl' => route('sepay.checkout', ['code' => $link->code]),
            // PayOS trả chuỗi QR (EMV); SePay dùng ảnh QR nên để null — app dùng sepay.qr_url hoặc mở checkoutUrl.
            'qrCode' => null,
            // Trả đúng mã luồng đặt phòng đã truyền vào (như PayOS) để nơi gọi lưu vào remaining_payos_code... ; mã khách chuyển khoản là $link->code (ngắn).
            'orderCode' => (int) $link->ref_code,
            'amount' => (int) $link->amount,
            'status' => 'PENDING',
            'paymentLinkId' => 'sepay-' . $link->id,
            'sepay' => $this->sePay->payloadFor($link, $this->account),
        ];
    }

    public function getPaymentLinkInformation(string|int $orderCode): array
    {
        $link = $this->find($orderCode);

        if (! $link) {
            return ['orderCode' => (int) $orderCode, 'amount' => 0, 'amountPaid' => 0, 'status' => 'PENDING'];
        }

        $status = match (true) {
            $link->status === SePayPaymentLink::PAID => 'PAID',
            $link->status === SePayPaymentLink::CANCELLED => 'CANCELLED',
            $link->expires_at !== null && $link->expires_at->isPast() => 'EXPIRED',
            default => 'PENDING',
        };

        return [
            'orderCode' => (int) $link->ref_code, 'amount' => (int) $link->amount, 'status' => $status,
            'amountPaid' => $status === 'PAID' ? (int) $link->amount : 0,
        ];
    }

    public function cancelPaymentLink(string|int $orderCode, ?string $cancellationReason = null): array
    {
        SePayPaymentLink::query()->where('ref_code', (int) $orderCode)->where('order_id', $this->order->id)
            ->where('status', SePayPaymentLink::PENDING)->update(['status' => SePayPaymentLink::CANCELLED]);

        return ['orderCode' => (int) $orderCode, 'status' => 'CANCELLED'];
    }

    /** Yêu cầu thanh toán của đơn theo mã luồng đặt phòng (ref_code) — mới nhất trước. */
    private function find(string|int $orderCode): ?SePayPaymentLink
    {
        return SePayPaymentLink::query()->where('order_id', $this->order->id)->where('ref_code', (int) $orderCode)->latest('id')->first();
    }

    public function verifyPaymentWebhookData(array $webhookBody): array
    {
        throw new LogicException('Webhook SePay được xác thực riêng (HMAC) ở SePayWebhookController.');
    }
}
