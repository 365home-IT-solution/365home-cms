<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\Carbon;

/**
 * GIÁ NGÀY LỄ, TẾT của bộ giá (price_board_sheets / price_board_items.holiday_prices).
 *
 * Mỗi dòng: { name, recurring, from, to, mode, value }
 *  - recurring = true  → lặp hàng năm, from/to dạng "MM-DD" (VD 30/4 → "04-30"); khoảng được phép vắt qua
 *    năm mới ("12-31" → "01-01").
 *  - recurring = false → đúng 1 năm, from/to dạng "YYYY-MM-DD" (VD Tết Âm lịch, đổi ngày mỗi năm).
 *  - mode = price   → giá cố định (đêm của phòng theo ngày): value = số tiền.
 *    mode = percent → tăng/giảm theo % trên GIÁ ĐANG ÁP DỤNG của ngày đó (đã gồm giá theo thứ / cuối tuần): value = +30, -10…
 *    mode = fixed   → cộng/trừ số tiền trên giá đang áp dụng: value = +300000, -50000…
 *    Phòng theo giờ chỉ dùng percent / fixed (áp cho giá từng khung giờ).
 *  - Dòng cũ chỉ có "price" được hiểu là mode = price, value = price.
 * Ngày thuộc dòng nào thì tính theo dòng ấy, thắng cả giá theo thứ. Dòng của đúng 1 năm thắng dòng lặp
 * hàng năm (đặt riêng cho 1 năm đặc biệt mà không phải bỏ dòng lặp).
 */
class HolidayPrices
{
    public const MAX_ROWS = 60;
    public const MAX_SPAN_DAYS = 400;
    public const MAX_PRICE = 999999999;
    public const MODES = ['price', 'percent', 'fixed'];

    /** Năm nhuận để dựng/duyệt ngày "MM-DD" (chấp nhận cả 29/2). */
    private const LEAP_YEAR = 2024;

    /**
     * Dịp lễ dựng sẵn (nút "Thêm ngày lễ có sẵn" trên form, file SQL mẫu). Lễ dương lịch lặp hàng năm;
     * Tết Âm lịch đổi ngày mỗi năm nên khai báo đúng từng năm (mùng 2 → mùng 8; mùng 1 Tết 2027 = 06/02/2027,
     * Tết 2028 = 26/01/2028). Cần thêm năm nữa thì thêm dòng vào đây.
     */
    public const PRESETS = [
        ['name' => 'Giáng sinh (24-25/12)',          'recurring' => true,  'from' => '12-24',    'to' => '12-25'],
        ['name' => 'Tết Dương lịch (31/12-1/1)',     'recurring' => true,  'from' => '12-31',    'to' => '01-01'],
        ['name' => 'Lễ 30/4 - 1/5',                  'recurring' => true,  'from' => '04-30',    'to' => '05-01'],
        ['name' => 'Quốc khánh (1/9-2/9)',           'recurring' => true,  'from' => '09-01',    'to' => '09-02'],
        ['name' => 'Tết Âm lịch 2027 (mùng 2-8)',    'recurring' => false, 'from' => '2027-02-07', 'to' => '2027-02-13'],
        ['name' => 'Tết Âm lịch 2028 (mùng 2-8)',    'recurring' => false, 'from' => '2028-01-27', 'to' => '2028-02-02'],
    ];

    /**
     * Dòng dựng sẵn kèm giá cố định $price (hoặc kiểu $mode + $value), bỏ dòng "đúng 1 năm" đã qua.
     *
     * @return list<array{name: string, recurring: bool, from: string, to: string, mode: string, value: int|float|null}>
     */
    public static function presets(int|float|null $value = null, string $mode = 'price'): array
    {
        $today = now()->toDateString();

        return array_values(array_map(
            fn (array $row) => $row + ['mode' => $mode, 'value' => $value],
            array_filter(self::PRESETS, fn (array $row) => $row['recurring'] || $row['to'] >= $today)
        ));
    }

    /**
     * Dòng lễ áp cho ngày $date: ['mode' => ..., 'value' => ...]; null = ngày thường (không thuộc dòng nào).
     *
     * @param array<int,array<string,mixed>>|null $rows
     * @return array{mode: string, value: float}|null
     */
    public static function match(?array $rows, Carbon|string $date): ?array
    {
        if (! $rows) {
            return null;
        }

        $date     = Carbon::parse($date);
        $ymd      = $date->format('Y-m-d');
        $md       = $date->format('m-d');
        $recurred = null;

        foreach ($rows as $row) {
            $from  = $row['from'] ?? null;
            $to    = $row['to'] ?? null;
            $mode  = $row['mode'] ?? 'price';
            $value = $row['value'] ?? ($row['price'] ?? null);

            if (! $from || ! $to || $value === null || $value === '' || ! in_array($mode, self::MODES, true)) {
                continue;
            }

            $rule = ['mode' => $mode, 'value' => (float) $value];

            if (! empty($row['recurring'])) {
                $hit = $from <= $to ? ($md >= $from && $md <= $to) : ($md >= $from || $md <= $to);

                if ($hit && $recurred === null) {
                    $recurred = $rule;
                }
            } elseif ($ymd >= $from && $ymd <= $to) {
                return $rule;
            }
        }

        return $recurred;
    }

