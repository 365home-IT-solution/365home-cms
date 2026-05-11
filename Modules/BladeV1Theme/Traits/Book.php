<?php

namespace Modules\BladeThemeV1\Traits;

use Carbon\Carbon;

trait Book
{
    public function getDatesForOneMonth(): array
    {
        $today = Carbon::today();
        $endDate = $today->copy()->addMonthNoOverflow(); // đúng 1 tháng sau

        $dates = [];
        while ($today->lte($endDate)) {
            $dates[] = [
                'day' => $this->getVietnameseDayName($today),
                'date' => $today->format('d-m-Y'),
                'is_today' => $today->isToday(),
            ];
            $today->addDay();
        }

        return $dates;
    }

    /**
     * Lấy tên thứ bằng tiếng Việt
     */
    protected function getVietnameseDayName(Carbon $date): string
    {
        $days = [
            0 => 'Chủ nhật',
            1 => 'Thứ 2',
            2 => 'Thứ 3',
            3 => 'Thứ 4',
            4 => 'Thứ 5',
            5 => 'Thứ 6',
            6 => 'Thứ 7',
        ];
        if ($date->isToday()) {
            return 'Hôm nay';
        }
        return $days[$date->dayOfWeek];
    }
}