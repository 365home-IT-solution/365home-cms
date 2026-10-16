<?php

declare(strict_types=1);

namespace App\Services;

use Modules\Product\App\Models\TimeSlot;

/**
 * CÁCH RANDOM để tạo khung giờ + giá gợi ý cho bảng giá, đối tác không phải thêm và đặt giá từng khung
 * (cách còn lại là chọn trong bảng giá mẫu — PriceTemplateService). Dựng bộ ngẫu nhiên trải 1 ngày từ
 * sáng đến tối + 1 khung qua đêm, gắn vào time_slots của đối tác (dùng lại khung trùng giờ, chưa có
 * thì tạo khung riêng).
 *
 * Quy tắc random:
 *  - các khung ban ngày nối tiếp từ 06:00–08:00, độ dài xấp xỉ nhau (lệch ±15%), làm tròn 5 phút;
 *  - khung cuối là khung qua đêm: bắt đầu 21:00–23:00, kết thúc đúng giờ khung đầu tiên của hôm sau
 *    (VD 07:00 hôm nay → 07:00 hôm sau là 1 ngày tròn);
 *  - 2 khung liền nhau cách nhau 15–30 phút (bước 5 phút).
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
     * RANDOM: lịch $count khung giờ + giá ngẫu nhiên, đã gắn với time_slots của đối tác. Giá
     * ban ngày theo độ dài khung 100.000–300.000đ, qua đêm 250.000–450.000đ, làm tròn 10.000đ.
     *
     * @return array{slots: list<array{timeslot_id:int,price:int,over_night:bool,is_new:bool}>, created: int}
     */
    public function pick(?string $partnerId, int $count = self::DEFAULT_COUNT): array
    {
        $rows = array_map(function (array $slot) {
            $hours = (($this->toMinutes($slot['end']) - $this->toMinutes($slot['start'])) + 1440) % 1440 / 60;

            return $slot + ['price' => $slot['over_night']
                ? intdiv(random_int(250000, 450000), 10000) * 10000
                : max(100000, min(300000, intdiv((int) ($hours * random_int(40000, 70000)), 10000) * 10000)),
            ];
        }, $this->schedule($count));

        return $this->resolve($partnerId, $rows);
    }

    /**
     * Gắn từng khung giờ vào time_slots của đối tác: trùng giờ với khung đã dùng được (hệ thống hoặc
     * riêng) thì dùng lại, chưa có thì tạo khung riêng của đối tác.
     *
     * @param list<array{start:string,end:string,over_night:bool,price:int}> $rows
     * @return array{slots: list<array{timeslot_id:int,price:int,over_night:bool,is_new:bool}>, created: int}
     */
    private function resolve(?string $partnerId, array $rows): array
    {
        $out     = [];
        $created = 0;

        foreach ($rows as $slot) {
            $existing = $this->findOrCreateSlot($partnerId, $slot['start'], $slot['end'], (bool) $slot['over_night']);
            $isNew    = $existing->wasRecentlyCreated;

            if ($isNew) {
                $created++;
            }

            $out[] = [
                'timeslot_id' => (int) $existing->id,
                'price'       => (int) $slot['price'],
                'over_night'  => (bool) $slot['over_night'],
                'is_new'      => $isNew,
            ];
        }

        return ['slots' => $out, 'created' => $created];
    }

    /**
     * Khung giờ trùng giờ bắt đầu/kết thúc/qua đêm mà đối tác dùng được (hệ thống hoặc riêng); null nếu
     * chưa có. Khung hệ thống được ưu tiên. Không tạo gì.
     */
    public function findSlot(?string $partnerId, string $start, string $end, bool $overNight): ?TimeSlot
    {
        return TimeSlot::usableBy($partnerId)
            ->where(fn ($q) => $q->whereNull('type')->orWhere('type', '!=', 'date'))
            ->where('over_night', $overNight)
            ->where('start_time', substr($start, 0, 5) . ':00')
            ->where('end_time', substr($end, 0, 5) . ':00')
            ->orderByRaw('partner_id IS NULL DESC') // ưu tiên khung hệ thống
            ->first();
    }

    /** Như findSlot() nhưng chưa có thì tạo khung riêng của đối tác (không bao giờ tạo trùng giờ). */
    public function findOrCreateSlot(?string $partnerId, string $start, string $end, bool $overNight): TimeSlot
    {
        $start = substr($start, 0, 5);
        $end   = substr($end, 0, 5);

        return $this->findSlot($partnerId, $start, $end, $overNight)
            ?? TimeSlot::create([
                'partner_id' => $partnerId,
                'start_time' => $start,
                'end_time'   => $end,
                'over_night' => $overNight,
                'label'      => "{$start} - {$end}" . ($overNight ? ' (Qua đêm)' : ''),
                'type'       => 'time',
            ]);
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
