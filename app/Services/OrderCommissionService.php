<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Partner;
use App\Services\Payment\PayOsAccountResolver;
use Modules\Payment\Entities\Order;
use Modules\Promotion\App\Models\Coupon;

/**
 * HOA HỒNG TRÊN TỪNG ĐƠN của đối tác Homestay (tiền đặt phòng về thẳng đối tác — xem SettlementService).
 *
 * Hai mốc:
 *  1. Lúc tạo đơn (snapshotOnCreate): chụp collected_by (tiền về đâu), commission_rate (đổi hợp đồng sau không làm sai
 *     đơn cũ) và tách từng khoản giảm kèm người chịu vào orders.discounts.
 *  2. Lúc đơn hoàn thành — đã trả phòng, hoặc bị huỷ/hoàn mà đối tác vẫn giữ một phần tiền (finalize): chốt
 *     commission_amount và platform_subsidy.
 *
 * Công thức (K = khách trả về đối tác, S = khoản giảm do 365home chịu):
 *   D = K + S                 (doanh thu tính hoa hồng = giá sau giảm của đối tác)
 *   C = D × commission_rate   (hoa hồng)
 *   đối tác nộp 365home C − S (âm = 365home chi lại), đối tác thực nhận D × (1 − tỉ lệ)
 * Khuyến mãi do đối tác chịu giảm thẳng vào D; khuyến mãi do 365home chịu được 365home bù đủ (không bao giờ trừ vào
 * ký quỹ).
 *
 * MiniHouse và đối tác nền tảng (365home tự bán) không áp dụng.
 */
class OrderCommissionService
{
    public const COLLECTED_PARTNER = 'partner';
    public const COLLECTED_PLATFORM = 'platform';
    public const COLLECTED_CASH = 'cash';

    public const COLLECTED_BY = [
        self::COLLECTED_PARTNER  => 'Về thẳng đối tác',
        self::COLLECTED_PLATFORM => '365home thu hộ',
        self::COLLECTED_CASH     => 'Tại quầy',
    ];

    public function partnerOf(Order $order): ?Partner
    {
        $partnerId = $order->partner_id ?: PayOsAccountResolver::partnerIdForOrder($order);

        return $partnerId ? Partner::withTrashed()->find($partnerId) : null;
    }

    public function applies(Order $order, ?Partner $partner = null): bool
    {
        $partner ??= $this->partnerOf($order);

        return $partner !== null && ! $partner->isMinihouse() && ! $partner->isPlatformPartner() && ! $partner->isSystemPartner();
    }

    // ───────────────────────── Mốc 1: tạo đơn ─────────────────────────

    public function resolveCollectedBy(Order $order): string
    {
        if ($order->payment_method === 'cod') {
            return self::COLLECTED_CASH;
        }

        $categoryId = $order->category_id ? (int) $order->category_id : null;
        $partnerId = PayOsAccountResolver::partnerIdForOrder($order);

        return PayOsAccountResolver::branchAccountFor($categoryId) || PayOsAccountResolver::partnerAccountFor($partnerId)
            ? self::COLLECTED_PARTNER
            : self::COLLECTED_PLATFORM;
    }

    public function commissionRateOf(Partner $partner): float
    {
        return app(PartnerContractWorkflowService::class)->commissionValue($partner->commission_rate)
            ?? (float) config('partner_flow.default_commission_rate', 20);
    }

