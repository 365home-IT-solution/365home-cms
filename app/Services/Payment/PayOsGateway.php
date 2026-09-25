<?php

namespace App\Services\Payment;

use PayOS\PayOS;
use Throwable;

// PayOS client đã chọn đúng tài khoản cho 1 đơn (xem PayOsAccountResolver::forOrder()), kèm các
// tài khoản DỰ PHÒNG để tra/huỷ/xác thực những link được tạo TRƯỚC khi chi nhánh đổi tài khoản
// (vd link cọc tạo bằng tài khoản chung, sau đó chi nhánh mới bật PayOS riêng — tra bằng tài khoản
// riêng sẽ báo "không tìm thấy"). createPaymentLink() LUÔN dùng tài khoản chính, không bao giờ rơi
// sang dự phòng — tiền phải vào đúng tài khoản đã chọn.
class PayOsGateway extends PayOS
{
    /**
     * @param  PayOS[]  $fallbacks
     */
    public function __construct(
        string $clientId,
        string $apiKey,
        string $checksumKey,
        private readonly array $fallbacks = [],
        public readonly ?int $branchAccountId = null,
    ) {
        parent::__construct($clientId, $apiKey, $checksumKey);
    }

    public function usesBranchAccount(): bool
    {
        return $this->branchAccountId !== null;
    }

    public function getPaymentLinkInformation(string|int $orderCode): array
    {
        return $this->withFallback(fn (PayOS $payOS) => $payOS === $this
            ? parent::getPaymentLinkInformation($orderCode)
            : $payOS->getPaymentLinkInformation($orderCode));
    }

    public function cancelPaymentLink(string|int $orderCode, ?string $cancellationReason = null): array
    {
        return $this->withFallback(fn (PayOS $payOS) => $payOS === $this
            ? parent::cancelPaymentLink($orderCode, $cancellationReason)
            : $payOS->cancelPaymentLink($orderCode, $cancellationReason));
    }

    public function verifyPaymentWebhookData(array $webhookBody): array
    {
        return $this->withFallback(fn (PayOS $payOS) => $payOS === $this
            ? parent::verifyPaymentWebhookData($webhookBody)
            : $payOS->verifyPaymentWebhookData($webhookBody));
    }

    private function withFallback(callable $call): array
    {
        try {
            return $call($this);
        } catch (Throwable $first) {
            foreach ($this->fallbacks as $fallback) {
                try {
                    return $call($fallback);
                } catch (Throwable) {
                }
            }

            throw $first;
        }
    }
}
