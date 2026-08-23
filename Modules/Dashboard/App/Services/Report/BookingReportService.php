<?php

declare(strict_types=1);

namespace Modules\Dashboard\App\Services\Report;

use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Modules\Dashboard\App\Services\OverviewService;

/**
 * BÁO CÁO ĐẶT PHÒNG: đặt phòng theo thời gian, top 5 nhân viên đặt phòng nhiều nhất, tỷ lệ đơn bị
 * huỷ theo lý do — trong kỳ đã chọn (mặc định 7 ngày gần nhất). Lọc theo created_at của đơn.
 */
class BookingReportService
{
    private const CANCELLED_STATUSES = ['cancelled_payment', 'failed'];

    /** Số ngày tối đa để vẫn chia theo TỪNG NGÀY; dài hơn thì gộp theo THÁNG. */
    private const MAX_DAYS_FOR_DAILY_BREAKDOWN = 62;

    public static function getData($user, string $filter, ?string $customStart, ?string $customEnd, ?array $branchCategoryIds, int $limit = 5): array
    {
        [$start, $end] = OverviewService::resolveRange($filter, $customStart, $customEnd);

        $baseQuery = ReportScope::orderQuery($user, $branchCategoryIds)
            ->whereBetween('created_at', [$start, $end]);

        return [
            'period'            => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
            'bookings_over_time' => static::bookingsOverTime(clone $baseQuery, $start, $end),
            'top_staff'          => static::topStaff(clone $baseQuery, $limit),
            'cancellation'       => static::cancellation(clone $baseQuery),
        ];
    }

    private static function bookingsOverTime($query, Carbon $start, Carbon $end): array
    {
        $totalDays = max(1, (int) $start->diffInDays($end) + 1);
        $byMonth   = $totalDays > self::MAX_DAYS_FOR_DAILY_BREAKDOWN;

        if ($byMonth) {
            $rows = (clone $query)
                ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as d, COUNT(*) as c")
                ->groupBy('d')
                ->pluck('c', 'd');

            $series = [];
            foreach (CarbonPeriod::create($start->copy()->startOfMonth(), '1 month', $end) as $m) {
                $key = $m->format('Y-m');
                $series[] = ['date' => $key, 'count' => (int) ($rows[$key] ?? 0)];
            }

            return $series;
        }

        $rows = (clone $query)
            ->selectRaw('DATE(created_at) as d, COUNT(*) as c')
            ->groupBy('d')
            ->pluck('c', 'd');

        $series = [];
        foreach (CarbonPeriod::create($start, '1 day', $end) as $day) {
            $series[] = ['date' => $day->toDateString(), 'count' => (int) ($rows[$day->toDateString()] ?? 0)];
        }

        return $series;
    }

    private static function topStaff($query, int $limit): array
    {
        $rows = (clone $query)
            ->whereNotNull('created_by')
            ->selectRaw('created_by, COUNT(*) as bookings_count')
            ->groupBy('created_by')
            ->orderByDesc('bookings_count')
            ->limit($limit)
            ->get();

        $staffIds = $rows->pluck('created_by');
        $names    = \App\Models\User::whereIn('id', $staffIds)->pluck('fullname', 'id');

        return $rows->map(fn ($r) => [
            'user_id'        => $r->created_by,
            'name'           => $names[$r->created_by] ?? 'N/A',
            'bookings_count' => (int) $r->bookings_count,
        ])->toArray();
    }

    private static function cancellation($query): array
    {
        $total = (clone $query)->count();

        $noShow = (clone $query)
            ->whereIn('status', self::CANCELLED_STATUSES)
            ->where('cancel_reason', 'no_show')
            ->count();

        $otherCancel = (clone $query)
            ->whereIn('status', self::CANCELLED_STATUSES)
            ->where(fn ($q) => $q->where('cancel_reason', 'other')->orWhereNull('cancel_reason'))
            ->count();

        $stayed = (clone $query)->whereNotNull('checked_in_at')->count();

        $cancelledTotal = $noShow + $otherCancel;

        return [
            'total_bookings'    => $total,
            'no_show_count'     => $noShow,
            'other_cancel_count' => $otherCancel,
            'stayed_count'      => $stayed,
            'cancelled_rate_pct' => $total > 0 ? round(($cancelledTotal / $total) * 100, 2) : 0,
        ];
    }
}
