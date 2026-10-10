<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Partner;
use App\Models\PartnerSettlement as Settlement;
use App\Models\PartnerSettlementDispute as Dispute;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Payment\Entities\Order;

/**
 * ĐỐI SOÁT HOA HỒNG đối tác Homestay theo kỳ (partners.payment_cycle: weekly | biweekly | monthly).
 *
 * Mỗi kỳ, các đơn đã CHỐT hoa hồng (OrderCommissionService::finalize) mà chưa thuộc kỳ nào được gom vào 1 bảng đối soát:
 *   net = Σ hoa hồng − Σ khoản 365home bù − Σ tiền 365home đã thu hộ (đơn collected_by = platform)
 *   net > 0 : đối tác chuyển khoản nộp 365home (QR PayOS của 365home) trước hạn; quá hạn ⇒ tự trừ ký quỹ
 *   net < 0 : 365home chi cho đối tác (Super Admin xác nhận đã chi)
 *
 * Trạng thái: draft → sent → disputed → paid | deducted | paid_out. Đơn bị giữ khoản bù (nghi giả) chưa vào kỳ nào
 * cho tới khi Super Admin xem xét. Tiền ký quỹ KHÔNG phải doanh thu nên không xuất hoá đơn GTGT; hoá đơn GTGT xuất
 * cho phần hoa hồng của kỳ (invoice_amount = commission_total).
 */
class SettlementService
{
    public function __construct(
        private OrderCommissionService $commission,
        private EscrowService $escrow,
        private PartnerNotifier $notifier,
        private SettlementPayosService $payos,
    ) {}

    // ───────────────────────── Sinh bảng đối soát ─────────────────────────

    /** @return array{0: Carbon, 1: Carbon} kỳ ĐÃ ĐÓNG gần nhất tính đến $asOf (đầu và cuối kỳ, theo ngày). */
    public function lastClosedPeriod(string $cycle, Carbon $asOf): array
    {
        $today = $asOf->copy()->startOfDay();

        return match ($cycle) {
            'weekly' => [$today->copy()->startOfWeek()->subWeek(), $today->copy()->startOfWeek()->subDay()],
            'biweekly' => $today->day >= 16
                ? [$today->copy()->startOfMonth(), $today->copy()->startOfMonth()->addDays(14)]
                : [$today->copy()->subMonthNoOverflow()->startOfMonth()->addDays(15), $today->copy()->startOfMonth()->subDay()],
            default => [$today->copy()->subMonthNoOverflow()->startOfMonth(), $today->copy()->startOfMonth()->subDay()],
        };
    }

    /** Đối tác Homestay đang hoạt động, đã duyệt (không MiniHouse, không đối tác nền tảng). */
    private function eligiblePartners(): Collection
    {
        return Partner::query()
            ->where('partner_type', Partner::TYPE_HOMESTAY)
            ->where('is_platform_partner', false)
            ->where('id', '!=', \Modules\Minihouse\App\Support\HomestayBridge::PARTNER_ID)
            ->where('verification_status', 'approved')
            ->get();
    }

    /** Chạy định kỳ: sinh bảng đối soát cho kỳ vừa đóng của từng đối tác (nếu có đơn). @return int số bảng mới */
    public function generateDue(?Carbon $asOf = null): int
    {
        $asOf ??= now();
        $created = 0;

        foreach ($this->eligiblePartners() as $partner) {
            [$start, $end] = $this->lastClosedPeriod((string) ($partner->payment_cycle ?: 'biweekly'), $asOf);

            if ($this->generate($partner, $start, $end)) {
                $created++;
            }
        }

        return $created;
    }

