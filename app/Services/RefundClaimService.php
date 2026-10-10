<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PartnerRefundClaim as Claim;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Payment\Entities\Order;

/**
 * YÊU CẦU HOÀN TIỀN KHÁCH khi tiền của đơn đang nằm ở đối tác (đơn về thẳng đối tác hoặc thu tại quầy — luồng tiền mới).
 *
 *   mở yêu cầu → đối tác có settlement.refund_grace_hours (24 giờ) để hoàn khách
 *      ├─ đối tác hoàn (OrderRefundService::refund) → claim "refunded_by_partner"
 *      └─ quá hạn → claim "overdue", báo đối tác + Super Admin → Super Admin hoàn thay (refundOnBehalf): đơn chuyển "refunded" với refund_paid_by = platform
 *         và OrderRefundService tự lập đề xuất trừ ký quỹ khoản đã ứng (trường hợp "refund_not_returned").
 * Đơn 365home đã thu hộ (collected_by = platform) không cần luồng này: tiền đang ở 365home, kỳ đối soát tự bù trừ.
 */
class RefundClaimService
{
    public function __construct(
        private OrderCommissionService $commission,
        private PartnerNotifier $notifier,
        private OrderRefundService $refunds,
        private EscrowService $escrow,
    ) {}

    public function open(Order $order, int $amount, string $reason, User $by): Claim
    {
        $partner = $this->commission->partnerOf($order);

        if (! $partner || ! $this->commission->applies($order, $partner) || ! $partner->usesDirectPayment()) {
            throw new \DomainException('Đơn này không thuộc luồng tiền mới của đối tác Homestay.');
        }
        if (! in_array($order->collected_by, [OrderCommissionService::COLLECTED_PARTNER, OrderCommissionService::COLLECTED_CASH], true)) {
            throw new \DomainException('Tiền của đơn đang ở 365home (thu hộ) — 365home hoàn trực tiếp, không qua yêu cầu hoàn của đối tác.');
        }
        if (! in_array($order->status, ['paid', 'deposit'], true)) {
            throw new \DomainException('Chỉ tạo yêu cầu hoàn cho đơn đã thanh toán (đủ hoặc đặt cọc).');
        }
        if ($amount < 1 || $amount > $this->commission->paidAmount($order)) {
            throw new \DomainException('Số tiền hoàn phải từ 1đ đến số tiền khách đã trả (' . $this->money($this->commission->paidAmount($order)) . ').');
        }
        if (Claim::query()->where('order_id', $order->id)->whereIn('status', Claim::ACTIVE)->exists()) {
            throw new \DomainException('Đơn này đang có yêu cầu hoàn tiền chưa xử lý.');
        }

        $claim = Claim::create([
            'partner_id' => $partner->id, 'order_id' => $order->id, 'order_code' => $order->order_code, 'amount' => $amount, 'reason' => $reason,
            'status' => Claim::STATUS_OPEN, 'requested_at' => now(), 'due_at' => now()->addHours((int) config('settlement.refund_grace_hours', 24)),
            'requested_by' => $by->id,
        ]);

        $this->notifier->send($partner, 'refund_claim_opened', 'Khách cần được hoàn tiền', "Đơn #{$order->order_code}: hoàn {$this->money($amount)} cho khách trước {$claim->due_at->format('d/m/Y H:i')}. "
            . 'Quá hạn mà chưa hoàn, 365home sẽ hoàn thay cho khách và trừ khoản đã ứng vào ký quỹ. Lý do: ' . $reason, ['claim_id' => $claim->id, 'order_code' => $order->order_code]);

        return $claim;
    }

    /**
     * Gọi từ OrderRefundService::refund(): đơn đã được hoàn thì đóng yêu cầu đang mở, ghi lại ai hoàn / hình thức / số tiền.
     * Đối tác tự báo đã hoàn thì Super Admin được thông báo để theo dõi.
     */
    public function closeForRefund(Order $order, bool $byPlatform, ?User $actor = null, ?string $method = null, ?int $amount = null, ?string $note = null): void
    {
        $claims = Claim::query()->where('order_id', $order->id)->whereIn('status', Claim::ACTIVE)->get();

        foreach ($claims as $claim) {
            $claim->update([
                'status' => $byPlatform ? Claim::STATUS_REFUNDED_BY_PLATFORM : Claim::STATUS_REFUNDED_BY_PARTNER,
                'resolved_at' => now(), 'resolved_by' => $actor?->id ?? $claim->resolved_by,
                'refund_method' => $method, 'refunded_amount' => $amount, 'refund_note' => $note !== null ? mb_substr($note, 0, 500) : null,
            ]);

            if (! $byPlatform && ($partner = $claim->partner)) {
                $this->escrow->notifySuperAdmins('refund_claim_refunded', 'Đối tác đã hoàn tiền cho khách', ($partner->legal_name ?: $partner->name)
                    . ": đơn #{$claim->order_code}, {$this->money((int) ($amount ?? $claim->amount))} (" . (Claim::REFUND_METHODS[$method] ?? 'không rõ hình thức') . ')'
                    . ($actor ? " — do {$actor->fullname} ghi nhận." : '.'), $partner, ['claim_id' => $claim->id, 'order_code' => $claim->order_code]);
            }
        }
    }

