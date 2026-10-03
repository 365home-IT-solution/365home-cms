<?php

declare(strict_types=1);

namespace App\Support;

// Đọc số thành chữ tiếng Việt (dùng cho hợp đồng: "1.000.000 đồng (Một triệu đồng)", "20% (hai mươi phần trăm)").
class VietnameseNumber
{
    private const DIGITS = ['không', 'một', 'hai', 'ba', 'bốn', 'năm', 'sáu', 'bảy', 'tám', 'chín'];

    private const UNITS = ['', 'nghìn', 'triệu', 'tỷ', 'nghìn tỷ', 'triệu tỷ'];

    /** Số nguyên không âm → chữ thường, vd 1000000 → "một triệu". */
    public static function words(int $number): string
    {
        if ($number === 0) {
            return self::DIGITS[0];
        }

        $groups = [];
        while ($number > 0) {
            $groups[] = $number % 1000;
            $number = intdiv($number, 1000);
        }

        $parts = [];
        $count = count($groups);
        foreach (array_reverse($groups, true) as $index => $group) {
            if ($group === 0) {
                continue;
            }
            // Nhóm không phải nhóm cao nhất mà < 100 thì đọc đủ "không trăm ..." (vd 1.005.000 → "một triệu không trăm linh năm nghìn").
            $full = $index < $count - 1;
            $parts[] = trim(self::readGroup($group, $full) . ' ' . self::UNITS[$index]);
        }

        return trim(implode(' ', $parts));
    }

    /** Số tiền → "Một triệu đồng" (viết hoa chữ đầu). */
    public static function money(int $amount): string
    {
        return self::ucfirst(self::words($amount) . ' đồng');
    }

    /** 1000000 → "1.000.000". */
    public static function format(int $amount): string
    {
        return number_format($amount, 0, ',', '.');
    }

    /** Phần trăm (có thể lẻ): 20 → "hai mươi phần trăm"; 7.5 → "bảy phẩy năm phần trăm". */
    public static function percentWords(float $percent): string
    {
        $whole = (int) floor($percent);
        $fraction = round($percent - $whole, 2);
        $text = self::words($whole);

        if ($fraction > 0) {
            $digits = rtrim(substr(number_format($fraction, 2, '.', ''), 2), '0');
            $text .= ' phẩy ' . implode(' ', array_map(fn ($d) => self::DIGITS[(int) $d], str_split($digits)));
        }

        return $text . ' phần trăm';
    }

    /** 20.0 → "20"; 7.5 → "7,5". */
    public static function percent(float $percent): string
    {
        return rtrim(rtrim(number_format($percent, 2, ',', ''), '0'), ',');
    }

    private static function readGroup(int $group, bool $full): string
    {
        $hundreds = intdiv($group, 100);
        $tens = intdiv($group % 100, 10);
        $ones = $group % 10;
        $out = [];

        if ($hundreds > 0 || $full) {
            $out[] = self::DIGITS[$hundreds] . ' trăm';
        }

        if ($tens > 1) {
            $out[] = self::DIGITS[$tens] . ' mươi';
            if ($ones === 1) {
                $out[] = 'mốt';
            } elseif ($ones === 5) {
                $out[] = 'lăm';
            } elseif ($ones > 0) {
                $out[] = self::DIGITS[$ones];
            }
        } elseif ($tens === 1) {
            $out[] = 'mười';
            if ($ones === 5) {
                $out[] = 'lăm';
            } elseif ($ones > 0) {
                $out[] = self::DIGITS[$ones];
            }
        } elseif ($ones > 0) {
            if ($hundreds > 0 || $full) {
                $out[] = 'linh';
            }
            $out[] = $ones === 5 && ($hundreds > 0 || $full) ? 'năm' : self::DIGITS[$ones];
        }

        return implode(' ', $out);
    }

    private static function ucfirst(string $text): string
    {
        return mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1);
    }
}
