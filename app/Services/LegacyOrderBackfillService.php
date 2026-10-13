<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Partner;
use App\Models\PartnerSettlement;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Category\Entities\Category;
use Modules\Payment\Entities\Order;

/**
 * Đưa các đơn TẠO TRƯỚC khi luồng tiền mới có hiệu lực (đối tác ký hợp đồng offline, bật payment_flow_effective_at sau) vào đối soát:
 * chụp collected_by/hoa hồng/khoản giảm như lúc tạo đơn, chốt hoa hồng đơn đã hoàn thành (mốc chốt = ngày trả phòng THẬT để chia đúng kỳ),
 * rồi sinh các bảng đối soát NHÁP theo từng kỳ — không tự gửi đối tác, Super Admin xem số rồi mới bấm gửi.
 *
 * Quy ước tiền về đâu của đơn cũ (trước luồng mới 365home thu hộ toàn bộ thanh toán online): COD = tiền mặt tại đối tác, còn lại = 365home đã thu hộ
 * (platform) — KHÔNG dùng kênh PayOS hiện tại của đối tác vì đơn cũ không được thanh toán vào kênh đó.
 */
class LegacyOrderBackfillService
{
    public function __construct(
        private OrderCommissionService $commission,
        private SettlementService $settlements,
    ) {}

    /** Công cụ làm riêng cho đối tác Về Nhà (ký hợp đồng offline) — không dùng cho đối tác khác. */
    public static function isEligiblePartner(Partner $partner): bool
    {
        return $partner->partner_type === Partner::TYPE_HOMESTAY
            && ! $partner->isPlatformPartner()
            && ! $partner->isSystemPartner()
            && mb_stripos((string) $partner->name . ' ' . (string) $partner->legal_name, 'về nhà') !== false;
    }