    /** Sinh bảng đối soát 1 kỳ cho 1 đối tác. null = không có đơn nào hoặc kỳ này đã có bảng. */
    public function generate(Partner $partner, Carbon $start, Carbon $end, ?User $by = null): ?Settlement
    {
        if (Settlement::query()->where('partner_id', $partner->id)->whereDate('period_start', $start)->whereDate('period_end', $end)->exists()) {
            return null;
        }

        return DB::transaction(function () use ($partner, $start, $end, $by) {
            $orders = $this->unsettledOrders($partner, $end)->lockForUpdate()->get();

            if ($orders->isEmpty()) {
                return null;
            }

            $settlement = Settlement::create([
                'code'         => $this->newCode($end),
                'partner_id'   => $partner->id,
                'period_start' => $start->toDateString(),
                'period_end'   => $end->toDateString(),
                'cycle'        => (string) ($partner->payment_cycle ?: 'biweekly'),
                'status'       => Settlement::STATUS_DRAFT,
                'created_by'   => $by?->id,
            ]);

            Order::withoutGlobalScopes()->whereIn('id', $orders->pluck('id'))->update(['settlement_id' => $settlement->id]);
            $this->recalculate($settlement);

            if ((bool) config('settlement.auto_send')) {
                $this->send($settlement->fresh(), null);
            }

            return $settlement->fresh();
        });
    }

    /** Đơn đã chốt hoa hồng, chưa vào kỳ nào, không bị giữ khoản bù, chốt trước hết ngày $end. */
    private function unsettledOrders(Partner $partner, Carbon $end)
    {
        return Order::withoutGlobalScopes()
            ->where('partner_id', $partner->id)
            ->whereNull('settlement_id')
            ->whereNotNull('commission_finalized_at')
            ->whereNull('subsidy_held_at')
            // Đơn trong thời gian miễn phí: đối tác tự thu thì không hoa hồng, không bù — không có gì đối soát; đơn 365home thu hộ vẫn vào kỳ để chi lại 100%.
            ->where(fn ($q) => $q->where('commission_waived', false)->orWhere('collected_by', OrderCommissionService::COLLECTED_PLATFORM))
            ->where('commission_finalized_at', '<=', $end->copy()->endOfDay());
    }

    /** Tính lại các tổng của bảng theo đơn đang thuộc bảng. */
    public function recalculate(Settlement $settlement): Settlement
    {
        $orders = Order::withoutGlobalScopes()->where('settlement_id', $settlement->id)->get();

        $revenue = (int) $orders->sum(fn (Order $order) => $this->commission->retainedAmount($order));
        $commission = (int) $orders->sum('commission_amount');
        $subsidy = (int) $orders->sum('platform_subsidy');
        $held = (int) $orders->sum(fn (Order $order) => $this->commission->platformCollectedAmount($order));

        $settlement->update([
            'orders_count'             => $orders->count(),
            'revenue_total'            => $revenue,
            'commission_total'         => max(0, $commission),
            'subsidy_total'            => $subsidy,
            'platform_collected_total' => $held,
            'net_amount'               => $commission - $subsidy - $held,
        ]);

        return $settlement->refresh();
    }

    private function newCode(Carbon $end): string
    {
        do {
            $code = 'DS' . $end->format('ym') . '-' . strtoupper(Str::random(5));
        } while (Settlement::query()->where('code', $code)->exists());

        return $code;
    }

    // ───────────────────────── Gửi & thanh toán ─────────────────────────

    /** Gửi bảng đối soát cho đối tác (kèm QR nộp nếu đối tác phải nộp). */
    public function send(Settlement $settlement, ?User $by): Settlement
    {
        if ($settlement->status !== Settlement::STATUS_DRAFT) {
            throw new \DomainException('Chỉ gửi được bảng đối soát đang ở trạng thái nháp.');
        }

        $settlement->update([
            'status'  => Settlement::STATUS_SENT,
            'sent_at' => now(),
            'due_at'  => $settlement->net_amount > 0 ? now()->addDays((int) config('settlement.overdue_days'))->endOfDay() : null,
        ]);

        $partner = $settlement->partner;
        $period = $settlement->period_start->format('d/m/Y') . ' – ' . $settlement->period_end->format('d/m/Y');

        if ($settlement->net_amount > 0) {
            $this->payos->createLink($settlement->fresh());
            $this->notifier->send($partner, 'settlement_sent', 'Bảng đối soát mới cần thanh toán', "Kỳ {$period}: bạn cần nộp 365home " . $this->money($settlement->net_amount)
                . ' hoa hồng trước ' . $settlement->due_at->format('d/m/Y') . '. Quá hạn, khoản này sẽ tự trừ vào ký quỹ.', ['settlement_id' => $settlement->id, 'settlement_code' => $settlement->code]);
        } elseif ($settlement->net_amount < 0) {
            $this->notifier->send($partner, 'settlement_sent', 'Bảng đối soát mới', "Kỳ {$period}: 365home sẽ chi cho bạn " . $this->money(-$settlement->net_amount) . '.', ['settlement_id' => $settlement->id, 'settlement_code' => $settlement->code], 'success');
        } else {
            $settlement->update(['status' => Settlement::STATUS_PAID, 'paid_at' => now()]);
            $this->notifier->send($partner, 'settlement_sent', 'Bảng đối soát mới', "Kỳ {$period}: hai bên không còn khoản phải nộp hay chi.", ['settlement_id' => $settlement->id, 'settlement_code' => $settlement->code], 'success');
        }

        return $settlement->fresh();
    }

