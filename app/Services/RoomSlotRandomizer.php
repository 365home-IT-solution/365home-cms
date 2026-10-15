<?php

declare(strict_types=1);

namespace App\Services;

use Modules\Product\App\Models\TimeSlot;

/**
 * Sinh NGẪU NHIÊN bộ khung giờ + giá cho phòng theo khung giờ, để đối tác không phải thêm và đặt giá
 * từng khung. Dùng chung cho dữ liệu mẫu (PartnerSampleDataGenerator), form Bảng giá ở Filament và API
 * bảng giá.
 *
 * Bộ khung giờ trải dài CẢ MỘT NGÀY, từ sáng đến tối, kèm 1 khung qua đêm:
 *  - các khung ban ngày nối tiếp từ 06:00–08:00 đến trước khung qua đêm, độ dài xấp xỉ nhau
 *    (lệch ±15%), làm tròn 5 phút;
 *  - khung cuối là khung qua đêm: bắt đầu 21:00–23:00, kết thúc đúng giờ khung đầu tiên bắt đầu của
 *    hôm sau (VD 07:00 hôm nay → 07:00 hôm sau là 1 ngày tròn);
 *  - 2 khung liền nhau cách nhau 15–30 phút (bước 5 phút).
 * Khung giờ trùng giờ với khung đối tác đã dùng được (hệ thống hoặc riêng) thì dùng lại, chỉ tạo mới
 * khi chưa có.
 */
class RoomSlotRandomizer
{
    public const MIN_COUNT = 2; // ít nhất 1 khung ban ngày + khung qua đêm
    public const MAX_COUNT = 8;
    public const DEFAULT_COUNT = 5;

    /**
     * Dựng lịch $count khung giờ (chưa ghi DB).
     *
     * @return list<array{start:string,end:string,over_night:bool}> theo thứ tự thời gian, "H:i"
     */
    public function schedule(int $count): array
    {
        $count = max(self::MIN_COUNT, min(self::MAX_COUNT, $count));

        $days       = $count - 1; // số khung ban ngày; khung cuối là khung qua đêm
        $dayStart   = 5 * random_int(6 * 12, 8 * 12);    // 06:00–08:00
        $nightStart = 5 * random_int(21 * 12, 23 * 12);  // 21:00–23:00
        $nightEnd   = $dayStart + 1440;                  // 1 ngày tròn: sáng hôm nay → cùng giờ sáng hôm sau

        $gaps      = array_map(fn () => 5 * random_int(3, 6), range(1, $count - 1)); // 15–30 phút
        $lastGap   = array_pop($gaps);                       // khoảng cách trước khung qua đêm
        $dayEnd    = $nightStart - $lastGap;
        $usable    = $dayEnd - $dayStart - array_sum($gaps);
        $weights   = array_map(fn () => random_int(85, 115), range(1, $days));
        $weightSum = array_sum($weights);

        $slots  = [];
        $cursor = $dayStart;

        foreach ($weights as $i => $weight) {
            $end = $i === $days - 1
                ? $dayEnd
                : $cursor + (int) (round($usable * $weight / $weightSum / 5) * 5);

            $slots[] = ['start' => $this->clock($cursor), 'end' => $this->clock($end), 'over_night' => false];
            $cursor  = $end + ($gaps[$i] ?? 0);
        }

        $slots[] = ['start' => $this->clock($nightStart), 'end' => $this->clock($nightEnd), 'over_night' => true];

        return $slots;
    }

    /**
     * Lịch $count khung giờ + giá ngẫu nhiên, đã gắn với time_slots của đối tác (dùng lại khung trùng
     * giờ, chưa có thì tạo khung riêng của đối tác). Giá theo độ dài khung, 100.000–300.000đ, làm tròn
     * 10.000đ.
     *
     * @return array{slots: list<array{timeslot_id:int,price:int,over_night:bool,is_new:bool}>, created: int}
     */
    public function pick(?string $partnerId, int $count = self::DEFAULT_COUNT): array
    {
        $rows    = [];
        $created = 0;

        foreach ($this->schedule($count) as $slot) {
            $isNew    = false;
            $existing = TimeSlot::usableBy($partnerId)
                ->where(fn ($q) => $q->whereNull('type')->orWhere('type', '!=', 'date'))
                ->where('over_night', $slot['over_night'])
                ->where('start_time', $slot['start'] . ':00')
                ->where('end_time', $slot['end'] . ':00')
                ->orderByRaw('partner_id IS NULL DESC') // ưu tiên khung hệ thống
                ->first();

            if (! $existing) {
                $existing = TimeSlot::create([
                    'partner_id' => $partnerId,
                    'start_time' => $slot['start'],
                    'end_time'   => $slot['end'],
                    'over_night' => $slot['over_night'],
                    'label'      => "{$slot['start']} - {$slot['end']}" . ($slot['over_night'] ? ' (Qua đêm)' : ''),
                    'type'       => 'time',
                ]);
                $created++;
                $isNew = true;
            }

            $hours = (($this->toMinutes($slot['end']) - $this->toMinutes($slot['start'])) + 1440) % 1440 / 60;

            $rows[] = [
                'timeslot_id' => (int) $existing->id,
                'price'       => $slot['over_night']
                    ? intdiv(random_int(250000, 450000), 10000) * 10000
                    : max(100000, min(300000, intdiv((int) ($hours * random_int(40000, 70000)), 10000) * 10000)),
                'over_night'  => $slot['over_night'],
                'is_new'      => $isNew,
            ];
        }

        return ['slots' => $rows, 'created' => $created];
    }

    private function clock(int $minutes): string
    {
        $minutes %= 1440;

        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    private function toMinutes(string $clock): int
    {
        [$h, $m] = array_map('intval', explode(':', $clock));

        return $h * 60 + $m;
    }
}