    /** Báo cáo dạng văn bản để hiện trong thông báo CMS. */
    public static function describe(array $report): string
    {
        $money = fn (int $v) => number_format($v, 0, ',', '.') . 'đ';
        $o = $report['orders'];
        $t = $report['totals'];

        $lines = [
            "Đơn được chụp hoa hồng: {$o['snapshotted']} (365home thu hộ: {$o['platform_collected']}, tiền mặt: {$o['cash']})",
            "Đã chốt hoa hồng: {$o['finalized']} — chưa hoàn thành (chốt sau khi trả phòng): {$o['not_completed_yet']}",
            'Doanh thu đối tác giữ: ' . $money($t['revenue']) . ' — Hoa hồng: ' . $money($t['commission'])
                . ' — 365home bù: ' . $money($t['subsidy']) . ' — 365home đã thu hộ: ' . $money($t['platform_collected']),
        ];

        foreach ($report['settlements'] as $s) {
            $lines[] = "{$s['code']} ({$s['period']}): {$s['orders']} đơn, hoa hồng " . $money($s['commission']) . ', net ' . $money($s['net']);
        }

        foreach ($report['held'] as $held) {
            $lines[] = 'Giữ khoản bù — ' . $held;
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * @param  bool  $apply  false = chạy thử: tính đủ số liệu rồi hoàn tác toàn bộ, không ghi gì.
     * @return array{orders: array<string, int>, totals: array<string, int>, held: array<int, string>, skipped_no_partner: int, settlements: array<int, array<string, mixed>>}
     */
    public function run(Partner $partner, Carbon $from, ?Carbon $to, ?float $rate, ?User $by, bool $apply): array
    {
        if ($partner->partner_type !== Partner::TYPE_HOMESTAY || $partner->isPlatformPartner() || $partner->isSystemPartner()) {
            throw new \InvalidArgumentException('Chỉ áp dụng cho đối tác Homestay thường (không phải MiniHouse/nền tảng/hệ thống).');
        }

        if (! $partner->usesDirectPayment() && $apply && ! $by) {
            throw new \InvalidArgumentException('Cần người thao tác (Super Admin) để bật luồng đối soát cho đối tác.');
        }

        if ($rate !== null && ($rate < 0 || $rate > 100)) {
            throw new \InvalidArgumentException('Tỉ lệ hoa hồng phải từ 0 đến 100.');
        }

        $autoSend = config('settlement.auto_send');
        // Bảng đối soát cũ luôn để NHÁP: gửi ngay sẽ tính hạn nộp từ hôm nay và có thể kích hoạt trừ ký quỹ ngoài ý muốn.
        config(['settlement.auto_send' => false]);

        DB::beginTransaction();

        try {
            // Hợp đồng ký offline nên payment_flow_effective_at còn trống: bật luồng đối soát ngay tại đây (đúng như khi hợp đồng online có hiệu lực) để
            // đơn tạo từ giờ về sau được chụp hoa hồng tự động như các đối tác khác. Chạy thử chỉ gán trong bộ nhớ, không ghi/không gửi thông báo.
            if (! $partner->usesDirectPayment()) {
                $apply
                    ? app(PartnerContractWorkflowService::class)->activatePaymentFlow($partner, now(), $by, true)
                    : $partner->setAttribute('payment_flow_effective_at', now());
            }

            $report = $this->process($partner, $from, $to, $rate, $by);

            $apply ? DB::commit() : DB::rollBack();
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        } finally {
            config(['settlement.auto_send' => $autoSend]);
        }

        return $report;
    }

    private function process(Partner $partner, Carbon $from, ?Carbon $to, ?float $rate, ?User $by): array
    {
        $branchIds = Category::withoutGlobalScopes()->where('partner_id', $partner->id)->pluck('id');

        $query = Order::withoutGlobalScopes()
            ->where(fn ($q) => $q->where('partner_id', $partner->id)
                ->orWhere(fn ($w) => $w->whereNull('partner_id')->whereIn('category_id', $branchIds)))
            ->whereNull('collected_by')
            ->whereNull('settlement_id')
            ->whereIn('status', ['paid', 'deposit', 'refunded'])
            ->whereDate('created_at', '>=', $from->toDateString())
            ->when($to, fn ($q) => $q->whereDate('created_at', '<=', $to->toDateString()))
            ->orderBy('id');

        $orders = ['snapshotted' => 0, 'finalized' => 0, 'not_completed_yet' => 0, 'platform_collected' => 0, 'cash' => 0];
        $totals = ['revenue' => 0, 'commission' => 0, 'subsidy' => 0, 'platform_collected' => 0];
        $held = [];
        $partnerRate = $rate ?? $this->commission->commissionRateOf($partner);

        $query->chunkById(200, function ($chunk) use (&$orders, &$totals, &$held, $partnerRate) {
            foreach ($chunk as $order) {
                $collectedBy = strtolower((string) $order->payment_method) === 'cod'
                    ? OrderCommissionService::COLLECTED_CASH
                    : OrderCommissionService::COLLECTED_PLATFORM;

                $order->forceFill([
                    'collected_by'      => $collectedBy,
                    'commission_rate'   => $partnerRate,
                    'commission_waived' => false,
                ])->saveQuietly();
                $order->forceFill(['discounts' => $this->commission->discountLines($order)])->saveQuietly();

                $orders['snapshotted']++;
                $orders[$collectedBy === OrderCommissionService::COLLECTED_CASH ? 'cash' : 'platform_collected']++;

                if (! $this->commission->isFinalizable($order)) {
                    $orders['not_completed_yet']++;

                    continue;
                }

                $this->commission->finalize($order, true);
                $order->refresh();
                $order->forceFill(['commission_finalized_at' => $this->completedAt($order)])->saveQuietly();

                $orders['finalized']++;
                $totals['revenue'] += $this->commission->retainedAmount($order);
                $totals['commission'] += (int) $order->commission_amount;
                $totals['subsidy'] += (int) $order->platform_subsidy;
                $totals['platform_collected'] += $this->commission->platformCollectedAmount($order);

                if ($order->subsidy_held_at) {
                    $held[] = "#{$order->order_code}: {$order->subsidy_held_reason}";
                }
            }
        });

        return [
            'orders'      => $orders,
            'totals'      => $totals,
            'held'        => $held,
            'settlements' => $this->generateDraftSettlements($partner, $from, $by),
        ];
    }

    /** Mốc chốt hoa hồng = lúc đơn thực sự hoàn thành (không phải hôm chạy lệnh) để đơn vào đúng kỳ đối soát. */
    private function completedAt(Order $order): Carbon
    {
        $at = $order->status === 'refunded' && $order->refunded_at
            ? $order->refunded_at
            : ($order->checked_out_at
                ?? ($order->items()->max('checkout_date')
                    ? Carbon::parse($order->items()->max('checkout_date'))->endOfDay()
                    : ($order->updated_at ?? now())));

        return $at->gt(now()) ? now() : Carbon::instance($at);
    }

    /** Mỗi kỳ (theo payment_cycle của đối tác) từ kỳ chứa $from đến kỳ đã đóng gần nhất → 1 bảng NHÁP, theo thứ tự thời gian. */
    private function generateDraftSettlements(Partner $partner, Carbon $from, ?User $by): array
    {
        $cycle = (string) ($partner->payment_cycle ?: 'biweekly');
        $periods = [];
        $asOf = now();

        while (count($periods) < 240) {
            [$start, $end] = $this->settlements->lastClosedPeriod($cycle, $asOf);

            if ($end->lt($from->copy()->startOfDay())) {
                break;
            }

            array_unshift($periods, [$start, $end]);
            $asOf = $start->copy();
        }

        $sheets = [];

        foreach ($periods as [$start, $end]) {
            $sheet = $this->settlements->generate($partner, $start, $end, $by);

            if ($sheet instanceof PartnerSettlement) {
                $sheets[] = [
                    'code'               => $sheet->code,
                    'period'             => $start->format('d/m/Y') . ' — ' . $end->format('d/m/Y'),
                    'orders'             => (int) $sheet->orders_count,
                    'revenue'            => (int) $sheet->revenue_total,
                    'commission'         => (int) $sheet->commission_total,
                    'subsidy'            => (int) $sheet->subsidy_total,
                    'platform_collected' => (int) $sheet->platform_collected_total,
                    'net'                => (int) $sheet->net_amount,
                ];
            }
        }

        return $sheets;
    }
}