    /** Tạo/làm mới QR nộp hoa hồng (đối tác bấm). */
    public function paymentLink(Settlement $settlement): Settlement
    {
        if ($settlement->net_amount <= 0 || ! in_array($settlement->status, [Settlement::STATUS_SENT, Settlement::STATUS_DISPUTED], true)) {
            throw new \DomainException('Bảng đối soát này không có khoản đối tác phải nộp.');
        }

        if (! $settlement->payos_checkout_url || ($settlement->payos_expired_at && $settlement->payos_expired_at->isPast())) {
            $this->payos->createLink($settlement);
        }

        return $settlement->fresh();
    }

    /** Đối tác đã chuyển khoản (webhook PayOS hoặc Super Admin ghi nhận tay). */
    public function markPaid(Settlement $settlement, ?string $reference = null): bool
    {
        $changed = DB::transaction(function () use ($settlement) {
            $locked = Settlement::query()->whereKey($settlement->id)->lockForUpdate()->firstOrFail();

            if ($locked->isFinal() || $locked->net_amount <= 0) {
                return false;
            }

            $locked->update(['status' => Settlement::STATUS_PAID, 'paid_at' => now()]);

            return true;
        });

        if ($changed) {
            $this->notifier->send($settlement->partner, 'settlement_paid', 'Đã nhận hoa hồng kỳ đối soát', "Đã ghi nhận khoản nộp {$this->money($settlement->net_amount)} của bảng đối soát {$settlement->code}. Cảm ơn bạn.",
                ['settlement_id' => $settlement->id, 'settlement_code' => $settlement->code, 'reference' => $reference], 'success');
        }

        return $changed;
    }

    /** 365home đã chi tiền cho đối tác (net < 0). */
    public function markPaidOut(Settlement $settlement, string $reference, User $by): Settlement
    {
        if ($settlement->net_amount >= 0 || $settlement->status !== Settlement::STATUS_SENT) {
            throw new \DomainException('Chỉ xác nhận chi cho bảng đã gửi mà 365home phải chi cho đối tác.');
        }

        $settlement->update(['status' => Settlement::STATUS_PAID_OUT, 'paid_out_at' => now(), 'paid_out_reference' => $reference, 'paid_out_by' => $by->id]);

        $this->notifier->send($settlement->partner, 'settlement_paid_out', '365home đã chi cho bạn', "365home đã chi {$this->money(-$settlement->net_amount)} cho bảng đối soát {$settlement->code} (tham chiếu {$reference}).",
            ['settlement_id' => $settlement->id, 'settlement_code' => $settlement->code], 'success');

        return $settlement->fresh();
    }

    // ───────────────────────── Khiếu nại ─────────────────────────