    /** Chụp collected_by + tỉ lệ + từng khoản giảm kèm người chịu. Gọi từ OrderObserver::created(). */
    public function snapshotOnCreate(Order $order): void
    {
        $partner = $this->partnerOf($order);

        // Chỉ đơn tạo SAU KHI luồng tiền mới có hiệu lực (hợp đồng mẫu mới/phụ lục đã ký) mới vào đối soát: đơn trước đó vẫn 365home thu hộ và
        // chi cho đối tác theo cách cũ (ngoài hệ thống) — đưa vào kỳ đối soát sẽ trả tiền hai lần.
        if (! $this->applies($order, $partner) || ! $partner->usesDirectPayment()) {
            return;
        }

        // Đơn TẠO trong thời gian miễn phí tháng đầu: hoa hồng 0 và khoản giảm do 365home phát hành do đối tác chịu (xem discountLines) — bất kể đơn trả phòng sau đó.
        $waived = $partner->isInFeeFreePeriod();
        $order->commission_waived = $waived;

        $order->forceFill([
            'collected_by'      => $this->resolveCollectedBy($order),
            'commission_rate'   => $waived ? 0 : $this->commissionRateOf($partner),
            'commission_waived' => $waived,
            'discounts'         => $this->discountLines($order),
        ])->saveQuietly();
    }

    /**
     * Các dòng giảm giá của đơn: dòng giảm của phòng (promotion/system — do đối tác chịu) đã được nơi tạo đơn ghi sẵn
     * vào orders.discounts được giữ nguyên; dòng mã giảm giá dựng lại từ coupon_codes theo người chịu của mã LÚC ĐẶT.
     *
     * @return array<int, array<string, mixed>>
     */
    public function discountLines(Order $order): array
    {
        $kept = collect($order->discounts ?? [])->reject(fn ($line) => ($line['source'] ?? null) === 'coupon')->values()->all();
        // Người chịu của mỗi mã được CHỤP lúc đặt: sửa/xoá mã sau đó không làm đổi đơn cũ — chỉ số tiền giảm được cập nhật theo đơn (khi đơn được sửa giá).
        $snapshot = collect($order->discounts ?? [])->where('source', 'coupon')->keyBy('code');

        $codes = collect($order->coupon_codes ?: array_filter([$order->coupon_code]))->filter()->values();
        $amounts = (array) ($order->coupon_discount_amounts ?? []);
        $coupons = $codes->isEmpty() ? collect() : Coupon::withoutGlobalScopes()->whereIn('code', $codes->all())->get()->keyBy('code');

        $lines = $codes->map(function (string $code) use ($amounts, $coupons, $snapshot) {
            $amount = max(0, (int) round((float) ($amounts[$code] ?? 0)));
            $coupon = $coupons->get($code);
            $frozen = $snapshot->get($code);
            $fundedBy = $frozen['funded_by'] ?? $coupon?->funded_by ?: Coupon::FUNDED_PARTNER;
            $share = $frozen ? ($frozen['partner_share_pct'] ?? null) : $coupon?->partner_share_pct;

            $platformAmount = match ($fundedBy) {
                Coupon::FUNDED_PLATFORM => $amount,
                Coupon::FUNDED_SHARED   => $amount - (int) round($amount * min(100, max(0, (int) $share)) / 100),
                default                 => 0,
            };

            return [
                'source'            => 'coupon',
                'code'              => $code,
                'amount'            => $amount,
                'funded_by'         => $fundedBy,
                'partner_share_pct' => $fundedBy === Coupon::FUNDED_SHARED ? (int) $share : null,
                'platform_amount'   => $platformAmount,
                'partner_amount'    => $amount - $platformAmount,
            ];
        })->all();

        $all = [...$kept, ...$lines];

        // Miễn phí tháng đầu: 365home không bù — mọi khoản giảm trừ vào doanh thu của đối tác.
        if ($order->commission_waived) {
            $all = array_map(fn (array $line) => [...$line, 'funded_by' => Coupon::FUNDED_PARTNER, 'partner_share_pct' => null, 'partner_amount' => (int) ($line['amount'] ?? 0), 'platform_amount' => 0], $all);
        }

        return $all;
    }