    /**
     * Đối tác báo ĐÃ hoàn tiền cho khách (tiền mặt hoặc chuyển khoản ngoài hệ thống): đơn chuyển "đã hoàn" với đúng số tiền của yêu cầu,
     * yêu cầu chuyển "đối tác đã hoàn", không trừ ký quỹ. Dùng chung cho API và nút "Đã hoàn tiền cho khách" trên web.
     *
     * @throws \DomainException|\RuntimeException
     */
    public function partnerRefund(Claim $claim, string $method, ?string $note, User $by): Claim
    {
        if ($by->isSuperAdmin() || $by->belongsToPlatformPartner()) {
            throw new \DomainException('365home không báo hoàn thay đối tác ở đây — dùng "Hoàn thay & trừ ký quỹ" khi yêu cầu đã quá hạn.');
        }
        if ($by->partner_id !== $claim->partner_id) {
            throw new \DomainException('Yêu cầu này không thuộc đối tác của bạn.');
        }
        if (! in_array($claim->status, Claim::ACTIVE, true)) {
            throw new \DomainException('Yêu cầu này đã được xử lý.');
        }

        DB::transaction(function () use ($claim, $method, $note, $by) {
            $order = Order::withoutGlobalScopes()->findOrFail($claim->order_id);
            $this->refunds->refund($order, (int) $claim->amount, $method, 'Hoàn theo yêu cầu #' . $claim->id . ': ' . $claim->reason . ($note ? " — Ghi chú: {$note}" : ''), (string) $by->id, $note);
        });

        return $claim->fresh();
    }

    public function cancel(Claim $claim, User $by): Claim
    {
        if (! in_array($claim->status, Claim::ACTIVE, true)) {
            throw new \DomainException('Yêu cầu này đã được xử lý.');
        }

        $claim->update(['status' => Claim::STATUS_CANCELLED, 'resolved_at' => now(), 'resolved_by' => $by->id]);

        return $claim->fresh();
    }

    /** Chạy định kỳ (escrow:process): yêu cầu quá hạn → "overdue", báo đối tác và Super Admin (mỗi yêu cầu một lần). @return int số yêu cầu mới quá hạn */
    public function escalateOverdue(): int
    {
        $count = 0;

        Claim::query()->where('status', Claim::STATUS_OPEN)->where('due_at', '<', now())->get()->each(function (Claim $claim) use (&$count) {
            $claim->update(['status' => Claim::STATUS_OVERDUE, 'overdue_notified_at' => now()]);
            $partner = $claim->partner;

            $this->notifier->send($partner, 'refund_claim_overdue', 'Quá hạn hoàn tiền cho khách', "Đơn #{$claim->order_code}: chưa hoàn {$this->money($claim->amount)} cho khách trong hạn. 365home có thể hoàn thay và trừ vào ký quỹ của bạn.",
                ['claim_id' => $claim->id, 'order_code' => $claim->order_code]);
            $this->escrow->notifySuperAdmins('refund_claim_overdue', 'Đối tác chưa hoàn tiền khách quá hạn', ($partner->legal_name ?: $partner->name) . ": đơn #{$claim->order_code}, {$this->money($claim->amount)} — có thể hoàn thay.", $partner, ['claim_id' => $claim->id]);
            $count++;
        });

        return $count;
    }

    /** Super Admin hoàn thay cho khách sau khi quá hạn: ghi hoàn tiền (thủ công, ngoài hệ thống) và tự lập đề xuất trừ ký quỹ khoản đã ứng. */
    public function refundOnBehalf(Claim $claim, string $method, User $by): Claim
    {
        if ($claim->status !== Claim::STATUS_OVERDUE) {
            throw new \DomainException($claim->status === Claim::STATUS_OPEN
                ? 'Chưa quá hạn hoàn tiền (còn đến ' . $claim->due_at->format('d/m/Y H:i') . ') — đối tác còn thời gian tự hoàn.'
                : 'Yêu cầu này đã được xử lý.');
        }

        DB::transaction(function () use ($claim, $method, $by) {
            $order = Order::withoutGlobalScopes()->findOrFail($claim->order_id);
            $this->refunds->refund($order, (int) $claim->amount, $method, 'Hoàn thay đối tác quá hạn: ' . $claim->reason, (string) $by->id);
            $claim->update(['resolved_by' => $by->id]);
        });

        return $claim->fresh('resolver');
    }

    private function money(int $amount): string
    {
        return number_format($amount, 0, ',', '.') . 'đ';
    }
}
