<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Partner;
use App\Models\PartnerSettlement as Settlement;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Payment\Entities\Order;

/**
 * THỐNG KÊ HOA HỒNG & DOANH THU của đối tác Homestay, tách hai góc nhìn dùng chung cho trang quản trị (Filament) và API:
 *  - "platform": CHỦ NỀN TẢNG (Super Admin) — hoa hồng thu được, khoản 365home bù khuyến mãi, thu nhập ròng, hoa hồng miễn, tiền thu hộ,
 *    theo từng đối tác / từng tháng / từng kênh thu tiền, công nợ đối soát và ký quỹ.
 *  - "partner": ĐỐI TÁC — doanh thu, hoa hồng phải nộp, khoản được 365home bù, thực nhận, công nợ với nền tảng.
 *
 * Số liệu tính theo ĐƠN ĐÃ CHỐT HOA HỒNG (cùng tập đơn với bảng đối soát — SettlementService) và được xếp vào kỳ theo NGÀY GHI NHẬN:
 * ngày khách TRẢ PHÒNG thực tế (checked_out_at; không có thì ngày trả phòng cuối của đơn); đơn hoàn/huỷ có giữ tiền thì theo ngày hoàn.
 * Đối soát vẫn theo kỳ (đơn chốt trễ sang bảng kỳ sau) nên tổng một tháng có thể khác bảng đối soát của tháng đó — xem summary.unsettled_orders. Công thức (xem OrderCommissionService):
 *   K = tiền khách trả đối tác giữ lại · S = khoản giảm do 365home chịu · D = K + S (doanh thu tính hoa hồng) · C = hoa hồng
 *   Đối tác thực nhận = D − C · 365home thu nhập ròng = C − S · Đối tác nộp / (365home chi) = C − S − tiền 365home đã thu hộ
 */
class CommissionStatsService
{
    public const FILTERS = ['today', 'yesterday', '7d', '30d', '90d', 'this_month', 'last_month', 'this_year', 'ytd', 'last_year', 'custom'];

    public function __construct(private readonly OrderCommissionService $commission)
    {
    }

