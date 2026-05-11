<?php

namespace Modules\BladeThemeV1\Traits;

use Illuminate\Support\Collection;
use Carbon\Carbon;

trait HasTimeSlots
{
    /**
     * Tạo danh sách ngày theo quy tắc mới:
     * - Ngày hôm kia trở về trước → ẩn ngay
     * - Ngày hôm qua + chưa tới 7:30 sáng → vẫn hiển thị
     * - Ngày hôm qua + đã qua 7:30 sáng → ẩn
     * - Hôm nay và tương lai → hiển thị bình thường
     */
    protected function generateDateRange($days = 31): Collection
    {
        $shortDays = [
            'Sun' => 'CN', 
            'Mon' => 'T2', 
            'Tue' => 'T3', 
            'Wed' => 'T4',
            'Thu' => 'T5', 
            'Fri' => 'T6', 
            'Sat' => 'T7',
        ];

        $now = Carbon::now('Asia/Ho_Chi_Minh');
        $today = $now->copy()->startOfDay();
        $cutoffTime = '07:30';

        $dates = [];

        // Bắt đầu kiểm tra từ hôm kia để lọc đúng logic
        $current = $today->copy()->subDays(2);

        while (count($dates) < $days) {
            if ($this->shouldShowDate($current, $now, $today, $cutoffTime)) {
                $dates[] = [
                    'day'          => $current->isSameDay($today) ? 'Hôm nay' : $shortDays[$current->format('D')],
                    'date'         => $current->format('d-m-Y'),
                    'carbon_date'  => $current->copy(),
                    'is_today'     => $current->isSameDay($today),
                    'is_yesterday' => $current->isYesterday(),
                ];
            }
            $current->addDay();
        }

        return collect($dates);
    }

    /**
     * Logic quyết định ngày có được hiển thị hay không
     */
    protected function shouldShowDate(Carbon $date, Carbon $now, Carbon $today, string $cutoffTime): bool
    {
        // Ngày hôm kia trở về trước → luôn ẩn
        if ($date->lt($today->copy()->subDays(2)->startOfDay())) {
            return false;
        }

        // Ngày hôm qua
        if ($date->isYesterday()) {
            $cutoff = $today->copy()->setTimeFromTimeString($cutoffTime . ':00');

            // Nếu đã qua 7:30 sáng hôm nay → ẩn ngày hôm qua
            if ($now->gte($cutoff)) {
                return false;
            }

            // Chưa tới 7:30 sáng → vẫn cho hiển thị ngày hôm qua
            return true;
        }

        // Hôm nay và các ngày sau → luôn hiển thị
        return $date->gte($today);
    }

    protected function prepareTimeSlots($product): Collection
    {
        return collect($product->time_slots ?? [])->map(function ($slot) {
            return $slot;
        });
    }

    protected function isSlotValidForDate($slot, $carbonDate): bool
    {
        if (isset($slot['start_at']) && isset($slot['end_at'])) {
            return $carbonDate->between($slot['start_at'], $slot['end_at'], true);
        }

        return true;
    }
}