    /**
     * Dòng giảm của phòng: khuyến mãi bảng giá do ĐỐI TÁC chịu. Chiết khấu hệ thống (đặt trọn ngày, đặt nhiều khung) do đối tác chịu khi đối tác bật;
     * 365home chịu (hoặc đồng chịu) khi 365home áp toàn sàn — chọn bằng settlement.system_discount_funded_by (partner | platform | shared) và
     * settlement.system_discount_partner_share_pct. Người chịu được chụp vào đơn lúc đặt nên đổi cấu hình sau không làm đổi đơn cũ.
     */
    public static function roomDiscountLines(int $promotionDiscount, int $systemDiscount, int $membershipDiscount = 0): array
    {
        $fundedBy = in_array(config('settlement.system_discount_funded_by'), [Coupon::FUNDED_PLATFORM, Coupon::FUNDED_SHARED], true)
            ? config('settlement.system_discount_funded_by') : Coupon::FUNDED_PARTNER;
        $share = min(100, max(0, (int) config('settlement.system_discount_partner_share_pct', 50)));
        $systemPlatform = match ($fundedBy) {
            Coupon::FUNDED_PLATFORM => $systemDiscount,
            Coupon::FUNDED_SHARED   => $systemDiscount - (int) round($systemDiscount * $share / 100),
            default                 => 0,
        };

        return array_values(array_filter([
            $promotionDiscount > 0 ? ['source' => 'promotion', 'code' => null, 'amount' => $promotionDiscount, 'funded_by' => Coupon::FUNDED_PARTNER, 'platform_amount' => 0, 'partner_amount' => $promotionDiscount] : null,
            $systemDiscount > 0 ? [
                'source' => 'system', 'code' => null, 'amount' => $systemDiscount, 'funded_by' => $fundedBy,
                'partner_share_pct' => $fundedBy === Coupon::FUNDED_SHARED ? $share : null,
                'platform_amount' => $systemPlatform, 'partner_amount' => $systemDiscount - $systemPlatform,
            ] : null,
            // Giảm theo hạng thành viên (voucher hạng, chỉ có ở luồng đặt trên website) luôn do 365home chịu.
            $membershipDiscount > 0 ? ['source' => 'membership', 'code' => null, 'amount' => $membershipDiscount, 'funded_by' => Coupon::FUNDED_PLATFORM, 'platform_amount' => $membershipDiscount, 'partner_amount' => 0] : null,
        ]));
    }

    // ───────────────────────── Mốc 2: đơn hoàn thành ─────────────────────────

    /** Đơn đã đủ điều kiện chốt hoa hồng chưa? (chưa chốt + đã trả phòng, hoặc bị hoàn mà đối tác vẫn giữ một phần tiền) */
    public function isFinalizable(Order $order): bool
    {
        if ($order->commission_finalized_at !== null || $order->collected_by === null) {
            return false;
        }

        return ($order->status === 'paid' && ($order->order_status === 'checked_out' || $this->stayEnded($order)))
            || ($order->status === 'refunded' && $this->retainedAmount($order) > 0);
    }

    /**
     * Kỳ lưu trú đã kết thúc quá settlement.auto_complete_hours giờ dù đơn chưa được đánh dấu "đã trả phòng" (khách không đến, phòng không có khoá thông minh,
     * nhân viên không bấm trả phòng) — vẫn coi là hoàn thành để chốt hoa hồng, nếu không đối tác giữ tiền mà đơn không bao giờ vào đối soát.
     */
    public function stayEnded(Order $order): bool
    {
        $lastCheckout = $order->items()->max('checkout_date');

        return $lastCheckout !== null
            && \Illuminate\Support\Carbon::parse($lastCheckout)->addHours((int) config('settlement.auto_complete_hours', 12))->isPast();
    }

