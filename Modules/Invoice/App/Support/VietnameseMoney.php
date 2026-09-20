<?php

declare(strict_types=1);

namespace Modules\Invoice\App\Support;

// Đọc số tiền bằng chữ tiếng Việt — trường bắt buộc trên hoá đơn GTGT thật ("Số tiền viết bằng
// chữ: ..."). Không dùng thư viện ngoài vì không có sẵn trong composer.json và bài toán đủ nhỏ để
// tự viết đúng quy tắc (linh/lẻ, mươi/mười, lăm/năm, mốt/một) mà không cần thêm dependency.
class VietnameseMoney
{
    private const DIGITS = ['không', 'một', 'hai', 'ba', 'bốn', 'năm', 'sáu', 'bảy', 'tám', 'chín'];

    private const SCALES = ['', ' nghìn', ' triệu', ' tỷ', ' nghìn tỷ', ' triệu tỷ'];

    public static function toWords(int $amount): string
    {
        if ($amount === 0) {
            return 'Không đồng';
        }

        $negative = $amount < 0;
        $amount = abs($amount);

        $groups = [];
        while ($amount > 0) {
            $groups[] = $amount % 1000;
            $amount = intdiv($amount, 1000);
        }

        $words = [];
        for ($i = count($groups) - 1; $i >= 0; $i--) {
            if ($groups[$i] === 0) {
                continue;
            }

            $isFirstGroup = $i === count($groups) - 1;
            $words[] = self::readGroup($groups[$i], ! $isFirstGroup) . self::SCALES[$i];
        }

        $result = trim(implode(' ', $words));
        $result = ($negative ? 'Âm ' : '') . mb_strtoupper(mb_substr($result, 0, 1)) . mb_substr($result, 1);

        return $result . ' đồng';
    }

    // $padHundred: nhóm này KHÔNG phải nhóm đầu tiên (có nhóm lớn hơn phía trước) — phải đọc đủ
    // "không trăm" nếu hàng trăm = 0 nhưng vẫn còn hàng chục/đơn vị (VD 1.005 -> "một nghìn không
    // trăm linh năm", không phải "một nghìn linh năm").
    private static function readGroup(int $group, bool $padHundred): string
    {
        $hundred = intdiv($group, 100);
        $remainder = $group % 100;
        $tens = intdiv($remainder, 10);
        $unit = $remainder % 10;

        $parts = [];

        if ($hundred > 0) {
            $parts[] = self::DIGITS[$hundred] . ' trăm';
        } elseif ($padHundred && $remainder > 0) {
            $parts[] = 'không trăm';
        }

        if ($tens === 0) {
            if ($unit > 0 && ($hundred > 0 || $padHundred)) {
                $parts[] = 'linh ' . self::DIGITS[$unit];
            } elseif ($unit > 0) {
                $parts[] = self::DIGITS[$unit];
            }
        } elseif ($tens === 1) {
            // "mười" (10-19): 1 vẫn đọc "một" (mười một, KHÔNG PHẢI mười mốt), 5 đọc "lăm".
            $parts[] = 'mười' . ($unit > 0 ? ' ' . ($unit === 5 ? 'lăm' : self::DIGITS[$unit]) : '');
        } else {
            // "X mươi" (20+): 1 đọc "mốt", 5 đọc "lăm".
            $unitWord = match ($unit) {
                0 => '',
                1 => 'mốt',
                5 => 'lăm',
                default => self::DIGITS[$unit],
            };
            $parts[] = self::DIGITS[$tens] . ' mươi' . ($unitWord !== '' ? ' ' . $unitWord : '');
        }

        return trim(implode(' ', $parts));
    }
}