    public function disputeOrder(Settlement $settlement, string $orderCode, string $reason, User $by): Dispute
    {
        if (! in_array($settlement->status, [Settlement::STATUS_SENT, Settlement::STATUS_DISPUTED], true)) {
            throw new \DomainException('Chỉ khiếu nại được bảng đối soát đã gửi và chưa chốt.');
        }

        $order = Order::withoutGlobalScopes()->where('settlement_id', $settlement->id)->where('order_code', $orderCode)->first();
        if (! $order) {
            throw new \DomainException('Đơn này không thuộc bảng đối soát.');
        }
        if (Dispute::query()->where('settlement_id', $settlement->id)->where('order_id', $order->id)->where('status', Dispute::STATUS_OPEN)->exists()) {
            throw new \DomainException('Đơn này đang có khiếu nại chưa xử lý.');
        }

        $dispute = DB::transaction(function () use ($settlement, $order, $reason, $by) {
            $dispute = Dispute::create([
                'settlement_id' => $settlement->id, 'order_id' => $order->id, 'order_code' => $order->order_code,
                'status' => Dispute::STATUS_OPEN, 'reason' => $reason, 'created_by' => $by->id,
            ]);
            $settlement->update(['status' => Settlement::STATUS_DISPUTED]);

            return $dispute;
        });

        $this->escrow->notifySuperAdmins('settlement_disputed', 'Đối tác khiếu nại bảng đối soát', ($settlement->partner->legal_name ?: $settlement->partner->name) . " khiếu nại đơn #{$order->order_code} trong bảng {$settlement->code}: {$reason}",
            $settlement->partner, ['settlement_id' => $settlement->id, 'dispute_id' => $dispute->id]);

        return $dispute;
    }

    /**
     * Super Admin xử lý khiếu nại: accept = sửa hoa hồng của đơn thành $adjustedCommission (≥ 0), reject = giữ nguyên.
     * Hết khiếu nại chưa xử lý thì bảng quay về "đã gửi" và hạn nộp được tính lại từ hôm nay.
     */
    public function resolveDispute(Dispute $dispute, bool $accept, ?int $adjustedCommission, string $note, User $by): Dispute
    {
        if ($dispute->status !== Dispute::STATUS_OPEN) {
            throw new \DomainException('Khiếu nại này đã được xử lý.');
        }
        if ($accept && ($adjustedCommission === null || $adjustedCommission < 0)) {
            throw new \DomainException('Chấp nhận khiếu nại cần nhập số hoa hồng mới của đơn (≥ 0).');
        }

        $settlement = $dispute->settlement;

        DB::transaction(function () use ($dispute, $accept, $adjustedCommission, $note, $by, $settlement) {
            if ($accept) {
                Order::withoutGlobalScopes()->whereKey($dispute->order_id)->update(['commission_amount' => $adjustedCommission]);
                $this->recalculate($settlement);
            }

            $dispute->update([
                'status' => $accept ? Dispute::STATUS_ACCEPTED : Dispute::STATUS_REJECTED,
                'adjusted_commission' => $accept ? $adjustedCommission : null,
                'resolution_note' => $note, 'resolved_by' => $by->id, 'resolved_at' => now(),
            ]);

            if (! $settlement->disputes()->where('status', Dispute::STATUS_OPEN)->exists()) {
                $settlement->update([
                    'status' => Settlement::STATUS_SENT,
                    'due_at' => $settlement->net_amount > 0 ? now()->addDays((int) config('settlement.overdue_days'))->endOfDay() : null,
                ]);
            }
        });

        $settlement->refresh();
        $this->notifier->send($settlement->partner, 'settlement_dispute_resolved', 'Đã xử lý khiếu nại đối soát', "Đơn #{$dispute->order_code} trong bảng {$settlement->code}: " . ($accept ? 'chấp nhận, hoa hồng mới ' . $this->money((int) $adjustedCommission) : 'giữ nguyên') . ". {$note}",
            ['settlement_id' => $settlement->id, 'dispute_id' => $dispute->id]);

        return $dispute->fresh();
    }

    // ───────────────────────── Quá hạn: nhắc và tự trừ ký quỹ ─────────────────────────

    /** Nhắc đối tác trước hạn nộp (mỗi bảng một lần). @return int số bảng được nhắc */
    public function remindDue(): int
    {
        $count = 0;

        Settlement::query()->where('status', Settlement::STATUS_SENT)->where('net_amount', '>', 0)->whereNull('reminded_at')
            ->where('due_at', '<=', now()->addDays((int) config('settlement.remind_before_days')))->where('due_at', '>', now())
            ->get()->each(function (Settlement $settlement) use (&$count) {
                $settlement->update(['reminded_at' => now()]);
                $this->notifier->send($settlement->partner, 'settlement_due_soon', 'Sắp hết hạn nộp hoa hồng', "Bảng đối soát {$settlement->code}: nộp {$this->money($settlement->net_amount)} trước {$settlement->due_at->format('d/m/Y')}, quá hạn sẽ tự trừ vào ký quỹ.",
                    ['settlement_id' => $settlement->id, 'settlement_code' => $settlement->code]);
                $count++;
            });

        return $count;
    }

