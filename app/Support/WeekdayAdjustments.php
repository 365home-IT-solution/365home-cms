<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\Carbon;

/**
 * TĂNG/GIẢM GIÁ THEO THỨ của bộ giá (price_board_sheets / price_board_items.weekday_adjustments): giữ NGUYÊN giá
 * gốc theo loại phòng, chỉ tăng vào các thứ chọn (thường là Thứ 7, Chủ nhật).
 *
 * Mỗi dòng: { days: [ISO 1 = Thứ 2 … 7 = Chủ nhật], mode: percent|fixed, value }.
 * Dùng được cho cả phòng theo ngày (trên giá một đêm) lẫn phòng theo giờ (trên giá từng khung giờ).
 * Loại trừ với "giá theo từng thứ" (weekday_prices) vì cả hai cùng quyết định giá theo thứ.
 */
class WeekdayAdjustments
{
    public const MAX_ROWS = 7;
    public const MODES    = ['percent', 'fixed'];

    /**
     * Dòng áp cho ngày $date; null = không đổi giá.
     *
     * @param array<int,array<string,mixed>>|null $rows
     * @return array{mode: string, value: float}|null
     */
    public static function match(?array $rows, Carbon|string $date): ?array
    {
        if (! $rows) {
            return null;
        }

        $dow = Carbon::parse($date)->dayOfWeekIso;

        foreach ($rows as $row) {
            $mode  = $row['mode'] ?? null;
            $value = $row['value'] ?? null;

            if ($value === null || $value === '' || ! in_array($mode, self::MODES, true)) {
                continue;
            }

            if (in_array($dow, array_map('intval', (array) ($row['days'] ?? [])), true)) {
                return ['mode' => $mode, 'value' => (float) $value];
            }
        }

        return null;
    }

    /** Giá sau khi tăng/giảm theo thứ (không có dòng khớp → giữ nguyên $base). */
    public static function apply(?array $rows, Carbon|string $date, float $base): float
    {
        $rule = self::match($rows, $date);

        return $rule === null ? $base : HolidayPrices::apply($rule, $base);
    }

    /**
     * Chuẩn hoá + kiểm tra dữ liệu gửi lên.
     *
     * @return array{0: list<array{days: list<int>, mode: string, value: int|float}>|null, 1: array<string,list<string>>}
     */
    public static function normalize(mixed $raw, string $path): array
    {
        if (! is_array($raw)) {
            return [null, [$path => ['Tăng/giảm theo thứ phải là danh sách các dòng.']]];
        }

        $raw = array_values($raw);

        if (count($raw) > self::MAX_ROWS) {
            return [null, [$path => ['Tối đa ' . self::MAX_ROWS . ' dòng tăng/giảm theo thứ.']]];
        }

        $errors = [];
        $out    = [];
        $used   = [];

        foreach ($raw as $i => $row) {
            $at     = "{$path}.{$i}";
            $row    = is_array($row) ? $row : [];
            $before = count($errors);
            $mode   = (string) ($row['mode'] ?? '');
            $days   = [];

            foreach ((array) ($row['days'] ?? []) as $day) {
                if (! is_numeric($day) || (int) $day != $day || (int) $day < 1 || (int) $day > 7) {
                    $errors["{$at}.days"][] = 'Thứ chỉ nhận 1 (Thứ 2) … 7 (Chủ nhật).';

                    break;
                }

                $days[] = (int) $day;
            }

            $days = array_values(array_unique($days));
            sort($days);

            if (! $days && count($errors) === $before) {
                $errors["{$at}.days"][] = 'Chọn ít nhất 1 thứ.';
            }

            foreach ($days as $day) {
                if (isset($used[$day])) {
                    $errors["{$at}.days"][] = 'Thứ ' . ($day === 7 ? 'Chủ nhật' : $day + 1) . ' đã có ở dòng ' . ($used[$day] + 1) . '.';

                    break;
                }
            }

            foreach ($days as $day) {
                $used[$day] ??= $i;
            }

            $value = null;

            if (! in_array($mode, self::MODES, true)) {
                $errors["{$at}.mode"][] = 'Kiểu chỉ nhận percent (tăng/giảm %) hoặc fixed (cộng/trừ số tiền).';
            } else {
                $value = HolidayPrices::normalizeValue($mode, $row['value'] ?? null, "{$at}.value", $errors);
            }

            if (count($errors) === $before) {
                $out[] = ['days' => $days, 'mode' => $mode, 'value' => $value];
            }
        }

        return [$errors ? null : ($out ?: null), $errors];
    }
}
