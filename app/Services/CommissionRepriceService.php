<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Partner;
use App\Models\PartnerSettlement;
use App\Models\PartnerSettlementDispute;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Payment\Entities\Order;

/**
 * ÁP LẠI TỶ LỆ HOA HỒNG cho các đơn ĐÃ phát sinh của 1 đối tác trong 1 khoảng ngày — dùng khi hai bên thống nhất đổi tỷ lệ
 * (vd 20% → 30%) và muốn áp cho cả kỳ đang chạy thay vì chỉ cho đơn tạo sau thời điểm đổi.
 *
 * Mỗi đơn chụp commission_rate lúc tạo và chốt commission_amount lúc trả phòng (xem OrderCommissionService), bảng đối soát chỉ
 * cộng số đã chụp — nên đổi tỷ lệ ở hồ sơ đối tác KHÔNG ảnh hưởng đơn cũ. Service này sửa trực tiếp 2 cột đó trên từng đơn
 * rồi tính lại tổng các bảng đối soát bị ảnh hưởng. Doanh thu (revenue_total) và tiền khách trả KHÔNG đổi.
 *
 * Phạm vi một đơn = đơn đã chốt hoa hồng có commission_finalized_at trong khoảng (đúng cách bảng đối soát chọn đơn), cộng đơn
 * chưa chốt (đang ở/chưa trả phòng) tạo trong khoảng — đơn chưa chốt chỉ đổi tỷ lệ, số hoa hồng được tính khi trả phòng.
 *
 * Luôn bỏ qua: đơn miễn phí tháng đầu, đơn thuộc bảng đã chốt (đã nộp/đã trừ ký quỹ/365home đã chi) hoặc đã xuất hoá đơn GTGT,
 * đơn đã được điều chỉnh/đang khiếu nại. Đơn thuộc bảng ĐÃ GỬI chỉ sửa khi người gọi bật $includeSent.
 */
class CommissionRepriceService
{
    public const SKIP_WAIVED = 'Miễn phí tháng đầu (hoa hồng 0)';
    public const SKIP_FINAL = 'Bảng đối soát đã chốt (đã nộp / trừ ký quỹ / 365home đã chi)';
    public const SKIP_INVOICED = 'Bảng đã xuất hoá đơn GTGT';
    public const SKIP_SENT = 'Bảng đã gửi đối tác (chưa bật sửa bảng đã gửi)';
    public const SKIP_DISPUTE = 'Đã có khiếu nại / điều chỉnh hoa hồng';
    public const SKIP_SAME = 'Đã đúng tỷ lệ';

    public function __construct(private OrderCommissionService $commission, private SettlementService $settlements, private PartnerNotifier $notifier) {}

    /**
     * Danh sách đơn trong phạm vi, mỗi dòng cho biết sẽ sửa hay bỏ qua (kèm lý do) — KHÔNG ghi gì.
     *
     * @return Collection<int, array{order: Order, settlement: ?PartnerSettlement, skip: ?string, old_rate: ?float, old_commission: ?int, new_commission: ?int}>
     */
    public function plan(Partner $partner, Carbon $from, Carbon $to, float $rate, bool $includeSent = false): Collection
    {
        $start = $from->copy()->startOfDay();
        $end   = $to->copy()->endOfDay();

        $orders = Order::withoutGlobalScopes()
            ->where('partner_id', $partner->id)
            ->whereNotNull('collected_by')
            ->where(function ($q) use ($start, $end) {
                $q->where(fn ($w) => $w->whereNotNull('commission_finalized_at')->whereBetween('commission_finalized_at', [$start, $end]))
                    ->orWhere(fn ($w) => $w->whereNull('commission_finalized_at')->whereIn('status', ['paid', 'deposit'])->whereBetween('created_at', [$start, $end]));
            })
            ->orderBy('id')
            ->get();

        $settlements = PartnerSettlement::query()->whereIn('id', $orders->pluck('settlement_id')->filter()->unique())->get()->keyBy('id');
        $disputed    = PartnerSettlementDispute::query()->whereIn('order_id', $orders->pluck('id'))->pluck('order_id')->flip();

        return $orders->map(function (Order $order) use ($settlements, $disputed, $rate, $includeSent) {
            $settlement = $order->settlement_id ? $settlements->get($order->settlement_id) : null;
            $oldRate    = $order->commission_rate !== null ? (float) $order->commission_rate : null;

            $skip = match (true) {
                (bool) $order->commission_waived => self::SKIP_WAIVED,
                $settlement?->isFinal() => self::SKIP_FINAL,
                $settlement && filled($settlement->invoice_no) => self::SKIP_INVOICED,
                $settlement && $settlement->status !== PartnerSettlement::STATUS_DRAFT && ! $includeSent => self::SKIP_SENT,
                $disputed->has($order->id) => self::SKIP_DISPUTE,
                $oldRate !== null && abs($oldRate - $rate) < 0.005 && $this->newCommission($order, $rate) === ($order->commission_amount !== null ? (int) $order->commission_amount : null) => self::SKIP_SAME,
                default => null,
            };

            return [
                'order'          => $order,
                'settlement'     => $settlement,
                'skip'           => $skip,
                'old_rate'       => $oldRate,
                'old_commission' => $order->commission_amount !== null ? (int) $order->commission_amount : null,
                'new_commission' => $this->newCommission($order, $rate),
            ];
        });
    }