    /** Giá sau khi áp dòng lễ lên $base (giá đang áp dụng). Làm tròn 1.000đ, không âm — giống bảng giá "điều chỉnh". */
    public static function apply(array $rule, float $base): float
    {
        $value = (float) $rule['value'];

        return match ($rule['mode']) {
            'price'   => $value,
            'percent' => self::round($base * (1 + $value / 100)),
            'fixed'   => self::round($base + $value),
            default   => $base,
        };
    }

    /**
     * Giá của ngày $date theo danh sách dòng lễ; null = ngày thường. Dòng percent/fixed cần $base
     * (không có thì null — không tính được).
     *
     * @param array<int,array<string,mixed>>|null $rows
     */
    public static function priceOn(?array $rows, Carbon|string $date, ?float $base = null): ?float
    {
        $rule = self::match($rows, $date);

        if ($rule === null || ($rule['mode'] !== 'price' && $base === null)) {
            return null;
        }

        return self::apply($rule, $base ?? 0.0);
    }

    /** Làm tròn 1.000đ, không bao giờ âm (cùng công thức PriceBoardSyncService::applyAdjustment). */
    public static function round(float $price): float
    {
        return (float) max(0, (int) (round($price / 1000) * 1000));
    }

    /**
     * Chuẩn hoá + kiểm tra dữ liệu gửi lên. $allowPrice = false cho phòng theo giờ (chỉ percent / fixed).
     *
     * @return array{0: list<array<string,mixed>>|null, 1: array<string,list<string>>}
     */
    public static function normalize(mixed $raw, string $path, bool $allowPrice = true): array
    {
        if (! is_array($raw)) {
            return [null, [$path => ['Giá ngày lễ phải là danh sách các dòng.']]];
        }

        $raw = array_values($raw);

        if (count($raw) > self::MAX_ROWS) {
            return [null, [$path => ['Tối đa ' . self::MAX_ROWS . ' dòng ngày lễ cho mỗi bộ giá.']]];
        }

        $errors = [];
        $out    = [];
        $days   = [];

        foreach ($raw as $i => $row) {
            $at  = "{$path}.{$i}";
            $row = is_array($row) ? $row : [];

            $recurring = filter_var($row['recurring'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $name      = isset($row['name']) ? trim((string) $row['name']) : '';
            $mode      = (string) ($row['mode'] ?? 'price');
            $valueKey  = array_key_exists('value', $row) ? 'value' : 'price';
            $value     = $row[$valueKey] ?? null;
            $from      = isset($row['from']) ? trim((string) $row['from']) : '';
            $to        = isset($row['to']) ? trim((string) $row['to']) : '';
            $before    = count($errors);

            if (mb_strlen($name) > 100) {
                $errors["{$at}.name"][] = 'Tên dịp lễ tối đa 100 ký tự.';
            }

            if (! in_array($mode, self::MODES, true)) {
                $errors["{$at}.mode"][] = 'Kiểu giá chỉ nhận price (giá cố định), percent (tăng/giảm %) hoặc fixed (cộng/trừ số tiền).';
            } elseif ($mode === 'price' && ! $allowPrice) {
                $errors["{$at}.mode"][] = 'Phòng theo giờ chỉ dùng tăng/giảm % hoặc cộng/trừ số tiền (áp cho giá từng khung giờ), không dùng giá cố định.';
            } else {
                $value = self::normalizeValue($mode, $value, "{$at}.{$valueKey}", $errors);
            }

            $fromOk = self::validDay($from, $recurring);
            $toOk   = self::validDay($to, $recurring);
            $format = $recurring ? 'MM-DD (VD 04-30)' : 'YYYY-MM-DD';

            if (! $fromOk) {
                $errors["{$at}.from"][] = "Ngày bắt đầu không hợp lệ, cần dạng {$format}.";
            }

            if (! $toOk) {
                $errors["{$at}.to"][] = "Ngày kết thúc không hợp lệ, cần dạng {$format}.";
            }

            if ($fromOk && $toOk) {
                if (! $recurring && $from > $to) {
                    $errors["{$at}.to"][] = 'Ngày kết thúc phải sau hoặc bằng ngày bắt đầu.';
                } else {
                    $keys = self::dayKeys($from, $to, $recurring);

                    if (count($keys) > self::MAX_SPAN_DAYS) {
                        $errors["{$at}.to"][] = 'Mỗi dòng lễ tối đa ' . self::MAX_SPAN_DAYS . ' ngày.';
                    } else {
                        $type = $recurring ? 'r' : 'y';

                        foreach ($keys as $key) {
                            if (isset($days[$type][$key])) {
                                $errors["{$at}.from"][] = 'Trùng ngày với dòng ' . ($days[$type][$key] + 1) . '.';
                                break;
                            }
                        }

                        foreach ($keys as $key) {
                            $days[$type][$key] ??= $i;
                        }
                    }
                }
            }

            if (count($errors) === $before) {
                $clean = ['name' => $name !== '' ? $name : null, 'recurring' => $recurring, 'from' => $from, 'to' => $to, 'mode' => $mode, 'value' => $value];

                if ($mode === 'price') {
                    $clean['price'] = $value; // tương thích dòng cũ
                }

                $out[] = $clean;
            }
        }

        return [$errors ? null : ($out ?: null), $errors];
    }

    /**
     * Giá trị theo kiểu: price = số nguyên 0…MAX; fixed = số nguyên ±MAX; percent = số -100…1000.
     *
     * @param array<string,list<string>> $errors
     */
    public static function normalizeValue(string $mode, mixed $value, string $key, array &$errors): int|float|null
    {
        $label = ['price' => 'Giá ngày lễ', 'fixed' => 'Số tiền tăng/giảm', 'percent' => 'Phần trăm tăng/giảm'][$mode] ?? 'Giá trị';

        if ($value === null || $value === '' || ! is_numeric($value)) {
            $errors[$key][] = "{$label} phải là một số.";

            return null;
        }

        $number = (float) $value;

        if ($mode === 'percent') {
            if ($number < -100 || $number > 1000) {
                $errors[$key][] = 'Phần trăm tăng/giảm từ -100 đến 1000.';

                return null;
            }

            $number = round($number, 2);

            return $number == (int) $number ? (int) $number : $number;
        }

        if ($number != (int) $number) {
            $errors[$key][] = "{$label} phải là số nguyên.";

            return null;
        }

        if ($mode === 'price' && $number < 0) {
            $errors[$key][] = 'Giá ngày lễ phải là số nguyên không âm.';

            return null;
        }

        if (abs($number) > self::MAX_PRICE) {
            $errors[$key][] = "{$label} không được vượt quá " . number_format(self::MAX_PRICE, 0, ',', '.') . ' đ.';

            return null;
        }

        return (int) $number;
    }

    /**
     * Kiểm tra 1 khoảng ngày lễ (danh mục ngày lễ): định dạng theo recurring, từ ≤ đến (trừ lặp hàng năm được vắt năm), tối đa MAX_SPAN_DAYS.
     *
     * @return array<string,list<string>>
     */
    public static function rangeErrors(bool $recurring, string $from, string $to, string $fromKey = 'from', string $toKey = 'to'): array
    {
        $errors = [];
        $format = $recurring ? 'MM-DD (VD 04-30)' : 'YYYY-MM-DD';

        if (! self::validDay($from, $recurring)) {
            $errors[$fromKey][] = "Ngày bắt đầu không hợp lệ, cần dạng {$format}.";
        }

        if (! self::validDay($to, $recurring)) {
            $errors[$toKey][] = "Ngày kết thúc không hợp lệ, cần dạng {$format}.";
        }

        if ($errors) {
            return $errors;
        }

        if (! $recurring && $from > $to) {
            return [$toKey => ['Ngày kết thúc phải sau hoặc bằng ngày bắt đầu.']];
        }

        if (count(self::dayKeys($from, $to, $recurring)) > self::MAX_SPAN_DAYS) {
            return [$toKey => ['Mỗi dòng lễ tối đa ' . self::MAX_SPAN_DAYS . ' ngày.']];
        }

        return [];
    }

    private static function validDay(string $value, bool $recurring): bool
    {
        if ($recurring) {
            return (bool) preg_match('/^(\d{2})-(\d{2})$/', $value, $m) && checkdate((int) $m[1], (int) $m[2], self::LEAP_YEAR);
        }

        $date = \DateTime::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    /**
     * Mọi ngày của khoảng (khoá "MM-DD" hoặc "YYYY-MM-DD"), tối đa MAX_SPAN_DAYS + 1 để biết là quá dài.
     *
     * @return list<string>
     */
    private static function dayKeys(string $from, string $to, bool $recurring): array
    {
        $keys = [];

        if ($recurring) {
            $day = Carbon::createFromFormat('!Y-m-d', self::LEAP_YEAR . '-' . $from);

            for ($n = 0; $n <= self::MAX_SPAN_DAYS; $n++) {
                $keys[] = $day->format('m-d');

                if ($day->format('m-d') === $to) {
                    break;
                }

                $day->addDay();
            }

            return $keys;
        }

        $day = Carbon::createFromFormat('!Y-m-d', $from);

        for ($n = 0; $n <= self::MAX_SPAN_DAYS && $day->format('Y-m-d') <= $to; $n++) {
            $keys[] = $day->format('Y-m-d');
            $day->addDay();
        }

        return $keys;
    }
}
