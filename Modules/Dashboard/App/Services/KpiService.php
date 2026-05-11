<?php

declare(strict_types=1);

namespace Modules\Dashboard\App\Services;

use Carbon\Carbon;
use Modules\Payment\Entities\Order;

class KpiService
{
    public static function getData(string $period, $user = null, ?string $customStart = null, ?string $customEnd = null): array
    {
        if ($user === null) {
            $user = auth()->user();
        }

        $query = Order::query();
        if ($user && ! $user->isSuperAdmin()) {
            $allCategoryIds = $user->allowedCategoryIds();
            if (empty($allCategoryIds)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('category_id', $allCategoryIds);
            }
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
        } elseif ($period === 'ytd') {
            $start = Carbon::today()->startOfYear()->startOfDay();
            $end   = Carbon::now()->endOfDay();
        } else {
            $days  = match ($period) { '7d' => 7, '90d' => 90, default => 30 };
            $end   = Carbon::now()->endOfDay();
            $start = Carbon::now()->subDays($days - 1)->startOfDay();
        }

        $periodDays    = max(1, (int) $start->diffInDays($end));
        $prevEnd       = $start->copy()->subSecond();
        $prevStart     = $prevEnd->copy()->subDays($periodDays - 1)->startOfDay();
        $dateRange     = $start->format('j/n') . ' – ' . $end->format('j/n');
        $prevDateRange = $prevStart->format('j/n') . ' – ' . $prevEnd->format('j/n');

        $currentQuery  = (clone $query)->whereBetween('created_at', [$start, $end]);
        $previousQuery = (clone $query)->whereBetween('created_at', [$prevStart, $prevEnd]);

        $total     = (clone $currentQuery)->count();
        $prevTotal = (clone $previousQuery)->count();
        $totalDelta = $prevTotal > 0 ? round((($total - $prevTotal) / $prevTotal) * 100, 1) : 0;

        $revenue     = (clone $currentQuery)->where('status', 'paid')->sum('amount')
                     + (clone $currentQuery)->where('status', 'deposit')->whereNotNull('money_deposit')->sum('money_deposit');
        $prevRevenue = (clone $previousQuery)->where('status', 'paid')->sum('amount')
                     + (clone $previousQuery)->where('status', 'deposit')->whereNotNull('money_deposit')->sum('money_deposit');
        $revenueDelta = $prevRevenue > 0 ? round((($revenue - $prevRevenue) / $prevRevenue) * 100, 1) : 0;

        $revenuePayos     = (clone $currentQuery)->where('status', 'paid')->where('payment_method', 'PayOS')->sum('amount');
        $prevRevenuePayos = (clone $previousQuery)->where('status', 'paid')->where('payment_method', 'PayOS')->sum('amount');
        $revenuePayosDelta = $prevRevenuePayos > 0 ? round((($revenuePayos - $prevRevenuePayos) / $prevRevenuePayos) * 100, 1) : 0;

        $revenueCod     = (clone $currentQuery)->where('status', 'paid')->where('payment_method', 'cod')->sum('amount');
        $prevRevenueCod = (clone $previousQuery)->where('status', 'paid')->where('payment_method', 'cod')->sum('amount');
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
            'revenuePayos', 'revenuePayosDelta',
            'revenueCod', 'revenueCodDelta',
            'revenueDepositPayos', 'revenueDepositPayosDelta',
            'revenueDepositCod', 'revenueDepositCodDelta',
            'paidCount', 'paidDelta',
            'dateRange', 'prevDateRange'
        );
    }
}