    /** Bảng quá hạn mà đối tác chưa nộp ⇒ tự trừ ký quỹ (bút toán deduct_commission). @return int số bảng đã trừ */
    public function deductOverdue(): int
    {
        $count = 0;

        Settlement::query()->where('status', Settlement::STATUS_SENT)->where('net_amount', '>', 0)->where('due_at', '<', now())->get()
            ->each(function (Settlement $settlement) use (&$count) {
                $deducted = DB::transaction(function () use ($settlement) {
                    $locked = Settlement::query()->whereKey($settlement->id)->lockForUpdate()->first();

                    if (! $locked || $locked->status !== Settlement::STATUS_SENT) {
                        return false;
                    }

                    $entry = $this->escrow->deductOverdueCommission($locked->partner, (int) $locked->net_amount, $locked->code,
                        "Hoa hồng bảng đối soát {$locked->code} (kỳ {$locked->period_start->format('d/m/Y')} – {$locked->period_end->format('d/m/Y')}) quá hạn chưa nộp.");
                    $locked->update(['status' => Settlement::STATUS_DEDUCTED, 'deducted_at' => now(), 'deducted_entry_id' => $entry->id]);

                    return true;
                });

                if ($deducted) {
                    $partner = $settlement->partner->fresh();
                    $this->notifier->send($partner, 'settlement_deducted', 'Đã trừ ký quỹ do quá hạn nộp hoa hồng', "Bảng đối soát {$settlement->code} quá hạn: đã trừ {$this->money($settlement->net_amount)} vào ký quỹ. Số dư ký quỹ: {$this->money((int) $partner->escrow_balance)}.",
                        ['settlement_id' => $settlement->id, 'settlement_code' => $settlement->code]);
                    $count++;
                }
            });

        return $count;
    }

    // ───────────────────────── Đơn bị giữ khoản bù ─────────────────────────

    /** Super Admin xem xét xong đơn bị giữ: thả (đơn vào kỳ tới) hoặc huỷ khoản bù (đơn vào kỳ tới nhưng không được bù). */
    public function resolveHeldOrder(Order $order, bool $release, User $by): void
    {
        if ($order->subsidy_held_at === null) {
            throw new \DomainException('Đơn này không bị giữ khoản bù.');
        }

        if (! $release) {
            $order->forceFill(['platform_subsidy' => 0])->saveQuietly();
        }

        $this->commission->releaseSubsidyHold($order);
    }

    // ───────────────────────── Hoá đơn GTGT ─────────────────────────

    /**
     * Số liệu để kế toán xuất hoá đơn GTGT phần HOA HỒNG của kỳ (365home → đối tác). Số hoa hồng của kỳ là giá chưa thuế; thuế suất lấy từ settlement.vat_percent.
     * Khoản 365home bù khuyến mãi và tiền thu hộ KHÔNG nằm trong hoá đơn (đó là bù trừ công nợ), ký quỹ không phải doanh thu nên không xuất hoá đơn.
     *
     * @return array<string, mixed>
     */
    public function invoiceData(Settlement $settlement): array
    {
        $partner = $settlement->partner;
        $seller = (array) config('contract.platform');
        $vatPercent = (int) config('settlement.vat_percent', 10);
        $before = (int) $settlement->commission_total;
        $vat = (int) round($before * $vatPercent / 100);

        return [
            'settlement_code' => $settlement->code,
            'period'          => $settlement->period_start->format('d/m/Y') . ' – ' . $settlement->period_end->format('d/m/Y'),
            'seller'          => ['name' => $seller['name'] ?? null, 'tax_code' => $seller['tax_code'] ?? null, 'address' => $seller['address'] ?? null],
            'buyer'           => ['name' => $partner->legal_name ?: $partner->name, 'tax_code' => $partner->tax_code, 'address' => $partner->address],
            'line'            => 'Phí hợp tác kinh doanh (hoa hồng) kỳ đối soát ' . $settlement->period_start->format('d/m/Y') . ' – ' . $settlement->period_end->format('d/m/Y'),
            'amount_before_vat' => $before,
            'vat_percent'     => $vatPercent,
            'vat_amount'      => $vat,
            'total'           => $before + $vat,
            'invoiceable'     => $before > 0 && $settlement->status !== Settlement::STATUS_DRAFT,
            'invoice_no'      => $settlement->invoice_no,
            'invoiced_at'     => $settlement->invoiced_at?->toIso8601String(),
        ];
    }