    /**
     * Đơn ĐÃ chốt hoa hồng mà sau đó bị đổi giá/hoàn tiền/đổi mã: tính lại để hoa hồng luôn khớp tiền thật.
     *  - Chưa vào bảng đối soát, hoặc bảng còn NHÁP: tính lại ngay (bảng nháp được cộng lại tổng).
     *  - Đã vào bảng ĐÃ GỬI/đang khiếu nại/đã chốt: KHÔNG tự đổi số của bảng đã gửi đối tác — báo Super Admin để xử lý (khiếu nại hoặc điều chỉnh kỳ sau).
     */
    public function recalculateAfterChange(Order $order): void
    {
        if (! $order->commission_finalized_at) {
            return;
        }

        $settlement = $order->settlement_id ? \App\Models\PartnerSettlement::find($order->settlement_id) : null;

        if ($settlement && $settlement->status !== \App\Models\PartnerSettlement::STATUS_DRAFT) {
            $partner = $this->partnerOf($order);
            if ($partner) {
                app(EscrowService::class)->notifySuperAdmins('order_changed_after_settlement', 'Đơn đã vào bảng đối soát bị thay đổi',
                    "Đơn #{$order->order_code} thuộc bảng {$settlement->code} (" . (\App\Models\PartnerSettlement::STATUSES[$settlement->status] ?? $settlement->status) . ') vừa đổi giá/hoàn tiền. Số hoa hồng trong bảng giữ nguyên — xử lý qua khiếu nại hoặc điều chỉnh ở kỳ sau.',
                    $partner, ['order_code' => $order->order_code, 'settlement_id' => $settlement->id]);
            }

            return;
        }

        // Đơn đã hoàn hết tiền: không còn gì để tính hoa hồng — gỡ khỏi bảng nháp và coi như chưa chốt.
        if ($order->status === 'refunded' && $this->retainedAmount($order) <= 0) {
            $order->forceFill(['commission_amount' => 0, 'platform_subsidy' => 0, 'settlement_id' => null, 'commission_finalized_at' => null])->saveQuietly();
        } else {
            $order->forceFill(['commission_finalized_at' => null])->saveQuietly();
            $this->finalize($order);
        }

        if ($settlement) {
            app(SettlementService::class)->recalculate($settlement);
        }
    }

    /** Chạy định kỳ: chốt hoa hồng các đơn đã thanh toán, đã quá giờ trả phòng mà chưa được đánh dấu trả phòng. @return int số đơn đã chốt */
    public function finalizeEndedStays(): int
    {
        $count = 0;

        Order::withoutGlobalScopes()->whereNotNull('collected_by')->whereNull('commission_finalized_at')->where('status', 'paid')
            ->where(fn ($q) => $q->whereNull('order_status')->orWhere('order_status', '!=', 'checked_out'))
            ->orderBy('id')->chunkById(200, function ($orders) use (&$count) {
                foreach ($orders as $order) {
                    if ($this->isFinalizable($order)) {
                        $this->finalize($order);
                        $count++;
                    }
                }
            });

        return $count;
    }

    /** Tiền khách đã thực trả cho đơn (đặt cọc thì là số cọc đã trả). */
    public function paidAmount(Order $order): int
    {
        return $order->deposit_percent && $order->deposit_paid_amount ? (int) $order->deposit_paid_amount : (int) ($order->amount ?? $order->full_amount);
    }

    /** Tiền khách đã trả mà đối tác được giữ lại (K của đơn). */
    public function retainedAmount(Order $order): int
    {
        if ($order->status === 'refunded') {
            return max(0, $this->paidAmount($order) - (int) $order->refund_amount);
        }

        return max(0, (int) ($order->amount ?? $order->full_amount));
    }

    /**
     * Tiền 365home ĐÃ THU HỘ của đơn (chỉ đơn collected_by = platform). Phần còn lại trả tiền mặt tại quầy thì
     * đối tác cầm, không tính.
     */
    public function platformCollectedAmount(Order $order): int
    {
        if ($order->collected_by !== self::COLLECTED_PLATFORM) {
            return 0;
        }

        $retained = $this->retainedAmount($order);
        $remainderByCash = $order->deposit_percent && $order->remaining_payment_method && strtolower((string) $order->remaining_payment_method) !== 'payos';

        return $remainderByCash && $order->deposit_paid_amount ? min($retained, (int) $order->deposit_paid_amount) : $retained;
    }