    /**
     * Tóm tắt plan() cho API/giao diện: tổng, lý do bỏ qua, và từng bảng đối soát bị ảnh hưởng (đơn chưa vào bảng nào gom riêng ở "unsettled").
     * Hoa hồng "trước/sau" chỉ tính trên đơn SẼ SỬA và đã chốt (đơn chưa chốt chưa có số hoa hồng).
     *
     * @param  Collection<int, array<string, mixed>>  $plan
     * @return array<string, mixed>
     */
    public function summary(Collection $plan, bool $withOrders = false): array
    {
        $group = function (Collection $rows): array {
            $apply  = $rows->filter(fn (array $r) => $r['skip'] === null);
            $before = (int) $apply->sum('old_commission');
            $after  = (int) $apply->sum(fn (array $r) => $r['new_commission'] ?? $r['old_commission']);

            return [
                'orders_total'      => $rows->count(),
                'orders_to_update'  => $apply->count(),
                'commission_before' => $before,
                'commission_after'  => $after,
                'difference'        => $after - $before,
                'skipped'           => $rows->filter(fn (array $r) => $r['skip'] !== null)->groupBy('skip')->map(fn ($g, $why) => ['reason' => $why, 'count' => $g->count()])->values()->all(),
            ];
        };

        $settlements = $plan->filter(fn (array $r) => $r['settlement'] !== null)->groupBy(fn (array $r) => $r['settlement']->id)
            ->map(function (Collection $rows) use ($group) {
                /** @var PartnerSettlement $s */
                $s = $rows->first()['settlement'];

                return [
                    'settlement_id' => $s->id,
                    'code'          => $s->code,
                    'status'        => $s->status,
                    'status_label'  => PartnerSettlement::STATUSES[$s->status] ?? $s->status,
                    'invoice_no'    => $s->invoice_no,
                    'period_start'  => $s->period_start->toDateString(),
                    'period_end'    => $s->period_end->toDateString(),
                ] + $group($rows);
            })->sortBy('period_start')->values()->all();

        $unsettled = $plan->filter(fn (array $r) => $r['settlement'] === null);

        $result = [
            'totals'      => $group($plan),
            'settlements' => $settlements,
            'unsettled'   => $unsettled->isEmpty() ? null : $group($unsettled),
        ];

        if ($withOrders) {
            $result['orders'] = $plan->map(fn (array $r) => [
                'order_code'      => $r['order']->order_code,
                'settlement_code' => $r['settlement']?->code,
                'finalized'       => $r['order']->commission_finalized_at !== null,
                'old_rate'        => $r['old_rate'],
                'old_commission'  => $r['old_commission'],
                'new_commission'  => $r['new_commission'],
                'will_update'     => $r['skip'] === null,
                'skip_reason'     => $r['skip'],
            ])->values()->all();
        }

        return $result;
    }