    /** Bảng kê xuất hoá đơn GTGT dạng PDF để kế toán đối chiếu. */
    public function invoicePdf(Settlement $settlement): string
    {
        $d = $this->invoiceData($settlement);
        $money = fn (int $v) => number_format($v, 0, ',', '.') . 'đ';
        $e = fn ($v) => e((string) ($v ?? '—'));

        $html = '<div style="font-family:' . \App\Support\PartnerContractRenderer::FONT . ';font-size:12pt;">'
            . '<h2 style="text-align:center;margin:0 0 6pt;">BẢNG KÊ XUẤT HOÁ ĐƠN GTGT</h2>'
            . '<p style="text-align:center;margin:0 0 14pt;">Kỳ đối soát ' . $e($d['period']) . ' · Mã bảng ' . $e($d['settlement_code']) . '</p>'
            . '<p><strong>Bên bán:</strong> ' . $e($d['seller']['name']) . '<br>Mã số thuế: ' . $e($d['seller']['tax_code']) . '<br>Địa chỉ: ' . $e($d['seller']['address']) . '</p>'
            . '<p><strong>Bên mua:</strong> ' . $e($d['buyer']['name']) . '<br>Mã số thuế: ' . $e($d['buyer']['tax_code']) . '<br>Địa chỉ: ' . $e($d['buyer']['address']) . '</p>'
            . '<table style="width:100%;border-collapse:collapse;margin-top:10pt;" border="1" cellpadding="5"><tr style="background:#eee;"><th align="left">Nội dung</th><th align="right">Thành tiền</th></tr>'
            . '<tr><td>' . $e($d['line']) . '</td><td align="right">' . $money($d['amount_before_vat']) . '</td></tr>'
            . '<tr><td>Thuế GTGT ' . $d['vat_percent'] . '%</td><td align="right">' . $money($d['vat_amount']) . '</td></tr>'
            . '<tr><td><strong>Tổng thanh toán</strong></td><td align="right"><strong>' . $money($d['total']) . '</strong></td></tr></table>'
            . '<p style="margin-top:12pt;font-size:10.5pt;color:#444;">Lưu ý: khoản ký quỹ không phải doanh thu nên không xuất hoá đơn. Khoản 365home bù khuyến mãi và tiền 365home thu hộ là bù trừ công nợ trong bảng đối soát, không nằm trong hoá đơn.</p>'
            . ($d['invoice_no'] ? '<p>Đã xuất hoá đơn số <strong>' . $e($d['invoice_no']) . '</strong> ngày ' . e(\Illuminate\Support\Carbon::parse($d['invoiced_at'])->format('d/m/Y')) . '.</p>' : '')
            . '</div>';

        return \App\Services\PdfSigning\ContractPdfRenderer::render($html);
    }

    /** Kế toán đã xuất hoá đơn ngoài hệ thống: ghi nhận số hoá đơn (ghi đè được số/ngày nếu nhập sai). */
    public function markInvoiced(Settlement $settlement, string $invoiceNo, User $by, ?Carbon $issuedAt = null): Settlement
    {
        $data = $this->invoiceData($settlement);
        if (! $data['invoiceable']) {
            throw new \DomainException($settlement->status === Settlement::STATUS_DRAFT ? 'Bảng nháp chưa gửi đối tác — chưa xuất hoá đơn.' : 'Kỳ này không có hoa hồng để xuất hoá đơn.');
        }

        $settlement->update(['invoice_no' => $invoiceNo, 'invoiced_at' => $issuedAt ?? now(), 'invoiced_by' => $by->id]);

        return $settlement->fresh();
    }

    // ───────────────────────── Hiển thị ─────────────────────────

