<?php

namespace Modules\Motrak\App\Support;

use Carbon\Carbon;

/**
 * Giải mã thời gian Motrak (SUY LUẬN từ dữ liệu mẫu — cần Motrak xác nhận, xem config motrak.time):
 *  - utcTicks: .NET ticks (đơn vị 100 ns kể từ 0001-01-01 00:00 UTC) → giờ chuẩn.
 *  - time / lastStopped: giây kể từ 2010-01-01 00:00 theo giờ địa phương UTC+7 → chỉ dùng dự phòng khi thiếu utcTicks.
 * Mọi kết quả trả về ở múi giờ của ứng dụng để lưu/hiển thị nhất quán.
 */
class MotrakTime
{
    /** Số ticks của 1970-01-01 00:00 UTC. */
    public const UNIX_EPOCH_TICKS = 621355968000000000;

    public const TICKS_PER_SECOND = 10000000;

    public static function fromUtcTicks(mixed $ticks): ?Carbon
    {
        if ($ticks === null || ! is_numeric($ticks) || (int) $ticks <= self::UNIX_EPOCH_TICKS) {
            return null;
        }

        $unix = intdiv((int) $ticks - self::UNIX_EPOCH_TICKS, self::TICKS_PER_SECOND);

        return Carbon::createFromTimestampUTC($unix)->setTimezone(config('app.timezone'));
    }

    public static function toUtcTicks(Carbon $time): int
    {
        return $time->copy()->utc()->getTimestamp() * self::TICKS_PER_SECOND + self::UNIX_EPOCH_TICKS;
    }

    public static function fromDeviceSeconds(mixed $seconds): ?Carbon
    {
        if ($seconds === null || ! is_numeric($seconds) || (int) $seconds < 0) {
            return null;
        }

        $offset = (int) config('motrak.time.device_utc_offset_hours', 7);
        $base = Carbon::parse((string) config('motrak.time.device_epoch', '2010-01-01 00:00:00'), 'UTC')->subHours($offset);

        return $base->addSeconds((int) $seconds)->setTimezone(config('app.timezone'));
    }

    /**
     * Giờ GPS của 1 bản ghi: ưu tiên utcTicks, thiếu thì dùng "time".
     *
     * @return array{time: ?Carbon, ticks: ?int, source: string} source: utc_ticks | device_time | none
     */
    public static function resolve(mixed $utcTicks, mixed $deviceSeconds): array
    {
        if ($t = self::fromUtcTicks($utcTicks)) {
            return ['time' => $t, 'ticks' => (int) $utcTicks, 'source' => 'utc_ticks'];
        }
        if ($t = self::fromDeviceSeconds($deviceSeconds)) {
            return ['time' => $t, 'ticks' => self::toUtcTicks($t), 'source' => 'device_time'];
        }

        return ['time' => null, 'ticks' => null, 'source' => 'none'];
    }
}
