<?php

declare(strict_types=1);

namespace Modules\Dashboard\App\Services;

use Carbon\Carbon;
use Modules\Payment\Entities\Order;

class KpiService
{
    public static function getData(string $period, $user = null, ?string $customStart = null, ?string $customEnd = null, ?array $branchCategoryIds = null): array
    {
        if ($user === null) {
            $user = auth()->user();
        }

        $query = Order::query()->where('exclude_from_stats', false);
        if ($user && ! $user->isSuperAdmin()) {
            // BelongsToPartner chỉ tự lọc partner_id khi chạy TRONG Filament panel
            // (AdminPanelContext::isActive() — xem app/Models/Concerns/BelongsToPartner.php).
            // Route API token (Sanctum, KHÔNG qua panel) không có global scope này nên PHẢI lọc
            // tường minh ở đây, nếu không tài khoản chủ đối tác (không có bản ghi branchPermissions
            // riêng nên allowedCategoryIds() rỗng) sẽ thấy dữ liệu của TẤT CẢ đối tác khác.
            if (empty($user->partner_id)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->where('partner_id', $user->partner_id);

                $allCategoryIds = $user->allowedCategoryIds();
                if (! empty($allCategoryIds)) {
                    $query->whereIn('category_id', $allCategoryIds);
                }
            }
        }

        if ($branchCategoryIds !== null) {
            $query->whereIn('category_id', $branchCategoryIds);
        }

        if ($period === 'custom') {
            $start = $customStart
                ? Carbon::parse($customStart)->startOfDay()
                : Carbon::now()->subDays(29)->startOfDay();
            $end = $customEnd
                ? Carbon::parse($customEnd)->endOfDay()
                : Carbon::now()->endOfDay();
            if ($start->gt($end)) {
                [$start, $end] = [$end, $start];
            }
        } elseif ($period === 'today') {
            $start = Carbon::today()->startOfDay();
            $end   = Carbon::today()->endOfDay();
        } elseif ($period === 'yesterday') {
            $start = Carbon::yesterday()->startOfDay();
            $end   = Carbon::yesterday()->endOfDay();
        } elseif ($period === 'this_month') {
            $start = Carbon::now()->startOfMonth()->startOfDay();
            $end   = Carbon::now()->endOfDay();
        } elseif ($period === 'last_month') {
            $start = Carbon::now()->subMonthNoOverflow()->startOfMonth()->startOfDay();
            $end   = Carbon::now()->subMonthNoOverflow()->endOfMonth()->endOfDay();
        } elseif ($period === 'this_year' || $period === 'ytd') {
            $start = Carbon::today()->startOfYear()->startOfDay();
            $end   = Carbon::now()->endOfDay();
        } elseif ($period === 'last_year') {
            $start = Carbon::now()->subYear()->startOfYear()->startOfDay();
            $end   = Carbon::now()->subYear()->endOfYear()->endOfDay();
        } else {
            $days  = match ($period) { '7d' => 7, '90d' => 90, default => 30 };
            $end   = Carbon::now()->endOfDay();
            $start = Carbon::now()->subDays($days - 1)->startOfDay();
        }

        // Kỳ so sánh: tháng này → cùng số ngày đầu tháng trước; tháng trước/năm trước → cùng kỳ
        // năm ngoái; các kỳ còn lại (today/yesterday/Nd/custom/this_year) → liền trước cùng độ dài.
        if ($period === 'this_month') {
            $prevStart = Carbon::now()->subMonthNoOverflow()->startOfMonth()->startOfDay();
            $prevEnd   = $prevStart->copy()->addDays(Carbon::now()->day - 1)->endOfDay();
        } elseif ($period === 'last_month') {
            $prevStart = Carbon::now()->subMonths(2)->startOfMonth()->startOfDay();
            $prevEnd   = Carbon::now()->subMonths(2)->endOfMonth()->endOfDay();
        } elseif ($period === 'last_year') {
            $prevStart = Carbon::now()->subYears(2)->startOfYear()->startOfDay();
            $prevEnd   = Carbon::now()->subYears(2)->endOfYear()->endOfDay();
        } else {
            $periodDays = max(1, (int) $start->diffInDays($end));
            $prevEnd    = $start->copy()->subSecond();
            $prevStart  = $prevEnd->copy()->subDays($periodDays - 1)->startOfDay();
        }

        $dateRange     = $start->format('j/n') . ' – ' . $end->format('j/n');
        $prevDateRange = $prevStart->format('j/n') . ' – ' . $prevEnd->format('j/n');

        $currentQuery  = (clone $query)->whereBetween('created_at', [$start, $end]);
        $previousQuery = (clone $query)->whereBetween('created_at', [$prevStart, $prevEnd]);

        $total     = (clone $currentQuery)->count();
        $prevTotal = (clone $previousQuery)->count();
        $totalDelta = $prevTotal > 0 ? round((($total - $prevTotal) / $prevTotal) * 100, 1) : 0;

        $revenue     = static::sumOrderAmount((clone $currentQuery)->where('status', 'paid'))
                     + (clone $currentQuery)->where('status', 'deposit')->whereNotNull('money_deposit')->sum('money_deposit');
        $prevRevenue = static::sumOrderAmount((clone $previousQuery)->where('status', 'paid'))
                     + (clone $previousQuery)->where('status', 'deposit')->whereNotNull('money_deposit')->sum('money_deposit');
        $revenueDelta = $prevRevenue > 0 ? round((($revenue - $prevRevenue) / $prevRevenue) * 100, 1) : 0;

        // 'Tổng giá gốc' (full_amount, KHÔNG đổi kể cả có phát sinh sau này) đặt cạnh 'Doanh thu'
        // (amount, tổng thực thu cuối cùng) để dễ so sánh — cùng phạm vi (chỉ đơn 'paid'); chênh
        // lệch giữa 2 số chính là tổng phụ phí phát sinh trong kỳ.
        $revenuePaidOnly     = static::sumOrderAmount((clone $currentQuery)->where('status', 'paid'));
        $prevRevenuePaidOnly = static::sumOrderAmount((clone $previousQuery)->where('status', 'paid'));
        $revenueOriginal     = (int) ((clone $currentQuery)->where('status', 'paid')->sum('full_amount'));
        $prevRevenueOriginal = (int) ((clone $previousQuery)->where('status', 'paid')->sum('full_amount'));
        $revenueOriginalDelta = $prevRevenueOriginal > 0
            ? round((($revenueOriginal - $prevRevenueOriginal) / $prevRevenueOriginal) * 100, 1)
            : 0;
        $revenueExtraCharge = $revenuePaidOnly - $revenueOriginal;

        $revenuePayos     = static::sumOrderAmount((clone $currentQuery)->where('status', 'paid')->where('payment_method', 'PayOS'));
        $prevRevenuePayos = static::sumOrderAmount((clone $previousQuery)->where('status', 'paid')->where('payment_method', 'PayOS'));
        $revenuePayosDelta = $prevRevenuePayos > 0 ? round((($revenuePayos - $prevRevenuePayos) / $prevRevenuePayos) * 100, 1) : 0;

        $revenueCod     = static::sumOrderAmount((clone $currentQuery)->where('status', 'paid')->where('payment_method', 'cod'));
        $prevRevenueCod = static::sumOrderAmount((clone $previousQuery)->where('status', 'paid')->where('payment_method', 'cod'));
        $revenueCodDelta = $prevRevenueCod > 0 ? round((($revenueCod - $prevRevenueCod) / $prevRevenueCod) * 100, 1) : 0;

        $revenueDepositPayos     = (clone $currentQuery)->where('status', 'deposit')->where('payment_method', 'PayOS')->sum('money_deposit');
        $prevRevenueDepositPayos = (clone $previousQuery)->where('status', 'deposit')->where('payment_method', 'PayOS')->sum('money_deposit');
        $revenueDepositPayosDelta = $prevRevenueDepositPayos > 0 ? round((($revenueDepositPayos - $prevRevenueDepositPayos) / $prevRevenueDepositPayos) * 100, 1) : 0;

        $revenueDepositCod     = (clone $currentQuery)->where('status', 'deposit')->where('payment_method', 'cod')->sum('money_deposit');
        $prevRevenueDepositCod = (clone $previousQuery)->where('status', 'deposit')->where('payment_method', 'cod')->sum('money_deposit');
        $revenueDepositCodDelta = $prevRevenueDepositCod > 0 ? round((($revenueDepositCod - $prevRevenueDepositCod) / $prevRevenueDepositCod) * 100, 1) : 0;

        $paidCount = (clone $currentQuery)->whereIn('status', ['paid', 'completed'])->count();
        $prevPaid  = (clone $previousQuery)->whereIn('status', ['paid', 'completed'])->count();
        $paidDelta = $prevPaid > 0 ? round((($paidCount - $prevPaid) / $prevPaid) * 100, 1) : 0;

        return compact(
            'total', 'totalDelta',
            'revenue', 'revenueDelta',
            'revenueOriginal', 'revenueOriginalDelta', 'revenueExtraCharge',
            'revenuePayos', 'revenuePayosDelta',
            'revenueCod', 'revenueCodDelta',
            'revenueDepositPayos', 'revenueDepositPayosDelta',
            'revenueDepositCod', 'revenueDepositCodDelta',
            'paidCount', 'paidDelta',
            'dateRange', 'prevDateRange'
        );
    }

    /**
     * Tổng tiền đơn paid: ưu tiên 'amount' (tổng thực thu cuối cùng, gồm cả phụ phí phát sinh
     * sau cọc nếu có) — 'full_amount' chỉ là tổng giá gốc CỐ ĐỊNH lúc đặt, fallback khi amount rỗng.
     */
    private static function sumOrderAmount($query): int
    {
        return (int) ($query->selectRaw('SUM(COALESCE(amount, full_amount)) as total')->value('total') ?? 0);
    }
}
