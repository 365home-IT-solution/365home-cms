<?php

declare(strict_types=1);

namespace Modules\Dashboard\App\Services\Report;

use App\Models\Customer;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Modules\Dashboard\App\Services\OverviewService;

/**
 * BÁO CÁO KHÁCH HÀNG: khách hàng mới theo ngày, cơ cấu khách hàng (mới/quay lại), top 5 khách chi
 * tiêu nhiều nhất — trong kỳ đã chọn (mặc định 7 ngày gần nhất). Khách hàng không gắn "chi nhánh
 * xem được" nên lọc gián tiếp qua đơn hàng thuộc chi nhánh user được phép xem (customers.categories
 * chỉ ghi nhận chi nhánh gán lúc tạo, KHÔNG dùng để giới hạn quyền xem — xem ghi chú ở
 * Customer::categories()).
 *
 * "Khách mới" = có đơn ĐẦU TIÊN (MIN(orders.created_at)) rơi vào trong kỳ; "khách quay lại" = đã có
 * đơn từ TRƯỚC kỳ, vẫn phát sinh đơn mới trong kỳ.
 */
class CustomerReportService
{
    /** Số ngày tối đa để vẫn chia theo TỪNG NGÀY; dài hơn thì gộp theo THÁNG. */
    private const MAX_DAYS_FOR_DAILY_BREAKDOWN = 62;

    public static function getData($user, string $filter, ?string $customStart, ?string $customEnd, ?array $branchCategoryIds, int $limit = 5): array
    {
        [$start, $end] = OverviewService::resolveRange($filter, $customStart, $customEnd);

        $customerIds = ReportScope::orderQuery($user, $branchCategoryIds)
            ->whereBetween('created_at', [$start, $end])
            ->whereNotNull('customer_id')
            ->distinct()
            ->pluck('customer_id');

        if ($customerIds->isEmpty()) {
            return [
                'period'      => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
                'new_by_day'  => [],
                'composition' => ['new' => ['count' => 0, 'pct' => 0], 'returning' => ['count' => 0, 'pct' => 0]],
                'top_customers' => [],
            ];
        }

        // Ngày đơn ĐẦU TIÊN của mỗi khách trong danh sách này (không giới hạn theo chi nhánh —
        // 1 khách có thể đã từng đặt ở chi nhánh khác trước đó, cái cần biết là họ có PHẢI khách
        // hoàn toàn mới của hệ thống hay không).
        $firstOrderDates = \Modules\Payment\Entities\Order::query()
            ->where('exclude_from_stats', false)
            ->whereIn('customer_id', $customerIds)
            ->selectRaw('customer_id, MIN(created_at) as first_order_at')
            ->groupBy('customer_id')
            ->pluck('first_order_at', 'customer_id');

        $newCustomerIds = $firstOrderDates->filter(fn ($date) => Carbon::parse($date)->between($start, $end))->keys();
        $returningCount = $customerIds->count() - $newCustomerIds->count();

        $newByDay = static::newByDay($newCustomerIds, $firstOrderDates, $start, $end);

        $totalCustomers = $customerIds->count();
        $pct = fn (int $count) => $totalCustomers > 0 ? round(($count / $totalCustomers) * 100, 2) : 0;

        $topCustomers = static::topCustomers($user, $branchCategoryIds, $start, $end, $limit);

        return [
            'period'     => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
            'new_by_day' => $newByDay,
            'composition' => [
                'new'       => ['count' => $newCustomerIds->count(), 'pct' => $pct($newCustomerIds->count())],
                'returning' => ['count' => $returningCount, 'pct' => $pct($returningCount)],
            ],
            'top_customers' => $topCustomers,
        ];
    }

    private static function newByDay($newCustomerIds, $firstOrderDates, Carbon $start, Carbon $end): array
    {
        $dailyCounts = [];
        foreach ($newCustomerIds as $customerId) {
            $date = Carbon::parse($firstOrderDates[$customerId])->toDateString();
            $dailyCounts[$date] = ($dailyCounts[$date] ?? 0) + 1;
        }

        $totalDays = max(1, (int) $start->diffInDays($end) + 1);
        $byMonth   = $totalDays > self::MAX_DAYS_FOR_DAILY_BREAKDOWN;

        $series = [];
        if ($byMonth) {
            $monthCounts = [];
            foreach ($dailyCounts as $date => $count) {
                $key = substr($date, 0, 7);
                $monthCounts[$key] = ($monthCounts[$key] ?? 0) + $count;
            }
            foreach (CarbonPeriod::create($start->copy()->startOfMonth(), '1 month', $end) as $m) {
                $key = $m->format('Y-m');
                $series[] = ['date' => $key, 'count' => $monthCounts[$key] ?? 0];
            }
        } else {
            foreach (CarbonPeriod::create($start, '1 day', $end) as $day) {
                $series[] = ['date' => $day->toDateString(), 'count' => $dailyCounts[$day->toDateString()] ?? 0];
            }
        }

        return $series;
    }

    private static function topCustomers($user, ?array $branchCategoryIds, Carbon $start, Carbon $end, int $limit): array
    {
        $rows = ReportScope::orderQuery($user, $branchCategoryIds)
            ->where('status', 'paid')
            ->whereBetween('created_at', [$start, $end])
            ->whereNotNull('customer_id')
            ->selectRaw('customer_id, SUM(COALESCE(amount, full_amount)) as spending')
            ->groupBy('customer_id')
            ->orderByDesc('spending')
            ->limit($limit)
            ->get();

        $customers = Customer::whereIn('id', $rows->pluck('customer_id'))->pluck('fullname', 'id');

        return $rows->map(fn ($r) => [
            'customer_id' => $r->customer_id,
            'name'        => $customers[$r->customer_id] ?? 'N/A',
            'spending'    => (int) $r->spending,
        ])->toArray();
    }
}