    public function finalize(Order $order): void
    {
        if (! $this->isFinalizable($order)) {
            return;
        }

        $partner = $this->partnerOf($order);
        if (! $this->applies($order, $partner)) {
            return;
        }

        $lines = $this->discountLines($order);
        $subsidy = (int) collect($lines)->sum('platform_amount');
        $revenue = $this->retainedAmount($order);

        // Đơn huỷ/hoàn một phần: khoản 365home bù chia theo tỉ lệ tiền đối tác được giữ (đơn không thành thì không được bù).
        if ($order->status === 'refunded') {
            $paid = $this->paidAmount($order);
            $subsidy = $paid > 0 ? (int) round($subsidy * min(1, $revenue / $paid)) : 0;
        }

        $rate = $order->commission_rate !== null ? (float) $order->commission_rate : $this->commissionRateOf($partner);
        $base = $revenue + $subsidy;

        $order->forceFill([
            'commission_rate'         => $rate,
            'commission_amount'       => (int) round($base * $rate / 100),
            'platform_subsidy'        => $subsidy,
            'discounts'               => $lines,
            'commission_finalized_at' => now(),
        ])->saveQuietly();

        if ($subsidy > 0 && ($reason = $this->suspicionOf($order, $partner))) {
            $order->forceFill(['subsidy_held_at' => now(), 'subsidy_held_reason' => $reason])->saveQuietly();
            app(EscrowService::class)->notifySuperAdmins(
                'order_subsidy_held',
                'Giữ khoản 365home bù của đơn nghi ngờ',
                "Đơn #{$order->order_code} của " . ($partner->legal_name ?: $partner->name) . ": {$reason}. Đơn chưa vào kỳ đối soát cho tới khi bạn xem xét.",
                $partner,
                ['order_code' => $order->order_code],
            );
        }
    }

    /** Đơn nghi giả để hưởng tiền bù: người đặt trùng SĐT chủ/nhân viên đối tác, hoặc nhận phòng rồi trả phòng ngay. */
    public function suspicionOf(Order $order, Partner $partner): ?string
    {
        $digits = fn (?string $phone): string => preg_replace('/\D+/', '', (string) $phone);
        $buyer = $digits($order->buyer_phone);

        if ($buyer !== '') {
            $partnerPhones = \App\Models\User::query()->where('partner_id', $partner->id)->pluck('phone')
                ->push($partner->phone)->map($digits)->filter();

            if ($partnerPhones->contains($buyer)) {
                return 'người đặt trùng số điện thoại của chủ/nhân viên đối tác';
            }
        }

        // Cùng thiết bị: đơn đặt từ thiết bị (push token) của chủ/nhân viên đối tác. Tra theo hash như FcmToken::upsertForUser().
        if (filled($order->device_token) && \App\Models\FcmToken::query()->where('token_hash', hash('sha256', (string) $order->device_token))
            ->whereIn('user_id', \App\Models\User::query()->where('partner_id', $partner->id)->pluck('id'))->exists()) {
            return 'đơn được đặt từ thiết bị của chủ/nhân viên đối tác';
        }

        $minutes = (int) config('settlement.suspicious_stay_minutes', 30);
        if ($order->checked_in_at && $order->checked_out_at && $order->checked_in_at->diffInMinutes($order->checked_out_at) < $minutes) {
            return "nhận phòng và trả phòng cách nhau dưới {$minutes} phút";
        }

        return $this->priceRaisedBeforeBooking($order);
    }