    /**
     * Ghi tỷ lệ mới lên các đơn "sửa được" của plan(), tính lại các bảng đối soát bị ảnh hưởng, tuỳ chọn đổi luôn tỷ lệ trong hồ sơ
     * đối tác (để đơn tạo sau cũng theo tỷ lệ mới). Bảng đã gửi bị đổi số sẽ xoá QR PayOS cũ (đúng số tiền cũ) và báo đối tác.
     *
     * @return array{orders: int, commission_before: int, commission_after: int, settlements: list<array{code: string, net_before: int, net_after: int}>}
     */
    public function apply(Partner $partner, Carbon $from, Carbon $to, float $rate, bool $includeSent, bool $updatePartnerRate): array
    {
        $plan   = $this->plan($partner, $from, $to, $rate, $includeSent)->filter(fn (array $row) => $row['skip'] === null)->values();
        $before = 0;
        $after  = 0;
        $report = [];

        DB::transaction(function () use ($partner, $plan, $rate, $updatePartnerRate, &$before, &$after, &$report) {
            foreach ($plan as $row) {
                /** @var Order $order */
                $order   = $row['order'];
                $updates = ['commission_rate' => $rate];

                if ($row['new_commission'] !== null) {
                    $updates['commission_amount'] = $row['new_commission'];
                }

                $before += (int) $row['old_commission'];
                $after  += (int) ($row['new_commission'] ?? $row['old_commission']);

                $order->forceFill($updates)->saveQuietly();
            }

            $settlementIds = $plan->pluck('settlement.id')->filter()->unique();

            foreach (PartnerSettlement::query()->whereIn('id', $settlementIds)->get() as $settlement) {
                $netBefore  = (int) $settlement->net_amount;
                $commBefore = (int) $settlement->commission_total;
                $fresh      = $this->settlements->recalculate($settlement);

                $report[] = ['code' => $fresh->code, 'net_before' => $netBefore, 'net_after' => (int) $fresh->net_amount, 'commission_before' => $commBefore, 'commission_after' => (int) $fresh->commission_total, 'status' => $fresh->status];

                $line = now()->format('d/m/Y H:i') . ": áp lại hoa hồng {$this->formatRate($rate)}% (hoa hồng {$commBefore}đ → {$fresh->commission_total}đ)";
                $fresh->forceFill(['note' => trim(($fresh->note ? $fresh->note . "\n" : '') . $line)])->save();

                if ($fresh->status !== PartnerSettlement::STATUS_DRAFT && $netBefore !== (int) $fresh->net_amount) {
                    // QR/liên kết PayOS đã tạo mang số tiền cũ — xoá để lần "Lấy QR nộp hoa hồng" sau tạo lại đúng số.
                    $fresh->forceFill(['payos_order_code' => null, 'payos_payment_link_id' => null, 'payos_checkout_url' => null, 'payos_qr_code' => null,
                        'payos_expired_at' => null, 'payos_bank_bin' => null, 'payos_account_number' => null, 'payos_account_name' => null])->save();
                }
            }

            if ($updatePartnerRate) {
                $partner->update(['commission_rate' => $this->formatRate($rate)]);
            }
        });

        foreach ($report as $item) {
            if ($item['status'] !== PartnerSettlement::STATUS_DRAFT && $item['net_before'] !== $item['net_after']) {
                $this->notifier->send(
                    $partner,
                    'settlement_adjusted',
                    'Bảng đối soát đã được điều chỉnh hoa hồng',
                    "Bảng {$item['code']} được áp lại tỷ lệ hoa hồng {$this->formatRate($rate)}%: hoa hồng " . number_format($item['commission_before'], 0, ',', '.') . 'đ → ' . number_format($item['commission_after'], 0, ',', '.') . 'đ. Vui lòng xem lại bảng và lấy lại QR nộp (nếu có).',
                    ['settlement_code' => $item['code']],
                );
            }
        }

        Log::info('Áp lại hoa hồng đối tác', [
            'partner' => $partner->id, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'rate' => $rate, 'orders' => $plan->count(),
            'commission_before' => $before, 'commission_after' => $after, 'settlements' => array_column($report, 'code'), 'by' => auth()->id(),
        ]);

        return ['orders' => $plan->count(), 'commission_before' => $before, 'commission_after' => $after, 'settlements' => $report];
    }

    /** Hoa hồng mới của đơn ĐÃ chốt, cùng công thức OrderCommissionService::finalize(); đơn chưa chốt không có số (tính khi trả phòng). */
    private function newCommission(Order $order, float $rate): ?int
    {
        if ($order->commission_finalized_at === null) {
            return null;
        }

        return (int) round(($this->commission->retainedAmount($order) + (int) $order->platform_subsidy) * $rate / 100);
    }

    private function formatRate(float $rate): string
    {
        return rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.');
    }
}