    /** @return array{0: Carbon, 1: Carbon, 2: string} [từ, đến, nhãn] */
    public function range(string $filter, ?string $start = null, ?string $end = null): array
    {
        $now = Carbon::now();

        [$from, $to] = match ($filter) {
            'yesterday'  => [Carbon::yesterday()->startOfDay(), Carbon::yesterday()->endOfDay()],
            '7d'         => [$now->copy()->subDays(6)->startOfDay(), $now->copy()->endOfDay()],
            '30d'        => [$now->copy()->subDays(29)->startOfDay(), $now->copy()->endOfDay()],
            '90d'        => [$now->copy()->subDays(89)->startOfDay(), $now->copy()->endOfDay()],
            'this_month' => [$now->copy()->startOfMonth(), $now->copy()->endOfDay()],
            'last_month' => [$now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()->endOfMonth()->endOfDay()],
            'this_year', 'ytd' => [$now->copy()->startOfYear(), $now->copy()->endOfDay()],
            'last_year'  => [$now->copy()->subYear()->startOfYear(), $now->copy()->subYear()->endOfYear()->endOfDay()],
            'custom'     => [
                $start ? Carbon::parse($start)->startOfDay() : $now->copy()->subDays(29)->startOfDay(),
                $end ? Carbon::parse($end)->endOfDay() : $now->copy()->endOfDay(),
            ],
            default      => [Carbon::today()->startOfDay(), Carbon::today()->endOfDay()],
        };

        if ($from->gt($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        return [$from, $to, $from->format('d/m/Y') . ' – ' . $to->format('d/m/Y')];
    }

    /**
     * Góc nhìn CHỦ NỀN TẢNG. $partnerId (tuỳ chọn) lọc về một đối tác. Chỉ Super Admin được gọi (người gọi kiểm tra quyền).
     *
     * @return array<string, mixed>
     */
    public function forPlatform(User $viewer, Carbon $from, Carbon $to, ?string $partnerId = null, int $limit = 10): array
    {
        $orders = $this->orders($from, $to, $viewer);
        if ($partnerId !== null) {
            $orders->where('partner_id', $partnerId);
        }
        $rows = $orders->get();

        $settlements = Settlement::query()->whereBetween('period_end', [$from->toDateString(), $to->toDateString()]);
        if ($partnerId !== null) {
            $settlements->where('partner_id', $partnerId);
        }

        $partners = Partner::withTrashed()->whereIn('id', $rows->pluck('partner_id')->unique()->all())->get(['id', 'name', 'legal_name'])->keyBy('id');

        return [
            'scope'          => 'platform',
            'period'         => $this->period($from, $to),
            'basis'          => $this->basis(),
            'summary'        => $this->summarize($rows),
            'by_partner'     => $this->byPartner($rows, $partners, $limit),
            'by_month'       => $this->byMonth($rows),
            'by_collected'   => $this->byCollected($rows),
            'settlements'    => $this->settlementSummary($settlements->get()),
            'receivables'    => $this->receivables($partnerId),
            'escrow'         => $this->platformEscrow($partnerId),
            'pending'        => $this->pending($partnerId, $viewer),
            'income'         => $partnerId === null ? $this->income($from, $to, (int) $this->summarize($rows)['platform_net_income']) : null,
        ];
    }

    /**
     * ĐƠN ĐÃ THANH TOÁN NHƯNG CHƯA CHỐT HOA HỒNG (đang ở / sắp tới / chưa trả phòng) — KHÔNG phụ thuộc kỳ lọc, là số hiện tại. Hoa hồng chỉ chốt khi
     * khách trả phòng nên các đơn này chưa nằm trong summary; ở đây chỉ báo trước số tiền và hoa hồng DỰ KIẾN (tỉ lệ hợp đồng × tiền khách đã trả, chưa
     * gồm khoản 365home bù) để đối chiếu với doanh thu ở Dashboard. Đối tác đang trong thời gian miễn hoa hồng: dự kiến = 0.
     *
     * @return array{orders: int, customer_paid: int, expected_commission: int, note: string}
     */
    private function pending(?string $partnerId, ?User $viewer): array
    {
        // Chỉ đối tác có hoa hồng (Homestay, không phải đối tác nền tảng / hệ thống): lọc ở SQL để không nạp các đơn tự doanh của 365home.
        $partners = Partner::query()->where('partner_type', Partner::TYPE_HOMESTAY)->where('is_platform_partner', false)
            ->when($partnerId !== null, fn ($q) => $q->where('id', $partnerId))->get()
            ->reject(fn (Partner $p) => $p->isSystemPartner())->keyBy('id');

        $query = Order::withoutGlobalScopes()->countedInStats($viewer)->where('status', 'paid')->whereNull('commission_finalized_at')
            ->whereIn('partner_id', $partners->keys()->all() ?: ['']);
        $orders = 0;
        $paid = 0;
        $expected = 0;

        foreach ($query->cursor() as $order) {
            $partner = $partners->get($order->partner_id);
            if (! $partner || ! $this->commission->applies($order, $partner)) {
                continue;
            }
            $amount = $this->commission->retainedAmount($order);
            $rate = $order->commission_rate !== null ? (float) $order->commission_rate : $this->commission->commissionRateOf($partner);
            $orders++;
            $paid += $amount;
            $expected += $partner->isInFeeFreePeriod() ? 0 : (int) round($amount * $rate / 100);
        }

        return [
            'orders' => $orders, 'customer_paid' => $paid, 'expected_commission' => $expected,
            'note' => 'Đơn đã thanh toán nhưng chưa trả phòng; hoa hồng chốt khi khách trả phòng. Số dự kiến chưa gồm khoản 365home bù.',
        ];
    }

    /**
     * TỔNG THU NHẬP của 365home trong kỳ = doanh thu phòng của CHÍNH MÌNH (đơn đã thanh toán của đối tác nền tảng) + thu nhập ròng từ hoa hồng
     * (hoa hồng − khoản bù khuyến mãi). Doanh thu của đối tác KHÔNG phải tiền của 365home nên không cộng vào. Ngày ghi nhận của đơn tự doanh:
     * ngày trả phòng thực tế → ngày trả phòng cuối của đơn → ngày đặt.
     *
     * @return array{own_orders: int, own_revenue: int, commission_net_income: int, total: int}
     */
    private function income(Carbon $from, Carbon $to, int $commissionNet): array
    {
        $p = DB::getTablePrefix();
        $o = $p . 'orders';
        $i = $p . 'order_items';
        $expr = "COALESCE({$o}.checked_out_at, (SELECT MAX(oi.checkout_date) FROM {$i} oi WHERE oi.order_id = {$o}.id), {$o}.created_at)";

        $own = Order::withoutGlobalScopes()->where('exclude_from_stats', false)->where('status', 'paid')
            // 365home = đối tác nền tảng + đơn chưa gắn đối tác nào (phòng không có đối tác mặc định thuộc 365home).
            ->where(fn ($q) => $q->whereIn('partner_id', Partner::query()->where('is_platform_partner', true)->select('id'))->orWhereNull('partner_id'))
            ->whereRaw("({$expr}) between ? and ?", [$from, $to]);

        $revenue = (int) (clone $own)->selectRaw('COALESCE(SUM(COALESCE(amount, full_amount)), 0) as t')->value('t');

        return ['own_orders' => (int) (clone $own)->count(), 'own_revenue' => $revenue, 'commission_net_income' => $commissionNet, 'total' => $revenue + $commissionNet];
    }

    /**
     * BẢNG SO SÁNH TẤT CẢ ĐỐI TÁC Homestay trong kỳ (kể cả đối tác chưa có đơn), cho góc nhìn chủ nền tảng. Mỗi dòng gồm các tổng hợp hoa hồng
     * (cùng công thức summary) + công nợ đối soát đang mở, ký quỹ và trạng thái miễn hoa hồng.
     *
     * @return array{period: array<string, string>, totals: array<string, int|float>, rows: list<array<string, mixed>>}
     */
    public function partnersTable(User $viewer, Carbon $from, Carbon $to, string $search = '', string $sort = 'commission', string $direction = 'desc'): array
    {
        $groups = $this->orders($from, $to, $viewer)->get()->toBase()->groupBy('partner_id');
        $excluded = \App\Support\PlatformStats::excludedPartnerIds($viewer);

        $partners = Partner::query()->where('partner_type', Partner::TYPE_HOMESTAY)->whereNotIn('id', $excluded)
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$search}%")->orWhere('legal_name', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")))
            ->get()->reject(fn (Partner $p) => $p->isSystemPartner() || $p->isPlatformPartner());

        $open = Settlement::query()->whereIn('status', [Settlement::STATUS_SENT, Settlement::STATUS_DISPUTED])->where('net_amount', '>', 0)
            ->whereIn('partner_id', $partners->pluck('id'))->get()->groupBy('partner_id');

        $rows = $partners->map(function (Partner $p) use ($groups, $open) {
            $summary = $this->summarize($groups->get($p->id, collect()));

            return [
                'partner_id'      => $p->id,
                'name'            => $p->legal_name ?: $p->name,
                'contract_status' => $p->contract_status,
                'commission_rate' => $this->commission->commissionRateOf($p),
                'fee_free_active' => $p->isInFeeFreePeriod(),
                'escrow_balance'  => (int) $p->escrow_balance,
                'open_to_collect' => (int) ($open->get($p->id)?->sum('net_amount') ?? 0),
            ] + $summary;
        });

        $key = in_array($sort, ['name', 'orders', 'revenue_base', 'commission', 'platform_subsidy', 'platform_net_income', 'partner_receives', 'settlement_net', 'open_to_collect', 'escrow_balance'], true) ? $sort : 'commission';
        $rows = $direction === 'asc' ? $rows->sortBy($key) : $rows->sortByDesc($key);

        $totals = $this->summarize($groups->only($partners->pluck('id')->all())->flatten(1));

        return ['period' => $this->period($from, $to), 'basis' => $this->basis(), 'totals' => $totals, 'rows' => $rows->values()->all()];
    }

    /**
     * Góc nhìn ĐỐI TÁC: chỉ dữ liệu của chính $partner.
     *
     * @return array<string, mixed>
     */
    public function forPartner(Partner $partner, Carbon $from, Carbon $to): array
    {
        $rows = $this->orders($from, $to, null)->where('partner_id', $partner->id)->get();
        $settlements = Settlement::query()->where('partner_id', $partner->id)->whereBetween('period_end', [$from->toDateString(), $to->toDateString()])->get();
        $summary = $this->summarize($rows);

        return [
            'scope'        => 'partner',
            'period'       => $this->period($from, $to),
            'basis'        => $this->basis(),
            'partner'      => [
                'id'                   => $partner->id,
                'name'                 => $partner->legal_name ?: $partner->name,
                'commission_rate'      => $this->commission->commissionRateOf($partner),
                'payment_cycle'        => $partner->payment_cycle,
                'fee_free_until'       => $partner->fee_free_until?->toIso8601String(),
                'fee_free_active'      => $partner->isInFeeFreePeriod(),
                'payment_flow_active'  => $partner->usesDirectPayment(),
            ],
            'summary'      => $summary + [
                // Góc nhìn đối tác: thực nhận sau hoa hồng, và bảng đối soát kỳ này phải nộp hay được chi.
                'amount_to_pay_platform' => max(0, $summary['settlement_net']),
                'amount_platform_pays'   => max(0, -$summary['settlement_net']),
            ],
            'by_month'     => $this->byMonth($rows),
            'by_collected' => $this->byCollected($rows),
            'settlements'  => $this->settlementSummary($settlements),
            'receivables'  => $this->receivables($partner->id),
            'pending'      => $this->pending($partner->id, null),
            'escrow'       => [
                'balance'    => (int) $partner->escrow_balance,
                'min_amount' => $partner->escrow_min_amount !== null ? (int) $partner->escrow_min_amount : null,
            ],
        ];
    }

    /**
     * Danh sách TỪNG ĐƠN đã chốt hoa hồng trong kỳ (phân trang) — để đối chiếu số tổng. $partnerId null = mọi đối tác (chỉ góc nhìn nền tảng).
     *
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function ordersPage(?User $viewer, Carbon $from, Carbon $to, ?string $partnerId, int $perPage = 20, ?string $collectedBy = null, ?bool $waived = null)
    {
        $query = $this->orders($from, $to, $viewer)->with('partner:id,name,legal_name')->orderByDesc('recognized_at');
        if ($partnerId !== null) {
            $query->where('partner_id', $partnerId);
        }
        if ($collectedBy !== null) {
            $query->where('collected_by', $collectedBy);
        }
        if ($waived !== null) {
            $query->where('commission_waived', $waived);
        }

        return $query->paginate(max(1, min(100, $perPage)))->through(function (Order $order) {
            $k = $this->commission->retainedAmount($order);
            $s = (int) $order->platform_subsidy;
            $c = (int) $order->commission_amount;
            $held = $this->commission->platformCollectedAmount($order);

            return [
                'order_code'         => $order->order_code,
                'partner_id'         => $order->partner_id,
                'partner_name'       => $order->partner?->legal_name ?: $order->partner?->name,
                'buyer_name'         => $order->buyer_name,
                'status'             => $order->status,
                'collected_by'       => $order->collected_by,
                'customer_paid'      => $k,
                'platform_subsidy'   => $s,
                'revenue_base'       => $k + $s,
                'commission_rate'    => $order->commission_rate !== null ? (float) $order->commission_rate : null,
                'commission'         => $c,
                'commission_waived'  => (bool) $order->commission_waived,
                'platform_collected' => $held,
                'settlement_net'     => $c - $s - $held,
                'partner_receives'   => $k + $s - $c,
                'settlement_id'      => $order->settlement_id,
                'recognized_at'      => $order->recognized_at ? Carbon::parse($order->recognized_at)->toIso8601String() : null,
                'finalized_at'       => $order->commission_finalized_at?->toIso8601String(),
            ];
        });
    }

    // ───────────────────────── nội bộ ─────────────────────────

    /**
     * NGÀY GHI NHẬN của đơn (SQL): đơn hoàn → ngày hoàn (lúc chốt); đơn thường → ngày khách trả phòng thực tế, dự phòng là ngày trả phòng cuối của
     * đơn rồi mới tới thời điểm chốt. Nhờ đó nhân viên bấm trả phòng trễ qua ngày/tháng vẫn không làm hoa hồng nhảy sang kỳ khác.
     */
    private function recognizedAtSql(): string
    {
        $p = DB::getTablePrefix();
        $o = $p . 'orders';
        $i = $p . 'order_items';

        return "CASE WHEN {$o}.status = 'refunded' THEN {$o}.commission_finalized_at "
            . "ELSE COALESCE({$o}.checked_out_at, (SELECT MAX(oi.checkout_date) FROM {$i} oi WHERE oi.order_id = {$o}.id), {$o}.commission_finalized_at) END";
    }

    private function orders(Carbon $from, Carbon $to, ?User $viewer)
    {
        $expr = $this->recognizedAtSql();

        return Order::withoutGlobalScopes()
            ->countedInStats($viewer)
            ->select('orders.*')->selectRaw("({$expr}) as recognized_at")
            ->whereNotNull('partner_id')
            ->whereNotNull('commission_finalized_at')
            ->whereRaw("({$expr}) between ? and ?", [$from, $to]);
    }

    /** @return array{field: string, label: string} mốc thời gian dùng để xếp đơn vào kỳ (trả về kèm mọi API để client ghi chú). */
    private function basis(): array
    {
        return [
            'field' => 'checkout_date',
            'label' => 'Theo ngày khách trả phòng thực tế (đơn hoàn tiền: theo ngày hoàn). Đối soát vẫn theo kỳ: đơn chốt trễ vào bảng kỳ sau.',
        ];
    }

    /** @return array{from: string, to: string, label: string} */
    private function period(Carbon $from, Carbon $to): array
    {
        return ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'label' => $from->format('d/m/Y') . ' – ' . $to->format('d/m/Y')];
    }

    /** @return array<string, int|float> */
    private function blank(): array
    {
        return [
            'orders' => 0, 'customer_paid' => 0, 'platform_subsidy' => 0, 'revenue_base' => 0, 'commission' => 0,
            'waived_orders' => 0, 'waived_revenue_base' => 0, 'platform_collected' => 0, 'settlement_net' => 0,
            'partner_receives' => 0, 'platform_net_income' => 0, 'unsettled_orders' => 0,
        ];
    }

    private function add(array $agg, Order $order): array
    {
        $k = $this->commission->retainedAmount($order);
        $s = (int) $order->platform_subsidy;
        $d = $k + $s;
        $c = (int) $order->commission_amount;
        $held = $this->commission->platformCollectedAmount($order);

        $agg['orders']++;
        $agg['customer_paid'] += $k;
        $agg['platform_subsidy'] += $s;
        $agg['revenue_base'] += $d;
        $agg['commission'] += $c;
        $agg['platform_collected'] += $held;
        if ($order->settlement_id === null) {
            $agg['unsettled_orders']++;
        }
        if ($order->commission_waived) {
            $agg['waived_orders']++;
            $agg['waived_revenue_base'] += $d;
        }

        return $agg;
    }

    private function finish(array $agg): array
    {
        $agg['settlement_net'] = $agg['commission'] - $agg['platform_subsidy'] - $agg['platform_collected'];
        $agg['partner_receives'] = $agg['revenue_base'] - $agg['commission'];
        $agg['platform_net_income'] = $agg['commission'] - $agg['platform_subsidy'];
        $agg['effective_commission_rate'] = $agg['revenue_base'] > 0 ? round($agg['commission'] / $agg['revenue_base'] * 100, 2) : 0.0;

        return $agg;
    }

    private function summarize(Collection $rows): array
    {
        $agg = $this->blank();
        foreach ($rows as $order) {
            $agg = $this->add($agg, $order);
        }

        return $this->finish($agg);
    }

    private function byPartner(Collection $rows, Collection $partners, int $limit): array
    {
        return $rows->groupBy('partner_id')->map(function (Collection $group, $partnerId) use ($partners) {
            $partner = $partners->get($partnerId);

            return ['partner_id' => (string) $partnerId, 'name' => $partner ? ($partner->legal_name ?: $partner->name) : null] + $this->summarize($group);
        })->sortByDesc('commission')->take(max(1, $limit))->values()->all();
    }

    private function byMonth(Collection $rows): array
    {
        return $rows->groupBy(fn (Order $o) => Carbon::parse($o->recognized_at ?: $o->commission_finalized_at)->format('Y-m'))->sortKeys()->map(
            fn (Collection $group, string $month) => ['month' => $month] + $this->summarize($group)
        )->values()->all();
    }

    private function byCollected(Collection $rows): array
    {
        $out = [];
        foreach (OrderCommissionService::COLLECTED_BY as $key => $label) {
            $group = $rows->filter(fn (Order $o) => $o->collected_by === $key);
            $out[] = ['collected_by' => $key, 'label' => $label] + $this->summarize($group);
        }

        return $out;
    }

    /** @param Collection<int, Settlement> $settlements */
    private function settlementSummary(Collection $settlements): array
    {
        $out = [];
        foreach (Settlement::STATUSES as $status => $label) {
            $group = $settlements->where('status', $status);
            $out[] = [
                'status' => $status, 'label' => $label, 'count' => $group->count(),
                'commission_total' => (int) $group->sum('commission_total'), 'subsidy_total' => (int) $group->sum('subsidy_total'),
                'net_amount' => (int) $group->sum('net_amount'),
            ];
        }

        return $out;
    }

    /** Công nợ đối soát đang mở (không phụ thuộc kỳ lọc): đối tác phải nộp, quá hạn, và 365home phải chi. */
    private function receivables(?string $partnerId): array
    {
        $open = Settlement::query()->whereIn('status', [Settlement::STATUS_SENT, Settlement::STATUS_DISPUTED]);
        if ($partnerId !== null) {
            $open->where('partner_id', $partnerId);
        }
        $rows = $open->get();
        $toCollect = $rows->where('net_amount', '>', 0);

        return [
            'to_collect_count'   => $toCollect->count(),
            'to_collect_amount'  => (int) $toCollect->sum('net_amount'),
            'overdue_count'      => $toCollect->filter(fn (Settlement $s) => $s->due_at !== null && $s->due_at->isPast())->count(),
            'overdue_amount'     => (int) $toCollect->filter(fn (Settlement $s) => $s->due_at !== null && $s->due_at->isPast())->sum('net_amount'),
            'to_pay_out_count'   => $rows->where('net_amount', '<', 0)->count(),
            'to_pay_out_amount'  => (int) -$rows->where('net_amount', '<', 0)->sum('net_amount'),
            'disputed_count'     => $rows->where('status', Settlement::STATUS_DISPUTED)->count(),
        ];
    }

    private function platformEscrow(?string $partnerId): array
    {
        $query = Partner::query()->where('partner_type', Partner::TYPE_HOMESTAY);
        if ($partnerId !== null) {
            $query->whereKey($partnerId);
        }
        $partners = $query->get(['id', 'escrow_balance', 'escrow_min_amount', 'escrow_suspended_at', 'escrow_terminated_at']);

        return [
            'total_balance'      => (int) $partners->sum('escrow_balance'),
            'partners_count'     => $partners->count(),
            'suspended_count'    => $partners->whereNotNull('escrow_suspended_at')->count(),
            'terminated_count'   => $partners->whereNotNull('escrow_terminated_at')->count(),
            'below_min_count'    => $partners->filter(fn (Partner $p) => $p->escrow_min_amount !== null && (int) $p->escrow_balance < (int) $p->escrow_min_amount)->count(),
        ];
    }
}