    /**
     * Chống "nâng giá gốc rồi dùng voucher 365home để được bù": phòng của đơn có lần TĂNG giá (lịch sử giá price_board_price_logs) từ
     * settlement.price_increase_percent% trở lên trong settlement.price_increase_lookback_days ngày trước ngày đặt → giữ khoản bù để Super Admin
     * đối chiếu với giá niêm yết lúc đặt. Chỉ gọi khi đơn có khoản 365home bù.
     */
    private function priceRaisedBeforeBooking(Order $order): ?string
    {
        $productIds = $order->items()->pluck('product_id')->filter()->unique()->all();
        if ($productIds === [] || ! $order->created_at) {
            return null;
        }

        $percent = (float) config('settlement.price_increase_percent', 10);
        $days = (int) config('settlement.price_increase_lookback_days', 30);

        $raise = \Modules\Product\App\Models\PriceBoardPriceLog::query()
            ->whereIn('product_id', $productIds)
            ->whereBetween('created_at', [$order->created_at->copy()->subDays($days), $order->created_at])
            ->where('old_price', '>', 0)
            ->whereRaw('new_price >= old_price * ?', [1 + $percent / 100])
            ->orderByDesc('created_at')->first();

        return $raise
            ? 'giá phòng được tăng từ ' . number_format((float) $raise->old_price, 0, ',', '.') . 'đ lên ' . number_format((float) $raise->new_price, 0, ',', '.') . 'đ ngày ' . $raise->created_at->format('d/m/Y') . " (trong {$days} ngày trước khi đặt) rồi đơn dùng voucher 365home"
            : null;
    }

    /**
     * Chống "bảo khách huỷ đơn có voucher 365home rồi đặt lại ngoài để giữ tiền bù": đơn có khoản 365home bù vừa bị hoàn tiền mà CÙNG người đặt (cùng số điện thoại)
     * lại có đơn khác cùng đối tác, tạo sau, còn hiệu lực → báo Super Admin xem xét (không tự trừ tiền, chỉ cảnh báo vì có thể là khách đặt lại hợp lệ).
     */
    public function flagRebookAfterRefund(Order $order): void
    {
        $partner = $this->partnerOf($order);
        if (! $partner || ! $this->applies($order, $partner) || $order->collected_by === null || blank($order->buyer_phone)) {
            return;
        }

        if ((int) collect($this->discountLines($order))->sum('platform_amount') <= 0) {
            return;
        }

        $replacement = Order::withoutGlobalScopes()->where('partner_id', $partner->id)->where('buyer_phone', $order->buyer_phone)
            ->where('id', '!=', $order->id)->whereIn('status', ['paid', 'deposit', 'pending'])->where('created_at', '>=', $order->created_at)->orderBy('created_at')->first();

        if ($replacement) {
            app(EscrowService::class)->notifySuperAdmins('order_rebook_suspected', 'Nghi huỷ đơn có voucher rồi đặt lại', ($partner->legal_name ?: $partner->name)
                . ": đơn #{$order->order_code} (có voucher 365home) vừa bị hoàn tiền, cùng người đặt đã có đơn #{$replacement->order_code}. Kiểm tra xem có đặt lại ngoài để giữ tiền bù không.",
                $partner, ['order_code' => $order->order_code, 'replacement_order_code' => $replacement->order_code]);
        }
    }

    public function releaseSubsidyHold(Order $order): void
    {
        $order->forceFill(['subsidy_held_at' => null, 'subsidy_held_reason' => null])->saveQuietly();
    }

    // ───────────────────────── Thông báo ─────────────────────────

    /** Tiền đặt phòng vừa về thẳng tài khoản đối tác (lần thanh toán đầu của đơn). */
    public function notifyPaidToPartner(Order $order): void
    {
        if ($order->collected_by !== self::COLLECTED_PARTNER || ! ($partner = $this->partnerOf($order))) {
            return;
        }

        $paid = $order->status === 'deposit' && $order->deposit_paid_amount ? (int) $order->deposit_paid_amount : (int) $order->amount;

        app(PartnerNotifier::class)->send(
            $partner,
            'order_paid_to_partner',
            'Đơn đã thanh toán về tài khoản của bạn',
            "Đơn #{$order->order_code} đã thanh toán " . number_format($paid, 0, ',', '.') . 'đ vào tài khoản PayOS của bạn.',
            ['order_code' => $order->order_code],
            'success',
            false,
        );
    }
}