    /** @return array<string, mixed> */
    public function toArray(Settlement $settlement, bool $withOrders = false): array
    {
        $data = [
            'id'                       => $settlement->id,
            'code'                     => $settlement->code,
            'partner_id'               => $settlement->partner_id,
            'period_start'             => $settlement->period_start->toDateString(),
            'period_end'               => $settlement->period_end->toDateString(),
            'cycle'                    => $settlement->cycle,
            'status'                   => $settlement->status,
            'status_label'             => Settlement::STATUSES[$settlement->status] ?? $settlement->status,
            'direction'                => $settlement->direction(),
            'orders_count'             => $settlement->orders_count,
            'revenue_total'            => $settlement->revenue_total,
            'commission_total'         => $settlement->commission_total,
            'subsidy_total'            => $settlement->subsidy_total,
            'platform_collected_total' => $settlement->platform_collected_total,
            'net_amount'               => $settlement->net_amount,
            // Hoá đơn GTGT xuất cho phần hoa hồng của kỳ; tiền ký quỹ không phải doanh thu nên không nằm trong hoá đơn.
            'invoice_amount'           => $settlement->commission_total,
            'invoice_no'               => $settlement->invoice_no,
            'invoiced_at'              => $settlement->invoiced_at?->toIso8601String(),
            'sent_at'                  => $settlement->sent_at?->toIso8601String(),
            'due_at'                   => $settlement->due_at?->toIso8601String(),
            'is_overdue'               => $settlement->status === Settlement::STATUS_SENT && $settlement->net_amount > 0 && $settlement->due_at?->isPast(),
            'paid_at'                  => $settlement->paid_at?->toIso8601String(),
            'deducted_at'              => $settlement->deducted_at?->toIso8601String(),
            'paid_out_at'              => $settlement->paid_out_at?->toIso8601String(),
            'paid_out_reference'       => $settlement->paid_out_reference,
            'payment'                  => $settlement->payos_checkout_url ? [
                'checkout_url' => $settlement->payos_checkout_url, 'qr_code' => $settlement->payos_qr_code,
                'bank_bin' => $settlement->payos_bank_bin, 'account_number' => $settlement->payos_account_number,
                'account_name' => $settlement->payos_account_name, 'transfer_content' => $settlement->code,
                'expires_at' => $settlement->payos_expired_at?->toIso8601String(),
            ] : null,
            'open_disputes'            => $settlement->disputes()->where('status', Dispute::STATUS_OPEN)->count(),
            'note'                     => $settlement->note,
        ];

        if ($withOrders) {
            $disputes = $settlement->disputes()->get()->groupBy('order_id');

            $data['orders'] = $settlement->orders()->with('items')->orderBy('commission_finalized_at')->get()->map(fn (Order $order) => [
                'order_code'        => $order->order_code,
                'buyer_name'        => $order->buyer_name,
                // Phòng đã thuê và ngày ở (đối chiếu từng dòng đối soát với lưu trú thực tế).
                'rooms'             => $order->items->map(fn ($item) => [
                    'name' => $item->name,
                    'checkin_date' => $item->checkin_date?->toIso8601String(),
                    'checkout_date' => $item->checkout_date?->toIso8601String(),
                ])->values(),
                'checked_out_at'    => $order->checked_out_at?->toIso8601String(),
                'collected_by'      => $order->collected_by,
                'collected_by_label' => OrderCommissionService::COLLECTED_BY[$order->collected_by] ?? null,
                'revenue'           => $this->commission->retainedAmount($order),
                'platform_collected' => $this->commission->platformCollectedAmount($order),
                'commission_rate'   => $order->commission_rate !== null ? (float) $order->commission_rate : null,
                'commission_amount' => $order->commission_amount,
                'platform_subsidy'  => $order->platform_subsidy,
                'discounts'         => $order->discounts ?? [],
                'finalized_at'      => $order->commission_finalized_at?->toIso8601String(),
                'disputes'          => ($disputes[$order->id] ?? collect())->map(fn (Dispute $dispute) => [
                    'id' => $dispute->id, 'status' => $dispute->status, 'reason' => $dispute->reason,
                    'adjusted_commission' => $dispute->adjusted_commission, 'resolution_note' => $dispute->resolution_note,
                ])->values(),
            ])->values();
        }

        return $data;
    }

    private function money(int $amount): string
    {
        return number_format($amount, 0, ',', '.') . 'đ';
    }
}
